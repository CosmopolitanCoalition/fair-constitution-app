#!/usr/bin/env pwsh
#
# test_installer_contracts.ps1 - deploy.ps1 installer failure/retry contract harness
# (Windows parity with tests/deploy/test_installer_contracts.sh, including the public-Matrix
# config-generation-failure and missing-output gate cases now mirrored in deploy.ps1).
#
# Runs the REAL deploy.ps1 in an isolated temp dir with a native `docker` stub
# (docker.bat -> docker_stub.ps1) on PATH. The stub logs every `php artisan` invocation to
# $env:ART_LOG, every raw docker invocation to $env:DOCKER_LOG, an ordering trace to
# $env:ORDER_LOG, and returns controlled exit codes (PostgreSQL readiness via a per-workspace
# counter file; resume/join rc via env). Because deploy.ps1 is the -File target, its own
# `exit N` / terminating error sets the child exit code the parent reads. .env / .env.example
# are synthetic. No Docker, no PostgreSQL, no live world is touched.
#
# Cases (every one a Windows failure/retry contract the register row names for deploy.ps1):
#   (1)  PG not-ready x3 then ready       -> TCP probe (-h 127.0.0.1), retried, migrate AFTER ready
#   (2)  -PublicUrl config-gen FAILURE    -> matrix:setup non-zero -> exit 1, "generation failed",
#                                            Matrix/MAS not started, APP_KEY preserved, staging cleaned
#   (3)  -PublicUrl MISSING OUTPUT        -> matrix:setup ok but bundle validation fails -> exit 1,
#                                            "did not pass validation", not started, APP_KEY preserved
#   (3v) -PublicUrl VALID existing bundle -> preserve, no matrix:setup, Matrix+MAS force-recreated
#                                            with the validated (never the committed dev) secrets
#   (4)  custom project (-Project X)      -> compose -p X, .env pins COMPOSE_PROJECT_NAME=X
#   (5)  existing project (.env pin)      -> reuse the pinned project, not the -Prefix default
#   (6a) own APP_KEY                      -> preserve byte-identical, no key:generate, no --rotate
#   (6b) example APP_KEY                  -> key:generate --force, federation:init, no --rotate
#   (7a) rerun --join, resume rc0         -> resume only, no cluster:join, no --rotate
#   (7b) rerun --join, resume rc3         -> resume tried, cluster:join once, no --rotate
#   (7c) rerun --join, resume rc4         -> fail loud non-zero, no re-adopt, no --rotate, mint recovery
#   (7d) rerun --join, resume rc3 + join rc1 (exhausted key) -> fail loud, mint recovery, no --rotate
#
# deploy.ps1 now mirrors the deploy.sh public-Matrix gate: on -PublicUrl it runs matrix:setup,
# validates the generated bundle with the same isolated check_public_matrix.py, refuses to start
# Matrix + MAS on a failed or missing bundle, and never falls back to the committed development
# Matrix secrets. Cases 2 / 3 / 3v exercise that gate (stubbed artisan + docker, no real Docker).
#
# Run from the worktree:  pwsh -NoProfile -File tests/deploy/test_installer_contracts.ps1
#
$ErrorActionPreference = 'Stop'

$here   = Split-Path -Parent $MyInvocation.MyCommand.Path
$repo   = Split-Path -Parent (Split-Path -Parent $here)
$deploy = Join-Path $repo 'deploy.ps1'
if (-not (Test-Path $deploy)) { Write-Error "deploy.ps1 not found at $deploy"; exit 2 }

$exampleKey = 'base64:EXAMPLEEXAMPLEEXAMPLEEXAMPLEEXAMPLEEXAMPLE0='
$realKey    = 'base64:RealBoxKeyRealBoxKeyRealBoxKeyRealBoxKeyABC='

