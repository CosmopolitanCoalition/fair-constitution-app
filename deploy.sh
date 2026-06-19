#!/usr/bin/env bash
#
# Phase G (G0b) — one-command deploy for a Cosmopolitan Governance App instance.
#
# Stands up a fresh instance from a code checkout: writes .env (unique container
# prefix + host ports, and a FRESH APP_KEY so a clone NEVER shares another
# instance's ballot-encryption / signed-URL keys), brings the Docker stack up,
# migrates, and — optionally — adopts a host as a read-only MIRROR in one step.
#
# Idempotent (safe to re-run) and clone-identity-safe (every fresh deploy mints
# its own APP_KEY, and `federation:init` mints its own Ed25519 server_id in its
# own database, so two instances are never the same identity).
#
# Examples:
#   ./deploy.sh                                    # default ports (8080/5432/5173)
#   ./deploy.sh --with-etl                         # a FOUNDER node that will import map data (incl. Raspberry Pi)
#   ./deploy.sh --prefix fcm --nginx-port 8082 --pg-port 5434 --vite-port 5175 \
#               --join http://host.docker.internal:8081 --key handle.secret
#
set -euo pipefail

PREFIX="fc"
NGINX_PORT="8080"
PG_PORT="5432"
VITE_PORT="5173"
SELF_URL=""
PROJECT=""
SEED=""
JOIN_URL=""
JOIN_KEY=""
WITH_ETL=""

usage() {
  sed -n '2,24p' "$0" | sed 's/^# \{0,1\}//'
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --prefix)      PREFIX="$2"; shift 2;;
    --nginx-port)  NGINX_PORT="$2"; shift 2;;
    --pg-port)     PG_PORT="$2"; shift 2;;
    --vite-port)   VITE_PORT="$2"; shift 2;;
    --self-url)    SELF_URL="$2"; shift 2;;
    --project)     PROJECT="$2"; shift 2;;
    --seed)        SEED="1"; shift;;
    --join)        JOIN_URL="$2"; shift 2;;
    --key)         JOIN_KEY="$2"; shift 2;;
    --with-etl)    WITH_ETL="1"; shift;;
    -h|--help)     usage; exit 0;;
    *) echo "Unknown option: $1" >&2; usage; exit 1;;
  esac
done

SELF_URL="${SELF_URL:-http://host.docker.internal:${NGINX_PORT}}"
PROJECT="${PROJECT:-$PREFIX}"

cd "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

DC=(docker compose -p "$PROJECT")
art() { "${DC[@]}" exec -T app php artisan "$@"; }

# 1. .env from the template on a fresh checkout.
[[ -f .env ]] || cp .env.example .env

set_env() {
  local key="$1" val="$2"
  if grep -qE "^${key}=" .env; then
    sed -i.bak -E "s#^${key}=.*#${key}=${val}#" .env && rm -f .env.bak
  else
    printf '\n%s=%s\n' "$key" "$val" >> .env
  fi
}

set_env CONTAINER_PREFIX   "$PREFIX"
set_env NGINX_HOST_PORT    "$NGINX_PORT"
set_env POSTGRES_HOST_PORT "$PG_PORT"
set_env VITE_HOST_PORT     "$VITE_PORT"
set_env FEDERATION_SELF_URL "$SELF_URL"
set_env APP_URL            "http://localhost:${NGINX_PORT}"

# Architecture: the official postgis image is amd64-only; on arm64 (Pi, ARM
# servers, Apple Silicon) use the multi-arch rebuild. amd64 keeps the default.
case "$(uname -m)" in aarch64|arm64) set_env POSTGIS_IMAGE imresamu/postgis:17-3.5 ;; esac

# Deployed posture: production + debug off. deploy.sh produces a built-asset
# instance (no Vite/HMR) viewable from any machine on the network; a dev box uses
# `docker compose up` (local + HMR) instead — these .env values only steer the
# stack deploy.sh brings up.
set_env APP_ENV   production
set_env APP_DEBUG false

# The `database` cache store backstops the federation/mesh throttle limiter whenever
# CACHE_STORE ever resolves to `database`. Pin its connection to pgsql so it can never fall
# through to the sqlite default and 500 every /api/federation route. A fresh clone gets this
# from .env.example; setting it here also covers an IN-PLACE upgrade whose pre-existing .env
# predates the key (the cp .env.example above only runs when .env is absent).
set_env DB_CACHE_CONNECTION pgsql

