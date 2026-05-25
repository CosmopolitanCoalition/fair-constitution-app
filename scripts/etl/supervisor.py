"""
supervisor.py — Long-running watcher for Laravel-triggered ETL jobs.

Replaces the old `tail -f /dev/null` idle loop. Polls /etl/control/request.json
every 2 seconds; when found, launches seed_database.py with the requested
flags and tracks lifecycle state via files in the same directory.

Lifecycle files (all under /etl/control/):
    request.json  → Laravel writes; watcher consumes atomically.
    running.json  → Watcher writes when the job starts; holds pid + started_at.
    done.json     → Watcher writes on successful exit.
    failed.json   → Watcher writes on non-zero exit.

This is deliberately simple: one job at a time, no queue. Submitting a new
request while one is running is rejected by Laravel (checks for running.json);
the watcher itself just skips the file until the current job finishes.

Log output is captured to /etl/etl.log so the existing seed_database logger
and the new UI log tail hit the same source.
"""

import json
import os
import signal
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

CONTROL_DIR = Path("/etl/control")
LOG_FILE    = Path("/etl/etl.log")

REQUEST = CONTROL_DIR / "request.json"
RUNNING = CONTROL_DIR / "running.json"
DONE    = CONTROL_DIR / "done.json"
FAILED  = CONTROL_DIR / "failed.json"
CURRENT = CONTROL_DIR / "current.json"

# User-initiated control signals. Each is a sentinel file the supervisor
# watches during proc.wait() and consumes on detection.
#   halt.request   → SIGTERM the subprocess, record halted=true
#   pause.request  → SIGSTOP the subprocess (child frozen, memory retained)
#   resume.request → SIGCONT the subprocess
HALT    = CONTROL_DIR / "halt.request"
PAUSE   = CONTROL_DIR / "pause.request"
RESUME  = CONTROL_DIR / "resume.request"

# Phase P.1.2: bar-state file the supervisor patches on pause/resume so the
# frontend's elapsed-time computations freeze while paused. Heartbeat.py
# writes this file from inside the Python ETL process; the supervisor reads
# + selectively patches the pause-tracking fields. Must match
# heartbeat.py::BARS (/etl/control/bars.json — both processes run inside
# the etl container with the same bind mount).
BARS    = CONTROL_DIR / "bars.json"

POLL_SECONDS = 2


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def write_atomic(path: Path, payload: dict) -> None:
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(json.dumps(payload, indent=2))
    tmp.replace(path)


# Phase P.1.2: pause-aware bar-timer support. When pause fires we stamp
# `_paused_at` at the top level of bars.json AND on each currently-running
# bar. The frontend reads these and freezes elapsed/ETA. When resume
# fires we compute the pause duration and push each affected bar's
# `started_at` forward by that duration, then clear the `_paused_at`
# markers. Net effect: elapsed measures real processing time, not
# wall-clock.

def _read_bars_json() -> dict:
    try:
        return json.loads(BARS.read_text())
    except (OSError, ValueError):
        return {}


def _write_bars_json(state: dict) -> None:
    try:
        write_atomic(BARS, state)
    except OSError:
        pass


def _bar_iter(state: dict):
    """Yield every bar across all three list buckets + the summary."""
    for k in ("geoboundaries_bars", "cleanup_bars",
              "worldpop_current_country_bars"):
        for b in state.get(k, []) or []:
            yield b
    summ = state.get("worldpop_country_summary")
    if isinstance(summ, dict):
        yield summ


def freeze_bar_timers(at_iso: str) -> None:
    """Stamp `_paused_at` at the top level and on each running bar."""
    state = _read_bars_json()
    if not state:
        return
    state["_paused_at"] = at_iso
    for b in _bar_iter(state):
        # Only running bars need freezing; done/pending stay as-is. The
        # summary bar doesn't have a `status` field — it's always
        # "running-equivalent" while a country is processing, so always
        # stamp it.
        if b.get("status") == "running" or "status" not in b:
            if not b.get("paused_at"):
                b["paused_at"] = at_iso
    _write_bars_json(state)


def thaw_bar_timers() -> None:
    """Compute pause duration and push each paused bar's `started_at`
    forward by that delta, then clear `paused_at` markers. The result:
    on resume, elapsed = (now - started_at) reads as if the pause never
    happened — the bar's apparent throughput stays correct."""
    state = _read_bars_json()
    if not state or "_paused_at" not in state:
        return
    try:
        paused_dt = datetime.fromisoformat(state["_paused_at"])
        now_dt    = datetime.now(timezone.utc)
        delta_sec = (now_dt - paused_dt).total_seconds()
    except (TypeError, ValueError):
        delta_sec = 0.0
    if delta_sec <= 0:
        state.pop("_paused_at", None)
        for b in _bar_iter(state):
            b.pop("paused_at", None)
        _write_bars_json(state)
        return
    from datetime import timedelta
    delta = timedelta(seconds=delta_sec)
    for b in _bar_iter(state):
        if b.get("paused_at") and b.get("started_at"):
            try:
                started_dt = datetime.fromisoformat(b["started_at"])
                b["started_at"] = (started_dt + delta).isoformat()
            except (TypeError, ValueError):
                pass
        b.pop("paused_at", None)
    state.pop("_paused_at", None)
    _write_bars_json(state)