$script:fails = 0
function Pass($m) { Write-Host "  PASS: $m" }
function Fail($m) { Write-Host "  FAIL: $m"; $script:fails++ }
function AssertContains($label, $file, $needle) {
  if ((Test-Path $file) -and ((Get-Content -Raw $file) -like "*$needle*")) { Pass $label } else { Fail "$label (missing: $needle)" }
}
function AssertAbsent($label, $file, $needle) {
  if ((Test-Path $file) -and ((Get-Content -Raw $file) -like "*$needle*")) { Fail "$label (present but should be absent: $needle)" } else { Pass $label }
}
function AssertCount($label, $file, $needle, $n) {
  $c = 0
  if (Test-Path $file) { $c = @(Get-Content $file | Where-Object { $_ -like "*$needle*" }).Count }
  if ($c -eq $n) { Pass $label } else { Fail "$label (want $n got $c of: $needle)" }
}
# AssertCountAtLeast: the probe loop logs one line per attempt; only a floor is contractual.
function AssertCountAtLeast($label, $file, $needle, $n) {
  $c = 0
  if (Test-Path $file) { $c = @(Get-Content $file | Where-Object { $_ -like "*$needle*" }).Count }
  if ($c -ge $n) { Pass $label } else { Fail "$label (want >=$n got $c of: $needle)" }
}
# AssertBefore: earlier marker first appears on a LINE above later (earlier ran first).
function AssertBefore($label, $file, $earlier, $later) {
  $lines = @(); if (Test-Path $file) { $lines = @(Get-Content $file) }
  $le = -1; $ll = -1
  for ($i = 0; $i -lt $lines.Count; $i++) {
    if ($le -lt 0 -and $lines[$i] -like "*$earlier*") { $le = $i }
    if ($ll -lt 0 -and $lines[$i] -like "*$later*")   { $ll = $i }
  }
  if ($le -lt 0) { Fail "$label (earlier marker never logged: $earlier)"; return }
  if ($ll -lt 0) { Fail "$label (later marker never logged: $later)"; return }
  if ($le -lt $ll) { Pass $label } else { Fail "$label ($earlier at $le not before $later at $ll)" }
}

$stubPs1 = @'
$a = @($args)
$art   = $env:ART_LOG
$order = $env:ORDER_LOG
$dock  = $env:DOCKER_LOG
$full = ($a -join ' ')
if ($dock)  { Add-Content -LiteralPath $dock -Value $full }
# nginx start marker (docker compose ... up -d nginx) so ordering can be proven.
if ($order -and ($full -match ' up .*nginx')) { Add-Content -LiteralPath $order -Value 'NGINX_UP' }
# Isolated python checks (identity name probe / bundle validation) run via `docker run`.
# The bundle check returns STUB_BUNDLE_RC (default 1 = invalid, forces the regen path, parity
# with the .sh stub). The name probe reports a fresh box.
if ($full -match 'deploy-check.py bundle') {
  if ($order) { Add-Content -LiteralPath $order -Value 'BUNDLE_CHECK' }
  $brc = 1; if ($env:STUB_BUNDLE_RC) { $brc = [int]$env:STUB_BUNDLE_RC }
  exit $brc
}
if ($full -match 'deploy-check.py name') { Write-Output 'fresh'; exit 0 }
# PostgreSQL readiness probe: fail STUB_PG_FAILS times (per-workspace counter), then ready.
if ($full -match 'pg_isready') {
  $cf = $env:PG_COUNT_FILE
  $n = 0
  if ($cf -and (Test-Path $cf)) { $n = [int](Get-Content -Raw $cf) }
  $n++
  if ($cf) { Set-Content -LiteralPath $cf -Value $n }
  $need = 0; if ($env:STUB_PG_FAILS) { $need = [int]$env:STUB_PG_FAILS }
  if ($n -le $need) {
    if ($order) { Add-Content -LiteralPath $order -Value 'PG_PROBE_FAIL' }
    exit 1
  }
  if ($order) { Add-Content -LiteralPath $order -Value 'PG_PROBE_OK' }
  exit 0
}
# Matrix logical-DB existence guard: report present so no CREATE DATABASE is issued.
if ($full -match 'pg_database') { Write-Output '1'; exit 0 }
# artisan invocations: log + controlled rc for the join detect-and-branch.
$idx = [Array]::IndexOf($a, 'artisan')
if ($idx -ge 0) {
  $cmd = ($a[($idx + 1)..($a.Count - 1)]) -join ' '
  if ($art)   { Add-Content -LiteralPath $art -Value $cmd }
  if ($order) { Add-Content -LiteralPath $order -Value "ARTISAN $cmd" }
  $sub = [string]$a[$idx + 1]
  if ($sub -eq 'federation:resume-join') { exit ([int]$env:STUB_RESUME_RC) }
  if ($sub -eq 'cluster:join')           { exit ([int]$env:STUB_CLUSTER_JOIN_RC) }
  if ($sub -eq 'matrix:setup')           { exit ([int]$env:STUB_MATRIX_SETUP_RC) }
  exit 0
}
exit 0
'@

