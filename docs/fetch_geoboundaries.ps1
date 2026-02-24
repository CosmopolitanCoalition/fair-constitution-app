# fetch_geoboundaries.ps1 — Clone or update the geoBoundaries repository using
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
#   powershell -ExecutionPolicy Bypass -File docs\fetch_geoboundaries.ps1
#
# Requirements: git >= 2.26 (for --no-cone sparse-checkout support)

$ErrorActionPreference = "Stop"

$RepoRoot   = Split-Path -Parent $PSScriptRoot
$Dest       = Join-Path $RepoRoot "docs\geoBoundaries_repo"
$Remote     = "https://github.com/wmgeolab/geoBoundaries.git"

$SparsePaths = @(
    "releaseData/gbOpen/**/geoBoundaries-*-ADM[0-9].geojson",
    "releaseData/gbOpen/**/geoBoundaries-*-ADM[0-9].shp",
    "releaseData/gbOpen/**/geoBoundaries-*-ADM[0-9].dbf",
    "releaseData/gbOpen/**/geoBoundaries-*-ADM[0-9].shx",
    "releaseData/gbOpen/**/geoBoundaries-*-ADM[0-9].prj",
    "releaseData/geoBoundariesOpen-meta.csv"
)

# ─── Verify git version ───────────────────────────────────────────────────────
$gitVersionRaw = (git --version) -replace "git version ", ""
$gitParts      = $gitVersionRaw -split "\."
$gitMajor      = [int]$gitParts[0]
$gitMinor      = [int]$gitParts[1]
if ($gitMajor -lt 2 -or ($gitMajor -eq 2 -and $gitMinor -lt 26)) {
    Write-Warning "git $gitVersionRaw detected. Sparse checkout --no-cone requires git >= 2.26."
    Write-Warning "Proceeding anyway — upgrade git if the sparse-checkout step fails."
}

# ─── Clone or update ─────────────────────────────────────────────────────────
$gitDir = Join-Path $Dest ".git"

if (Test-Path $gitDir) {

    Write-Host "geoBoundaries repo already exists at: $Dest" -ForegroundColor Cyan
    Write-Host "Applying sparse checkout and pulling latest..."

    Push-Location $Dest
    try {
        git sparse-checkout init --no-cone
        git sparse-checkout set @SparsePaths
        git pull --depth 1
    } finally {
        Pop-Location
    }

} else {

    Write-Host "Cloning geoBoundaries (sparse, depth=1, gbOpen only)..." -ForegroundColor Cyan
    Write-Host "Remote: $Remote"
    Write-Host ""

    git clone `
        --depth 1 `
        --filter=blob:none `
        --sparse `
        $Remote `
        $Dest

    Push-Location $Dest
    try {
        git sparse-checkout set --no-cone @SparsePaths
    } finally {
        Pop-Location
    }

    Write-Host ""
    Write-Host "Sparse checkout applied." -ForegroundColor Green
}

# ─── Verify ──────────────────────────────────────────────────────────────────
Write-Host ""
Write-Host "Verifying checkout..." -ForegroundColor Cyan

$Meta    = Join-Path $Dest "releaseData\geoBoundariesOpen-meta.csv"
$GbOpen  = Join-Path $Dest "releaseData\gbOpen"
$errors  = 0

if (-not (Test-Path $Meta)) {
    Write-Host "  ERROR  geoBoundariesOpen-meta.csv not found" -ForegroundColor Red
    $errors++
} else {
    Write-Host "  OK     geoBoundariesOpen-meta.csv" -ForegroundColor Green
}

if (-not (Test-Path $GbOpen)) {
    Write-Host "  ERROR  releaseData/gbOpen/ directory not found" -ForegroundColor Red
    $errors++
} else {
    $countryCount = (Get-ChildItem $GbOpen -Directory).Count
    $geojsonCount = (Get-ChildItem $GbOpen -Recurse -Filter "geoBoundaries-*-ADM[0-9].geojson" |
        Where-Object { $_.Name -notmatch "-simplified" }).Count
    Write-Host "  OK     releaseData/gbOpen/ — $countryCount countries, $geojsonCount primary GeoJSON files" -ForegroundColor Green
}

# Spot-check USA/ADM0 for excluded file types
$Sample = Join-Path $GbOpen "USA\ADM0"
if (Test-Path $Sample) {
    $excludedPatterns = @(
        "geoBoundaries-USA-ADM0-simplified.geojson",
        "geoBoundaries-USA-ADM0-all.zip",
        "geoBoundaries-USA-ADM0.topojson",
        "geoBoundaries-USA-ADM0-PREVIEW.png"
    )
    $foundExcluded = $false
    foreach ($p in $excludedPatterns) {
        if (Test-Path (Join-Path $Sample $p)) {
            Write-Host "  WARN   $p is present (should have been excluded)" -ForegroundColor Yellow
            $foundExcluded = $true
        }
    }
    if (-not $foundExcluded) {
        Write-Host "  OK     excluded file types absent from USA/ADM0 spot-check" -ForegroundColor Green
    }
}

foreach ($excluded in @("releaseData\gbHumanitarian", "releaseData\gbAuthoritative")) {
    $excPath = Join-Path $Dest $excluded
    if (Test-Path $excPath) {
        Write-Host "  WARN   $excluded\ is present (unexpected for sparse checkout)" -ForegroundColor Yellow
    } else {
        Write-Host "  OK     $excluded\ excluded (not downloaded)" -ForegroundColor Green
    }
}

Write-Host ""
if ($errors -gt 0) {
    Write-Host "Completed with $errors error(s). Check output above." -ForegroundColor Red
    exit 1
} else {
    Write-Host "geoBoundaries sync complete." -ForegroundColor Green
}