echo "→ Bringing up the stack (project=${PROJECT}, prefix=${PREFIX}, nginx :${NGINX_PORT})…"
# Explicit service list. Always omit `vite` (dev HMR — a deployed box serves the built
# assets produced at the end). `etl` (the geoBoundaries+WorldPop loader) is OPT-IN via
# --with-etl: a FOUNDING node that will import map data needs it (its image now builds on
# amd64 AND arm64/Raspberry Pi); a mirror ingests no geodata and skips it. nginx starts
# LAST (after the app is healthy + assets built) so compose never aborts the `up` waiting
# on a php-fpm still mid composer-install.
SERVICES=(app postgres redis horizon scheduler)
[[ -n "$WITH_ETL" ]] && SERVICES+=(etl)
"${DC[@]}" up -d --build "${SERVICES[@]}"

echo "→ Waiting for PostgreSQL…"
for _ in $(seq 1 60); do
  if "${DC[@]}" exec -T postgres pg_isready -U fc_user -d fair_constitution >/dev/null 2>&1; then break; fi
  sleep 2
done

# The app entrypoint runs `composer install` on first boot (minutes on a Pi) and
# writes vendor/.installed-hash as its DONE marker. Wait for that STAMP — NOT
# vendor/autoload.php: on a from-scratch (`down -v`) in-container install the
# autoloader file appears BEFORE the framework is fully extracted, so gating on
# it fires the artisan chain against an incomplete vendor/ → a "class not found"
# fatal in key:generate, and nothing after it runs. The stamp is written only
# after `composer install` returns (docker/php/entrypoint.sh), so it reliably
# means "vendor is complete". ~20 min ceiling for a slow Pi cold install.
echo "→ Waiting for the app (composer install)…"
for _ in $(seq 1 240); do
  if "${DC[@]}" exec -T app test -f vendor/.installed-hash 2>/dev/null; then break; fi
  sleep 5
done

# 2. A FRESH APP_KEY — clone-identity-safe (never reuse the repo's shared dev key).
echo "→ Generating a fresh APP_KEY…"
art key:generate --force

echo "→ Migrating…"
art migrate --force

# A fresh instance needs the constitutional clock registry (CLK-01…21) seeded —
# the scheduler + federation:init's CLK-20 arming depend on it. (DatabaseSeeder
# does NOT include it; it is its own seeder.)
echo "→ Seeding the constitutional clock registry…"
art db:seed --class=ClockRegistrySeeder --force

# 3. Federation identity when this instance will federate. --rotate forces a fresh
#    keypair: key:generate changed APP_KEY above, so any keypair carried in from a
#    clone is no longer decryptable — re-key it (and the server_id) under the new key.
if [[ -n "$JOIN_URL" ]]; then
  echo "→ Minting a fresh federation identity…"
  art federation:init --rotate
fi

# 4. Optional standing demo data.
[[ -n "$SEED" ]] && { echo "→ Seeding demo data…"; art institutions:demo-e || true; }

# 5. Optional: adopt a host as a read-only mirror in one step.
if [[ -n "$JOIN_URL" ]]; then
  [[ -n "$JOIN_KEY" ]] || { echo "ERROR: --join requires --key handle.secret" >&2; exit 1; }
  echo "→ Joining ${JOIN_URL} as a read-only mirror…"
  art cluster:join "$JOIN_URL" --key "$JOIN_KEY"
fi

# 6. Production front-end assets — build ONCE (no Vite at runtime), so the UI
#    renders from any machine on the network (`localhost`-pinned HMR assets would
#    break when opened from another box). A one-shot run of the vite image (node
#    toolchain) writes public/build; removing public/hot makes Laravel resolve
#    assets from that manifest. A dev box uses `docker compose up` → Vite/HMR.
echo "→ Building production front-end assets (one-shot — minutes on a Pi)…"
"${DC[@]}" run --rm --build --no-deps --entrypoint sh vite -c "npm install --no-audit --no-fund && npm run build"
rm -f public/hot

# 7. Reload the long-lived workers with the FINAL APP_KEY. php-fpm, horizon and
#    scheduler booted in step 1 BEFORE `key:generate` rewrote APP_KEY, so they
#    still hold the OLD key in memory. `federation:init --rotate` then wrote the
#    instance signing keypair to instance_settings.private_key_encrypted under
#    the NEW key — so every web/worker `Crypt::decryptString()` of it throws
#    "MAC is invalid", 500ing the UI and POST /api/federation/sync, while CLI
#    (fresh process = new key) worked the whole time (migrate/init/join). Bounce
#    them so they reload .env before nginx serves a single request.
echo "→ Reloading workers with the final APP_KEY…"
"${DC[@]}" restart app horizon scheduler

# nginx LAST — the app is healthy and public/build exists, so it comes up serving
# the built assets with no startup 502 and nothing to wait on.
echo "→ Starting nginx…"
"${DC[@]}" up -d nginx

echo "✓ Instance up (production assets) — http://localhost:${NGINX_PORT}"
