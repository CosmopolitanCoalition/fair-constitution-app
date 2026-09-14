#!/usr/bin/env pwsh
#
# test_bootstrap_project.ps1 - deploy.ps1 + bootstrap.ps1 compose-project + failure harness
# (M4, parity with test_bootstrap_project.sh).
#
# Runs the REAL deploy.ps1 and the REAL bootstrap.ps1 in isolated temp dirs with a native
# `docker` stub (docker.bat -> docker_stub.ps1) on PATH that logs each `-p <project>` + artisan
# sub-command to $env:DC_LOG and exits with an env-controlled code. No Docker, no PostgreSQL,
# no live world is touched. Because each script is the -File target, its own `exit`/`throw`
# sets the child process exit code, which the parent reads.
#
# PART A - deploy.ps1 project resolution (the .env-pin fix):
#   (a1) rerun, .env pin custom_x, no -Project -> every docker call -p custom_x (not fc)
#   (a2) -Project wins                          -> every docker call -p custom_p
#   (a3) no pin, no -Project                     -> falls back to -Prefix (fc)
# PART B - bootstrap.ps1 consuming the pinned project + failure hardening (fake deploy.ps1
#          pins custom_x):
#   (b1) all steps ok         -> post-deploy artisan calls -p custom_x; completion line printed
#   (b2) federation:init fails -> non-zero exit, NO completion line
#   (b3) transport:register fails -> non-zero exit, NO completion line
#   (b4) directory:publish real error (exit 1) -> non-zero exit, NO completion line
#   (b5) mesh:gates fails      -> non-zero exit, NO completion line
#   (b1 also proves directory:publish exit 0 (no-authority) continues to mesh:gates)
#
# Run from the worktree:  pwsh -NoProfile -File tests/deploy/test_bootstrap_project.ps1
#
$ErrorActionPreference = 'Stop'

$here      = Split-Path -Parent $MyInvocation.MyCommand.Path
$repo      = Split-Path -Parent (Split-Path -Parent $here)
$deployPs1 = Join-Path $repo 'deploy.ps1'
$bootPs1   = Join-Path $repo 'bootstrap/bootstrap.ps1'
if (-not (Test-Path $deployPs1)) { Write-Error "deploy.ps1 not found at $deployPs1"; exit 2 }
if (-not (Test-Path $bootPs1))   { Write-Error "bootstrap.ps1 not found at $bootPs1"; exit 2 }

$exampleKey  = 'base64:EXAMPLEEXAMPLEEXAMPLEEXAMPLEEXAMPLEEXAMPLE0='
$realKey     = 'base64:RealBoxKeyRealBoxKeyRealBoxKeyRealBoxKeyABC='
$successLine = 'Survival-mesh setup complete'

$script:fails = 0
function Pass($m) { Write-Host "  PASS: $m" }
function Fail($m) { Write-Host "  FAIL: $m"; $script:fails++ }
function AssertContains($label, $file, $needle) {
  if ((Get-Content -Raw $file) -like "*$needle*") { Pass $label } else { Fail "$label (missing: $needle)" }
}
function AssertAbsent($label, $file, $needle) {
  if ((Get-Content -Raw $file) -like "*$needle*") { Fail "$label (present but should be absent: $needle)" } else { Pass $label }
}
function AssertAllProject($label, $log, $proj) {
  $lines = @(Get-Content $log | Where-Object { $_ -like 'PROJECT=*' })
  $wrong = @($lines | Where-Object { $_ -notlike "PROJECT=$proj *" })
  if ($lines.Count -ge 1 -and $wrong.Count -eq 0) {
    Pass "$label ($($lines.Count) calls, all -p $proj)"
  } else {
    Fail "$label ($($wrong.Count) of $($lines.Count) not -p $proj)"
    $lines | ForEach-Object { Write-Host "      $_" }
  }
}

