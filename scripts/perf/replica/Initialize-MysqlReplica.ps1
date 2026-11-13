#Requires -Version 5.1
[CmdletBinding()]
param([string]$Project='ecommerce2026-perf',[string]$EnvFile='',
      [switch]$ReseedReplica,[switch]$SkipPrimaryRestart,[switch]$ResetReplicaVolume,
      [ValidateRange(1,900)][int]$TimeoutSeconds=180)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '../Perf.Common.ps1')
if ($ResetReplicaVolume) { throw 'Volume deletion is not supported; use -ReseedReplica to rebuild only the verified replica database.' }
$ctx=New-PerfContext $Project $EnvFile
$oldRead=$env:DB_READ_HOST
$temp=New-PerfTemp
$activated=$false
$writers=@()
try {
    Assert-PerfDatabases $ctx
    $writers=@(Stop-PerfWriters $ctx)
    Set-PerfReadHost $ctx mysql
    if ((Invoke-PerfSql $ctx mysql 'SELECT @@log_bin;') -ne '1') { throw 'Primary binary log is disabled.' }
    $null=Invoke-PerfSql $ctx mysql 'SELECT COUNT(*) FROM migrations; SELECT COUNT(*) FROM orders;'
    $null=Invoke-PerfCompose $ctx @('--profile','replica','up','-d','mysql-replica') 'replica startup'
    Wait-PerfReady { (Invoke-PerfSql $ctx mysql-replica 'SELECT 1;') -eq '1' } $TimeoutSeconds 'replica SQL'
    $null=Assert-PerfIdentity $ctx 'mysql-replica'
    if ((Invoke-PerfSql $ctx mysql-replica 'SELECT @@server_id;') -ne '2') { throw 'Replica identity mismatch.' }
    $status=Invoke-PerfSql $ctx mysql-replica 'SHOW REPLICA STATUS\G'
    if ($status -match 'Source_Host:\s+mysql\b' -and -not $ReseedReplica) {
        # --skip-replica-start requires explicitly resuming a previously configured channel.
        $null=Invoke-PerfSql $ctx mysql-replica 'START REPLICA;'
        Wait-PerfReady {
            $s=Invoke-PerfSql $ctx mysql-replica 'SHOW REPLICA STATUS\G'
            $s -match 'Replica_IO_Running:\s+Yes' -and $s -match 'Replica_SQL_Running:\s+Yes' -and $s -match 'Seconds_Behind_Source:\s+0\b'
        } $TimeoutSeconds 'existing replication channel'
        $status=Invoke-PerfSql $ctx mysql-replica 'SHOW REPLICA STATUS\G'
    }
    if ($status -notmatch 'Replica_IO_Running:\s+Yes' -or $status -notmatch 'Replica_SQL_Running:\s+Yes' -or $ReseedReplica) {
        $tables=ConvertTo-PerfCount (Invoke-PerfSql $ctx mysql-replica "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE();")
        if ($tables -gt 0 -and -not $ReseedReplica) { throw 'Replica has an incomplete/existing schema; retry explicitly with -ReseedReplica. Primary preserved.' }
        $null=Invoke-PerfSql $ctx mysql "CREATE USER IF NOT EXISTS 'repl'@'%' IDENTIFIED BY 'perf_repl_password'; ALTER USER 'repl'@'%' IDENTIFIED BY 'perf_repl_password'; GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%';"
        $dump=Join-Path $temp 'seed.sql'
        $null=Invoke-PerfCompose $ctx @('exec','-T','mysql','sh','-ec','MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --source-data=2 --routines --triggers --add-drop-database --databases ecommerce_main_perf') 'replica dump' -OutputFile $dump
        $line=Select-String -LiteralPath $dump -Pattern "SOURCE_LOG_FILE='([^']+)', SOURCE_LOG_POS=(\d+)" | Select-Object -First 1
        if (-not $line -or $line.Line -notmatch "SOURCE_LOG_FILE='([a-zA-Z0-9_.-]+)', SOURCE_LOG_POS=(\d+)") { throw 'Dump lacks valid replication coordinates.' }
        $log=$Matches[1]; $pos=$Matches[2]
        $null=Invoke-PerfSql $ctx mysql-replica 'STOP REPLICA; RESET REPLICA ALL; SET GLOBAL super_read_only=OFF; SET GLOBAL read_only=OFF;'
        $null=Invoke-PerfCompose $ctx @('exec','-T','mysql-replica','sh','-ec','MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4') 'replica import' -InputFile $dump
        $null=Invoke-PerfSql $ctx mysql-replica "CHANGE REPLICATION SOURCE TO SOURCE_HOST='mysql',SOURCE_USER='repl',SOURCE_PASSWORD='perf_repl_password',SOURCE_LOG_FILE='$log',SOURCE_LOG_POS=$pos,GET_SOURCE_PUBLIC_KEY=1; START REPLICA; SET GLOBAL read_only=ON; SET GLOBAL super_read_only=ON;"
    }
    Wait-PerfReady {
        $s=Invoke-PerfSql $ctx mysql-replica 'SHOW REPLICA STATUS\G'
        $s -match 'Replica_IO_Running:\s+Yes' -and $s -match 'Replica_SQL_Running:\s+Yes' -and $s -match 'Seconds_Behind_Source:\s+0\b'
    } $TimeoutSeconds 'replication threads and catch-up'
    . (Join-Path $PSScriptRoot '../transfer/Transfer.Common.ps1')
    $a=Get-PerfFingerprint $ctx mysql 'ecommerce_main_perf' $temp -ReplicaComparison
    $b=Get-PerfFingerprint $ctx mysql-replica 'ecommerce_main_perf' $temp -ReplicaComparison
    Assert-PerfFingerprint $a $b 'replica schema/data'
    if ((Invoke-PerfSql $ctx mysql-replica 'SELECT @@read_only,@@super_read_only;') -ne ('1'+[char]9+'1')) { throw 'Replica is not read-only.' }
    Set-PerfReadHost $ctx mysql-replica
    $null=Invoke-PerfCompose $ctx @('up','-d','--no-deps','--force-recreate','app') 'replica routing recreate'
    # Resume inventory before the gRPC readiness validation.
    $null=Invoke-PerfCompose $ctx @('up','-d','--no-deps','inventory-service') 'resume inventory'
    & (Join-Path $PSScriptRoot '../Validate-PerfEnvironment.ps1') -Project $Project -EnvFile $ctx.EnvFile -TimeoutSeconds $TimeoutSeconds
    $activated=$true
    foreach ($w in $writers) {
        if ($w -notin @('mysql-replica','app','inventory-service')) { $null=Invoke-PerfCompose $ctx @('--profile','lifecycle','up','-d','--no-deps','--force-recreate',$w) 'resume writer' }
    }
} finally {
    try {
        if (-not $activated) {
            Set-PerfReadHost $ctx mysql
            try { $null=Invoke-PerfCompose $ctx @('up','-d','--no-deps','--force-recreate','app') 'primary fallback recreate' }
            catch { $null=Invoke-PerfCompose $ctx @('stop','app') 'stop unsafe app'; throw }
        }
    } finally {
        try { Remove-PerfTemp $temp } finally { $env:DB_READ_HOST=$oldRead }
    }
}
Write-Host 'REPLICA INIT OK (verified before read switch)'
