#Requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$CredentialsPath,
    [string]$SecretDirectory = (Join-Path $env:USERPROFILE '.ecommerce-secrets/ecommerce2026')
)

$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$marker = '# Managed by scripts/setup-firebase.ps1'

function Invoke-Checked {
    param([string[]]$DockerArguments)
    & docker @DockerArguments | Out-Host
    if ($LASTEXITCODE -ne 0) { throw 'Docker/Firebase check failed. See the safe diagnostic above; local Firebase setup was not completed.' }
}

function Invoke-FirebaseCheck {
    param([string[]]$DockerArguments)
    for ($attempt = 0; $attempt -lt 3; $attempt++) {
        & docker @DockerArguments | Out-Host
        if ($LASTEXITCODE -eq 0) { return }
        if ($LASTEXITCODE -ne 2 -or $attempt -eq 2) {
            throw 'Firebase verification did not pass. Fix the diagnostic above and rerun this setup; no test message was delivered.'
        }
        Write-Host 'Transient Firebase/network error; retrying the non-delivering check...'
        Start-Sleep -Seconds 2
    }
}

Push-Location -LiteralPath $projectRoot
try {
    Get-Command docker -ErrorAction Stop | Out-Null
    if (-not (Test-Path -LiteralPath 'main-app/laravel/.env')) { throw 'Complete README first-time setup or run update.cmd before configuring Firebase.' }
    $sourcePath = (Resolve-Path -LiteralPath $CredentialsPath -ErrorAction Stop).ProviderPath
    $secretRoot = [IO.Path]::GetFullPath($SecretDirectory)
    foreach ($candidate in @($sourcePath, $secretRoot)) {
        if ($candidate.Equals($projectRoot, [StringComparison]::OrdinalIgnoreCase) -or
            $candidate.StartsWith($projectRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
            throw 'Credentials and their destination must be outside the repository.'
        }
    }
    try { $account = [IO.File]::ReadAllText($sourcePath) | ConvertFrom-Json }
    catch { throw 'The credential file is not valid JSON. Its contents were not printed.' }
    if ($account.type -ne 'service_account' -or -not $account.private_key -or -not $account.client_email -or
        $account.project_id -notmatch '^[a-z][a-z0-9-]{4,62}$') {
        throw 'Expected a service-account JSON with project_id, client_email and private_key.'
    }
    $projectId = [string]$account.project_id
    $account = $null
    $overridePath = Join-Path $projectRoot 'docker-compose.override.yml'
    foreach ($alternate in @('compose.override.yaml', 'compose.override.yml', 'docker-compose.override.yaml')) {
        if (Test-Path -LiteralPath $alternate) { throw 'An existing Compose override requires manual integration; see docs/order-notifications.md. No files changed.' }
    }
    if ((Test-Path -LiteralPath $overridePath) -and (Get-Content -LiteralPath $overridePath -TotalCount 1) -ne $marker) {
        throw 'docker-compose.override.yml already contains custom configuration. It was preserved; see docs/order-notifications.md for manual integration.'
    }
    Invoke-Checked @('compose', 'config', '--quiet')
    # Preflight uses the source directly, a separate target, isolated config/cache,
    # and an unconditional FCM validate_only request. No worker is enabled yet.
    $mount = $sourcePath.Replace('\', '/') + ':/run/secrets/firebase-check.json:ro'
    Invoke-FirebaseCheck @('compose', 'run', '--rm', '--no-deps', '-T',
        '-e', 'APP_CONFIG_CACHE=/tmp/firebase-check-config.php', '-e', 'CACHE_STORE=array',
        '-e', 'ORDER_PUSH_DRIVER=disabled', '-e', "FIREBASE_PROJECT_ID=$projectId",
        '-e', 'FIREBASE_CREDENTIALS=/run/secrets/firebase-check.json',
        '--volume', $mount, 'app', 'php', 'artisan', 'notifications:check-firebase')

    New-Item -ItemType Directory -Path $secretRoot -Force | Out-Null
    $destination = Join-Path $secretRoot 'firebase-service-account.json'
    if (-not $sourcePath.Equals($destination, [StringComparison]::OrdinalIgnoreCase)) {
        Copy-Item -LiteralPath $sourcePath -Destination $destination -Force
    }
    $yamlPath = $destination.Replace('\', '/').Replace("'", "''")
    $lines = @($marker, '# Local only: ignored by Git, automatically loaded by docker compose.',
        'services:')
    foreach ($service in @('app', 'checkout-recovery', 'notification-worker')) {
        $lines += @("  ${service}:", '    environment:', '      ORDER_PUSH_DRIVER: firebase',
            "      FIREBASE_PROJECT_ID: '$projectId'", "      FIREBASE_CREDENTIALS: '/run/secrets/firebase-service-account.json'",
            '    secrets:', '      - firebase-service-account.json')
    }
    $lines += @('secrets:', '  firebase-service-account.json:', "    file: '$yamlPath'")
    [IO.File]::WriteAllText($overridePath, ($lines -join "`n") + "`n", [Text.UTF8Encoding]::new($false))
    Invoke-Checked @('compose', 'config', '--quiet')
    Invoke-Checked @('compose', 'run', '--rm', '--no-deps', '-T', 'app', 'php', 'artisan', 'config:clear')
    Invoke-Checked @('compose', 'up', '-d', '--no-deps', '--force-recreate', 'app', 'checkout-recovery', 'notification-worker')
    Invoke-FirebaseCheck @('compose', 'exec', '-T', 'app', 'php', 'artisan', 'notifications:check-firebase')
    Write-Host 'Firebase is configured. Future updates use update.cmd normally; the local override and external secret are preserved.' -ForegroundColor Green
    Write-Host 'No test push was delivered. Device display still requires Android integration.'
} catch {
    Write-Error $_.Exception.Message
    exit 1
} finally {
    Pop-Location
}