def build_argv(options: dict) -> list[str]:
    """Translate request JSON into a seed_database.py argv list."""
    argv = ["python3", "-u", "/etl/seed_database.py"]

    if options.get("fresh"):
        argv.append("--fresh")
    elif options.get("resume", True):
        argv.append("--resume")

    if options.get("skip_population"):
        argv.append("--skip-population")

    # Both names accepted from request.json — the wizard sends
    # pause_on_exception now, but legacy clients may still send
    # stop_on_exception. Either truthy → --pause-on-exception flag.
    if options.get("pause_on_exception") or options.get("stop_on_exception"):
        argv.append("--pause-on-exception")

    countries = options.get("countries") or []
    if countries:
        argv.append("--countries")
        argv.extend(countries)

    adm_levels = options.get("adm_levels") or []
    if adm_levels:
        argv.append("--adm-levels")
        argv.extend(str(n) for n in adm_levels)

    # Phase P.8 — operator-supplied data root from the wizard's source
    # picker. Forwarded to seed_database.py which sets DATA_ROOT in env
    # before importing the per-phase modules.
    data_root = options.get("data_root")
    if data_root:
        argv.extend(["--data-root", str(data_root)])

    return argv


def clear_stale_pause_markers() -> None:
    """At the start of a new run, drop `_paused_at` / per-bar `paused_at`
    markers left over from a previous halted run. We can't call
    `thaw_bar_timers()` for this — that pushes `started_at` forward by the
    pause delta, which (when the prior pause was hours ago) would make
    every elapsed timer read in the future. Here we just delete the
    markers; the new run's bar_start calls overwrite their `started_at`
    fresh anyway."""
    try:
        state = _read_bars_json()
    except Exception:
        return
    if not state:
        return
    changed = False
    if "_paused_at" in state:
        state.pop("_paused_at", None)
        changed = True
    for b in _bar_iter(state):
        if b.get("paused_at"):
            b.pop("paused_at", None)
            changed = True
    if changed:
        _write_bars_json(state)


