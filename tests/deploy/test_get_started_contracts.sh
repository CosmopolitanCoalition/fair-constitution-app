#!/usr/bin/env bash
#
# test_get_started_contracts.sh — get-started.sh contract harness (stub phase).
#
# Runs the REAL get-started.sh in an isolated temp dir with `docker` and `git` stubbed on
# PATH. The docker stub answers `docker info` with a chosen host size and FAILS every
# `docker compose` call, so a normal start stops at the first container step: no Docker, no
# network, no browser and no live world is touched. `--rederive` never reaches that step.
#
# Contracts (operator orders 2026-09-18, WoS demo box flags 1 and 3):
#
#   (1) update check   — a normal start still runs `git pull --ff-only`.
#   (2) --rederive     — never pulls (it re-measures the host; the code stays), exits 0.
#   (3) --no-pull      — never pulls.
#   (4) CGA_NO_PULL=1  — in .env, or in the shell: never pulls (a box that takes code only
#                        on the desk's GOOD TO PULL).
#   (5) flag order     — `--no-pull --rederive` still takes the rederive branch.
#   (6) needs kept     — 16 GB geodata host (the demo box shape): Horizon, the login service
#                        and the scheduler hold their needs, no donor is used, the caps fit.
#   (7) new supervisor — a seventh supervisor in config/horizon.php raises the Horizon need.
#   (8) tiny host      — a 4 GB host is below the resident minimum: the script says so, names
#                        the minimum host, and never over-commits.
#   The whole size family is swept by tests/deploy/test_sizing_family.sh.
#
# The boot check (operator order 2026-09-19: the cloud boxes resize themselves):
#
#   (9)  --boot, same host   — nothing is re-derived, nothing is recreated, nothing is pulled.
#   (10) --boot, RAM changed — every ledger value re-derives, the RUNNING services (and only
#                              those) are recreated, the config cache is re-baked, Horizon restarts.
#   (11) --boot, cores only  — a resize that keeps the RAM still re-derives (the cores trigger).
#   (12) --print-boot-unit   — the systemd unit runs `get-started.sh --boot` after docker.service.
#
# Run from the repo:  bash tests/deploy/test_get_started_contracts.sh
#
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
SCRIPT="$REPO/get-started.sh"
[[ -f "$SCRIPT" ]] || { echo "FATAL: get-started.sh not found at $SCRIPT" >&2; exit 2; }

FAILS=0
pass() { echo "  PASS: $1"; }
fail() { echo "  FAIL: $1" >&2; FAILS=$((FAILS + 1)); }
assert_contains() { grep -Fq -- "$3" "$2" && pass "$1" || fail "$1 (missing: $3)"; }
assert_absent()   { grep -Fq -- "$3" "$2" && fail "$1 (present but should be absent: $3)" || pass "$1"; }
assert_eq()       { [[ "$2" == "$3" ]] && pass "$1" || fail "$1 (want '$3' got '$2')"; }
assert_ge()       { (( $2 >= $3 )) && pass "$1 ($2 >= $3)" || fail "$1 (want >= $3 got $2)"; }
assert_le()       { (( $2 <= $3 )) && pass "$1 ($2 <= $3)" || fail "$1 (want <= $3 got $2)"; }

env_mb() { grep -E "^$2=" "$1" | tail -1 | cut -d= -f2- | tr -d 'mMB'; }

