#Requires -Version 5.1
param([string]$EvidenceDirectory=(Join-Path $PSScriptRoot '../../docs/qa/round3-evidence'))
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '../perf/Perf.Common.ps1')
. (Join-Path $PSScriptRoot '../perf/transfer/Transfer.Common.ps1')
$temp=New-PerfTemp
$project='perf-r3-pg-'+[guid]::NewGuid().ToString('N').Substring(0,8)
$file=Join-Path $temp 'test.env'
$example=Join-Path $PSScriptRoot '../../main-app/laravel/.env.perf.example'
$text=[IO.File]::ReadAllText($example)+[Environment]::NewLine+"PERF_PG_PORT=0"+[Environment]::NewLine+"PERF_GRPC_PORT=0"+[Environment]::NewLine+"PERF_INVENTORY_IMAGE=ecommerce2026-inventory-service"
[IO.File]::WriteAllText($file,$text)
$c=New-PerfContext $project $file
try {
 $null=Invoke-PerfCompose $c @('up','-d','inventory-db','inventory-service') 'PG fixture startup'
 Wait-PerfReady {(Invoke-PerfSql $c postgres "SELECT COUNT(*) FROM pg_tables WHERE schemaname='public';") -eq '2'} 180 'PG Hibernate schema'
 $null=Invoke-PerfCompose $c @('stop','inventory-service') 'quiesce PG fixture'
 $null=Invoke-PerfSql $c postgres "INSERT INTO inventory_stock(product_id,total_quantity,reserved_quantity,created_at,updated_at) VALUES(1,10,2,'2026-01-01','2026-01-01'); INSERT INTO inventory_reservations(reservation_id,product_id,quantity,status,created_at,updated_at) VALUES('r3-fixture',1,2,'ACTIVE','2026-01-01','2026-01-01');"
 $before=Get-PerfFingerprint $c postgres 'ecommerce_inventory_perf' $temp
 $schemaSql=@"
SELECT column_name,ordinal_position,data_type,is_nullable,column_default,character_maximum_length FROM information_schema.columns WHERE table_schema='public' AND table_name='inventory_reservations' ORDER BY ordinal_position;
SELECT conname,pg_get_constraintdef(oid),convalidated FROM pg_constraint WHERE conrelid='public.inventory_reservations'::regclass ORDER BY conname;
SELECT indexname,indexdef FROM pg_indexes WHERE schemaname='public' AND tablename='inventory_reservations' ORDER BY indexname;
"@
 Invoke-PerfSql $c postgres $schemaSql | Set-Content -LiteralPath (Join-Path $EvidenceDirectory 'pg-schema-before.txt') -Encoding UTF8
 $id=Assert-PerfIdentity $c inventory-db
 $remote='/tmp/perf-owned-'+[guid]::NewGuid().ToString('N')+'.dump'
 $dump=Join-Path $temp 'postgres.dump'
 try{
  $null=Invoke-PerfCompose $c @('exec','-T','inventory-db','pg_dump','-U','inventory_perf_user','-d','ecommerce_inventory_perf','--no-owner','--no-privileges','-Fc','-f',$remote) 'PG debug dump'
  $null=Invoke-PerfNative @('cp',($id+':'+$remote),$dump) -Stage 'PG debug copy'
 }finally{$null=Invoke-PerfCompose $c @('exec','-T','inventory-db','rm','-f',$remote) 'PG debug remote cleanup'}
 $null=Invoke-PerfSql $c postgres 'CREATE DATABASE perf_stage_debug;'
 Import-PerfPostgres $c perf_stage_debug $dump
 $after=Get-PerfFingerprint $c postgres perf_stage_debug $temp
 Invoke-PerfSql $c postgres $schemaSql perf_stage_debug | Set-Content -LiteralPath (Join-Path $EvidenceDirectory 'pg-schema-after.txt') -Encoding UTF8
 $before|ConvertTo-Json -Depth 10|Set-Content -LiteralPath (Join-Path $EvidenceDirectory 'pg-fingerprint-before.json') -Encoding UTF8
 $after|ConvertTo-Json -Depth 10|Set-Content -LiteralPath (Join-Path $EvidenceDirectory 'pg-fingerprint-after.json') -Encoding UTF8
 Assert-PerfFingerprint $before $after 'PG isolated round-trip'
 $tests=@(@{case='postgres-schema-data-sequence-roundtrip';pass=$true})
 $null=Invoke-PerfSql $c postgres "ALTER TABLE inventory_reservations DROP CONSTRAINT inventory_reservations_status_check; ALTER TABLE inventory_reservations ADD CONSTRAINT inventory_reservations_status_check CHECK (status <> 'BROKEN');" perf_stage_debug
 $rejected=$false
 try{$null=Get-PerfFingerprint $c postgres perf_stage_debug $temp}catch{$rejected=$_.Exception.Message -match 'status constraint'}
 if(-not $rejected){throw 'Changed enum constraint accepted'}
 $tests+=@{case='changed-status-constraint-rejected';pass=$true}
 $null=Invoke-PerfSql $c postgres "ALTER TABLE inventory_reservations DROP CONSTRAINT inventory_reservations_status_check; ALTER TABLE inventory_reservations ADD CONSTRAINT inventory_reservations_status_check CHECK (status IN ('ACTIVE','RELEASED','COMMITTED')); ALTER TABLE inventory_stock DROP CONSTRAINT chk_inventory_reserved_quantity;" perf_stage_debug
 $rejected=$false
 try{$null=Get-PerfFingerprint $c postgres perf_stage_debug $temp}catch{$rejected=$_.Exception.Message -match 'Required inventory'}
 if(-not $rejected){throw 'Missing inventory constraint accepted'}
 $tests+=@{case='missing-quantity-constraint-rejected';pass=$true}
 $tests|ConvertTo-Json|Set-Content -LiteralPath (Join-Path $EvidenceDirectory 'pg-roundtrip-results.json') -Encoding UTF8
}finally{
 $null=Invoke-PerfCompose $c @('down','-v','--remove-orphans') 'cleanup owned PG roundtrip'
 Remove-PerfTemp $temp
}
Write-Host 'PG ROUNDTRIP OK'
