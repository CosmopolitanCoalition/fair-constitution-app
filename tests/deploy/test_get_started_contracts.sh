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
#   (6) Horizon floor  — derived from the supervisor tree in config/horizon.php and it
#                        SURVIVES the budget scaler: 16 GB geodata host (the demo box shape)
#                        lands MEM_HORIZON at the floor, the etl gives up the gap, and the
#                        non-postgres caps still fit the budget.
#   (7) new supervisor — a seventh supervisor in config/horizon.php raises the floor.
#   (8) tiny host      — a 4 GB host cannot meet the floor: the script says so and no donor
#                        is taken below half of its scaled cap.
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
case "\$full" in
  "info --format {{.MemTotal}}") echo $(( host_mb * 1048576 )); exit 0;;
  "info --format {{.NCPU}}")     echo 8; exit 0;;
  info*)                          exit 0;;
  "compose version"*)             exit 0;;
  *)                              exit 1;;
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
  : > "$ws/git.log"
  ( cd "$ws" && env "${envs[@]}" PATH="$ws/bin:$PATH" GIT_LOG="$ws/git.log" \
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

echo "== (6) the Horizon floor survives the scaler (16 GB geodata host) =="
WS="$(make_workspace 15990 geodata)"
RC="$(run_case "$WS" -- --rederive)"
SUPS="$(grep -cE "^        'supervisor-[a-z0-9-]+' => \[" "$REPO/config/horizon.php")"
FLOOR=$(( (1 + SUPS + 2 * SUPS) * 80 + 256 ))
HZ="$(env_mb "$WS/.env" MEM_HORIZON)"
assert_eq       "exit 0"                      "$RC" "0"
assert_ge       "MEM_HORIZON at or above the derived floor" "$HZ" "$FLOOR"
assert_contains "the floor was restored"      "$WS/out.log" "Horizon floor restored (${FLOOR}m)"
assert_contains "the etl gave up the gap"     "$WS/out.log" "Horizon floor: etl gives up"
BUDGET=$(( 15990 * 80 / 100 ))
SUM=0
for k in POSTGRES_MEM_LIMIT MEM_HORIZON MEM_APP MEM_VITE ETL_MEM_LIMIT MEM_REDIS_CACHE MEM_REDIS_QUEUE \
         MEM_MATRIX MEM_SCHEDULER MEM_MAS MEM_NGINX MEM_LIVEKIT MEM_EDGE; do
  v="$(env_mb "$WS/.env" "$k")"; SUM=$(( SUM + ${v:-0} ))
done
assert_le       "every cap together still fits the budget" "$SUM" "$BUDGET"
rm -rf "$WS"

echo "== (7) a new supervisor raises the floor =="
WS="$(make_workspace 15990 geodata)"
sed -i.bak "s#^        'supervisor-prewarm' => \[#        'supervisor-extra' => [],\n        'supervisor-prewarm' => [#" "$WS/config/horizon.php" && rm -f "$WS/config/horizon.php.bak"
RC="$(run_case "$WS" -- --rederive)"
FLOOR7=$(( (1 + (SUPS + 1) + 2 * (SUPS + 1)) * 80 + 256 ))
HZ7="$(env_mb "$WS/.env" MEM_HORIZON)"
assert_ge       "MEM_HORIZON follows the larger tree" "$HZ7" "$FLOOR7"
rm -rf "$WS"

echo "== (8) a 4 GB host cannot meet the floor and is told so =="
WS="$(make_workspace 4096 geodata)"
RC="$(run_case "$WS" -- --rederive)"
assert_eq       "exit 0"                      "$RC" "0"
assert_contains "the shortfall is stated"     "$WS/out.log" "cannot be met on this host"
ETL="$(env_mb "$WS/.env" ETL_MEM_LIMIT)"
assert_ge       "the etl keeps a working cap" "$ETL" "128"
rm -rf "$WS"

echo ""
if [[ "$FAILS" -eq 0 ]]; then
  echo "ALL GET-STARTED CONTRACT CASES PASSED"
  exit 0
else
  echo "$FAILS ASSERTION(S) FAILED"
  exit 1
fi
