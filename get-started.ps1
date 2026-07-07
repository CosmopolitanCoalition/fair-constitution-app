param(
    # Re-ask the build-dependent questions (map-data folder, etc.) on an existing
    # box and recreate the containers so the answers take effect. Use this instead
    # of hand-running docker commands to change where the app reads map data.
    [switch]$Reconfigure
)

# Cosmopolitan Governance App - get started (Windows 10/11)
#
# Installs nothing by hand: it checks Docker, downloads the app, ASKS the few
# settings that must be baked in before the containers are built (mainly: where
# your map-data files live), starts everything, and opens the setup page.
#
# Run from anywhere (downloads the app for you):
#   irm https://raw.githubusercontent.com/CosmopolitanCoalition/fair-constitution-app/main/get-started.ps1 | iex
#
# Or, if you already downloaded the code, run it from inside that folder:
#   .\get-started.ps1              (first run, or normal start)
#   .\get-started.ps1 -Reconfigure (change the map-data folder etc. and recreate)
#
# Works in stock Windows PowerShell 5.1 and PowerShell 7. To preseed without any
# prompts (automation), set $env:CGA_ARCHIVE_PATH before running.

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'   # progress bars slow downloads badly in PowerShell 5.1
try {
    [Net.ServicePointManager]::SecurityProtocol = `
        [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
} catch { }

$Repo   = 'CosmopolitanCoalition/fair-constitution-app'
$Branch = 'main'

function Say([string]$msg) { Write-Host $msg -ForegroundColor Cyan }

# Read a line from the console. Returns $default when non-interactive or blank.
# Works through `irm | iex` in an interactive PowerShell window (Read-Host reads
# the console, not the piped script text); degrades to $default in automation.
function Ask([string]$prompt, [string]$default) {
    if (-not [Environment]::UserInteractive) { return $default }
    try {
        $ans = Read-Host $prompt
    } catch { return $default }
    if ([string]::IsNullOrWhiteSpace($ans)) { return $default }
    return $ans.Trim()
}

function Get-EnvValue([string]$key) {
    if (-not (Test-Path '.env')) { return '' }
    $m = Select-String -Path '.env' -Pattern ("^\s*" + [regex]::Escape($key) + "\s*=(.*)$") | Select-Object -First 1
    if ($m) { return $m.Matches[0].Groups[1].Value.Trim() }
    return ''
}

function Set-EnvValue([string]$key, [string]$value) {
    $lines = @(Get-Content '.env')
    $found = $false
    $out = foreach ($l in $lines) {
        if ($l -match ("^\s*" + [regex]::Escape($key) + "\s*=")) { $found = $true; "$key=$value" }
        else { $l }
    }
    if (-not $found) { $out = @($out) + "$key=$value" }
    Set-Content '.env' $out
}

# Run docker (a native command) WITHOUT letting its normal progress output abort
# the script. docker streams all of its build/pull and "Container Running" progress
# to stderr, and Windows PowerShell 5.1 promotes the FIRST stderr line from a native
# command to a TERMINATING error while $ErrorActionPreference = 'Stop' - which killed
# the run on the first "Image ... Building" line of a cold build. Relax the preference
# just for the call (docker's own exit code is the real signal, read via $LASTEXITCODE),
# and print its progress as plain lines instead of scary red NativeCommandError records.
function Invoke-Docker {
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & docker @args 2>&1 | ForEach-Object {
            # Native stderr arrives as ErrorRecords; the raw line is Exception.Message
            # (empty for docker's blank progress lines) - ToString() would print the
            # exception TYPE name for those, so use the message.
            if ($_ -is [System.Management.Automation.ErrorRecord]) { Write-Host $_.Exception.Message }
            else { Write-Host $_ }
        }
    } finally {
        $ErrorActionPreference = $prev
    }
}

# Ask where the map-data files live and write ARCHIVE_PATH / PROTOMAPS_DIR into
# .env BEFORE the containers are built, so the folder is mounted from the first
# boot. This is the thing that otherwise forces a `docker compose up -d` recreate
# mid-setup - settling it here removes that entirely.
function Configure-MapData([bool]$firstRun) {
    $current   = Get-EnvValue 'ARCHIVE_PATH'
    $isDefault = ($current -eq '' -or $current -eq './data/archive')

    # Automation override - no prompt.
    if ($env:CGA_ARCHIVE_PATH) {
        $p = ($env:CGA_ARCHIVE_PATH.Trim().Trim('"').Trim("'") -replace '\\', '/').TrimEnd('/')
        Set-EnvValue 'ARCHIVE_PATH' $p
        Set-EnvValue 'PROTOMAPS_DIR' "$p/protomaps_pmtiles"
        Say "      Map-data folder set from CGA_ARCHIVE_PATH: $p"
        return
    }

    # Only ask on the first run, when it's still unset/default, or on -Reconfigure.
    if (-not ($firstRun -or $isDefault -or $Reconfigure)) { return }

    # Detect a likely folder (a real archive has a geoBoundaries_repo subfolder).
    $candidates = @(
        'D:\fair-constitution-map-files',
        (Join-Path $HOME 'fair-constitution-map-files'),
        (Join-Path $HOME 'Downloads\fair-constitution-map-files')
    )
    $detected = $null
    foreach ($c in $candidates) {
        # A candidate on a drive that does not exist (e.g. D:\ on a single-C: PC) makes
        # Join-Path throw a terminating DriveNotFoundException under 'Stop' - catch and skip.
        try {
            if ((Test-Path (Join-Path $c 'geoBoundaries_repo')) -or (Test-Path (Join-Path $c 'worldpop_100m_latest'))) {
                $detected = $c; break
            }
        } catch { }
    }
    $default = if ($detected) { $detected } elseif (-not $isDefault) { ($current -replace '/', '\') } else { '' }

    Say ''
    Say 'Map data (boundaries + population).'
    Write-Host '  If you have already downloaded the geoBoundaries + WorldPop files, tell me the'
    Write-Host '  folder and the app will read them directly. Leave blank to skip - you can'
    Write-Host '  download them later from inside the app.'
    if ($detected) { Write-Host "  (Found a likely folder: $detected)" -ForegroundColor DarkGray }

    $promptText = if ($default) { "Map data folder [$default]" } else { "Map data folder (blank to skip)" }
    $ans = Ask $promptText $default

    if ([string]::IsNullOrWhiteSpace($ans)) {
        Say '  No folder set - point at it later with -Reconfigure, or use the in-app download.'
        return
    }
    # Strip surrounding quotes first - Windows "Copy as path" wraps the path in
    # double-quotes, which would otherwise corrupt the .env line and break the mount.
    $ans = $ans.Trim().Trim('"').Trim("'")
    $ans = ($ans -replace '\\', '/').TrimEnd('/')
    Set-EnvValue 'ARCHIVE_PATH' $ans
    Set-EnvValue 'PROTOMAPS_DIR' "$ans/protomaps_pmtiles"
    Say "  Map-data folder set to $ans  (applied when the app starts below - no docker commands needed)."
}

# -- 1. Is Docker installed and running? -------------------------------------
Say '[1/5] Checking Docker...'
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
    Say "[2/5] Using the app code in this folder: $AppDir"
} else {
    $AppDir = Join-Path $HOME 'fair-constitution-app'
    if (Test-Path (Join-Path $AppDir 'docker-compose.yml')) {
        Say "[2/5] Found the app at $AppDir"
    } else {
        Say "[2/5] Downloading the app to $AppDir ..."
        $zip = Join-Path $env:TEMP 'fair-constitution-app.zip'
        $zipUri = "https://github.com/$Repo/archive/refs/heads/$Branch.zip"
        # Retry the download with a friendly message - a novice on flaky wifi should not
        # get a raw red WebException and give up (this is the first and flakiest network
        # step, and it runs under $ErrorActionPreference='Stop').
        $downloaded = $false
        for ($try = 1; $try -le 3; $try++) {
            try {
                Invoke-WebRequest -UseBasicParsing -Uri $zipUri -OutFile $zip -TimeoutSec 120
                $downloaded = $true; break
            } catch {
                if ($try -lt 3) {
                    Say "      Download attempt $try failed ($($_.Exception.Message)). Retrying..."
                    Start-Sleep -Seconds 5
                }
            }
        }
        if (-not $downloaded) {
            throw ('Could not download the app from GitHub after several tries. Check your ' +
                'internet connection (a corporate proxy or VPN can block github.com), then run this again.')
        }
        $extract = Join-Path $env:TEMP ('cga-extract-' + [Guid]::NewGuid().ToString('N'))
        Expand-Archive -Path $zip -DestinationPath $extract -Force
        $src = Join-Path $extract "fair-constitution-app-$Branch"
        # If an incomplete earlier copy already exists (an abandoned clone, a manual ZIP,
        # a partial move), Move-Item would NEST the download inside it and the run would
        # later abort at '.env.example'. Set the old copy aside instead of nesting.
        if (Test-Path $AppDir) {
            $bak = "$AppDir.old"
            if (Test-Path $bak) { Remove-Item -Recurse -Force $bak }
            Rename-Item -LiteralPath $AppDir -NewName (Split-Path $bak -Leaf)
            Say "      Set aside an incomplete earlier copy at $bak"
        }
        Move-Item $src $AppDir
        Remove-Item $zip -Force -ErrorAction SilentlyContinue
        Remove-Item $extract -Recurse -Force -ErrorAction SilentlyContinue
    }
}
Set-Location $AppDir

# -- 3. First-run settings file ----------------------------------------------
$firstRun = -not (Test-Path '.env')
if ($firstRun) {
    Copy-Item '.env.example' '.env'
    Say '[3/5] Created your settings file (.env) from the defaults.'
} else {
    Say '[3/5] Keeping your existing settings file (.env).'
}

# -- 4. Configure the build-dependent settings (before the containers build) --
Say '[4/5] Configuring...'
Configure-MapData $firstRun

# -- 5. Start the app --------------------------------------------------------
Say '[5/5] Starting the app. The FIRST run downloads and builds everything (10-30 minutes); later starts take seconds...'
Invoke-Docker compose up -d
if ($LASTEXITCODE -ne 0) {
    # A first boot occasionally trips over itself (a container loses a race and
    # restarts) - re-issuing the same command resumes and finishes the job.
    Say 'The first start reported a problem - giving it one more push (this is usually enough)...'
    Start-Sleep -Seconds 20
    Invoke-Docker compose up -d
}
if ($LASTEXITCODE -ne 0) {
    throw ('The app containers had trouble starting. This script is safe to run again and ' +
        'resumes where it left off - try that first. If it keeps failing, run ' +
        '"docker compose logs app --tail 50" in this folder and report what it says.')
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
