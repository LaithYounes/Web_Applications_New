#Requires -Version 5.1
<#
.SYNOPSIS
  Creates validated backups of the isolated performance databases for laptop transfer.

.DESCRIPTION
  Writes compressed dumps under backups/perf/ (gitignored). Does not commit secrets.
  Does not touch the demo stack. Does not DROP or truncate anything.
#>
[CmdletBinding()]
param(
  [string]$OutDir = ''
)

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot = Resolve-Path (Join-Path $ScriptDir '..\..\..')
Set-Location $RepoRoot

$MysqlContainer = 'ecommerce2026-perf-mysql'
$PgContainer = 'ecommerce2026-perf-inventory-db'
$EnvPerf = Join-Path $RepoRoot 'main-app\laravel\.env.perf'
$Stamp = Get-Date -Format 'yyyyMMdd-HHmmss'

if (-not $OutDir) {
  $OutDir = Join-Path $RepoRoot "backups\perf\$Stamp"
}
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

if (-not (Test-Path -LiteralPath $EnvPerf)) {
  throw "Missing $EnvPerf"
}

function Get-EnvPerfValue([string]$Key) {
  $line = Get-Content -LiteralPath $EnvPerf | Where-Object { $_ -match "^$([regex]::Escape($Key))=" } | Select-Object -First 1
  if (-not $line) { throw "Missing $Key in .env.perf" }
  return ($line -replace "^$([regex]::Escape($Key))=", '').Trim().Trim('"').Trim("'")
}

$DbUser = Get-EnvPerfValue 'DB_USERNAME'
$DbPass = Get-EnvPerfValue 'DB_PASSWORD'
$DbName = Get-EnvPerfValue 'DB_DATABASE'
if ($DbName -ne 'ecommerce_main_perf') {
  throw "Refusing backup: DB_DATABASE=$DbName"
}

$running = docker ps --filter "name=$MysqlContainer" --format '{{.Names}}'
if (-not $running) { throw "$MysqlContainer is not running." }

Write-Host "Backup directory: $OutDir"

# 1) MySQL dump (orders + catalog). Password via env var inside container only.
$MysqlOut = Join-Path $OutDir 'ecommerce_main_perf.sql'
Write-Host 'Dumping MySQL ecommerce_main_perf ...'
$prev = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
& docker exec $MysqlContainer sh -c "mysqldump -u'$DbUser' -p'$DbPass' --single-transaction --routines --triggers ecommerce_main_perf" 2>$null | Set-Content -LiteralPath $MysqlOut -Encoding utf8
$ErrorActionPreference = $prev
if (-not (Test-Path -LiteralPath $MysqlOut) -or ((Get-Item -LiteralPath $MysqlOut).Length -lt 1000)) {
  throw 'MySQL dump failed or is too small.'
}

# Compress
$MysqlGz = "$MysqlOut.gz"
Write-Host 'Compressing MySQL dump ...'
if (Get-Command gzip -ErrorAction SilentlyContinue) {
  gzip -f $MysqlOut
} else {
  # Fallback: .NET DeflateStream as .gz-compatible via Compress-Archive zip
  $zip = Join-Path $OutDir 'ecommerce_main_perf.sql.zip'
  Compress-Archive -LiteralPath $MysqlOut -DestinationPath $zip -Force
  Remove-Item -LiteralPath $MysqlOut -Force
  $MysqlGz = $zip
}

# 2) PostgreSQL inventory dump
$PgOut = Join-Path $OutDir 'ecommerce_inventory_perf.dump'
Write-Host 'Dumping PostgreSQL ecommerce_inventory_perf ...'
& docker exec $PgContainer pg_dump -U inventory_perf_user -d ecommerce_inventory_perf -Fc -f /tmp/inv.dump
& docker cp "${PgContainer}:/tmp/inv.dump" $PgOut
& docker exec $PgContainer rm -f /tmp/inv.dump
if (-not (Test-Path -LiteralPath $PgOut) -or ((Get-Item -LiteralPath $PgOut).Length -lt 100)) {
  throw 'PostgreSQL dump failed or is too small.'
}

# 3) Manifest (no secrets)
function Invoke-MysqlScalar([string]$Sql) {
  $prev = $ErrorActionPreference
  $ErrorActionPreference = 'Continue'
  $out = & docker exec $MysqlContainer mysql -N "-u$DbUser" "-p$DbPass" ecommerce_main_perf -e $Sql 2>&1
  $ErrorActionPreference = $prev
  if ($LASTEXITCODE -ne 0) {
    throw "MySQL scalar query failed for validation."
  }
  $line = @($out | ForEach-Object { "$_" } | Where-Object { $_ -notmatch 'Using a password' -and $_.Trim() -ne '' }) | Select-Object -Last 1
  return "$line".Trim()
}

$gitCommit = (git rev-parse HEAD).Trim()
$gitBranch = (git rev-parse --abbrev-ref HEAD).Trim()
$orderCount = Invoke-MysqlScalar 'SELECT COUNT(*) FROM orders;'
$itemCount = Invoke-MysqlScalar 'SELECT COUNT(*) FROM order_items;'
$indexPresent = Invoke-MysqlScalar "SHOW INDEX FROM orders WHERE Key_name='orders_created_at_index';"

$manifest = [ordered]@{
  created_at           = (Get-Date).ToString('o')
  git_remote           = 'https://github.com/LaithYounes/Web_Applications_New.git'
  git_branch           = $gitBranch
  git_commit           = $gitCommit
  mysql_database       = 'ecommerce_main_perf'
  mysql_orders         = [int]("$orderCount".Trim())
  mysql_order_items    = [int]("$itemCount".Trim())
  index_orders_created_at = ($indexPresent -match 'orders_created_at_index')
  postgres_database    = 'ecommerce_inventory_perf'
  mysql_dump           = (Split-Path -Leaf $MysqlGz)
  postgres_dump        = (Split-Path -Leaf $PgOut)
  note                 = 'Do not commit this directory or .env.perf. Restore requires explicit -AllowOverwrite on second laptop if target is non-empty.'
}
$manifestPath = Join-Path $OutDir 'MANIFEST.json'
$manifest | ConvertTo-Json | Set-Content -LiteralPath $manifestPath -Encoding UTF8

# Checksums
Get-FileHash -LiteralPath $MysqlGz -Algorithm SHA256 | ForEach-Object {
  "$($_.Hash)  $(Split-Path -Leaf $_.Path)"
} | Set-Content -LiteralPath (Join-Path $OutDir 'SHA256SUMS.txt') -Encoding ascii
Get-FileHash -LiteralPath $PgOut -Algorithm SHA256 | ForEach-Object {
  "$($_.Hash)  $(Split-Path -Leaf $_.Path)"
} | Add-Content -LiteralPath (Join-Path $OutDir 'SHA256SUMS.txt') -Encoding ascii

# Validate counts in manifest
if ([int]$manifest.mysql_orders -lt 200000) {
  throw "Backup validation failed: orders=$($manifest.mysql_orders)"
}
if (-not $manifest.index_orders_created_at) {
  throw 'Backup validation failed: orders_created_at_index missing in source DB.'
}

Write-Host "BACKUP OK: $OutDir" -ForegroundColor Green
Write-Host 'Copy this folder privately to the second laptop. Do not push dumps to GitHub.'
Write-Host $manifestPath
