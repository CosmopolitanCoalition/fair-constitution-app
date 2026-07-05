# Cosmopolitan Governance App - get started (Windows 10/11)
#
# Gets the app onto this computer, starts it in Docker, and opens the setup
# page in your browser. Safe to run again any time - it reuses what exists.
#
# Run from anywhere (downloads the app for you):
#   irm https://raw.githubusercontent.com/CosmopolitanCoalition/fair-constitution-app/main/get-started.ps1 | iex
#
# Or, if you already downloaded the code, run it from inside that folder:
#   .\get-started.ps1
#
# Works in stock Windows PowerShell 5.1 and PowerShell 7.

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'   # progress bars slow downloads badly in PowerShell 5.1
try {
    [Net.ServicePointManager]::SecurityProtocol = `
        [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
} catch { }

$Repo   = 'CosmopolitanCoalition/fair-constitution-app'
$Branch = 'main'

function Say([string]$msg) { Write-Host $msg -ForegroundColor Cyan }

# -- 1. Is Docker installed and running? -------------------------------------
Say '[1/4] Checking Docker...'
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw ('Docker is not installed. Install Docker Desktop from ' +
        'https://www.docker.com/products/docker-desktop/ , open it, wait until it says ' +
        '"Engine running", then run this again.')
}
cmd /c "docker info >nul 2>&1"
if ($LASTEXITCODE -ne 0) {
    throw ('Docker is installed but not running. Open Docker Desktop, wait until it says ' +
        '"Engine running", then run this again.')
}

# -- 2. Find or download the app code ----------------------------------------
if (Test-Path (Join-Path (Get-Location) 'docker-compose.yml')) {
    $AppDir = (Get-Location).Path
    Say "[2/4] Using the app code in this folder: $AppDir"
} else {
    $AppDir = Join-Path $HOME 'fair-constitution-app'
    if (Test-Path (Join-Path $AppDir 'docker-compose.yml')) {
        Say "[2/4] Found the app at $AppDir"
    } else {
        Say "[2/4] Downloading the app to $AppDir ..."
        $zip = Join-Path $env:TEMP 'fair-constitution-app.zip'
        Invoke-WebRequest -UseBasicParsing `
            -Uri "https://github.com/$Repo/archive/refs/heads/$Branch.zip" -OutFile $zip
        $extract = Join-Path $env:TEMP ('cga-extract-' + [Guid]::NewGuid().ToString('N'))
        Expand-Archive -Path $zip -DestinationPath $extract -Force
        Move-Item (Join-Path $extract "fair-constitution-app-$Branch") $AppDir
        Remove-Item $zip -Force -ErrorAction SilentlyContinue
        Remove-Item $extract -Recurse -Force -ErrorAction SilentlyContinue
    }
}
Set-Location $AppDir

# -- 3. First-run settings file ----------------------------------------------
if (-not (Test-Path '.env')) {
    Copy-Item '.env.example' '.env'
    Say '[3/4] Created your settings file (.env) from the defaults.'
} else {
    Say '[3/4] Keeping your existing settings file (.env).'
}

# -- 4. Start the app --------------------------------------------------------
Say '[4/4] Starting the app. The FIRST run downloads and builds everything (10-30 minutes); later starts take seconds...'
docker compose up -d
if ($LASTEXITCODE -ne 0) {
    throw ('Docker could not start the app. Scroll up for the error. The most common fix is ' +
        'opening Docker Desktop and waiting for "Engine running", then running this again.')
}

# Wait for the web page to answer, then open the browser.
$port = 8080
$portLine = Select-String -Path '.env' -Pattern '^\s*NGINX_HOST_PORT=(\d+)' | Select-Object -First 1
if ($portLine) { $port = [int]$portLine.Matches[0].Groups[1].Value }
$url = "http://localhost:$port/setup"

Say "Waiting for $url to come up (this is the long part on a first run)..."
$deadline = (Get-Date).AddMinutes(40)
$up = $false
while ((Get-Date) -lt $deadline) {
    try {
        $resp = Invoke-WebRequest -UseBasicParsing -Uri $url -TimeoutSec 5
        if ($resp.StatusCode -eq 200) { $up = $true; break }
    } catch { }
    Start-Sleep -Seconds 10
    Write-Host '.' -NoNewline
}
Write-Host ''

if ($up) {
    Say "Ready! Opening $url"
    Start-Process $url
    Say 'The setup wizard in your browser takes it from here.'
} else {
    Say "It's still building - that can be normal on a slower connection. Leave it running"
    Say "and open $url in your browser in a little while."
    Say '(To watch progress: docker compose logs -f --tail 20)'
}
