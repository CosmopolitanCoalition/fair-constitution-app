#!/usr/bin/env pwsh
#
# test_join_rerun.ps1 - deploy.ps1 join-rerun identity harness (M3, parity with test_join_rerun.sh).
#
# Runs the REAL deploy.ps1 in an isolated temp dir with a native `docker` stub (docker.bat ->
# docker_stub.ps1) on PATH: it logs each `php artisan` invocation to $env:ART_LOG and exits with
# a controlled code. Because deploy.ps1 is the -File target, its own `exit N` sets the child
# process exit code, which the parent reads. .env / .env.example are synthetic. No Docker, no
# PostgreSQL, no live world is touched. Cases mirror the bash harness:
#   (a) first run (example key)         -> key:generate + federation:init (no --rotate) + cluster:join
#   (b) rerun, membership (resume 0)    -> NO key:generate/--rotate/cluster:join, resume-join ran
#   (c) rerun, no membership (resume 3) -> resume-join THEN cluster:join once
#   (d) -CloneRekey                     -> federation:init --rotate
#   (e) exhausted key (join fails)      -> non-zero exit + mint instruction
#   (f) departed membership (resume 4)  -> fail loud, NO cluster:join, NO rotate
#   (g) hostile native pref (resume 3)  -> pin holds: fall-through adopts, no pre-branch throw
#
# Run from the worktree:  pwsh -NoProfile -File tests/deploy/test_join_rerun.ps1
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
  if ((Get-Content -Raw $file) -like "*$needle*") { Pass $label } else { Fail "$label (missing: $needle)" }
}
function AssertAbsent($label, $file, $needle) {
  if ((Get-Content -Raw $file) -like "*$needle*") { Fail "$label (present but should be absent: $needle)" } else { Pass $label }
}
function AssertCount($label, $file, $needle, $n) {
  $c = @(Get-Content $file | Where-Object { $_ -like "*$needle*" }).Count
  if ($c -eq $n) { Pass $label } else { Fail "$label (want $n got $c of: $needle)" }
}

$stubPs1 = @'
$a = @($args)
$log = $env:ART_LOG
$idx = [Array]::IndexOf($a, 'artisan')
if ($idx -ge 0) {
  $cmd = ($a[($idx + 1)..($a.Count - 1)]) -join ' '
  Add-Content -LiteralPath $log -Value $cmd
  $sub = [string]$a[$idx + 1]
  if ($sub -eq 'federation:resume-join') { exit ([int]$env:STUB_RESUME_RC) }
  if ($sub -eq 'cluster:join')           { exit ([int]$env:STUB_CLUSTER_JOIN_RC) }
  exit 0
}
if (($a -join ' ') -match 'pg_database') { Write-Output '1' }
exit 0
'@