$stubBat = "@echo off`r`npwsh -NoProfile -File `"%~dp0docker_stub.ps1`" %*`r`nexit /b %ERRORLEVEL%`r`n"

# New-Workspace: synthetic .env / .env.example, docker stub on ./bin. $EnvExtra adds .env lines
# (e.g. a COMPOSE_PROJECT_NAME pin) BEFORE deploy.ps1 reads them. -Public also lays down the
# source files the public-Matrix staging block copies (registration/mas-synapse/livekit configs
# and the deploy-check script) so the gate has real files to stage, plus the mas output dir.
function New-Workspace($currentKey, [string[]]$EnvExtra, [switch]$Public) {
  $ws = Join-Path ([System.IO.Path]::GetTempPath()) ("instps_" + [System.Guid]::NewGuid().ToString('N'))
  New-Item -ItemType Directory -Path $ws | Out-Null
  New-Item -ItemType Directory -Path (Join-Path $ws 'bin') | Out-Null
  Copy-Item $deploy (Join-Path $ws 'deploy.ps1')
  Set-Content -LiteralPath (Join-Path $ws '.env.example') -Value "APP_KEY=$exampleKey"
  $envLines = @("APP_KEY=$currentKey")
  if ($EnvExtra) { $envLines += $EnvExtra }
  Set-Content -LiteralPath (Join-Path $ws '.env') -Value $envLines
  Set-Content -LiteralPath (Join-Path $ws 'bin/docker_stub.ps1') -Value $stubPs1
  Set-Content -LiteralPath (Join-Path $ws 'bin/docker.bat')      -Value $stubBat -NoNewline
  if ($Public) {
    New-Item -ItemType Directory -Path (Join-Path $ws 'docker/matrix/mas') -Force | Out-Null
    New-Item -ItemType Directory -Path (Join-Path $ws 'docker/matrix/appservice') -Force | Out-Null
    New-Item -ItemType Directory -Path (Join-Path $ws 'docker/matrix/conf.d') -Force | Out-Null
    New-Item -ItemType Directory -Path (Join-Path $ws 'docker/livekit') -Force | Out-Null
    New-Item -ItemType Directory -Path (Join-Path $ws 'scripts/deploy') -Force | Out-Null
    Set-Content -LiteralPath (Join-Path $ws 'docker/matrix/appservice/registration.yaml') -Value 'stub'
    Set-Content -LiteralPath (Join-Path $ws 'docker/matrix/conf.d/20-mas.yaml') -Value 'stub'
    Set-Content -LiteralPath (Join-Path $ws 'docker/livekit/livekit.yaml') -Value 'use_external_ip: true'
    Set-Content -LiteralPath (Join-Path $ws 'scripts/deploy/check_public_matrix.py') -Value 'stub'
  }
  return $ws
}

# Invoke-Case: run deploy.ps1 in $ws with the stub on PATH. $stub carries per-case env
# (Pg failures, resume/join rc). $deployArgs are the deploy.ps1 parameters for the case.
function Invoke-Case($ws, [hashtable]$stub, [string[]]$deployArgs) {
  $env:ART_LOG              = (Join-Path $ws 'art.log')
  $env:ORDER_LOG            = (Join-Path $ws 'order.log')
  $env:DOCKER_LOG           = (Join-Path $ws 'docker.log')
  $env:PG_COUNT_FILE        = (Join-Path $ws 'pg.count')
  $env:STUB_PG_FAILS        = [string]([int]$stub.PgFails)
  $env:STUB_RESUME_RC       = [string]([int]$stub.Resume)
  $env:STUB_CLUSTER_JOIN_RC = [string]([int]$stub.Join)
  $env:STUB_MATRIX_SETUP_RC = [string]([int]$stub.MatrixSetup)
  $env:STUB_BUNDLE_RC       = [string]([int]$stub.Bundle)
  $oldPath = $env:Path
  $env:Path = (Join-Path $ws 'bin') + [IO.Path]::PathSeparator + $oldPath
  try {
    & pwsh -NoProfile -File (Join-Path $ws 'deploy.ps1') @deployArgs *> (Join-Path $ws 'out.log')
    return $LASTEXITCODE
  } finally {
    $env:Path = $oldPath
    Remove-Item Env:\STUB_PG_FAILS, Env:\STUB_RESUME_RC, Env:\STUB_CLUSTER_JOIN_RC, Env:\PG_COUNT_FILE, Env:\STUB_MATRIX_SETUP_RC, Env:\STUB_BUNDLE_RC -ErrorAction SilentlyContinue
  }
}

# --------------------------------------------------------------------------------------------
Write-Host "== (1) PostgreSQL not-ready x3 then ready: TCP probe, retried, migrate after ready =="
$ws = New-Workspace $realKey @()
$rc = Invoke-Case $ws @{ PgFails = 3; Resume = 0; Join = 0 } @('-SelfUrl','http://box.invalid:8080')
$ord = Join-Path $ws 'order.log'; $doc = Join-Path $ws 'docker.log'; $art = Join-Path $ws 'art.log'
if ($rc -eq 0) { Pass "exit 0" } else { Fail "exit 0 (got $rc)" }
AssertContains     "TCP probe -h 127.0.0.1"     $doc "pg_isready -h 127.0.0.1"
AssertCountAtLeast "3 not-ready probes retried"  $ord "PG_PROBE_FAIL" 3
AssertContains     "reached ready"               $ord "PG_PROBE_OK"
AssertBefore       "ready before migrate"        $ord "PG_PROBE_OK" "ARTISAN migrate"
# redis_queue is the queue's single home and is NOT behind a profile. Pin it into the stack up
# so it can never be dropped again (WoS 2026-09-08: down redis_queue -> app 500 getaddrinfo).
AssertContains     "stack up includes redis_queue" $doc "up -d --build app postgres redis redis_queue horizon scheduler"
Remove-Item -Recurse -Force $ws

Write-Host "== (2) -PublicUrl config generation FAILURE (matrix:setup non-zero) =="
$ws = New-Workspace $realKey @() -Public
$beforeKey = (Get-Content (Join-Path $ws '.env') | Where-Object { $_ -like 'APP_KEY=*' })
$rc = Invoke-Case $ws @{ PgFails = 0; Resume = 0; Join = 0; MatrixSetup = 1; Bundle = 1 } `
        @('-PublicUrl','https://earth.example.org','-MediaIp','203.0.113.10','-Project','t2')
