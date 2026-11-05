#!/usr/bin/env python3
"""Render the untracked Matrix, MAS and LiveKit config files from their tracked *.example templates.

Credentials never live in git (operator rule 2026-11-03). Each real file is untracked and ignored.
This script creates a real file only when it is missing. It never overwrites an existing file.
Shared values come from .env when present. Missing values are generated and appended to .env,
so the appservice, MAS, Synapse and LiveKit stay matched.

Usage:  python3 scripts/ops/seed_node_config.py [--root <repo>] [--dry-run]
"""
import argparse
import os
import secrets
import subprocess
import sys

TARGETS = [
    "docker/livekit/livekit.yaml",
    "docker/matrix/appservice/registration.yaml",
    "docker/matrix/conf.d/10-cga.yaml",
    "docker/matrix/conf.d/20-mas.yaml",
    "docker/matrix/mas/config.yaml",
]

# template token -> (.env name, generator)
SHARED = {
    "AS_TOKEN": ("MATRIX_AS_TOKEN", lambda: "cga_as_" + secrets.token_hex(32)),
    "HS_TOKEN": ("MATRIX_HS_TOKEN", lambda: "cga_hs_" + secrets.token_hex(32)),
    "LIVEKIT_KEY": ("LIVEKIT_API_KEY", lambda: "cga_" + secrets.token_hex(6)),
    "LIVEKIT_SECRET": ("LIVEKIT_API_SECRET", lambda: secrets.token_hex(24)),
    "OIDC_CLIENT_SECRET": ("OIDC_MAS_CLIENT_SECRET", lambda: secrets.token_hex(32)),
    "MAS_SYNAPSE_SECRET": ("MATRIX_MAS_SYNAPSE_SECRET", lambda: secrets.token_hex(16)),
    "MAS_ENCRYPTION": ("MATRIX_MAS_ENCRYPTION", lambda: secrets.token_hex(32)),
}


def read_env(path):
    out = {}
    if os.path.exists(path):
        with open(path, encoding="utf-8") as fh:
            for line in fh:
                line = line.rstrip("\n")
                if line and not line.startswith("#") and "=" in line:
                    k, v = line.split("=", 1)
                    out[k.strip()] = v.strip().strip('"').strip("'")
    return out


def openssl(*args):
    return subprocess.run(["openssl", *args], capture_output=True, text=True, check=True).stdout


def keys_block():
    rsa = openssl("genrsa", "2048").strip().splitlines()
    ec = openssl("ecparam", "-name", "prime256v1", "-genkey", "-noout").strip().splitlines()
    lines = []
    for pem in (rsa, ec):
        lines.append("  - key: |")
        lines.extend("      " + l for l in pem)
    return "\n".join(lines)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--root", default=os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..")))
    ap.add_argument("--dry-run", action="store_true")
    a = ap.parse_args()

    env_path = os.path.join(a.root, ".env")
    env = read_env(env_path)
    todo = [t for t in TARGETS if not os.path.exists(os.path.join(a.root, t))]
    if not todo:
        print("seed_node_config: all four config files exist; nothing to do")
        return 0

    values, new_env = {}, {}
    for token, (name, gen) in SHARED.items():
        if env.get(name):
            values[token] = env[name]
        else:
            values[token] = gen()
            new_env[name] = values[token]
    if env.get("DB_PASSWORD"):
        values["DB_PASSWORD"] = env["DB_PASSWORD"]
    else:
        values["DB_PASSWORD"] = secrets.token_hex(24)
        new_env["DB_PASSWORD"] = values["DB_PASSWORD"]

    for rel in todo:
        src = os.path.join(a.root, rel + ".example")
        if not os.path.exists(src):
            print(f"seed_node_config: missing template {rel}.example", file=sys.stderr)
            return 1
        text = open(src, encoding="utf-8").read()
        for token, val in values.items():
            text = text.replace("{{" + token + "}}", val)
        if "{{MAS_KEYS_BLOCK}}" in text:
            text = text.replace("{{MAS_KEYS_BLOCK}}", keys_block() if not a.dry_run else "  # keys (dry run)")
        if a.dry_run:
            print(f"would write {rel}")
            continue
        with open(os.path.join(a.root, rel), "w", encoding="utf-8", newline="\n") as fh:
            fh.write(text)
        print(f"wrote {rel}")

    if new_env and not a.dry_run:
        with open(env_path, "a", encoding="utf-8", newline="\n") as fh:
            fh.write("\n# Added by scripts/ops/seed_node_config.py (untracked; never commit)\n")
            for k, v in new_env.items():
                fh.write(f"{k}={v}\n")
        print("appended to .env: " + ", ".join(new_env))
    return 0


if __name__ == "__main__":
    sys.exit(main())
