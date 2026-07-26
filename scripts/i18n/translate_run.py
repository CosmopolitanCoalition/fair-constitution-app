#!/usr/bin/env python3
"""
CGA — scripts/i18n/translate_run.py
The multi-worker orchestrator. Runs several languages at once and publishes
live state so a run can be WATCHED rather than waited on.

WHY THIS EXISTS
  translate_catalog.py translates one locale in one process. That is correct
  and resumable, but it answers "how many workers are active?" with "one", and
  it gives an operator nothing to look at while it runs. This spawns a pool,
  one worker per locale, and each worker heartbeats to
  storage/app/i18n-run/workers/*.json at every committed chunk boundary.

THE CONCURRENCY LIMIT IS THE GPU, NOT THE CPU
  Each NLLB worker loads its own ~2.4 GB copy of the model. On an 8 GB card
  that is three workers before the card is full, and this box shares its GPU
  with the dub lane's whisper models. The default is therefore deliberately
  conservative and the ceiling is stated rather than discovered by an OOM
  halfway through a long run. CPU workers are unbounded by VRAM but ~10x
  slower, so they are opt-in.

HALT IS A FILE, AND IT IS HONOURED AT A COMMITTED BOUNDARY
  storage/app/i18n-run/halt.request — dropped by the operator's page or by
  --halt here. Workers check it between chunks, so halting costs at most one
  chunk of redone work and can never leave a half-written catalog.

Usage:
  python3 scripts/i18n/translate_run.py --locales es,ar,hi,zh-Hans
  python3 scripts/i18n/translate_run.py --locales all --workers 2
  python3 scripts/i18n/translate_run.py --halt
  python3 scripts/i18n/translate_run.py --status

Options:
  --locales LIST   comma-separated codes, or `all` for every enabled locale
  --workers N      concurrent workers (default 2; the GPU is the real ceiling)
  --provider NAME  stub | nllb | claude          (default nllb)
  --chunk N        strings per committed chunk   (default 16)
  --device DEV     force cuda | cpu for every worker
  --halt           request a halt and exit
  --status         print the current run state and exit
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
I18N = ROOT / "resources" / "js" / "i18n"
RUN_DIR = ROOT / "storage" / "app" / "i18n-run"
WORKER_DIR = RUN_DIR / "workers"
RUN_FILE = RUN_DIR / "run.json"
HALT_FILE = RUN_DIR / "halt.request"
WORKER = Path(__file__).with_name("translate_catalog.py")

# One NLLB-200-distilled-600M in fp16 is ~1.3 GB of weights and ~2.4 GB
# resident with activations. Three fills an 8 GB card that is already hosting
# another lane's models.
GPU_WORKER_CEILING = 3


def write_atomic(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    tmp.replace(path)


def enabled_locales() -> list[str]:
    src = (I18N / "locales.generated.js").read_text(encoding="utf-8")
    import re
    out = []
    for m in re.finditer(r'\{ code: "([\w-]+)".*?enabled: (true|false)', src):
        if m.group(2) == "true" and m.group(1) != "en":
            out.append(m.group(1))
    return out


def read_workers() -> list[dict]:
    if not WORKER_DIR.exists():
        return []
    rows = []
    for p in sorted(WORKER_DIR.glob("*.json")):
        try:
            rows.append(json.loads(p.read_text(encoding="utf-8")))
        except Exception:  # noqa: BLE001
            continue
    return rows


def clear_stale() -> None:
    """A previous run's worker files are not this run's state."""
    if WORKER_DIR.exists():
        for p in WORKER_DIR.glob("*.json"):
            p.unlink(missing_ok=True)


def status() -> int:
    run = {}
    if RUN_FILE.exists():
        run = json.loads(RUN_FILE.read_text(encoding="utf-8"))
    workers = read_workers()
    live = [w for w in workers if time.time() - w.get("last_seen", 0) < 120]
    print(f"\nrun      {run.get('status', 'none')}   started {run.get('started_at_h', '-')}")
    print(f"halt     {'REQUESTED' if HALT_FILE.exists() else 'no'}")
    print(f"workers  {len(live)} live / {len(workers)} total\n")
    for w in workers:
        age = time.time() - w.get("last_seen", 0)
        print(f"  {w['id']:<18} {w['locale']:<8} {w.get('state',''):<12} "
              f"{w.get('done',0):>5}/{w.get('total',0):<6} "
              f"ns={w.get('namespace') or '-':<14} {w.get('rate') or 0:>5}/s "
              f"{'(stale)' if age > 120 else ''}")
    return 0


def main() -> int:
    ap = argparse.ArgumentParser(description="Run several translation workers and publish live state.")
    ap.add_argument("--locales", default="all")
    ap.add_argument("--workers", type=int, default=2)
    ap.add_argument("--provider", default="nllb", choices=["stub", "nllb", "claude"])
    ap.add_argument("--chunk", type=int, default=16)
    ap.add_argument("--device", choices=["cuda", "cpu"])
    ap.add_argument("--halt", action="store_true")
    ap.add_argument("--status", action="store_true")
    args = ap.parse_args()

    if args.status:
        return status()

    if args.halt:
        RUN_DIR.mkdir(parents=True, exist_ok=True)
        HALT_FILE.write_text(str(time.time()), encoding="utf-8")
        print("halt requested — workers park at their next committed chunk boundary")
        return 0

    HALT_FILE.unlink(missing_ok=True)
    clear_stale()

    locales = enabled_locales() if args.locales == "all" else [
        c.strip() for c in args.locales.split(",") if c.strip()
    ]
    if not locales:
        print("no locales to run")
        return 2

    pool = max(1, args.workers)
    if args.provider == "nllb" and args.device != "cpu" and pool > GPU_WORKER_CEILING:
        print(f"capping {pool} -> {GPU_WORKER_CEILING} workers: each NLLB worker holds its own "
              f"~2.4 GB copy of the model and this GPU is shared")
        pool = GPU_WORKER_CEILING

    run = {
        "run_id": f"run-{int(time.time())}",
        "status": "running",
        "started_at": time.time(),
        "started_at_h": time.strftime("%Y-%m-%d %H:%M:%S"),
        "provider": args.provider,
        "locales": locales,
        "workers_target": pool,
        "gpu_ceiling": GPU_WORKER_CEILING,
    }
    write_atomic(RUN_FILE, run)

    print(f"\nrun {run['run_id']} — {len(locales)} locales, {pool} workers, provider {args.provider}")
    print(f"watch it at /system/translations\n")

    queue = list(locales)
    running: list[tuple[str, subprocess.Popen]] = []

    def launch(locale: str) -> None:
        cmd = [sys.executable, str(WORKER), "--locale", locale,
               "--provider", args.provider, "--chunk", str(args.chunk)]
        env = dict(os.environ, PYTHONIOENCODING="utf-8")
        if args.device:
            env["CGA_TRANSLATE_DEVICE"] = args.device
        proc = subprocess.Popen(cmd, cwd=str(ROOT), env=env,
                                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        running.append((locale, proc))
        print(f"  + worker {locale} (pid {proc.pid})")

    try:
        while queue or running:
            while queue and len(running) < pool:
                launch(queue.pop(0))
            time.sleep(2)
            for entry in list(running):
                locale, proc = entry
                if proc.poll() is not None:
                    running.remove(entry)
                    print(f"  - worker {locale} finished (exit {proc.returncode})")
            if HALT_FILE.exists() and not queue:
                pass  # workers park themselves; just wait them out
            run["workers_live"] = len(running)
            write_atomic(RUN_FILE, run)
    except KeyboardInterrupt:
        print("\ninterrupted — requesting halt so workers park cleanly")
        HALT_FILE.write_text(str(time.time()), encoding="utf-8")
        for _, proc in running:
            proc.wait()

    run["status"] = "halted" if HALT_FILE.exists() else "done"
    run["finished_at"] = time.time()
    write_atomic(RUN_FILE, run)
    print(f"\nrun {run['status']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
