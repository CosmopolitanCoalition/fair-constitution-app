#!/usr/bin/env bash
# Cosmopolitan Governance App - get started (macOS / Linux / Raspberry Pi)
#
# Installs nothing by hand: checks Docker, downloads the app, ASKS the few
# settings that must be baked in before the containers are built (mainly: where
# your map-data files live), starts everything, and opens the setup page.
#
# Run from anywhere (downloads the app for you):
#   curl -fsSL https://raw.githubusercontent.com/CosmopolitanCoalition/fair-constitution-app/main/get-started.sh | bash
#
# Or, if you already downloaded the code, run it from inside that folder:
#   ./get-started.sh                (first run, or normal start)
#   ./get-started.sh --reconfigure  (change the map-data folder etc. and recreate)
#
# To preseed without any prompts (automation): set CGA_ARCHIVE_PATH before running.

set -euo pipefail

REPO="CosmopolitanCoalition/fair-constitution-app"
BRANCH="main"
RECONFIGURE=0
for a in "$@"; do [ "$a" = "--reconfigure" ] && RECONFIGURE=1; done

say()  { printf '\033[36m%s\033[0m\n' "$*"; }
fail() { printf '\n\033[31m%s\033[0m\n' "$*" >&2; exit 1; }

# Read a line from the TERMINAL (not stdin, which is the piped script under
# `curl | bash`). Echoes $2 default when there's no tty (automation). Usage:
#   ans="$(ask 'Prompt' 'default')"
ask() {
  local prompt="$1" default="${2:-}" ans=""
  if [ -e /dev/tty ]; then
    printf '%s' "$prompt" > /dev/tty
    IFS= read -r ans < /dev/tty || ans=""
  fi
  [ -n "$ans" ] && printf '%s' "$ans" || printf '%s' "$default"
}

get_env() { [ -f .env ] && grep -E "^[[:space:]]*$1=" .env | tail -1 | cut -d= -f2- | tr -d '"' || true; }

set_env() {
  local key="$1" val="$2"
  if grep -qE "^[[:space:]]*$key=" .env 2>/dev/null; then
    # Portable in-place replace (BSD + GNU sed differ on -i); rewrite the file.
    awk -v k="$key" -v v="$val" 'BEGIN{done=0} $0 ~ "^[[:space:]]*"k"=" && !done {print k"="v; done=1; next} {print} END{if(!done) print k"="v}' .env > .env.tmp && mv .env.tmp .env
  else
    printf '%s=%s\n' "$key" "$val" >> .env
  fi
}

