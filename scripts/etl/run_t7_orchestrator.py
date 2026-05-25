"""
run_t7_orchestrator.py — Phase T.7 subprocess-per-pair orchestrator.

Solves the OOM-creep that killed the in-process sweep at pair 69/483:
each (iso, level) runs as a FRESH subprocess via `run_t7_pair.py`,
exits, and frees all memory before the next pair starts. Python heap
fragmentation, rasterio caches, NumPy temp allocations — all reclaimed
by the OS at subprocess exit.

Workflow:
  1. Read t7_dryrun_report.json (incremental checkpoint).
  2. Enumerate (iso, level) pairs from DB.
  3. Skip pairs already marked done in the report.
  4. For each remaining pair: subprocess.run(run_t7_pair.py iso level),
     parse stdout JSON, append to report, save incrementally.
  5. Summary at end.

Usage:
  python3 /etl/run_t7_orchestrator.py
  APPLY_TO_DB=1 python3 /etl/run_t7_orchestrator.py     # actually UPDATE

Halt-safe: ctrl-C between pairs is clean. Killing mid-pair leaves the
in-flight subprocess to die with the parent (or finish independently if
the parent is killed first); next resume skips done pairs.
"""

from __future__ import annotations

import json
import os
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, "/etl")

from db import get_connection, get_cursor

REPORT_PATH = Path("/etl/control/t7_dryrun_report.json")
LOG_PATH    = Path("/etl/control/t7_orchestrator.log")
PAIR_SCRIPT = "/etl/run_t7_pair.py"
APPLY_TO_DB = os.environ.get("APPLY_TO_DB", "0") == "1"


def log(msg: str) -> None:
    line = f"{datetime.now(timezone.utc).isoformat()} {msg}"
    print(line, flush=True)
    try:
        with open(LOG_PATH, "a") as f:
            f.write(line + "\n")
    except OSError:
        pass


def load_report() -> dict:
    if REPORT_PATH.exists():
        try:
            with open(REPORT_PATH) as f:
                return json.load(f)
        except (json.JSONDecodeError, OSError) as exc:
            log(f"WARNING: could not load existing report ({exc}) — starting fresh")
    return {
        "started_at": datetime.now(timezone.utc).isoformat(),
        "apply_to_db": APPLY_TO_DB,
        "pairs": [],
        "totals_by_verdict": {
            "exact": 0, "near": 0, "partial": 0, "far": 0,
            "no_l1": 0, "no_polys": 0, "no_rasters": 0,
            "failed": 0, "killed": 0, "timeout": 0,
        },
    }


def save_report(report: dict) -> None:
    tmp = REPORT_PATH.with_suffix(".json.tmp")
    with open(tmp, "w") as f:
        json.dump(report, f, indent=2, default=str)
    os.replace(tmp, REPORT_PATH)


