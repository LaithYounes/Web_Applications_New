#Requires -Version 5.1
<#
.SYNOPSIS
  Initializes a real MySQL primary/replica pair for ecommerce2026-perf.

.DESCRIPTION
  - Enables binary logging on the existing primary (restart once with primary.cnf).
  - Seeds mysql-replica from a consistent mysqldump (--source-data=2).
  - Does NOT truncate or drop the primary ecommerce_main_perf dataset.
  - Does NOT touch demo volumes.
#>
[CmdletBinding()]
param(
  [switch]$SkipPrimaryRestart,
  [switch]$ResetReplicaVolume
)

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot = Resolve-Path (Join-Path $ScriptDir '..\..\..')
Set-Location $RepoRoot

$ComposeProject = 'ecommerce2026-perf'
$ComposeFile = 'docker-compose.perf.yml'
$Primary = 'ecommerce2026-perf-mysql'
$Replica = 'ecommerce2026-perf-mysql-replica'
$RootPass = 'perf_root_password'
$WorkDir = Join-Path $RepoRoot 'backups\perf\replica-seed'
New-Item -ItemType Directory -Force -Path $WorkDir | Out-Null
$DumpFile = Join-Path $WorkDir 'primary-seed.sql'

function Invoke-PrimarySql([string]$Sql) {
  $prev = $ErrorActionPreference
  $ErrorActionPreference = 'Continue'
  $out = & docker exec $Primary mysql -uroot "-p$RootPass" -N -e $Sql 2>$null
  $code = $LASTEXITCODE
  $ErrorActionPreference = $prev
  if ($code -ne 0) { throw "Primary SQL failed ($code): $Sql" }
  return $out
}

function Invoke-ReplicaSql([string]$Sql) {
  $prev = $ErrorActionPreference
  $ErrorActionPreference = 'Continue'
  $out = & docker exec $Replica mysql -uroot "-p$RootPass" -N -e $Sql 2>$null
  $code = $LASTEXITCODE
  $ErrorActionPreference = $prev
  if ($code -ne 0) { throw "Replica SQL failed ($code): $Sql" }
  return $out
}

Write-Host '=== Perf MySQL replica initialization ==='

# 0) Safety: primary must be the perf DB with large order set
$orders = [int](Invoke-PrimarySql "SELECT COUNT(*) FROM ecommerce_main_perf.orders;")
$db = (Invoke-PrimarySql "SELECT DATABASE(); SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='ecommerce_main_perf';" | Select-Object -Last 1)
Write-Host "Primary orders count: $orders"
if ($orders -lt 200000) {
  throw "Refusing replica init: expected >=200000 orders, found $orders"
}

# 1) Mount primary.cnf + restart primary unless skipped
if (-not $SkipPrimaryRestart) {
  Write-Host 'Recreating primary with binary-log config (data volume preserved)...'
  & docker compose -p $ComposeProject -f $ComposeFile up -d mysql
  Start-Sleep -Seconds 3
  & docker compose -p $ComposeProject -f $ComposeFile restart mysql
  Write-Host 'Waiting for primary healthy...'
  $deadline = (Get-Date).AddMinutes(3)
  do {
    Start-Sleep -Seconds 3
    $health = docker inspect --format '{{.State.Health.Status}}' $Primary 2>$null
    Write-Host "  primary health=$health"
  } while ($health -ne 'healthy' -and (Get-Date) -lt $deadline)
  if ($health -ne 'healthy') { throw 'Primary did not become healthy after restart.' }
}

$logBin = (Invoke-PrimarySql "SHOW VARIABLES LIKE 'log_bin';" | Out-String)
$serverId = (Invoke-PrimarySql "SELECT @@server_id;").Trim()
Write-Host "Primary server_id=$serverId"
Write-Host $logBin
if ($serverId -ne '1') { throw "Expected primary server_id=1, got $serverId" }
$logBinOn = (Invoke-PrimarySql "SELECT @@log_bin;").Trim()
if ($logBinOn -ne '1') { throw 'Primary log_bin is not enabled.' }

# 2) Replication user on primary
Write-Host 'Ensuring replication user on primary...'
Invoke-PrimarySql @"
CREATE USER IF NOT EXISTS 'repl'@'%' IDENTIFIED BY 'perf_repl_password';
GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%';
FLUSH PRIVILEGES;
"@ | Out-Null

# 3) Consistent dump with source coordinates
Write-Host "Dumping primary to $DumpFile (single-transaction + source-data)..."
if (Test-Path -LiteralPath $DumpFile) { Remove-Item -LiteralPath $DumpFile -Force }
$prev = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
& docker exec $Primary sh -c "mysqldump -uroot -p$RootPass --single-transaction --source-data=2 --routines --triggers --databases ecommerce_main_perf" 2>$null | Set-Content -LiteralPath $DumpFile -Encoding utf8
$ErrorActionPreference = $prev
if (-not (Test-Path -LiteralPath $DumpFile) -or ((Get-Item $DumpFile).Length -lt 1000)) {
  throw 'Primary dump failed or is too small.'
}

