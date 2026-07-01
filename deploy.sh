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

# FEDERATION_SELF_URL: the callback URL peers reach THIS box at. `host.docker.internal` is a
# machine-RELATIVE alias — a remote peer resolves it to ITSELF, so it only works for two instances
# on ONE host. On a real machine, advertise this host's LAN IP instead, so a plain `./deploy.sh` on a
# LAN box (Box B) is reachable without anyone remembering --self-url. --self-url always wins (and
# bootstrap.sh passes it for an overlay transport — tailnet/yggdrasil/onion). Fallback stays
# host.docker.internal for a single-host box where no LAN IP is detectable.
detect_lan_ip() {
  local ip=""
  # Linux (iproute2): the source IP the kernel would use to reach the internet = the primary LAN NIC.
  ip=$(ip route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1); exit}}')
  [[ -z "$ip" ]] && ip=$(hostname -I 2>/dev/null | awk '{print $1}')      # Linux fallback
  [[ -z "$ip" ]] && ip=$(ipconfig getifaddr en0 2>/dev/null)              # macOS fallback
  printf '%s' "$ip"
}
if [[ -z "$SELF_URL" ]]; then
  LAN_IP="$(detect_lan_ip)"
  if [[ -n "$LAN_IP" ]]; then
    SELF_URL="http://${LAN_IP}:${NGINX_PORT}"
  else
    SELF_URL="http://host.docker.internal:${NGINX_PORT}"
  fi
fi
echo "→ FEDERATION_SELF_URL = ${SELF_URL}   (peers reach this box here; override with --self-url)"
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
# Pin the compose project so the plain `docker compose exec/down` commands in
# docs/FRESH-NODE-START.md resolve to the SAME project this script brought up with `-p "$PROJECT"`.
# Without it, compose derives the project from the directory name and the doc's bare commands miss
# the just-deployed containers. (.env.example deliberately omits this so each git worktree still
# auto-derives its own per-dir project and they never collide.)
set_env COMPOSE_PROJECT_NAME "$PROJECT"
set_env NGINX_HOST_PORT    "$NGINX_PORT"
set_env POSTGRES_HOST_PORT "$PG_PORT"
set_env VITE_HOST_PORT     "$VITE_PORT"
set_env FEDERATION_SELF_URL "$SELF_URL"
set_env APP_URL            "http://localhost:${NGINX_PORT}"

# Architecture: the official postgis image is amd64-only; on arm64 (Pi, ARM
# servers, Apple Silicon) use the multi-arch rebuild. amd64 keeps the default.
case "$(uname -m)" in aarch64|arm64) set_env POSTGIS_IMAGE imresamu/postgis:17-3.5 ;; esac

# Matrix homeserver image (Phase K-3). Synapse is the verified default on EVERY arch
# (feature-complete: v12 immutable-creator + appservice + MAS; matrix-org/synapse was archived
# Apr 2024 → maintained image at element-hq, AGPLv3). The lighter Dendrite as the arm64/Pi
# default is DEFERRED to the K3-N rig spike (its v12 / appservice-sole-creator / whitelist support
# is unverified there); until it passes, deploy pulls Synapse everywhere. Override to test Dendrite.
set_env MATRIX_IMPL  synapse
set_env MATRIX_IMAGE ghcr.io/element-hq/synapse:latest
# MAS (the OIDC auth service fronting Synapse, Phase K-3). NOTE: a production deploy must run
# `php artisan matrix:setup` (K3-D) to regenerate the MAS config + the Synapse-shared secret BEFORE
# bringing MAS up — the committed docker/matrix/mas/config.yaml carries DEV-ONLY secrets. So this
# only sets the image; the `mas` service is brought up by matrix:setup, not here.
set_env MAS_IMAGE ghcr.io/element-hq/matrix-authentication-service:latest

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
# Probe over TCP (-h 127.0.0.1), NOT the Unix socket. On a fresh `down -v` the postgres image runs initdb
# on a TRANSIENT temp-server that listens on the local SOCKET ONLY (listen_addresses='') while it runs
# /docker-entrypoint-initdb.d/init.sql — which CREATEs the matrix/matrix_auth DBs — THEN restarts into the
# real TCP server. A SOCKET probe (psql/pg_isready with no -h) clears on that temp-server BEFORE init.sql
# finishes, so the next step's matrix-DB guard races init.sql and double-CREATEs `matrix` ("duplicate key
# … datname=matrix already exists" → set -e aborts the cold deploy). A TCP probe is invisible to the
# socket-only temp-server, so it clears only once the REAL server is up AND past recovery — by which point
# init.sql has fully run and the matrix DBs already exist, so the guard below correctly skips them.
for _ in $(seq 1 90); do
  if "${DC[@]}" exec -T postgres pg_isready -h 127.0.0.1 -U fc_user -d fair_constitution >/dev/null 2>&1; then break; fi
  sleep 2