$stubBat = "@echo off`r`npwsh -NoProfile -File `"%~dp0docker_stub.ps1`" %*`r`nexit /b %ERRORLEVEL%`r`n"

function New-Workspace($currentKey) {
  $ws = Join-Path ([System.IO.Path]::GetTempPath()) ("m3ps_" + [System.Guid]::NewGuid().ToString('N'))
  New-Item -ItemType Directory -Path $ws | Out-Null
  New-Item -ItemType Directory -Path (Join-Path $ws 'bin') | Out-Null
  Copy-Item $deploy (Join-Path $ws 'deploy.ps1')
  Set-Content -LiteralPath (Join-Path $ws '.env.example') -Value "APP_KEY=$exampleKey"
  Set-Content -LiteralPath (Join-Path $ws '.env')         -Value "APP_KEY=$currentKey"
  Set-Content -LiteralPath (Join-Path $ws 'bin/docker_stub.ps1') -Value $stubPs1
  Set-Content -LiteralPath (Join-Path $ws 'bin/docker.bat')      -Value $stubBat -NoNewline
  return $ws
}

function Invoke-Case($ws, [hashtable]$stub, [string[]]$extra) {
  $env:ART_LOG              = (Join-Path $ws 'art.log')
  $env:STUB_RESUME_RC       = [string]$stub.Resume
  $env:STUB_CLUSTER_JOIN_RC = [string]$stub.Join
  $oldPath = $env:Path
  $env:Path = (Join-Path $ws 'bin') + [IO.Path]::PathSeparator + $oldPath
  try {
    $a = @('-Join', 'http://host.invalid:8081', '-Key', 'handle.secret',
           '-SelfUrl', 'http://box.invalid:8080', '-Project', 'testproj') + $extra
    & pwsh -NoProfile -File (Join-Path $ws 'deploy.ps1') @a *> (Join-Path $ws 'out.log')
    return $LASTEXITCODE
  } finally {
    $env:Path = $oldPath
  }
}

Write-Host "== (a) first run: virgin example key =="
$ws = New-Workspace $exampleKey
$rc = Invoke-Case $ws @{ Resume = 0; Join = 0 } @()
$log = Join-Path $ws 'art.log'
if ($rc -eq 0) { Pass "exit 0" } else { Fail "exit 0 (got $rc)" }
AssertContains "key:generate ran"       $log "key:generate --force"
AssertContains "federation:init ran"    $log "federation:init"
AssertAbsent   "no --rotate"            $log "federation:init --rotate"
AssertContains "cluster:join adopt"     $log "cluster:join http://host.invalid:8081 --key handle.secret"
AssertAbsent   "no resume-join"         $log "federation:resume-join"
Remove-Item -Recurse -Force $ws

Write-Host "== (b) rerun, membership present (resume exits 0) =="
$ws = New-Workspace $realKey
$rc = Invoke-Case $ws @{ Resume = 0; Join = 0 } @()
$log = Join-Path $ws 'art.log'
if ($rc -eq 0) { Pass "exit 0" } else { Fail "exit 0 (got $rc)" }
AssertAbsent   "no key:generate"        $log "key:generate"
AssertAbsent   "no --rotate"            $log "federation:init --rotate"
AssertContains "resume-join --sync ran" $log "federation:resume-join --sync"
AssertAbsent   "no cluster:join"        $log "cluster:join"
Remove-Item -Recurse -Force $ws

Write-Host "== (c) rerun, no membership (resume exits 3) =="
$ws = New-Workspace $realKey
$rc = Invoke-Case $ws @{ Resume = 3; Join = 0 } @()
$log = Join-Path $ws 'art.log'
if ($rc -eq 0) { Pass "exit 0" } else { Fail "exit 0 (got $rc)" }
AssertAbsent   "no key:generate"          $log "key:generate"
AssertAbsent   "no --rotate"              $log "federation:init --rotate"
AssertContains "resume-join --sync tried" $log "federation:resume-join --sync"
AssertCount    "cluster:join once"        $log "cluster:join" 1
Remove-Item -Recurse -Force $ws

Write-Host "== (d) -CloneRekey: explicit rotate =="
$ws = New-Workspace $realKey
$rc = Invoke-Case $ws @{ Resume = 0; Join = 0 } @('-CloneRekey')
$log = Join-Path $ws 'art.log'
if ($rc -eq 0) { Pass "exit 0" } else { Fail "exit 0 (got $rc)" }
AssertContains "federation:init --rotate" $log "federation:init --rotate"
AssertAbsent   "clone does not resume"    $log "federation:resume-join"
AssertContains "clone adopts with key"    $log "cluster:join http://host.invalid:8081 --key handle.secret"
Remove-Item -Recurse -Force $ws

Write-Host "== (e) exhausted key: resume 3 then cluster:join fails =="
$ws = New-Workspace $realKey
$rc = Invoke-Case $ws @{ Resume = 3; Join = 1 } @()
$log = Join-Path $ws 'art.log'
$out = Join-Path $ws 'out.log'
if ($rc -ne 0) { Pass "non-zero exit" } else { Fail "non-zero exit (got $rc)" }
AssertContains "mint instruction printed" $out "cluster:keys:mint"
AssertAbsent   "identity not rotated"     $log "federation:init --rotate"
Remove-Item -Recurse -Force $ws

Write-Host "== (f) rerun, departed/rejected membership (resume exits 4): fail loud, no re-adopt =="
$ws = New-Workspace $realKey
$rc = Invoke-Case $ws @{ Resume = 4; Join = 0 } @()
$log = Join-Path $ws 'art.log'
$out = Join-Path $ws 'out.log'
if ($rc -ne 0) { Pass "non-zero exit" } else { Fail "non-zero exit (got $rc)" }
AssertContains "resume-join --sync tried" $log "federation:resume-join --sync"
AssertAbsent   "no silent re-adopt"       $log "cluster:join"
AssertAbsent   "identity not rotated"     $log "federation:init --rotate"
AssertContains "mint instruction printed" $out "cluster:keys:mint"
Remove-Item -Recurse -Force $ws

Write-Host "== (g) hostile native pref (resume 3 under Stop + pref True): pin holds, fall-through adopts =="
$ws = New-Workspace $realKey
$env:ART_LOG              = (Join-Path $ws 'art.log')
$env:STUB_RESUME_RC       = '3'
$env:STUB_CLUSTER_JOIN_RC = '0'
$oldPath = $env:Path
$env:Path = (Join-Path $ws 'bin') + [IO.Path]::PathSeparator + $oldPath
try {
  $deployInWs = (Join-Path $ws 'deploy.ps1')
  # Set the fragile preference True in the CHILD session BEFORE deploy.ps1 runs. Without
  # deploy.ps1's own pin, the rc=3 native exit would THROW under $ErrorActionPreference='Stop'
  # and abort before the fall-through branch. This proves the pin holds (fall-through adopts,
  # exit 0). -Command (not -File) lets the child set the preference before deploy.ps1 executes.
  $cmd = "`$PSNativeCommandUseErrorActionPreference = `$true; `$ErrorActionPreference = 'Stop'; " +
         "& '$deployInWs' -Join 'http://host.invalid:8081' -Key 'handle.secret' " +
         "-SelfUrl 'http://box.invalid:8080' -Project 'testproj'; exit `$LASTEXITCODE"
  & pwsh -NoProfile -Command $cmd *> (Join-Path $ws 'out.log')
  $rc = $LASTEXITCODE
} finally {
  $env:Path = $oldPath
}
$log = Join-Path $ws 'art.log'
if ($rc -eq 0) { Pass "exit 0 (pin prevented the throw; fall-through ran)" } else { Fail "exit 0 (got $rc)" }
AssertContains "resume-join --sync tried" $log "federation:resume-join --sync"
AssertCount    "cluster:join once"        $log "cluster:join" 1
Remove-Item -Recurse -Force $ws

Write-Host ""
if ($script:fails -eq 0) {
  Write-Host "ALL DEPLOY JOIN-RERUN CASES PASSED (pwsh)"
  exit 0
} else {
  Write-Host "$($script:fails) ASSERTION(S) FAILED (pwsh)"
  exit 1
}
