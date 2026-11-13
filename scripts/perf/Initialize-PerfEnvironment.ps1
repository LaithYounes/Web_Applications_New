#Requires -Version 5.1
[CmdletBinding()]
param([switch]$SkipBuild, [ValidateRange(0,5)][int]$BuildRetries=2,
      [string]$Project='ecommerce2026-perf', [string]$EnvFile='',
      [switch]$WithReplica, [switch]$WithLifecycle, [switch]$ReseedReplica,
      [ValidateRange(1,900)][int]$TimeoutSeconds=180)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'Perf.Common.ps1')
if (-not $EnvFile) {
    $EnvFile=Join-Path $PSScriptRoot '../../main-app/laravel/.env.perf'
    if (-not (Test-Path -LiteralPath $EnvFile)) { Copy-Item -LiteralPath (Join-Path $PSScriptRoot '../../main-app/laravel/.env.perf.example') -Destination $EnvFile }
}
$ctx=New-PerfContext $Project $EnvFile
$oldRead=$env:DB_READ_HOST
try {
    Set-PerfReadHost $ctx mysql
    $text=[IO.File]::ReadAllText($ctx.EnvFile)
    foreach ($spec in @(@('APP_KEY',32),@('JWT_SECRET',64))) {
        if ($text -notmatch ('(?m)^'+$spec[0]+'=\S+')) {
            $bytes=New-Object byte[] $spec[1]
            $rng=[Security.Cryptography.RandomNumberGenerator]::Create()
            try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
            $value=[Convert]::ToBase64String($bytes)
            if ($spec[0] -eq 'APP_KEY') { $value='base64:'+$value }
            $text=[regex]::Replace($text, '(?m)^'+$spec[0]+'=.*\r?\n?', '')
            $text=$text.TrimEnd()+[Environment]::NewLine+$spec[0]+'='+$value+[Environment]::NewLine
        }
    }
    [IO.File]::WriteAllText($ctx.EnvFile,$text,(New-Object Text.UTF8Encoding($false)))
    $null=Invoke-PerfCompose $ctx @('config','--quiet') 'compose validation'
    if (-not $SkipBuild) {
        for ($attempt=0; ; $attempt++) {
            try { $null=Invoke-PerfCompose $ctx @('build') 'build'; break }
            catch { if ($attempt -ge $BuildRetries) { throw } }
        }
    }
    Write-Host '[bootstrap] databases and SQL readiness'
    $null=Invoke-PerfCompose $ctx @('up','-d','mysql','inventory-db','redis') 'database startup'
    Wait-PerfReady { (Invoke-PerfSql $ctx mysql 'SELECT 1;') -eq '1' } $TimeoutSeconds 'primary SQL'
    Wait-PerfReady { (Invoke-PerfSql $ctx postgres 'SELECT 1;') -eq '1' } $TimeoutSeconds 'PostgreSQL SQL'
    Assert-PerfDatabases $ctx
    $null=Stop-PerfWriters $ctx
    Write-Host '[bootstrap] clear configuration, verify app destination, migrate (no seed)'
    $null=Invoke-PerfCompose $ctx @('run','--rm','--no-deps','-T','app','php','artisan','config:clear') 'config clear'
    $check=@'
<?php
require '/var/www/vendor/autoload.php';
$app=require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c=Illuminate\Support\Facades\DB::connection();
foreach ([$c->getPdo(),$c->getReadPdo()] as $pdo) {
 $r=$pdo->query('SELECT DATABASE() db, @@server_id sid')->fetch(PDO::FETCH_ASSOC);
 if ($r['db']!=='ecommerce_main_perf'||(int)$r['sid']!==1) exit(7);
}
echo "PRIMARY_OK\n";
'@
    $temp=New-PerfTemp
    try {
        $php=Join-Path $temp 'identity.php'
        [IO.File]::WriteAllText($php,$check,(New-Object Text.UTF8Encoding($false)))
        $result=Invoke-PerfCompose $ctx @('run','--rm','--no-deps','-T','app','php') 'app primary identity' -InputFile $php
        if ($result -ne 'PRIMARY_OK') { throw 'App primary identity check failed.' }
    } finally { Remove-PerfTemp $temp }
    $null=Invoke-PerfCompose $ctx @('run','--rm','--no-deps','-T','app','php','artisan','migrate','--force') 'migration'
    $null=Invoke-PerfCompose $ctx @('up','-d','--no-deps','--force-recreate','app','inventory-service') 'application recreate'
    if ($WithReplica) {
        & (Join-Path $PSScriptRoot 'replica/Initialize-MysqlReplica.ps1') -Project $Project -EnvFile $ctx.EnvFile -TimeoutSeconds $TimeoutSeconds -ReseedReplica:$ReseedReplica
    }
    if ($WithLifecycle) {
        $null=Invoke-PerfCompose $ctx @('--profile','lifecycle','up','-d','--no-deps','--force-recreate','checkout-recovery','notification-worker') 'lifecycle workers'
    }
    & (Join-Path $PSScriptRoot 'Validate-PerfEnvironment.ps1') -Project $Project -EnvFile $ctx.EnvFile -WithLifecycle:$WithLifecycle -TimeoutSeconds $TimeoutSeconds
    Write-Host 'INITIALIZE OK (no seed; replica outage fails closed; rerun without -WithReplica to select primary).'
} finally { $env:DB_READ_HOST=$oldRead }
