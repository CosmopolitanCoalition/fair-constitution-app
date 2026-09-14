#Requires -Version 7
<#
.SYNOPSIS
  Phase G (G0b) — one-command deploy for a Cosmopolitan Governance App instance (Windows/pwsh).

.DESCRIPTION
  Stands up a fresh instance from a code checkout: writes .env (unique container
  prefix + host ports, and a FRESH APP_KEY so a clone NEVER shares another
  instance's ballot-encryption / signed-URL keys), brings the Docker stack up,
  migrates, and — optionally — adopts a host as a read-only MIRROR in one step.

  Idempotent and clone-identity-safe (every fresh deploy mints its own APP_KEY,
  and `federation:init` mints its own Ed25519 server_id in its own database, so
  two instances are never the same identity).

.EXAMPLE
  ./deploy.ps1
.EXAMPLE
  ./deploy.ps1 -Prefix fcm -NginxPort 8082 -PgPort 5434 -VitePort 5175 `
               -Join http://host.docker.internal:8081 -Key handle.secret
#>
[CmdletBinding()]
param(
  [string]$Prefix    = "fc",
  [int]$NginxPort    = 8080,
  [int]$PgPort       = 5432,
  [int]$VitePort     = 5173,
  [string]$SelfUrl   = "",
  [string]$PublicUrl = "",
  [string]$MediaIp   = "",
  [string]$Project   = "",
  [switch]$Seed,
  [switch]$WithEtl,
  [string]$Join      = "",
  [string]$Key       = "",
  [switch]$CloneRekey
)

$ErrorActionPreference = "Stop"

Set-Location -Path $PSScriptRoot

# -- PUBLIC (cloud / VPS) MODE (parity with deploy.sh public block) ---------------------------
# -PublicUrl turns a LAN-shaped deploy into an internet-facing one. It is the ONLY place the
# box's permanent public identity is set, because three of these values are LOCKED once the
# stack has booted once: MATRIX_DOMAIN (Synapse writes it into every event; no rename),
# FEDERATION_SELF_URL (peers PIN it at handshake) and APP_URL (MAS pins it as the OIDC issuer).
# Getting them right BEFORE the first boot is the whole reason this flag exists.
$PublicHost = ""
if ($PublicUrl) {
  $PublicUrl = $PublicUrl.TrimEnd('/')
  if ($PublicUrl -like 'http://*') {
    Write-Error ("-PublicUrl must be https:// (TLS is required for browser geolocation, secure " +
      "cookies, and Matrix federation). Got: $PublicUrl") -ErrorAction Continue
    exit 1
  }
  if ($PublicUrl -notlike 'https://*') {
    Write-Error "-PublicUrl must start with https:// - got: $PublicUrl" -ErrorAction Continue
    exit 1
  }
  $PublicHost = $PublicUrl.Substring('https://'.Length)
  $PublicHost = ($PublicHost -split '/')[0]
  $PublicHost = ($PublicHost -split ':')[0]
  if (-not $PublicHost -or $PublicHost -notlike '*.*') {
    Write-Error ("-PublicUrl needs a real hostname (e.g. https://earth.example.org), not an IP " +
      "or a bare name - Let's Encrypt cannot issue for '$PublicHost'.") -ErrorAction Continue
    exit 1
  }
  # A public box must never be seeded with demo institutions: institutions:demo-e writes
  # residency confirmations, i.e. MANUFACTURED CONSENT, into an append-only ledger.
  if ($Seed) {
    Write-Error ("-Seed cannot be combined with -PublicUrl. -Seed runs institutions:demo-e, " +
      "which fabricates residents on a public instance. Refusing.") -ErrorAction Continue
    exit 1
  }
  # The dev time + role controls can advance constitutional deadlines and file ballots AS a
  # seated member. A PUBLIC box deployed with either still on is the one path by which a
  # fabricated vote could enter a hash-chained record other nodes take on trust. Refuse at
  # the door, like -Seed. (docs/plans/playtest/DEV_TIME_AND_ROLE_CONTROLS.md section 4.)
  if (Test-Path .env) {
    $envText = @(Get-Content .env)
    if ($envText | Where-Object { $_ -match '^CGA_DEV_TIME=\s*(1|true|on|yes)\s*$' }) {
      Write-Error ("CGA_DEV_TIME is enabled in .env and cannot be combined with -PublicUrl. The " +
        "dev clock/role controls can file ballots as a seated member; on a public node that would " +
        "put a fabricated vote into a record other nodes trust. Set CGA_DEV_TIME=false and re-run.") -ErrorAction Continue
      exit 1
    }
    if ($envText | Where-Object { $_ -match '^CGA_IMPERSONATION=\s*(1|true|on|yes)\s*$' }) {
      Write-Error "CGA_IMPERSONATION is enabled in .env and cannot be combined with -PublicUrl. Set CGA_IMPERSONATION=false and re-run." -ErrorAction Continue
      exit 1
    }
  }
  # -SelfUrl still wins if given explicitly (overlay transports pass it).
  if (-not $SelfUrl) { $SelfUrl = $PublicUrl }
}

if (-not $SelfUrl) { $SelfUrl = "http://host.docker.internal:$NginxPort" }

# Compose project resolution (parity with deploy.sh:146-155). -Project wins. Otherwise REUSE
# the project this checkout already runs under: the COMPOSE_PROJECT_NAME a prior run pinned in
# .env. Defaulting STRAIGHT to $Prefix orphaned a box previously deployed under a custom
# project — the rerun redeployed under a DIFFERENT, empty set of volumes and the world looked
# gone. Only when .env carries no pin do we fall back to $Prefix. Parse like the .env readers
# below (literal, strip surrounding quotes / whitespace).
if (-not $Project) {
  $pinnedProject = ""
  if (Test-Path .env) {
    foreach ($line in @(Get-Content .env)) {
      if ($line -like "COMPOSE_PROJECT_NAME=*") {
        $pinnedProject = ($line.Substring("COMPOSE_PROJECT_NAME=".Length)).Trim().Trim('"')
        break
      }
    }
  }
  if ($pinnedProject) { $Project = $pinnedProject } else { $Project = $Prefix }
}

$dc = @("compose", "-p", $Project)
function Invoke-Artisan { docker @dc exec -T app php artisan @args }

# Public Matrix bundle validator (parity with deploy.sh:186-190 check_public_matrix_bundle).
# Runs the isolated Python check inside Synapse's own image with PyYAML, mounting only the
# check script and the bundle read-only, on no network. Returns $true when the bundle is a
# consistent public configuration (exit 0), $false otherwise.
$PublicMatrixImage = "ghcr.io/element-hq/synapse:latest"
function Test-PublicMatrixBundle([string]$Root) {
  docker run --rm --network none --read-only --user 0:0 `
    --mount "type=bind,src=$($PWD.Path)/scripts/deploy/check_public_matrix.py,dst=/deploy-check.py,readonly" `
    --mount "type=bind,src=$Root,dst=/bundle,readonly" `
    --entrypoint python $PublicMatrixImage /deploy-check.py bundle /bundle $PublicHost $PublicUrl
  return ($LASTEXITCODE -eq 0)
}