# Native docker stub: log `-p <project>` + the artisan sub-command; exit per STUB_* env.
$stubPs1 = @'
$a = @($args)
$log = $env:DC_LOG
$proj = ''
$pi = [Array]::IndexOf($a, '-p')
if ($pi -ge 0 -and ($pi + 1) -lt $a.Count) { $proj = [string]$a[$pi + 1] }
$idx = [Array]::IndexOf($a, 'artisan')
if ($idx -ge 0) {
  $cmd = ($a[($idx + 1)..($a.Count - 1)]) -join ' '
  Add-Content -LiteralPath $log -Value "PROJECT=$proj ART=$cmd"
  $sub = [string]$a[$idx + 1]
  function RC($v) { if ($v) { [int]$v } else { 0 } }
  if ($sub -eq 'federation:init')    { exit (RC $env:STUB_FEDINIT_RC) }
  if ($sub -eq 'transport:register') { exit (RC $env:STUB_TRANSPORT_RC) }
  if ($sub -eq 'directory:publish')  { exit (RC $env:STUB_DIRPUB_RC) }
  if ($sub -eq 'mesh:gates')         { exit (RC $env:STUB_GATES_RC) }
  exit 0
}
if (($a -join ' ') -match 'pg_database') { Write-Output '1' }
exit 0
'@
$stubBat = "@echo off`r`npwsh -NoProfile -File `"%~dp0docker_stub.ps1`" %*`r`nexit /b %ERRORLEVEL%`r`n"

# A FAKE deploy.ps1 for the bootstrap.ps1 tests: honour -Project else pin custom_x, nothing else.
$fakeDeploy = @'
[CmdletBinding()]
param(
  [string]$Prefix = "fc", [int]$NginxPort = 8080, [int]$PgPort = 5432, [int]$VitePort = 5173,
  [string]$SelfUrl = "", [string]$Project = "", [switch]$Seed, [switch]$WithEtl,
  [string]$Join = "", [string]$Key = "", [switch]$CloneRekey,
  [Parameter(ValueFromRemainingArguments = $true)] [string[]]$Rest = @()
)
$ErrorActionPreference = "Stop"
Set-Location -Path $PSScriptRoot
$proj = if ($Project) { $Project } else { "custom_x" }
$envPath = Join-Path $PSScriptRoot ".env"
$lines = @()
if (Test-Path $envPath) { $lines = @(Get-Content $envPath) }
$found = $false
$out = foreach ($line in $lines) {
  if ($line -like "COMPOSE_PROJECT_NAME=*") { $found = $true; "COMPOSE_PROJECT_NAME=$proj" } else { $line }
}
if (-not $found) { $out = @($out) + "COMPOSE_PROJECT_NAME=$proj" }
Set-Content -Path $envPath -Value $out
Write-Host "[fake deploy.ps1] compose project = $proj"
exit 0
'@

$catalog = @'
{ "transports": { "https": { "label": "HTTPS", "needs_host_daemon": false,
    "self_advert": "https://node.example:8081", "configure": "n/a", "env": {} } },
  "recommend": { "public-anchor-node": ["https"] } }
'@

# Air-gapped-style catalog: the sole recommended transport advertises NOTHING (self_advert null).
# The register loop skips it, so zero transports are registered.
$catalogNoAdvert = @'
{ "transports": { "sneakernet": { "label": "Offline bundle", "needs_host_daemon": false,
    "self_advert": null, "configure": "advertise nothing", "env": null } },
  "recommend": { "public-anchor-node": ["sneakernet"] } }
'@

function New-Ws() {
  $ws = Join-Path ([System.IO.Path]::GetTempPath()) ("m4ps_" + [System.Guid]::NewGuid().ToString('N'))
  New-Item -ItemType Directory -Path $ws | Out-Null
  New-Item -ItemType Directory -Path (Join-Path $ws 'bin') | Out-Null
  Set-Content -LiteralPath (Join-Path $ws 'bin/docker_stub.ps1') -Value $stubPs1
  Set-Content -LiteralPath (Join-Path $ws 'bin/docker.bat')      -Value $stubBat -NoNewline
  return $ws
}

function Set-Stub([hashtable]$s) {
  $env:STUB_FEDINIT_RC   = [string]($s.Fed      ?? 0)
  $env:STUB_TRANSPORT_RC = [string]($s.Transport ?? 0)
  $env:STUB_DIRPUB_RC    = [string]($s.Dir      ?? 0)
  $env:STUB_GATES_RC     = [string]($s.Gates    ?? 0)
}

function Invoke-Script($ws, $scriptPath, [hashtable]$stub, [string[]]$scriptArgs) {
  $env:DC_LOG = Join-Path $ws 'dc.log'
  Set-Stub $stub
  $oldPath = $env:Path
  $env:Path = (Join-Path $ws 'bin') + [IO.Path]::PathSeparator + $oldPath
  try {
    & pwsh -NoProfile -File $scriptPath @scriptArgs *> (Join-Path $ws 'out.log')
    return $LASTEXITCODE
  } finally {
    $env:Path = $oldPath
  }
}

# ---------------------------------------------------------------- PART A: deploy.ps1
Write-Host "== (a1) deploy.ps1 rerun, .env pin custom_x, no -Project -> -p custom_x (not fc) =="
$ws = New-Ws
Copy-Item $deployPs1 (Join-Path $ws 'deploy.ps1')
Set-Content -LiteralPath (Join-Path $ws '.env.example') -Value "APP_KEY=$exampleKey"
Set-Content -LiteralPath (Join-Path $ws '.env')         -Value @("APP_KEY=$realKey", "COMPOSE_PROJECT_NAME=custom_x")
$rc = Invoke-Script $ws (Join-Path $ws 'deploy.ps1') @{} @('-SelfUrl','http://box.invalid:8080')
if ($rc -eq 0) { Pass "exit 0" } else { Fail "exit 0 (got $rc)" }
AssertAllProject "all deploy calls -p custom_x" (Join-Path $ws 'dc.log') 'custom_x'
AssertAbsent     "never -p fc"                  (Join-Path $ws 'dc.log') 'PROJECT=fc '
Remove-Item -Recurse -Force $ws

Write-Host "== (a2) deploy.ps1 -Project wins -> -p custom_p =="
$ws = New-Ws
Copy-Item $deployPs1 (Join-Path $ws 'deploy.ps1')
Set-Content -LiteralPath (Join-Path $ws '.env.example') -Value "APP_KEY=$exampleKey"
Set-Content -LiteralPath (Join-Path $ws '.env')         -Value @("APP_KEY=$realKey", "COMPOSE_PROJECT_NAME=custom_x")
$rc = Invoke-Script $ws (Join-Path $ws 'deploy.ps1') @{} @('-SelfUrl','http://box.invalid:8080','-Project','custom_p')
if ($rc -eq 0) { Pass "exit 0" } else { Fail "exit 0 (got $rc)" }
AssertAllProject "all deploy calls -p custom_p" (Join-Path $ws 'dc.log') 'custom_p'
Remove-Item -Recurse -Force $ws

Write-Host "== (a3) deploy.ps1 no pin, no -Project -> falls back to -Prefix (fc) =="
$ws = New-Ws
Copy-Item $deployPs1 (Join-Path $ws 'deploy.ps1')
Set-Content -LiteralPath (Join-Path $ws '.env.example') -Value "APP_KEY=$exampleKey"
Set-Content -LiteralPath (Join-Path $ws '.env')         -Value "APP_KEY=$realKey"
$rc = Invoke-Script $ws (Join-Path $ws 'deploy.ps1') @{} @('-SelfUrl','http://box.invalid:8080')
if ($rc -eq 0) { Pass "exit 0" } else { Fail "exit 0 (got $rc)" }
AssertAllProject "all deploy calls -p fc" (Join-Path $ws 'dc.log') 'fc'
Remove-Item -Recurse -Force $ws

# ---------------------------------------------------------------- PART B: bootstrap.ps1
function New-BootWs() {
  $ws = New-Ws
  New-Item -ItemType Directory -Path (Join-Path $ws 'bootstrap') | Out-Null
  Copy-Item $bootPs1 (Join-Path $ws 'bootstrap/bootstrap.ps1')
  Set-Content -LiteralPath (Join-Path $ws 'bootstrap/mesh-catalog.json') -Value $catalog
  Set-Content -LiteralPath (Join-Path $ws 'deploy.ps1') -Value $fakeDeploy
  Set-Content -LiteralPath (Join-Path $ws '.env.example') -Value "APP_ENV=testing"
  return $ws
}
$bootArgs = @('-NonInteractive','-Profile','public-anchor-node')

Write-Host "== (b1) bootstrap.ps1 all ok -> post-deploy -p custom_x; completion line =="
$ws = New-BootWs
$rc = Invoke-Script $ws (Join-Path $ws 'bootstrap/bootstrap.ps1') @{} $bootArgs
if ($rc -eq 0) { Pass "exit 0" } else { Fail "exit 0 (got $rc)" }
AssertAllProject "all post-deploy calls -p custom_x" (Join-Path $ws 'dc.log') 'custom_x'
AssertAbsent     "never -p fc"                       (Join-Path $ws 'dc.log') 'PROJECT=fc '
AssertContains   "federation:init ran"               (Join-Path $ws 'dc.log') 'ART=federation:init'
AssertContains   "transport:register ran"            (Join-Path $ws 'dc.log') 'ART=transport:register'
AssertContains   "directory:publish ran"             (Join-Path $ws 'dc.log') 'ART=directory:publish'
AssertContains   "mesh:gates ran after publish"      (Join-Path $ws 'dc.log') 'ART=mesh:gates'
AssertContains   "completion line printed"           (Join-Path $ws 'out.log') $successLine
Remove-Item -Recurse -Force $ws

Write-Host "== (b2) federation:init fails -> non-zero, NO completion line =="
$ws = New-BootWs
$rc = Invoke-Script $ws (Join-Path $ws 'bootstrap/bootstrap.ps1') @{ Fed = 1 } $bootArgs
if ($rc -ne 0) { Pass "non-zero exit" } else { Fail "non-zero exit (got $rc)" }
AssertContains "federation:init failure message" (Join-Path $ws 'out.log') 'federation:init failed'
AssertAbsent   "no completion line"               (Join-Path $ws 'out.log') $successLine
Remove-Item -Recurse -Force $ws

Write-Host "== (b3) transport:register fails -> non-zero, NO completion line =="
$ws = New-BootWs
$rc = Invoke-Script $ws (Join-Path $ws 'bootstrap/bootstrap.ps1') @{ Transport = 1 } $bootArgs
if ($rc -ne 0) { Pass "non-zero exit" } else { Fail "non-zero exit (got $rc)" }
AssertContains "transport:register failure message" (Join-Path $ws 'out.log') 'transport:register https failed'
AssertAbsent   "no completion line"                  (Join-Path $ws 'out.log') $successLine
Remove-Item -Recurse -Force $ws

Write-Host "== (b4) directory:publish real error (exit 1) -> non-zero, NO completion line =="
$ws = New-BootWs
$rc = Invoke-Script $ws (Join-Path $ws 'bootstrap/bootstrap.ps1') @{ Dir = 1 } $bootArgs
if ($rc -ne 0) { Pass "non-zero exit" } else { Fail "non-zero exit (got $rc)" }
AssertContains "directory:publish failure message" (Join-Path $ws 'out.log') 'directory:publish failed'
AssertAbsent   "no completion line"                 (Join-Path $ws 'out.log') $successLine
Remove-Item -Recurse -Force $ws

Write-Host "== (b5) mesh:gates fails -> non-zero, NO completion line =="
$ws = New-BootWs
$rc = Invoke-Script $ws (Join-Path $ws 'bootstrap/bootstrap.ps1') @{ Gates = 1 } $bootArgs
if ($rc -ne 0) { Pass "non-zero exit" } else { Fail "non-zero exit (got $rc)" }
AssertContains "mesh:gates failure message" (Join-Path $ws 'out.log') 'mesh:gates reported the node is not ready'
AssertAbsent   "no completion line"          (Join-Path $ws 'out.log') $successLine
Remove-Item -Recurse -Force $ws

Write-Host "== (b6) no transport registered (air-gapped) -> directory:publish SKIPPED, still completes =="
$ws = New-BootWs
Set-Content -LiteralPath (Join-Path $ws 'bootstrap/mesh-catalog.json') -Value $catalogNoAdvert
# Dir = 1: even if directory:publish WERE called it would fail — proving it is skipped.
$rc = Invoke-Script $ws (Join-Path $ws 'bootstrap/bootstrap.ps1') @{ Dir = 1 } $bootArgs
if ($rc -eq 0) { Pass "exit 0" } else { Fail "exit 0 (got $rc)" }
AssertAbsent   "transport:register skipped (no advert)" (Join-Path $ws 'dc.log') 'ART=transport:register'
AssertAbsent   "directory:publish NOT run"              (Join-Path $ws 'dc.log') 'ART=directory:publish'
AssertContains "skip line printed"                      (Join-Path $ws 'out.log') 'skipping directory:publish'
AssertContains "mesh:gates still ran"                   (Join-Path $ws 'dc.log') 'ART=mesh:gates'
AssertContains "completion line printed"                (Join-Path $ws 'out.log') $successLine
Remove-Item -Recurse -Force $ws

Write-Host ""
if ($script:fails -eq 0) {
  Write-Host "ALL BOOTSTRAP/DEPLOY PROJECT + FAILURE CASES PASSED (pwsh)"
  exit 0
} else {
  Write-Host "$($script:fails) ASSERTION(S) FAILED"
  exit 1
}