$out = Join-Path $ws 'out.log'; $art = Join-Path $ws 'art.log'
if ($rc -eq 1) { Pass "non-zero exit (1)" } else { Fail "non-zero exit (got $rc)" }
AssertContains "generation-failed message"  $out "Public Matrix configuration generation failed"
AssertContains "not started"                 $out "Matrix and MAS were not started"
AssertContains "matrix:setup was attempted"  $art "matrix:setup"
AssertAbsent   "no success banner"           $out "Instance up"
AssertAbsent   "identity not rotated"        $art "federation:init --rotate"
AssertAbsent   "no key regeneration"         $art "key:generate"
$afterKey = (Get-Content (Join-Path $ws '.env') | Where-Object { $_ -like 'APP_KEY=*' })
if ($afterKey -eq $beforeKey) { Pass "APP_KEY preserved" } else { Fail "APP_KEY preserved (got '$afterKey')" }
$stale = @(Get-ChildItem -Path $ws -Directory -Filter '.matrix-deploy.*' -Force -ErrorAction SilentlyContinue).Count
if ($stale -eq 0) { Pass "staging dir cleaned" } else { Fail "staging dir cleaned (found $stale)" }
Remove-Item -Recurse -Force $ws

Write-Host "== (3) -PublicUrl MISSING OUTPUT (matrix:setup ok, bundle validation fails) =="
$ws = New-Workspace $realKey @() -Public
$beforeKey = (Get-Content (Join-Path $ws '.env') | Where-Object { $_ -like 'APP_KEY=*' })
$rc = Invoke-Case $ws @{ PgFails = 0; Resume = 0; Join = 0; MatrixSetup = 0; Bundle = 1 } `
        @('-PublicUrl','https://earth.example.org','-MediaIp','203.0.113.10','-Project','t3')
$out = Join-Path $ws 'out.log'; $art = Join-Path $ws 'art.log'
if ($rc -eq 1) { Pass "non-zero exit (1)" } else { Fail "non-zero exit (got $rc)" }
AssertContains "validation-failed message"   $out "did not pass validation"
AssertContains "not started"                 $out "Matrix and MAS were not started"
AssertContains "matrix:setup ran (gen ok)"   $art "matrix:setup"
AssertAbsent   "no success banner"           $out "Instance up"
AssertAbsent   "identity not rotated"        $art "federation:init --rotate"
$afterKey = (Get-Content (Join-Path $ws '.env') | Where-Object { $_ -like 'APP_KEY=*' })
if ($afterKey -eq $beforeKey) { Pass "APP_KEY preserved" } else { Fail "APP_KEY preserved (got '$afterKey')" }
$stale = @(Get-ChildItem -Path $ws -Directory -Filter '.matrix-deploy.*' -Force -ErrorAction SilentlyContinue).Count
if ($stale -eq 0) { Pass "staging dir cleaned" } else { Fail "staging dir cleaned (found $stale)" }
Remove-Item -Recurse -Force $ws

Write-Host "== (3v) -PublicUrl VALID existing bundle -> preserve, no matrix:setup, Matrix+MAS started =="
$ws = New-Workspace $realKey @() -Public
$rc = Invoke-Case $ws @{ PgFails = 0; Resume = 0; Join = 0; MatrixSetup = 0; Bundle = 0 } `
        @('-PublicUrl','https://earth.example.org','-MediaIp','203.0.113.10','-Project','t3v')
