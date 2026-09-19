$ErrorActionPreference = 'Stop'
# Evaluate only the real sizing function. Docker, logging and .env I/O are
# in-memory stubs; the installer body is never executed.
$repo = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
Set-Location -LiteralPath $repo
$parseErrors = $null; $tokens = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile((Join-Path $repo 'get-started.ps1'), [ref]$tokens, [ref]$parseErrors)
if ($parseErrors.Count) { throw ($parseErrors | Out-String) }
$fn = $ast.Find({ param($node) $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq 'Configure-HostMemory' }, $true)
. ([scriptblock]::Create($fn.Extent.Text))
function Get-EnvValue($key) { if ($script:settings.ContainsKey($key)) { return [string]$script:settings[$key] }; return '' }
function Set-EnvValue($key, $value) { $script:settings[$key] = [string]$value }
function Say($message) { }
function docker {
    if (($args -join ' ') -eq 'info --format {{.MemTotal}}') { return [long]$script:hostMb * 1MB }
    if (($args -join ' ') -eq 'info --format {{.NCPU}}') { return 8 }
    if (($args -join ' ') -like '*SELECT 1 FROM autoscale_runs LIMIT 1*') { return $script:hasAutoscale }
    throw "Unexpected Docker command: $args"
}
$Rederive = $true
$cases = 0
foreach ($size in @(920, 1900, 3790, 7800, 3916, 7937, 15988, 32090, 64300, 96500, 128700, 193000)) {
    foreach ($profile in @('geodata', 'mapping', 'serving')) {
        $script:hostMb = $size
        $script:hasAutoscale = [int]($profile -eq 'mapping')
        $script:settings = @{ CGA_MEM_PROFILE=$profile; COMPOSE_FILE='docker-compose.yml:docker-compose.public.yml' }
        Configure-HostMemory
        $cap = [int]($settings.MEM_REDIS_QUEUE -replace '[^0-9]', '')
        $data = [int]($settings.REDIS_QUEUE_MAXMEMORY -replace '[^0-9]', '')
        if ($data * 5 -gt $cap * 2 -or $data -le 0 -or $cap -gt 2048) { throw "Persistence headroom failed: $size/$profile cap=$cap data=$data" }
        if ($size -ge 7937 -and $data -lt 192) { throw "Funded queue lost its minimum: $size/$profile" }
        $cases++
    }
}
$script:hostMb = 128700; $script:hasAutoscale = 1
$script:settings = @{ CGA_MEM_PROFILE='geodata' }
Configure-HostMemory
if ($settings.CGA_MEM_PROFILE -ne 'mapping') { throw 'Re-derive must redetect automatic profiles.' }
$script:settings = @{ CGA_MEM_PROFILE='open' }
Configure-HostMemory
if ($settings.CGA_MEM_PROFILE -ne 'open' -or $settings.REDIS_QUEUE_MAXMEMORY -ne '512mb') { throw 'Open profile must keep its existing data limit.' }
$script:settings = @{ CGA_MEM_PROFILE='serving'; MEM_REDIS_QUEUE='2500m'; DERIVED_KEYS='REDIS_QUEUE_MAXMEMORY' }
Configure-HostMemory
if ($settings.MEM_REDIS_QUEUE -ne '2500m' -or $settings.CGA_MEM_PROFILE -ne 'serving') { throw 'Operator pins must be retained.' }
Write-Output "Queue Redis sizing passed: $cases host/profile cases, automatic profile redetection, open profile and operator pins."
