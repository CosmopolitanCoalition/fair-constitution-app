#!/usr/bin/env bash
#
# test_join_rerun.sh — deploy.sh join-rerun identity harness (M3).
#
# Runs the REAL deploy.sh in an isolated temp dir with every external binary stubbed:
# a fake `docker` on PATH logs each `php artisan` invocation into a file and returns
# controlled exit codes; `.env`/`.env.example` are synthetic. No Docker, no PostgreSQL,
# no live world is touched. It asserts the argv the script would run, per case:
#
#   (a) first run (env APP_KEY == example key)      -> key:generate + federation:init
#                                                       (no --rotate) + cluster:join, NO resume
#   (b) rerun, membership present (resume exits 0)   -> NO key:generate, NO --rotate,
#                                                       NO cluster:join, resume-join called
#   (c) rerun, no membership (resume exits 3)        -> resume-join THEN cluster:join once
#   (d) --clone-rekey                                -> federation:init --rotate
#   (e) exhausted key (cluster:join fails)           -> non-zero exit + mint instruction
#   (f) rerun, departed/rejected membership (rc 4)   -> fail loud, NO cluster:join, NO rotate
#
# Run from the worktree:  bash tests/deploy/test_join_rerun.sh
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

# assert_contains <label> <file> <needle>
assert_contains() { grep -Fq -- "$3" "$2" && pass "$1" || fail "$1 (missing: $3)"; }
# assert_absent <label> <file> <needle>
assert_absent()   { grep -Fq -- "$3" "$2" && fail "$1 (present but should be absent: $3)" || pass "$1"; }
# assert_count <label> <file> <needle> <n>
assert_count()    { local c; c="$(grep -Fc -- "$3" "$2")"; [[ "$c" == "$4" ]] && pass "$1" || fail "$1 (want $4 got $c of: $3)"; }

# Build a scratch workspace: a copy of deploy.sh (so its self-cd lands here), synthetic
# env files, and a stub `docker` on PATH. Returns the workspace dir on stdout.
make_workspace() {
  local current_key="$1"
  local ws; ws="$(mktemp -d)"
  cp "$DEPLOY_SH" "$ws/deploy.sh"
  printf 'APP_KEY=%s\n' "$EXAMPLE_KEY" > "$ws/.env.example"
  printf 'APP_KEY=%s\n' "$current_key" > "$ws/.env"

  mkdir -p "$ws/bin"
  cat > "$ws/bin/docker" <<'STUB'
#!/usr/bin/env bash
# Stub docker: log `php artisan <cmd>` calls to $ART_LOG, control exit codes via env. It also
# writes an ORDERING trace to $ORDER_LOG (in invocation order) with a marker for the nginx
# start and for each artisan call, so the harness can prove the join is DISPATCHED after nginx
# is up (the M5 outcome: the UI serves while the transfer runs).
args=("$@")
full="${args[*]}"

# Ordering trace: nginx start marker (docker compose ... up -d nginx).
if [[ -n "${ORDER_LOG:-}" && "$full" == *" up "*"nginx"* ]]; then
  echo "NGINX_UP" >> "$ORDER_LOG"
fi

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
    *)                      exit 0;;
  esac
fi

# Non-artisan exec (test -f, pg_isready, psql). Emit "1" for the pg_database probe so the
# db-existence guard skips creation; everything else just succeeds.
for a in "$@"; do
  if [[ "$a" == *pg_database* ]]; then echo 1; fi
done
exit 0
STUB
  chmod +x "$ws/bin/docker"
  echo "$ws"
}

# run_case <workspace> <extra deploy args...>  (env STUB_* already exported)
run_case() {
  local ws="$1"; shift
  ( cd "$ws" && PATH="$ws/bin:$PATH" ART_LOG="$ws/art.log" ORDER_LOG="$ws/order.log" \
      bash "$ws/deploy.sh" --self-url http://box.invalid:8080 --project testproj "$@" \
      > "$ws/out.log" 2>&1 )
  echo $?  # deploy.sh exit code
}

# assert_before <label> <file> <earlier_needle> <later_needle>
# passes when earlier_needle first appears on a LINE NUMBER below later_needle (i.e. earlier ran first).
assert_before() {
  local label="$1" file="$2" earlier="$3" later="$4"
  local le ll
  le="$(grep -Fn -- "$earlier" "$file" | head -1 | cut -d: -f1)"
  ll="$(grep -Fn -- "$later" "$file" | head -1 | cut -d: -f1)"
  if [[ -z "$le" ]]; then fail "$label (earlier marker never logged: $earlier)"; return; fi
  if [[ -z "$ll" ]]; then fail "$label (later marker never logged: $later)"; return; fi
  if (( le < ll )); then pass "$label"; else fail "$label ($earlier at line $le not before $later at line $ll)"; fi
}

