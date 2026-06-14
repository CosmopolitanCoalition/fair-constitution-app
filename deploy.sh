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

echo "→ Bringing up the stack (project=${PROJECT}, prefix=${PREFIX}, nginx :${NGINX_PORT})…"
"${DC[@]}" up -d --build

echo "→ Waiting for PostgreSQL…"
for _ in $(seq 1 60); do
  if "${DC[@]}" exec -T postgres pg_isready -U fc_user -d fair_constitution >/dev/null 2>&1; then break; fi
  sleep 2
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

echo "✓ Instance up — http://localhost:${NGINX_PORT}"
