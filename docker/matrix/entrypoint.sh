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

# Run synapse directly with BOTH the generated base config AND the CGA overrides explicitly
# merged. The image's `run` does NOT auto-discover /data/conf.d, so without this the generated
# sqlite/defaults win; passing the override as a later --config-path replaces the database block
# (→ the shared postgres 'matrix' DB), the empty federation whitelist, and the no-policy-server
# posture. Runs as root (the generated files are root-owned), so /data stays writable.
exec python -m synapse.app.homeserver \
  --config-path /data/homeserver.yaml \
  --config-path /data/conf.d/10-cga.yaml

