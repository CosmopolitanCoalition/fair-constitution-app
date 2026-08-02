"""
worker.py — one geodata pull worker (GEODATA_PULL_ENGINE_PLAN.md §4).

Registers a lease (its claim token), then loops: claim the current phase's next
item → run it in a FRESH subprocess (etl_unit.py) → record the outcome →
repeat. Exits on halt/pause, when the run leaves 'running', when no work is
claimable AND the budget elapses, or on SIGTERM. supervisor.py maintains the
pool, re-seeding a fresh worker within a poll of any exit.

NO self-rescheduling, NO payload state: a worker that dies drops its lease; its
one in-flight claim goes stale and the Laravel pump reclaims it minutes later.
Crash-safety is the contract (each item is idempotent — boundary/raster loads
are ON CONFLICT / DELETE-first, attribution re-runs cleanly).
"""

from __future__ import annotations

import argparse
import json
import signal
import subprocess
import sys
import time
from pathlib import Path

sys.path.insert(0, "/etl")

import claims  # noqa: E402
from db import get_connection  # noqa: E402

ETL_UNIT = "/etl/etl_unit.py"
LOG_FILE = Path("/etl/etl.log")

CLAIM_BUDGET_SECONDS = 3000   # exit after this; the supervisor re-seeds
IDLE_SLEEP_SECONDS   = 5      # between-phase wait for the pump to advance

_STOP = {"v": False}


def _log_line(tag: str, msg: str) -> None:
    try:
        with LOG_FILE.open("a", buffering=1) as fh:
            fh.write(f"[{tag}] {msg}\n")
    except OSError:
        pass


def _parse_outcome(proc: subprocess.CompletedProcess) -> dict:
    """The last valid JSON line on stdout is the item result; anything else
    (missing JSON / non-zero exit / a crash) → review with the stderr tail."""
    for line in reversed((proc.stdout or "").strip().splitlines()):
        try:
            p = json.loads(line)
            return {
                "status":  p.get("status", "review"),
                "reason":  p.get("reason"),
                "metrics": p.get("metrics"),
            }
        except ValueError:
            continue
    return {
        "status":  "review",
        "reason":  f"no result JSON (exit {proc.returncode}): {(proc.stderr or '')[-400:]}",
        "metrics": None,
    }


def run_worker(run_id: str, worker_tag: str) -> int:
    conn = get_connection()
    token = claims.register_lease(conn, run_id)
    started = time.monotonic()
    _log_line(worker_tag, f"lease {token[:8]} up on run {run_id[:8]}")

    try:
        while True:
            if _STOP["v"]:
                break
            if (time.monotonic() - started) > CLAIM_BUDGET_SECONDS:
                break

            ctl = claims.run_control(conn, run_id)
            if ctl["status"] != "running" or ctl["halt"] or ctl["paused"]:
                break

            claim = claims.claim_next(conn, run_id, ctl["phase"], token)
            if claim is None:
                # This phase is drained for us; wait for the pump to advance
                # (or for peers to open review work). Heartbeat while idle.
                claims.touch_lease(conn, token)
                time.sleep(IDLE_SLEEP_SECONDS)
                continue

            claims.touch_lease(conn, token, claim["kind"], claims.label(claim))
            _log_line(worker_tag, f"claim {claim['kind']} {claim.get('iso_code') or ''} "
                                  f"L{claim.get('adm_level')}".rstrip(" LNone"))

            proc = subprocess.run(
                ["python3", ETL_UNIT, "--run", run_id, "--item", claim["id"]],
                capture_output=True, text=True, check=False,
            )
            # Fold the child's own logging (stderr) into the unified run log.
            if proc.stderr:
                for line in proc.stderr.strip().splitlines()[-40:]:
                    _log_line(worker_tag, line)

            outcome = _parse_outcome(proc)
            claims.record_outcome(conn, claim["id"], outcome["status"],
                                  outcome.get("reason"), outcome.get("metrics"))
            _log_line(worker_tag, f"→ {claim['kind']} {outcome['status']}")
            claims.touch_lease(conn, token)  # clear the claim label
    finally:
        try:
            claims.clear_lease(conn, token)
        except Exception:
            pass
        conn.close()
        _log_line(worker_tag, "exit")
    return 0


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--run", required=True)
    ap.add_argument("--tag", default="w")
    args = ap.parse_args()

    signal.signal(signal.SIGTERM, lambda *_: _STOP.update(v=True))
    signal.signal(signal.SIGINT, lambda *_: _STOP.update(v=True))

    return run_worker(args.run, args.tag)


if __name__ == "__main__":
    sys.exit(main())
