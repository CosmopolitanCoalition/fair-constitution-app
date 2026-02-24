# ===================== USER SETTINGS =====================
# Paths are resolved relative to this script's location (docs/).
# Override here only if your repo layout differs from the default.
$RepoRoot   = Split-Path -Parent $PSScriptRoot
$GbOpenPath = Join-Path $RepoRoot "docs\geoBoundaries_repo\releaseData\gbOpen"
$OutRoot    = Join-Path $RepoRoot "docs\worldpop_100m_latest"

$TestOnly = $false        # true = only check availability
$MaxYear  = 2023         # ignore years beyond this year
$OverwriteExisting = $false

$MaxPages = 200          # safety valve per ISO3 collection (pagination)
$SleepMsBetweenPages = 0 # set to e.g. 100 if you want to be gentle
# ========================================================

$ErrorActionPreference = "Stop"

function Get-NextLinkHref($links) {
    if ($null -eq $links) { return $null }
    foreach ($l in $links) {
        if ($l.rel -eq "next" -and $l.href) { return $l.href }
    }
    return $null
}

function Get-LatestWorldPop100m($ISO3) {

    $url = "https://api.stac.worldpop.org/collections/$ISO3/items"

    $candidates = @()
    $page = 0

    while ($true) {

        $page++
        if ($page -gt $MaxPages) {
            Write-Host " [$ISO3] pagination hit MaxPages=$MaxPages; stopping" -ForegroundColor DarkYellow
            break
        }

        try {
            $resp = Invoke-RestMethod -Uri $url -TimeoutSec 60
        }
        catch {
            if ($page -eq 1) {
                Write-Host "[$ISO3] No dataset collection" -ForegroundColor DarkGray
            } else {
                Write-Host " [$ISO3] error on page $page; stopping pagination" -ForegroundColor DarkYellow
            }
            break
        }

        if ($resp.features) {
            foreach ($f in $resp.features) {

                # Only Population project
                if ($f.properties.project -ne "Population") { continue }

                # Only 100m products
                if ($f.properties.resolution -ne "100m") { continue }

                # Only constrained
                if ($f.id -notmatch "_CN_100m_") { continue }

                # Ignore beyond MaxYear
                if ($null -ne $f.properties.year -and $f.properties.year -gt $MaxYear) { continue }

                # Grab asset URL
                $href = $null
                if ($f.assets -and $f.assets.data -and $f.assets.data.href) { $href = $f.assets.data.href }
                if (-not $href) { continue }

                $candidates += [PSCustomObject]@{
                    ISO3 = $ISO3
                    Year = [int]$f.properties.year
                    Url  = $href
                    Size = $f.properties.size
                    Id   = $f.id
                }
            }
        }

        $nextUrl = Get-NextLinkHref $resp.links
        if (-not $nextUrl) { break }

        if ($SleepMsBetweenPages -gt 0) { Start-Sleep -Milliseconds $SleepMsBetweenPages }
        $url = $nextUrl
    }

    if ($candidates.Count -eq 0) { return $null }

    # Choose latest Year; tie-break by Id
    return $candidates |
        Sort-Object @{Expression="Year";Descending=$true}, @{Expression="Id";Descending=$true} |
        Select-Object -First 1
}

# Create output dir
New-Item -ItemType Directory -Force -Path $OutRoot | Out-Null

$ISOList = Get-ChildItem $GbOpenPath -Directory | Select-Object -Expand Name

Write-Host ""
Write-Host "Scanning $($ISOList.Count) jurisdictions..." -ForegroundColor Cyan
Write-Host ""

$results = @()

foreach ($iso in $ISOList) {

    Write-Host "Checking $iso..." -NoNewline

    $match = Get-LatestWorldPop100m $iso

    if ($null -eq $match) {
        Write-Host " no 100m population dataset" -ForegroundColor DarkYellow
        continue
    }

    Write-Host " FOUND $($match.Year)" -ForegroundColor Green

    $results += $match

    if ($TestOnly) { continue }

    $isoDir = Join-Path $OutRoot $iso
    New-Item -ItemType Directory -Force -Path $isoDir | Out-Null

    $filename = Split-Path $match.Url -Leaf
    $outfile  = Join-Path $isoDir $filename

    if ((Test-Path $outfile) -and (-not $OverwriteExisting)) {
        Write-Host "  already exists"
        continue
    }

    Write-Host "  downloading..."
    Invoke-WebRequest $match.Url -OutFile $outfile
}

Write-Host ""
Write-Host "========= SUMMARY =========" -ForegroundColor Cyan
$results | Sort-Object ISO3 | Format-Table ISO3, Year, Size -AutoSize

if ($TestOnly) {
    Write-Host ""
    Write-Host "TEST MODE ENABLED � nothing downloaded." -ForegroundColor Yellow
}