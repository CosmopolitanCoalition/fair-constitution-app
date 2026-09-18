#!/usr/bin/env bash
#
# test_installer_contracts.sh — deploy.sh installer failure/retry contract harness (stub phase).
#
# Runs the REAL deploy.sh in an isolated temp dir with every external binary stubbed:
# a fake `docker` on PATH logs each `php artisan` / compose / run call, and returns controlled
# exit codes driven by env. A fake `sleep` no-ops so the readiness loops finish instantly.
# No Docker, no PostgreSQL, no Matrix/LiveKit, no live world is touched.
#
# It asserts deploy.sh's INSTALLER branch contracts, per case:
#
#   (1) PG readiness    — probe is TCP (-h 127.0.0.1), NOT the socket; the loop RETRIES while
#                         not-ready and proceeds only once ready; no DB write before ready.
#   (2) config-gen fail — --public-url, matrix:setup exits non-zero -> exit 1, "generation failed",
#                         Matrix/MAS not started, APP_KEY preserved, staging dir cleaned.
#   (3) missing output  — --public-url, matrix:setup exits 0 but bundle validation fails ->
#                         exit 1, "did not pass validation", not started, APP_KEY preserved.
#   (4) custom project  — --project NAME propagates to `compose -p NAME` and to .env.
#   (5) existing project— no --project, COMPOSE_PROJECT_NAME in .env is reused (not the dir name).
#   (6a) key preserve   — own APP_KEY -> "Preserving", NO key:generate, NO --rotate, key byte-identical.
#   (6b) key generate   — example APP_KEY -> key:generate --force, federation:init (NO --rotate).
#   (7a) resume no-rotate— rerun + --join, resume rc 0 -> resume-join only, NO cluster:join, NO --rotate.
#   (7b) resume adopt   — rerun + --join, resume rc 3 (no membership) -> cluster:join once, NO --rotate.
#   (7c) departed loud  — rerun + --join, resume rc 4 -> fail loud, NO re-adopt, NO --rotate, mint hint.
#
# THIS PASS is the stub harness only. The cold container install (stub success -> real
# `docker compose up` + composer/vendor + real artisan) is NOT established here — it needs a
# Linux Docker host and would change real docker state, which this row must not do.
#
# Run from the worktree:  bash tests/deploy/test_installer_contracts.sh
#
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_SH="$(cd "$HERE/../.." && pwd)/deploy.sh"
[[ -f "$DEPLOY_SH" ]] || { echo "FATAL: deploy.sh not found at $DEPLOY_SH" >&2; exit 2; }

EXAMPLE_KEY="base64:EXAMPLEEXAMPLEEXAMPLEEXAMPLEEXAMPLEEXAMPLE0="
REAL_KEY="base64:RealBoxKeyRealBoxKeyRealBoxKeyRealBoxKeyABC="

FAILS=0
pass() { echo "  PASS: $1"; }
fail() { echo "  FAIL: $1" >&2; FAILS=$((FAILS + 1)); }

assert_contains() { grep -Fq -- "$3" "$2" && pass "$1" || fail "$1 (missing: $3)"; }
assert_absent()   { grep -Fq -- "$3" "$2" && fail "$1 (present but should be absent: $3)" || pass "$1"; }
assert_count()    { local c; c="$(grep -Fc -- "$3" "$2")"; [[ "$c" == "$4" ]] && pass "$1" || fail "$1 (want $4 got $c of: $3)"; }
assert_eq()       { [[ "$2" == "$3" ]] && pass "$1" || fail "$1 (want '$3' got '$2')"; }

# assert_before <label> <file> <earlier> <later> : earlier logged before later.
assert_before() {
  local label="$1" file="$2" earlier="$3" later="$4" le ll
  le="$(grep -Fn -- "$earlier" "$file" | head -1 | cut -d: -f1)"
  ll="$(grep -Fn -- "$later"   "$file" | head -1 | cut -d: -f1)"
  if [[ -z "$le" ]]; then fail "$label (earlier marker never logged: $earlier)"; return; fi
  if [[ -z "$ll" ]]; then fail "$label (later marker never logged: $later)"; return; fi
  if (( le < ll )); then pass "$label"; else fail "$label ($earlier line $le not before $later line $ll)"; fi
}

