#Requires -Version 7
<#
.SYNOPSIS
  Phase G (G8b / C7) — universal survival-mesh setup (Windows / pwsh).

.DESCRIPTION
  The Windows mirror of bootstrap.sh: a thin front-end over the SHARED spec
  (bootstrap/mesh-catalog.json) and the existing deploy.ps1. Same wording, same flow —
  walk an interactive pick of which transports this node offers, guide their host-daemon
  setup (winget), write the transport .env, hand off to deploy.ps1 for the app layer, then
  register the chosen transports + publish the directory. Transport FACTS live only in the
  catalog, never here.

  HOST-DAEMON INSTALL (tor / yggdrasil / tailscale) MODIFIES THE HOST OS — that step is
  certified on the physical rig, not on Docker-Desktop. This script GUIDES it and only on
  explicit confirmation runs the catalog's winget command; it never installs silently.

.EXAMPLE
  ./bootstrap/bootstrap.ps1
.EXAMPLE
  ./bootstrap/bootstrap.ps1 -Profile public-anchor-node -Prefix fc -NginxPort 8080
.EXAMPLE
  ./bootstrap/bootstrap.ps1 -NonInteractive -Profile volunteer-home -SelfUrl https://node.example
#>
[CmdletBinding()]
param(
  [string]$Profile = "",
  [switch]$NonInteractive,
  [string]$Prefix = "fc",
  [int]$NginxPort = 8080,
  [string]$Project = "",
  [Parameter(ValueFromRemainingArguments = $true)] [string[]]$PassThru = @()
)

$ErrorActionPreference = "Stop"
$Here = $PSScriptRoot
$Root = (Resolve-Path (Join-Path $Here "..")).Path
$CatalogPath = Join-Path $Here "mesh-catalog.json"
if (-not (Test-Path $CatalogPath)) { throw "catalog not found at $CatalogPath" }
$Catalog = Get-Content $CatalogPath -Raw | ConvertFrom-Json

function Ask([string]$Prompt, [string]$Default) {
  if ($NonInteractive) { return $Default }
  $ans = Read-Host $Prompt
  if ([string]::IsNullOrWhiteSpace($ans)) { return $Default } else { return $ans }
}

Write-Host "-- Cosmopolitan Governance App - Survival-Mesh Setup --"
Write-Host "Reading transports from $(Split-Path $CatalogPath -Leaf)."

# 1. Posture -> a recommended profile.
if (-not $Profile) {
  Write-Host "What is this node?  [a] volunteer mirror  [b] my jurisdiction's server  [c] public anchor"
  $node = Ask "  choice [a]:" "a"
  Write-Host "Where is it?        [a] open internet  [b] untrusted/public network  [c] air-gapped"
  $net = Ask "  choice [a]:" "a"
  $Profile = switch -Regex ("$node-$net") {
    '-b$'  { "secure-default"; break }
    '-c$'  { "air-gapped"; break }
    '^c-'  { "public-anchor-node"; break }
    default { "volunteer-home" }
  }
}
Write-Host "-> Profile: $Profile"

$transportNames = $Catalog.transports.PSObject.Properties.Name
$defaultOn = @($Catalog.recommend.$Profile)

$chosen = @()
$advert = @{}
foreach ($t in $transportNames) {
  $spec = $Catalog.transports.$t
  $def = if ($defaultOn -contains $t) { "y" } else { "n" }
  $inc = Ask "Offer $($spec.label) [$t]? (y/n) [$def]:" $def
  if ($inc -notmatch '^[Yy]') { continue }
  $chosen += $t

  if ($spec.needs_host_daemon) {
    $installCmd = $spec.install.windows
    Write-Host "  -> $t needs a host daemon (RIG-CERTIFIED step)."
    Write-Host "     install : $(if ($installCmd) { $installCmd } else { '<none for this OS>' })"
    Write-Host "     configure: $($spec.configure)"
    if ($installCmd -and -not $NonInteractive) {
      $run = Ask "     run the install command now? (y/n) [n]:" "n"
      if ($run -match '^[Yy]') {
        if ($installCmd -match '\bwinget\b' -and -not (Get-Command winget -ErrorAction SilentlyContinue)) {
          Write-Host "     winget not found — install $t manually per the catalog, then re-run."
        } else {
          Write-Host "     running..."; Invoke-Expression $installCmd
        }
      }
    }
  }

  if ($spec.self_advert) {
    $advert[$t] = Ask "  reachable address for $t [$($spec.self_advert)]:" $spec.self_advert
  }
}

