#Requires -Version 5.1
<#
.SYNOPSIS
  Restores performance MySQL/Postgres dumps into ecommerce2026-perf.

.DESCRIPTION
  Refuses to overwrite a non-empty ecommerce_main_perf unless -AllowOverwrite is passed.
  Never touches demo volumes/project ecommerce2026.
#>
[CmdletBinding()]
param(
  [Parameter(Mandatory = $true)][string]$BackupDir,
  [switch]$AllowOverwrite
)

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot = Resolve-Path (Join-Path $ScriptDir '..\..\..')
Set-Location $RepoRoot

$ComposeFile = Join-Path $RepoRoot 'docker-compose.perf.yml'
$Project = 'ecommerce2026-perf'
$MysqlContainer = 'ecommerce2026-perf-mysql'
$PgContainer = 'ecommerce2026-perf-inventory-db'
$EnvPerf = Join-Path $RepoRoot 'main-app\laravel\.env.perf'
$EnvExample = Join-Path $RepoRoot 'main-app\laravel\.env.perf.example'

$BackupDir = Resolve-Path $BackupDir
$ManifestPath = Join-Path $BackupDir 'MANIFEST.json'
if (-not (Test-Path -LiteralPath $ManifestPath)) {
  throw "Missing MANIFEST.json in $BackupDir"
}
$manifest = Get-Content -LiteralPath $ManifestPath -Raw | ConvertFrom-Json

if (-not (Test-Path -LiteralPath $EnvPerf)) {
  Copy-Item -LiteralPath $EnvExample -Destination $EnvPerf
  Write-Host "Created .env.perf from example — fill APP_KEY/JWT_SECRET before serving."
}

function Get-EnvPerfValue([string]$Key) {
  $line = Get-Content -LiteralPath $EnvPerf | Where-Object { $_ -match "^$([regex]::Escape($Key))=" } | Select-Object -First 1
  if (-not $line) { throw "Missing $Key in .env.perf" }
  return ($line -replace "^$([regex]::Escape($Key))=", '').Trim().Trim('"').Trim("'")
}

$DbUser = Get-EnvPerfValue 'DB_USERNAME'
$DbPass = Get-EnvPerfValue 'DB_PASSWORD'

Write-Host "Ensuring compose project $Project is up (no demo project)..."
& docker compose -p $Project -f $ComposeFile up -d mysql inventory-db
Start-Sleep -Seconds 8

# Guard: refuse overwrite of existing orders without explicit flag
$prevErr = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
$existingRaw = & docker exec $MysqlContainer mysql -N "-u$DbUser" "-p$DbPass" ecommerce_main_perf -e "SELECT COUNT(*) FROM orders;" 2>&1
$ErrorActionPreference = $prevErr
$existingLine = @($existingRaw | ForEach-Object { "$_" } | Where-Object { $_ -notmatch 'Using a password' -and "$_".Trim() -ne '' }) | Select-Object -Last 1
$existingCount = 0
try { $existingCount = [int]("$existingLine".Trim()) } catch { $existingCount = 0 }
if ($existingCount -gt 0 -and -not $AllowOverwrite) {
  throw "Target ecommerce_main_perf already has $existingCount orders. Re-run with -AllowOverwrite to replace (explicit approval)."
}

$MysqlDump = Join-Path $BackupDir $manifest.mysql_dump
$PgDump = Join-Path $BackupDir $manifest.postgres_dump
if (-not (Test-Path -LiteralPath $MysqlDump)) { throw "Missing MySQL dump: $MysqlDump" }
if (-not (Test-Path -LiteralPath $PgDump)) { throw "Missing Postgres dump: $PgDump" }

# Verify checksums if present
$sums = Join-Path $BackupDir 'SHA256SUMS.txt'
if (Test-Path -LiteralPath $sums) {
  Write-Host 'Verifying SHA256SUMS...'
  Get-Content -LiteralPath $sums | ForEach-Object {
    if ($_ -match '^([A-Fa-f0-9]+)\s+(.+)$') {
      $expected = $Matches[1].ToUpperInvariant()
      $file = Join-Path $BackupDir $Matches[2].Trim()
      $actual = (Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash.ToUpperInvariant()
      if ($actual -ne $expected) { throw "Checksum mismatch for $file" }
    }
  }
  Write-Host 'PASS: checksums'
}

Write-Host 'Restoring MySQL...'
if ($MysqlDump -like '*.zip') {
  $tmp = Join-Path $env:TEMP "perf-mysql-restore-$PID.sql"
  Expand-Archive -LiteralPath $MysqlDump -DestinationPath (Split-Path $tmp) -Force
  $extracted = Get-ChildItem (Split-Path $tmp) -Filter 'ecommerce_main_perf.sql' | Select-Object -First 1
  Get-Content -LiteralPath $extracted.FullName -Raw | & docker exec -i $MysqlContainer mysql "-u$DbUser" "-p$DbPass" ecommerce_main_perf 2>$null
} elseif ($MysqlDump -like '*.gz') {
  if (-not (Get-Command gzip -ErrorAction SilentlyContinue)) {
    throw 'gzip not found to decompress .sql.gz. Install gzip or use the .zip backup.'
  }
  gzip -dc $MysqlDump | & docker exec -i $MysqlContainer mysql "-u$DbUser" "-p$DbPass" ecommerce_main_perf 2>$null
} else {
  Get-Content -LiteralPath $MysqlDump -Raw | & docker exec -i $MysqlContainer mysql "-u$DbUser" "-p$DbPass" ecommerce_main_perf 2>$null
}

Write-Host 'Restoring PostgreSQL...'
docker cp $PgDump "${PgContainer}:/tmp/inv.dump"
docker exec $PgContainer pg_restore -U inventory_perf_user -d ecommerce_inventory_perf --clean --if-exists /tmp/inv.dump
docker exec $PgContainer rm -f /tmp/inv.dump

# Post checks
$ErrorActionPreference = 'Continue'
$ordersRaw = & docker exec $MysqlContainer mysql -N "-u$DbUser" "-p$DbPass" ecommerce_main_perf -e "SELECT COUNT(*) FROM orders;" 2>&1
$idx = & docker exec $MysqlContainer mysql -N "-u$DbUser" "-p$DbPass" ecommerce_main_perf -e "SHOW INDEX FROM orders WHERE Key_name='orders_created_at_index';" 2>&1
$ErrorActionPreference = 'Stop'
$ordersLine = @($ordersRaw | ForEach-Object { "$_" } | Where-Object { $_ -notmatch 'Using a password' -and "$_".Trim() -ne '' }) | Select-Object -Last 1
$orders = [int]("$ordersLine".Trim())
if ($orders -lt 200000) { throw "Restore validation failed: orders=$orders" }
if ($idx -notmatch 'orders_created_at_index') {
  Write-Host 'Index missing after dump restore — applying Laravel migration on perf app...'
  & docker compose -p $Project -f $ComposeFile up -d app inventory-service
  & docker compose -p $Project -f $ComposeFile exec -T app php artisan migrate --force --path=database/migrations/2026_11_10_170000_add_orders_created_at_index.php
}

Write-Host "RESTORE OK: orders=$orders" -ForegroundColor Green
Write-Host 'Next: Preflight-SecondLaptop.ps1 then Show-SqlPerformance.ps1 -Mode Compare'
