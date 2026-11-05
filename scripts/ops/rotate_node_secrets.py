#!/usr/bin/env python3
"""Roll the shared Matrix, MAS and LiveKit secrets on one box (W-0489 mechanics).

Rolls these pairs together, so both sides always agree:
  appservice as_token and hs_token   (.env, registration.yaml)
  LiveKit api key and secret         (.env, livekit.yaml)
  OIDC client secret game <-> MAS    (.env, mas/config.yaml)
  MAS <-> Synapse shared secret      (conf.d/20-mas.yaml, mas/config.yaml)

Not rolled here: MAS encryption key and signing keys (they protect stored data and
sessions), the database password and APP_KEY (they need data migration).

The script backs up .env and the four files first. It prints names and counts, never values.
A pair is changed only when its old value is found where expected. Restart containers after.

Usage: python3 scripts/ops/rotate_node_secrets.py [--root <repo>] [--dry-run]
"""
import argparse
import os
import re
import secrets
import shutil
import sys
import time

FILES = {
    "livekit": "docker/livekit/livekit.yaml",
    "registration": "docker/matrix/appservice/registration.yaml",
    "synapse_mas": "docker/matrix/conf.d/20-mas.yaml",
    "mas": "docker/matrix/mas/config.yaml",
}


def env_get(text, name):
    m = re.search(rf"^{re.escape(name)}=(.*)$", text, re.M)
    return m.group(1).strip().strip('"').strip("'") if m else ""


def env_set(text, name, value):
    if re.search(rf"^{re.escape(name)}=", text, re.M):
        return re.sub(rf"^{re.escape(name)}=.*$", lambda _: f"{name}={value}", text, count=1, flags=re.M)
    return text.rstrip("\n") + f"\n{name}={value}\n"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--root", default=os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..")))
    ap.add_argument("--dry-run", action="store_true")
    a = ap.parse_args()
    root = a.root

    paths = {k: os.path.join(root, v) for k, v in FILES.items()}
    env_path = os.path.join(root, ".env")
    texts = {k: open(p, encoding="utf-8").read() for k, p in paths.items()}
    env = open(env_path, encoding="utf-8").read()

    # (label, old value, new value, [file keys that must hold it], env name or None)
    plan = []
    for label, name, new, files in [
        ("appservice as_token", "MATRIX_AS_TOKEN", "cga_as_" + secrets.token_hex(32), ["registration"]),
        ("appservice hs_token", "MATRIX_HS_TOKEN", "cga_hs_" + secrets.token_hex(32), ["registration"]),
        ("livekit api key", "LIVEKIT_API_KEY", "cga_" + secrets.token_hex(6), ["livekit"]),
        ("livekit api secret", "LIVEKIT_API_SECRET", secrets.token_hex(24), ["livekit"]),
        ("oidc client secret", "OIDC_MAS_CLIENT_SECRET", secrets.token_hex(32), ["mas"]),
    ]:
        old = env_get(env, name)
        plan.append((label, old, new, files, name))

    m = re.search(r"^  secret: (\S+)$", texts["synapse_mas"], re.M)
    plan.append(("mas-synapse secret", m.group(1) if m else "", secrets.token_hex(16), ["synapse_mas", "mas"], None))

    problems = []
    for label, old, _new, files, _name in plan:
        if not old:
            problems.append(f"{label}: old value not found")
            continue
        for f in files:
            if texts[f].count(old) < 1:
                problems.append(f"{label}: not present in {FILES[f]}")
    if problems:
        print("refused, nothing changed:\n  " + "\n  ".join(problems), file=sys.stderr)
        return 1

    if a.dry_run:
        for label, _o, _n, files, name in plan:
            print(f"would roll {label} in {', '.join(FILES[f] for f in files)}" + (f" and .env {name}" if name else ""))
        return 0

    backup = os.path.join(os.path.dirname(root.rstrip("/")), "secrets-backup-" + time.strftime("%Y%m%d-%H%M%S"))
    os.makedirs(backup, mode=0o700)
    for rel in list(FILES.values()) + [".env"]:
        dst = os.path.join(backup, rel)
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        shutil.copy2(os.path.join(root, rel), dst)

    for label, old, new, files, name in plan:
        for f in files:
            texts[f] = texts[f].replace(old, new)
        if name:
            env = env_set(env, name, new)
        print(f"rolled {label}")

    for k, p in paths.items():
        with open(p, "w", encoding="utf-8", newline="\n") as fh:
            fh.write(texts[k])
    with open(env_path, "w", encoding="utf-8", newline="\n") as fh:
        fh.write(env)
    print(f"backup of the old files: {backup} (delete it once everything is verified)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
