#Requires -Version 5.1
<#
.SYNOPSIS
  Read-only verification of the 200,000-order performance dataset.
#>
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$Primary = 'ecommerce2026-perf-mysql'
$RootPass = 'perf_root_password'

$prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
$orders = docker exec $Primary mysql -uroot "-p$RootPass" -N -e "SELECT COUNT(*) FROM ecommerce_main_perf.orders;" 2>$null
$items = docker exec $Primary mysql -uroot "-p$RootPass" -N -e "SELECT COUNT(*) FROM ecommerce_main_perf.order_items;" 2>$null
$users = docker exec $Primary mysql -uroot "-p$RootPass" -N -e "SELECT COUNT(*) FROM ecommerce_main_perf.users;" 2>$null
$products = docker exec $Primary mysql -uroot "-p$RootPass" -N -e "SELECT COUNT(*) FROM ecommerce_main_perf.products;" 2>$null
$idx = docker exec $Primary mysql -uroot "-p$RootPass" -N -e "SHOW INDEX FROM ecommerce_main_perf.orders WHERE Key_name='orders_created_at_index';" 2>$null
$ErrorActionPreference = $prev

Write-Host "orders=$orders"
Write-Host "order_items=$items"
Write-Host "users=$users"
Write-Host "products=$products"
Write-Host "orders_created_at_index present: $([bool]$idx)"
if ([int]$orders -lt 200000) { throw 'Dataset incomplete' }
Write-Host 'DATASET OK'