# make_workspace <current_app_key> [compose_project_name] [mode=plain|public]
# Returns the workspace dir on stdout.
make_workspace() {
  local current_key="$1" cpn="${2:-}" mode="${3:-plain}"
  local ws; ws="$(mktemp -d)"
  cp "$DEPLOY_SH" "$ws/deploy.sh"
  printf 'APP_KEY=%s\n' "$EXAMPLE_KEY" > "$ws/.env.example"
  {
    printf 'APP_KEY=%s\n' "$current_key"
    [[ -n "$cpn" ]] && printf 'COMPOSE_PROJECT_NAME=%s\n' "$cpn"
  } > "$ws/.env"

  # Source files the public staging block copies (else set -e aborts on the cp before matrix:setup).
  if [[ "$mode" == "public" ]]; then
    mkdir -p "$ws/docker/matrix/appservice" "$ws/docker/matrix/conf.d" "$ws/docker/livekit" "$ws/scripts/deploy"
    printf 'stub\n' > "$ws/docker/matrix/appservice/registration.yaml"
    printf 'stub\n' > "$ws/docker/matrix/conf.d/20-mas.yaml"
    printf 'stub\n' > "$ws/docker/livekit/livekit.yaml"
    printf 'stub\n' > "$ws/scripts/deploy/check_public_matrix.py"
  fi

  mkdir -p "$ws/bin"
  # No-op sleep: readiness loops finish instantly.
  cat > "$ws/bin/sleep" <<'SLEEP'
#!/usr/bin/env bash
exit 0
SLEEP
  chmod +x "$ws/bin/sleep"

  cat > "$ws/bin/docker" <<'STUB'
#!/usr/bin/env bash
# Stub docker. Logs every call to $DOCKER_LOG. Logs `php artisan <cmd>` to $ART_LOG and an
# ordering trace to $ORDER_LOG. Exit codes are env-controlled:
#   STUB_RESUME_RC        federation:resume-join   (default 0)
#   STUB_CLUSTER_JOIN_RC  cluster:join             (default 0)
#   STUB_MATRIX_SETUP_RC  matrix:setup             (default 0)
#   STUB_BUNDLE_RC        check_public_matrix bundle run (default 1 = invalid, forces regen path)
#   STUB_PGREADY_FAILS    number of pg_isready probes that fail before ready (default 0)
# PGREADY_COUNT_FILE holds the pg_isready call counter.
args=("$@"); full="${args[*]}"
[[ -n "${DOCKER_LOG:-}" ]] && echo "$full" >> "$DOCKER_LOG"

# `docker volume ls` -> empty (no pre-existing matrix volume; keeps MATRIX_EXISTING=fresh).
if [[ "${args[0]:-}" == "volume" ]]; then exit 0; fi

# `docker run ...` -> the isolated python checks (identity / bundle).
if [[ "${args[0]:-}" == "run" ]]; then
  if [[ "$full" == *deploy-check.py*bundle* ]]; then
    [[ -n "${ORDER_LOG:-}" ]] && echo "BUNDLE_CHECK" >> "$ORDER_LOG"
    exit "${STUB_BUNDLE_RC:-1}"
  fi
  if [[ "$full" == *deploy-check.py*name* ]]; then echo fresh; exit 0; fi
  exit 0
fi

# From here: `docker compose ...`.
# PostgreSQL readiness probe.
if [[ "$full" == *pg_isready* ]]; then
  n=0; [[ -n "${PGREADY_COUNT_FILE:-}" && -f "$PGREADY_COUNT_FILE" ]] && n="$(cat "$PGREADY_COUNT_FILE")"
  n=$((n + 1)); [[ -n "${PGREADY_COUNT_FILE:-}" ]] && echo "$n" > "$PGREADY_COUNT_FILE"
  if (( n <= ${STUB_PGREADY_FAILS:-0} )); then
    [[ -n "${ORDER_LOG:-}" ]] && echo "PG_PROBE_FAIL" >> "$ORDER_LOG"
    exit 1
  fi
  [[ -n "${ORDER_LOG:-}" ]] && echo "PG_PROBE_OK" >> "$ORDER_LOG"
  exit 0
fi

# php artisan <cmd ...>
art_start=-1
for i in "${!args[@]}"; do
  if [[ "${args[$i]}" == "artisan" ]]; then art_start=$((i + 1)); fi
done
if [[ "$art_start" -ge 0 ]]; then
  cmd="${args[*]:$art_start}"
  echo "$cmd" >> "$ART_LOG"
  [[ -n "${ORDER_LOG:-}" ]] && echo "ARTISAN $cmd" >> "$ORDER_LOG"
  set -- "${args[@]:$art_start}"
  case "$1" in
    federation:resume-join) exit "${STUB_RESUME_RC:-0}";;
    cluster:join)           exit "${STUB_CLUSTER_JOIN_RC:-0}";;
    matrix:setup)           exit "${STUB_MATRIX_SETUP_RC:-0}";;
    *)                      exit 0;;
  esac