# Ask where the map-data files live and write ARCHIVE_PATH / PROTOMAPS_DIR into
# .env BEFORE the containers are built, so the folder is mounted from the first
# boot - removing the mid-setup `docker compose up -d` recreate entirely.
configure_map_data() {
  local first_run="$1" current is_default candidate detected="" default ans
  current="$(get_env ARCHIVE_PATH)"
  if [ -z "$current" ] || [ "$current" = "./data/archive" ]; then is_default=1; else is_default=0; fi

  if [ -n "${CGA_ARCHIVE_PATH:-}" ]; then
    set_env ARCHIVE_PATH "$CGA_ARCHIVE_PATH"
    set_env PROTOMAPS_DIR "$CGA_ARCHIVE_PATH/protomaps_pmtiles"
    say "      Map-data folder set from CGA_ARCHIVE_PATH: $CGA_ARCHIVE_PATH"
    return
  fi

  # Ask on first run, when unset/default, or on --reconfigure.
  if [ "$first_run" != "1" ] && [ "$is_default" != "1" ] && [ "$RECONFIGURE" != "1" ]; then return; fi
  [ -e /dev/tty ] || return   # non-interactive: keep defaults

  for candidate in "$HOME/fair-constitution-map-files" "$HOME/Downloads/fair-constitution-map-files" "/mnt/fair-constitution-map-files"; do
    if [ -d "$candidate/geoBoundaries_repo" ] || [ -d "$candidate/worldpop_100m_latest" ]; then detected="$candidate"; break; fi
  done
  if [ -n "$detected" ]; then default="$detected"; elif [ "$is_default" != "1" ]; then default="$current"; else default=""; fi

  say ""
  say "Map data (boundaries + population)."
  printf '  If you already downloaded the geoBoundaries + WorldPop files, tell me the\n'
  printf '  folder and the app reads them directly. Leave blank to skip - you can\n'
  printf '  download them later from inside the app.\n'
  [ -n "$detected" ] && printf '  (Found a likely folder: %s)\n' "$detected"

  if [ -n "$default" ]; then ans="$(ask "Map data folder [$default]: " "$default")"; else ans="$(ask 'Map data folder (blank to skip): ' '')"; fi

  if [ -z "$ans" ]; then
    say "  No folder set - point at it later with --reconfigure, or use the in-app download."
    return
  fi
  # Strip a surrounding pair of quotes (some file managers copy a path wrapped in
  # them) - an interior quote would corrupt the .env line and break the mount.
  case "$ans" in \"*\") ans="${ans#\"}"; ans="${ans%\"}";; \'*\') ans="${ans#\'}"; ans="${ans%\'}";; esac
  ans="${ans%/}"
  set_env ARCHIVE_PATH "$ans"
  set_env PROTOMAPS_DIR "$ans/protomaps_pmtiles"
  say "  Map-data folder set to $ans  (applied when the app starts below - no docker commands needed)."
}

# -- 1. Is Docker installed and running? -------------------------------------
say "[1/5] Checking Docker..."
command -v docker >/dev/null 2>&1 || fail "Docker is not installed.
- Mac: install Docker Desktop from https://www.docker.com/products/docker-desktop/
- Linux / Raspberry Pi:  curl -fsSL https://get.docker.com | sh
  then:  sudo usermod -aG docker \$USER   and log out and back in.
Then run this again."

docker info >/dev/null 2>&1 || fail "Docker is installed but not running (or your user lacks permission).
- Docker Desktop: open it and wait until it says \"Engine running\".
- Linux:  sudo systemctl start docker
  (and if you just ran usermod, log out and back in first).
Then run this again."

docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 is missing.
On Debian / Ubuntu / Raspberry Pi OS:  sudo apt install -y docker-compose-plugin
Then run this again."

# -- 2. Find or download the app code ----------------------------------------
JUST_DOWNLOADED=0
if [ -f docker-compose.yml ]; then
  APP_DIR="$(pwd)"
  say "[2/5] Using the app code in this folder: $APP_DIR"
else
  APP_DIR="$HOME/fair-constitution-app"
  if [ -f "$APP_DIR/docker-compose.yml" ]; then
    say "[2/5] Found the app at $APP_DIR"
  elif command -v git >/dev/null 2>&1; then
    JUST_DOWNLOADED=1
    say "[2/5] Downloading the app to $APP_DIR ..."
    git clone --depth 1 -b "$BRANCH" "https://github.com/$REPO.git" "$APP_DIR"
  else
    JUST_DOWNLOADED=1
    say "[2/5] Downloading the app to $APP_DIR (no git found - using a ZIP) ..."
    command -v unzip >/dev/null 2>&1 || fail "Need either git or unzip installed. Easiest:  sudo apt install -y git   then run this again."
    tmp="$(mktemp -d)"
    curl -fsSL "https://github.com/$REPO/archive/refs/heads/$BRANCH.zip" -o "$tmp/app.zip"
    unzip -q "$tmp/app.zip" -d "$tmp"
    mv "$tmp/fair-constitution-app-$BRANCH" "$APP_DIR"
    rm -rf "$tmp"
  fi
fi
cd "$APP_DIR"

# -- 2b. Keep an existing install up to date ----------------------------------
# Re-running the start command IS the update path: pull the latest code and,
# when it changed, apply it after step 5 (migrations + interface build +
# worker restart). ZIP-era installs get connected to the update channel once;
# settings (.env) and data are untracked and untouched by any of this.
UPDATED=0
if [ "$JUST_DOWNLOADED" != "1" ] && command -v git >/dev/null 2>&1; then
  if [ ! -d .git ]; then
    say "      Connecting this install to the update channel (one-time)..."
    git init -q \
      && git remote add origin "https://github.com/$REPO.git" \
      && git fetch --depth 1 -q origin "$BRANCH" \
      && git checkout -f -q -B "$BRANCH" "origin/$BRANCH" \
      && UPDATED=1 \
      || say "      Could not connect to the update channel (offline?) - continuing with the current code."
  else
    before="$(git rev-parse HEAD 2>/dev/null || true)"
    say "      Checking for updates..."
    if git pull --ff-only -q origin "$BRANCH" 2>/dev/null; then
      after="$(git rev-parse HEAD 2>/dev/null || true)"
      if [ -n "$after" ] && [ "$before" != "$after" ]; then
        UPDATED=1
        say "      Update downloaded - it will be applied after the app starts."
      fi
    else
      say "      Could not check for updates (offline?) - continuing with the current code."
    fi
  fi
fi

# -- 3. First-run settings file ----------------------------------------------
FIRST_RUN=0
if [ ! -f .env ]; then
  FIRST_RUN=1
  cp .env.example .env
  say "[3/5] Created your settings file (.env) from the defaults."
  # ARM computers (Raspberry Pi, Apple Silicon): the default database image is
  # Intel-only - switch to the multi-arch build of the same Postgres 17 + PostGIS 3.5.
  arch="$(uname -m)"
  if [ "$arch" = "aarch64" ] || [ "$arch" = "arm64" ]; then
    echo "POSTGIS_IMAGE=imresamu/postgis:17-3.5" >> .env
    say "      ARM computer detected - selected the ARM-compatible database image."
  fi
else
  say "[3/5] Keeping your existing settings file (.env)."
fi

# -- 4. Configure the build-dependent settings (before the containers build) --
# Host-derived memory posture (operator ruling 2026-08-02: never hard-code
# what the hardware can answer — this stack must scale from a Raspberry Pi
# to big iron). Measure host RAM once and write the container caps + PG
# tuning into .env; docker-compose.yml consumes them with safe fallbacks.
# WRITE-IF-ABSENT: an operator's hand-set value is never clobbered.
configure_host_memory() {
  # Docker's OWN memory ceiling is the truthful host: on native Linux (a
  # Pi, a server) it equals machine RAM; on Docker Desktop it is the VM's
  # allocation — sizing against raw machine RAM there would over-commit
  # the VM and resurrect the mutual postgres/etl OOM.
  total_mb=$(( $(docker info --format '{{.MemTotal}}' 2>/dev/null || echo 0) / 1048576 ))
  if [ "${total_mb:-0}" -le 0 ] && [ -r /proc/meminfo ]; then
    total_mb=$(awk '/^MemTotal:/ {print int($2/1024)}' /proc/meminfo)
  fi
  if [ "${total_mb:-0}" -le 0 ] && command -v sysctl >/dev/null 2>&1; then
    total_mb=$(( $(sysctl -n hw.memsize 2>/dev/null || echo 0) / 1048576 ))
  fi
  [ "${total_mb:-0}" -gt 0 ] || return 0   # can't measure — compose fallbacks apply

  clamp() { v=$1; lo=$2; hi=$3; [ "$v" -lt "$lo" ] && v=$lo; [ "$v" -gt "$hi" ] && v=$hi; echo "$v"; }
  # Ceiling lifted (audit row, 2026-08-30): the frozen 16 GB cap starved
  # burst iron — a 256 GB box got a 16 GB postgres. 60% holds at any size.
  pg_mb=$(clamp $(( total_mb * 60 / 100 )) 1024 262144)   # postgres ~60% of the host
  # ETL_MEM_LIMIT is no longer written (THE LIVE WALL, 2026-08-06): etl runs
  # uncapped, admission sizes against MemAvailable on the fly, and chunk
  # profiles self-govern to the 30% posture in code. An existing .env pin
  # still wins — blank it to go live-wall.

  # THE HEADROOM LAW (2026-08-02): a giant boundary INSERT is one backend
  # whose transient is set by the DATA (largest single feature on Earth),
  # not by this host — shared_buffers stays SMALL so (cap - shared_buffers)
  # funds that backend. The old 12.5%-of-host derivation shaved the legacy
  # Phase-L headroom and kernel-kill-looped giants. Mirrors get-started.ps1.

  # Cores for the parallel posture (lane 2G's audit, operator order
  # 2026-08-30). Docker's own count is the truthful host, same as memory.
  cores=$(docker info --format '{{.NCPU}}' 2>/dev/null || echo 0)
  [ "${cores:-0}" -gt 0 ] || cores=$(nproc 2>/dev/null || echo 4)

  # THE RE-DERIVE MECHANISM (operator order 2026-08-30, the cloud-box
  # enabler): every value this function writes is listed in the
  # DERIVED_KEYS ledger inside .env. `./get-started.sh --rederive` (after
  # a resize) re-measures the host and overwrites every value STILL in the
  # ledger; a hand-pinned value is one the operator edited AND removed
  # from DERIVED_KEYS — it is never clobbered. A missing ledger
  # bootstraps itself: any current value that exactly equals its fresh
  # derivation is self-evidently derived and joins the ledger.
  ledger="$(get_env DERIVED_KEYS)"
  ledger_boot=0; [ -z "$ledger" ] && ledger_boot=1
  in_ledger()  { case ",$ledger," in *",$1,"*) return 0;; *) return 1;; esac; }
  ledger_add() { in_ledger "$1" || ledger="${ledger:+$ledger,}$1"; }
  wrote=0
  write_derived() { # key fresh-value
    cur="$(get_env "$1")"
    if [ -z "$cur" ]; then
      set_env "$1" "$2"; wrote=1; ledger_add "$1"
    elif [ "$ledger_boot" -eq 1 ] && [ "$cur" = "$2" ]; then
      ledger_add "$1"
    elif [ "${REDERIVE:-0}" = "1" ] && in_ledger "$1" && [ "$cur" != "$2" ]; then
      set_env "$1" "$2"; wrote=1
      say "      rederived $1: $cur -> $2"
    fi
  }
  # Ceilings lifted where they only bound big iron (audit rows 2026-08-30).
  # DELIBERATELY KEPT: shared_buffers ≤ 512MB (the headroom law — cap minus
  # buffers must fund the largest single-feature insert transient) and
  # global work_mem ≤ 64MB (per-connection safety; districting lanes raise
  # their own sessions via HostCapacity::laneWorkMemMb).
  write_derived POSTGRES_MEM_LIMIT "${pg_mb}m"
  write_derived PG_SHARED_BUFFERS  "$(clamp $(( pg_mb / 9 )) 128  512)MB"
  write_derived PG_EFFECTIVE_CACHE "$(clamp $(( pg_mb * 80 / 100 )) 256 210000)MB"
  write_derived PG_WORK_MEM        "$(clamp $(( pg_mb / 72 ))   8   64)MB"
  write_derived PG_MAINTENANCE_MEM "$(clamp $(( pg_mb / 9 )) 128 4096)MB"
  write_derived PG_MAX_WAL         "$(clamp $(( pg_mb * 160 / 100 )) 512 65536)MB"
  write_derived PG_MIN_WAL         "$(clamp $(( pg_mb * 20 / 100 )) 512 8192)MB"
  write_derived PG_WAL_BUFFERS     "$(clamp $(( pg_mb / 72 )) 16 128)MB"
  write_derived PG_SHM_SIZE        "$(clamp $(( pg_mb / 4 )) 1024 8192)m"
  write_derived PG_MAX_CONNECTIONS "$(clamp $(( pg_mb / 23 )) 100 800)"
  write_derived REDIS_CACHE_MAXMEMORY "$(clamp $(( total_mb / 10 )) 768 8192)mb"
  # Parallel posture: workers=cores, parallel=cores/2, per_gather small
  # (many concurrent lanes beat wide gathers), maintenance=cores/4.
  write_derived PG_MAX_WORKER_PROCESSES  "$(clamp "$cores" 8 64)"
  write_derived PG_MAX_PARALLEL_WORKERS  "$(clamp $(( cores / 2 )) 2 32)"
  write_derived PG_PARALLEL_PER_GATHER   "$(clamp $(( cores / 6 )) 1 4)"
  write_derived PG_PARALLEL_MAINTENANCE  "$(clamp $(( cores / 4 )) 1 8)"
  # The queue redis (noeviction) sizes from the host; the split itself is
  # host-independent wiring, written once, never rederived.
  write_derived REDIS_QUEUE_MAXMEMORY "$(clamp $(( total_mb / 40 )) 128 1024)mb"
  write_derived PG_AUTOVACUUM_COST_LIMIT "$(clamp $(( 200 * cores / 2 )) 200 2000)"
  [ -n "$(get_env REDIS_QUEUE_HOST)" ] || set_env REDIS_QUEUE_HOST redis_queue
  set_env DERIVED_KEYS "$ledger"
  [ "$wrote" -eq 1 ] && say "      Memory sized from this host (${total_mb} MB RAM, ${cores} cores): postgres ${pg_mb}m, etl uncapped (live wall)."
  return 0
}

