#!/usr/bin/env bash
# fetch_geoboundaries.sh — Clone or update the geoBoundaries repository using
# Git sparse checkout so only the files used by import_geoboundaries.py are
# downloaded.
#
# Files included:
#   releaseData/gbOpen/                    all ADM-level GeoJSON boundary files
#   releaseData/geoBoundariesOpen-meta.csv supplementary metadata (UNSDG region etc.)
#
# Files excluded (never read by the importer):
#   releaseData/gbHumanitarian/
#   releaseData/gbAuthoritative/
#   All repo scaffolding (LICENSE, README, .github/, scripts, etc.)
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
    "releaseData/gbOpen/"
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
    geojson_count=$(find "$GBOPEN" -name "*.geojson" | wc -l | tr -d ' ')
    echo "  OK  releaseData/gbOpen/ — ${country_count} countries, ${geojson_count} GeoJSON files"
fi

# Confirm excluded paths are absent
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
