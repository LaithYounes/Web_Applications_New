#Requires -Version 5.1
<#
.SYNOPSIS
  Runs the baseline EXPLAIN pack against ecommerce_main_perf only.
#>
[CmdletBinding()]
param(
  [string]$MysqlContainer = 'ecommerce2026-perf-mysql',
  [string]$OutDir = ''
)

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot = Resolve-Path (Join-Path $ScriptDir '..\..\..')
$SqlFile = Join-Path $ScriptDir 'queries.sql'

if (-not $OutDir) {
  $OutDir = Join-Path $RepoRoot 'docs\performance\baselines'
}
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

if (-not (Test-Path -LiteralPath $SqlFile)) {
  throw "Missing $SqlFile"
}

$running = docker ps --filter "name=^/$MysqlContainer$" --format '{{.Names}}'
if (-not $running) {
  # docker name filter without anchors as fallback
  $running = docker ps --filter "name=$MysqlContainer" --format '{{.Names}}' | Select-Object -First 1
}
if (-not $running) {
  throw "MySQL perf container '$MysqlContainer' is not running. Finish Initialize-PerfEnvironment.ps1 first."
}

$probe = docker exec $MysqlContainer mysql -N -uecommerce_perf_user -pecommerce_perf_password ecommerce_main_perf -e "SELECT DATABASE();"
if ($probe.Trim() -ne 'ecommerce_main_perf') {
  throw "Safety abort: live database is '$probe' (expected ecommerce_main_perf)."
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$outFile = Join-Path $OutDir "explain-baseline-$stamp.txt"
Write-Host "Writing EXPLAIN baseline to $outFile"

Get-Content -LiteralPath $SqlFile -Raw | docker exec -i $MysqlContainer mysql -uecommerce_perf_user -pecommerce_perf_password ecommerce_main_perf --table | Tee-Object -FilePath $outFile

Write-Host 'EXPLAIN baseline captured. Keep this file tagged pre-opt before adding indexes/Redis/replica.'