# --rederive (operator order 2026-08-30): after a host resize — the cloud
# burst box throttling up or down — re-measure and overwrite every value
# still in the DERIVED_KEYS ledger, report what changed, and stop. The
# operator recreates the named services when ready; nothing boots here.
if [ "${1:-}" = "--rederive" ]; then
  REDERIVE=1
  say "Re-deriving host-sized values (hand-pinned values — those removed from DERIVED_KEYS — stay untouched)..."
  configure_host_memory
  say "Done. Changed values apply on service RECREATE:"
  say "  postgres  — POSTGRES_MEM_LIMIT + every PG_* value"
  say "  redis_queue — REDIS_QUEUE_MAXMEMORY"
  say "  docker compose up -d --force-recreate postgres redis_queue   (when the box is quiet)"
  exit 0
fi

say "[4/5] Configuring..."
configure_map_data "$FIRST_RUN"
configure_host_memory

# -- 5. Start the app --------------------------------------------------------
say "[5/5] Starting the app. The FIRST run downloads and builds everything (10-30 minutes); later starts take seconds..."
if ! docker compose up -d; then
  # A first boot occasionally trips over itself (a container loses a race and
  # restarts) - re-issuing the same command resumes and finishes the job.
  say "The first start reported a problem - giving it one more push (this is usually enough)..."
  sleep 20
  docker compose up -d || fail "The app containers had trouble starting. This script is safe to run again and resumes where it left off - try that first. If it keeps failing, run:  docker compose logs app --tail 50   in this folder and report what it says."
