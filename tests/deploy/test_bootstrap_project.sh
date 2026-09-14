#!/usr/bin/env bash
#
# test_bootstrap_project.sh — bootstrap.sh compose-project + failure-hardening harness (M4).
#
# Runs the REAL bootstrap/bootstrap.sh in an isolated temp dir with every external binary
# stubbed. No Docker, no PostgreSQL, no live world, no real deploy.sh is touched:
#   * a FAKE deploy.sh mirrors the real one's project resolution (--project wins, else the
#     COMPOSE_PROJECT_NAME already pinned in .env, else the normalised dir basename) and PINS
#     the resolved name back into .env — exactly what the real deploy.sh does at :209;
#   * a stub `docker` logs the `-p <project>` and the artisan sub-command of every post-deploy
#     call and returns env-controlled exit codes;
#   * a stub `jq` answers the handful of catalog queries bootstrap.sh makes over a one-transport
#     fixture catalog (https);
#   * a stub `uname` reports Linux so the OS gate passes on any host (this box is MINGW).
#
# It asserts the two halves of the M4 done-clause:
#   (a) --project threaded through   -> every post-deploy compose call carries -p <that>, never fc
#   (b) pre-existing .env pin         -> every post-deploy compose call carries -p <that>, never fc
#   (c) basename-derived              -> every post-deploy compose call carries -p <basename>, never fc
#   (d) federation:init fails         -> non-zero exit, NO completion line
#   (e) transport:register fails      -> non-zero exit, NO completion line
#   (f) directory:publish no-authority (exit 0) continues; a real error (exit 1) aborts
#   (g) mesh:gates fails              -> non-zero exit, NO completion line
#
# Run from the worktree:  bash tests/deploy/test_bootstrap_project.sh
#
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BOOTSTRAP_SH="$(cd "$HERE/../.." && pwd)/bootstrap/bootstrap.sh"
[[ -f "$BOOTSTRAP_SH" ]] || { echo "FATAL: bootstrap.sh not found at $BOOTSTRAP_SH" >&2; exit 2; }

SUCCESS_LINE="Survival-mesh setup complete"

FAILS=0
pass() { echo "  PASS: $1"; }
fail() { echo "  FAIL: $1" >&2; FAILS=$((FAILS + 1)); }

# assert_contains <label> <file> <needle>
assert_contains() { grep -Fq -- "$3" "$2" && pass "$1" || fail "$1 (missing: $3)"; }
# assert_absent <label> <file> <needle>
assert_absent()   { grep -Fq -- "$3" "$2" && fail "$1 (present but should be absent: $3)" || pass "$1"; }
# assert_all_project <label> <dc_log> <project>  — every logged post-deploy call used -p <project>
assert_all_project() {
  local label="$1" log="$2" proj="$3" total wrong
  # grep -c prints a count (0 on no match) regardless of exit status; capture it directly.
  total="$(grep -c '^PROJECT=' "$log")"
  # count PROJECT= lines whose project is NOT the expected one
  wrong="$(grep '^PROJECT=' "$log" | grep -c -v -- "^PROJECT=${proj} ")"
  if [[ "$total" -ge 1 && "$wrong" -eq 0 ]]; then
    pass "$label ($total post-deploy calls, all -p ${proj})"
  else
    fail "$label (of $total calls, $wrong not -p ${proj}); log:"; sed 's/^/      /' "$log" >&2
  fi
}

# Build a scratch workspace: a copy of bootstrap.sh (so its self-dir/ROOT land here), a fake
# deploy.sh, a fixture catalog + .env.example, and stub docker/jq/uname on PATH.
make_workspace() {
  local ws; ws="$(mktemp -d)"
  mkdir -p "$ws/bootstrap" "$ws/bin"
  cp "$BOOTSTRAP_SH" "$ws/bootstrap/bootstrap.sh"

  # Fixture catalog: ONE transport, no host daemon, a self-advert, empty env; recommended for
  # the public-anchor-node profile so --non-interactive selects it.
  cat > "$ws/bootstrap/mesh-catalog.json" <<'JSON'
{ "transports": { "https": { "label": "HTTPS", "needs_host_daemon": false,
    "self_advert": "https://node.example:8081", "configure": "n/a", "env": {} } },
  "recommend": { "public-anchor-node": ["https"] } }
JSON

  # .env.example deliberately OMITS COMPOSE_PROJECT_NAME (the real template rule), so the
  # basename case starts with no pin.
  printf '# fixture env template\nAPP_ENV=testing\n' > "$ws/.env.example"

  # Fake deploy.sh — mirrors the real project resolution + the :209 pin, nothing else.
  cat > "$ws/deploy.sh" <<'FAKE'
#!/usr/bin/env bash
set -euo pipefail
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --project) PROJECT="$2"; shift 2;;
    *) shift;;
  esac
