#Requires -Version 5.1
param([string]$Project,[string]$EnvFile,[string]$EvidenceDirectory)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '../perf/Perf.Common.ps1')
if($Project -notmatch '^perf-r3-'){throw 'Native tests require a task-owned project'}
$ctx=New-PerfContext $Project $EnvFile
$id=Assert-PerfIdentity $ctx mysql
$temp=New-PerfTemp
$remote='/tmp/perf-owned-'+[guid]::NewGuid().ToString('N')
$rows=@()
try{
 $version=Invoke-PerfNative @('version','--format','{{.Server.Version}}') -Stage 'native version'
 $rows+=@{case='native-version-ps51';pass=[bool]$version;version=$version;powershell=$PSVersionTable.PSVersion.ToString()}
 $bad=$false
 try{$null=Invoke-PerfNative @('exec',$id,'sh','-c','exit 47') -Stage 'exit-code-47'}catch{$bad=$_.Exception.Message -match 'exit 47'}
 if(-not $bad){throw 'Native nonzero exit did not fail'}
 $rows+=@{case='nonzero-exit-ps51';pass=$true}
 $file=Join-Path $temp 'بيانات fixture with spaces.txt'
 $content='نص عربي "quoted" \slash $literal'
 [IO.File]::WriteAllText($file,$content,(New-Object Text.UTF8Encoding($false)))
 $null=Invoke-PerfNative @('cp',$file,($id+':'+$remote)) -Stage 'unicode native copy'
 $read=Invoke-PerfNative @('exec',$id,'cat',$remote) -Stage 'unicode native read'
 if($read -cne $content){throw 'Unicode UTF8/native quoting mismatch'}
 $rows+=@{case='unicode-file-copy-and-utf8-ps51';pass=$true}
 $out=Join-Path $temp 'roundtrip.bin'
 $null=Invoke-PerfNative @('exec','-i',$id,'cat') -InputFile $file -OutputFile $out -Stage 'byte-stream-roundtrip'
 if((Get-FileHash -LiteralPath $file).Hash -ne (Get-FileHash -LiteralPath $out).Hash){throw 'Streaming changed file bytes'}
 $rows+=@{case='byte-preserving-streams-ps51';pass=$true}
 # Bad authentication must propagate; no count may be inferred.
 $bad=$false
 try{$null=Invoke-PerfNative @('exec',$id,'mysql','-u','nonexistent_round3_user','-N','-e','SELECT COUNT(*) FROM ecommerce_main_perf.orders;') -Stage 'bad-auth'}catch{$bad=$_.Exception.Message -match 'failed'}
 if(-not $bad){throw 'Bad authentication did not fail'}
 $rows+=@{case='real-bad-authentication-ps51';pass=$true}
}finally{
 $null=Invoke-PerfNative @('exec',$id,'rm','-f',$remote) -Stage 'native test remote cleanup'
 Remove-PerfTemp $temp
 $rows|ConvertTo-Json -Depth 6|Set-Content -LiteralPath (Join-Path $EvidenceDirectory 'native-ps51.json') -Encoding UTF8
}
Write-Host 'NATIVE PS5.1 OK'
