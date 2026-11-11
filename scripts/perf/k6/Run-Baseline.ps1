#Requires -Version 5.1
<#
.SYNOPSIS
  Records Requirement-3 baseline k6 results (p50/p95/p99, throughput, error rate) against :8010 only.
#>
[CmdletBinding()]
param(
  [ValidateSet('products-list', 'product-detail', 'orders-list', 'all')]
  [string]$Scenario = 'all',
  [int]$Vus = 20,
  [string]$Duration = '60s',
  [string]$OutDir = ''
)

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot = Resolve-Path (Join-Path $ScriptDir '..\..\..')

if (-not $OutDir) {
  $OutDir = Join-Path $RepoRoot 'docs\performance\baselines'
}
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

if (-not $env:BASE_URL) { $env:BASE_URL = 'http://127.0.0.1:8010' }
if (-not $env:PERF_ENVIRONMENT) { $env:PERF_ENVIRONMENT = '1' }

if ($env:BASE_URL -notmatch ':8010\b') {
  throw "Refusing load test: BASE_URL must target performance port 8010 (got $($env:BASE_URL))"
}
if ($env:BASE_URL -match ':(8000|3307|5433|9090)\b') {
  throw "Refusing load test: BASE_URL looks like the demo stack ($($env:BASE_URL))"
}
if ($env:PERF_ENVIRONMENT -ne '1') {
  throw 'Refusing load test: PERF_ENVIRONMENT must be 1'
}

function Invoke-K6Baseline {
  param([Parameter(Mandatory = $true)][string]$Name, [Parameter(Mandatory = $true)][string]$File)
  $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
  $raw = (Join-Path $OutDir "k6-raw-$Name-$stamp.json") -replace '\\', '/'
  $summary = (Join-Path $OutDir "k6-summary-$Name-$stamp.json") -replace '\\', '/'
  $metrics = (Join-Path $OutDir "k6-metrics-$Name-$stamp.json") -replace '\\', '/'
  Write-Host "Running $Name"
  Write-Host "  metrics=$metrics"
  Write-Host "  raw=$raw"
  & k6 run `
    --out "json=$raw" `
    -e "BASE_URL=$($env:BASE_URL)" `
    -e "PERF_ENVIRONMENT=$($env:PERF_ENVIRONMENT)" `
    -e "PERF_JWT=$($env:PERF_JWT)" `
    -e "PERF_USER_EMAIL=$($env:PERF_USER_EMAIL)" `
    -e "PERF_USER_PASSWORD=$($env:PERF_USER_PASSWORD)" `
    -e "VUS=$Vus" `
    -e "DURATION=$Duration" `
    -e "K6_SUMMARY_PATH=$summary" `
    -e "K6_METRICS_PATH=$metrics" `
    $File
  if ($LASTEXITCODE -ne 0) {
    throw "k6 scenario failed: $Name (exit $LASTEXITCODE). Partial files may exist under $OutDir"
  }
  if (-not (Test-Path -LiteralPath ($metrics -replace '/', '\'))) {
    throw "k6 finished without metrics file: $metrics"
  }
}

$scenarios = @{
  'products-list'  = Join-Path $ScriptDir 'baseline-products-list.js'
  'product-detail' = Join-Path $ScriptDir 'baseline-product-detail.js'
  'orders-list'    = Join-Path $ScriptDir 'baseline-orders-list.js'
}

$toRun = if ($Scenario -eq 'all') { @('products-list', 'product-detail', 'orders-list') } else { @($Scenario) }

foreach ($name in $toRun) {
  Invoke-K6Baseline -Name $name -File $scenarios[$name]
}

Write-Host 'Baseline recorded. Do not add indexes, Redis, ETag, or a replica until these files are archived as pre-opt.'
