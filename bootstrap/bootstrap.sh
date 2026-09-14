#!/usr/bin/env bash
#
# Phase G (G8b / C7) — universal survival-mesh setup (Linux + macOS).
#
# A thin front-end over the SHARED spec (bootstrap/mesh-catalog.json) and the existing
# deploy.sh. It does ONE extra thing deploy.sh does not: it walks an interactive pick of
# which transports this node will offer, guides their host-daemon setup, writes the
# transport .env, hands off to deploy.sh for the app layer, then registers the chosen
# transports + publishes the directory. The same wording runs on every OS (bootstrap.ps1
# mirrors it for Windows); transport FACTS live only in the catalog, never in the script.
#
# HOST-DAEMON INSTALL (tor / yggdrasil / tailscale) MODIFIES THE HOST OS — that step is
# certified on the physical rig, not on Docker-Desktop. This script GUIDES it and, only on
# explicit confirmation, runs the catalog's install command; it never installs silently.
#
# Examples:
#   ./bootstrap/bootstrap.sh                                   # interactive
#   ./bootstrap/bootstrap.sh --profile public-anchor-node --prefix fc --nginx-port 8080
#   ./bootstrap/bootstrap.sh --non-interactive --profile volunteer-home --self-url https://node.example
#
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
CATALOG="$HERE/mesh-catalog.json"

PROFILE=""
NONINTERACTIVE=""
PREFIX="fc"
NGINX_PORT="8080"
PASSTHRU=()

usage() { sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; }

# Args we ACT on here; everything else is passed straight through to deploy.sh.
while [[ $# -gt 0 ]]; do
  case "$1" in
    --profile)         PROFILE="$2"; shift 2;;
    --non-interactive) NONINTERACTIVE="1"; shift;;
    --prefix)          PREFIX="$2"; PASSTHRU+=(--prefix "$2"); shift 2;;
    --nginx-port)      NGINX_PORT="$2"; PASSTHRU+=(--nginx-port "$2"); shift 2;;
    -h|--help)         usage; exit 0;;
    *)                 PASSTHRU+=("$1"); shift;;
  esac
done

command -v jq >/dev/null 2>&1 || { echo "ERROR: jq is required to read the mesh catalog. Install it (apt/dnf/brew install jq) and re-run." >&2; exit 1; }
[[ -f "$CATALOG" ]] || { echo "ERROR: catalog not found at $CATALOG" >&2; exit 1; }

case "$(uname -s)" in
  Linux)  OS="linux";;
  Darwin) OS="macos";;
  *)      echo "ERROR: unsupported OS $(uname -s) — use bootstrap.ps1 on Windows." >&2; exit 1;;
esac

ask() { # ask "prompt" "default" -> echoes the answer (default when non-interactive)
  local prompt="$1" def="$2" ans
  if [[ -n "$NONINTERACTIVE" ]]; then echo "$def"; return; fi
  read -r -p "$prompt " ans || true
  echo "${ans:-$def}"
}

echo "── Cosmopolitan Governance App — Survival-Mesh Setup ──"
echo "Reading transports from $(basename "$CATALOG")."

# 1. Posture → a recommended profile (the operator can still toggle each transport).
if [[ -z "$PROFILE" ]]; then
  echo "What is this node?  [a] volunteer mirror  [b] my jurisdiction's server  [c] public anchor"
  node_kind="$(ask "  choice [a]:" "a")"
  echo "Where is it?        [a] open internet  [b] untrusted/public network  [c] air-gapped"
  net_kind="$(ask "  choice [a]:" "a")"
  case "${node_kind}-${net_kind}" in
    *-b) PROFILE="secure-default";;
    *-c) PROFILE="air-gapped";;
    c-*) PROFILE="public-anchor-node";;
    *)   PROFILE="volunteer-home";;
  esac
fi
echo "→ Profile: ${PROFILE}"

mapfile -t ALL_TRANSPORTS < <(jq -r '.transports | keys[]' "$CATALOG")
mapfile -t DEFAULT_ON < <(jq -r --arg p "$PROFILE" '.recommend[$p][]? // empty' "$CATALOG")
is_default_on() { local t="$1"; for d in "${DEFAULT_ON[@]:-}"; do [[ "$d" == "$t" ]] && return 0; done; return 1; }

