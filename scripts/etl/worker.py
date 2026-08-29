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
import collections
import json
import os
import signal
import subprocess
import sys
import threading
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


def _pump_child(proc, on_stderr_line=None, stdout_cap_bytes: int = 2_000_000,
                stderr_tail_lines: int = 400):
    """Line-stream the child's pipes from reader threads.

    stderr lines reach `on_stderr_line` AS THEY ARRIVE. The previous
    communicate() pattern trapped every progress line in the pipe until the
    child exited and then folded in only the LAST 40 — an hours-long resolve
    item (IND) was completely dark in the run log the whole time it worked,
    which is law 4 (Visible) violated at the exact moment it mattered.

    stdout accumulates (capped) for the result-JSON parse; stderr keeps a
    bounded tail for outcome reasons. Returns a join fn — call it after the
    process exits to get (stdout_text, stderr_tail_text)."""
    out_buf: list = []
    out_len = [0]
    err_tail = collections.deque(maxlen=stderr_tail_lines)

    def _read(stream, sink):
        try:
            for line in iter(stream.readline, ""):
                sink(line)
        except Exception:
            pass
        finally:
            try:
                stream.close()
            except Exception:
                pass

    def _out_sink(line: str) -> None:
        if out_len[0] < stdout_cap_bytes:
            out_buf.append(line)
            out_len[0] += len(line)

    def _err_sink(line: str) -> None:
        s = line.rstrip("\n")
        err_tail.append(s)
        if on_stderr_line is not None:
            try:
                on_stderr_line(s)
            except Exception:
                pass

    readers = [
        threading.Thread(target=_read, args=(proc.stdout, _out_sink), daemon=True),
        threading.Thread(target=_read, args=(proc.stderr, _err_sink), daemon=True),
    ]
    for t in readers:
        t.start()

    def _join(timeout: float = 10.0):
        for t in readers:
            t.join(timeout=timeout)
        return "".join(out_buf), "\n".join(err_tail)

    return _join


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
              worker_tag: str = "w", phase: str | None = None) -> tuple[dict, bool]:
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
        # Overlap ingest (2026-08-02, INGEST_OVERLAP_PLAN.md §3 — the one
        # bug the plan flagged as non-optional before overlap ships):
        # counting only claim["kind"] assumed the ladder guarantees a
        # single kind runs at a time. Overlapped, that double-funds —
        # 2 boundary children @ budget/2 PLUS 8 raster children @ budget/8
        # sums to 2x budget committed, the exact co-residency shape behind
        # kills #32/#33. The denominator now counts every kind the CURRENT
        # PHASE can claim (its own kind + PHASE_FALLTHROUGH), so the field
        # is funded as one merged pool regardless of which kind each child
        # actually is. Falls back to claim["kind"] alone if phase is
        # unknown (keeps old behavior for any caller that doesn't pass it).
        #
        # INCLUDING RANGE CHILDREN (2026-08-03 — the CAN boundary and USA
        # raster exit -9s of this morning's fresh run). Parents-only was the
        # claimable-closure bug: _attempt_claim hands out *_range items from
        # the same pile, so at a phase TAIL the parent count collapses while
        # several range children are still live — each freshly-spawned child
        # then gets a near-full slice, the co-resident sum blows past the
        # cgroup, and the kernel kills the fattest (Nunavut's 5.39M-vertex
        # parse; USA's band 200+46 load). The denominator is the closure of
        # what a lane can actually be RUNNING, or it is fiction.
        _FAMILY = {"boundary_iso":  ("boundary_iso", "boundary_range"),
                   "raster_iso":    ("raster_iso", "raster_range"),
                   "attribution_pair": ("attribution_pair", "attribution_range"),
                   "resolve_global": ("resolve_global", "resolve_range")}
        if phase is not None:
            seeds = (claims.PHASE_KIND.get(phase, claim["kind"]),
                     *claims.PHASE_FALLTHROUGH.get(phase, ()))
        else:
            seeds = (claim["kind"],)
        denom_kinds = tuple({k for s in seeds for k in _FAMILY.get(s, (s,))})
        with get_cursor(conn) as cur:
            cur.execute(
                """
                SELECT COUNT(*) AS n FROM geodata_items
                 WHERE run_id = %s AND kind = ANY(%s)
                   AND status IN ('pending', 'running')
                """,
                (run_id, list(denom_kinds)),
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
    # Live log streaming: every child line lands in the run log the moment it
    # is emitted (tagged with the worker), instead of post-mortem last-40.
    pump_join = _pump_child(
        proc, on_stderr_line=lambda s: _log_line(worker_tag, s))
    last_beat = time.monotonic()
    while True:
        try:
            # wait(), not poll+sleep: returns the INSTANT the child exits, so
            # a seconds-long item never pays a poll-lag tax on completion.
            proc.wait(timeout=5)
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
                    proc.wait(timeout=10)
                except Exception:
                    proc.kill()
                pump_join()
                return ({}, False)
    stdout, stderr_tail = pump_join()
    return (_parse_outcome(stdout, stderr_tail, proc.returncode), True)


def run_worker(run_id: str, worker_tag: str, lane: str = "small",
               pref: str | None = None) -> int:
    conn = get_connection()
    token = claims.register_lease(conn, run_id)
    started = time.monotonic()
    _log_line(worker_tag, f"lease {token[:8]} up on run {run_id[:8]} (lane={lane})")

    # Overlap ingest (2026-08-02, INGEST_OVERLAP_PLAN.md §2, "giant-gate
    # yields become productive instead of idle"): per-kind self-skip after
    # a GiantFloorYield. A yielded item goes back to 'pending' at the head
    # of its own est_cost ordering, so re-trying the SAME kind immediately
    # would almost always re-claim the exact giant this lane just yielded
    # off of. Skip that kind for ~60s so the lane's next claims fall
    # through to an independent kind (raster work) instead of sleeping —
    # the sleep now fires only when nothing anywhere is claimable.
    skip_until: dict[str, float] = {}
    GIANT_YIELD_SKIP_SECONDS = 60

    try:
        while True:
            if _STOP["v"]:
                break
            if (time.monotonic() - started) > CLAIM_BUDGET_SECONDS:
                break

            ctl = claims.run_control(conn, run_id)
            if ctl["status"] != "running" or ctl["halt"] or ctl["paused"]:
                break

            now = time.monotonic()
            active_skips = frozenset(k for k, until in skip_until.items() if until > now)
            if len(active_skips) != len(skip_until):
                skip_until = {k: v for k, v in skip_until.items() if v > now}

            claim = claims.claim_next(conn, run_id, ctl["phase"], token, lane=lane,
                                      skip_kinds=active_skips, pref=pref)
            if claim is None:
                # This phase is drained for us; wait for the pump to advance
                # (or for peers to open review work). Heartbeat while idle.
                claims.touch_lease(conn, token, run_id=run_id)
                time.sleep(IDLE_SLEEP_SECONDS)
                continue

            claims.touch_lease(conn, token, claim["kind"], claims.label(claim), run_id=run_id)
            _log_line(worker_tag, f"claim {claim['kind']} {claim.get('iso_code') or ''} "
                                  f"L{claim.get('adm_level')}".rstrip(" LNone"))

            outcome, still_ours = _run_unit(conn, run_id, claim, token, worker_tag, phase=ctl["phase"])
            if not still_ours:
                _log_line(worker_tag, f"→ {claim['kind']} ABANDONED (claim reclaimed mid-run)")
                claims.touch_lease(conn, token, run_id=run_id)
                continue

            landed = claims.record_outcome(conn, claim["id"], token, outcome["status"],
                                           outcome.get("reason"), outcome.get("metrics"))
            _log_line(worker_tag, f"→ {claim['kind']} {outcome['status']}"
                                  + ("" if landed else " (outcome discarded — claim no longer ours)"))
            claims.touch_lease(conn, token, run_id=run_id)  # clear the claim label
            if outcome["status"] == "pending":
                # The child yielded — a giant holds the parse floor. Self-skip
                # THIS kind for a while so the lane's next claim falls through
                # to independent work (raster) instead of immediately
                # re-claiming the same blocked giant and instead of sleeping
                # while unrelated work sits idle (2026-08-02, replaces the
                # old unconditional 45s sleep — operator-caught: "giants
                # holding the rest of the lanes hostage").
                skip_until[claim["kind"]] = time.monotonic() + GIANT_YIELD_SKIP_SECONDS
                _log_line(worker_tag, f"yielded {claim['kind']} — skipping it for "
                                      f"{GIANT_YIELD_SKIP_SECONDS}s, falling through")
    finally:
        # THE CLAIM RELEASE (2026-08-29, operator fix set): a worker dying
        # mid-item used to strand its claim 'running' until the pump's
        # 30-minute stale backstop (the round-2 finalize stall). On ANY exit
        # path, release whatever this token still holds — on a FRESH
        # connection, because the death class that matters (postgres
        # recovery) kills the loop connection itself. The guard
        # (claim_token = token AND status = 'running') can never clobber
        # another worker's re-claim, and the lease delete makes the pump's
        # lease-keyed reclaim treat any residue as orphaned immediately.
        try:
            _c2 = get_connection()
            try:
                with _c2.cursor() as _cur:
                    _cur.execute(
                        "UPDATE geodata_items SET status = 'pending', claim_token = NULL, "
                        "reason = 'released: worker exited mid-item', updated_at = now() "
                        "WHERE claim_token = %s AND status = 'running'", (token,))
                    _cur.execute(
                        "DELETE FROM geodata_worker_leases WHERE id = %s", (token,))
                _c2.commit()
            finally:
                _c2.close()
        except Exception:
            pass
        try:
            claims.clear_lease(conn, token)
        except Exception:
            pass
        try:
            conn.close()
        except Exception:
            pass
        _log_line(worker_tag, "exit")
    return 0


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--run", required=True)
    ap.add_argument("--tag", default="w")
    ap.add_argument("--lane", default="small", choices=["small", "big"])
    ap.add_argument("--pref", default=None, choices=["boundary", "raster"],
                    help="Quadrant preference: which ingest pile this lane "
                         "tries FIRST during the boundaries phase (operator "
                         "50/50 spec, 2026-08-03). Falls through to the "
                         "complement when its pile is empty.")
    args = ap.parse_args()

    signal.signal(signal.SIGTERM, lambda *_: _STOP.update(v=True))
    signal.signal(signal.SIGINT, lambda *_: _STOP.update(v=True))

    return run_worker(args.run, args.tag, args.lane, args.pref)


if __name__ == "__main__":
    sys.exit(main())
