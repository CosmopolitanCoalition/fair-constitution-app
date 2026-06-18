#!/usr/bin/env bash
# fetch_geoboundaries.sh — Clone or update the geoBoundaries repository using
# Git sparse checkout so only the files used by import_geoboundaries.py are
# downloaded.
#
# Files included (per country/ADM directory):
#   geoBoundaries-{ISO3}-ADM{N}.geojson   primary boundary file (full-resolution;
#                                          size varies widely — most are well under
#                                          1 MB, but high-detail boundaries such as
#                                          complex coastlines run to tens or even
#                                          >100 MB, e.g. NZL ADM0 ≈ 106 MB)
#   geoBoundaries-{ISO3}-ADM{N}.shp/.dbf/.shx/.prj  shapefile fallback set
#   releaseData/geoBoundariesOpen-meta.csv supplementary metadata (UNSDG region etc.)
#
# IMPORTANT: the entire releaseData/gbOpen/ tree is stored in Git LFS
# (releaseData/gbOpen/** filter=lfs in the upstream .gitattributes), so git-lfs MUST be
# installed or the clone yields tiny pointer files instead of real GeoJSON and the import
# fails later with an opaque parse error. This script preflights for it below.
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
# Requirements:
#   git ≥ 2.26 (for --no-cone sparse-checkout support)
#   git-lfs    (the gbOpen boundary tree is LFS-tracked — see note above)
#     Ubuntu/Debian:  sudo apt-get install git-lfs
#     macOS (Homebrew): brew install git-lfs

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

# ─── Verify git-lfs (HARD requirement) ────────────────────────────────────────
# The upstream .gitattributes marks releaseData/gbOpen/** as filter=lfs, so every
# GeoJSON/shapefile is an LFS object. Without the git-lfs smudge filter active, the
# checkout writes ~130-byte pointer text files in place of the real geometry and the
# import later fails with an opaque JSON parse error (the volunteer has no way to tie that
# back to a missing tool). Fail fast here instead, with the install command.
if ! command -v git-lfs &>/dev/null && ! git lfs version &>/dev/null; then
    echo "ERROR: git-lfs is required but not installed." >&2
    echo "  The geoBoundaries gbOpen tree is stored in Git LFS — without git-lfs the clone" >&2
    echo "  produces pointer files instead of real boundary data and the ETL import fails." >&2
    echo "  Ubuntu/Debian: sudo apt-get install git-lfs" >&2
    echo "  macOS:         brew install git-lfs" >&2
    exit 1
fi
# Ensure the global smudge/clean filters are wired (idempotent; a no-op if already done).
# Without this a freshly-installed git-lfs binary still checks out pointer files.
git lfs install >/dev/null 2>&1 || true

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
