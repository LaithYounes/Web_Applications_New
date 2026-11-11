#Requires -Version 5.1
<#
.SYNOPSIS
  Live ETag / If-None-Match demonstration against GET /api/v1/products/{id}.

.NOTES
  On Windows PowerShell, prefer curl --header @file so quoted ETag values are not stripped.
#>
[CmdletBinding()]
param(
  [int]$ProductId = 1
)

$ErrorActionPreference = 'Stop'
$Base = 'http://127.0.0.1:8010'
$Url = "$Base/api/v1/products/$ProductId"
$HeadersFile = Join-Path $env:TEMP 'perf-etag-headers.txt'
$BodyFile = Join-Path $env:TEMP 'perf-etag-body.json'
$InmFile = Join-Path $env:TEMP 'perf-etag-if-none-match.txt'

Write-Host '=== ETag demo ==='
Write-Host "GET $Url"
Write-Host 'Representation includes live available_quantity from inventory gRPC.'

curl.exe -s -D $HeadersFile -o $BodyFile $Url
Get-Content $HeadersFile | Select-String -Pattern 'HTTP/|ETag|Cache-Control'
$etagMatch = Select-String -Path $HeadersFile -Pattern '^ETag:\s*(.+)$' | Select-Object -First 1
if (-not $etagMatch) { throw 'No ETag returned. Is inventory gRPC healthy?' }
$etag = $etagMatch.Matches.Groups[1].Value.Trim()
Write-Host "Captured ETag: $etag"
Write-Host 'Body length (200):' (Get-Item $BodyFile).Length

# Write exact header line; curl -H @file preserves quotes (PowerShell -H "...\"...\" often does not).
Set-Content -LiteralPath $InmFile -Value "If-None-Match: $etag" -Encoding ascii -NoNewline

Write-Host 'Conditional GET with If-None-Match (expect 304, empty body)...'
Remove-Item -LiteralPath $BodyFile -Force -ErrorAction SilentlyContinue
curl.exe -s -D - -o $BodyFile -H "@$InmFile" $Url | Select-String -Pattern 'HTTP/|ETag|Cache-Control'
$bodyLen = if (Test-Path -LiteralPath $BodyFile) { (Get-Item $BodyFile).Length } else { 0 }
Write-Host "Body length after conditional GET: $bodyLen (expect 0 for 304)"

Write-Host 'curl.exe recipes (use header file on Windows):'
Write-Host "  curl.exe -i $Url"
Write-Host "  echo If-None-Match: $etag> inm.txt"
Write-Host "  curl.exe -i -H `@inm.txt $Url"
Write-Host 'ETAG DEMO DONE'
