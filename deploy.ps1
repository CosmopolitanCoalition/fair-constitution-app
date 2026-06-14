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
  $lines = Get-Content .env
  if ($lines -match "^$Key=") {
    $lines = $lines -replace "^$Key=.*", "$Key=$Value"
    Set-Content -Path .env -Value $lines
  } else {
    Add-Content -Path .env -Value "$Key=$Value"
  }
}

Set-EnvVar "CONTAINER_PREFIX"    $Prefix
Set-EnvVar "NGINX_HOST_PORT"     "$NginxPort"
Set-EnvVar "POSTGRES_HOST_PORT"  "$PgPort"
Set-EnvVar "VITE_HOST_PORT"      "$VitePort"
Set-EnvVar "FEDERATION_SELF_URL" $SelfUrl
Set-EnvVar "APP_URL"             "http://localhost:$NginxPort"

Write-Host "-> Bringing up the stack (project=$Project, prefix=$Prefix, nginx :$NginxPort)..."
docker @dc up -d --build

Write-Host "-> Waiting for PostgreSQL..."
for ($i = 0; $i -lt 60; $i++) {
  docker @dc exec -T postgres pg_isready -U fc_user -d fair_constitution *> $null
  if ($LASTEXITCODE -eq 0) { break }
  Start-Sleep -Seconds 2
}

# 2. A FRESH APP_KEY — clone-identity-safe (never reuse the repo's shared dev key).
Write-Host "-> Generating a fresh APP_KEY..."
Invoke-Artisan key:generate --force

Write-Host "-> Migrating..."
Invoke-Artisan migrate --force

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

Write-Host "OK Instance up — http://localhost:$NginxPort"
