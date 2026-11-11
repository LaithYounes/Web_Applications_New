#Requires -Version 5.1
[CmdletBinding()]
param([switch]$SkipPull)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot

function Invoke-Checked {
    param([string]$Program, [string[]]$CommandArguments)

    & $Program @CommandArguments | Out-Host
    if ($LASTEXITCODE -ne 0) {
        throw "$Program $($CommandArguments -join ' ') failed (exit $LASTEXITCODE)."
    }
}

Push-Location -LiteralPath $projectRoot
try {
    Get-Command git, docker -ErrorAction Stop | Out-Null
    if (-not (Test-Path -LiteralPath 'main-app/laravel/.env')) {
        throw 'Complete the first-time setup in README.md before using this updater.'
    }

    if (-not $SkipPull) {
        $localChanges = & git status --porcelain --untracked-files=all
        if ($LASTEXITCODE -ne 0) { throw 'Could not read Git status.' }
        if ($localChanges) { throw 'Preserve your local changes before updating. No services were stopped.' }
        Invoke-Checked git @('fetch')
        & git merge-base --is-ancestor HEAD '@{upstream}'
        if ($LASTEXITCODE -ne 0) {
            throw 'The branch needs an upstream and must not have unpublished/divergent commits. No services were stopped.'
        }
    }

    Invoke-Checked docker @('compose', 'version')
    Invoke-Checked docker @('info', '--format', '{{.ServerVersion}}')
    Invoke-Checked docker @('compose', 'config', '--quiet')

    Write-Host 'Stopping services before updating application files...'
    Invoke-Checked docker @('compose', 'stop')
    if (-not $SkipPull) { Invoke-Checked git @('pull', '--ff-only') }
    Invoke-Checked docker @('compose', 'config', '--quiet')

    Write-Host 'Building application and inventory images...'
    Invoke-Checked docker @('compose', 'build', 'app', 'inventory-service')
    Invoke-Checked docker @('compose', 'up', '-d', '--no-build', '--wait', '--wait-timeout', '120', 'mysql', 'inventory-db', 'inventory-service')

    # The named vendor volume survives image rebuilds, so refresh it explicitly.
    Invoke-Checked docker @('compose', 'run', '--rm', '--no-deps', '-T', 'app', 'composer', 'install', '--no-interaction', '--prefer-dist')
    Invoke-Checked docker @('compose', 'run', '--rm', '--no-deps', '-T', 'app', 'php', 'artisan', 'config:clear')

    # A real read-only RPC verifies readiness; an open TCP port is insufficient.
    $inventoryProbe = @'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
for ($attempt = 0; $attempt < 30; $attempt++) {
    try {
        app(\App\Modules\Inventory\Services\InventoryGrpcClient::class)->getStock(1);
        echo 'Inventory gRPC is ready.' . PHP_EOL;
        exit(0);
    } catch (\Throwable $error) {
        sleep(2);
    }
}
fwrite(STDERR, 'Inventory readiness failed. Inspect docker compose logs inventory-service.' . PHP_EOL);
exit(1);
'@
    Invoke-Checked docker @('compose', 'run', '--rm', '--no-deps', '-T', 'app', 'php', '-r', $inventoryProbe)

    Write-Host 'Applying database migrations...'
    Invoke-Checked docker @('compose', 'run', '--rm', '--no-deps', '-T', 'app', 'php', 'artisan', 'migrate', '--force')
    Invoke-Checked docker @('compose', 'up', '-d', '--no-build', 'app', 'checkout-recovery')

    $apiReady = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        try {
            $health = Invoke-RestMethod 'http://127.0.0.1:8000/api/v1/health' -TimeoutSec 5
            if ($health.success -eq $true -and $health.data.status -eq 'ok') {
                $apiReady = $true
                break
            }
        } catch { }
        Start-Sleep -Seconds 2
    }
    if (-not $apiReady) { throw 'API readiness failed. Inspect docker compose logs app.' }

    $runningServices = & docker compose ps --status running --services
    if ($LASTEXITCODE -ne 0 -or 'app' -notin $runningServices -or 'checkout-recovery' -notin $runningServices) {
        throw 'Application or recovery service is not running. Inspect docker compose ps and logs.'
    }
    Invoke-Checked docker @('compose', 'exec', '-T', 'app', 'php', 'artisan', 'schedule:list')
    Write-Host 'Update completed. API and inventory are ready; checkout recovery is running.' -ForegroundColor Green
} catch {
    Write-Host "Update failed: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host 'Resolve the error and run update.cmd again. Database volumes were not reset.'
    exit 1
} finally {
    Pop-Location
}