# Check the stored Matrix identity BEFORE changing .env or starting any services (parity with
# deploy.sh:160-182). A different public hostname cannot rename a homeserver. Never erase its
# databases or volume as a recovery step. The probe mounts existing state read-only.
$script:MatrixExisting = "fresh"
if ($PublicUrl) {
  $matrixVolumes = docker volume ls --format '{{.Name}}'
  if ($LASTEXITCODE -ne 0) {
    Write-Error "Cannot inspect existing Matrix storage. No deployment changes made." -ErrorAction Continue
    exit 1
  }
  if (@($matrixVolumes) -contains "${Project}_matrix_data") {
    $nameProbe = docker run --rm --network none --read-only --user 0:0 `
      --mount "type=volume,src=${Project}_matrix_data,dst=/matrix-state,readonly" `
      --mount "type=bind,src=$($PWD.Path)/scripts/deploy/check_public_matrix.py,dst=/deploy-check.py,readonly" `
      --entrypoint python $PublicMatrixImage /deploy-check.py name /matrix-state/homeserver.yaml $PublicHost
    if ($LASTEXITCODE -ne 0) {
      Write-Error ("Matrix identity check failed. Existing configuration and room data were " +
        "preserved. Re-run with the original public hostname or use a separate empty installation.") -ErrorAction Continue
      exit 1
    }
    $probeState = (@($nameProbe) | Select-Object -Last 1).Trim()
    switch ($probeState) {
      'fresh'    { $script:MatrixExisting = 'fresh' }
      'existing' { $script:MatrixExisting = 'existing' }
      default    {
        Write-Error "Matrix identity inspection returned an unknown state. No deployment changes made." -ErrorAction Continue
        exit 1
      }
    }
  }
}

# 1. .env from the template on a fresh checkout.
if (-not (Test-Path .env)) { Copy-Item .env.example .env }

function Set-EnvVar([string]$Key, [string]$Value) {
  # Wildcard match + literal rewrite (NOT regex) so a key/value with regex metachars can
  # never mis-match or mangle the replacement.
  $lines = @(Get-Content .env)
  $found = $false
  $out = foreach ($line in $lines) {
    if ($line -like "$Key=*") { $found = $true; "$Key=$Value" } else { $line }
  }
  if (-not $found) { $out = @($out) + "$Key=$Value" }
  Set-Content -Path .env -Value $out
}

# Literal .env reader (used by the public identity block and the APP_KEY detection below).
function Get-EnvValue([string]$Path, [string]$Name) {
  if (-not (Test-Path $Path)) { return "" }
  foreach ($line in @(Get-Content $Path)) {
    if ($line -like "$Name=*") { return $line.Substring($Name.Length + 1) }
  }
  return ""
}

Set-EnvVar "CONTAINER_PREFIX"    $Prefix
# Pin the compose project so the plain `docker compose exec/down` commands in docs/FRESH-NODE-START.md
# resolve to the SAME project this script brought up with `-p $Project`. Without it compose derives the
# project from the directory name and the doc's bare commands miss the just-deployed containers.
# (.env.example deliberately omits this so each git worktree still auto-derives its own per-dir project.)
Set-EnvVar "COMPOSE_PROJECT_NAME" $Project
Set-EnvVar "NGINX_HOST_PORT"     "$NginxPort"
Set-EnvVar "POSTGRES_HOST_PORT"  "$PgPort"
Set-EnvVar "VITE_HOST_PORT"      "$VitePort"
Set-EnvVar "FEDERATION_SELF_URL" $SelfUrl

if ($PublicUrl) {
  # The public identity (parity with deploy.sh public set_env block). These are LOCKED after
  # the first boot; see the -PublicUrl block above for why.
  Set-EnvVar "APP_URL"               $PublicUrl
  Set-EnvVar "MATRIX_DOMAIN"         $PublicHost
  # Matrix delegation: WellKnownController derives "<server_name>:<port>" from APP_URL's port,
  # and an https URL HAS no port, so it would emit a bare hostname the Matrix spec reads as
  # port 8448, NOT 443. Set it explicitly or remote homeservers dial a closed port.
  Set-EnvVar "MATRIX_DELEGATE_SERVER" "${PublicHost}:443"
  Set-EnvVar "MATRIX_MAS_ISSUER"     "https://auth.$PublicHost/"
  Set-EnvVar "LIVEKIT_PUBLIC_URL"    "wss://rtc.$PublicHost"
  # LiveKit ICE on a cloud box: PIN the advertised media address to the public host's own
  # A-record (the address browsers reach), never STUN (STUN returns the outbound SNAT address
  # behind a cloud NAT, which never routes inbound). -MediaIp overrides; else resolve via DNS.
  if (-not $MediaIp) {
    try {
      $MediaIp = @([System.Net.Dns]::GetHostAddresses($PublicHost) |
        Where-Object { $_.AddressFamily -eq 'InterNetwork' })[0].IPAddressToString
    } catch { $MediaIp = "" }
  }
  if ($MediaIp) {
    Set-EnvVar "LIVEKIT_NODE_IP" $MediaIp
    Write-Host "-> LiveKit media address = $MediaIp   (the A-record of $PublicHost; override with -MediaIp)"
  } else {
    Write-Warning "Could not resolve $PublicHost to an IPv4 address. Voice will advertise the default. Re-run with -MediaIp <the public address browsers reach> once DNS resolves."
  }
  if (Test-Path docker/livekit/livekit.yaml) {
    $lk = @(Get-Content docker/livekit/livekit.yaml) | ForEach-Object {
      $_ -replace '^(\s*use_external_ip:\s*).*', '${1}false'
    }
    Set-Content -LiteralPath docker/livekit/livekit.yaml -Value $lk
  }
  # Every internal port binds LOOPBACK on a public box. Postgres, raw Synapse, raw MAS and the
  # Vite dev port must never face the internet; the edge proxy is the only public listener.
  Set-EnvVar "NGINX_HOST_PORT"     "127.0.0.1:$NginxPort"
  Set-EnvVar "POSTGRES_HOST_PORT"  "127.0.0.1:$PgPort"
  Set-EnvVar "VITE_HOST_PORT"      "127.0.0.1:$VitePort"
  Set-EnvVar "MATRIX_HOST_PORT"    "127.0.0.1:8008"
  Set-EnvVar "MAS_HOST_PORT"       "127.0.0.1:8090"
  Set-EnvVar "LIVEKIT_HOST_PORT"   "127.0.0.1:7880"
  Set-EnvVar "PUBLIC_HOSTNAME"     $PublicHost
  # A public box is a SERVING box. Pin the closed 'serving' memory profile so the next
  # get-started rederive sizes for it. An operator pin of 'open' stands. deploy never
  # re-derives memory itself.
  $curProfile = (Get-EnvValue ".env" "CGA_MEM_PROFILE").Trim().Trim('"')
  if ($curProfile -ne "open" -and $curProfile -ne "serving") {
    Set-EnvVar "CGA_MEM_PROFILE" "serving"
    Write-Host "-> Memory profile pinned to 'serving' (apply the caps with: ./get-started.ps1 -Rederive)"
  }
  if (-not $env:ACME_EMAIL) { Set-EnvVar "ACME_EMAIL" ("admin@" + ($PublicHost -replace '^[^.]+\.', '')) }
  else { Set-EnvVar "ACME_EMAIL" $env:ACME_EMAIL }
  # Make every bare `docker compose` command in the runbooks pick up the edge proxy too.
  $composeFileValue = "docker-compose.yml" + [IO.Path]::PathSeparator + "docker-compose.public.yml"
  Set-EnvVar "COMPOSE_FILE" $composeFileValue
  $env:COMPOSE_FILE = $composeFileValue
} else {
  Set-EnvVar "APP_URL"             "http://localhost:$NginxPort"
}

# Architecture parity with deploy.sh: the official postgis image is amd64-only; on
# arm64 (Windows-on-ARM / an arm64 Docker host) use the multi-arch rebuild. amd64
# keeps the default. PROCESSOR_ARCHITECTURE is the host CPU under pwsh.
$arch = $env:PROCESSOR_ARCHITECTURE
if ($arch -match 'ARM64') { Set-EnvVar "POSTGIS_IMAGE" "imresamu/postgis:17-3.5" }

# Matrix homeserver image (Phase K-3, parity with deploy.sh). Synapse is the verified default
# on every arch (feature-complete; matrix-org/synapse archived Apr 2024 -> element-hq, AGPLv3).
# Dendrite as the arm64/Pi default is deferred to the K3-N rig spike; until it passes, Synapse.
Set-EnvVar "MATRIX_IMPL"  "synapse"
Set-EnvVar "MATRIX_IMAGE" "ghcr.io/element-hq/synapse:latest"
# MAS image (Phase K-3). A production deploy must run `php artisan matrix:setup` (K3-D) to regenerate
# the MAS config + Synapse-shared secret before bringing MAS up (the committed config.yaml is DEV-only).
Set-EnvVar "MAS_IMAGE" "ghcr.io/element-hq/matrix-authentication-service:latest"

# Deployed posture: production + debug off (parity with deploy.sh). deploy.ps1 stands up a
# built-asset instance; a dev box uses `docker compose up` (local + HMR) instead.
Set-EnvVar "APP_ENV"   "production"
Set-EnvVar "APP_DEBUG" "false"

# Pin the database cache store (federation/mesh throttle backstop) to pgsql so it can never
# fall through to the sqlite default and 500 every /api/federation route. Fresh clones get
# this from .env.example; this also covers an in-place upgrade whose .env predates the key.
Set-EnvVar "DB_CACHE_CONNECTION" "pgsql"

# Explicit service list (parity with deploy.sh): omit `etl` (heavy geospatial Python a
# federation node never uses) and `vite` (dev HMR — a deployed box serves the built assets
# produced at the end). nginx starts LAST so compose never aborts the up waiting on a
# php-fpm still mid composer-install.
Write-Host "-> Bringing up the stack (project=$Project, prefix=$Prefix, nginx :$NginxPort)..."
# `etl` (geoBoundaries+WorldPop loader) is OPT-IN via -WithEtl: a FOUNDING node that will
# import map data needs it (builds on amd64 AND arm64); a mirror skips it. vite is omitted
# (a deployed box serves built assets).
# redis_queue is the queue's single home (REDIS_QUEUE_HOST=redis_queue) and is NOT behind a
# profile, yet it was missing here: a --public-url deploy left it down, the app 500ed
# (getaddrinfo redis_queue) and Horizon looped until a manual up -d (WoS 2026-09-08).
$services = @("app", "postgres", "redis", "redis_queue", "horizon", "scheduler")
if ($WithEtl) { $services += "etl" }
docker @dc up -d --build @services

Write-Host "-> Waiting for PostgreSQL..."
# Probe over TCP (-h 127.0.0.1), NOT the Unix socket. On a fresh `down -v` the postgres image runs initdb
# on a transient temp-server that listens on the local SOCKET ONLY (listen_addresses='') while it runs
# init.sql (which CREATEs the matrix/matrix_auth DBs), THEN restarts into the real TCP server. A socket
# probe clears on that temp-server before init.sql finishes, so the matrix-DB guard races it and
# double-CREATEs `matrix` ("duplicate key ... datname=matrix already exists" -> aborts the cold deploy).
# A TCP probe is invisible to the socket-only temp-server, so it clears only once the real server is up
# and past recovery -- by which point init.sql has run and the matrix DBs exist. (Parity with deploy.sh.)
for ($i = 0; $i -lt 90; $i++) {
  docker @dc exec -T postgres pg_isready -h 127.0.0.1 -U fc_user -d fair_constitution *> $null
  if ($LASTEXITCODE -eq 0) { break }
  Start-Sleep -Seconds 2
}

# Phase K-3 (parity with deploy.sh): ensure the Matrix + MAS logical DBs exist before the
# homeserver boots. init.sql CREATE DATABASE runs only on a fresh postgres volume; a warm
# volume needs this idempotent guard. Synapse REQUIRES C collation (the server-wide --locale=C).
Write-Host "-> Ensuring the Matrix logical databases..."
foreach ($db in @("matrix", "matrix_auth")) {
  $exists = docker @dc exec -T postgres psql -U fc_user -d fair_constitution -tAc "SELECT 1 FROM pg_database WHERE datname='$db'"
  if (-not ($exists -match '1')) {
    docker @dc exec -T postgres psql -U fc_user -d fair_constitution -c "CREATE DATABASE $db WITH OWNER fc_user ENCODING 'UTF8' LC_COLLATE 'C' LC_CTYPE 'C' TEMPLATE template0"
  }
}

# Bring the homeserver + MAS up now that their DB exists (they crash-loop if they boot first).
# On a PUBLIC box we must NOT start them here: the committed docker/matrix/mas config carries
# DEV-ONLY secrets and a localhost issuer. They are started further down, AFTER matrix:setup
# regenerates a matched bundle and check_public_matrix validates it. A public box must never
# fall back to the committed development Matrix secrets. (Parity with deploy.sh:352-372.)
if (-not $PublicUrl) {
  Write-Host "-> Starting the Matrix homeserver..."
  docker @dc up -d matrix

  # The committed docker/matrix/mas config carries DEV-ONLY secrets (fine for a LAN rig; a PUBLIC
  # deploy runs `php artisan matrix:setup` first). Without this a fresh founder gets no Matrix login
  # until `docker compose up -d mas` is run by hand.
  Write-Host "-> Starting the Matrix Auth Service..."
  docker @dc up -d mas
}

# The app entrypoint runs `composer install` on first boot (minutes on a fresh clone) and
# writes vendor/.installed-hash as its DONE marker. Wait for that STAMP before firing
# artisan — gating on vendor/autoload.php races (it appears before the framework is fully
# extracted → 'class not found' in key:generate). The vendor named volume starts EMPTY on a
# fresh checkout, so without this the first deploy fatals. (Parity with deploy.sh.)
Write-Host "-> Waiting for the app (composer install)..."
for ($i = 0; $i -lt 240; $i++) {
  docker @dc exec -T app test -f vendor/.installed-hash *> $null
  if ($LASTEXITCODE -eq 0) { break }
  Start-Sleep -Seconds 5
}

# 2. APP_KEY — mint one ONLY when this box has never had its own (parity with deploy.sh:400-407).
#    APP_KEY encrypts instance_settings.private_key_encrypted (the federation signing key), so
#    regenerating it on a rerun makes every Crypt::decryptString() of that key throw "MAC is
#    invalid" — the whole point of M3. Detection is exact: a virgin .env carries the committed
#    .env.example key verbatim, so "differs from the example key" means "this box is already
#    keyed" and we leave it alone. Same Set-EnvVar-style parse used above (literal, not regex).
#    (Get-EnvValue is defined near Set-EnvVar above.)
$exampleAppKey = Get-EnvValue ".env.example" "APP_KEY"
$currentAppKey = Get-EnvValue ".env" "APP_KEY"
if (-not $currentAppKey -or $currentAppKey -eq $exampleAppKey) {
  Write-Host "-> Generating a fresh APP_KEY..."
  Invoke-Artisan key:generate --force
  # Virgin box = FIRST run: the join step below adopts (keyed cluster:join), never resumes.
  $script:HadExistingAppKey = $false
} else {
  Write-Host "-> Preserving the existing APP_KEY (federation identity intact)."
  # Already-keyed box = RERUN: the join step resumes first, adopts only if no membership exists.
  $script:HadExistingAppKey = $true
}

# 2b. Public rooms start only with a validated matched bundle (parity with deploy.sh:419-481).
# Reuse a valid bundle on updates: regenerating MAS's encryption key would break existing auth
# records. A fresh install generates into a temporary directory and installs only after every
# sibling file passes; warnings from matrix:setup are not readiness. A public box NEVER falls
# back to the committed development Matrix secrets: on a failed or missing bundle it refuses,
# and Matrix + MAS are not started.
if ($PublicUrl) {
  if (Test-PublicMatrixBundle $PWD.Path) {
    Write-Host "-> Preserving the existing matched Matrix/MAS/LiveKit credentials."
  } else {
    if ($script:MatrixExisting -eq 'existing') {
      Write-Error ("Existing Matrix configuration needs repair. Its encryption keys and room data " +
        "were preserved. Restore the matched configuration from this installation before re-running deploy.") -ErrorAction Continue
      exit 1
    }
    $matrixStage = Join-Path $PWD.Path (".matrix-deploy." + [System.Guid]::NewGuid().ToString('N').Substring(0, 6))
    try {
      New-Item -ItemType Directory -Path (Join-Path $matrixStage 'docker/matrix/mas') -Force | Out-Null
      New-Item -ItemType Directory -Path (Join-Path $matrixStage 'docker/matrix/appservice') -Force | Out-Null
      New-Item -ItemType Directory -Path (Join-Path $matrixStage 'docker/matrix/conf.d') -Force | Out-Null
      New-Item -ItemType Directory -Path (Join-Path $matrixStage 'docker/livekit') -Force | Out-Null
      Copy-Item .env (Join-Path $matrixStage '.env')
      Copy-Item docker/matrix/appservice/registration.yaml (Join-Path $matrixStage 'docker/matrix/appservice/registration.yaml')
      Copy-Item docker/matrix/conf.d/20-mas.yaml (Join-Path $matrixStage 'docker/matrix/conf.d/20-mas.yaml')
      Copy-Item docker/livekit/livekit.yaml (Join-Path $matrixStage 'docker/livekit/livekit.yaml')
      $matrixContainerStage = "/var/www/html/" + (Split-Path -Leaf $matrixStage)
      Write-Host "-> Preparing the public Matrix/MAS/LiveKit configuration..."
      Invoke-Artisan matrix:setup --server-name=$PublicHost --issuer=$PublicUrl `
        --mas-issuer="https://auth.$PublicHost/" `
        --env-path="$matrixContainerStage/.env" `
        --mas-config-path="$matrixContainerStage/docker/matrix/mas/config.yaml" `
        --registration-path="$matrixContainerStage/docker/matrix/appservice/registration.yaml" `
        --mas-synapse-conf-path="$matrixContainerStage/docker/matrix/conf.d/20-mas.yaml" `
        --livekit-config-path="$matrixContainerStage/docker/livekit/livekit.yaml" `
        *> (Join-Path $matrixStage 'generate.log')
      if ($LASTEXITCODE -ne 0) {
        Write-Error "Public Matrix configuration generation failed. Matrix and MAS were not started." -ErrorAction Continue
        exit 1
      }
      if (-not (Test-PublicMatrixBundle $matrixStage)) {
        Write-Error "Public Matrix configuration did not pass validation. Matrix and MAS were not started." -ErrorAction Continue
        exit 1
      }
      Copy-Item (Join-Path $matrixStage '.env') .env -Force
      Copy-Item (Join-Path $matrixStage 'docker/matrix/mas/config.yaml') docker/matrix/mas/config.yaml -Force
      Copy-Item (Join-Path $matrixStage 'docker/matrix/appservice/registration.yaml') docker/matrix/appservice/registration.yaml -Force
      Copy-Item (Join-Path $matrixStage 'docker/matrix/conf.d/20-mas.yaml') docker/matrix/conf.d/20-mas.yaml -Force
      Copy-Item (Join-Path $matrixStage 'docker/livekit/livekit.yaml') docker/livekit/livekit.yaml -Force
    } finally {
      # Clean the staging dir on every exit path (success, refusal, or error). PowerShell runs
      # finally before the process exits, so the deploy never leaves a .matrix-deploy.* behind.
      if ($matrixStage -and (Test-Path $matrixStage)) {
        Remove-Item -Recurse -Force $matrixStage -ErrorAction SilentlyContinue
      }
    }
  }
  # Re-bake the config cache AFTER the last .env writer (key:generate, matrix:setup). A cached
  # config overrides .env; without this the app keeps the placeholder cga_dev_* tokens while
  # .env holds the minted ones and every Matrix call 401s (WoS 2026-09-08).
  Write-Host "-> Re-baking the config cache with the final .env..."
  Invoke-Artisan config:cache
  Write-Host "-> Starting Synapse + MAS with the validated public configuration..."
  docker @dc up -d --force-recreate matrix mas
}

