#Requires -Version 5.1
[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$BackupDir,[switch]$AllowOverwrite,
      [string]$Project='ecommerce2026-perf',[string]$EnvFile='', [string]$RecoveryDir='')
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '../Perf.Common.ps1')
. (Join-Path $PSScriptRoot 'Transfer.Common.ps1')
# Validate every file/hash before starting or stopping any service.
$m=Read-PerfManifest $BackupDir
$BackupDir=(Resolve-Path -LiteralPath $BackupDir).Path
$ctx=New-PerfContext $Project $EnvFile
$temp=New-PerfTemp
$stageDb='perf_stage_'+[guid]::NewGuid().ToString('N')
$mysqlStage=$false; $pgStage=$false; $replacing=$false
$oldRead=$env:DB_READ_HOST
$writers=@()
$phase='preflight'
try {
    $sql=Join-Path $temp 'mysql.sql'
    Expand-PerfMysql (Join-Path $BackupDir $m.mysql_dump) $sql
    $null=Invoke-PerfCompose $ctx @('up','-d','mysql','inventory-db') 'restore databases startup'
    $null=Assert-PerfIdentity $ctx 'mysql'
    $null=Assert-PerfIdentity $ctx 'inventory-db'
    Wait-PerfReady { (Invoke-PerfSql $ctx mysql 'SELECT 1;') -eq '1' } 180 'restore primary'
    Wait-PerfReady { (Invoke-PerfSql $ctx postgres 'SELECT 1;') -eq '1' } 180 'restore PostgreSQL'
    Assert-PerfDatabases $ctx
    $writers=@(Stop-PerfWriters $ctx)
    # Any user table means nonempty, even if orders is absent/empty. SQL/parser errors are fatal.
    $myTables=ConvertTo-PerfCount (Invoke-PerfSql $ctx mysql 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE();')
    $pgTables=ConvertTo-PerfCount (Invoke-PerfSql $ctx postgres "SELECT COUNT(*) FROM pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema');")
    if (($myTables -gt 0 -or $pgTables -gt 0) -and -not $AllowOverwrite) { throw 'Destination contains schema/data; explicit -AllowOverwrite required for BOTH databases.' }
    $phase='staging'
    $null=Invoke-PerfSql $ctx mysql "CREATE DATABASE $stageDb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    $mysqlStage=$true
    $null=Invoke-PerfSql $ctx postgres "CREATE DATABASE $stageDb;"
    $pgStage=$true
    Import-PerfMysql $ctx $stageDb $sql
    Import-PerfPostgres $ctx $stageDb (Join-Path $BackupDir $m.postgres_dump)
    Assert-PerfFingerprint $m.mysql_fingerprint (Get-PerfFingerprint $ctx mysql $stageDb $temp) 'staged MySQL'
    Assert-PerfFingerprint $m.postgres_fingerprint (Get-PerfFingerprint $ctx postgres $stageDb $temp) 'staged PostgreSQL'
    # Preserve exact pre-state dumps before destructive replacement; never delete recovery artifacts.
    if (-not $RecoveryDir) { $RecoveryDir=Join-Path $ctx.Repo ('backups/perf/recovery-'+[guid]::NewGuid().ToString('N')) }
    if (Test-Path -LiteralPath $RecoveryDir) { throw 'Recovery directory must be new.' }
    $null=New-Item -ItemType Directory -Path $RecoveryDir
    Export-PerfDatabases $ctx $RecoveryDir
    $journal=Join-Path $RecoveryDir 'RESTORE-STATE.json'
    function Save-State([string]$State) {
        [ordered]@{state=$State; backup=$BackupDir; project=$Project; recovery=$RecoveryDir; writers=$writers;
          retry='Keep writers stopped. Retry the same verified archive with -AllowOverwrite; or import BOTH pre-state dumps from this recovery directory. Do not resume replica until reseeded.'} |
          ConvertTo-Json -Depth 6 | Set-Content -LiteralPath $journal -Encoding UTF8
    }
    Save-State 'validated-staging'
    # Recheck identities immediately before destructive work.
    Assert-PerfDatabases $ctx
    Set-PerfReadHost $ctx mysql
    $phase='replace-mysql'; $replacing=$true
    Save-State $phase
    $null=Invoke-PerfSql $ctx mysql 'DROP DATABASE ecommerce_main_perf; CREATE DATABASE ecommerce_main_perf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;' 'mysql'
    Import-PerfMysql $ctx 'ecommerce_main_perf' $sql
    Assert-PerfFingerprint $m.mysql_fingerprint (Get-PerfFingerprint $ctx mysql 'ecommerce_main_perf' $temp) 'live MySQL'
    $phase='replace-postgres'
    Save-State $phase
    # PostgreSQL replacement is one local transaction; MySQL replacement remains separate.
    $null=Invoke-PerfSql $ctx postgres 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;' 'ecommerce_inventory_perf'
    Import-PerfPostgres $ctx 'ecommerce_inventory_perf' (Join-Path $BackupDir $m.postgres_dump)
    Assert-PerfFingerprint $m.postgres_fingerprint (Get-PerfFingerprint $ctx postgres 'ecommerce_inventory_perf' $temp) 'live PostgreSQL'
    $phase='verified-both'
    Save-State $phase
    # New cache namespace avoids stale catalog values without deleting any existing Redis keys.
    Set-PerfEnvValue $ctx 'CACHE_PREFIX' ('perf-restore-'+[guid]::NewGuid().ToString('N')+'-')
    if ($writers.Count -gt 0) {
        $null=Invoke-PerfCompose $ctx @('run','--rm','--no-deps','-T','app','php','artisan','config:clear') 'restore config clear'
    }
    $null=Invoke-PerfSql $ctx mysql "DROP DATABASE $stageDb;" 'mysql'; $mysqlStage=$false
    $null=Invoke-PerfSql $ctx postgres "DROP DATABASE $stageDb;" 'postgres'; $pgStage=$false
    Remove-PerfTemp $temp; $temp=''
    # Resume only original writers, with explicitly recreated primary read host; replica stays stopped.
    foreach ($w in $writers) {
        if ($w -ne 'mysql-replica') { $null=Invoke-PerfCompose $ctx @('--profile','lifecycle','up','-d','--no-deps','--force-recreate',$w) 'resume restore writer' }
    }
    Save-State 'complete-primary-read'
} catch {
    Write-Host "[restore] FAILED at $phase; writers remain stopped. Recovery: $RecoveryDir"
    if ($replacing) { Save-State ('FAILED-'+$phase) }
    # Never resume any writers following an unsuccessful restore.
    $null=Stop-PerfWriters $ctx
    throw
} finally {
    # Cleanup is restricted to unique staging DBs and the owned temp directory. Failure propagates before OK.
    try {
        if ($mysqlStage) { $null=Invoke-PerfSql $ctx mysql "DROP DATABASE $stageDb;" 'mysql' }
        if ($pgStage) { $null=Invoke-PerfSql $ctx postgres "DROP DATABASE $stageDb;" 'postgres' }
        if ($temp) { Remove-PerfTemp $temp }
    } finally { $env:DB_READ_HOST=$oldRead }
}
Write-Host 'RESTORE OK: MySQL and PostgreSQL schema/data/constraints/sequence fingerprints verified.'
