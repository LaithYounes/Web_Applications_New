#Requires -Version 5.1
<#
.SYNOPSIS
  Live SQL demonstration for OrderService::getAllOrders (admin listing).

.DESCRIPTION
  Proves the effect of orders_created_at_index WITHOUT dropping it.
  Before = IGNORE INDEX (orders_created_at_index) on the same 200k-row dataset.
  After  = normal optimizer selection; must use orders_created_at_index.
  Never ALTER / DROP INDEX / truncate / reset volumes.

.PARAMETER Mode
  Before | After | Compare | Verify

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\scripts\perf\demo\Show-SqlPerformance.ps1 -Mode Compare
#>
[CmdletBinding()]
param(
  [ValidateSet('Before', 'After', 'Compare', 'Verify')]
  [string]$Mode = 'Compare',
  [int]$Warmups = 2,
  [int]$Runs = 5,
  [string]$OutDir = ''
)

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot = Resolve-Path (Join-Path $ScriptDir '..\..\..')
Set-Location $RepoRoot

$MysqlHostContainer = 'ecommerce2026-perf-mysql'
$AppContainer = 'ecommerce2026-perf-main-app'
$ExpectedDb = 'ecommerce_main_perf'
$ExpectedOrders = 200000
$IndexName = 'orders_created_at_index'
$Limit = 15

# Credentials from .env.perf only — never Write-Host the password.
$EnvPerf = Join-Path $RepoRoot 'main-app\laravel\.env.perf'
if (-not (Test-Path -LiteralPath $EnvPerf)) {
  throw "Missing $EnvPerf. Copy from .env.perf.example and configure locally."
}

function Get-EnvPerfValue([string]$Key) {
  $line = Get-Content -LiteralPath $EnvPerf | Where-Object { $_ -match "^$([regex]::Escape($Key))=" } | Select-Object -First 1
  if (-not $line) { throw "Missing $Key in .env.perf" }
  return ($line -replace "^$([regex]::Escape($Key))=", '').Trim().Trim('"').Trim("'")
}

$DbUser = Get-EnvPerfValue 'DB_USERNAME'
$DbPass = Get-EnvPerfValue 'DB_PASSWORD'
$DbName = Get-EnvPerfValue 'DB_DATABASE'
if ($DbName -ne $ExpectedDb) {
  throw "Safety abort: .env.perf DB_DATABASE=$DbName (expected $ExpectedDb)."
}

if (-not $OutDir) {
  $OutDir = Join-Path $RepoRoot 'docs\performance\artifacts\sql-demo'
}
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

# Representative SQL for OrderService::getAllOrders page query:
# Order::query()->latest()->paginate(15) => ORDER BY created_at DESC LIMIT 15
# Eager loads (user, items) are separate cheap queries and are NOT timed here.
$SelectList = 'id, user_id, status, total_amount, reservation_id, created_at, updated_at'
$SqlAfter = @"
SELECT $SelectList
FROM orders
ORDER BY created_at DESC
LIMIT $Limit
"@.Trim()

$SqlBefore = @"
SELECT $SelectList
FROM orders IGNORE INDEX ($IndexName)
ORDER BY created_at DESC
LIMIT $Limit
"@.Trim()

function Invoke-PerfMysql {
  param(
    [Parameter(Mandatory = $true)][string]$Sql,
    [switch]$Table
  )
  $args = @('exec', '-i', $MysqlHostContainer, 'mysql', "-u$DbUser", "-p$DbPass", $ExpectedDb)
  if ($Table) { $args += '--table' }
  $args += @('-e', $Sql)
  $prev = $ErrorActionPreference
  $ErrorActionPreference = 'Continue'
  $out = & docker @args 2>&1
  $ErrorActionPreference = $prev
  $text = ($out | ForEach-Object { "$_" }) -join "`n"
  # Strip the insecure-password warning without revealing secrets.
  $text = ($text -split "`n" | Where-Object { $_ -notmatch 'Using a password on the command line' }) -join "`n"
  if ($LASTEXITCODE -ne 0) {
    throw "MySQL command failed (exit $LASTEXITCODE)."
  }
  return $text
}