# make_workspace <host_mb> <profile> [extra .env line]
make_workspace() {
  local host_mb="$1" profile="$2" extra="${3:-}"
  local ws; ws="$(mktemp -d)"
  cp "$SCRIPT" "$ws/get-started.sh"
  : > "$ws/docker-compose.yml"
  mkdir -p "$ws/.git" "$ws/config" "$ws/routes" "$ws/bin"
  cp "$REPO/config/horizon.php" "$ws/config/horizon.php"
  cp "$REPO/routes/console.php" "$ws/routes/console.php"
  printf 'APP_KEY=base64:x\n' > "$ws/.env.example"
  {
    printf 'APP_KEY=base64:x\n'
    printf 'ARCHIVE_PATH=/srv/archive\n'
    printf 'CGA_MEM_PROFILE=%s\n' "$profile"
    [[ -n "$extra" ]] && printf '%s\n' "$extra"
  } > "$ws/.env"

  cat > "$ws/bin/git" <<'GIT'
#!/usr/bin/env bash
echo "$*" >> "$GIT_LOG"
case "$1" in rev-parse) echo deadbeef;; esac
exit 0
GIT
  cat > "$ws/bin/docker" <<DOCKER
#!/usr/bin/env bash
full="\$*"
[[ -n "\${DOCKER_LOG:-}" ]] && echo "\$full" >> "\$DOCKER_LOG"
case "\$full" in
  "info --format {{.MemTotal}}") echo \$(( \${STUB_HOST_MB:-$host_mb} * 1048576 )); exit 0;;
  "info --format {{.NCPU}}")     echo "\${STUB_NCPU:-8}"; exit 0;;
  info*)                          exit 0;;
  "compose version"*)             exit 0;;
esac
[[ "\${STUB_COMPOSE_OK:-0}" == "1" ]] || exit 1
case "\$full" in
  *"--status restarting"*)        exit 0;;
  *"ps --services --status running"*) printf 'app\nhorizon\npostgres\n'; exit 0;;
  *psql*)                         exit 1;;
  *)                              exit 0;;
esac
DOCKER
  chmod +x "$ws/bin/git" "$ws/bin/docker"
  echo "$ws"
}