if ($chosen.Count -eq 0) { Write-Host "No transports chosen - nothing to set up."; exit 0 }

# 2. Write transport .env (e.g. the Tor SOCKS proxy) BEFORE the stack comes up.
if (-not (Test-Path (Join-Path $Root ".env"))) { Copy-Item (Join-Path $Root ".env.example") (Join-Path $Root ".env") }
function Set-EnvVar([string]$Key, [string]$Value) {
  # Wildcard match + literal rewrite (NOT regex) so a key/value with regex metachars can
  # never mis-match or mangle the replacement.
  $envPath = Join-Path $Root ".env"
  $lines = @(Get-Content $envPath)
  $found = $false
  $out = foreach ($line in $lines) {
    if ($line -like "$Key=*") { $found = $true; "$Key=$Value" } else { $line }
  }
  if (-not $found) { $out = @($out) + "$Key=$Value" }
  Set-Content -Path $envPath -Value $out
}
foreach ($t in $chosen) {
  $env = $Catalog.transports.$t.env
  if ($env) { foreach ($p in $env.PSObject.Properties) { Set-EnvVar $p.Name $p.Value } }
}

# 3. Hand off to deploy.ps1 for the app layer. The handshake callback URL must be an
#    address a REMOTE peer can reach — prefer an overlay self-advert over the LAN https one.
#    Skip if the operator already passed -SelfUrl.
$selfUrl = $null
foreach ($t in @('yggdrasil', 'tailnet', 'onion', 'https')) {
  if ($advert.ContainsKey($t)) { $selfUrl = $advert[$t]; break }
}
$selfArgs = @()
if ($selfUrl -and -not ($PassThru -contains '-SelfUrl')) { $selfArgs = @('-SelfUrl', $selfUrl) }
Write-Host "-> Handing off to deploy.ps1 for the app layer..."
# Forward every argument by NAME. PowerShell ARRAY splatting binds POSITIONALLY — it ignores the
# '-Name' tokens — so a mixed `& deploy.ps1 -Prefix x @selfArgs` sent -SelfUrl/-Project to the
# wrong positional slots (deploy.ps1 position 3 is [int]$PgPort, so -SelfUrl failed to convert).
# Invoking through `pwsh -File` routes the tokens through the argument parser, which honours
# -Name (the same mechanism tests/deploy/test_join_rerun.ps1 relies on). Thread an explicit
# -Project when the operator gave one; otherwise deploy.ps1 resolves it (the .env pin, else
# $Prefix), pins it to .env, and we read that back below. Either way both scripts agree.
$deployArgs = @('-Prefix', $Prefix, '-NginxPort', "$NginxPort")
if ($Project) { $deployArgs += @('-Project', $Project) }
$deployArgs += $PassThru
$deployArgs += $selfArgs
& pwsh -NoProfile -File (Join-Path $Root "deploy.ps1") @deployArgs
if ($LASTEXITCODE -ne 0) { throw "deploy.ps1 failed (exit $LASTEXITCODE) - the app layer did not come up." }

