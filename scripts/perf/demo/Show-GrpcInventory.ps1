#Requires -Version 5.1
<#
.SYNOPSIS
  Demonstrates inventory gRPC success and failure behavior via product detail.
#>
[CmdletBinding()]
param(
  [int]$ProductId = 1
)

$ErrorActionPreference = 'Stop'
$Base = 'http://127.0.0.1:8010'
$Url = "$Base/api/v1/products/$ProductId"

Write-Host '=== Inventory gRPC demo ==='
Write-Host "Healthy detail (includes available_quantity): GET $Url"
curl.exe -s $Url | Select-Object -First 1
Write-Host ''
Write-Host 'Failure behavior (do not leave stopped after demo):'
Write-Host '  docker stop ecommerce2026-perf-inventory-service'
Write-Host "  curl.exe -i $Url   # expect 503, no ETag/304"
Write-Host '  docker start ecommerce2026-perf-inventory-service'
Write-Host 'Automated: ProductEtagTest::test_inventory_failure_does_not_return_304'
Write-Host 'GRPC DEMO DONE'