done

# Phase K-3: ensure the Matrix + MAS logical DBs exist before the homeserver boots. init.sql
# CREATE DATABASE runs ONLY on a fresh postgres volume; an in-place upgrade with a warm volume
# needs this idempotent guard. Synapse REQUIRES C collation (the server-wide --locale=C gives it).
echo "→ Ensuring the Matrix logical databases…"
for db in matrix matrix_auth; do
  if ! "${DC[@]}" exec -T postgres psql -U fc_user -d fair_constitution -tAc "SELECT 1 FROM pg_database WHERE datname='${db}'" | grep -q 1; then
    "${DC[@]}" exec -T postgres psql -U fc_user -d fair_constitution -c \
      "CREATE DATABASE ${db} WITH OWNER fc_user ENCODING 'UTF8' LC_COLLATE 'C' LC_CTYPE 'C' TEMPLATE template0"
  fi
done

# Bring the homeserver up now that its DB exists (it crash-loops if it boots first).
echo "→ Starting the Matrix homeserver…"
"${DC[@]}" up -d matrix

# Bring up the Matrix Auth Service (MAS) too. The committed docker/matrix/mas config carries DEV-ONLY
# secrets — fine for a LAN rig; a PUBLIC deploy should run `php artisan matrix:setup` first to regenerate
# them. Without this, a fresh founder gets NO Matrix login until `docker compose up -d mas` is run by hand
# (the gap Box B hit). The matrix_auth DB was ensured in the loop above.
echo "→ Starting the Matrix Auth Service…"
"${DC[@]}" up -d mas

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
  # Redirect BOTH streams: while the container is mid-init `docker compose exec` prints a
  # transient "FailedPrecondition: init process is not running" to STDOUT, which a lone
  # `2>/dev/null` misses — it then leaks into the deploy log and trips the doc's "copy the
  # error, tell your operator" guidance even though it self-clears on the next iteration.
  if "${DC[@]}" exec -T app test -f vendor/.installed-hash >/dev/null 2>&1; then break; fi
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

# 3. Federation identity. EVERY deployed node is federation-capable — the mesh is the
#    point — so this runs UNCONDITIONALLY, not only on --join. A --self-url peer (the
#    discover→handshake path in docs/FRESH-NODE-START.md, now that mirror-join is retired)
#    needs it too: without it `federation_enabled` stays false, every /api/federation/*
#    404s, and `mesh:gates` reports "not ready to federate" with no step that fixes it.
#    `federation:init` is idempotent (reuses the existing server_id), mints the identity
#    under the APP_KEY generated above, enables the mesh endpoints, and arms CLK-20.
#    --rotate ONLY on --join: a clone that carried in a keypair encrypted under the OLD
#    APP_KEY must re-key; a from-scratch peer has none, so a plain init is correct.
echo "→ Minting the federation identity…"
if [[ -n "$JOIN_URL" ]]; then
  art federation:init --rotate
else
  art federation:init
fi

# Deploy-side readiness assertion — the backstop the command-level guard can't fully cover.
# mesh:gates exits non-zero on a hard FAIL (federation off / identity not minted), so `set -e`
# aborts HERE rather than shipping a node that 404s every peer. WARN gates (no transport/peer
# yet) do not abort. This is what makes "Step 4 green in one cold pass" enforced, not hoped for.
echo "→ Verifying federation readiness…"
art mesh:gates

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