$out = Join-Path $ws 'out.log'; $art = Join-Path $ws 'art.log'; $doc = Join-Path $ws 'docker.log'
if ($rc -eq 0) { Pass "exit 0 (valid bundle path completes)" } else { Fail "exit 0 (got $rc)" }
AssertContains "preserve message"            $out "Preserving the existing matched"
AssertAbsent   "no matrix:setup on preserve" $art "matrix:setup"
AssertContains "Matrix+MAS force-recreated"  $doc "up -d --force-recreate matrix mas"
AssertContains "started with validated cfg"  $out "validated public configuration"
Remove-Item -Recurse -Force $ws

Write-Host "== (4) custom project (-Project cga_custom) =="
$ws = New-Workspace $realKey @()
$rc = Invoke-Case $ws @{ PgFails = 0; Resume = 0; Join = 0 } @('-Project','cga_custom','-SelfUrl','http://box.invalid:8080')
$doc = Join-Path $ws 'docker.log'
if ($rc -eq 0) { Pass "exit 0" } else { Fail "exit 0 (got $rc)" }
AssertContains "compose -p cga_custom used"      $doc "compose -p cga_custom"
AssertContains ".env pins COMPOSE_PROJECT_NAME"  (Join-Path $ws '.env') "COMPOSE_PROJECT_NAME=cga_custom"
Remove-Item -Recurse -Force $ws

