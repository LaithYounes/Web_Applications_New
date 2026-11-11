#Requires -Version 5.1
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$testRoot = Join-Path ([IO.Path]::GetTempPath()) ('firebase-setup-tests-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $testRoot | Out-Null
$harness = @'
param([string]$Scenario, [string]$InputFile, [string]$SecretRoot)
$ErrorActionPreference = 'Stop'
$global:scenario = $Scenario
$global:steps = [System.Collections.Generic.List[string]]::new()
function global:docker {
    $command = $args -join ' '
    $global:steps.Add($command)
    ConvertTo-Json -InputObject @($global:steps) | Set-Content -LiteralPath "$PSScriptRoot/commands.json"
    $global:LASTEXITCODE = 0
    if ($global:scenario -eq 'check-failure' -and $command -like '*notifications:check-firebase*') { $global:LASTEXITCODE = 17 }
    if ($global:scenario -eq 'transient-once' -and $command -like '*notifications:check-firebase*' -and -not $global:transientInjected) {
        $global:transientInjected = $true
        $global:LASTEXITCODE = 2
    }
}
& "$PSScriptRoot/scripts/setup-firebase.ps1" -CredentialsPath $InputFile -SecretDirectory $SecretRoot
if ($Scenario -eq 'repeat') { & "$PSScriptRoot/scripts/setup-firebase.ps1" -CredentialsPath $InputFile -SecretDirectory $SecretRoot }
'@
foreach ($scenario in @('success', 'repeat', 'transient-once', 'invalid-json', 'wrong-type', 'inside-repo', 'custom-override', 'check-failure')) {
    $fixture = Join-Path $testRoot $scenario
    $secretRoot = Join-Path $testRoot ("secrets-$scenario")
    New-Item -ItemType Directory -Path (Join-Path $fixture 'scripts'), (Join-Path $fixture 'main-app/laravel') -Force | Out-Null
    Copy-Item -LiteralPath (Join-Path $projectRoot 'scripts/setup-firebase.ps1') -Destination (Join-Path $fixture 'scripts/setup-firebase.ps1')
    Set-Content -LiteralPath (Join-Path $fixture 'harness.ps1') -Value $harness -Encoding UTF8
    $envFile = Join-Path $fixture 'main-app/laravel/.env'
    Set-Content -LiteralPath $envFile -Value 'ORDER_PUSH_DRIVER=disabled'
    $inputFile = Join-Path $testRoot ("account-$scenario.json")
    if ($scenario -eq 'inside-repo') { $inputFile = Join-Path $fixture 'account.json' }
    $account = @{ type = 'service_account'; project_id = 'test-project'; private_key = 'fake-private-canary'; client_email = 'test@example.test' }
    if ($scenario -eq 'wrong-type') { $account.type = 'authorized_user' }
    if ($scenario -eq 'invalid-json') { Set-Content -LiteralPath $inputFile -Value 'invalid fake-private-canary' }
    else { ConvertTo-Json -InputObject $account | Set-Content -LiteralPath $inputFile }
    $override = Join-Path $fixture 'docker-compose.override.yml'
    if ($scenario -eq 'custom-override') { Set-Content -LiteralPath $override -Value '# existing custom configuration' }
    $ErrorActionPreference = 'Continue'
    $output = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $fixture 'harness.ps1') -Scenario $scenario -InputFile $inputFile -SecretRoot $secretRoot 2>&1
    $code = $LASTEXITCODE
    $ErrorActionPreference = 'Stop'
    $success = $scenario -in @('success', 'repeat', 'transient-once')
    if (($code -eq 0) -ne $success) { throw "${scenario}: unexpected exit $code. $output" }
    if (($output -join "`n") -match 'fake-private-canary') { throw "${scenario}: printed credential contents" }
    if ((Get-Content -LiteralPath $envFile -Raw).Trim() -ne 'ORDER_PUSH_DRIVER=disabled') { throw 'Existing .env was changed' }
    if ($success) {
        $text = Get-Content -LiteralPath $override -Raw
        if ($text -notmatch 'ORDER_PUSH_DRIVER: firebase' -or $text -notmatch 'notification-worker:' -or $text -match 'fake-private-canary') { throw 'Invalid generated override' }
        if (-not (Test-Path -LiteralPath (Join-Path $secretRoot 'firebase-service-account.json'))) { throw 'External credential copy missing' }
        $commands = Get-Content -LiteralPath (Join-Path $fixture 'commands.json') -Raw | ConvertFrom-Json
        $probe = @($commands | Where-Object { $_ -like '*firebase-check.json*notifications:check-firebase' })
        if ($probe.Count -eq 0) { throw 'No preflight credential/FCM validation' }
    } elseif ($scenario -eq 'custom-override') {
        if ((Get-Content -LiteralPath $override -Raw).Trim() -ne '# existing custom configuration') { throw 'Custom override was overwritten' }
    } elseif (Test-Path -LiteralPath $override) { throw "${scenario}: wrote configuration despite failure" }
    Write-Host "PASS $scenario"
}
Write-Host '8 Firebase setup scenarios passed.'
