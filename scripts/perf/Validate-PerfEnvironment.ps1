#Requires -Version 5.1
<#
.SYNOPSIS
  Validates the isolated performance stack without touching demo services.
#>
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')
Set-Location $RepoRoot

$ComposeFile = Join-Path $RepoRoot 'docker-compose.perf.yml'
$Project = 'ecommerce2026-perf'
$BaseUrl = 'http://127.0.0.1:8010'
$Failures = @()

function Assert-True($Condition, $Message) {
  if (-not $Condition) {
    $script:Failures += $Message
    Write-Host "FAIL: $Message" -ForegroundColor Red
  } else {
    Write-Host "PASS: $Message" -ForegroundColor Green
  }
}

function Invoke-AppPhp([string]$Code) {
  # Pass PHP source via stdin; no host file writes.
  $Code | & docker compose -p $Project -f $ComposeFile exec -T app php
  return $LASTEXITCODE
}

Write-Host "=== Performance environment validation ==="

# 1) Containers running
$psJson = docker compose -p $Project -f $ComposeFile ps --format json 2>&1 | Out-String
Assert-True ($LASTEXITCODE -eq 0) "compose ps for $Project succeeded"
Assert-True ($psJson -match 'ecommerce2026-perf-main-app') "perf app container listed"
Assert-True ($psJson -match 'ecommerce2026-perf-mysql') "perf mysql container listed"
Assert-True ($psJson -match 'ecommerce2026-perf-inventory') "perf inventory containers listed"

# 2) Volume isolation
$volumes = docker volume ls --format '{{.Name}}' | Out-String
Assert-True ($volumes -match 'ecommerce2026_perf_mysql_data') "perf MySQL volume exists"
Assert-True ($volumes -match 'ecommerce2026_perf_postgres_data') "perf Postgres volume exists"
Assert-True ($volumes -match 'ecommerce2026_perf_laravel_storage') "perf storage volume exists"
Assert-True ($volumes -match 'ecommerce2026_perf_laravel_bootstrap_cache') "perf bootstrap/cache volume exists"

# 3) Port isolation
$perfPorts = docker compose -p $Project -f $ComposeFile port app 8000
Assert-True ($perfPorts -match '8010') "Laravel published on 8010 (not 8000)"
$grpcPort = docker compose -p $Project -f $ComposeFile port inventory-service 9090
Assert-True ($grpcPort -match '9100') "Inventory gRPC published on 9100 (not 9090)"
Assert-True ($perfPorts -notmatch ':8000$') "Perf app is not published on host :8000"

# 4) Mount isolation evidence
$inspect = docker inspect ecommerce2026-perf-main-app | Out-String
Assert-True ($inspect -match 'ecommerce2026_perf_laravel_storage') "app mounts perf storage volume"
Assert-True ($inspect -match 'ecommerce2026_perf_laravel_bootstrap_cache') "app mounts perf bootstrap/cache volume"
Assert-True ($inspect -match '\.env\.perf') "app mounts .env.perf over /var/www/.env"
Assert-True ($inspect -notmatch 'ecommerce2026_mysql_data') "app does not mount demo mysql volume"

# 5) Process env + effective Laravel DB
$dbNameEnv = (docker compose -p $Project -f $ComposeFile exec -T app printenv DB_DATABASE | Out-String).Trim()
Assert-True ($dbNameEnv -eq 'ecommerce_main_perf') "process env DB_DATABASE=ecommerce_main_perf"

$phpDbCheck = @'
<?php
require "/var/www/vendor/autoload.php";
$app = require "/var/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$cfg = config("database.connections.mysql.database");
$pdo = Illuminate\Support\Facades\DB::connection()->getDatabaseName();
$row = Illuminate\Support\Facades\DB::select("select database() as d")[0]->d;
echo "DBCHECK:$cfg|$pdo|$row\n";
exit(($cfg === "ecommerce_main_perf" && $pdo === "ecommerce_main_perf" && $row === "ecommerce_main_perf") ? 0 : 2);
'@
$dbOut = $phpDbCheck | docker compose -p $Project -f $ComposeFile exec -T app php 2>&1 | Out-String
Assert-True (($LASTEXITCODE -eq 0) -and ($dbOut -match 'DBCHECK:ecommerce_main_perf\|ecommerce_main_perf\|ecommerce_main_perf')) "Laravel effective DB is ecommerce_main_perf"

# 6) DB engine health
docker compose -p $Project -f $ComposeFile exec -T mysql mysqladmin ping -h localhost -u root -pperf_root_password | Out-Null
Assert-True ($LASTEXITCODE -eq 0) "MySQL ping inside perf container"
docker compose -p $Project -f $ComposeFile exec -T inventory-db pg_isready -U inventory_perf_user -d ecommerce_inventory_perf | Out-Null
Assert-True ($LASTEXITCODE -eq 0) "Postgres ready for ecommerce_inventory_perf"

