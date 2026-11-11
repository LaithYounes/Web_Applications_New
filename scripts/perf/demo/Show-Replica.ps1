#Requires -Version 5.1
<#
.SYNOPSIS
  Demonstrates real MySQL primary/replica status and Laravel read/write routing.
#>
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..\..')
Set-Location $RepoRoot

Write-Host '=== MySQL read replica demo ==='
Write-Host 'Replication does NOT guarantee immediate consistency; Seconds_Behind_Source can be > 0 under load.'
powershell -ExecutionPolicy Bypass -File .\scripts\perf\replica\Verify-MysqlReplica.ps1
Write-Host 'Orders/checkout paths force primary via useWritePdo (PERF_ORDERS_USE_PRIMARY=1).'
Write-Host 'REPLICA DEMO DONE'