$changeLine = Select-String -Path $DumpFile -Pattern 'CHANGE (MASTER|REPLICATION SOURCE) TO' | Select-Object -First 1
if (-not $changeLine) { throw 'Dump is missing SOURCE/MASTER coordinates.' }
Write-Host "Dump coordinates: $($changeLine.Line.Trim())"

# 4) Start / reset replica volume
if ($ResetReplicaVolume) {
  Write-Host 'Stopping replica and removing replica volume only...'
  $prevEap = $ErrorActionPreference
  $ErrorActionPreference = 'Continue'
  & docker compose -p $ComposeProject -f $ComposeFile --profile replica stop mysql-replica 2>&1 | Out-Null
  & docker compose -p $ComposeProject -f $ComposeFile --profile replica rm -f mysql-replica 2>&1 | Out-Null
  docker volume rm ecommerce2026_perf_mysql_replica_data 2>&1 | Out-Null
  $ErrorActionPreference = $prevEap
}

Write-Host 'Starting mysql-replica profile...'
& docker compose -p $ComposeProject -f $ComposeFile --profile replica up -d mysql-replica
$deadline = (Get-Date).AddMinutes(4)
do {
  Start-Sleep -Seconds 4
  $rHealth = docker inspect --format '{{.State.Health.Status}}' $Replica 2>$null
  Write-Host "  replica health=$rHealth"
} while ($rHealth -ne 'healthy' -and (Get-Date) -lt $deadline)
if ($rHealth -ne 'healthy') { throw 'Replica container did not become healthy.' }

$rServerId = (Invoke-ReplicaSql "SELECT @@server_id;").Trim()
if ($rServerId -ne '2') { throw "Expected replica server_id=2, got $rServerId" }

# 5) Load dump into replica (temporarily disable super_read_only)
Write-Host 'Loading dump into replica...'
Invoke-ReplicaSql "SET GLOBAL super_read_only=OFF; SET GLOBAL read_only=OFF;" | Out-Null
Get-Content -LiteralPath $DumpFile -Raw | & docker exec -i $Replica mysql -uroot "-p$RootPass"
if ($LASTEXITCODE -ne 0) { throw "Replica dump import failed with exit $LASTEXITCODE" }

# Extract file/pos from dump CHANGE line
if ($changeLine.Line -match "MASTER_LOG_FILE='([^']+)'") { $logFile = $Matches[1] }
elseif ($changeLine.Line -match "SOURCE_LOG_FILE='([^']+)'") { $logFile = $Matches[1] }
else { throw 'Could not parse log file from dump.' }
if ($changeLine.Line -match 'MASTER_LOG_POS=(\d+)') { $logPos = $Matches[1] }
elseif ($changeLine.Line -match 'SOURCE_LOG_POS=(\d+)') { $logPos = $Matches[1] }
else { throw 'Could not parse log pos from dump.' }

Write-Host "Configuring replication SOURCE_LOG_FILE=$logFile SOURCE_LOG_POS=$logPos"
Invoke-ReplicaSql "STOP REPLICA; RESET REPLICA ALL;" 2>$null | Out-Null
Invoke-ReplicaSql @"
CHANGE REPLICATION SOURCE TO
  SOURCE_HOST='mysql',
  SOURCE_PORT=3306,
  SOURCE_USER='repl',
  SOURCE_PASSWORD='perf_repl_password',
  SOURCE_LOG_FILE='$logFile',
  SOURCE_LOG_POS=$logPos,
  GET_SOURCE_PUBLIC_KEY=1;
START REPLICA;
SET GLOBAL read_only=ON;
SET GLOBAL super_read_only=ON;
"@ | Out-Null

Start-Sleep -Seconds 3
$status = & docker exec $Replica mysql -uroot "-p$RootPass" -e "SHOW REPLICA STATUS\G"
Write-Host $status
$io = ($status | Select-String 'Replica_IO_Running:\s+(\S+)').Matches.Groups[1].Value
$sql = ($status | Select-String 'Replica_SQL_Running:\s+(\S+)').Matches.Groups[1].Value
$behind = ($status | Select-String 'Seconds_Behind_Source:\s+(\S+)').Matches.Groups[1].Value
$rOrders = [int](Invoke-ReplicaSql "SELECT COUNT(*) FROM ecommerce_main_perf.orders;")
Write-Host "Replica IO=$io SQL=$sql Behind=$behind Orders=$rOrders"

if ($io -ne 'Yes' -or $sql -ne 'Yes') {
  throw 'Replica threads are not running. Inspect SHOW REPLICA STATUS.'
}
if ($rOrders -lt 200000) {
  throw "Replica order count $rOrders is below 200000."
}

Write-Host 'REPLICA INIT OK. Next: point DB_READ_HOST=mysql-replica and recreate app.'
Write-Host "  docker compose -p $ComposeProject -f $ComposeFile up -d app"
