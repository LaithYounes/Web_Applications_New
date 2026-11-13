#Requires -Version 5.1
param([Parameter(Mandatory=$true)][string]$EvidenceDirectory)
$ErrorActionPreference='Stop'
$jdkRoot=Split-Path (Split-Path (Get-Command java).Source -Parent) -Parent
if(-not (Test-Path -LiteralPath (Join-Path $jdkRoot 'bin/javac.exe'))){throw 'A JDK 21 java executable must be on PATH'}
$jdkVersion=& (Join-Path $jdkRoot 'bin/javac.exe') -version 2>&1
if($LASTEXITCODE -ne 0 -or "$jdkVersion" -notmatch '^javac 21\.') {throw 'The live Java suite requires JDK 21 on PATH'}
$env:JAVA_HOME=$jdkRoot
. (Join-Path $PSScriptRoot '../perf/Perf.Common.ps1')
$repo=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$null=New-Item -ItemType Directory -Force $EvidenceDirectory
$temp=New-PerfTemp
$project='perf-r3-round4-'+[guid]::NewGuid().ToString('N').Substring(0,12)
$file=Join-Path $temp 'live.env'
$text=[IO.File]::ReadAllText((Join-Path $repo 'main-app/laravel/.env.perf.example'))
$text += [Environment]::NewLine+(@('PERF_APP_IMAGE=ecommerce2026-app','PERF_INVENTORY_IMAGE=ecommerce2026-inventory-service','PERF_MYSQL_PORT=0','PERF_PG_PORT=0','PERF_HTTP_PORT=0','PERF_GRPC_PORT=0','PERF_REDIS_PORT=0','PERF_REPLICA_PORT=0') -join [Environment]::NewLine)+[Environment]::NewLine
[IO.File]::WriteAllText($file,$text,(New-Object Text.UTF8Encoding($false)))
$ctx=New-PerfContext $project $file
$clean=$false
try {
 $null=Invoke-PerfCompose $ctx @('up','-d','mysql','inventory-db','redis') 'owned live databases'
 $null=Invoke-PerfCompose $ctx @('run','--rm','--no-deps','-T','app','composer','install','--no-interaction','--no-scripts','--no-plugins','--prefer-dist') 'owned live dependencies'
 & (Join-Path $repo 'scripts/perf/Initialize-PerfEnvironment.ps1') -SkipBuild -Project $project -EnvFile $file -TimeoutSeconds 180
 $id=Assert-PerfIdentity $ctx app
 $hostName=Invoke-PerfNative @('exec',$id,'hostname') -Stage 'owned hostname'
 $null=Invoke-PerfNative @('exec','-e',"ROUND4_PROJECT=$project",'-e',"ROUND4_HOSTNAME=$hostName",$id,'php','tests/Support/round4-live.php') -Stage 'real Laravel gRPC regression' -OutputFile (Join-Path $EvidenceDirectory 'live-results.json')
 $live=@(Get-Content -LiteralPath (Join-Path $EvidenceDirectory 'live-results.json') -Raw | ConvertFrom-Json)
 if($live.Count -ne 6 -or @($live|Where-Object{-not $_.pass}).Count){throw 'Live runner did not prove all six scenarios'}
 & powershell.exe -NoProfile -File (Join-Path $repo 'scripts/qa/Test-Round3Native.ps1') -Project $project -EnvFile $file -EvidenceDirectory $EvidenceDirectory
 if($LASTEXITCODE -ne 0){throw 'Native PowerShell 5.1 verification failed'}
 # Execute Java tests against the same disposable PostgreSQL schema, using the current source.
 $pgId=Assert-PerfIdentity $ctx inventory-db
 $port=Invoke-PerfNative @('port',$pgId,'5432/tcp') -Stage 'owned PostgreSQL port'
 $pgPort=($port -split ':')[-1]
 $env:INVENTORY_QA_DB_URL="jdbc:postgresql://127.0.0.1:$pgPort/ecommerce_inventory_perf"
 $env:INVENTORY_QA_DB_USER='inventory_perf_user'
 $env:INVENTORY_QA_DB_PASSWORD='inventory_perf_password'
 $env:SPRING_DATASOURCE_URL=$env:INVENTORY_QA_DB_URL
 $env:SPRING_DATASOURCE_USERNAME=$env:INVENTORY_QA_DB_USER
 $env:SPRING_DATASOURCE_PASSWORD=$env:INVENTORY_QA_DB_PASSWORD
 $env:SPRING_GRPC_SERVER_PORT='0'
 $javaRoot=Join-Path $temp 'java'
 $null=New-Item -ItemType Directory $javaRoot
 Copy-Item -LiteralPath (Join-Path $repo 'inventory-service') -Destination $javaRoot -Recurse
 Copy-Item -LiteralPath (Join-Path $repo 'proto') -Destination $javaRoot -Recurse
 Push-Location (Join-Path $javaRoot 'inventory-service')
 try {
  & ./mvnw.cmd -B test *> (Join-Path $EvidenceDirectory 'java.log')
  if($LASTEXITCODE -ne 0){throw 'Java tests failed; inspect java.log'}
  Copy-Item -Path 'target/surefire-reports/TEST-*.xml' -Destination $EvidenceDirectory
  $javaId=Assert-PerfIdentity $ctx inventory-service
  $jar=Join-Path $temp 'runtime.jar'
  $null=Invoke-PerfNative @('cp',($javaId+':/app/app.jar'),$jar) -Stage 'runtime class provenance'
  Add-Type -AssemblyName System.IO.Compression.FileSystem
  $zip=[IO.Compression.ZipFile]::OpenRead($jar)
  $classes=@()
  try {
   foreach($entry in $zip.Entries|Where-Object{$_.FullName -like 'BOOT-INF/classes/com/ecommerce/inventory/*.class'}){
    $relative=$entry.FullName.Substring('BOOT-INF/classes/'.Length)
    $classPath=Join-Path (Get-Location) ('target/classes/'+$relative)
    $stream=$entry.Open();$sha=[Security.Cryptography.SHA256]::Create()
    try{$hash=([BitConverter]::ToString($sha.ComputeHash($stream))).Replace('-','')}finally{$stream.Dispose();$sha.Dispose()}
    $classes+=@{file=$relative;matches=((Get-FileHash -LiteralPath $classPath).Hash -eq $hash)}
   }
  }finally{$zip.Dispose()}
  $classes|ConvertTo-Json -Depth 4|Set-Content -LiteralPath (Join-Path $EvidenceDirectory 'runtime-class-provenance.json') -Encoding UTF8
  if(-not $classes.Count -or @($classes|Where-Object{-not $_.matches}).Count){throw 'Runtime Java classes differ from current-source compilation'}
 } finally {Pop-Location}
} finally {
 $ids=Invoke-PerfCompose $ctx @('ps','-a','-q') 'live cleanup inventory'
 foreach($id in ($ids -split '\r?\n'|Where-Object{$_})){
  $obj=@(ConvertFrom-Json (Invoke-PerfNative @('inspect',$id) -Stage 'live cleanup labels'))[0]
  if($obj.Config.Labels.'com.docker.compose.project' -ne $project){throw 'Foreign live cleanup resource'}
  foreach($v in $obj.Mounts|Where-Object{$_.Type -eq 'volume'}){
   $label=Invoke-PerfNative @('volume','inspect','--format','{{index .Labels "com.docker.compose.project"}}',$v.Name) -Stage 'live volume ownership'
   if($label -ne $project){throw 'Foreign live volume'}
  }
 }
 $null=Invoke-PerfCompose $ctx @('down','-v','--remove-orphans') 'live owned cleanup'
 Remove-PerfTemp $temp
 @{project=$project;cleaned=$true}|ConvertTo-Json|Set-Content -LiteralPath (Join-Path $EvidenceDirectory 'cleanup.json') -Encoding UTF8
}
Write-Host 'ROUND4 LIVE OK'