fi

# psql pg_database existence probe -> emit "1" so the matrix-DB guard skips CREATE.
for a in "$@"; do
  if [[ "$a" == *pg_database* ]]; then echo 1; fi
done
[[ "$full" == *"CREATE DATABASE"* && -n "${ORDER_LOG:-}" ]] && echo "PSQL_CREATE_DB" >> "$ORDER_LOG"
exit 0
STUB
  chmod +x "$ws/bin/docker"
  echo "$ws"
}

# run_case <workspace> <extra deploy args...>  (env STUB_* / SELF/PROJECT already set by caller)
# Echoes deploy.sh exit code.
run_case() {
  local ws="$1"; shift
  ( cd "$ws" && PATH="$ws/bin:$PATH" \
      ART_LOG="$ws/art.log" ORDER_LOG="$ws/order.log" DOCKER_LOG="$ws/docker.log" \
      PGREADY_COUNT_FILE="$ws/pgready.count" \
      bash "$ws/deploy.sh" "$@" > "$ws/out.log" 2>&1 )
  echo $?
}

appkey_of() { grep -E '^APP_KEY=' "$1" | head -1 | cut -d= -f2-; }

# ── (1) PG readiness: TCP probe, retry while not-ready, proceed once ready ────────────
echo "== (1) PG readiness — TCP probe + retry-until-ready =="
WS="$(make_workspace "$REAL_KEY")"
RC="$(STUB_PGREADY_FAILS=3 run_case "$WS" --self-url http://box.invalid:8080 --project t1)"
assert_eq       "exit 0 once ready"          "$RC" "0"
assert_contains "probe is TCP (-h 127.0.0.1)" "$WS/docker.log" "pg_isready -h 127.0.0.1"
assert_count    "3 not-ready probes retried"  "$WS/order.log" "PG_PROBE_FAIL" "3"
assert_contains "reached ready"               "$WS/order.log" "PG_PROBE_OK"
assert_before   "ready before any migrate"    "$WS/order.log" "PG_PROBE_OK" "ARTISAN migrate"
# redis_queue is the queue's single home and is NOT behind a profile. Pin it into the stack up
# so it can never be dropped again (WoS 2026-09-08: down redis_queue -> app 500 getaddrinfo).
assert_contains "stack up includes redis_queue" "$WS/docker.log" "up -d --build app postgres redis redis_queue horizon scheduler"
rm -rf "$WS"

# ── (2) config generation failure (matrix:setup non-zero) ─────────────────────────────
echo "== (2) --public-url config generation FAILURE (matrix:setup non-zero) =="
WS="$(make_workspace "$REAL_KEY" "" public)"
BEFORE="$(appkey_of "$WS/.env")"
RC="$(STUB_MATRIX_SETUP_RC=1 STUB_BUNDLE_RC=1 run_case "$WS" \
        --public-url https://earth.example.org --media-ip 203.0.113.10 --project t2)"
assert_eq       "non-zero exit"              "$RC" "1"
assert_contains "generation-failed message" "$WS/out.log" "Public Matrix configuration generation failed"
assert_contains "not started"               "$WS/out.log" "Matrix and MAS were not started"
assert_contains "matrix:setup was attempted" "$WS/art.log" "matrix:setup"
assert_absent   "no success banner"          "$WS/out.log" "Instance up"
assert_eq       "APP_KEY preserved"          "$(appkey_of "$WS/.env")" "$BEFORE"
assert_absent   "identity not rotated"       "$WS/art.log" "federation:init --rotate"
assert_absent   "no key regeneration"        "$WS/art.log" "key:generate"
STALE="$(find "$WS" -maxdepth 1 -name '.matrix-deploy.*' 2>/dev/null | wc -l | tr -d ' ')"
assert_eq       "staging dir cleaned"        "$STALE" "0"
rm -rf "$WS"

# ── (3) missing / invalid output (matrix:setup ok, bundle validation fails) ───────────
echo "== (3) --public-url MISSING OUTPUT (generate ok, bundle validation fails) =="
WS="$(make_workspace "$REAL_KEY" "" public)"
BEFORE="$(appkey_of "$WS/.env")"
RC="$(STUB_MATRIX_SETUP_RC=0 STUB_BUNDLE_RC=1 run_case "$WS" \
        --public-url https://earth.example.org --media-ip 203.0.113.10 --project t3)"
