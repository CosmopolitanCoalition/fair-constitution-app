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
  Write-Host "Where is it?        [a] open internet  [b] censored/monitored  [c] air-gapped"
  $net = Ask "  choice [a]:" "a"
  $Profile = switch -Regex ("$node-$net") {
    '-b$'  { "censored-region"; break }
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
& (Join-Path $Root "deploy.ps1") -Prefix $Prefix -NginxPort $NginxPort @PassThru @selfArgs

# 4. Post-up: enable federation, register transports, publish the directory.
$dc = @("compose", "-p", $Prefix)
function Invoke-Artisan { docker @dc exec -T app php artisan @args }

# federation:init mints the identity AND opens the mesh endpoints (federation_enabled) —
# without it /api/federation/identity is refused and the anchor is undiscoverable.
Write-Host "-> Enabling federation (mint identity + open the mesh endpoints)..."
try { Invoke-Artisan federation:init } catch { Write-Host "  WARN: federation:init failed - the mesh endpoints stay closed" }

Write-Host "-> Registering transports..."
foreach ($t in $chosen) {
  if (-not $advert.ContainsKey($t)) { Write-Host "  (skipping $t - no live address)"; continue }
  try { Invoke-Artisan transport:register $t $advert[$t] } catch { Write-Host "  WARN: transport:register $t failed" }
}
Write-Host "-> Publishing the directory..."
try { Invoke-Artisan directory:publish } catch { Write-Host "  (no explicit-authority jurisdiction yet - run directory:publish <id> later)" }

# 5. Honest reachability report instead of a blind checkmark. Non-fatal — the overlay
#    daemon may not be up yet.
Write-Host "-> Mesh self-check:"
try { Invoke-Artisan mesh:doctor } catch {}

Write-Host "OK Survival-mesh setup complete. Transports: $($chosen -join ', ')"
Write-Host "  Two-way check: once BOTH boxes are up, run 'php artisan mesh:doctor <other-box-url>' on each."