# 2. Pick transports + collect each one's self-advert; guide host-daemon setup.
CHOSEN=()
declare -A ADVERT
for t in "${ALL_TRANSPORTS[@]}"; do
  label="$(jq -r --arg t "$t" '.transports[$t].label' "$CATALOG")"
  def="n"; is_default_on "$t" && def="y"
  inc="$(ask "Offer ${label} [${t}]? (y/n) [${def}]:" "$def")"
  [[ "$inc" =~ ^[Yy] ]] || continue
  CHOSEN+=("$t")

  needs_daemon="$(jq -r --arg t "$t" '.transports[$t].needs_host_daemon' "$CATALOG")"
  if [[ "$needs_daemon" == "true" ]]; then
    install_cmd="$(jq -r --arg t "$t" --arg os "$OS" '.transports[$t].install[$os] // empty' "$CATALOG")"
    configure="$(jq -r --arg t "$t" '.transports[$t].configure' "$CATALOG")"
    echo "  ↳ ${t} needs a host daemon (RIG-CERTIFIED step)."
    echo "    install : ${install_cmd:-<none for this OS>}"
    echo "    configure: ${configure}"
    if [[ -n "$install_cmd" && -z "$NONINTERACTIVE" ]]; then
      run_it="$(ask "    run the install command now? (y/n) [n]:" "n")"
      [[ "$run_it" =~ ^[Yy] ]] && { echo "    running…"; eval "$install_cmd"; }
    fi
  fi

  tmpl="$(jq -r --arg t "$t" '.transports[$t].self_advert // empty' "$CATALOG")"
  if [[ -n "$tmpl" ]]; then
    ADVERT[$t]="$(ask "  reachable address for ${t} [${tmpl}]:" "$tmpl")"
  fi
done

