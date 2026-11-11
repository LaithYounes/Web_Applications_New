#Requires -Version 5.1
<#
.SYNOPSIS
  Short reproducible k6 smoke (same scenarios as baseline, shorter duration).
#>
[CmdletBinding()]
param(
  [int]$Vus = 5,
  [string]$Duration = '15s'
)

$ErrorActionPreference = 'Stop'
$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..\..')
Set-Location $RepoRoot
$OutDir = Join-Path $RepoRoot 'docs\performance\artifacts\k6-demo'

Write-Host '=== Short k6 demo ==='
Write-Host "VUs=$Vus Duration=$Duration OutDir=$OutDir"
Write-Host 'Full comparable run uses scripts/perf/k6/Run-Baseline.ps1 with Vus=20 Duration=60s into post-opt/.'
powershell -ExecutionPolicy Bypass -File .\scripts\perf\k6\Run-Baseline.ps1 -Scenario products-list -Vus $Vus -Duration $Duration -OutDir $OutDir
Write-Host 'K6 DEMO DONE'
