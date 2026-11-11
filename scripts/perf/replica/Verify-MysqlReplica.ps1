#Requires -Version 5.1
<#
.SYNOPSIS
  Verifies MySQL replication lag, write propagation, and Laravel read/write routing.
#>
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot = Resolve-Path (Join-Path $ScriptDir '..\..\..')
Set-Location $RepoRoot

$Primary = 'ecommerce2026-perf-mysql'
$Replica = 'ecommerce2026-perf-mysql-replica'
$App = 'ecommerce2026-perf-main-app'
$RootPass = 'perf_root_password'
$Marker = "perf_replica_probe_" + (Get-Date -Format 'yyyyMMddHHmmss')

function Sql-Primary([string]$Sql) {
  $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  $out = & docker exec $Primary mysql -uroot "-p$RootPass" -N -e $Sql 2>$null
  $ErrorActionPreference = $prev
  return $out
}
function Sql-Replica([string]$Sql) {
  $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  $out = & docker exec $Replica mysql -uroot "-p$RootPass" -N -e $Sql 2>$null
  $ErrorActionPreference = $prev
  return $out
}

Write-Host '=== Replica verification ==='
$pOrders = [int](Sql-Primary "SELECT COUNT(*) FROM ecommerce_main_perf.orders;")
$rOrders = [int](Sql-Replica "SELECT COUNT(*) FROM ecommerce_main_perf.orders;")
Write-Host "Primary orders=$pOrders Replica orders=$rOrders"
if ($pOrders -lt 200000 -or $rOrders -lt 200000) { throw 'Order counts below 200000.' }

$prev = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
$status = & docker exec $Replica mysql -uroot "-p$RootPass" -e "SHOW REPLICA STATUS\G" 2>$null
$ErrorActionPreference = $prev
$io = ($status | Select-String 'Replica_IO_Running:\s+(\S+)').Matches.Groups[1].Value
$sql = ($status | Select-String 'Replica_SQL_Running:\s+(\S+)').Matches.Groups[1].Value
$behind = ($status | Select-String 'Seconds_Behind_Source:\s+(\S+)').Matches.Groups[1].Value
Write-Host "IO=$io SQL=$sql Seconds_Behind_Source=$behind"
if ($io -ne 'Yes' -or $sql -ne 'Yes') { throw 'Replica threads not running.' }

Write-Host "Writing marker on primary: $Marker"
Sql-Primary "CREATE TABLE IF NOT EXISTS ecommerce_main_perf.perf_replica_markers (id BIGINT AUTO_INCREMENT PRIMARY KEY, marker VARCHAR(64) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);" | Out-Null
Sql-Primary "INSERT INTO ecommerce_main_perf.perf_replica_markers (marker) VALUES ('$Marker');" | Out-Null

$found = $null
for ($i = 0; $i -lt 20; $i++) {
  Start-Sleep -Milliseconds 250
  $found = (Sql-Replica "SELECT marker FROM ecommerce_main_perf.perf_replica_markers WHERE marker='$Marker' LIMIT 1;").Trim()
  if ($found -eq $Marker) { break }
}
if ($found -ne $Marker) { throw 'Committed primary write did not appear on replica within timeout.' }
Write-Host "Propagation OK after ~$([Math]::Max(1,$i)*250)ms (not a guarantee of zero lag)."

Write-Host 'Laravel connection identity (read vs write):'
$prev = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
& docker exec $App php app/Support/Performance/perf_replica_probe.php
$probeCode = $LASTEXITCODE
$ErrorActionPreference = $prev
if ($probeCode -ne 0) { throw "Laravel replica probe failed with exit $probeCode" }

Write-Host 'REPLICA VERIFY OK'