done
if [[ -z "$PROJECT" ]]; then
  if [[ -f .env ]] && grep -qE '^COMPOSE_PROJECT_NAME=' .env; then
    PROJECT="$(grep -E '^COMPOSE_PROJECT_NAME=' .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d '\r')"
  fi
  PROJECT="${PROJECT:-$(basename "$PWD" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9_-]//g')}"
fi
if grep -qE '^COMPOSE_PROJECT_NAME=' .env 2>/dev/null; then
  sed -i.bak -E "s#^COMPOSE_PROJECT_NAME=.*#COMPOSE_PROJECT_NAME=${PROJECT}#" .env && rm -f .env.bak
else
  printf 'COMPOSE_PROJECT_NAME=%s\n' "$PROJECT" >> .env
fi
echo "[fake deploy.sh] compose project = ${PROJECT}"
FAKE
  chmod +x "$ws/deploy.sh"

  # Stub docker: log `-p <project>` + the artisan sub-command; exit per STUB_* env.
  cat > "$ws/bin/docker" <<'STUB'
#!/usr/bin/env bash
args=("$@")
proj=""
for i in "${!args[@]}"; do
  [[ "${args[$i]}" == "-p" ]] && proj="${args[$((i+1))]}"
done
art_start=-1
for i in "${!args[@]}"; do
  [[ "${args[$i]}" == "artisan" ]] && art_start=$((i + 1))
done
if [[ "$art_start" -ge 0 ]]; then
  cmd="${args[*]:$art_start}"
  echo "PROJECT=${proj} ART=${cmd}" >> "$DC_LOG"
  case "${args[$art_start]}" in
    federation:init)    exit "${STUB_FEDINIT_RC:-0}";;
    transport:register) exit "${STUB_TRANSPORT_RC:-0}";;
    directory:publish)  exit "${STUB_DIRPUB_RC:-0}";;
    mesh:gates)         exit "${STUB_GATES_RC:-0}";;
    *)                  exit 0;;
  esac
fi
exit 0
STUB
  chmod +x "$ws/bin/docker"

  # Stub jq: answer the fixed set of catalog queries bootstrap.sh makes (one transport: https).
  # The filter is always the penultimate arg; the catalog path is the last.
  cat > "$ws/bin/jq" <<'STUB'
#!/usr/bin/env bash
args=("$@")
n=${#args[@]}
filter=""
(( n >= 2 )) && filter="${args[$((n-2))]}"
case "$filter" in
  *"keys[]"*)          echo "https";;
  *recommend*)         echo "https";;
  *to_entries*)        : ;;
  *self_advert*)       echo "https://node.example:8081";;
  *.label*)            echo "HTTPS";;
  *needs_host_daemon*) echo "false";;
  *install*)           : ;;
  *configure*)         echo "n/a";;
  *)                   : ;;
esac
exit 0
STUB
  chmod +x "$ws/bin/jq"

  # Stub uname: bootstrap gates on `uname -s` (this box is MINGW; report Linux).
  printf '#!/usr/bin/env bash\necho Linux\n' > "$ws/bin/uname"
  chmod +x "$ws/bin/uname"

  echo "$ws"
}

