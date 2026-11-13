#Requires -Version 5.1
param([string]$EvidenceDirectory)
$ErrorActionPreference='Stop'
$repo=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$common=Join-Path $repo 'scripts/perf/Perf.Common.ps1'
$transfer=Join-Path $repo 'scripts/perf/transfer/Transfer.Common.ps1'
$script:results=@()
. $common
. $transfer
$root=New-PerfTemp
$script:ownedTemps=New-Object Collections.Generic.List[string]
$envfile=Join-Path $root 'test.env'
[IO.File]::WriteAllText($envfile,'DB_READ_HOST=mysql')
$archive=Join-Path $root 'نسخة fixture with spaces'
$null=New-Item -ItemType Directory -Path $archive
[IO.File]::WriteAllText((Join-Path $archive 'mysql.sql'),'CREATE TABLE fixture(id INT);')
$in=[IO.File]::OpenRead((Join-Path $archive 'mysql.sql'))
$out=[IO.File]::Create((Join-Path $archive 'بيانات MySQL.sql.gz'))
$zip=New-Object IO.Compression.GZipStream($out,[IO.Compression.CompressionMode]::Compress)
try{$in.CopyTo($zip)}finally{$zip.Dispose();$out.Dispose();$in.Dispose()}
[IO.File]::WriteAllText((Join-Path $archive 'مخزون PG.dump'),'fixture archive (model only)')
$script:fixtureMy=@([ordered]@{table='orders';count=3;schema='ABC';data='DEF'})
$script:fixturePg=@([ordered]@{table='inventory_stock';count=2;schema='GHI';data='JKL'})
$script:baseManifest=[ordered]@{version=2;mysql_database='ecommerce_main_perf';postgres_database='ecommerce_inventory_perf';
 mysql_dump='بيانات MySQL.sql.gz';postgres_dump='مخزون PG.dump';mysql_fingerprint=$script:fixtureMy;postgres_fingerprint=$script:fixturePg;
 files=@(@{name='بيانات MySQL.sql.gz';sha256=(Get-FileHash -LiteralPath (Join-Path $archive 'بيانات MySQL.sql.gz')).Hash},
         @{name='مخزون PG.dump';sha256=(Get-FileHash -LiteralPath (Join-Path $archive 'مخزون PG.dump')).Hash})}
