#Requires -Version 5.1
[CmdletBinding()]
param([string]$EvidenceDirectory=(Join-Path $PSScriptRoot '../../docs/qa/round3-evidence'))
$ErrorActionPreference='Stop'
$repo=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
. (Join-Path $repo 'scripts/perf/Perf.Common.ps1')
. (Join-Path $repo 'scripts/perf/transfer/Transfer.Common.ps1')
$root=New-PerfTemp
$run='perf-r3-'+[guid]::NewGuid().ToString('N').Substring(0,10)
$contexts=@()
$results=@()
$cleanup=@()
$null=New-Item -ItemType Directory -Force -Path $EvidenceDirectory
$EvidenceDirectory=[IO.Path]::GetFullPath($EvidenceDirectory)
$demoBefore=Invoke-PerfNative @('ps','-a','--filter','label=com.docker.compose.project=ecommerce2026','--format','{{.ID}} {{.Names}} {{.Status}}') -Stage 'demo read-only snapshot'
$protected=@('main-app/laravel/.env','main-app/laravel/.env.perf','.git/index','.git/HEAD','.git/MERGE_HEAD')
$hashBefore=@{}
foreach($p in $protected){$full=Join-Path $repo $p;if(Test-Path -LiteralPath $full){$hashBefore[$p]=(Get-FileHash -LiteralPath $full).Hash}}
function Record([string]$Case,[scriptblock]$Body,[bool]$ExpectedFailure=$false){
 $watch=[Diagnostics.Stopwatch]::StartNew()
 $failed=$false;$errorText=''
 Write-Host "[test] $Case"
 try{& $Body}catch{$failed=$true;$errorText=$_.Exception.Message}
 $script:results += [ordered]@{case=$Case;pass=($failed -eq $ExpectedFailure);expected_failure=$ExpectedFailure;observed_failure=$failed;message=$errorText;seconds=[math]::Round($watch.Elapsed.TotalSeconds,2)}
 $script:results|ConvertTo-Json -Depth 8|Set-Content -LiteralPath (Join-Path $EvidenceDirectory 'integration-results.json') -Encoding UTF8
 if($failed -ne $ExpectedFailure){throw "Integration case failed: $Case; $errorText"}
}
function Make-Context([string]$Suffix){
 $project=$run+'-'+$Suffix
 $file=Join-Path $root ($Suffix+'.env')
 $text=[IO.File]::ReadAllText((Join-Path $repo 'main-app/laravel/.env.perf.example'))
 $text += [Environment]::NewLine+(@('PERF_APP_IMAGE=ecommerce2026-app','PERF_INVENTORY_IMAGE=ecommerce2026-inventory-service','PERF_MYSQL_PORT=0','PERF_PG_PORT=0','PERF_HTTP_PORT=0','PERF_GRPC_PORT=0','PERF_REDIS_PORT=0','PERF_REPLICA_PORT=0') -join [Environment]::NewLine)+[Environment]::NewLine
 [IO.File]::WriteAllText($file,$text,(New-Object Text.UTF8Encoding($false)))
 New-PerfContext $project $file
}
try{
 $src=Make-Context 'source';$contexts+=,$src
 Record 'fresh-empty-owned-resources' {
  $null=Invoke-PerfCompose $src @('up','-d','mysql','inventory-db','redis') 'fresh database startup'
  Wait-PerfReady {(Invoke-PerfSql $src mysql 'SELECT 1;') -eq '1'} 180 'fresh primary SQL'
  Wait-PerfReady {(Invoke-PerfSql $src postgres 'SELECT 1;') -eq '1'} 180 'fresh PostgreSQL SQL'
  Assert-PerfDatabases $src
  if((Invoke-PerfSql $src mysql 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE();') -ne '0'){throw 'MySQL not empty'}
  if((Invoke-PerfSql $src postgres "SELECT COUNT(*) FROM pg_tables WHERE schemaname='public';") -ne '0'){throw 'PG not empty'}
 }
 # Existing local runtime image has older vendor; prepare ONLY the new task-owned vendor volume.
 Record 'prepare-isolated-runtime-dependencies' {
  $null=Invoke-PerfCompose $src @('run','--rm','--no-deps','-T','app','composer','install','--no-interaction','--no-scripts','--no-plugins','--prefer-dist') 'isolated Composer preparation'
 }
 Record 'fresh-bootstrap-without-replica-and-workers' {
  & (Join-Path $repo 'scripts/perf/Initialize-PerfEnvironment.ps1') -SkipBuild -Project $src.Project -EnvFile $src.EnvFile -WithLifecycle -TimeoutSeconds 180
  if(Invoke-PerfCompose $src @('ps','-a','-q','mysql-replica') 'no replica assertion'){throw 'Replica unexpectedly created'}
 }
 Record 'fixture-known-both-databases' {
  $null=Stop-PerfWriters $src
  $null=Invoke-PerfSql $src mysql @"
INSERT INTO users(id,name,email,password,role,created_at,updated_at) VALUES(1,'Round3 fixture','r3@example.test','not-a-login-hash','customer','2026-01-01','2026-01-01');
INSERT INTO categories(id,name,created_at,updated_at) VALUES(1,'Fixture category','2026-01-01','2026-01-01');
INSERT INTO products(id,category_id,name,description,price,image_path,created_at,updated_at) VALUES(1,1,'منتج اختبار عربي','fixture',12.50,'fixture.png','2026-01-01','2026-01-01');
INSERT INTO orders(id,user_id,status,total_amount,reservation_id,created_at,updated_at) VALUES(1,1,'pending',25,'r3-fixture','2026-01-01','2026-01-01');
INSERT INTO order_items(id,order_id,product_id,product_name,unit_price,quantity,subtotal,created_at,updated_at) VALUES(1,1,1,'منتج اختبار عربي',12.5,2,25,'2026-01-01','2026-01-01');
"@
  $null=Invoke-PerfSql $src postgres @"
INSERT INTO inventory_stock(product_id,total_quantity,reserved_quantity,created_at,updated_at) VALUES(1,10,2,'2026-01-01','2026-01-01');
INSERT INTO inventory_reservations(reservation_id,product_id,quantity,status,created_at,updated_at) VALUES('r3-fixture',1,2,'ACTIVE','2026-01-01','2026-01-01');
"@
 }
 Record 'restart-preserves-fixture-and-resumes-initialization' {
  & (Join-Path $repo 'scripts/perf/Initialize-PerfEnvironment.ps1') -SkipBuild -Project $src.Project -EnvFile $src.EnvFile -WithLifecycle -TimeoutSeconds 90
  if((Invoke-PerfSql $src mysql "SELECT COUNT(*) FROM orders WHERE reservation_id='r3-fixture';") -ne '1'){throw 'Fixture lost'}
 }
 Record 'uninitialized-incomplete-replica-refused' {
  $null=Invoke-PerfCompose $src @('--profile','replica','up','-d','mysql-replica') 'incomplete replica startup'
  Wait-PerfReady {(Invoke-PerfSql $src mysql-replica 'SELECT 1;') -eq '1'} 180 'incomplete replica SQL'
  $null=Invoke-PerfSql $src mysql-replica 'CREATE TABLE incomplete(id INT);'
  & (Join-Path $repo 'scripts/perf/replica/Initialize-MysqlReplica.ps1') -Project $src.Project -EnvFile $src.EnvFile -TimeoutSeconds 30
 } $true
 Record 'initialize-replica-then-activate-read-identity' {
  & (Join-Path $repo 'scripts/perf/replica/Initialize-MysqlReplica.ps1') -Project $src.Project -EnvFile $src.EnvFile -ReseedReplica -TimeoutSeconds 90
 }
 Record 'replica-outage-fails-closed' {
  $null=Invoke-PerfCompose $src @('--profile','replica','stop','mysql-replica') 'test replica outage'
  & (Join-Path $repo 'scripts/perf/Validate-PerfEnvironment.ps1') -Project $src.Project -EnvFile $src.EnvFile -TimeoutSeconds 3
 } $true
 Record 'explicit-primary-fallback-preserves-fixture' {
  & (Join-Path $repo 'scripts/perf/Initialize-PerfEnvironment.ps1') -SkipBuild -Project $src.Project -EnvFile $src.EnvFile -WithLifecycle -TimeoutSeconds 90
  if((Invoke-PerfSql $src mysql 'SELECT COUNT(*) FROM orders;') -ne '1'){throw 'Orders lost'}
 }
 $backup=Join-Path $root 'نسخة احتياطية with spaces'
 Record 'real-backup-consistent-both-fixtures' {
  & (Join-Path $repo 'scripts/perf/transfer/Backup-PerfDataset.ps1') -Project $src.Project -EnvFile $src.EnvFile -OutDir $backup
  # Exercise Unicode leaf names too; hashes still cover the same bytes.
  $m=Read-PerfManifest $backup
  Move-Item -LiteralPath (Join-Path $backup $m.mysql_dump) -Destination (Join-Path $backup 'بيانات MySQL.sql.gz')
  Move-Item -LiteralPath (Join-Path $backup $m.postgres_dump) -Destination (Join-Path $backup 'مخزون PG.dump')
  $m.files[0].name='بيانات MySQL.sql.gz';$m.files[1].name='مخزون PG.dump'
  $m.mysql_dump='بيانات MySQL.sql.gz';$m.postgres_dump='مخزون PG.dump'
  $m|ConvertTo-Json -Depth 14|Set-Content -LiteralPath (Join-Path $backup 'MANIFEST.json') -Encoding UTF8
  $null=Read-PerfManifest $backup
 }
 $dst=Make-Context 'target';$contexts+=,$dst
 Record 'real-round-trip-into-empty-resources' {
  & (Join-Path $repo 'scripts/perf/transfer/Restore-PerfDataset.ps1') -Project $dst.Project -EnvFile $dst.EnvFile -BackupDir $backup -RecoveryDir (Join-Path $root 'recovery-empty')
  if((Invoke-PerfSql $dst mysql "SELECT CONCAT(name,'|',price) FROM products WHERE id=1;") -ne 'منتج اختبار عربي|12.50'){throw 'Unicode product/price mismatch'}
  if((Invoke-PerfSql $dst postgres "SELECT total_quantity||'|'||reserved_quantity FROM inventory_stock WHERE product_id=1;") -ne '10|2'){throw 'Inventory mismatch'}
  if((Invoke-PerfSql $dst postgres "SELECT status||'|'||quantity FROM inventory_reservations WHERE reservation_id='r3-fixture';") -ne 'ACTIVE|2'){throw 'Reservation mismatch'}
 }
 Record 'real-nonempty-destination-refused' {
  & (Join-Path $repo 'scripts/perf/transfer/Restore-PerfDataset.ps1') -Project $dst.Project -EnvFile $dst.EnvFile -BackupDir $backup
 } $true
 Record 'real-overwrite-and-retry-after-partial-state' {
  # A separate real partial destination state, entirely owned by this test.
  $null=Invoke-PerfSql $dst postgres 'DELETE FROM inventory_reservations;'
  & (Join-Path $repo 'scripts/perf/transfer/Restore-PerfDataset.ps1') -Project $dst.Project -EnvFile $dst.EnvFile -BackupDir $backup -AllowOverwrite -RecoveryDir (Join-Path $root 'recovery-retry')
  if((Invoke-PerfSql $dst postgres 'SELECT COUNT(*) FROM inventory_reservations;') -ne '1'){throw 'Retry failed'}
 }
 Record 'checked-native-exit-code' {
  $id=Assert-PerfIdentity $dst mysql
  $null=Invoke-PerfNative @('exec',$id,'sh','-c','exit 47') -Stage 'expected-native-exit-47'
 } $true
 Record 'demo-environment-and-git-index-unchanged' {
  foreach($p in $hashBefore.Keys){if((Get-FileHash -LiteralPath (Join-Path $repo $p)).Hash -ne $hashBefore[$p]){throw "Protected file changed: $p"}}
  $beforeIds=@($demoBefore -split '\r?\n' | ForEach-Object {($_ -split ' ')[0]})|Sort-Object
  $after=Invoke-PerfNative @('ps','-a','--filter','label=com.docker.compose.project=ecommerce2026','--format','{{.ID}}') -Stage 'demo after'
  if(($beforeIds -join ',') -ne ((@($after -split '\r?\n')|Sort-Object) -join ',')){throw 'Demo containers changed'}
 }
}finally{
 foreach($c in $contexts){
  try {
   # Generated project names never existed before this run. Inspect all identities/volume labels first.
   $ids=Invoke-PerfCompose $c @('--profile','replica','--profile','lifecycle','ps','-a','-q') 'cleanup inventory'
   foreach($id in ($ids -split '\r?\n'|Where-Object{$_})){
    $obj=@(ConvertFrom-Json (Invoke-PerfNative @('inspect',$id) -Stage 'cleanup ownership'))[0]
    if($obj.Config.Labels.'com.docker.compose.project' -ne $c.Project){throw 'Foreign container during cleanup'}
    foreach($v in $obj.Mounts|Where-Object{$_.Type -eq 'volume'}){
     $label=Invoke-PerfNative @('volume','inspect','--format','{{index .Labels "com.docker.compose.project"}}',$v.Name) -Stage 'cleanup volume ownership'
     if($label -ne $c.Project){throw 'Foreign volume during cleanup'}
    }
   }
   $null=Invoke-PerfCompose $c @('--profile','replica','--profile','lifecycle','down','-v','--remove-orphans') 'cleanup task-only resources'
   $cleanup += @{project=$c.Project;removed=$true}
  }catch{$cleanup += @{project=$c.Project;removed=$false;message=$_.Exception.Message}}
 }
 $cleanup|ConvertTo-Json -Depth 5|Set-Content -LiteralPath (Join-Path $EvidenceDirectory 'integration-cleanup.json') -Encoding UTF8
 Remove-PerfTemp $root
}
if(@($cleanup|Where-Object{-not $_.removed}).Count){throw 'Task resource cleanup incomplete'}
Write-Host ("INTEGRATION OK: "+$results.Count)
