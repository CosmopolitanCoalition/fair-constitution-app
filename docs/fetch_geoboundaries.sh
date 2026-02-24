#!/usr/bin/env bash
# fetch_geoboundaries.sh — Clone or update the geoBoundaries repository using
# Git sparse checkout so only the files used by import_geoboundaries.py are
# downloaded.
#
# Files included (per country/ADM directory):
#   geoBoundaries-{ISO3}-ADM{N}.geojson   primary boundary file (~1.4 MB each)
#   geoBoundaries-{ISO3}-ADM{N}.shp/.dbf/.shx/.prj  shapefile fallback set
#   releaseData/geoBoundariesOpen-meta.csv supplementary metadata (UNSDG region etc.)
#
# Files excluded (never read by the importer — ~68% of each ADM directory):
#   *-simplified.geojson/shp/topojson/dbf/shx/prj   simplified geometry variants
#   *.topojson                                         TopoJSON format (unused)
#   *-all.zip                                          redundant archive of same dir
#   *-metaData.json/.txt, *-PREVIEW.png               per-boundary metadata/previews
#   CITATION-AND-USE-*.txt, desktop.ini               repo scaffolding
#   releaseData/gbHumanitarian/, gbAuthoritative/      other release types
#
# Usage (from repo root):
#   bash docs/fetch_geoboundaries.sh
#
# Requirements: git ≥ 2.26 (for --no-cone sparse-checkout support)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
DEST="${REPO_ROOT}/docs/geoBoundaries_repo"
REMOTE="https://github.com/wmgeolab/geoBoundaries.git"

SPARSE_PATHS=(
    "releaseData/gbOpen/**/geoBoundaries-*-ADM[0-9].geojson"
    "releaseData/gbOpen/**/geoBoundaries-*-ADM[0-9].shp"
    "releaseData/gbOpen/**/geoBoundaries-*-ADM[0-9].dbf"
    "releaseData/gbOpen/**/geoBoundaries-*-ADM[0-9].shx"
    "releaseData/gbOpen/**/geoBoundaries-*-ADM[0-9].prj"
    "releaseData/geoBoundariesOpen-meta.csv"
)

# ─── Verify git version ───────────────────────────────────────────────────────
GIT_VERSION=$(git --version | grep -oE '[0-9]+\.[0-9]+' | head -1)
GIT_MAJOR=$(echo "$GIT_VERSION" | cut -d. -f1)
GIT_MINOR=$(echo "$GIT_VERSION" | cut -d. -f2)
if [[ $GIT_MAJOR -lt 2 || ($GIT_MAJOR -eq 2 && $GIT_MINOR -lt 26) ]]; then
    echo "WARNING: git $GIT_VERSION detected. Sparse checkout --no-cone requires git ≥ 2.26." >&2
    echo "         Proceeding anyway — upgrade git if the sparse-checkout step fails." >&2
fi

# ─── Clone or update ─────────────────────────────────────────────────────────
if [[ -d "$DEST/.git" ]]; then
    echo "geoBoundaries repo already exists at: $DEST"
    echo "Applying sparse checkout and pulling latest..."

    cd "$DEST"
    git sparse-checkout init --no-cone
    git sparse-checkout set "${SPARSE_PATHS[@]}"
    git pull --depth 1

else
    echo "Cloning geoBoundaries (sparse, depth=1, gbOpen only)..."
    echo "Remote: $REMOTE"
    echo ""

    git clone \
        --depth 1 \
        --filter=blob:none \
        --sparse \
        "$REMOTE" \
        "$DEST"

    cd "$DEST"
    git sparse-checkout set --no-cone "${SPARSE_PATHS[@]}"

    echo ""
    echo "Sparse checkout applied."
fi

# ─── Verify ──────────────────────────────────────────────────────────────────
echo ""
echo "Verifying checkout..."

META="${DEST}/releaseData/geoBoundariesOpen-meta.csv"
GBOPEN="${DEST}/releaseData/gbOpen"

errors=0

if [[ ! -f "$META" ]]; then
    echo "  ERROR: geoBoundariesOpen-meta.csv not found" >&2
    errors=$((errors + 1))
else
    echo "  OK  geoBoundariesOpen-meta.csv"
fi

if [[ ! -d "$GBOPEN" ]]; then
    echo "  ERROR: releaseData/gbOpen/ directory not found" >&2
    errors=$((errors + 1))
else
    country_count=$(find "$GBOPEN" -mindepth 1 -maxdepth 1 -type d | wc -l | tr -d ' ')
    geojson_count=$(find "$GBOPEN" -name "geoBoundaries-*-ADM[0-9].geojson" | wc -l | tr -d ' ')
    echo "  OK  releaseData/gbOpen/ — ${country_count} countries, ${geojson_count} primary GeoJSON files"
fi

# Confirm excluded file types are absent (spot-check USA/ADM0 if present)
SAMPLE="${GBOPEN}/USA/ADM0"
if [[ -d "$SAMPLE" ]]; then
    for excluded_pattern in \
        "geoBoundaries-USA-ADM0-simplified.geojson" \
        "geoBoundaries-USA-ADM0-all.zip" \
        "geoBoundaries-USA-ADM0.topojson" \
        "geoBoundaries-USA-ADM0-PREVIEW.png"; do
        if [[ -f "${SAMPLE}/${excluded_pattern}" ]]; then
            echo "  WARN ${excluded_pattern} is present (should have been excluded)"
        fi
    done
    echo "  OK  excluded file types absent from USA/ADM0 spot-check"
fi

# Confirm other release types are absent
for excluded in "releaseData/gbHumanitarian" "releaseData/gbAuthoritative"; do
    if [[ -d "${DEST}/${excluded}" ]]; then
        echo "  WARN ${excluded}/ is present (unexpected for sparse checkout)"
    else
        echo "  OK  ${excluded}/ excluded (not downloaded)"
    fi
done

echo ""
if [[ $errors -gt 0 ]]; then
    echo "Completed with $errors error(s). Check output above." >&2
    exit 1
else
    echo "geoBoundaries sync complete."
fi
