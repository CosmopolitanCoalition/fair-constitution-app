param(
    # Re-ask the build-dependent questions (map-data folder, etc.) on an existing
    # box and recreate the containers so the answers take effect. Use this instead
    # of hand-running docker commands to change where the app reads map data.
    [switch]$Reconfigure,
    # Re-derive host-sized values after a resize (operator order 2026-08-30,
    # the cloud-box enabler): re-measures the host and overwrites every value
    # still listed in the .env DERIVED_KEYS ledger, reports what changed, and
    # stops. Hand-pinned values (edited AND removed from DERIVED_KEYS) are
    # never clobbered. Recreate postgres / redis_queue afterwards.
    [switch]$Rederive
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

# git is a native command too - clone/fetch/pull all narrate PROGRESS on stderr
# and would abort the script under 'Stop' exactly like docker (see Invoke-Docker).
function Invoke-Git {
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & git @args 2>&1 | ForEach-Object {
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
$justDownloaded = $false
if (Test-Path (Join-Path (Get-Location) 'docker-compose.yml')) {
    $AppDir = (Get-Location).Path
    Say "[2/5] Using the app code in this folder: $AppDir"
} else {
    $AppDir = Join-Path $HOME 'fair-constitution-app'
    if (Test-Path (Join-Path $AppDir 'docker-compose.yml')) {
        Say "[2/5] Found the app at $AppDir"
    } else {
        $justDownloaded = $true
        # If an incomplete earlier copy already exists (an abandoned clone, a manual ZIP,
        # a partial move), downloading into it would nest or fail. Set the old copy aside.
        if (Test-Path $AppDir) {
            $bak = "$AppDir.old"
            if (Test-Path $bak) { Remove-Item -Recurse -Force $bak }
            Rename-Item -LiteralPath $AppDir -NewName (Split-Path $bak -Leaf)
            Say "      Set aside an incomplete earlier copy at $bak"
        }
        if (Get-Command git -ErrorAction SilentlyContinue) {
            # Prefer git (the bash installer always has): a cloned install can
            # UPDATE in place on every re-run. A ZIP install has no update
            # channel at all - that gap stranded real installs on day-one code.
            Say "[2/5] Downloading the app to $AppDir ..."
            Invoke-Git clone --depth 1 -b $Branch "https://github.com/$Repo.git" $AppDir
            if ($LASTEXITCODE -ne 0) {
                throw ('Could not download the app with git. Check your internet connection ' +
                    '(a corporate proxy or VPN can block github.com), then run this again.')
            }
        } else {
            Say "[2/5] Downloading the app to $AppDir (no git found - using a ZIP) ..."
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
            Move-Item (Join-Path $extract "fair-constitution-app-$Branch") $AppDir
            Remove-Item $zip -Force -ErrorAction SilentlyContinue
            Remove-Item $extract -Recurse -Force -ErrorAction SilentlyContinue
        }
    }
}
Set-Location $AppDir

# -- 2b. Keep an existing install up to date ----------------------------------
# Re-running the start command IS the update path: pull the latest code and,
# when it changed, apply it inside the running app after step 5 (database
# migrations + interface build + worker restart). ZIP-era installs get
# connected to the update channel once; settings (.env) and data are untracked
# and untouched by any of this.
$updated = $false
if (-not $justDownloaded -and (Get-Command git -ErrorAction SilentlyContinue)) {
    if (-not (Test-Path '.git')) {
        Say '      Connecting this install to the update channel (one-time)...'
        Invoke-Git init
        Invoke-Git remote add origin "https://github.com/$Repo.git"
        Invoke-Git fetch --depth 1 origin $Branch
        Invoke-Git checkout -f -B $Branch "origin/$Branch"
        $updated = ($LASTEXITCODE -eq 0)
    } else {
        try { $before = & git rev-parse HEAD } catch { $before = '' }
        Say '      Checking for updates...'
        Invoke-Git pull --ff-only origin $Branch
        try { $after = & git rev-parse HEAD } catch { $after = $before }
        $updated = ($LASTEXITCODE -eq 0) -and $after -and ($before -ne $after)
        if ($updated) { Say '      Update downloaded - it will be applied after the app starts.' }
    }
}

# -- 3. First-run settings file ----------------------------------------------
$firstRun = -not (Test-Path '.env')
if ($firstRun) {
    Copy-Item '.env.example' '.env'
    Say '[3/5] Created your settings file (.env) from the defaults.'
} else {
    Say '[3/5] Keeping your existing settings file (.env).'
}

# Host-derived memory posture (operator ruling 2026-08-02: never hard-code
# what the hardware can answer — this stack must scale from a Raspberry Pi
# to big iron). Measure host RAM once and write the container caps + PG
# tuning into .env; docker-compose.yml consumes them with safe fallbacks.
# WRITE-IF-ABSENT: an operator's hand-set value is never clobbered.
function Configure-HostMemory {
    # Docker's OWN memory ceiling is the truthful host: on native Linux (a
    # Pi, a server) it equals machine RAM; on Docker Desktop it is the VM's
    # allocation — sizing against raw machine RAM there would over-commit
    # the VM and resurrect the mutual postgres/etl OOM.
    $totalMb = 0
    try {
        $memBytes = [long](& docker info --format '{{.MemTotal}}' 2>$null)
        if ($memBytes -gt 0) { $totalMb = [int]([math]::Floor($memBytes / 1MB)) }
    } catch { }
    if ($totalMb -le 0) {
        try {
            $totalMb = [int]([math]::Floor((Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory / 1MB))
        } catch { }
    }
    if ($totalMb -le 0) { return }   # can't measure — compose fallbacks apply

    function Clamp([double]$v, [int]$lo, [int]$hi) { [int][math]::Max($lo, [math]::Min($hi, [math]::Floor($v))) }
    # THE CLOSED BUDGET (operator ruling 2026-09-01, the WoS reclaim-
    # livelock freeze): every service cap is a SHARE of one derived
    # budget (HOST_BUDGET_PCT of host RAM) and the shares sum to 1000
    # per mille — the application total can never pass the host. The
    # reserved ~20% is the kernel's working room. Enforcement is the
    # cgroup OOM killer: app fails, host lives. The live wall stays as
    # the etl's graceful governor; the cap is the guarantee (revises the
    # 2026-08-06 etl-uncapped ruling, operator word 2026-09-01). Two
    # profiles: 'geodata' (etl-led ingest) and 'mapping' (horizon-led
    # drawing). Mirrors get-started.sh exactly.
    $budgetPct = 80
    $cur = Get-EnvValue 'HOST_BUDGET_PCT'
    if ($cur -ne '') { $budgetPct = [int]$cur }
    $budgetMb = [int]($totalMb * $budgetPct / 100)
    $profile = Get-EnvValue 'CGA_MEM_PROFILE'
    if ($profile -eq '') {
        $profile = 'geodata'
        try {
            $probe = & docker compose exec -T postgres psql -U ($(Get-EnvValue 'DB_USERNAME'), 'fc_user' | Where-Object { $_ })[0] -d ($(Get-EnvValue 'DB_DATABASE'), 'fair_constitution' | Where-Object { $_ })[0] -t -A -c 'SELECT 1 FROM autoscale_runs LIMIT 1' 2>$null
            if ("$probe" -match '1') { $profile = 'mapping' }
        } catch { }
    }
    # 'open' (operator pin only): the legacy overcommit posture — postgres
    # 60% of host, everything else uncapped. The E-box speed gate
    # (2026-09-01) proved closed shares must come from REAL peaks (cgroup
    # memory.peak over a run), not snapshots. Mirrors get-started.sh.
    if ($profile -eq 'open') {
        $sh = @{ pg=0; etl=0; horizon=0; app=0; vite=0; rcache=0; rqueue=0; aux=0 }
        $pgMb = Clamp ($totalMb * 0.60) 1024 262144
    } elseif ($profile -eq 'mapping') {
        $sh = @{ pg=260; etl=15;  horizon=430; app=25; vite=70; rcache=75; rqueue=60; aux=65 }
        $pgMb = Clamp ($budgetMb * $sh.pg / 1000.0) 1024 262144
    } else {
        $sh = @{ pg=340; etl=340; horizon=100; app=30; vite=50; rcache=60; rqueue=40; aux=40 }
        $pgMb = Clamp ($budgetMb * $sh.pg / 1000.0) 1024 262144
    }
    $rcMb  = Clamp ($budgetMb * $sh.rcache / 1000.0) 256 16384
    $rqMb  = Clamp ($budgetMb * $sh.rqueue / 1000.0) 226 1024
    $auxMb = Clamp ($budgetMb * $sh.aux / 1000.0) 192 4096
    $open  = ($profile -eq 'open')
    if ($open) { $rqMb = [int]((Clamp ($totalMb / 20.0) 192 512) * 100 / 85) }

    # THE HEADROOM LAW (2026-08-02, the AUS-ADM0 backend kill-loop): a giant
    # boundary INSERT is ONE backend whose transient is set by the DATA (the
    # largest single feature on Earth), not by this host — so shared_buffers
    # must stay SMALL to leave (cap - shared_buffers) as backend headroom.
    # The first derivation here (12.5% of host = 977MB on a 7.6GB box) shaved
    # legacy Phase-L's 4.0GB headroom to 3.6GB and turned a surviving 3.9GB
    # giant into a kernel kill-loop. Buffers are a cache — Postgres works
    # fine with a modest one (the OS page cache covers the rest, which is
    # what PG_EFFECTIVE_CACHE tells the planner). 512MB is plenty at any
    # scale this app runs at; work_mem is per-sort-per-connection and 64MB
    # is already generous.
    # Cores for the parallel posture (lane 2G's audit, operator order
    # 2026-08-30). Docker's own count is the truthful host, same as memory.
    $cores = 0
    try { $cores = [int](& docker info --format '{{.NCPU}}' 2>$null) } catch { }
    if ($cores -le 0) { try { $cores = [int]$env:NUMBER_OF_PROCESSORS } catch { } }
    if ($cores -le 0) { $cores = 4 }

    # Ceilings lifted where they only bound big iron (audit 2026-08-30).
    # KEPT deliberately: shared_buffers <= 512MB (the headroom law) and
    # global work_mem <= 64MB (lanes raise their own sessions).
    $derived = [ordered]@{
        POSTGRES_MEM_LIMIT = "${pgMb}m"
        PG_SHARED_BUFFERS  = (Clamp ($pgMb / 9.0) 128  512).ToString() + 'MB'
        PG_EFFECTIVE_CACHE = (Clamp ($pgMb * 0.80) 256 210000).ToString() + 'MB'
        PG_WORK_MEM        = (Clamp ($pgMb / 72.0)   8   64).ToString() + 'MB'
        PG_MAINTENANCE_MEM = (Clamp ($pgMb / 9.0) 128 4096).ToString() + 'MB'
        PG_MAX_WAL         = (Clamp ($pgMb * 1.60) 512 65536).ToString() + 'MB'
        PG_MIN_WAL         = (Clamp ($pgMb * 0.20) 512 8192).ToString() + 'MB'
        PG_WAL_BUFFERS     = (Clamp ($pgMb / 72.0) 16 128).ToString() + 'MB'
        PG_SHM_SIZE        = (Clamp ($pgMb / 4.0) 1024 8192).ToString() + 'm'
        PG_MAX_CONNECTIONS = (Clamp ($pgMb / 23.0) 100 800).ToString()
        # Budget-ledger keys. maxmemory = 85% of each redis container cap,
        # so eviction (or the queue's volatile-ttl shed) always fires
        # before the cgroup killer reaps the whole redis.
        HOST_BUDGET_PCT = $budgetPct.ToString()
        MEM_HORIZON = $(if ($open) { "${totalMb}m" } else { (Clamp ($budgetMb * $sh.horizon / 1000.0) 512 65536).ToString() + 'm' })
        MEM_APP     = $(if ($open) { "${totalMb}m" } else { (Clamp ($budgetMb * $sh.app / 1000.0) 128 8192).ToString() + 'm' })
        MEM_VITE    = $(if ($open) { "${totalMb}m" } else { (Clamp ($budgetMb * $sh.vite / 1000.0) 256 4096).ToString() + 'm' })
        ETL_MEM_LIMIT = $(if ($open) { '0' } else { (Clamp ($budgetMb * $sh.etl / 1000.0) 96 262144).ToString() + 'm' })
        MEM_REDIS_CACHE = $(if ($open) { "${totalMb}m" } else { "${rcMb}m" })
        MEM_REDIS_QUEUE = $(if ($open) { "${totalMb}m" } else { "${rqMb}m" })
        MEM_MATRIX    = $(if ($open) { "${totalMb}m" } else { (Clamp ($auxMb * 0.40) 160 4096).ToString() + 'm' })
        MEM_SCHEDULER = $(if ($open) { "${totalMb}m" } else { (Clamp ($auxMb * 0.35) 96 2048).ToString() + 'm' })
        MEM_MAS       = $(if ($open) { "${totalMb}m" } else { (Clamp ($auxMb * 0.17) 48 1024).ToString() + 'm' })
        MEM_NGINX     = $(if ($open) { "${totalMb}m" } else { (Clamp ($auxMb * 0.08) 32 512).ToString() + 'm' })
        REDIS_CACHE_MAXMEMORY = $(if ($open) { (Clamp ($totalMb / 10.0) 768 8192).ToString() + 'mb' } else { ([int]($rcMb * 0.85)).ToString() + 'mb' })
        # Parallel posture: workers=cores, parallel=cores/2, per_gather
        # small (many concurrent lanes beat wide gathers), maintenance=cores/4.
        PG_MAX_WORKER_PROCESSES = (Clamp $cores 8 64).ToString()
        PG_MAX_PARALLEL_WORKERS = (Clamp ($cores / 2.0) 2 32).ToString()
        PG_PARALLEL_PER_GATHER  = (Clamp ($cores / 6.0) 1 4).ToString()
        PG_PARALLEL_MAINTENANCE = (Clamp ($cores / 4.0) 1 8).ToString()
        # The queue redis (volatile-ttl: TTL'd horizon metadata self-trims,
        # queue payloads never evict) sizes from its budget share.
        REDIS_QUEUE_MAXMEMORY   = ([int]($rqMb * 0.85)).ToString() + 'mb'
        PG_AUTOVACUUM_COST_LIMIT = (Clamp (200 * $cores / 2.0) 200 2000).ToString()
    }

    # THE RE-DERIVE MECHANISM (operator order 2026-08-30): the DERIVED_KEYS
    # ledger in .env lists every value this function owns. -Rederive
    # overwrites values still in the ledger; a hand-pinned value (edited
    # AND removed from the ledger) is never clobbered. A missing ledger
    # bootstraps itself: a current value equal to its fresh derivation is
    # self-evidently derived. Mirrors get-started.sh exactly.
    $ledger = @((Get-EnvValue 'DERIVED_KEYS') -split ',' | Where-Object { $_ -ne '' })
    $ledgerBoot = ($ledger.Count -eq 0)
    # THE LEDGER BOOT DERIVES EVERYTHING + THE HOST-SIZE RE-DERIVE (WoS
    # 2026-09-02): before the ledger existed nothing was a pin, so the first
    # run owns every key; a changed host size re-derives every ledger value.
    $derived['DERIVED_HOST_MB'] = "$totalMb"
    $prevHost = Get-EnvValue 'DERIVED_HOST_MB'
    if ($prevHost -ne '' -and $prevHost -ne "$totalMb" -and -not $Rederive) {
        $Rederive = $true
        Say "  host RAM changed ($prevHost -> $totalMb MB): re-deriving every ledger value"
    }
    $wrote = @()
    foreach ($k in $derived.Keys) {
        $cur = Get-EnvValue $k
        if ($cur -eq '') {
            Set-EnvValue $k $derived[$k]; $wrote += $k
            if ($ledger -notcontains $k) { $ledger += $k }
        } elseif ($ledgerBoot) {
            if ($cur -ne $derived[$k]) { Set-EnvValue $k $derived[$k]; $wrote += $k; Say "      derived ${k}: $cur -> $($derived[$k]) (ledger boot)" }
            if ($ledger -notcontains $k) { $ledger += $k }
        } elseif ($Rederive -and $ledger -contains $k -and $cur -ne $derived[$k]) {
            Set-EnvValue $k $derived[$k]; $wrote += $k
            Say "      rederived ${k}: $cur -> $($derived[$k])"
        }
    }
    if ((Get-EnvValue 'REDIS_QUEUE_HOST') -eq '') { Set-EnvValue 'REDIS_QUEUE_HOST' 'redis_queue' }
    Set-EnvValue 'DERIVED_KEYS' ($ledger -join ',')
    if ($wrote.Count -gt 0) {
        Say ("      Memory sized from this host ({0:N1} GB RAM, {1} cores): budget {2}m ({3}%), profile {4}, postgres {5}." -f ($totalMb/1024), $cores, $budgetMb, $budgetPct, $profile, $derived.POSTGRES_MEM_LIMIT)
    }
    Set-EnvValue 'CGA_MEM_PROFILE' $profile
}

# -Rederive (operator order 2026-08-30): after a host resize — the cloud
# burst box throttling up or down — re-measure, overwrite every value still
# in the DERIVED_KEYS ledger, report, and stop. Nothing boots here.
if ($Rederive) {
    Say 'Re-deriving host-sized values (hand-pinned values - those removed from DERIVED_KEYS - stay untouched)...'
    Configure-HostMemory
    Say 'Done. Changed values apply on service RECREATE:'
    Say '  every capped service - its MEM_* share of the closed budget'
    Say '  postgres    - POSTGRES_MEM_LIMIT + every PG_* value'
    Say '  redis pair  - REDIS_*_MAXMEMORY + MEM_REDIS_*'
    Say '  docker compose up -d   (when the box is quiet; recreates changed services)'
    exit 0
}

# -- 4. Configure the build-dependent settings (before the containers build) --
Say '[4/5] Configuring...'
Configure-MapData $firstRun
Configure-HostMemory

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

# FRESH INSTALL: load the database schema (fresh-install walk, 2026-08-02 —
# a brand-new clone booted with an EMPTY database and /setup 500'd; the
# update branch below migrates, but a first install never did). The app
# container composer-installs on first boot (minutes); wait for its DONE
# stamp (vendor/.installed-hash — the deploy.ps1 pattern) before migrating.
if ($justDownloaded -or $firstRun) {
    Say 'Loading the database schema (waits for the first-boot dependency install)...'
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try {
        for ($i = 0; $i -lt 240; $i++) {
            & docker compose exec -T app test -f vendor/.installed-hash 2>$null
            if ($LASTEXITCODE -eq 0) { break }
            Start-Sleep -Seconds 5
        }
    } finally { $ErrorActionPreference = $prev }
    Invoke-Docker compose exec -T app php artisan migrate --force
    if ($LASTEXITCODE -ne 0) {
        Start-Sleep -Seconds 15
        Invoke-Docker compose exec -T app php artisan migrate --force
    }
}

# Apply a downloaded update inside the running app: database migrations, a
# fresh interface build, and a worker restart so queued jobs load the new code.
if ($updated) {
    Say 'Applying the update (database changes + interface build)...'
    Invoke-Docker compose exec -T app php artisan migrate --force
    if ($LASTEXITCODE -ne 0) {
        # The database container can still be waking up right after `up -d`.
        Start-Sleep -Seconds 15
        Invoke-Docker compose exec -T app php artisan migrate --force
    }
    Invoke-Docker compose run --rm --no-deps vite npm run build
    Invoke-Docker compose restart app horizon scheduler
    Say 'Update applied.'
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
