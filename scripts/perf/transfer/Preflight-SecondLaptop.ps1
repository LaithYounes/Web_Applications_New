#Requires -Version 5.1
<#
.SYNOPSIS
  Single preflight command for the second Windows laptop before the committee demo.

.DESCRIPTION
  Validates compose project, DB name, order count, index, HTTP health, and SQL demo Verify mode.
  Read-only except optional service start. Never resets volumes.
#>
[CmdletBinding()]
param(
  [switch]$StartStack
)

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot = Resolve-Path (Join-Path $ScriptDir '..\..\..')
Set-Location $RepoRoot

$ComposeFile = Join-Path $RepoRoot 'docker-compose.perf.yml'
$Project = 'ecommerce2026-perf'
$Failures = New-Object System.Collections.Generic.List[string]

function Pass([string]$Message) { Write-Host "PASS: $Message" -ForegroundColor Green }
function Fail([string]$Message) {
  $Failures.Add($Message) | Out-Null
  Write-Host "FAIL: $Message" -ForegroundColor Red
}

Write-Host "=== Second-laptop preflight (project $Project) ==="

if (-not (Test-Path -LiteralPath $ComposeFile)) { Fail 'docker-compose.perf.yml missing'; exit 1 }
if (-not (Test-Path -LiteralPath (Join-Path $RepoRoot 'main-app\laravel\.env.perf.example'))) {
  Fail '.env.perf.example missing'; exit 1
}
if (-not (Test-Path -LiteralPath (Join-Path $RepoRoot 'main-app\laravel\.env.perf'))) {
  Fail '.env.perf missing — copy from .env.perf.example and set APP_KEY/JWT_SECRET locally'
}

if ($StartStack) {
  Write-Host 'Starting ecommerce2026-perf (no demo compose)...'
  & docker compose -p $Project -f $ComposeFile up -d
}

$ps = docker compose -p $Project -f $ComposeFile ps --format json 2>&1 | Out-String
if ($LASTEXITCODE -ne 0) { Fail 'compose ps failed' } else { Pass 'compose ps' }
if ($ps -match 'ecommerce2026-perf-mysql') { Pass 'mysql container' } else { Fail 'mysql container missing' }
if ($ps -match 'ecommerce2026-perf-main-app') { Pass 'app container' } else { Fail 'app container missing' }
if ($ps -match 'ecommerce2026-perf-inventory') { Pass 'inventory containers' } else { Fail 'inventory containers missing' }

try {
  $health = Invoke-RestMethod -Uri 'http://127.0.0.1:8010/api/v1/health' -TimeoutSec 10
  if ($health.success -eq $true) { Pass 'HTTP :8010 /api/v1/health' } else { Fail 'health payload unexpected' }
} catch {
  Fail "HTTP health failed: $($_.Exception.Message)"
}

$demo = powershell -ExecutionPolicy Bypass -File (Join-Path $RepoRoot 'scripts\perf\demo\Show-SqlPerformance.ps1') -Mode Verify
if ($LASTEXITCODE -eq 0) { Pass 'SQL demo Verify mode' } else { Fail 'SQL demo Verify mode' }

Write-Host ""
if ($Failures.Count -gt 0) {
  Write-Host "PREFLIGHT FAILED ($($Failures.Count))" -ForegroundColor Red
  $Failures | ForEach-Object { Write-Host " - $_" }
  exit 1
}

Write-Host 'PREFLIGHT PASSED — ready for Show-SqlPerformance.ps1 -Mode Compare' -ForegroundColor Green
exit 0
