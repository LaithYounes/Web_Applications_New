#Requires -Version 5.1
<#
.SYNOPSIS
  Creates the isolated Requirement-3 performance Docker environment.

.DESCRIPTION
  Uses ONLY docker-compose.perf.yml and project name ecommerce2026-perf.
  Refuses to touch the demo project ecommerce2026 / ports 8000,3307,5433,9090.
  Does NOT run docker compose down -v / prune / volume wipe.
#>
[CmdletBinding()]
param(
  [switch]$SkipBuild,
  [int]$BuildRetries = 2
)

$ErrorActionPreference = 'Stop'
$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')
Set-Location $RepoRoot

$ComposeFile = Join-Path $RepoRoot 'docker-compose.perf.yml'
$Project = 'ecommerce2026-perf'
$EnvPerf = Join-Path $RepoRoot 'main-app\laravel\.env.perf'
$EnvExample = Join-Path $RepoRoot 'main-app\laravel\.env.perf.example'
$DemoEnv = Join-Path $RepoRoot 'main-app\laravel\.env'

if (-not (Test-Path -LiteralPath $ComposeFile)) {
  throw "Missing $ComposeFile"
}
if (-not (Test-Path -LiteralPath $EnvExample)) {
  throw "Missing $EnvExample"
}

Write-Host "Repo: $RepoRoot"
Write-Host "Project: $Project"
Write-Host "Compose: docker-compose.perf.yml"

if ($env:COMPOSE_FILE -and ($env:COMPOSE_FILE -notmatch 'docker-compose\.perf\.yml')) {
  throw "COMPOSE_FILE is set to '$env:COMPOSE_FILE'. Unset it or point it at docker-compose.perf.yml only."
}
if ($env:COMPOSE_PROFILES) {
  throw "COMPOSE_PROFILES is set ('$env:COMPOSE_PROFILES'). Unset it so Phase-0 does not start future-cache/future-replica."
}

# Refuse accidental demo project selection.
$demoRunning = docker ps --filter 'name=ecommerce2026-main-app' --filter 'name=ecommerce2026-mysql' --format '{{.Names}}' 2>$null
# Informational only — demo may coexist on different ports.
Write-Host "Info: demo container name probe: $demoRunning"

if (-not (Test-Path -LiteralPath $EnvPerf)) {
  Copy-Item -LiteralPath $EnvExample -Destination $EnvPerf
  Write-Host "Created main-app/laravel/.env.perf from example"
}

# Snapshot demo .env fingerprint (existence only) to detect accidental mutation.
$demoEnvExisted = Test-Path -LiteralPath $DemoEnv
$demoEnvHashBefore = $null
if ($demoEnvExisted) {
  $demoEnvHashBefore = (Get-FileHash -LiteralPath $DemoEnv -Algorithm SHA256).Hash
}

function Invoke-PerfCompose {
  param([Parameter(ValueFromRemainingArguments = $true)][string[]]$ComposeArgs)
  & docker compose -p $Project -f $ComposeFile @ComposeArgs
  if ($LASTEXITCODE -ne 0) {
    throw "docker compose failed: $($ComposeArgs -join ' ')"
  }
}

function Set-EnvPerfValue {
  param(
    [Parameter(Mandatory = $true)][string]$Key,
    [Parameter(Mandatory = $true)][string]$Value
  )
  $lines = Get-Content -LiteralPath $EnvPerf
  $replaced = $false
  $updated = foreach ($line in $lines) {
    if ($line -match "^$([regex]::Escape($Key))=") {
      $replaced = $true
      "$Key=$Value"
    } else {
      $line
    }
  }
  if (-not $replaced) {
    $updated = @($updated + "$Key=$Value")
  }
  Set-Content -LiteralPath $EnvPerf -Value $updated -Encoding ascii
}

function Assert-DemoEnvUntouched {
  $existsNow = Test-Path -LiteralPath $DemoEnv
  if ($demoEnvExisted -ne $existsNow) {
    throw 'Safety abort: demo main-app/laravel/.env existence changed during initialization.'
  }
  if ($demoEnvExisted) {
    $hashNow = (Get-FileHash -LiteralPath $DemoEnv -Algorithm SHA256).Hash
    if ($hashNow -ne $demoEnvHashBefore) {
      throw 'Safety abort: demo main-app/laravel/.env was modified. Refusing to continue.'
    }
  }
}

Write-Host "Validating Compose config (no future profiles)..."
& docker compose -p $Project -f $ComposeFile config --quiet
if ($LASTEXITCODE -ne 0) { throw 'compose config failed' }