# make_workspace_noadvert — like make_workspace, but the sole recommended transport advertises
# NOTHING (self_advert null, e.g. the air-gapped profile's sneakernet). The register loop then
# skips it, so ZERO transports are registered and directory:publish must be skipped, not run.
make_workspace_noadvert() {
  local ws; ws="$(make_workspace)"
  # One transport with a null self-advert; recommended for public-anchor-node so it is chosen.
  cat > "$ws/bootstrap/mesh-catalog.json" <<'JSON'
{ "transports": { "sneakernet": { "label": "Offline bundle", "needs_host_daemon": false,
    "self_advert": null, "configure": "advertise nothing", "env": null } },
  "recommend": { "public-anchor-node": ["sneakernet"] } }
JSON
  # Stub jq: sneakernet everywhere; self_advert is EMPTY (null template -> no ADVERT entry).
  cat > "$ws/bin/jq" <<'STUB'
#!/usr/bin/env bash
args=("$@")
n=${#args[@]}
filter=""
(( n >= 2 )) && filter="${args[$((n-2))]}"
case "$filter" in
  *"keys[]"*)          echo "sneakernet";;
  *recommend*)         echo "sneakernet";;
  *to_entries*)        : ;;
  *self_advert*)       : ;;
  *.label*)            echo "Offline bundle";;
  *needs_host_daemon*) echo "false";;
  *install*)           : ;;
  *configure*)         echo "advertise nothing";;
  *)                   : ;;
esac
exit 0
STUB
  chmod +x "$ws/bin/jq"
  echo "$ws"
}

# run_case <workspace> <extra bootstrap args...>  (env STUB_* already exported)
# Prints deploy exit code on stdout; writes $ws/dc.log (post-deploy calls) + $ws/out.log.
run_case() {
  local ws="$1"; shift
  ( cd "$ws" && PATH="$ws/bin:$PATH" DC_LOG="$ws/dc.log" \
      bash "$ws/bootstrap/bootstrap.sh" --non-interactive --profile public-anchor-node "$@" \
      > "$ws/out.log" 2>&1 )
  echo $?
}

echo "== (a) --project threaded through -> post-deploy uses that project, not fc =="
WS="$(make_workspace)"
RC="$(STUB_FEDINIT_RC=0 STUB_TRANSPORT_RC=0 STUB_DIRPUB_RC=0 STUB_GATES_RC=0 run_case "$WS" --project custom_a)"
[[ "$RC" == "0" ]] && pass "exit 0" || fail "exit 0 (got $RC)"
assert_all_project "all post-deploy calls -p custom_a" "$WS/dc.log" "custom_a"
assert_absent      "never -p fc"                       "$WS/dc.log" "PROJECT=fc "
assert_contains    "federation:init ran"               "$WS/dc.log" "ART=federation:init"
assert_contains    "transport:register ran"            "$WS/dc.log" "ART=transport:register"
assert_contains    "directory:publish ran"             "$WS/dc.log" "ART=directory:publish"
assert_contains    "mesh:gates ran"                    "$WS/dc.log" "ART=mesh:gates"
assert_contains    "completion line printed"           "$WS/out.log" "$SUCCESS_LINE"
rm -rf "$WS"

echo "== (b) pre-existing .env COMPOSE_PROJECT_NAME=custom_b (differs from prefix fc) =="
WS="$(make_workspace)"
printf 'APP_ENV=testing\nCOMPOSE_PROJECT_NAME=custom_b\n' > "$WS/.env"
RC="$(STUB_FEDINIT_RC=0 STUB_TRANSPORT_RC=0 STUB_DIRPUB_RC=0 STUB_GATES_RC=0 run_case "$WS")"
[[ "$RC" == "0" ]] && pass "exit 0" || fail "exit 0 (got $RC)"
assert_all_project "all post-deploy calls -p custom_b" "$WS/dc.log" "custom_b"
assert_absent      "never -p fc"                       "$WS/dc.log" "PROJECT=fc "
assert_contains    "completion line printed"           "$WS/out.log" "$SUCCESS_LINE"
rm -rf "$WS"

echo "== (c) basename-derived (no --project, no pin) -> that basename, not fc =="
WS="$(make_workspace)"
EXP_C="$(basename "$WS" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9_-]//g')"
RC="$(STUB_FEDINIT_RC=0 STUB_TRANSPORT_RC=0 STUB_DIRPUB_RC=0 STUB_GATES_RC=0 run_case "$WS")"
[[ "$RC" == "0" ]] && pass "exit 0" || fail "exit 0 (got $RC)"
assert_all_project "all post-deploy calls -p ${EXP_C}" "$WS/dc.log" "$EXP_C"
assert_absent      "never -p fc"                       "$WS/dc.log" "PROJECT=fc "
rm -rf "$WS"

