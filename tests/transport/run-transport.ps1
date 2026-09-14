# R2 · rooms over real transport — orchestrator.
# Runs the PHP journey (door refusals + real Matrix HTTP + real LiveKit SFU/TTL) and the Node
# LiveKit transport-lifetime harness against the already-running fc_matrix + fc_livekit containers.
# It never restarts or reconfigures those containers; if the voice profile is down it prints the
# exact bring-up command and marks the LiveKit portion BLOCKED.
#
# Usage (from the main repo root E:\fair-constitution-app):
#   pwsh .wt\edu\tests\transport\run-transport.ps1

$ErrorActionPreference = 'Continue'
$wt = '/var/www/html/.wt/edu'

function Running($name) { return (docker ps --format '{{.Names}}' | Select-String -SimpleMatch $name) }

Write-Host '== R2 transport preflight =='
$matrixUp = Running 'fc_matrix'
$livekitUp = Running 'fc_livekit'
Write-Host ("fc_matrix : {0}" -f ($(if ($matrixUp) { 'up' } else { 'DOWN' })))
Write-Host ("fc_livekit: {0}" -f ($(if ($livekitUp) { 'up' } else { 'DOWN' })))

if (-not $livekitUp) {
  Write-Host 'R2 LiveKit portion BLOCKED — bring up the voice profile with:' -ForegroundColor Yellow
  Write-Host '  docker compose -p wos -f docker-compose.yml -f docker-compose.voice-local.yml --profile voice up -d --no-deps livekit'
}
if (-not $matrixUp) {
  Write-Host 'R2 Matrix portion BLOCKED — bring up Matrix with:' -ForegroundColor Yellow
  Write-Host '  docker compose -p wos up -d matrix'
}

Write-Host ''
Write-Host '== PHP journey (RoomTransportTest) =='
docker exec fc_app sh -c "cd $wt && php vendor/bin/phpunit tests/Unit/RoomTransportTest.php"
$php = $LASTEXITCODE

Write-Host ''
Write-Host '== Node LiveKit transport-lifetime harness =='
docker exec fc_vite sh -c "cd $wt && node --test tests/transport/transport-media-workflow.test.mjs"
$node = $LASTEXITCODE

Write-Host ''
Write-Host ("php exit={0}  node exit={1}" -f $php, $node)
Write-Host 'Media-frame publish/subscribe (WebRTC) is NOT established here: node has no browser WebRTC'
Write-Host 'media runtime and browser automation is not authorized. Report it as not established.'
if ($php -ne 0 -or $node -ne 0) { exit 1 } else { exit 0 }