Write-Host "== (5) existing project (.env pin, no -Project): reuse, not the -Prefix default =="
$ws = New-Workspace $realKey @('COMPOSE_PROJECT_NAME=worldbox')
$rc = Invoke-Case $ws @{ PgFails = 0; Resume = 0; Join = 0 } @('-SelfUrl','http://box.invalid:8080')
$doc = Join-Path $ws 'docker.log'
if ($rc -eq 0) { Pass "exit 0" } else { Fail "exit 0 (got $rc)" }
AssertContains "reused pinned project worldbox"  $doc "compose -p worldbox"
AssertAbsent   "did not fall back to -Prefix fc" $doc "compose -p fc "
Remove-Item -Recurse -Force $ws

Write-Host "== (6a) own APP_KEY -> preserve, no generate, no rotate =="
$ws = New-Workspace $realKey @()
$rc = Invoke-Case $ws @{ PgFails = 0; Resume = 0; Join = 0 } @('-SelfUrl','http://box.invalid:8080')
$art = Join-Path $ws 'art.log'; $out = Join-Path $ws 'out.log'
if ($rc -eq 0) { Pass "exit 0" } else { Fail "exit 0 (got $rc)" }
AssertContains "preserving message"      $out "Preserving the existing APP_KEY"
AssertAbsent   "no key:generate"         $art "key:generate"
AssertAbsent   "no --rotate"             $art "federation:init --rotate"
AssertContains "plain federation:init"   $art "federation:init"
$keyLine = (Get-Content (Join-Path $ws '.env') | Where-Object { $_ -like 'APP_KEY=*' })
if ($keyLine -eq "APP_KEY=$realKey") { Pass "APP_KEY byte-identical" } else { Fail "APP_KEY byte-identical (got '$keyLine')" }
Remove-Item -Recurse -Force $ws

Write-Host "== (6b) example APP_KEY -> generate fresh, init not rotate =="
$ws = New-Workspace $exampleKey @()
$rc = Invoke-Case $ws @{ PgFails = 0; Resume = 0; Join = 0 } @('-SelfUrl','http://box.invalid:8080')
$art = Join-Path $ws 'art.log'; $out = Join-Path $ws 'out.log'
if ($rc -eq 0) { Pass "exit 0" } else { Fail "exit 0 (got $rc)" }
AssertContains "generating message"      $out "Generating a fresh APP_KEY"
AssertContains "key:generate --force"    $art "key:generate --force"
AssertContains "federation:init ran"     $art "federation:init"
AssertAbsent   "no --rotate on fresh"    $art "federation:init --rotate"
Remove-Item -Recurse -Force $ws

Write-Host "== (7a) rerun --join, resume rc0 -> resume only, no adopt, no rotate =="
$ws = New-Workspace $realKey @()
$rc = Invoke-Case $ws @{ PgFails = 0; Resume = 0; Join = 0 } @('-Join','http://host.invalid:8081','-Key','handle.secret','-SelfUrl','http://box.invalid:8080')
$art = Join-Path $ws 'art.log'
if ($rc -eq 0) { Pass "exit 0" } else { Fail "exit 0 (got $rc)" }
AssertContains "resume-join ran"         $art "federation:resume-join"
AssertAbsent   "no cluster:join"         $art "cluster:join"
AssertAbsent   "no --rotate"             $art "federation:init --rotate"
Remove-Item -Recurse -Force $ws

Write-Host "== (7b) rerun --join, resume rc3 -> adopt once, no rotate =="
$ws = New-Workspace $realKey @()
$rc = Invoke-Case $ws @{ PgFails = 0; Resume = 3; Join = 0 } @('-Join','http://host.invalid:8081','-Key','handle.secret','-SelfUrl','http://box.invalid:8080')
$art = Join-Path $ws 'art.log'
if ($rc -eq 0) { Pass "exit 0" } else { Fail "exit 0 (got $rc)" }
AssertContains "resume-join tried"       $art "federation:resume-join"
AssertCount    "cluster:join once"       $art "cluster:join" 1
AssertAbsent   "no --rotate"             $art "federation:init --rotate"
Remove-Item -Recurse -Force $ws

