#Requires -Version 5.1
# Run with: powershell -NoProfile -ExecutionPolicy Bypass -File scripts/tests/update.Tests.ps1
# Each case runs the real updater in Windows PowerShell against mocked commands
# in a disposable checkout. No application containers or databases are changed.
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$testRoot = Join-Path ([IO.Path]::GetTempPath()) ('ecommerce-updater-tests-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $testRoot | Out-Null

$harness = @'
param([string]$Scenario)
$ErrorActionPreference = 'Stop'
$global:scenario = $Scenario
$global:commands = [System.Collections.Generic.List[string]]::new()
$global:LASTEXITCODE = 0
function global:git {
    $command = 'git ' + ($args -join ' ')
    $global:commands.Add($command)
    $global:LASTEXITCODE = 0
    if ($command -like 'git status*' -and $global:scenario -eq 'dirty') { ' M local.php' }
    if (($command -eq 'git fetch' -and $global:scenario -eq 'fetch-failure') -or
        ($command -like 'git merge-base*' -and $global:scenario -eq 'diverged') -or
        ($command -eq 'git pull --ff-only' -and $global:scenario -eq 'pull-failure')) {
        $global:LASTEXITCODE = 17
    }
}
function global:docker {
    $command = 'docker ' + ($args -join ' ')
    $global:commands.Add($command)
    $global:LASTEXITCODE = 0
    $failure = @{
        'build-failure' = 'docker compose build*'
        'composer-failure' = '*composer install*'
        'inventory-failure' = '*php -r *'
        'migration-failure' = '*artisan migrate --force'
    }[$global:scenario]
    if ($failure -and $command -like $failure) { $global:LASTEXITCODE = 17 }
    if ($command -like 'docker compose ps*') {
        'app'
        if ($global:scenario -ne 'missing-worker') { 'checkout-recovery' }
    }
}
function global:Invoke-RestMethod {
    $global:commands.Add('HTTP health')
    if ($global:scenario -eq 'health-failure') { throw 'Injected unavailable API' }
    @{ success = $true; data = @{ status = 'ok' } }
}
function global:Start-Sleep { }
if ($Scenario -eq 'skip-pull') { & "$PSScriptRoot/scripts/update.ps1" -SkipPull }
else { & "$PSScriptRoot/scripts/update.ps1" }
$resultCode = $LASTEXITCODE
ConvertTo-Json -InputObject @($global:commands) | Set-Content -LiteralPath "$PSScriptRoot/commands.json" -Encoding UTF8
exit $resultCode
'@

function Assert-That {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) { throw $Message }
}

$scenarios = @('success', 'skip-pull', 'dirty', 'diverged', 'fetch-failure', 'pull-failure', 'build-failure', 'composer-failure', 'inventory-failure', 'migration-failure', 'health-failure', 'missing-worker')
foreach ($scenario in $scenarios) {
    $fixture = Join-Path $testRoot $scenario
    New-Item -ItemType Directory -Path (Join-Path $fixture 'scripts'), (Join-Path $fixture 'main-app/laravel') -Force | Out-Null
    Copy-Item -LiteralPath (Join-Path $projectRoot 'scripts/update.ps1') -Destination (Join-Path $fixture 'scripts/update.ps1')
    Set-Content -LiteralPath (Join-Path $fixture 'main-app/laravel/.env') -Value ''
    Set-Content -LiteralPath (Join-Path $fixture 'harness.ps1') -Value $harness -Encoding UTF8
    $output = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $fixture 'harness.ps1') -Scenario $scenario 2>&1
    $exitCode = $LASTEXITCODE
    [string[]]$commands = Get-Content -Raw -LiteralPath (Join-Path $fixture 'commands.json') | ConvertFrom-Json
    $log = $commands -join "`n"
    $success = $scenario -in @('success', 'skip-pull')
    Assert-That (($exitCode -eq 0) -eq $success) "${scenario}: wrong exit code $exitCode. Output: $output"

    if ($success) {
        $orderedSteps = @('docker compose stop', 'docker compose build app inventory-service', 'docker compose up -d --no-build --wait --wait-timeout 120 mysql inventory-db inventory-service', 'docker compose run --rm --no-deps -T app composer install --no-interaction --prefer-dist', 'docker compose run --rm --no-deps -T app php artisan config:clear', 'docker compose run --rm --no-deps -T app php -r *', 'docker compose run --rm --no-deps -T app php artisan migrate --force', 'docker compose up -d --no-build app checkout-recovery', 'HTTP health', 'docker compose ps --status running --services', 'docker compose exec -T app php artisan schedule:list')
        $previousIndex = -1
        foreach ($step in $orderedSteps) {
            $matching = @($commands | Where-Object { $_ -like $step })
            Assert-That ($matching.Count -eq 1) "${scenario}: missing/duplicate step $step"
            $index = [array]::IndexOf($commands, [string]$matching[0])
            Assert-That ($index -gt $previousIndex) "${scenario}: out-of-order step $step"
            $previousIndex = $index
        }
        if ($scenario -eq 'success') {
            $stopIndex = [array]::IndexOf($commands, 'docker compose stop')
            $pullIndex = [array]::IndexOf($commands, 'git pull --ff-only')
            $buildIndex = [array]::IndexOf($commands, 'docker compose build app inventory-service')
            Assert-That ($stopIndex -lt $pullIndex -and $pullIndex -lt $buildIndex) 'Pull must happen after stop and before build.'
        } else { Assert-That (-not ($commands -like 'git *')) 'SkipPull must not fetch/pull or require a clean checkout.' }
    } elseif ($scenario -in @('dirty', 'diverged', 'fetch-failure')) {
        Assert-That (-not ($commands -contains 'docker compose stop')) "${scenario}: stopped services during preflight failure"
    } elseif ($scenario -in @('pull-failure', 'build-failure', 'composer-failure', 'inventory-failure')) {
        Assert-That (-not ($log -match 'artisan migrate --force')) "${scenario}: migrated after failure"
        Assert-That (-not ($commands -contains 'docker compose up -d --no-build app checkout-recovery')) "${scenario}: started application after failure"
    } elseif ($scenario -eq 'migration-failure') {
        Assert-That (-not ($commands -contains 'docker compose up -d --no-build app checkout-recovery')) 'Started application after failed migration.'
    } else {
        Assert-That (-not ($log -match 'artisan schedule:list')) "${scenario}: continued after failed readiness"
    }
    Assert-That (-not ($log -match 'migrate:fresh|migrate:reset|db:seed|down --volumes|down -v')) "${scenario}: attempted database reset"
    Write-Host "PASS $scenario"
}
Write-Host "$($scenarios.Count) updater scenarios passed. Disposable fixtures: $testRoot"
