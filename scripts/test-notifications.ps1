#Requires -Version 5.1
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Push-Location -LiteralPath $projectRoot
try {
    & docker compose -f docker-compose.notifications-test.yml up -d --wait --wait-timeout 120
    if ($LASTEXITCODE -ne 0) { throw 'Could not start isolated notification lab.' }
    & docker compose -f docker-compose.notifications-test.yml exec -T app php tests/Integration/notifications.php
    if ($LASTEXITCODE -ne 0) { throw 'Notification integration verification failed.' }
} finally {
    # This file has a fixed test project name and tmpfs databases, no user DB volumes.
    & docker compose -f docker-compose.notifications-test.yml down
    Pop-Location
}
