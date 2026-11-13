#Requires -Version 5.1
<#
.SYNOPSIS
  Live Redis catalog cache demo: MISS (DB queries) then HIT (0 product queries), plus invalidation.
#>
[CmdletBinding()]
param(
  [ValidateSet('MissHit', 'Invalidate', 'All')]
  [string]$Mode = 'All'
)

$ErrorActionPreference = 'Stop'
$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..\..')
Set-Location $RepoRoot
$App = 'ecommerce2026-perf-main-app'
$Redis = 'ecommerce2026-perf-redis'
$Base = 'http://127.0.0.1:8010'

Write-Host '=== Redis catalog cache demo ==='
Write-Host 'NOTE: stock/reservations/checkout are NEVER cached. Catalog MySQL fields only.'

if ($Mode -eq 'MissHit' -or $Mode -eq 'All') {
  $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  docker exec $Redis redis-cli -n 1 FLUSHDB 2>$null | Out-Null
  $ErrorActionPreference = $prev

  Write-Host 'Running in-process MISS then HIT probe (query counts)...'
  & docker exec $App php app/Support/Performance/perf_cache_hit_probe.php
  Write-Host 'Redis db1 keys:'
  $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  docker exec $Redis redis-cli -n 1 KEYS '*catalog*' 2>$null
  $ErrorActionPreference = $prev

  Write-Host 'HTTP demonstration:'
  curl.exe -s -D - -o NUL "$Base/api/v1/products?per_page=12" | Select-String -Pattern 'HTTP/|Cache-Control'
  curl.exe -s -D - -o NUL "$Base/api/v1/products?per_page=12" | Select-String -Pattern 'HTTP/|Cache-Control'
}

if ($Mode -eq 'Invalidate' -or $Mode -eq 'All') {
  Write-Host 'Invalidation uses CatalogCache version bump after product/category mutations.'
  Write-Host 'Automated proof: CatalogCacheTest::test_product_creation_invalidates_catalog_cache'
}

Write-Host 'REDIS DEMO DONE'