echo "== (d) federation:init fails -> non-zero exit, NO completion line =="
WS="$(make_workspace)"
RC="$(STUB_FEDINIT_RC=1 STUB_TRANSPORT_RC=0 STUB_DIRPUB_RC=0 STUB_GATES_RC=0 run_case "$WS" --project custom_a)"
[[ "$RC" != "0" ]] && pass "non-zero exit" || fail "non-zero exit (got $RC)"
assert_contains "federation:init failure message" "$WS/out.log" "FAILED: federation:init"
assert_absent   "no completion line"               "$WS/out.log" "$SUCCESS_LINE"
rm -rf "$WS"

echo "== (e) transport:register fails -> non-zero exit, NO completion line =="
WS="$(make_workspace)"
RC="$(STUB_FEDINIT_RC=0 STUB_TRANSPORT_RC=1 STUB_DIRPUB_RC=0 STUB_GATES_RC=0 run_case "$WS" --project custom_a)"
[[ "$RC" != "0" ]] && pass "non-zero exit" || fail "non-zero exit (got $RC)"
assert_contains "transport:register failure message" "$WS/out.log" "FAILED: transport:register"
assert_absent   "no completion line"                  "$WS/out.log" "$SUCCESS_LINE"
rm -rf "$WS"

echo "== (f1) directory:publish no-authority (exit 0) -> continues to mesh:gates + completes =="
WS="$(make_workspace)"
RC="$(STUB_FEDINIT_RC=0 STUB_TRANSPORT_RC=0 STUB_DIRPUB_RC=0 STUB_GATES_RC=0 run_case "$WS" --project custom_a)"
[[ "$RC" == "0" ]] && pass "exit 0" || fail "exit 0 (got $RC)"
assert_contains "mesh:gates still ran after publish" "$WS/dc.log" "ART=mesh:gates"
assert_contains "completion line printed"            "$WS/out.log" "$SUCCESS_LINE"
rm -rf "$WS"

echo "== (f2) directory:publish real error (exit 1) -> non-zero exit, NO completion line =="
WS="$(make_workspace)"
RC="$(STUB_FEDINIT_RC=0 STUB_TRANSPORT_RC=0 STUB_DIRPUB_RC=1 STUB_GATES_RC=0 run_case "$WS" --project custom_a)"
[[ "$RC" != "0" ]] && pass "non-zero exit" || fail "non-zero exit (got $RC)"
assert_contains "directory:publish failure message" "$WS/out.log" "FAILED: directory:publish"
assert_absent   "no completion line"                 "$WS/out.log" "$SUCCESS_LINE"
rm -rf "$WS"

echo "== (g) mesh:gates fails -> non-zero exit, NO completion line =="
WS="$(make_workspace)"
RC="$(STUB_FEDINIT_RC=0 STUB_TRANSPORT_RC=0 STUB_DIRPUB_RC=0 STUB_GATES_RC=1 run_case "$WS" --project custom_a)"
[[ "$RC" != "0" ]] && pass "non-zero exit" || fail "non-zero exit (got $RC)"
assert_contains "mesh:gates failure message" "$WS/out.log" "FAILED: mesh:gates"
assert_absent   "no completion line"          "$WS/out.log" "$SUCCESS_LINE"
rm -rf "$WS"

echo "== (h) no transport registered (air-gapped) -> directory:publish SKIPPED, still completes =="
WS="$(make_workspace_noadvert)"
RC="$(STUB_FEDINIT_RC=0 STUB_TRANSPORT_RC=0 STUB_DIRPUB_RC=1 STUB_GATES_RC=0 run_case "$WS" --project custom_a)"
# STUB_DIRPUB_RC=1: even if directory:publish WERE called it would fail — proving it is skipped.
[[ "$RC" == "0" ]] && pass "exit 0" || fail "exit 0 (got $RC)"
assert_absent   "transport:register skipped (no advert)" "$WS/dc.log" "ART=transport:register"
assert_absent   "directory:publish NOT run"              "$WS/dc.log" "ART=directory:publish"
assert_contains "skip line printed"                      "$WS/out.log" "skipping directory:publish"
assert_contains "mesh:gates still ran"                   "$WS/dc.log" "ART=mesh:gates"
assert_contains "completion line printed"                "$WS/out.log" "$SUCCESS_LINE"
rm -rf "$WS"

echo ""
if [[ "$FAILS" -eq 0 ]]; then
  echo "ALL BOOTSTRAP PROJECT + FAILURE CASES PASSED"
  exit 0
else
  echo "$FAILS ASSERTION(S) FAILED"
  exit 1
fi
