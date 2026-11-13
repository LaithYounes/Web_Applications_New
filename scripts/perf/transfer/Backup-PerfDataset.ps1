#Requires -Version 5.1
[CmdletBinding()]
param([string]$OutDir='', [string]$Project='ecommerce2026-perf',[string]$EnvFile='')
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '../Perf.Common.ps1')
. (Join-Path $PSScriptRoot 'Transfer.Common.ps1')
$ctx=New-PerfContext $Project $EnvFile
if (-not $OutDir) { $OutDir=Join-Path $ctx.Repo ('backups/perf/'+[guid]::NewGuid().ToString('N')) }
if (Test-Path -LiteralPath $OutDir) { throw 'Backup output directory must be new; existing backup will not be overwritten.' }
$null=New-Item -ItemType Directory -Path $OutDir
$temp=New-PerfTemp
$writers=@()
try {
    Assert-PerfDatabases $ctx
    $writers=@(Stop-PerfWriters $ctx)
    Write-Host '[backup] writers stopped; no external clients/DDL may write during snapshot'
    Export-PerfDatabases $ctx $OutDir
    $my=Get-PerfFingerprint $ctx mysql 'ecommerce_main_perf' $temp
    $pg=Get-PerfFingerprint $ctx postgres 'ecommerce_inventory_perf' $temp
    $files=@()
    foreach ($name in @('mysql.sql.gz','postgres.dump')) {
        $files += [ordered]@{ name=$name; sha256=(Get-FileHash -LiteralPath (Join-Path $OutDir $name) -Algorithm SHA256).Hash }
    }
    $commit=& git -C $ctx.Repo rev-parse HEAD 2>$null
    if ($LASTEXITCODE -ne 0) { throw 'Backup provenance: git HEAD failed.' }
    $branch=& git -C $ctx.Repo branch --show-current 2>$null
    if ($LASTEXITCODE -ne 0) { throw 'Backup provenance: git branch failed.' }
    $dirty=& git -C $ctx.Repo status --porcelain 2>$null
    if ($LASTEXITCODE -ne 0) { throw 'Backup provenance: git status failed.' }
    $m=[ordered]@{version=2; created_at=[DateTimeOffset]::Now.ToString('o'); mysql_database='ecommerce_main_perf'; postgres_database='ecommerce_inventory_perf';
        mysql_dump='mysql.sql.gz'; postgres_dump='postgres.dump'; files=$files; mysql_fingerprint=$my; postgres_fingerprint=$pg;
        git_commit="$commit";git_branch="$branch";working_tree_dirty=[bool]$dirty;
        mysql_orders=($my|Where-Object {$_.table -eq 'orders'}).count;mysql_order_items=($my|Where-Object {$_.table -eq 'order_items'}).count;index_orders_created_at=$true;
        consistency='All Compose writers stopped across both snapshots. External writers/DDL must remain disconnected.'}
    $m | ConvertTo-Json -Depth 14 | Set-Content -LiteralPath (Join-Path $OutDir 'MANIFEST.json') -Encoding UTF8
    $null=Read-PerfManifest $OutDir
    # Cleanup and writer resume are part of success, not an ignored finally after printing OK.
    Remove-PerfTemp $temp; $temp=''
    if ($writers -contains 'mysql-replica') {
        $null=Invoke-PerfCompose $ctx @('--profile','replica','start','mysql-replica') 'resume backup replica'
        Wait-PerfReady { (Invoke-PerfSql $ctx mysql-replica 'SELECT 1;') -eq '1' } 180 'backup replica SQL'
        $null=Invoke-PerfSql $ctx mysql-replica 'START REPLICA;'
        Wait-PerfReady {
            $s=Invoke-PerfSql $ctx mysql-replica 'SHOW REPLICA STATUS\G'
            $s -match 'Replica_IO_Running:\s+Yes' -and $s -match 'Replica_SQL_Running:\s+Yes' -and $s -match 'Seconds_Behind_Source:\s+0\b'
        } 180 'backup replica catch-up'
    }
    foreach ($w in $writers) { if ($w -ne 'mysql-replica') { $null=Invoke-PerfCompose $ctx @('--profile','lifecycle','start',$w) 'resume backup writer' } }
} finally { if ($temp) { Remove-PerfTemp $temp } }
Write-Host "BACKUP OK: $OutDir"