[[ ${#CHOSEN[@]} -gt 0 ]] || { echo "No transports chosen — nothing to set up." ; exit 0; }

# 3. Write transport .env (e.g. the Tor SOCKS proxy) BEFORE the stack comes up.
[[ -f "$ROOT/.env" ]] || cp "$ROOT/.env.example" "$ROOT/.env"
set_env() {
  local key="$1" val="$2"
  if grep -qE "^${key}=" "$ROOT/.env"; then
    sed -i.bak -E "s#^${key}=.*#${key}=${val}#" "$ROOT/.env" && rm -f "$ROOT/.env.bak"
  else
    printf '\n%s=%s\n' "$key" "$val" >> "$ROOT/.env"
  fi
}
for t in "${CHOSEN[@]}"; do
  while IFS=$'\t' read -r k v; do [[ -n "$k" ]] && set_env "$k" "$v"; done \
    < <(jq -r --arg t "$t" '.transports[$t].env // {} | to_entries[] | "\(.key)\t\(.value)"' "$CATALOG")
done

# 4. Hand off to deploy.sh for the app layer (unchanged contract, hardened across rounds).
# The handshake callback URL (FEDERATION_SELF_URL) must be an address a REMOTE peer can
# reach — prefer an overlay self-advert (yggdrasil/tailnet/onion) over the LAN https one.
# Skip if the operator already passed --self-url.
SELF_ARG=()
if ! printf '%s\n' "${PASSTHRU[@]:-}" | grep -qx -- '--self-url'; then
  for t in yggdrasil tailnet onion https; do
    if [[ -n "${ADVERT[$t]:-}" ]]; then SELF_ARG=(--self-url "${ADVERT[$t]}"); break; fi
  done
fi
echo "→ Handing off to deploy.sh for the app layer…"
"$ROOT/deploy.sh" "${PASSTHRU[@]:-}" "${SELF_ARG[@]:-}"

# 5. Post-up: enable federation, register each chosen transport, publish the directory.
# deploy.sh (above) is the SINGLE OWNER of compose-project resolution: it honours --project,
# else the COMPOSE_PROJECT_NAME a prior run pinned in .env, else the normalised dir basename,
# and pins the resolved name back into .env. READ THAT PIN — never re-derive from $PREFIX,
# which is the CONTAINER-NAME prefix (fc), not the compose project. A $PREFIX-keyed DC
# addressed a different, often EMPTY stack from the one deploy.sh just brought up, so every
# post-deploy artisan call could silently hit the wrong project. Parse exactly as deploy.sh
# does (strip surrounding quotes and any CR); fall back to the same normalised basename only
# when the key is absent (it never is once deploy.sh has run).
PROJECT=""
if [[ -f "$ROOT/.env" ]] && grep -qE '^COMPOSE_PROJECT_NAME=' "$ROOT/.env"; then
  PROJECT="$(grep -E '^COMPOSE_PROJECT_NAME=' "$ROOT/.env" | head -1 | cut -d= -f2- | tr -d '"' | tr -d '\r')"
fi
PROJECT="${PROJECT:-$(basename "$ROOT" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9_-]//g')}"
echo "→ compose project = ${PROJECT}   (resolved by deploy.sh, read from .env)"
DC=(docker compose -p "$PROJECT")
art() { "${DC[@]}" exec -T app php artisan "$@"; }

# Any post-deploy step failing means the mesh is NOT ready. Abort LOUD rather than printing a
# completion line over a mistargeted or failed registration ("failures cannot report success").
fail() { echo "✗ Survival-mesh setup FAILED: $1" >&2; exit 1; }

# federation:init mints the identity AND opens the mesh endpoints (federation_enabled) —
# without it /api/federation/identity is refused and the anchor is undiscoverable. deploy.sh
# now runs it UNCONDITIONALLY, so this is idempotent/redundant when deploy.sh succeeded; it
# stays as the bootstrap-side backstop. Idempotent (no --rotate — never regenerate identity
# on a rerun). FATAL: a failure here means the endpoints stay closed, which is not "complete".
echo "→ Enabling federation (mint identity + open the mesh endpoints)…"
art federation:init || fail "federation:init failed — the mesh endpoints stay closed"

echo "→ Registering transports…"
REGISTERED=0
for t in "${CHOSEN[@]}"; do
  addr="${ADVERT[$t]:-}"
  [[ -n "$addr" ]] || { echo "  (skipping ${t} — no live address)"; continue; }
  art transport:register "$t" "$addr" || fail "transport:register ${t} failed"
  REGISTERED=$((REGISTERED + 1))
done
# directory:publish is FATAL on a genuine error, but two states are legitimate no-ops and must
# NOT abort setup:
#   1. No transport was registered (e.g. the air-gapped profile: sneakernet advertises nothing,
#      so the register loop skips it and there is nothing to advertise). mesh:gates classifies
#      "no transport advertised" as WARN, not FAIL, so the node is still federation-ready — do
#      not abort one step before the readiness gate. directory:publish exits non-zero on this
#      state (no transports = FAILURE, correct in general), so guard it here and skip.
#   2. No jurisdiction is explicitly authoritative to this node — a mirror or fresh anchor. The
#      command itself now exits 0 with an informational line there (DirectoryPublishCommand),
#      so it is already non-fatal.
echo "→ Publishing the directory for jurisdictions this node is authoritative for…"
if [[ "$REGISTERED" -eq 0 ]]; then
  echo "  (no live transport registered — nothing to advertise; skipping directory:publish)"
else
  art directory:publish || fail "directory:publish failed"
fi

# 6. Final readiness assertion — the SAME contract deploy.sh enforces at the end of its run
#    (art mesh:gates). mesh:gates exits non-zero on a hard FAIL (federation off / identity not
#    minted), so this is the gate that makes the completion line below trustworthy: it prints
#    ONLY after the node is verified ready to federate, never over a failed/mistargeted step.
echo "→ Verifying federation readiness (mesh:gates)…"
art mesh:gates || fail "mesh:gates reported the node is not ready to federate"

echo "✓ Survival-mesh setup complete. Transports: ${CHOSEN[*]}"
echo "  Two-way check: once BOTH boxes are up, run 'php artisan mesh:doctor <other-box-url>' on each."