assert_eq       "non-zero exit"              "$RC" "1"
assert_contains "validation-failed message" "$WS/out.log" "did not pass validation"
assert_contains "not started"               "$WS/out.log" "Matrix and MAS were not started"
assert_contains "matrix:setup ran (gen ok)" "$WS/art.log" "matrix:setup"
assert_absent   "no success banner"          "$WS/out.log" "Instance up"
assert_eq       "APP_KEY preserved"          "$(appkey_of "$WS/.env")" "$BEFORE"
assert_absent   "identity not rotated"       "$WS/art.log" "federation:init --rotate"
rm -rf "$WS"

# ── (4) custom project name propagation ───────────────────────────────────────────────
echo "== (4) custom --project propagates to compose + .env =="
WS="$(make_workspace "$REAL_KEY")"
RC="$(run_case "$WS" --self-url http://box.invalid:8080 --project cga_custom)"
assert_eq       "exit 0"                     "$RC" "0"
assert_contains "echoed project"            "$WS/out.log" "compose project = cga_custom"
assert_contains "compose -p cga_custom"     "$WS/docker.log" "compose -p cga_custom up"
assert_contains ".env pins project"         "$WS/.env" "COMPOSE_PROJECT_NAME=cga_custom"
rm -rf "$WS"

# ── (5) existing project reuse (no --project) ─────────────────────────────────────────
echo "== (5) existing COMPOSE_PROJECT_NAME reused when no --project =="
WS="$(make_workspace "$REAL_KEY" "worldbox")"
RC="$(run_case "$WS" --self-url http://box.invalid:8080)"
assert_eq       "exit 0"                     "$RC" "0"
assert_contains "echoed reused project"     "$WS/out.log" "compose project = worldbox"
assert_contains "compose -p worldbox"       "$WS/docker.log" "compose -p worldbox up"
rm -rf "$WS"

# ── (6a) APP_KEY preservation (own key) ───────────────────────────────────────────────
echo "== (6a) own APP_KEY preserved — no generate, no rotate =="
WS="$(make_workspace "$REAL_KEY")"
BEFORE="$(appkey_of "$WS/.env")"
RC="$(run_case "$WS" --self-url http://box.invalid:8080 --project t6a)"
assert_eq       "exit 0"                     "$RC" "0"
assert_contains "preserving message"        "$WS/out.log" "Preserving the existing APP_KEY"
assert_absent   "no key:generate"            "$WS/art.log" "key:generate"
assert_absent   "no --rotate"                "$WS/art.log" "federation:init --rotate"
assert_contains "plain federation:init ran" "$WS/art.log" "federation:init"
assert_eq       "APP_KEY byte-identical"     "$(appkey_of "$WS/.env")" "$BEFORE"
rm -rf "$WS"

# ── (6b) APP_KEY generation (virgin example key) ──────────────────────────────────────
echo "== (6b) example APP_KEY -> generate fresh, init NOT rotate =="
WS="$(make_workspace "$EXAMPLE_KEY")"
RC="$(run_case "$WS" --self-url http://box.invalid:8080 --project t6b)"
assert_eq       "exit 0"                     "$RC" "0"
assert_contains "generating message"        "$WS/out.log" "Generating a fresh APP_KEY"
assert_contains "key:generate --force ran"  "$WS/art.log" "key:generate --force"
assert_contains "federation:init ran"       "$WS/art.log" "federation:init"
assert_absent   "no --rotate on fresh"       "$WS/art.log" "federation:init --rotate"
rm -rf "$WS"

# ── (7a) interrupted join — resume, no identity rotation ──────────────────────────────
echo "== (7a) rerun --join, resume rc0 -> resume only, no adopt, no rotate =="
WS="$(make_workspace "$REAL_KEY")"
RC="$(STUB_RESUME_RC=0 run_case "$WS" --self-url http://box.invalid:8080 --project t7a \
        --join http://host.invalid:8081 --key handle.secret)"
assert_eq       "exit 0"                     "$RC" "0"
assert_contains "resume-join ran"           "$WS/art.log" "federation:resume-join"
assert_absent   "no cluster:join"            "$WS/art.log" "cluster:join"
assert_absent   "no --rotate"                "$WS/art.log" "federation:init --rotate"
rm -rf "$WS"

