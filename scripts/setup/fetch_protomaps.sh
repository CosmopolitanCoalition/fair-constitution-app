#!/usr/bin/env bash
#
# Fetch a Protomaps PMTiles bundle for the Jurisdiction Viewer's self-
# hostable base map (Phase P.7).
#
# Usage:
#   ./scripts/setup/fetch_protomaps.sh                     # default: Florence demo (6.6 MB)
#   ./scripts/setup/fetch_protomaps.sh --planet            # full planet build (~135 GB!)
#   PMTILES_URL=https://... ./scripts/setup/fetch_protomaps.sh   # any other URL
#
# About the bundles (verified against https://docs.protomaps.com/basemaps/downloads)
# -----------------------------------------------------------------------------------
# Protomaps does NOT publish a single small "world bundle." Real options:
#
#   1. **Florence demo (default, 6.6 MB)** — pmtiles.io ships a tiny
#      "ODbL Firenze" sample. Proves the integration path works locally
#      (the viewer renders street/water context inside Florence and
#      ocean-blue everywhere else). Useful for smoke-testing the viewer
#      before committing to a full download.
#
#   2. **Full planet daily build (~135 GB)** — Protomaps publishes a
#      fresh build every day at `https://build.protomaps.com/<YYYYMMDD>.pmtiles`,
#      retained for ~7 days. Pass `--planet` to this script to download
#      the most recent build to public/maps/world.pmtiles. WARNING:
#      this is 135 GB and takes hours on most connections. Protomaps
#      explicitly recommends mirroring the file to your own object
#      storage rather than re-downloading: "URLs may change and
#      hotlinking to these downloads are discouraged. Instead, you
#      should copy the tileset to your own Cloud Storage."
#
#   3. **Source Cooperative mirror** — alternative URL at
#      `https://beta.source.coop/repositories/protomaps/openstreetmap/`,
#      mirroring the latest daily build only.
#
#   4. **Operator-clipped subset** — run `pmtiles extract` (from
#      protomaps/go-pmtiles) against the full planet file to generate a
#      low-zoom or geographically-bounded subset (typical zoom 0-6 world:
#      ~500 MB - 1 GB). Place the result at public/maps/world.pmtiles
#      and the viewer uses it. Recommended for production self-hosting.
#
# The Jurisdiction Viewer's lookup order (in resources/js/Pages/Jurisdictions/Show.vue):
#
#   public/maps/world.pmtiles   →  if present
#   VITE_PROTOMAPS_URL env var  →  if set (set in .env, then npm run build)
#   (neither)                   →  polygon-only "ocean blue" rendering

set -euo pipefail

# Argument parsing — currently just --planet to switch the default URL.
PLANET=0
for arg in "$@"; do
    case "$arg" in
        --planet) PLANET=1 ;;
        --help|-h)
            sed -n '2,/^set -euo/p' "$0" | sed 's/^# \?//'
            exit 0
            ;;
        *) echo "Unknown argument: $arg (use --help)" >&2; exit 1 ;;
    esac
done

if [[ "$PLANET" == "1" ]]; then
    # Daily build URL pattern. Protomaps retains ~7 days; the latest build
    # is always at today's UTC date. If today's hasn't been published yet
    # (early in the UTC day) we fall back to yesterday — the 4xx on
    # today's URL triggers the fallback.
    TODAY=$(date -u +%Y%m%d)
    YESTERDAY=$(date -u -d 'yesterday' +%Y%m%d 2>/dev/null || date -u -v-1d +%Y%m%d)
    if curl -sI -o /dev/null --fail "https://build.protomaps.com/${TODAY}.pmtiles" --max-time 10; then
        DEFAULT_URL="https://build.protomaps.com/${TODAY}.pmtiles"
    else
        DEFAULT_URL="https://build.protomaps.com/${YESTERDAY}.pmtiles"
    fi
    echo "WARNING: --planet downloads ~135 GB. This will take hours on most connections."
    echo "         Protomaps recommends mirroring to your own storage rather than"
    echo "         re-downloading; a 'pmtiles extract' subset is also a good option."
    echo
else
    # Default: small Florence demo. 6.6 MB, useful for smoke-testing the
    # viewer integration without committing to a multi-GB download.
    DEFAULT_URL="https://pmtiles.io/protomaps(vector)ODbL_firenze.pmtiles"
fi

PMTILES_URL="${PMTILES_URL:-$DEFAULT_URL}"
DEST="${PMTILES_DEST:-public/maps/world.pmtiles}"

# Resolve from repo root (one level up from scripts/setup).
SCRIPT_DIR="$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
REPO_ROOT="$( cd -- "${SCRIPT_DIR}/../.." &> /dev/null && pwd )"
DEST_ABS="${REPO_ROOT}/${DEST}"

mkdir -p "$(dirname "${DEST_ABS}")"

if [[ -f "${DEST_ABS}" ]]; then
    SIZE=$(stat -c %s "${DEST_ABS}" 2>/dev/null || stat -f %z "${DEST_ABS}")
    echo "Protomaps bundle already exists at ${DEST_ABS} (${SIZE} bytes)."
    echo "Delete it first to force a re-download."
    exit 0
fi

echo "Fetching Protomaps planet bundle from ${PMTILES_URL} ..."
echo "  → ${DEST_ABS}"

if command -v curl >/dev/null 2>&1; then
    curl -L --fail --progress-bar -o "${DEST_ABS}" "${PMTILES_URL}"
elif command -v wget >/dev/null 2>&1; then
    wget --show-progress -O "${DEST_ABS}" "${PMTILES_URL}"
else
    echo "ERROR: neither curl nor wget is installed." >&2
    exit 1
fi

SIZE=$(stat -c %s "${DEST_ABS}" 2>/dev/null || stat -f %z "${DEST_ABS}")
echo "Done — ${DEST_ABS} (${SIZE} bytes)."
echo
echo "Attribution required: '© OpenStreetMap contributors / Protomaps' must"
echo "be visible on the map. Leaflet's protomaps-leaflet adapter does this"
echo "automatically when added as a base layer."