Write-Host "-> Migrating..."
Invoke-Artisan migrate --force

# A fresh instance needs the constitutional clock registry (CLK-01..21) seeded —
# the scheduler + federation:init's CLK-20 arming depend on it. (DatabaseSeeder
# does NOT include it; it is its own seeder.)
Write-Host "-> Seeding the constitutional clock registry..."
Invoke-Artisan db:seed --class=ClockRegistrySeeder --force

# 3. Federation identity. Every deployed node is federation-capable, so this runs
#    UNCONDITIONALLY (parity with deploy.sh) — a -SelfUrl peer (discover->handshake) needs
#    it too, or federation_enabled stays false and mesh:gates reports "not ready to
#    federate". federation:init is idempotent (ensureIdentity is a no-op once minted).
#    NEVER -rotate on an ordinary run OR on -Join: rotating mints a brand-new server_id that
#    every peer must re-handshake, so a plain rerun of an admitted node would lose its identity.
#    -rotate is reached ONLY through the explicit -CloneRekey flag: a box CLONED from another
#    carries a keypair encrypted under the SOURCE's key and MUST re-key so two peers never
#    share an identity. That is an operator's deliberate one-time act, never the packaged join.
Write-Host "-> Minting the federation identity..."
if ($CloneRekey) {
  Write-Warning "-CloneRekey: rotating the federation identity (new server_id + keypair)."
  Write-Warning "Every peer must RE-HANDSHAKE with this box; its old identity is discarded."
  Invoke-Artisan federation:init --rotate
} else {
  Invoke-Artisan federation:init
}