# run_case <workspace> [VAR=val ...] -- <args...> ; echoes the exit code.
run_case() {
  local ws="$1"; shift
  local -a envs=()
  while [[ $# -gt 0 && "$1" != "--" ]]; do envs+=("$1"); shift; done
  [[ "${1:-}" == "--" ]] && shift
  : > "$ws/git.log"; : > "$ws/docker.log"
  ( cd "$ws" && env "${envs[@]}" PATH="$ws/bin:$PATH" GIT_LOG="$ws/git.log" DOCKER_LOG="$ws/docker.log" \
      bash "$ws/get-started.sh" "$@" > "$ws/out.log" 2>&1 < /dev/null )
  echo $?
}

echo "== (1) a normal start still checks for updates =="
WS="$(make_workspace 16384 serving)"
RC="$(run_case "$WS" --)"
assert_contains "git pull --ff-only ran"      "$WS/git.log" "pull --ff-only"
rm -rf "$WS"

echo "== (2) --rederive never pulls =="
WS="$(make_workspace 16384 serving)"
RC="$(run_case "$WS" -- --rederive)"
assert_eq       "exit 0"                      "$RC" "0"
assert_absent   "no git pull"                 "$WS/git.log" "pull"
assert_absent   "no git fetch"                "$WS/git.log" "fetch"
assert_contains "says why"                    "$WS/out.log" "Update check skipped (--rederive"
assert_contains "took the rederive branch"    "$WS/out.log" "Re-deriving host-sized values"
rm -rf "$WS"

echo "== (3) --no-pull never pulls =="
WS="$(make_workspace 16384 serving)"
RC="$(run_case "$WS" -- --no-pull)"
assert_absent   "no git pull"                 "$WS/git.log" "pull"
assert_contains "says why"                    "$WS/out.log" "Update check skipped (--no-pull"
rm -rf "$WS"

echo "== (4a) CGA_NO_PULL=1 in .env never pulls =="
WS="$(make_workspace 16384 serving "CGA_NO_PULL=1")"
RC="$(run_case "$WS" --)"
assert_absent   "no git pull"                 "$WS/git.log" "pull"
rm -rf "$WS"

echo "== (4b) CGA_NO_PULL=1 in the shell never pulls =="
WS="$(make_workspace 16384 serving)"
RC="$(run_case "$WS" CGA_NO_PULL=1 --)"
assert_absent   "no git pull"                 "$WS/git.log" "pull"
rm -rf "$WS"

echo "== (5) --no-pull --rederive still takes the rederive branch =="
WS="$(make_workspace 16384 serving)"
RC="$(run_case "$WS" -- --no-pull --rederive)"
assert_eq       "exit 0"                      "$RC" "0"
assert_contains "took the rederive branch"    "$WS/out.log" "Re-deriving host-sized values"
assert_absent   "no git pull"                 "$WS/git.log" "pull"
rm -rf "$WS"

echo "== (6) 16 GB geodata host: every service keeps its need and the caps fit =="
WS="$(make_workspace 15990 geodata)"
RC="$(run_case "$WS" -- --rederive)"
SUPS="$(grep -cE "^        'supervisor-[a-z0-9-]+' => \[" "$REPO/config/horizon.php")"
MASTER=$(( 15990 * 16 / 1024 )); (( MASTER > 256 )) && MASTER=256
NEED_HZ=$(( MASTER + (SUPS + 2 * SUPS - 1) * 64 + 2 * 192 ))
assert_eq       "exit 0"                      "$RC" "0"
assert_ge       "MEM_HORIZON holds the Horizon need" "$(env_mb "$WS/.env" MEM_HORIZON)" "$NEED_HZ"
assert_ge       "the login service holds its need"  "$(env_mb "$WS/.env" MEM_MAS)" "128"
assert_ge       "the scheduler holds its need"      "$(env_mb "$WS/.env" MEM_SCHEDULER)" "800"
assert_contains "says the needs are kept"     "$WS/out.log" "every service keeps its need"
assert_absent   "no donor, no restoration"    "$WS/out.log" "gives up"
BUDGET=$(( 15990 * 80 / 100 ))
SUM=0   # the services a LAN box runs (no dev server, no voice SFU, no public edge)
for k in POSTGRES_MEM_LIMIT MEM_HORIZON MEM_APP ETL_MEM_LIMIT MEM_REDIS_CACHE MEM_REDIS_QUEUE \
         MEM_MATRIX MEM_SCHEDULER MEM_MAS MEM_NGINX; do
  v="$(env_mb "$WS/.env" "$k")"; SUM=$(( SUM + ${v:-0} ))
done
assert_le       "the running caps fit the budget" "$SUM" "$BUDGET"
rm -rf "$WS"

echo "== (7) a new supervisor raises the Horizon need =="
WS="$(make_workspace 15990 geodata)"
sed -i.bak "s#^        'supervisor-prewarm' => \[#        'supervisor-extra' => [],\n        'supervisor-prewarm' => [#" "$WS/config/horizon.php" && rm -f "$WS/config/horizon.php.bak"
RC="$(run_case "$WS" -- --rederive)"
NEED_HZ7=$(( MASTER + ((SUPS + 1) + 2 * (SUPS + 1) - 1) * 64 + 2 * 192 ))
assert_ge       "MEM_HORIZON follows the larger tree" "$(env_mb "$WS/.env" MEM_HORIZON)" "$NEED_HZ7"
rm -rf "$WS"

echo "== (8) a 4 GB host is below the resident minimum and is told so =="
WS="$(make_workspace 4096 geodata)"
RC="$(run_case "$WS" -- --rederive)"
assert_eq       "exit 0"                      "$RC" "0"
assert_contains "the shortfall is stated"     "$WS/out.log" "BELOW THE RESIDENT MINIMUM"
assert_contains "the minimum host is named"   "$WS/out.log" "Minimum host for this set"
assert_ge       "no cap is zero or negative"  "$(env_mb "$WS/.env" MEM_HORIZON)" "8"
SUM=0
for k in POSTGRES_MEM_LIMIT MEM_HORIZON MEM_APP ETL_MEM_LIMIT MEM_REDIS_CACHE MEM_REDIS_QUEUE \
         MEM_MATRIX MEM_SCHEDULER MEM_MAS MEM_NGINX; do
  v="$(env_mb "$WS/.env" "$k")"; SUM=$(( SUM + ${v:-0} ))
done
assert_le       "the host is never over-committed" "$SUM" "$(( 4096 * 80 / 100 ))"
rm -rf "$WS"

echo "== (9) --boot on the same host changes nothing =="
WS="$(make_workspace 16384 serving)"
RC="$(run_case "$WS" STUB_COMPOSE_OK=1 -- --rederive)"          # the ledger is derived on a 16 GB, 8 core host
BEFORE="$(grep -E '^(MEM_|POSTGRES_MEM_LIMIT|PG_)' "$WS/.env" | sort)"
RC="$(run_case "$WS" STUB_COMPOSE_OK=1 -- --boot)"
assert_eq       "exit 0"                      "$RC" "0"
assert_contains "says the host is unchanged"  "$WS/out.log" "Host unchanged"
assert_absent   "nothing recreated"           "$WS/docker.log" "compose up"
assert_absent   "no git pull"                 "$WS/git.log" "pull"
assert_eq       "no derived value moved"      "$(grep -E '^(MEM_|POSTGRES_MEM_LIMIT|PG_)' "$WS/.env" | sort)" "$BEFORE"

echo "== (10) --boot after a RAM resize re-derives and recreates the running services =="
PG_BEFORE="$(env_mb "$WS/.env" POSTGRES_MEM_LIMIT)"
RC="$(run_case "$WS" STUB_COMPOSE_OK=1 STUB_HOST_MB=32768 -- --boot)"
PG_AFTER="$(env_mb "$WS/.env" POSTGRES_MEM_LIMIT)"
assert_eq       "exit 0"                      "$RC" "0"
assert_contains "names the RAM change"        "$WS/out.log" "host RAM changed (16384 -> 32768 MB)"
assert_ge       "the postgres cap grew"       "$PG_AFTER" "$(( PG_BEFORE + 1 ))"
assert_contains "only the running services are recreated" "$WS/docker.log" "compose up -d app horizon postgres"
assert_contains "the config cache is re-baked" "$WS/docker.log" "artisan config:cache"
assert_contains "Horizon restarts"            "$WS/docker.log" "compose restart horizon scheduler"
assert_absent   "no interface build"          "$WS/docker.log" "npm run build"
assert_absent   "no git pull"                 "$WS/git.log" "pull"
assert_eq       "the ledger names the new host" "$(env_mb "$WS/.env" DERIVED_HOST_MB)" "32768"

echo "== (11) --boot after a cores-only resize re-derives too =="
RC="$(run_case "$WS" STUB_COMPOSE_OK=1 STUB_HOST_MB=32768 STUB_NCPU=16 -- --boot)"
assert_eq       "exit 0"                      "$RC" "0"
assert_contains "names the cores change"      "$WS/out.log" "host cores changed (8 -> 16)"
assert_eq       "the postgres workers follow the cores" "$(env_mb "$WS/.env" PG_MAX_WORKER_PROCESSES)" "16"
assert_contains "the running services are recreated" "$WS/docker.log" "compose up -d app horizon postgres"
rm -rf "$WS"

echo "== (12) --print-boot-unit =="
WS="$(make_workspace 16384 serving)"
RC="$(run_case "$WS" -- --print-boot-unit)"
assert_eq       "exit 0"                      "$RC" "0"
assert_contains "runs after Docker"           "$WS/out.log" "After=docker.service"
assert_contains "runs the boot check"         "$WS/out.log" "get-started.sh --boot"
assert_contains "is a oneshot"                "$WS/out.log" "Type=oneshot"
assert_absent   "no git pull"                 "$WS/git.log" "pull"
rm -rf "$WS"

echo ""
if [[ "$FAILS" -eq 0 ]]; then
  echo "ALL GET-STARTED CONTRACT CASES PASSED"
  exit 0
else
  echo "$FAILS ASSERTION(S) FAILED"
  exit 1
fi
