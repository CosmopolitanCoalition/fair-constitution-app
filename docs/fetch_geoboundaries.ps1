# fetch_geoboundaries.ps1 — Clone or update the geoBoundaries repository using
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
#   powershell -ExecutionPolicy Bypass -File docs\fetch_geoboundaries.ps1
#
# Requirements: git >= 2.26 (for --no-cone sparse-checkout support)

$ErrorActionPreference = "Stop"

$RepoRoot   = Split-Path -Parent $PSScriptRoot
$Dest       = Join-Path $RepoRoot "docs\geoBoundaries_repo"
$Remote     = "https://github.com/wmgeolab/geoBoundaries.git"

$SparsePaths = @(
    "releaseData/gbOpen/",
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
    $geojsonCount = (Get-ChildItem $GbOpen -Recurse -Filter "*.geojson").Count
    Write-Host "  OK     releaseData/gbOpen/ — $countryCount countries, $geojsonCount GeoJSON files" -ForegroundColor Green
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
