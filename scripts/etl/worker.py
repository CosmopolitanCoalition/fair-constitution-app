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
import os
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


def _parse_outcome(stdout: str, stderr: str, returncode: int) -> dict:
    """The last valid JSON line on stdout is the item result; anything else
    (missing JSON / non-zero exit / a crash) → review with the stderr tail."""
    for line in reversed((stdout or "").strip().splitlines()):
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
        "reason":  f"no result JSON (exit {returncode}): {(stderr or '')[-400:]}",
        "metrics": None,
    }


def _run_unit(conn, run_id: str, claim: dict, token: str,
              worker_tag: str = "w") -> tuple[dict, bool]:
    """Run etl_unit.py for the claim, heartbeating the claimed item every ~20 s
    so the pump's 30-min stale reclaim never false-fires on a live long unit.
    Returns (outcome, still_ours). If the claim was reclaimed from under us
    (pg pause + reclaim), the child is killed and the outcome is discarded."""
    # Per-worker memory slice, TAIL-AWARE: the child sizes its streaming
    # chunk profile (batch bytes, raster window px) against
    # budget ÷ min(pool, items actually open for this kind) — never the
    # whole container while the field is crowded, never starved at the tail.
    # A full 232-country field on 10 workers streams lean (~budget/10); the
    # 5-straggler tail gets double slices; THE LAST MONSTER STANDING gets
    # the entire budget — which is exactly why the legacy single-threaded
    # path could always digest CAN/IND/NZL: an irreducible single-FEATURE
    # peak (Nunavut ~800 MB) can't be batched smaller, only funded. The env
    # override is set ONLY on the child: the worker's own cap derivation
    # (claims.kind_cap) must keep seeing the full container budget.
    child_env = {**os.environ, "CGA_ETL_ITEM_ID": claim["id"]}
    try:
        from memory_budget import etl_budget_bytes
        from db import get_cursor
        pool = int(os.environ.get("CGA_ETL_POOL_SIZE", "0")) or 1
        with get_cursor(conn) as cur:
            cur.execute(
                """
                SELECT COUNT(*) AS n FROM geodata_items
                 WHERE run_id = %s AND kind = %s
                   AND status IN ('pending', 'running')
                """,
                (run_id, claim["kind"]),
            )
            open_items = max(1, int(cur.fetchone()["n"]))
        denom = max(1, min(pool, open_items))
        child_env["ETL_MEMORY_BUDGET_BYTES"] = str(
            max(192 * 1024 * 1024, etl_budget_bytes() // denom))
    except Exception:
        pass  # child falls back to the container budget — safe, just fatter

    proc = subprocess.Popen(
        ["python3", ETL_UNIT, "--run", run_id, "--item", claim["id"]],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
        cwd="/etl", start_new_session=True,
        # CGA_ETL_ITEM_ID: the child's heartbeat bar hooks write live per-
        # feature progress onto THIS item's row (the pull panel's mini bars).
        env=child_env,
    )
    last_beat = time.monotonic()
    while True:
        try:
            stdout, stderr = proc.communicate(timeout=5)
            break
        except subprocess.TimeoutExpired:
            pass
        if (time.monotonic() - last_beat) >= 20:
            last_beat = time.monotonic()
            try:
                still_ours = claims.heartbeat_claim(conn, claim["id"], token)
                claims.touch_lease(conn, token, claim["kind"], claims.label(claim), run_id=run_id)
            except Exception:
                continue  # transient DB blip — keep the child running, retry next beat
            if not still_ours:
                # The pump reclaimed this item (we looked dead to it) and it may
                # already be re-claimed — abandon: kill the child, discard.
                try:
                    proc.terminate()
                    proc.communicate(timeout=10)
                except Exception:
                    proc.kill()
                return ({}, False)
    # Fold the child's own logging (stderr) into the unified run log.
    if stderr:
        for line in stderr.strip().splitlines()[-40:]:
            _log_line(worker_tag, line)
    return (_parse_outcome(stdout, stderr, proc.returncode), True)


def run_worker(run_id: str, worker_tag: str, lane: str = "small") -> int:
    conn = get_connection()
    token = claims.register_lease(conn, run_id)
    started = time.monotonic()
    _log_line(worker_tag, f"lease {token[:8]} up on run {run_id[:8]} (lane={lane})")

    try:
        while True:
            if _STOP["v"]:
                break
            if (time.monotonic() - started) > CLAIM_BUDGET_SECONDS:
                break

            ctl = claims.run_control(conn, run_id)
            if ctl["status"] != "running" or ctl["halt"] or ctl["paused"]:
                break

            claim = claims.claim_next(conn, run_id, ctl["phase"], token, lane=lane)
            if claim is None:
                # This phase is drained for us; wait for the pump to advance
                # (or for peers to open review work). Heartbeat while idle.
                claims.touch_lease(conn, token, run_id=run_id)
                time.sleep(IDLE_SLEEP_SECONDS)
                continue

            claims.touch_lease(conn, token, claim["kind"], claims.label(claim), run_id=run_id)
            _log_line(worker_tag, f"claim {claim['kind']} {claim.get('iso_code') or ''} "
                                  f"L{claim.get('adm_level')}".rstrip(" LNone"))

            outcome, still_ours = _run_unit(conn, run_id, claim, token, worker_tag)
            if not still_ours:
                _log_line(worker_tag, f"→ {claim['kind']} ABANDONED (claim reclaimed mid-run)")
                claims.touch_lease(conn, token, run_id=run_id)
                continue

            landed = claims.record_outcome(conn, claim["id"], token, outcome["status"],
                                           outcome.get("reason"), outcome.get("metrics"))
            _log_line(worker_tag, f"→ {claim['kind']} {outcome['status']}"
                                  + ("" if landed else " (outcome discarded — claim no longer ours)"))
            claims.touch_lease(conn, token, run_id=run_id)  # clear the claim label
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
    ap.add_argument("--lane", default="small", choices=["small", "big"])
    args = ap.parse_args()

    signal.signal(signal.SIGTERM, lambda *_: _STOP.update(v=True))
    signal.signal(signal.SIGINT, lambda *_: _STOP.update(v=True))

    return run_worker(args.run, args.tag, args.lane)


if __name__ == "__main__":
    sys.exit(main())