$manifestFile=Join-Path $archive 'MANIFEST.json'
function Reset-Archive {
 $script:baseManifest | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath $manifestFile -Encoding UTF8
}
function Invoke-Subject([string]$Relative,[hashtable]$Parameters) {
 $file=Join-Path $repo $Relative
 $source=[IO.File]::ReadAllText($file)
 # Run original orchestration; replace only the external tools/DB boundary with the model.
 $source=[regex]::Replace($source,'(?m)^[ \t]*\. \(Join-Path \$PSScriptRoot ''(?:\.\./)?(?:Perf.Common.ps1|Transfer.Common.ps1|\.\./transfer/Transfer.Common.ps1)''\)\r?\n','')
 $source=$source.Replace('$PSScriptRoot',("'"+(Split-Path $file -Parent).Replace("'","''")+"'"))
 & ([scriptblock]::Create($source)) @Parameters
}
function Install-Model([string]$Fault) {
 $script:fault=$Fault; $script:events=New-Object Collections.Generic.List[string];$script:myLive=$false;$script:pgLive=$false
 $script:pgRestores=0;$script:fakeProbeAttempts=0
 $script:replicationConfigured=$false
 $script:realNew=(Get-Item Function:New-PerfTemp).ScriptBlock
 function script:New-PerfTemp { $path=& $script:realNew; $script:ownedTemps.Add($path); return $path }
 function script:Invoke-PerfNative {
  param([string[]]$Arguments,[string]$InputFile,[string]$OutputFile,[string]$Stage='docker',[int]$TimeoutSeconds=600)
  $script:events.Add($Stage)
  if($Stage -eq $script:fault){throw "Injected checked native failure: $Stage"}
  if($script:fault -eq 'route-and-fallback' -and $Stage -in @('replica routing recreate','primary fallback recreate')){throw "Injected recreate failure: $Stage"}
  if($Stage -eq 'SQL mysql-replica') {
   $query=$Arguments -join ' '
   if($query -match 'SELECT @@server_id'){return '2'}
   if($query -match 'COUNT\(\*\) FROM information_schema.tables'){return '0'}
   if($query -match 'SHOW REPLICA STATUS') {
    if($script:replicationConfigured){return "Replica_IO_Running: Yes; Replica_SQL_Running: Yes; Seconds_Behind_Source: 0"}
    return ''
   }
   if($query -match 'CHANGE REPLICATION SOURCE') {
    if($script:fault -eq 'replication-configure'){throw 'Injected replication configure failure'}
    $script:replicationConfigured=$true
   }
   if($query -match 'SELECT @@read_only'){return ('1'+[char]9+'1')}
  }
  if($script:fault -eq 'live-pg-restore' -and $Stage -eq 'PostgreSQL restore') {
   $script:pgRestores++
   if($script:pgRestores -eq 2){throw 'Injected live PostgreSQL restore failure'}
  }
  if($Stage -eq 'SQL mysql' -and ($Arguments -join ' ') -match 'COUNT\(\*\) FROM information_schema.tables') {
   if($script:fault -eq 'count-query'){throw 'Injected SQL failure'}
   if($script:fault -eq 'count-parsing'){return 'not-a-number'}
   if($script:fault -eq 'nonempty' -or $script:fault -eq 'old-200k'){return '9'}
   return '0'
  }
  if($Stage -eq 'SQL postgres' -and ($Arguments -join ' ') -match 'COUNT\(\*\) FROM pg_tables'){return '0'}
  if($Stage -eq 'SQL mysql' -and ($Arguments -join ' ') -match 'DROP DATABASE ecommerce_main_perf'){$script:myLive=$true}
  if($Stage -eq 'SQL postgres' -and ($Arguments -join ' ') -match 'DROP SCHEMA public'){$script:pgLive=$true}
  if($Stage -match '^SQL '){return '1'}
  if($OutputFile){
   $content=if($Stage -eq 'replica dump'){"-- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='mysql-bin.000001', SOURCE_LOG_POS=123;"}else{'model'}
   [IO.File]::WriteAllText($OutputFile,$content)
  }
  if($Stage -eq 'PostgreSQL dump copy'){[IO.File]::WriteAllText($Arguments[-1],'model pg dump')}
  return ''
 }
 function script:Assert-PerfIdentity {param($Context,[string]$Service) if($script:fault -eq 'identity'){throw 'Wrong destination project/volume'}; return 'model-container'}
 function script:Assert-PerfDatabases {param($Context) $null=Assert-PerfIdentity $Context mysql; if($script:fault -eq 'credentials'){throw 'Injected invalid SQL credentials'} }
 function script:Stop-PerfWriters {param($Context) $script:events.Add('writers-stopped'); return @('app','inventory-service')}
 function script:Export-PerfDatabases {param($Context,[string]$Directory) $script:events.Add('prestate-export');[IO.File]::WriteAllText((Join-Path $Directory 'mysql.sql.gz'),'prestate');[IO.File]::WriteAllText((Join-Path $Directory 'postgres.dump'),'prestate')}
 function script:Get-PerfFingerprint {
  param($Context,[string]$Engine,[string]$Database,[string]$WorkDir)
  if($script:fault -eq 'replica-missing-schema' -and $Engine -eq 'mysql-replica'){throw 'Injected missing replica schema'}
  if($Engine -eq 'postgres'){
   if($script:fault -eq 'pg-validation' -and $Database -eq 'ecommerce_inventory_perf'){return ,@([ordered]@{table='inventory_stock';count=2;schema='GHI';data='PARTIAL'})}
   return ,$script:fixturePg
  }
  if($script:fault -eq 'old-200k' -and $Database -like 'perf_stage_*'){return ,@([ordered]@{table='orders';count=200000;schema='ABC';data='OLD-DATA'})}
  return ,$script:fixtureMy
 }
 $script:realRemove=(Get-Item Function:Remove-PerfTemp).ScriptBlock
 function script:Remove-PerfTemp {param([string]$Directory) if($script:fault -eq 'cleanup'){throw 'Injected cleanup refusal'}; & $script:realRemove $Directory}
}
function Test-Case([string]$Name,[scriptblock]$Body,[bool]$ShouldFail=$true) {
 Write-Host "[fault] $Name"
 $caught=$false;$message=''
 $records=New-Object Collections.Generic.List[string]
 try { & $Body 6>&1 | ForEach-Object {$records.Add("$_")} } catch {$caught=$true;$message=$_.Exception.Message}
 $pass=$caught -eq $ShouldFail
 if($ShouldFail -and (($records -join ' ') -match '(RESTORE|BACKUP|INITIALIZE) OK')){$pass=$false}
 $script:results += [ordered]@{case=$Name;pass=$pass;expected_failure=$ShouldFail;observed_failure=$caught;message=$message;events=@($script:events)}
 if(-not $pass){throw "Fault case failed: $Name"}
}
try {
 foreach($spec in @(
  @('empty-valid','', $false),@('nonempty-no-overwrite','nonempty',$true),
  @('nonempty-allow-overwrite','nonempty',$false),@('count-query-fails','count-query',$true),
  @('count-parsing-fails','count-parsing',$true),@('invalid-credentials','credentials',$true),
  @('wrong-identity','identity',$true),@('mysql-import','MySQL import',$true),
  @('postgres-copy','PostgreSQL restore copy',$true),@('staged-pg-restore','PostgreSQL restore',$true),
  @('mysql-live-success-then-pg-fails','live-pg-restore',$true),@('pg-post-validation','pg-validation',$true),
  @('old-200k-cannot-hide-failure','old-200k',$true),@('cleanup-fails','cleanup',$true))) {
  . $common; . $transfer
  Reset-Archive
  Install-Model $spec[1]
  $params=@{BackupDir=$archive;Project='perf-r3-fault-model';EnvFile=$envfile;RecoveryDir=(Join-Path $root ('recovery-'+[guid]::NewGuid().ToString('N')))}
  if($spec[0] -ne 'nonempty-no-overwrite'){$params.AllowOverwrite=$true}
  Test-Case $spec[0] {Invoke-Subject 'scripts/perf/transfer/Restore-PerfDataset.ps1' $params} $spec[2]
  if($spec[0] -in @('count-query-fails','count-parsing-fails','invalid-credentials','wrong-identity','nonempty-no-overwrite') -and $script:myLive){throw 'Mutation before destination verification'}
 }
 . $common; . $transfer
 foreach($spec in @('missing-manifest','invalid-manifest','missing-file','checksum-mismatch','manifest-traversal','manifest-ads','manifest-missing-checksum','decompression')) {
  Reset-Archive
  $script:events=New-Object Collections.Generic.List[string]
  $m=[IO.File]::ReadAllText($manifestFile)|ConvertFrom-Json
  switch($spec){
   'missing-manifest' {Remove-Item -LiteralPath $manifestFile}
   'invalid-manifest' {[IO.File]::WriteAllText($manifestFile,'{invalid')}
   'missing-file' {$m.files[0].name='absent.sql.gz';$m.mysql_dump='absent.sql.gz'}
   'checksum-mismatch' {$m.files[0].sha256=('0'*64)}
   'manifest-traversal' {$m.files[0].name='../outside.sql.gz'}
   'manifest-ads' {$m.files[0].name='archive.sql.gz:stream'}
   'manifest-missing-checksum' {$m.files=@($m.files[0])}
   'decompression' {[IO.File]::WriteAllText((Join-Path $root 'bad.gz'),'corrupt')}
  }
  if($spec -notin @('missing-manifest','invalid-manifest','decompression')){$m|ConvertTo-Json -Depth 12|Set-Content -LiteralPath $manifestFile -Encoding UTF8}
  Test-Case $spec {
   if($spec -eq 'decompression'){Expand-PerfMysql (Join-Path $root 'bad.gz') (Join-Path $root 'bad.sql')}
   else {$null=Read-PerfManifest $archive}
  }
 }
 Reset-Archive
 Test-Case 'unicode-and-spaces' {$null=Read-PerfManifest $archive} $false
 $unowned=Join-Path $root 'unowned'
 $null=New-Item -ItemType Directory -Path $unowned
 [IO.File]::WriteAllText((Join-Path $unowned 'keep'),'keep')
 Test-Case 'cleanup-rejects-unowned' {Remove-PerfTemp $unowned}
 if(-not(Test-Path -LiteralPath (Join-Path $unowned 'keep'))){throw 'Unowned file was deleted'}
 Test-Case 'delayed-readiness' {Wait-PerfReady {$script:fakeProbeAttempts++;$script:fakeProbeAttempts -ge 3} 5 'injected delayed SQL'} $false
 Test-Case 'readiness-timeout' {Wait-PerfReady {$false} 1 'injected unavailable SQL'}
 Test-Case 'readiness-rejects-nonboolean-output' {Wait-PerfReady {'not-ready'} 1 'injected unexpected output'}
 foreach($phase in @('migration','config clear','database startup','application recreate','build')) {
  . $common;. $transfer;Install-Model $phase
  function Wait-PerfReady {param($Probe,$TimeoutSeconds,$Stage)}
  $p=@{Project='perf-r3-fault-model';EnvFile=$envfile;TimeoutSeconds=1;BuildRetries=0}
  if($phase -ne 'build'){$p.SkipBuild=$true}
  $script:nativeModel=(Get-Item Function:Invoke-PerfNative).ScriptBlock
  function Invoke-PerfNative {
   param([string[]]$Arguments,[string]$InputFile,[string]$OutputFile,[string]$Stage='docker')
   if($Stage -eq 'app primary identity'){return 'PRIMARY_OK'}
   & $script:nativeModel @PSBoundParameters
  }
  Test-Case ('bootstrap-'+$phase) {Invoke-Subject 'scripts/perf/Initialize-PerfEnvironment.ps1' $p}
 }
 foreach($phase in @('replica startup','replica dump','replica import','replication-configure','replica-missing-schema','replica routing recreate','route-and-fallback')) {
  . $common;. $transfer;Install-Model $phase
  Test-Case ('replica-'+$phase) {
   Invoke-Subject 'scripts/perf/replica/Initialize-MysqlReplica.ps1' @{Project='perf-r3-fault-model';EnvFile=$envfile;TimeoutSeconds=2}
  }
  if([IO.File]::ReadAllText($envfile) -notmatch 'DB_READ_HOST=mysql\r?\n') {throw 'Replica failure did not persist primary fallback'}
  if($phase -eq 'route-and-fallback' -and $script:events -notcontains 'stop unsafe app'){throw 'Unsafe app was not stopped after fallback recreation failed'}
 }
 foreach($phase in @('MySQL dump','PostgreSQL dump','PostgreSQL dump copy','dump cleanup','resume backup writer')) {
  . $common;. $transfer
  $export=(Get-Item Function:Export-PerfDatabases).ScriptBlock
  Install-Model $phase
  Set-Item -Path Function:Export-PerfDatabases -Value $export
  Test-Case ('backup-'+$phase) {
   Invoke-Subject 'scripts/perf/transfer/Backup-PerfDataset.ps1' @{Project='perf-r3-fault-model';EnvFile=$envfile;OutDir=(Join-Path $root ([guid]::NewGuid().ToString('N')))}
  }
 }
 foreach($state in @('paused','malformed')) {
  . $common;. $transfer
  $stop=(Get-Item Function:Stop-PerfWriters).ScriptBlock
  Install-Model ''
  Set-Item -Path Function:Stop-PerfWriters -Value $stop
  $script:inventoryState=$state
  $script:nativeModel=(Get-Item Function:Invoke-PerfNative).ScriptBlock
  function Invoke-PerfNative {
   param([string[]]$Arguments,[string]$InputFile,[string]$OutputFile,[string]$Stage='docker')
   if($Stage -eq 'writer discovery') {
    if($script:inventoryState -eq 'paused'){return '{"Service":"app","State":"paused"}'}
    return '{"State":"running"}'
   }
   & $script:nativeModel @PSBoundParameters
  }
  Test-Case ('writer-inventory-'+$state) { $null=Stop-PerfWriters (New-PerfContext 'perf-r3-fault-model' $envfile) }
 }
} finally {
 . $common;. $transfer
 if($script:results){
  $null=New-Item -ItemType Directory -Force -Path $EvidenceDirectory
  $script:results|ConvertTo-Json -Depth 15|Set-Content -LiteralPath (Join-Path $EvidenceDirectory 'fault-results.json') -Encoding UTF8
 }
 foreach($owned in $script:ownedTemps){if(Test-Path -LiteralPath $owned){Remove-PerfTemp $owned}}
 Remove-PerfTemp $root
}
Write-Host ("FAULT TESTS OK: "+$script:results.Count)