fi

# FRESH INSTALL: load the database schema (fresh-install walk, 2026-08-02 —
# a brand-new clone booted with an EMPTY database and /setup 500'd; the
# update branch below migrates, but a first install never did). The app
# container composer-installs on first boot (minutes); wait for its DONE
# stamp (vendor/.installed-hash) before migrating.
if [ "$JUST_DOWNLOADED" = "1" ] || [ "$FIRST_RUN" = "1" ]; then
  say "Loading the database schema (waits for the first-boot dependency install)..."
  i=0
  while [ "$i" -lt 240 ]; do
    if docker compose exec -T app test -f vendor/.installed-hash >/dev/null 2>&1; then break; fi
    i=$((i+1)); sleep 5
  done
  if ! docker compose exec -T app php artisan migrate --force; then
    sleep 15
    docker compose exec -T app php artisan migrate --force || say "  Schema load failed - run:  docker compose exec app php artisan migrate --force   once the app is up."
  fi
fi

# Apply a downloaded update inside the running app: database migrations, a
# fresh interface build, and a worker restart so queued jobs load the new code.
if [ "$UPDATED" = "1" ]; then
  say "Applying the update (database changes + interface build)..."
  if ! docker compose exec -T app php artisan migrate --force; then
    # The database container can still be waking up right after `up -d`.
    sleep 15
    docker compose exec -T app php artisan migrate --force || say "  Migration failed - run:  docker compose exec app php artisan migrate --force   once the app is up."
  fi
  docker compose run --rm --no-deps vite npm run build || say "  Interface build failed - run:  docker compose run --rm --no-deps vite npm run build"
  docker compose restart app horizon scheduler || true
  say "Update applied."
fi

PORT="$(grep -E '^[[:space:]]*NGINX_HOST_PORT=' .env | tail -1 | cut -d= -f2 | tr -d '[:space:]' || true)"
[ -n "${PORT:-}" ] || PORT=8080
URL="http://localhost:$PORT/setup"

if ! command -v curl >/dev/null 2>&1; then
  say "Started. Open $URL in your browser once the first build finishes (10-30 minutes)."
  exit 0
fi

say "Waiting for $URL to come up (this is the long part on a first run)..."
up=0
for _ in $(seq 1 240); do
  if curl -fsS -o /dev/null --max-time 5 "$URL" 2>/dev/null; then up=1; break; fi
  sleep 10
  printf '.'
done
printf '\n'

if [ "$up" = "1" ]; then
  say "Ready! Open $URL"
  if command -v xdg-open >/dev/null 2>&1; then xdg-open "$URL" >/dev/null 2>&1 || true
  elif command -v open >/dev/null 2>&1; then open "$URL" || true
  fi
  say "The setup wizard in your browser takes it from here."
else
  say "It's still building - that can be normal on a slower connection. Leave it running"
  say "and open $URL in your browser in a little while."
  say "(To watch progress: docker compose logs -f --tail 20)"
fi
