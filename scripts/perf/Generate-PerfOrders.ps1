#Requires -Version 5.1
<#
.SYNOPSIS
  Runs perf:generate-orders inside the isolated ecommerce2026-perf app container.
#>
[CmdletBinding()]
param(
  [int]$Count = 200000,
  [int]$Chunk = 2000,
  [int]$Users = 5000,
  [int]$TargetTotal = 0,
  [switch]$DryRun
)

$ErrorActionPreference = 'Stop'
$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$ComposeFile = Join-Path $RepoRoot 'docker-compose.perf.yml'
$Project = 'ecommerce2026-perf'
$AppContainer = 'ecommerce2026-perf-main-app'

Set-Location $RepoRoot

if ($env:COMPOSE_PROFILES) {
  throw 'Unset COMPOSE_PROFILES before generating perf data.'
}

$running = docker ps --filter "name=$AppContainer" --format '{{.Names}}' | Select-Object -First 1
if (-not $running) {
  throw "App container '$AppContainer' is not running."
}

# Probe the perf MySQL server itself. The artisan command also refuses unless
# SELECT DATABASE() on Laravel's live connection is ecommerce_main_perf.
$liveDb = docker exec ecommerce2026-perf-mysql mysql -N -uecommerce_perf_user -pecommerce_perf_password ecommerce_main_perf -e "SELECT DATABASE();"
if ($liveDb.Trim() -ne 'ecommerce_main_perf') {
  throw "Safety abort: perf MySQL DATABASE() is '$liveDb'."
}

$args = @(
  'exec', '-T', 'app',
  'php', 'artisan', 'perf:generate-orders',
  "--count=$Count",
  "--chunk=$Chunk",
  "--users=$Users"
)
if ($TargetTotal -gt 0) {
  $args += "--target-total=$TargetTotal"
}
if ($DryRun) {
  $args += '--dry-run'
}

Write-Host "docker compose -p $Project -f docker-compose.perf.yml $($args -join ' ')"
& docker compose -p $Project -f $ComposeFile @args
if ($LASTEXITCODE -ne 0) {
  throw "perf:generate-orders failed with exit $LASTEXITCODE"
}