# ── (7b) interrupted join — no membership -> adopt once, no rotate ────────────────────
echo "== (7b) rerun --join, resume rc3 -> adopt once, no rotate =="
WS="$(make_workspace "$REAL_KEY")"
RC="$(STUB_RESUME_RC=3 STUB_CLUSTER_JOIN_RC=0 run_case "$WS" --self-url http://box.invalid:8080 --project t7b \
        --join http://host.invalid:8081 --key handle.secret)"
assert_eq       "exit 0"                     "$RC" "0"
assert_contains "resume-join tried"         "$WS/art.log" "federation:resume-join"
assert_count    "cluster:join once"         "$WS/art.log" "cluster:join" "1"
assert_absent   "no --rotate"                "$WS/art.log" "federation:init --rotate"
rm -rf "$WS"

# ── (7c) interrupted join — departed/rejected -> fail loud, no re-adopt, no rotate ────
echo "== (7c) rerun --join, resume rc4 -> fail loud, no re-adopt, no rotate =="
WS="$(make_workspace "$REAL_KEY")"
RC="$(STUB_RESUME_RC=4 STUB_CLUSTER_JOIN_RC=0 run_case "$WS" --self-url http://box.invalid:8080 --project t7c \
        --join http://host.invalid:8081 --key handle.secret)"
assert_eq       "non-zero exit"              "$RC" "1"
assert_contains "resume-join tried"         "$WS/art.log" "federation:resume-join"
assert_absent   "no silent re-adopt"         "$WS/art.log" "cluster:join"
assert_absent   "identity not rotated"       "$WS/art.log" "federation:init --rotate"
assert_contains "mint-a-fresh-key recovery" "$WS/out.log" "cluster:keys:mint"
rm -rf "$WS"

# ── (8) --public-url and the dev view-as key (operator order 2026-09-18) ──────────────
# config/cga.php reads an ABSENT CGA_IMPERSONATION as ON and launch:assert-clean refuses a
# public launch while it is on (WoS demo box 2026-09-17). The public deploy writes false
# when the key is absent, leaves a set key alone, and still refuses an explicit true.
# The runs stop at the stubbed matrix:setup failure; .env is written before that point.
echo "== (8a) --public-url, CGA_IMPERSONATION absent -> written false =="
WS="$(make_workspace "$REAL_KEY" "" public)"
RC="$(STUB_MATRIX_SETUP_RC=1 STUB_BUNDLE_RC=1 run_case "$WS" \
        --public-url https://earth.example.org --media-ip 203.0.113.10 --project t8a)"
assert_count    "key written false once"     "$WS/.env" "CGA_IMPERSONATION=false" "1"
rm -rf "$WS"

echo "== (8b) --public-url, CGA_IMPERSONATION=false already set -> left alone =="
WS="$(make_workspace "$REAL_KEY" "" public)"
printf 'CGA_IMPERSONATION=false\n' >> "$WS/.env"
RC="$(STUB_MATRIX_SETUP_RC=1 STUB_BUNDLE_RC=1 run_case "$WS" \
        --public-url https://earth.example.org --media-ip 203.0.113.10 --project t8b)"
assert_count    "one key line, not two"      "$WS/.env" "CGA_IMPERSONATION=" "1"
rm -rf "$WS"

echo "== (8c) --public-url, CGA_IMPERSONATION=true -> refused at the door =="
WS="$(make_workspace "$REAL_KEY" "" public)"
printf 'CGA_IMPERSONATION=true\n' >> "$WS/.env"
RC="$(run_case "$WS" --public-url https://earth.example.org --media-ip 203.0.113.10 --project t8c)"
assert_eq       "non-zero exit"              "$RC" "1"
assert_contains "names the key"              "$WS/out.log" "CGA_IMPERSONATION is enabled"
assert_contains "the true value is kept"     "$WS/.env" "CGA_IMPERSONATION=true"
rm -rf "$WS"

echo "== (8d) a LAN deploy (no --public-url) never writes the key =="
WS="$(make_workspace "$REAL_KEY")"
RC="$(run_case "$WS" --self-url http://box.invalid:8080 --project t8d)"
assert_eq       "exit 0"                     "$RC" "0"
assert_absent   "key not written"            "$WS/.env" "CGA_IMPERSONATION"
rm -rf "$WS"

echo ""
if [[ "$FAILS" -eq 0 ]]; then
  echo "ALL INSTALLER CONTRACT CASES PASSED"
  exit 0
else
  echo "$FAILS ASSERTION(S) FAILED"
  exit 1
fi
