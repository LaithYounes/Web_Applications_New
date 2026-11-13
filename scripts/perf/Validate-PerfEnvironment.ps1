#Requires -Version 5.1
[CmdletBinding()]
param([string]$Project='ecommerce2026-perf',[string]$EnvFile='', [switch]$WithLifecycle,
      [ValidateRange(1,900)][int]$TimeoutSeconds=180)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'Perf.Common.ps1')
$ctx=New-PerfContext $Project $EnvFile
Assert-PerfDatabases $ctx
foreach ($s in @('app','inventory-service','redis')) { $null=Assert-PerfIdentity $ctx $s }
$probe=@'
<?php
require '/var/www/vendor/autoload.php';
$app=require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
 $c=Illuminate\Support\Facades\DB::connection();
 $w=$c->getPdo()->query('SELECT DATABASE() db,@@server_id sid')->fetch(PDO::FETCH_ASSOC);
 $r=$c->getReadPdo()->query('SELECT DATABASE() db,@@server_id sid')->fetch(PDO::FETCH_ASSOC);
 $host=config('database.connections.mysql.read.host.0');
 $expected=$host==='mysql'?1:($host==='mysql-replica'?2:0);
 if($w['db']!=='ecommerce_main_perf'||$r['db']!=='ecommerce_main_perf'||(int)$w['sid']!==1||(int)$r['sid']!==$expected||!$expected) exit(3);
 foreach(['migrations','orders','order_items','pending_checkouts','order_status_histories','order_notifications'] as $table) {
   $c->getReadPdo()->query("SELECT 1 FROM $table LIMIT 1");
 }
 $qty=$app->make(App\Modules\Inventory\Services\InventoryGrpcClient::class)->getStock(1);
 if(!is_numeric($qty)||$qty<0) exit(4);
 Illuminate\Support\Facades\Cache::get('perf-readiness-probe');
 if(env('ORDER_PUSH_DRIVER')!=='disabled') exit(5);
 echo 'ROUTING_OK:write='.$w['sid'].'|read='.$r['sid'];
} catch(Throwable $e) { fwrite(STDERR,"READINESS_FAILED\n"); exit(8); }
'@
Wait-PerfReady {
    $script:routing=Invoke-PerfPhp $ctx $probe
    $script:routing -match '^ROUTING_OK:write=1\|read=[12]$'
} $TimeoutSeconds 'Laravel schema/read-write/gRPC/Redis'
Write-Host $script:routing
$published=Invoke-PerfCompose $ctx @('port','app','8000') 'HTTP port identity'
if ($published -notmatch '^127\.0\.0\.1:(\d+)$' -or [int]$Matches[1] -in @(8000,3307,5433,9090)) { throw 'Unsafe HTTP publication.' }
$url='http://'+$published+'/api/v1/health'
Wait-PerfReady {
    try { $health=Invoke-RestMethod -Uri $url -TimeoutSec 5; $health.success -eq $true -and $health.data.status -eq 'ok' } catch { $false }
} $TimeoutSeconds 'HTTP health'
if ($script:routing -match 'read=2$') {
    $status=Invoke-PerfSql $ctx mysql-replica 'SHOW REPLICA STATUS\G'
    if ($status -notmatch 'Replica_IO_Running:\s+Yes' -or $status -notmatch 'Replica_SQL_Running:\s+Yes' -or $status -notmatch 'Seconds_Behind_Source:\s+0\b') { throw 'Replica is not healthy/caught up. Reads fail closed; explicitly select primary to recover.' }
}
if ($WithLifecycle) {
    foreach ($s in @('checkout-recovery','notification-worker')) {
        $id=Assert-PerfIdentity $ctx $s
        $obj=@(ConvertFrom-Json (Invoke-PerfNative @('inspect',$id) -Stage 'worker inspect'))[0]
        if (-not $obj.State.Running -or $obj.Config.Env -notcontains 'ORDER_PUSH_DRIVER=disabled') { throw 'Required worker not running safely.' }
        $cmd=$obj.Config.Cmd -join ' '
        if (($s -eq 'checkout-recovery' -and $cmd -notmatch 'artisan schedule:work') -or
            ($s -eq 'notification-worker' -and $cmd -notmatch 'queue:work notifications')) { throw 'Incorrect worker command.' }
    }
    $null=Invoke-PerfCompose $ctx @('exec','-T','checkout-recovery','php','artisan','orders:recover-checkouts','--min-age=0') 'recovery worker command'
}
Write-Host 'VALIDATION OK'
