"""
error_pause.py — Pause-on-exception flow for the ETL pipeline.

When --pause-on-exception is set and a per-country error occurs, the ETL writes
/etl/control/paused_on_error.json with full error context, then polls for
/etl/control/error_resolution.json containing the operator's decision
(skip / retry / abort). On resolution, both files are removed and execution
continues based on the chosen action.

This is *child-side* polling — runs entirely inside the ETL process. The
supervisor's existing pause/resume flow (SIGSTOP/SIGCONT for user-initiated
pauses) is untouched.
"""

import json
import logging
import time
import traceback
from datetime import datetime, timezone
from pathlib import Path

CONTROL_DIR = Path("/etl/control")
PAUSE_FILE  = CONTROL_DIR / "paused_on_error.json"
RESOL_FILE  = CONTROL_DIR / "error_resolution.json"

# How often to check for the resolution file (seconds). 0.5s feels responsive
# without burning CPU — operator clicks travel a Laravel write → file system
# round-trip in <1s on a local docker stack.
POLL_INTERVAL_SEC = 0.5


def wait_for_error_decision(
    *,
    country: str,
    adm_level: int,
    phase: str,
    exception: BaseException,
    log: logging.Logger,
) -> str:
    """
    Persist error context and block until operator decides skip / retry / abort.

    Returns one of: "skip", "retry", "abort".

    The pause file is removed before this function returns, so subsequent
    errors during the same run start with a clean slate. If the resolution
    file is malformed, we keep polling — the operator can re-click the button.
    """
    payload = {
        "country":      country,
        "adm_level":    adm_level,
        "phase":        phase,
        "error_class":  type(exception).__name__,
        "error_message": str(exception),
        "traceback":    traceback.format_exc(),
        "options":      ["skip", "retry", "abort"],
        "paused_at":    datetime.now(timezone.utc).isoformat(),
    }

    try:
        CONTROL_DIR.mkdir(parents=True, exist_ok=True)
        # Atomic write via .tmp + replace, mirroring heartbeat.write_current.
        tmp = PAUSE_FILE.with_suffix(".tmp")
        tmp.write_text(json.dumps(payload, indent=2, default=str))
        tmp.replace(PAUSE_FILE)
    except OSError as exc:
        # If we can't even write the pause file, we can't ask the operator —
        # fall back to abort so the run doesn't silently spin forever.
        log.error("Could not write paused_on_error.json (%s) — aborting", exc)
        return "abort"

    log.warning(
        "ETL paused on error in %s (level=%d, phase=%s) — awaiting operator decision",
        country, adm_level, phase,
    )

    try:
        while True:
            if RESOL_FILE.exists():
                try:
                    decision = json.loads(RESOL_FILE.read_text())
                    action   = decision.get("action")
                    if action in {"skip", "retry", "abort"}:
                        log.info("ETL resuming from error pause: %s", action)
                        return action
                except (OSError, json.JSONDecodeError) as exc:
                    # Malformed file — log once-ish and keep polling.
                    log.debug("error_resolution.json unreadable (%s) — retrying", exc)
            time.sleep(POLL_INTERVAL_SEC)
    finally:
        # Clean up both files so the next error starts clean.
        for f in (PAUSE_FILE, RESOL_FILE):
            try:
                f.unlink(missing_ok=True)
            except OSError:
                pass