function Assert-Safety {
  Write-Host "=== Safety checks ===" -ForegroundColor Cyan

  $running = docker ps --filter "name=$MysqlHostContainer" --format '{{.Names}}'
  if ($running -notmatch [regex]::Escape($MysqlHostContainer)) {
    throw "Safety abort: container $MysqlHostContainer is not running. Start ecommerce2026-perf first."
  }

  $composeProbe = docker ps --filter "name=$AppContainer" --format '{{.Names}}'
  if (-not $composeProbe) {
    throw "Safety abort: $AppContainer is not running."
  }

  $liveRaw = Invoke-PerfMysql -Sql 'SELECT DATABASE();'
  $liveLines = @($liveRaw -split "`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })
  $liveDb = $liveLines[-1]
  if ($liveDb -ne $ExpectedDb) {
    throw "Safety abort: live DATABASE()='$liveDb' (expected $ExpectedDb)."
  }
  Write-Host "PASS: live database = $ExpectedDb"

  $countRaw = Invoke-PerfMysql -Sql 'SELECT COUNT(*) FROM orders;'
  $countLines = @($countRaw -split "`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })
  $orderCount = [int]$countLines[-1]
  if ($orderCount -lt $ExpectedOrders) {
    throw "Safety abort: orders count=$orderCount (expected >= $ExpectedOrders)."
  }
  Write-Host "PASS: orders count = $orderCount"

  $idx = Invoke-PerfMysql -Sql "SHOW INDEX FROM orders WHERE Key_name='$IndexName';"
  if ($idx -notmatch [regex]::Escape($IndexName)) {
    throw "Safety abort: index $IndexName is missing. Apply migration 2026_11_10_170000_add_orders_created_at_index on perf only."
  }
  Write-Host "PASS: index $IndexName exists (will NOT be dropped)"
  Write-Host "INFO: Before mode uses IGNORE INDEX — schema is unchanged."
  return $orderCount
}

function Get-OrderedIds([string]$Sql) {
  $idSql = $Sql -replace [regex]::Escape($SelectList), 'id'
  $raw = Invoke-PerfMysql -Sql $idSql
  $ids = @()
  foreach ($line in ($raw -split "`n")) {
    $t = $line.Trim()
    if ($t -match '^\d+$') { $ids += [int]$t }
  }
  if ($ids.Count -ne $Limit) {
    throw "Expected $Limit order ids, got $($ids.Count)."
  }
  return ,$ids
}

function Get-ExplainAnalyze([string]$Sql) {
  return (Invoke-PerfMysql -Sql "EXPLAIN ANALYZE $Sql")
}

function Get-RootActualMs([string]$ExplainText) {
  # Root operator is the first "actual time=start..end" in EXPLAIN ANALYZE.
  if ($ExplainText -match 'actual time=([0-9.]+)\.\.([0-9.]+)') {
    return [double]$Matches[2]
  }
  throw 'Unable to parse actual time from EXPLAIN ANALYZE output.'
}

function Test-PlanIsBefore([string]$ExplainText) {
  $scan = ($ExplainText -match 'Table scan on orders') -or ($ExplainText -match 'type\s*\|\s*ALL') -or ($ExplainText -match 'Using filesort') -or ($ExplainText -match 'Sort: orders\.created_at')
  $usedNew = $ExplainText -match [regex]::Escape($IndexName)
  return ($scan -and -not $usedNew)
}

function Test-PlanIsAfter([string]$ExplainText) {
  return ($ExplainText -match [regex]::Escape($IndexName)) -and ($ExplainText -match 'Index scan on orders')
}

function Get-Median([double[]]$Values) {
  $sorted = $Values | Sort-Object
  $n = $sorted.Count
  if ($n -eq 0) { throw 'No samples for median.' }
  if ($n % 2 -eq 1) { return [double]$sorted[[int]($n / 2)] }
  return ([double]$sorted[$n / 2 - 1] + [double]$sorted[$n / 2]) / 2.0
}

function Measure-Mode {
  param(
    [Parameter(Mandatory = $true)][ValidateSet('Before', 'After')][string]$Label,
    [Parameter(Mandatory = $true)][string]$Sql
  )

  Write-Host ""
  Write-Host "=== Mode $Label ===" -ForegroundColor Yellow
  if ($Label -eq 'Before') {
    Write-Host "Honest label: index excluded for this query (IGNORE INDEX $IndexName). Schema was NOT rolled back."
  } else {
    Write-Host "Normal optimizer path; must select $IndexName."
  }
  Write-Host "SQL (query time only — not full HTTP/API latency):"
  Write-Host $Sql

  for ($w = 1; $w -le $Warmups; $w++) {
    [void](Get-ExplainAnalyze -Sql $Sql)
    Write-Host "  warmup $w/$Warmups done"
  }

  $samples = New-Object System.Collections.Generic.List[double]
  $lastPlan = $null
  for ($i = 1; $i -le $Runs; $i++) {
    $plan = Get-ExplainAnalyze -Sql $Sql
    $ms = Get-RootActualMs -ExplainText $plan
    $samples.Add($ms) | Out-Null
    $lastPlan = $plan
    Write-Host ("  run {0}/{1}: {2:N3} ms" -f $i, $Runs, $ms)
  }

  $arr = @($samples.ToArray())
  $median = Get-Median -Values $arr
  $min = ($arr | Measure-Object -Minimum).Minimum
  $max = ($arr | Measure-Object -Maximum).Maximum

  if ($Label -eq 'Before') {
    if (-not (Test-PlanIsBefore -ExplainText $lastPlan)) {
      throw "Before mode did not show table scan/filesort without $IndexName. Plan:`n$lastPlan"
    }
  } else {
    if (-not (Test-PlanIsAfter -ExplainText $lastPlan)) {
      throw "After mode did not use $IndexName. Plan:`n$lastPlan"
    }
  }

  $ids = Get-OrderedIds -Sql $Sql

  return [pscustomobject]@{
    Label      = $Label
    MedianMs   = [math]::Round($median, 3)
    MinMs      = [math]::Round([double]$min, 3)
    MaxMs      = [math]::Round([double]$max, 3)
    SamplesMs  = ($arr | ForEach-Object { [math]::Round($_, 3) })
    Plan       = $lastPlan
    Ids        = $ids
    Sql        = $Sql
  }
}

function Write-Report {
  param($OrderCount, $Before, $After, [string]$Path)

  $speedup = if ($After.MedianMs -gt 0) { [math]::Round($Before.MedianMs / $After.MedianMs, 1) } else { 'n/a' }
  $lines = @(
    '# Live SQL demonstration report',
    '',
    "Generated: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')",
    "Database: $ExpectedDb",
    "Orders: $OrderCount",
    "Index present: $IndexName (never dropped during demo)",
    'Application source: OrderService::getAllOrders → Order::query()->latest()->paginate(15)',
    'Measured SQL: SELECT ... FROM orders ORDER BY created_at DESC LIMIT 15',
    'Not measured: Laravel HTTP, JWT, eager-load user/items, gRPC, k6.',
    '',
    '## Before (IGNORE INDEX — honest simulation)',
    "Median: $($Before.MedianMs) ms",
    "Range: $($Before.MinMs) .. $($Before.MaxMs) ms",
    "Samples: $($Before.SamplesMs -join ', ')",
    'Plan evidence:',
    '```',
    $Before.Plan.Trim(),
    '```',
    '',
    '## After (optimizer may use orders_created_at_index)',
    "Median: $($After.MedianMs) ms",
    "Range: $($After.MinMs) .. $($After.MaxMs) ms",
    "Samples: $($After.SamplesMs -join ', ')",
    'Plan evidence:',
    '```',
    $After.Plan.Trim(),
    '```',
    '',
    '## Result-set equality',
    "Before ids: $($Before.Ids -join ',')",
    "After ids:  $($After.Ids -join ',')",
    'Match: YES',
    '',
    '## Summary',
    "Median speedup (SQL only): ~${speedup}x",
    'k6 product/order customer scenarios do NOT exercise this admin query; do not cite them as evidence for this index.'
  )
  Set-Content -LiteralPath $Path -Value $lines -Encoding UTF8
}

# --- main ---
$orderCount = Assert-Safety

switch ($Mode) {
  'Verify' {
    $beforeIds = Get-OrderedIds -Sql $SqlBefore
    $afterIds = Get-OrderedIds -Sql $SqlAfter
    if (($beforeIds -join ',') -ne ($afterIds -join ',')) {
      throw "Verify failed: result sets differ.`nBefore=$($beforeIds -join ',')`nAfter=$($afterIds -join ',')"
    }
    $beforePlan = Get-ExplainAnalyze -Sql $SqlBefore
    $afterPlan = Get-ExplainAnalyze -Sql $SqlAfter
    if (-not (Test-PlanIsBefore -ExplainText $beforePlan)) { throw "Verify failed: Before plan invalid.`n$beforePlan" }
    if (-not (Test-PlanIsAfter -ExplainText $afterPlan)) { throw "Verify failed: After plan invalid.`n$afterPlan" }
    Write-Host "VERIFY PASS: same $Limit ids; Before=scan/filesort; After=uses $IndexName" -ForegroundColor Green
    exit 0
  }
  'Before' {
    $result = Measure-Mode -Label Before -Sql $SqlBefore
    Write-Host ("BEFORE median SQL time: {0} ms (min {1}, max {2})" -f $result.MedianMs, $result.MinMs, $result.MaxMs) -ForegroundColor Green
    Write-Host $result.Plan
    exit 0
  }
  'After' {
    $result = Measure-Mode -Label After -Sql $SqlAfter
    Write-Host ("AFTER median SQL time: {0} ms (min {1}, max {2})" -f $result.MedianMs, $result.MinMs, $result.MaxMs) -ForegroundColor Green
    Write-Host $result.Plan
    exit 0
  }
  'Compare' {
    $before = Measure-Mode -Label Before -Sql $SqlBefore
    $after = Measure-Mode -Label After -Sql $SqlAfter

    if (($before.Ids -join ',') -ne ($after.Ids -join ',')) {
      throw "Compare failed: different result sets.`nBefore=$($before.Ids -join ',')`nAfter=$($after.Ids -join ',')"
    }
    Write-Host ""
    Write-Host "=== COMPARISON (SQL query time only) ===" -ForegroundColor Cyan
    Write-Host ("Before median: {0} ms  [{1} .. {2}]  plan=table scan + filesort (index excluded)" -f $before.MedianMs, $before.MinMs, $before.MaxMs)
    Write-Host ("After  median: {0} ms  [{1} .. {2}]  plan=index scan via {3}" -f $after.MedianMs, $after.MinMs, $after.MaxMs, $IndexName)
    if ($after.MedianMs -gt 0) {
      Write-Host ("Approx speedup: {0}x" -f ([math]::Round($before.MedianMs / $after.MedianMs, 1)))
    }
    Write-Host "Result sets identical: YES ($Limit ids)"
    Write-Host "NOTE: This is NOT full API latency and NOT the customer k6 scenario."

    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $report = Join-Path $OutDir "sql-demo-compare-$stamp.md"
    Write-Report -OrderCount $orderCount -Before $before -After $after -Path $report
    Write-Host "Report saved: $report" -ForegroundColor Green
    exit 0
  }
}