# Confirm rendered config never references demo volume names.
$configText = & docker compose -p $Project -f $ComposeFile config | Out-String
if ($configText -match 'ecommerce2026_mysql_data|ecommerce2026_postgres_data|ecommerce2026_laravel_vendor[^\w]') {
  # Allow matching only if somehow substring; exact demo volume names must not appear as volume names.
  if ($configText -match 'name:\s*ecommerce2026_mysql_data\b' -or $configText -match 'name:\s*ecommerce2026_postgres_data\b') {
    throw 'Safety abort: rendered compose config references demo volume names.'
  }
}
if ($configText -notmatch 'ecommerce2026_perf_mysql_data') {
  throw 'Safety abort: perf MySQL volume missing from rendered config.'
}
if ($configText -match 'published: "8000"' -or $configText -match 'published: "3307"' -or $configText -match 'published: "9090"') {
  throw 'Safety abort: demo host ports appear in perf compose config.'
}

if (-not $SkipBuild) {
  $attempt = 0
  $built = $false
  while (-not $built -and $attempt -le $BuildRetries) {
    $attempt++
    Write-Host "Building images (attempt $attempt of $($BuildRetries + 1))..."
    & docker compose -p $Project -f $ComposeFile build
    if ($LASTEXITCODE -eq 0) {
      $built = $true
    } else {
      Write-Host "Build failed on attempt $attempt." -ForegroundColor Yellow
      if ($attempt -le $BuildRetries) {
        Start-Sleep -Seconds (10 * $attempt)
      }
    }
  }
  if (-not $built) {
    throw 'docker compose build failed after retries (likely network/Docker Hub/PECL). See docs/performance/00_ENVIRONMENT_SETUP.md'
  }
}

Write-Host "Starting isolated performance services..."
Invoke-PerfCompose 'up' '-d' '--wait'

# Generate secrets locally into .env.perf ONLY (never artisan file writers).
$hasAppKey = Select-String -Path $EnvPerf -Pattern '^APP_KEY=base64:.+$' -Quiet
if (-not $hasAppKey) {
  Write-Host "Generating APP_KEY into .env.perf only (php random_bytes)..."
  $appKey = (& docker compose -p $Project -f $ComposeFile run --rm --no-deps -T app php -r "echo 'base64:'.base64_encode(random_bytes(32));" | Out-String).Trim()
  if ($LASTEXITCODE -ne 0 -or $appKey -notmatch '^base64:') {
    throw 'Failed to generate APP_KEY'
  }
  Set-EnvPerfValue -Key 'APP_KEY' -Value $appKey
}

$hasJwt = Select-String -Path $EnvPerf -Pattern '^JWT_SECRET=.+$' -Quiet
if (-not $hasJwt) {
  Write-Host "Generating JWT_SECRET into .env.perf only (php random_bytes)..."
  $jwtSecret = (& docker compose -p $Project -f $ComposeFile run --rm --no-deps -T app php -r "echo rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');" | Out-String).Trim()
  if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($jwtSecret) -or $jwtSecret.Length -lt 16) {
    throw 'Failed to generate JWT_SECRET'
  }
  Set-EnvPerfValue -Key 'JWT_SECRET' -Value $jwtSecret
}

Assert-DemoEnvUntouched

Write-Host "Recreating app so mounted .env.perf secrets are loaded..."
Invoke-PerfCompose 'up' '-d' '--wait' '--no-deps' '--force-recreate' 'app'

Write-Host "Clearing Laravel config cache inside perf bootstrap/cache volume..."
& docker compose -p $Project -f $ComposeFile exec -T app php artisan config:clear
# cache:clear needs the cache table; skip safely before first migrate.
& docker compose -p $Project -f $ComposeFile exec -T app php artisan cache:clear 2>$null | Out-Null
if ($LASTEXITCODE -ne 0) {
  Write-Host "Info: cache:clear skipped (table may not exist yet before migrate)."
}

Write-Host "Verifying EFFECTIVE database name before migrate..."
$effectiveDb = (& docker compose -p $Project -f $ComposeFile exec -T app php artisan tinker --execute="echo config('database.connections.mysql.database').'|'.Illuminate\Support\Facades\DB::connection()->getDatabaseName().'|'.Illuminate\Support\Facades\DB::select('select database() as d')[0]->d;" | Out-String)
# tinker --execute output can include banners; extract last pipe-separated triple
if ($effectiveDb -notmatch 'ecommerce_main_perf\|ecommerce_main_perf\|ecommerce_main_perf') {
  Write-Host $effectiveDb
  throw 'Safety abort: effective Laravel DB is not ecommerce_main_perf. Migrations refused.'
}
Write-Host "PASS: effective DB is ecommerce_main_perf"

Write-Host "Running migrations against ecommerce_main_perf ONLY..."
Invoke-PerfCompose 'exec' '-T' 'app' 'php' 'artisan' 'migrate' '--force'

Assert-DemoEnvUntouched

Write-Host "Initialize-PerfEnvironment completed."
Write-Host "HTTP base: http://127.0.0.1:8010"
Write-Host "gRPC host: 127.0.0.1:9100"
Write-Host "Next: .\scripts\perf\Validate-PerfEnvironment.ps1"
