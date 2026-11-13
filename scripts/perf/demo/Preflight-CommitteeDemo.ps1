#Requires -Version 5.1
<#
.SYNOPSIS
  Single preflight check before a Requirement-3 committee demonstration.
#>
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..\..')
Set-Location $RepoRoot

Write-Host '=== Committee demo preflight ==='
powershell -ExecutionPolicy Bypass -File .\scripts\perf\Validate-PerfEnvironment.ps1

Write-Host 'Redis ping...'
$prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
$pong = docker exec ecommerce2026-perf-redis redis-cli PING 2>$null
$ErrorActionPreference = $prev
if ($pong -ne 'PONG') { throw 'Redis not healthy' }
Write-Host 'PASS: Redis PONG'

Write-Host 'Replica status...'
powershell -ExecutionPolicy Bypass -File .\scripts\perf\replica\Verify-MysqlReplica.ps1

Write-Host 'SQL demo verify...'
powershell -ExecutionPolicy Bypass -File .\scripts\perf\demo\Show-SqlPerformance.ps1 -Mode Verify

Write-Host 'HTTP smoke...'
$code = curl.exe -s -o NUL -w '%{http_code}' 'http://127.0.0.1:8010/api/v1/products?per_page=1'
if ($code -ne '200') { throw "products list returned $code" }
$detail = curl.exe -s -o NUL -w '%{http_code}' 'http://127.0.0.1:8010/api/v1/products/1'
if ($detail -ne '200') { throw "product detail returned $detail (inventory gRPC?)" }
Write-Host 'PASS: catalog HTTP 200'

Write-Host 'PREFLIGHT OK — ready for committee demo'