# Deploy-side readiness assertion (parity with deploy.sh): mesh:gates exits non-zero on a hard FAIL
# (federation off / identity not minted). PowerShell does not abort on a native non-zero exit, so
# check $LASTEXITCODE explicitly and throw — don't ship a node that 404s every peer.
Write-Host "-> Verifying federation readiness..."
Invoke-Artisan mesh:gates
if ($LASTEXITCODE -ne 0) { throw "Federation readiness gates FAILED — the node is not ready to federate (see the [FAIL] gates above)." }

# 4. Optional standing demo data.
if ($Seed) { Write-Host "-> Seeding demo data..."; Invoke-Artisan institutions:demo-e }

# 5. Optional join validated here; DISPATCHED in step 8, AFTER the workers reload and nginx is up
#    (parity with deploy.sh). A -Join needs a -Key, so fail fast now rather than after the build.
if ($Join -and -not $Key) { throw "-Join requires -Key handle.secret" }

# 6. Production front-end assets — build ONCE (no Vite at runtime) so the UI renders from
#    any machine on the network (localhost-pinned HMR assets break when opened elsewhere).
#    A one-shot run of the vite image writes public/build; removing public/hot makes Laravel
#    resolve assets from that manifest. (Parity with deploy.sh.)
Write-Host "-> Building production front-end assets (one-shot)..."
docker @dc run --rm --build --no-deps --entrypoint sh vite -c "npm install --no-audit --no-fund && npm run build"
Remove-Item -Path (Join-Path $PSScriptRoot 'public/hot') -ErrorAction SilentlyContinue