def run_job(request_payload: dict) -> int:
    argv = build_argv(request_payload.get("options") or {})
    started_at = now_iso()

    # Wipe stale pause markers from a prior halted run before launching —
    # otherwise the frontend's elapsed/ETA math clips wall-clock to that
    # old timestamp and bars look frozen.
    clear_stale_pause_markers()

    # Open the log file fresh for this run.
    log_fh = LOG_FILE.open("a", buffering=1)  # line-buffered
    log_fh.write(f"\n==== ETL run started at {started_at} ====\n")
    log_fh.write(f"argv: {' '.join(argv)}\n")
    log_fh.flush()

    proc = subprocess.Popen(
        argv,
        stdout=log_fh,
        stderr=subprocess.STDOUT,
        cwd="/etl",
    )

    running_payload = {
        "pid":           proc.pid,
        "started_at":    started_at,
        "request":       request_payload,
        "argv":          argv,
        "paused":        False,
    }
    write_atomic(RUNNING, running_payload)

    halted = False
    paused = False
    while True:
        try:
            exit_code = proc.wait(timeout=POLL_SECONDS)
            break
        except subprocess.TimeoutExpired:
            pass

        # Drain control signals. Each file is consumed immediately so the
        # supervisor doesn't re-act on the next poll.
        if HALT.exists():
            try:
                HALT.unlink(missing_ok=True)
            except OSError:
                pass
            log_fh.write(f"[supervisor] halt requested — SIGTERM pid={proc.pid}\n")
            log_fh.flush()
            # If the child is currently SIGSTOP'd it won't see SIGTERM until
            # resumed, so unpause first.
            if paused:
                try:
                    os.kill(proc.pid, signal.SIGCONT)
                except ProcessLookupError:
                    pass
                paused = False
            try:
                proc.terminate()
            except ProcessLookupError:
                pass
            halted = True
            # Don't break — let the next wait() observe exit_code.
            continue

        if PAUSE.exists() and not paused:
            try:
                PAUSE.unlink(missing_ok=True)
            except OSError:
                pass
            try:
                os.kill(proc.pid, signal.SIGSTOP)
                paused = True
                pause_ts = now_iso()
                log_fh.write(f"[supervisor] paused pid={proc.pid} at {pause_ts}\n")
                log_fh.flush()
                running_payload["paused"]    = True
                running_payload["paused_at"] = pause_ts
                write_atomic(RUNNING, running_payload)
                # Freeze the bars-side elapsed timers so the UI doesn't tick
                # while the Python ETL is SIGSTOPped.
                freeze_bar_timers(pause_ts)
            except ProcessLookupError:
                pass

        if RESUME.exists() and paused:
            try:
                RESUME.unlink(missing_ok=True)
            except OSError:
                pass
            try:
                os.kill(proc.pid, signal.SIGCONT)
                paused = False
                log_fh.write(f"[supervisor] resumed pid={proc.pid}\n")
                log_fh.flush()
                running_payload["paused"] = False
                running_payload.pop("paused_at", None)
                write_atomic(RUNNING, running_payload)
                # Push each paused bar's started_at forward by the pause
                # duration. Elapsed timers continue from where they were
                # without the pause counted against them.
                thaw_bar_timers()
            except ProcessLookupError:
                pass

    finished_at = now_iso()
    log_fh.write(f"==== ETL run finished at {finished_at} (exit {exit_code}) ====\n")
    log_fh.close()

    status_file = DONE if exit_code == 0 else FAILED
    status_payload = {
        "pid":         proc.pid,
        "started_at":  started_at,
        "finished_at": finished_at,
        "exit_code":   exit_code,
        "request":     request_payload,
    }
    # Exit code 2 is seed_database's distinct "stopped on exception" signal.
    if exit_code == 2:
        status_payload["stopped_on_exception"] = True
    if halted:
        status_payload["halted"] = True
    write_atomic(status_file, status_payload)

    # P.1.2: freeze any bars left in `status=running` so the frontend stops
    # ticking elapsed/ETA after the run ends. Uses the same `_paused_at`
    # mechanism as pause — the Vue elapsedSeconds() function clips
    # wall-clock end to that timestamp. This handles halt, normal failure,
    # and successful completion uniformly. On clean completion the running
    # bars are already `status=done` so freezing is a no-op for them.
    freeze_bar_timers(finished_at)

    # Clear running marker so the next request can proceed; clear the
    # heartbeat file too as a backstop in case seed_database crashed
    # before its own cleanup. Also clear any leftover control signals.
    for p in (RUNNING, CURRENT, HALT, PAUSE, RESUME):
        try:
            p.unlink(missing_ok=True)
        except OSError:
            pass

    return exit_code


def consume_request() -> dict | None:
    """Atomically claim the current request.json payload, or return None."""
    if not REQUEST.exists():
        return None

    try:
        payload = json.loads(REQUEST.read_text())
    except (json.JSONDecodeError, OSError) as exc:
        # Malformed — move aside so we don't busy-loop on it.
        FAILED.write_text(json.dumps({
            "error":       f"malformed request: {exc}",
            "finished_at": now_iso(),
        }))
        try:
            REQUEST.unlink(missing_ok=True)
        except OSError:
            pass
        return None

    # Clear done/failed from any prior run so the new run has a clean slate.
    for p in (DONE, FAILED):
        try:
            p.unlink(missing_ok=True)
        except OSError:
            pass

    try:
        REQUEST.unlink(missing_ok=True)
    except OSError:
        pass

    return payload


def main() -> int:
    CONTROL_DIR.mkdir(parents=True, exist_ok=True)

    # If we're starting up and a running.json is left over from a crash,
    # flag it as failed so the UI isn't stuck thinking a job is live.
    if RUNNING.exists():
        try:
            stale = json.loads(RUNNING.read_text())
        except (json.JSONDecodeError, OSError):
            stale = {}
        write_atomic(FAILED, {
            "started_at":  stale.get("started_at"),
            "finished_at": now_iso(),
            "exit_code":   -1,
            "error":       "supervisor restarted while job was running",
            "request":     stale.get("request"),
        })
        for p in (RUNNING, CURRENT):
            try:
                p.unlink(missing_ok=True)
            except OSError:
                pass

    # Clear any stale heartbeat + control signals from a prior run (no-op
    # if absent). Pending halt/pause/resume files from a crashed supervisor
    # must not bleed into the next job.
    for p in (CURRENT, HALT, PAUSE, RESUME):
        try:
            p.unlink(missing_ok=True)
        except OSError:
            pass

    # Graceful shutdown on SIGTERM/SIGINT.
    def _shutdown(signum, _frame):
        sys.exit(0)

    signal.signal(signal.SIGTERM, _shutdown)
    signal.signal(signal.SIGINT,  _shutdown)

    sys.stdout.write(f"[supervisor] watching {CONTROL_DIR} (poll={POLL_SECONDS}s)\n")
    sys.stdout.flush()

    while True:
        payload = consume_request()
        if payload is not None:
            run_job(payload)
        time.sleep(POLL_SECONDS)


if __name__ == "__main__":
    sys.exit(main() or 0)