echo "== (a) first run: virgin example key =="
WS="$(make_workspace "$EXAMPLE_KEY")"
RC="$(STUB_RESUME_RC=0 STUB_CLUSTER_JOIN_RC=0 run_case "$WS" --join http://host.invalid:8081 --key handle.secret)"
[[ "$RC" == "0" ]] && pass "exit 0" || fail "exit 0 (got $RC)"
assert_contains "key:generate ran"            "$WS/art.log" "key:generate --force"
assert_contains "federation:init ran"         "$WS/art.log" "federation:init"
assert_absent   "no --rotate"                 "$WS/art.log" "federation:init --rotate"
assert_contains "cluster:join adopt"          "$WS/art.log" "cluster:join http://host.invalid:8081 --key handle.secret"
assert_absent   "no --sync (async dispatch)"  "$WS/art.log" "cluster:join http://host.invalid:8081 --key handle.secret --sync"
assert_absent   "no resume-join"              "$WS/art.log" "federation:resume-join"
# M5: the join is DISPATCHED after nginx is up, so the UI serves while the transfer runs.
assert_before   "adopt after nginx up"        "$WS/order.log" "NGINX_UP" "ARTISAN cluster:join"
rm -rf "$WS"

echo "== (b) rerun, membership present (resume exits 0) =="
WS="$(make_workspace "$REAL_KEY")"
RC="$(STUB_RESUME_RC=0 STUB_CLUSTER_JOIN_RC=0 run_case "$WS" --join http://host.invalid:8081 --key handle.secret)"
[[ "$RC" == "0" ]] && pass "exit 0" || fail "exit 0 (got $RC)"
assert_absent   "no key:generate"             "$WS/art.log" "key:generate"
assert_absent   "no --rotate"                 "$WS/art.log" "federation:init --rotate"
assert_contains "resume-join ran"             "$WS/art.log" "federation:resume-join"
assert_absent   "resume-join async (no --sync)" "$WS/art.log" "federation:resume-join --sync"
assert_absent   "no cluster:join"             "$WS/art.log" "cluster:join"
# M5: the resume is DISPATCHED after nginx is up.
assert_before   "resume after nginx up"       "$WS/order.log" "NGINX_UP" "ARTISAN federation:resume-join"
rm -rf "$WS"

echo "== (c) rerun, no membership (resume exits 3) =="
WS="$(make_workspace "$REAL_KEY")"
RC="$(STUB_RESUME_RC=3 STUB_CLUSTER_JOIN_RC=0 run_case "$WS" --join http://host.invalid:8081 --key handle.secret)"
[[ "$RC" == "0" ]] && pass "exit 0" || fail "exit 0 (got $RC)"
assert_absent   "no key:generate"             "$WS/art.log" "key:generate"
assert_absent   "no --rotate"                 "$WS/art.log" "federation:init --rotate"
assert_contains "resume-join tried"           "$WS/art.log" "federation:resume-join"
assert_absent   "resume-join async (no --sync)" "$WS/art.log" "federation:resume-join --sync"
assert_count    "cluster:join once"           "$WS/art.log" "cluster:join" "1"
# M5: both the resume attempt and the fall-through adopt run after nginx is up.
assert_before   "resume after nginx up"       "$WS/order.log" "NGINX_UP" "ARTISAN federation:resume-join"
assert_before   "adopt after nginx up"        "$WS/order.log" "NGINX_UP" "ARTISAN cluster:join"
rm -rf "$WS"

echo "== (d) --clone-rekey: explicit rotate =="
WS="$(make_workspace "$REAL_KEY")"
RC="$(STUB_RESUME_RC=0 STUB_CLUSTER_JOIN_RC=0 run_case "$WS" --join http://host.invalid:8081 --key handle.secret --clone-rekey)"
[[ "$RC" == "0" ]] && pass "exit 0" || fail "exit 0 (got $RC)"
assert_contains "federation:init --rotate"    "$WS/art.log" "federation:init --rotate"
assert_absent   "clone does not resume"       "$WS/art.log" "federation:resume-join"
assert_contains "clone adopts with key"       "$WS/art.log" "cluster:join http://host.invalid:8081 --key handle.secret"
rm -rf "$WS"

echo "== (e) exhausted key: resume 3 then cluster:join fails =="
WS="$(make_workspace "$REAL_KEY")"
RC="$(STUB_RESUME_RC=3 STUB_CLUSTER_JOIN_RC=1 run_case "$WS" --join http://host.invalid:8081 --key handle.secret)"
[[ "$RC" != "0" ]] && pass "non-zero exit" || fail "non-zero exit (got $RC)"
assert_contains "mint instruction printed"    "$WS/out.log" "cluster:keys:mint"
assert_absent   "identity not rotated"        "$WS/art.log" "federation:init --rotate"
rm -rf "$WS"

echo "== (f) rerun, departed/rejected membership (resume exits 4): fail loud, no re-adopt =="
WS="$(make_workspace "$REAL_KEY")"
RC="$(STUB_RESUME_RC=4 STUB_CLUSTER_JOIN_RC=0 run_case "$WS" --join http://host.invalid:8081 --key handle.secret)"
[[ "$RC" != "0" ]] && pass "non-zero exit" || fail "non-zero exit (got $RC)"
assert_contains "resume-join tried"           "$WS/art.log" "federation:resume-join"
assert_absent   "no silent re-adopt"          "$WS/art.log" "cluster:join"
assert_absent   "identity not rotated"        "$WS/art.log" "federation:init --rotate"
assert_contains "mint instruction printed"    "$WS/out.log" "cluster:keys:mint"
rm -rf "$WS"

echo ""
if [[ "$FAILS" -eq 0 ]]; then
  echo "ALL DEPLOY JOIN-RERUN CASES PASSED"
  exit 0
else
  echo "$FAILS ASSERTION(S) FAILED"
  exit 1
fi
