#!/usr/bin/env bash
# Cosmopolitan Governance App - get started (macOS / Linux / Raspberry Pi)
#
# Gets the app onto this computer, starts it in Docker, and opens the setup
# page in your browser. Safe to run again any time - it reuses what exists.
#
# Run from anywhere (downloads the app for you):
#   curl -fsSL https://raw.githubusercontent.com/CosmopolitanCoalition/fair-constitution-app/main/get-started.sh | bash
#
# Or, if you already downloaded the code, run it from inside that folder:
#   ./get-started.sh

set -euo pipefail

REPO="CosmopolitanCoalition/fair-constitution-app"
BRANCH="main"

say()  { printf '\033[36m%s\033[0m\n' "$*"; }
fail() { printf '\n\033[31m%s\033[0m\n' "$*" >&2; exit 1; }

# -- 1. Is Docker installed and running? -------------------------------------
say "[1/4] Checking Docker..."
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
if [ -f docker-compose.yml ]; then
  APP_DIR="$(pwd)"
  say "[2/4] Using the app code in this folder: $APP_DIR"
else
  APP_DIR="$HOME/fair-constitution-app"
  if [ -f "$APP_DIR/docker-compose.yml" ]; then
    say "[2/4] Found the app at $APP_DIR"
  elif command -v git >/dev/null 2>&1; then
    say "[2/4] Downloading the app to $APP_DIR ..."
    git clone --depth 1 -b "$BRANCH" "https://github.com/$REPO.git" "$APP_DIR"
  else
    say "[2/4] Downloading the app to $APP_DIR (no git found - using a ZIP) ..."
    command -v unzip >/dev/null 2>&1 || fail "Need either git or unzip installed. Easiest:  sudo apt install -y git   then run this again."
    tmp="$(mktemp -d)"
    curl -fsSL "https://github.com/$REPO/archive/refs/heads/$BRANCH.zip" -o "$tmp/app.zip"
    unzip -q "$tmp/app.zip" -d "$tmp"
    mv "$tmp/fair-constitution-app-$BRANCH" "$APP_DIR"
    rm -rf "$tmp"
  fi
fi
cd "$APP_DIR"

# -- 3. First-run settings file ----------------------------------------------
if [ ! -f .env ]; then
  cp .env.example .env
  say "[3/4] Created your settings file (.env) from the defaults."
  # ARM computers (Raspberry Pi, Apple Silicon): the default database image is
  # Intel-only - switch to the multi-arch build of the same Postgres 17 + PostGIS 3.5.
  arch="$(uname -m)"
  if [ "$arch" = "aarch64" ] || [ "$arch" = "arm64" ]; then
    echo "POSTGIS_IMAGE=imresamu/postgis:17-3.5" >> .env
    say "      ARM computer detected - selected the ARM-compatible database image."
  fi
else
  say "[3/4] Keeping your existing settings file (.env)."
fi

# -- 4. Start the app --------------------------------------------------------
say "[4/4] Starting the app. The FIRST run downloads and builds everything (10-30 minutes); later starts take seconds..."
if ! docker compose up -d; then
  # A first boot occasionally trips over itself (a container loses a race and
  # restarts) - re-issuing the same command resumes and finishes the job.
  say "The first start reported a problem - giving it one more push (this is usually enough)..."
  sleep 20
  docker compose up -d || fail "The app containers had trouble starting. This script is safe to run again and resumes where it left off - try that first. If it keeps failing, run:  docker compose logs app --tail 50   in this folder and report what it says."
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
