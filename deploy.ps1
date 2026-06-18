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
  [string]$Project   = "",
  [switch]$Seed,
  [string]$Join      = "",
  [string]$Key       = ""
)

$ErrorActionPreference = "Stop"
if (-not $SelfUrl) { $SelfUrl = "http://host.docker.internal:$NginxPort" }
if (-not $Project) { $Project = $Prefix }

Set-Location -Path $PSScriptRoot

$dc = @("compose", "-p", $Project)
function Invoke-Artisan { docker @dc exec -T app php artisan @args }

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

Set-EnvVar "CONTAINER_PREFIX"    $Prefix
Set-EnvVar "NGINX_HOST_PORT"     "$NginxPort"
Set-EnvVar "POSTGRES_HOST_PORT"  "$PgPort"
Set-EnvVar "VITE_HOST_PORT"      "$VitePort"
Set-EnvVar "FEDERATION_SELF_URL" $SelfUrl
Set-EnvVar "APP_URL"             "http://localhost:$NginxPort"

# Architecture parity with deploy.sh: the official postgis image is amd64-only; on
# arm64 (Windows-on-ARM / an arm64 Docker host) use the multi-arch rebuild. amd64
# keeps the default. PROCESSOR_ARCHITECTURE is the host CPU under pwsh.
$arch = $env:PROCESSOR_ARCHITECTURE
if ($arch -match 'ARM64') { Set-EnvVar "POSTGIS_IMAGE" "imresamu/postgis:17-3.5" }

# Deployed posture: production + debug off (parity with deploy.sh). deploy.ps1 stands up a
# built-asset instance; a dev box uses `docker compose up` (local + HMR) instead.
Set-EnvVar "APP_ENV"   "production"
Set-EnvVar "APP_DEBUG" "false"

# Explicit service list (parity with deploy.sh): omit `etl` (heavy geospatial Python a
# federation node never uses) and `vite` (dev HMR — a deployed box serves the built assets
# produced at the end). nginx starts LAST so compose never aborts the up waiting on a
# php-fpm still mid composer-install.
Write-Host "-> Bringing up the stack (project=$Project, prefix=$Prefix, nginx :$NginxPort)..."
docker @dc up -d --build app postgres redis horizon scheduler

Write-Host "-> Waiting for PostgreSQL..."
for ($i = 0; $i -lt 60; $i++) {
  docker @dc exec -T postgres pg_isready -U fc_user -d fair_constitution *> $null
  if ($LASTEXITCODE -eq 0) { break }
  Start-Sleep -Seconds 2
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

# 2. A FRESH APP_KEY — clone-identity-safe (never reuse the repo's shared dev key).
Write-Host "-> Generating a fresh APP_KEY..."
Invoke-Artisan key:generate --force

Write-Host "-> Migrating..."
Invoke-Artisan migrate --force

# A fresh instance needs the constitutional clock registry (CLK-01..21) seeded —
# the scheduler + federation:init's CLK-20 arming depend on it. (DatabaseSeeder
# does NOT include it; it is its own seeder.)
Write-Host "-> Seeding the constitutional clock registry..."
Invoke-Artisan db:seed --class=ClockRegistrySeeder --force

# 3. Federation identity when this instance will federate. -rotate forces a fresh
#    keypair: key:generate changed APP_KEY above, so any keypair carried in from a
#    clone is no longer decryptable — re-key it (and the server_id) under the new key.
if ($Join) {
  Write-Host "-> Minting a fresh federation identity..."
  Invoke-Artisan federation:init --rotate
}

# 4. Optional standing demo data.
if ($Seed) { Write-Host "-> Seeding demo data..."; Invoke-Artisan institutions:demo-e }

# 5. Optional: adopt a host as a read-only mirror in one step.
if ($Join) {
  if (-not $Key) { throw "-Join requires -Key handle.secret" }
  Write-Host "-> Joining $Join as a read-only mirror..."
  Invoke-Artisan cluster:join $Join --key $Key
}

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

Write-Host "OK Instance up (production assets) — http://localhost:$NginxPort"