Write-Host "== (7c) rerun --join, resume rc4 -> fail loud, no re-adopt, no rotate =="
$ws = New-Workspace $realKey @()
$rc = Invoke-Case $ws @{ PgFails = 0; Resume = 4; Join = 0 } @('-Join','http://host.invalid:8081','-Key','handle.secret','-SelfUrl','http://box.invalid:8080')
$art = Join-Path $ws 'art.log'; $out = Join-Path $ws 'out.log'
if ($rc -ne 0) { Pass "non-zero exit" } else { Fail "non-zero exit (got $rc)" }
AssertContains "resume-join tried"       $art "federation:resume-join"
AssertAbsent   "no silent re-adopt"      $art "cluster:join"
AssertAbsent   "identity not rotated"    $art "federation:init --rotate"
AssertContains "mint recovery printed"   $out "cluster:keys:mint --max-uses=1"
Remove-Item -Recurse -Force $ws

Write-Host "== (7d) rerun --join, resume rc3 then cluster:join rc1 (exhausted key) -> fail loud, mint recovery =="
$ws = New-Workspace $realKey @()
$rc = Invoke-Case $ws @{ PgFails = 0; Resume = 3; Join = 1 } @('-Join','http://host.invalid:8081','-Key','handle.secret','-SelfUrl','http://box.invalid:8080')
$art = Join-Path $ws 'art.log'; $out = Join-Path $ws 'out.log'
if ($rc -ne 0) { Pass "non-zero exit" } else { Fail "non-zero exit (got $rc)" }
AssertContains "resume-join tried"       $art "federation:resume-join"
AssertCount    "adopt attempted once"    $art "cluster:join" 1
AssertAbsent   "identity not rotated"    $art "federation:init --rotate"
AssertContains "mint recovery printed"   $out "cluster:keys:mint --max-uses=1"
Remove-Item -Recurse -Force $ws

# (8) -PublicUrl and the dev view-as key (operator order 2026-09-18; parity with the bash
# harness). An ABSENT CGA_IMPERSONATION reads as ON in config/cga.php and launch:assert-clean
# refuses a public launch, so the public deploy writes false when the key is absent and
# leaves a set key alone. The runs stop at the stubbed matrix:setup failure; .env is written first.
Write-Host "== (8a) -PublicUrl, CGA_IMPERSONATION absent -> written false =="
$ws = New-Workspace $realKey @() -Public
$rc = Invoke-Case $ws @{ PgFails = 0; Resume = 0; Join = 0; MatrixSetup = 1; Bundle = 1 } `
        @('-PublicUrl','https://earth.example.org','-MediaIp','203.0.113.10','-Project','t8a')
AssertCount "key written false once" (Join-Path $ws '.env') "CGA_IMPERSONATION=false" 1
Remove-Item -Recurse -Force $ws

Write-Host "== (8b) -PublicUrl, CGA_IMPERSONATION=false already set -> left alone =="
$ws = New-Workspace $realKey @('CGA_IMPERSONATION=false') -Public
$rc = Invoke-Case $ws @{ PgFails = 0; Resume = 0; Join = 0; MatrixSetup = 1; Bundle = 1 } `
        @('-PublicUrl','https://earth.example.org','-MediaIp','203.0.113.10','-Project','t8b')
AssertCount "one key line, not two" (Join-Path $ws '.env') "CGA_IMPERSONATION=" 1
Remove-Item -Recurse -Force $ws

Write-Host ""
if ($script:fails -eq 0) {
  Write-Host "ALL INSTALLER CONTRACT CASES PASSED (pwsh)"
  exit 0
} else {
  Write-Host "$($script:fails) ASSERTION(S) FAILED (pwsh)"
  exit 1
}
