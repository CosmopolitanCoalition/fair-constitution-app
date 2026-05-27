#!/bin/sh
# Vite container entrypoint — auto npm install on package.json drift.
#
# Why this exists. After `git pull` lands new dependencies in package.json,
# the named `node_modules` volume still holds the previous install. Vite's
# `npm run dev` fires immediately, can't resolve the missing imports, and
# the dev server emits red errors but the browser sees a blank page (the
# Inertia bootstrap JS fails to load). The operator has to know to run
# `docker compose exec vite npm install` manually.
#
# This entrypoint compares a hash of package.json + package-lock.json to a
# sentinel stored inside the named volume. When the hash matches, it skips
# the install entirely (boot stays sub-second). When it differs (or the
# sentinel is missing — fresh volume), it runs `npm install` first.
#
# Sentinel lives at node_modules/.installed-hash, inside the same volume as
# node_modules itself, so its lifetime is tied to the install it represents:
# wipe the volume → wipe the stamp → next boot reinstalls.

set -e

cd /var/www/html

STAMP="node_modules/.installed-hash"

# Hash both files because either changing should trigger a reinstall.
# `sha256sum` returns "<hash>  -" on stdin; cut to the hash alone.
WANT="$(cat package.json package-lock.json 2>/dev/null | sha256sum | cut -d' ' -f1)"
HAVE="$([ -f "$STAMP" ] && cat "$STAMP" || echo '')"

# Heuristic safeguard for the rare case where the named volume exists but
# is corrupted/partial (e.g. an aborted prior install). If a known recently-
# added dep is missing, force a reinstall regardless of the hash.
NEED_INSTALL=0
[ "$HAVE" != "$WANT" ] && NEED_INSTALL=1
[ ! -d "node_modules/protomaps-leaflet" ] && NEED_INSTALL=1

if [ "$NEED_INSTALL" = "1" ]; then
    echo "[vite-entrypoint] package.json drift detected — running npm install" >&2
    echo "[vite-entrypoint]   stamped hash: ${HAVE:-<missing>}" >&2
    echo "[vite-entrypoint]   desired hash: $WANT" >&2
    npm install --no-audit --no-fund --loglevel=warn
    echo "$WANT" > "$STAMP"
    echo "[vite-entrypoint] npm install complete — stamp updated" >&2
else
    echo "[vite-entrypoint] node_modules up to date (hash $WANT) — skipping install" >&2
fi

# Hand off to the original php base image's entrypoint so docker-php-entrypoint
# semantics are preserved (it's a thin wrapper that exec's CMD when CMD isn't
# a flag/option, which `npm run dev ...` isn't).
exec docker-php-entrypoint "$@"
