# Combined private application fixture -> actual browser seating logic.
# No live civic data, Matrix requests, SFU connections, or production build.
param([string] $AppContainer = 'fc_app', [string] $ViteContainer = 'fc_vite')
$ErrorActionPreference = 'Stop'
$repoPath = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../..')).Path
$fixtureId = [Guid]::NewGuid().ToString('N')
$fixtureDirectory = [IO.Path]::GetFullPath((Join-Path $repoPath 'storage/framework/testing'))
$fixturePath = [IO.Path]::GetFullPath((Join-Path $fixtureDirectory ('r2-room-workflow-' + $fixtureId + '.json')))
if (-not $fixturePath.StartsWith($fixtureDirectory + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Fixture path escaped the testing directory.' }
if (Test-Path -LiteralPath $fixturePath) { throw 'Refusing to replace an existing fixture file.' }
try {
    & docker exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e "ROOM_WORKFLOW_SNAPSHOT_ID=$fixtureId" $AppContainer php vendor/bin/phpunit tests/Unit/RoomWorkflowIntegrationTest.php --do-not-cache-result
    if ($LASTEXITCODE -ne 0) { throw 'Private room application workflow failed.' }
    & docker exec -e "ROOM_WORKFLOW_SNAPSHOT_ID=$fixtureId" $ViteContainer node --test tests/rooms/seating-workflow.test.mjs
    if ($LASTEXITCODE -ne 0) { throw 'Room seating workflow failed.' }
}
finally {
    # One uniquely named synthetic file only; never delete a directory or world data.
    if (Test-Path -LiteralPath $fixturePath) {
        $fixtureData = Get-Content -LiteralPath $fixturePath -Raw | ConvertFrom-Json
        if ($fixtureData.fixture -ne 'room-workflow-private-v1') { throw 'Unknown fixture identity; file retained.' }
        Remove-Item -LiteralPath $fixturePath
        Write-Output 'Private room workflow snapshot removed.'
    }
}