# 7) Migrations status (connection proof)
docker compose -p $Project -f $ComposeFile exec -T app php artisan migrate:status | Out-Null
Assert-True ($LASTEXITCODE -eq 0) "artisan migrate:status against perf MySQL"

# 8) HTTP health
try {
  $health = Invoke-RestMethod "$BaseUrl/api/v1/health"
  Assert-True ($health.success -eq $true -and $health.data.status -eq 'ok') "GET /api/v1/health => ok"
} catch {
  Assert-True $false "GET /api/v1/health failed: $($_.Exception.Message)"
}

# 9) Inventory JDBC target
$jdbc = (docker compose -p $Project -f $ComposeFile exec -T inventory-service printenv DB_URL | Out-String).Trim()
Assert-True ($jdbc -match 'ecommerce_inventory_perf') "Inventory JDBC uses ecommerce_inventory_perf"

$invLogs = docker compose -p $Project -f $ComposeFile logs --tail 120 inventory-service | Out-String
Assert-True ($invLogs -match 'gRPC Server started|Started InventoryServiceApplication|Netty started|grpc') "Inventory service appears started (log probe)"

# 10) gRPC transport + RPC success
# Java GetStock returns 0 when product is absent (not an error). Treat quantity>=0 as RPC success.
$phpGrpc = @'
<?php
require "/var/www/vendor/autoload.php";
$app = require "/var/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $qty = $app->make(App\Modules\Inventory\Services\InventoryGrpcClient::class)->getStock(1);
    if (!is_int($qty) && !is_numeric($qty)) {
        fwrite(STDERR, "GRPC_BAD_TYPE\n");
        exit(3);
    }
    echo "GRPC_OK:" . (int)$qty . "\n";
    exit(0);
} catch (App\Modules\Inventory\Exceptions\InventoryServiceException $e) {
    fwrite(STDERR, "GRPC_TRANSPORT_FAIL\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "GRPC_OTHER_FAIL:" . $e::class . "\n");
    exit(2);
}
'@
$grpcOut = $phpGrpc | docker compose -p $Project -f $ComposeFile exec -T app php 2>&1 | Out-String
Assert-True (($LASTEXITCODE -eq 0) -and ($grpcOut -match 'GRPC_OK:\d+')) "gRPC GetStock RPC succeeded (missing SKU => 0 is OK)"

# 11) Redis cache store
$redisPing = (docker compose -p $Project -f $ComposeFile exec -T redis redis-cli PING 2>$null | Out-String).Trim()
Assert-True ($redisPing -match 'PONG') "Redis PONG"
$cacheStore = (docker compose -p $Project -f $ComposeFile exec -T app printenv CACHE_STORE | Out-String).Trim()
Assert-True ($cacheStore -eq 'redis') "CACHE_STORE=redis"

# 12) Optional replica (profile replica)
$replicaRunning = docker ps --filter 'name=ecommerce2026-perf-mysql-replica' --format '{{.Names}}' | Out-String
if ($replicaRunning -match 'ecommerce2026-perf-mysql-replica') {
  $readHost = (docker compose -p $Project -f $ComposeFile exec -T app printenv DB_READ_HOST | Out-String).Trim()
  Assert-True ($readHost -eq 'mysql-replica') "DB_READ_HOST=mysql-replica while replica container is up"
  $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  $rStatus = docker exec ecommerce2026-perf-mysql-replica mysql -uroot -pperf_root_password -e "SHOW REPLICA STATUS\G" 2>$null | Out-String
  $ErrorActionPreference = $prev
  Assert-True ($rStatus -match 'Replica_IO_Running:\s+Yes' -and $rStatus -match 'Replica_SQL_Running:\s+Yes') "Replica IO/SQL running"
} else {
  Write-Host 'Info: mysql-replica not running (start with --profile replica after Initialize-MysqlReplica.ps1)'
}

# 13) Demo project informational probe
docker compose -p ecommerce2026 ps 2>$null | Out-Null
Write-Host "Info: demo project ecommerce2026 status probe exit=$LASTEXITCODE"

if ($Failures.Count -gt 0) {
  Write-Host "`nValidation FAILED with $($Failures.Count) issue(s)." -ForegroundColor Red
  $Failures | ForEach-Object { Write-Host " - $_" }
  exit 1
}

Write-Host "`nValidation PASSED for isolated performance environment." -ForegroundColor Green
exit 0