# 7. Reload the long-lived workers with the FINAL APP_KEY. app/horizon/scheduler booted
#    BEFORE key:generate rewrote APP_KEY, so they still hold the OLD key; federation:init
#    --rotate then wrote the signing keypair under the NEW key, so every web/worker
#    Crypt::decryptString() of it throws 'MAC is invalid' (500 on the UI + POST
#    /api/federation/sync) until they reload. (Parity with deploy.sh.)
Write-Host "-> Reloading workers with the final APP_KEY..."
docker @dc restart app horizon scheduler

# nginx LAST — the app is healthy and public/build exists, so it serves built assets with
# no startup 502 and nothing to wait on.
Write-Host "-> Starting nginx..."
docker @dc up -d nginx

# 8. Optional: join a host as a read-only mirror — DISPATCHED here, LAST, on purpose (parity with
#    deploy.sh). The seed + audit drain is a multi-GB, ~951k-row transfer; it now runs in the
#    long-running Horizon queue (ClusterJoinJob, timeout=0) so the UI is ALREADY SERVING (nginx up,
#    above) while the transfer proceeds asynchronously, and the worker holds the FINAL APP_KEY
#    (reloaded in step 7) — so Crypt::decryptString of the signing key never 500s the job.
#    DETECT-AND-BRANCH (parity with deploy.sh and SetupController::joinFromSetup): a FIRST run
#    adopts with the key then dispatches; a RERUN of an already-keyed box RESUMES the existing
#    membership and consumes NO second join-key use, falling through to a keyed adopt only when
#    there is no membership to resume. A rerun NEVER re-keys identity — a departed/rejected
#    membership or an exhausted key fails LOUD with the instruction to mint a fresh key on the host.
if ($Join) {
  function Fail-JoinLoud {
    # One Write-Error so $ErrorActionPreference='Stop' cannot terminate before the mint
    # instruction prints. The terminating error alone yields a non-zero exit; exit 1 is the
    # belt-and-braces path when the preference is not Stop.
    Write-Error ("Cannot (re)join $Join - no active membership to resume and the join key was rejected or is exhausted. " +
      "This box's federation identity was NOT changed. Mint a fresh single-use key ON THE HOST and re-run -Join with it: " +
      "docker compose exec app php artisan cluster:keys:mint --max-uses=1") -ErrorAction Continue
    exit 1
  }

  # Capture native exit codes reliably regardless of the ambient
  # $PSNativeCommandUseErrorActionPreference (mirrors deploy.sh's `set +e` around the resume
  # call). With that preference True AND $ErrorActionPreference='Stop', a non-zero native exit
  # THROWS a NativeCommandExitException before $LASTEXITCODE is read — which would break the
  # rc=3 fall-through and the fail-loud branch below. Pin it off for the detect-and-branch,
  # restore it after. (Default is False, so this is a robustness guard, not a behavior change.)
  $priorNativePref = $PSNativeCommandUseErrorActionPreference
  $PSNativeCommandUseErrorActionPreference = $false
  try {
    if ($script:HadExistingAppKey -and -not $CloneRekey) {
      # federation:resume-join exit codes: 0 = resumed (dispatched to the long-running queue);
      # 3 = NO membership at all (this box was never a mirror) - adopt with the key; 4 = a
      # DEPARTED/REJECTED membership exists - NEVER silently re-adopt, fail loud. Both commands
      # DISPATCH by default (async) so the UI keeps serving while the worker drains. A clone
      # (-CloneRekey) is a fresh node with a new identity, so it adopts, never resumes.
      Write-Host "-> Rerun of an already-keyed box - resuming the existing mirror membership..."
      Invoke-Artisan federation:resume-join
      $resumeRc = $LASTEXITCODE
      if ($resumeRc -eq 0) {
        Write-Host "-> Resume dispatched to the long-running queue (no new join-key use)."
      } elseif ($resumeRc -eq 3) {
        Write-Host "-> No membership to resume - adopting $Join with the join key..."
        Invoke-Artisan cluster:join $Join --key $Key
        if ($LASTEXITCODE -ne 0) { Fail-JoinLoud }
      } else {
        # rc 4 = a DEPARTED/REJECTED membership (this box left the cluster or was rejected);
        # any other rc = a resume failure. Never silently re-adopt and never rotate identity:
        # fail loud with the mint-a-fresh-key-on-the-host instruction.
        Fail-JoinLoud
      }
    } else {
      Write-Host "-> Joining $Join as a read-only mirror..."
      Invoke-Artisan cluster:join $Join --key $Key
      if ($LASTEXITCODE -ne 0) { Fail-JoinLoud }
    }
  } finally {
    $PSNativeCommandUseErrorActionPreference = $priorNativePref
  }
  Write-Host "   The seed + drain runs in Horizon; the UI is already serving. Watch progress at GET /federation/cluster/sync-progress."
}

Write-Host "OK Instance up (production assets) — http://localhost:$NginxPort"
