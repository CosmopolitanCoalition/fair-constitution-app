#!/usr/bin/env bash
# fetch_worldpop.sh — Download WorldPop 2023 constrained 100m population rasters
# Linux / macOS equivalent of fetch_worldpop.ps1
#
# Requirements: curl, jq
#   Ubuntu/Debian:  sudo apt-get install curl jq
#   macOS (Homebrew): brew install curl jq
#
# Usage (from repo root):
#   bash docs/fetch_worldpop.sh
#
# The script reads ISO3 country codes from the geoBoundaries directory, queries
# the WorldPop STAC API for each, and downloads the 2023 constrained 100m raster.
# Already-downloaded files are skipped unless OVERWRITE=true is set.

set -euo pipefail

# ─── Settings ────────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"

GB_OPEN_PATH="${REPO_ROOT}/docs/geoBoundaries_repo/releaseData/gbOpen"
OUT_ROOT="${REPO_ROOT}/docs/worldpop_100m_latest"

MAX_YEAR=2023
OVERWRITE="${OVERWRITE:-false}"   # set OVERWRITE=true to re-download existing files
MAX_PAGES=200
# ─────────────────────────────────────────────────────────────────────────────

if ! command -v jq &>/dev/null; then
  echo "ERROR: jq is required but not installed." >&2
  echo "  Ubuntu/Debian: sudo apt-get install jq" >&2
  echo "  macOS:         brew install jq" >&2
  exit 1
fi

if [[ ! -d "$GB_OPEN_PATH" ]]; then
  echo "ERROR: geoBoundaries gbOpen directory not found:" >&2
  echo "  $GB_OPEN_PATH" >&2
  echo "Run: git clone https://github.com/wmgeolab/geoBoundaries.git docs/geoBoundaries_repo" >&2
  exit 1
fi

mkdir -p "$OUT_ROOT"

# Collect ISO3 codes from geoBoundaries directory listing
ISO_LIST=()
while IFS= read -r -d '' d; do
  ISO_LIST+=("$(basename "$d")")
done < <(find "$GB_OPEN_PATH" -mindepth 1 -maxdepth 1 -type d -print0 | sort -z)

echo ""
echo "Scanning ${#ISO_LIST[@]} country codes..."
echo ""

downloaded=0
skipped_exists=0
skipped_none=0

for ISO3 in "${ISO_LIST[@]}"; do

  URL="https://api.stac.worldpop.org/collections/${ISO3}/items"
  best_year=-1
  best_url=""
  best_id=""
  page=0

  # Paginate through STAC API results
  while [[ -n "$URL" && $page -lt $MAX_PAGES ]]; do
    page=$((page + 1))
    response=$(curl -sf --max-time 60 "$URL" 2>/dev/null || true)

    if [[ -z "$response" ]]; then
      if [[ $page -eq 1 ]]; then
        echo "  ${ISO3}: no dataset collection"
        skipped_none=$((skipped_none + 1))
      fi
      break
    fi

    # Extract matching candidates: Population, 100m, constrained, year <= MAX_YEAR
    while IFS=$'\t' read -r year href id; do
      [[ -z "$href" || -z "$year" ]] && continue
      [[ "$year" -gt "$MAX_YEAR" ]] && continue
      if [[ "$year" -gt "$best_year" ]] || \
         [[ "$year" -eq "$best_year" && "$id" > "$best_id" ]]; then
        best_year=$year
        best_url=$href
        best_id=$id
      fi
    done < <(echo "$response" | jq -r '
      .features[]?
      | select(
          .properties.project == "Population" and
          .properties.resolution == "100m" and
          (.id | test("_CN_100m_"))
        )
      | [
          (.properties.year // ""),
          (.assets.data.href // ""),
          (.id // "")
        ]
      | @tsv
    ' 2>/dev/null || true)

    # Follow pagination
    URL=$(echo "$response" | jq -r '
      (.links // [])[] | select(.rel == "next") | .href
    ' 2>/dev/null || true)
    [[ "$URL" == "null" ]] && URL=""
  done

  if [[ -z "$best_url" ]]; then
    echo "  ${ISO3}: no 100m constrained population dataset"
    skipped_none=$((skipped_none + 1))
    continue
  fi

  echo "  ${ISO3}: found year ${best_year}"

  # Determine output path
  iso_dir="${OUT_ROOT}/${ISO3}"
  filename="${best_url##*/}"
  outfile="${iso_dir}/${filename}"

  if [[ -f "$outfile" && "$OVERWRITE" != "true" ]]; then
    echo "    already exists — skipping"
    skipped_exists=$((skipped_exists + 1))
    continue
  fi

  mkdir -p "$iso_dir"
  echo "    downloading ${filename}..."
  if curl -fL --max-time 3600 --retry 3 --retry-delay 10 \
       -o "$outfile" "$best_url"; then
    downloaded=$((downloaded + 1))
  else
    echo "    WARNING: download failed for ${ISO3}" >&2
    rm -f "$outfile"
  fi

done

echo ""
echo "========= SUMMARY ========="
echo "  Downloaded:       $downloaded"
echo "  Already existed:  $skipped_exists"
echo "  No dataset found: $skipped_none"
echo "==========================="