# 4. Post-up: enable federation, register transports, publish the directory.
# deploy.ps1 (above) is the SINGLE OWNER of compose-project resolution and pinned the resolved
# name into .env (COMPOSE_PROJECT_NAME). READ THAT — $Prefix is the CONTAINER-NAME prefix, not
# the compose project, and a $Prefix-keyed DC addressed a different, often EMPTY stack from the
# one deploy.ps1 just brought up. Parse literally (strip surrounding quotes / whitespace); fall
# back to -Project then $Prefix only when the pin is absent (it never is once deploy.ps1 ran).
$project = ""
$envPath = Join-Path $Root ".env"
if (Test-Path $envPath) {
  foreach ($line in @(Get-Content $envPath)) {
    if ($line -like "COMPOSE_PROJECT_NAME=*") {
      $project = ($line.Substring("COMPOSE_PROJECT_NAME=".Length)).Trim().Trim('"')
      break
    }
  }
}
if (-not $project) { $project = if ($Project) { $Project } else { $Prefix } }
Write-Host "-> compose project = $project   (resolved by deploy.ps1, read from .env)"
$dc = @("compose", "-p", $project)
function Invoke-Artisan { docker @dc exec -T app php artisan @args }

# A native (docker/artisan) non-zero exit does NOT throw by default, so check $LASTEXITCODE
# explicitly after each step and throw. Any post-deploy step failing means the mesh is NOT
# ready: abort LOUD rather than printing the completion line ("failures cannot report success").
function Assert-Artisan([string]$Label) { if ($LASTEXITCODE -ne 0) { throw "Survival-mesh setup FAILED: $Label" } }

# federation:init mints the identity AND opens the mesh endpoints (federation_enabled) —
# without it /api/federation/identity is refused and the anchor is undiscoverable. deploy.ps1
# now runs it unconditionally, so this is idempotent/redundant when deploy.ps1 succeeded; it
# stays as the bootstrap-side backstop. No -rotate (never regenerate identity on a rerun).
Write-Host "-> Enabling federation (mint identity + open the mesh endpoints)..."
Invoke-Artisan federation:init
Assert-Artisan "federation:init failed - the mesh endpoints stay closed"

Write-Host "-> Registering transports..."
$registered = 0
foreach ($t in $chosen) {
  if (-not $advert.ContainsKey($t)) { Write-Host "  (skipping $t - no live address)"; continue }
  Invoke-Artisan transport:register $t $advert[$t]
  Assert-Artisan "transport:register $t failed"
  $registered++
}
# directory:publish is FATAL on a genuine error, but two states are legitimate no-ops and must
# NOT abort setup:
#   1. No transport was registered (e.g. the air-gapped profile: sneakernet advertises nothing,
#      so the register loop skips it). mesh:gates classifies "no transport advertised" as WARN,
#      not FAIL, so the node is still federation-ready - do not abort one step before the
#      readiness gate. directory:publish exits non-zero on this state, so guard it here and skip.
#   2. No jurisdiction is explicitly authoritative - a mirror or fresh anchor. The command
#      itself exits 0 with an informational line there, so it is already non-fatal.
Write-Host "-> Publishing the directory for jurisdictions this node is authoritative for..."
if ($registered -eq 0) {
  Write-Host "  (no live transport registered - nothing to advertise; skipping directory:publish)"
} else {
  Invoke-Artisan directory:publish
  Assert-Artisan "directory:publish failed"
}

# 5. Final readiness assertion — the SAME contract deploy.ps1 enforces at the end of its run
#    (mesh:gates). It exits non-zero on a hard FAIL (federation off / identity not minted), so
#    the completion line below prints ONLY after the node is verified ready to federate.
Write-Host "-> Verifying federation readiness (mesh:gates)..."
Invoke-Artisan mesh:gates
Assert-Artisan "mesh:gates reported the node is not ready to federate"

Write-Host "OK Survival-mesh setup complete. Transports: $($chosen -join ', ')"
Write-Host "  Two-way check: once BOTH boxes are up, run 'php artisan mesh:doctor <other-box-url>' on each."