def enumerate_iso_levels(conn) -> list[tuple[str, int, int]]:
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT iso_code, adm_level, COUNT(*) AS n FROM jurisdictions
            WHERE iso_code IS NOT NULL AND adm_level >= 1 AND deleted_at IS NULL
            GROUP BY iso_code, adm_level HAVING COUNT(*) >= 2
            ORDER BY iso_code, adm_level
        """)
        return [(r["iso_code"], r["adm_level"], r["n"]) for r in cur.fetchall()]


def run_pair_subprocess(iso: str, level: int) -> dict:
    """
    Invoke run_t7_pair.py as a subprocess. Returns the parsed JSON
    result on success, or a synthetic failure dict on crash. No
    timeout — a pair runs as long as it needs.
    """
    cmd = ["python3", PAIR_SCRIPT, iso, str(level)]
    if APPLY_TO_DB:
        cmd.append("--apply")

    pair_start = time.monotonic()
    try:
        proc = subprocess.run(
            cmd, capture_output=True, text=True, check=False,
        )
    except Exception as exc:
        return {
            "iso": iso, "level": level, "ok": False,
            "verdict": "failed",
            "error": f"subprocess invocation failed: {exc}",
            "elapsed_s": time.monotonic() - pair_start,
        }

    wall_elapsed = time.monotonic() - pair_start

    # Parse the LAST stdout line — pair script may emit warnings on
    # stderr; the JSON result is the last (and typically only) stdout
    # line. Empty stdout indicates a crash before any output (OOM
    # signal-9, segfault, etc.) — treat as killed.
    out = (proc.stdout or "").strip()
    if not out:
        return {
            "iso": iso, "level": level, "ok": False,
            "verdict": "killed",
            "error": (
                f"subprocess produced no stdout "
                f"(returncode={proc.returncode}; likely OOM-killed). "
                f"stderr (truncated): {(proc.stderr or '')[:500]}"
            ),
            "elapsed_s": wall_elapsed,
        }

    # Last line is the JSON result.
    for line in reversed(out.splitlines()):
        line = line.strip()
        if line.startswith("{"):
            try:
                result = json.loads(line)
                # Add wall-clock elapsed (includes subprocess startup/teardown).
                result["wall_elapsed_s"] = round(wall_elapsed, 2)
                return result
            except json.JSONDecodeError:
                continue

    return {
        "iso": iso, "level": level, "ok": False,
        "verdict": "failed",
        "error": f"could not parse JSON from stdout: {out[:500]}",
        "elapsed_s": wall_elapsed,
    }


def main() -> int:
    log(f"[T.7 orchestrator start] APPLY_TO_DB={APPLY_TO_DB}")

    conn = get_connection()
    try:
        pairs = enumerate_iso_levels(conn)
    finally:
        conn.close()
    log(f"Enumerated {len(pairs)} (iso, level) pairs from DB")

    report = load_report()
    # Build set of already-done (iso, level) keys.
    done_keys = {
        (p.get("iso"), p.get("level"))
        for p in report.get("pairs", [])
        if p.get("ok") is True
    }
    log(f"Already done in prior runs: {len(done_keys)} pairs (will skip)")

    run_start = time.monotonic()
    new_done = 0
    new_failed = 0

    for idx, (iso, level, n_polys) in enumerate(pairs, start=1):
        if (iso, level) in done_keys:
            continue

        log(f"  [{idx:4d}/{len(pairs):4d}] {iso} L={level} (n={n_polys}) — running subprocess")
        result = run_pair_subprocess(iso, level)
        result.setdefault("timestamp", datetime.now(timezone.utc).isoformat())
        report["pairs"].append(result)

        verdict = result.get("verdict") or ("failed" if not result.get("ok") else "no_l1")
        report["totals_by_verdict"][verdict] = (
            report["totals_by_verdict"].get(verdict, 0) + 1
        )

        if result.get("ok"):
            new_done += 1
            log(
                f"    -> {result.get('verdict','?')} "
                f"({result.get('elapsed_s','?')}s, "
                f"n_polys={result.get('n_polys','?')}, "
                f"n_rasters={result.get('n_rasters','?')}, "
                f"pre_dev={result.get('pre_sum',0)-result.get('l1_pop',0):+d}, "
                f"post_dev={result.get('post_dev','?'):+d})"
            )
        else:
            new_failed += 1
            log(f"    -> FAILED: {result.get('error','?')[:300]}")

        save_report(report)

    total_elapsed = time.monotonic() - run_start
    report["finished_at"] = datetime.now(timezone.utc).isoformat()
    report["session_elapsed_s"] = round(total_elapsed, 2)
    save_report(report)

    log(
        f"[T.7 orchestrator done] new_done={new_done} new_failed={new_failed} "
        f"session_elapsed={total_elapsed:.1f}s ({total_elapsed/3600:.2f}h)"
    )
    log(f"Final verdict totals: {report['totals_by_verdict']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
