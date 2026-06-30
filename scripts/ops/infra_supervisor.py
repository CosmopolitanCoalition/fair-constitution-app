#!/usr/bin/env python3
"""
Operator Operations console (Phase 3) — host-side infra-apply supervisor.

RUN THIS ON THE HOST (not in a container): it needs the host `.env` and the host
Docker daemon. It is the host counterpart to the in-container ETL supervisor — the
Laravel app cannot rewrite its own `.env` or recreate its own container, so the
Operations console writes a desired-state control file here and this process applies it.

Protocol (mirrors scripts/etl): the app writes `control/request.json`; this consumes
it, writes `control/running.json`, applies, then `control/done.json` or
`control/failed.json`. The console polls those.

Hard guards (defence in depth — the app validates too):
  * a STRICT key whitelist (LiveKit ICE networking only) — nothing else is written;
  * per-key value validation (IP / ws-URL);
  * `.env` is BACKED UP before every edit;
  * only the whitelisted compose service is recreated.

Usage:
    python3 scripts/ops/infra_supervisor.py            # watch loop (Ctrl-C to stop)
    python3 scripts/ops/infra_supervisor.py --once     # apply one pending request, exit
    python3 scripts/ops/infra_supervisor.py --dry-run  # validate + print, never write/recreate
"""
from __future__ import annotations

import argparse
import datetime
import ipaddress
import json
import re
import shutil
import subprocess
import sys
import time
from pathlib import Path
from urllib.parse import urlparse

REPO_ROOT = Path(__file__).resolve().parents[2]
CONTROL_DIR = REPO_ROOT / "scripts" / "ops" / "control"
ENV_PATH = REPO_ROOT / ".env"
POLL_SECONDS = 2

# The ONLY keys this supervisor will ever write, and the compose service each recreates.
WHITELIST = {
    "LIVEKIT_NODE_IP": {"validate": "ip", "recreate": "livekit"},
    "LIVEKIT_PUBLIC_URL": {"validate": "wsurl", "recreate": "livekit"},
}
ALLOWED_SERVICES = {"livekit"}


def now() -> str:
    return datetime.datetime.now(datetime.timezone.utc).isoformat()


def write_atomic(path: Path, payload: dict) -> None:
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(json.dumps(payload, indent=2))
    tmp.replace(path)


def validate(key: str, value: str) -> str:
    value = (value or "").strip()
    if not value:
        raise ValueError(f"{key} may not be blank")
    kind = WHITELIST[key]["validate"]
    if kind == "ip":
        ipaddress.ip_address(value)  # raises on invalid
        return value
    if kind == "wsurl":
        u = urlparse(value)
        if u.scheme not in ("ws", "wss") or not u.netloc:
            raise ValueError(f"{key} must be a ws:// or wss:// URL")
        return value
    raise ValueError(f"unknown validator for {key}")


def rewrite_env(changes: dict[str, str], dry_run: bool) -> None:
    """Replace each KEY=... line in .env (or append it), after a timestamped backup."""
    if not ENV_PATH.is_file():
        raise FileNotFoundError(f".env not found at {ENV_PATH}")
    text = ENV_PATH.read_text()

    for key, value in changes.items():
        line = f"{key}={value}"
        pattern = re.compile(rf"(?m)^{re.escape(key)}=.*$")
        text = pattern.sub(line, text) if pattern.search(text) else (text.rstrip("\n") + "\n" + line + "\n")

    if dry_run:
        print(f"[dry-run] would write {ENV_PATH} with: {changes}")
        return

    backup = ENV_PATH.with_name(f".env.bak.{int(time.time())}")
    shutil.copy2(ENV_PATH, backup)
    ENV_PATH.write_text(text)
    print(f"[ok] .env updated (backup: {backup.name})")


def recreate(services: list[str], dry_run: bool) -> None:
    svc = [s for s in services if s in ALLOWED_SERVICES]
    if not svc:
        return
    cmd = ["docker", "compose", "up", "-d", "--force-recreate", *svc]
    if dry_run:
        print(f"[dry-run] would run: {' '.join(cmd)}")
        return
    print(f"[..] {' '.join(cmd)}")
    subprocess.run(cmd, cwd=str(REPO_ROOT), check=True)
    print("[ok] recreated:", ", ".join(svc))


def apply_request(req: dict, dry_run: bool) -> None:
    req_id = req.get("id", "unknown")
    raw_changes = req.get("changes", {}) or {}

    # Validate EVERYTHING before touching anything.
    clean: dict[str, str] = {}
    for key, value in raw_changes.items():
        if key not in WHITELIST:
            raise ValueError(f"{key} is not whitelisted")
        clean[key] = validate(key, str(value))
    if not clean:
        raise ValueError("no whitelisted changes")

    services = req.get("recreate", []) or []

    if not dry_run:
        write_atomic(CONTROL_DIR / "running.json", {"id": req_id, "started_at": now(), "changes": clean})

    rewrite_env(clean, dry_run)
    recreate(services, dry_run)

    if not dry_run:
        write_atomic(CONTROL_DIR / "done.json", {"id": req_id, "applied_at": now(), "changes": clean, "recreate": services})
    print(f"[ok] request {req_id} applied")


def consume_one(dry_run: bool) -> bool:
    req_path = CONTROL_DIR / "request.json"
    if not req_path.is_file():
        return False
    try:
        req = json.loads(req_path.read_text())
    except Exception as e:  # malformed — drop it, don't loop on it
        req_path.unlink(missing_ok=True)
        write_atomic(CONTROL_DIR / "failed.json", {"id": "unknown", "failed_at": now(), "error": f"malformed request: {e}"})
        return True

    req_path.unlink(missing_ok=True)  # consume
    try:
        apply_request(req, dry_run)
    except Exception as e:
        print(f"[FAIL] {e}", file=sys.stderr)
        if not dry_run:
            write_atomic(CONTROL_DIR / "failed.json", {"id": req.get("id", "unknown"), "failed_at": now(), "error": str(e)})
    return True


def main() -> int:
    ap = argparse.ArgumentParser(description="Operator infra-apply supervisor (host-side).")
    ap.add_argument("--once", action="store_true", help="apply one pending request, then exit")
    ap.add_argument("--dry-run", action="store_true", help="validate + print, never write .env or recreate")
    args = ap.parse_args()

    CONTROL_DIR.mkdir(parents=True, exist_ok=True)
    print(f"[infra-supervisor] repo={REPO_ROOT} control={CONTROL_DIR} dry_run={args.dry_run}")

    if args.once:
        consume_one(args.dry_run)
        return 0

    print("[infra-supervisor] watching for requests (Ctrl-C to stop)…")
    try:
        while True:
            consume_one(args.dry_run)
            time.sleep(POLL_SECONDS)
    except KeyboardInterrupt:
        print("\n[infra-supervisor] stopped")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
