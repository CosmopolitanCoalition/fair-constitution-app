#!/bin/sh
# Phase K-3 — Synapse first-boot wrapper. Generates the homeserver config + the
# per-instance signing key into the /data volume on first boot (idempotent: skipped
# once /data/homeserver.yaml exists), then runs. The CGA conf.d overrides
# (/data/conf.d/*.yaml — postgres, empty federation whitelist, no policy server) are
# merged on top at run time. Runs as root (UID/GID=0 env) so the named /data volume,
# which starts root-owned, is writable — dev parity with the php-fpm -R container.
set -e

if [ ! -f /data/homeserver.yaml ]; then
  echo "[cga-matrix] first boot — generating homeserver.yaml + signing key for ${SYNAPSE_SERVER_NAME}…"
  /start.py generate
fi

# Run synapse with the generated base config + EVERY CGA override in /data/conf.d (sorted) merged
# on top. The image's `run` does NOT auto-discover conf.d, so we pass each file explicitly as a
# later --config-path (later wins): 10-cga.yaml swaps in postgres + the empty federation whitelist +
# no policy server; 20-mas.yaml delegates auth to MAS. Globbing (not naming files) means a fresh box
# with only 10-cga.yaml still boots, and new overrides drop in without editing this script. Runs as
# root (the generated files are root-owned), so /data stays writable.
EXTRA=""
for f in /data/conf.d/*.yaml; do
  [ -e "$f" ] && EXTRA="$EXTRA --config-path $f"
done
exec python -m synapse.app.homeserver --config-path /data/homeserver.yaml $EXTRA

