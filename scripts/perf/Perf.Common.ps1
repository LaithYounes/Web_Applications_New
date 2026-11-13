#Requires -Version 5.1
# Shared checked Docker boundary. Never include arguments/stderr in errors: they may contain secrets.
function Invoke-PerfNative {
    param([string[]]$Arguments, [string]$InputFile, [string]$OutputFile, [string]$Stage = 'docker', [int]$TimeoutSeconds=600)
    $start = New-Object Diagnostics.ProcessStartInfo
    $start.FileName = 'docker.exe'
    # Windows CommandLineToArgvW quoting, including embedded quotes and trailing backslashes.
    $start.Arguments = (($Arguments | ForEach-Object {
        '"' + ([regex]::Replace([regex]::Replace($_, '(\\*)"', '$1$1\"'), '(\\+)$', '$1$1')) + '"'
    }) -join ' ')
    $start.UseShellExecute = $false
    $start.CreateNoWindow = $true
    $start.RedirectStandardInput = [bool]$InputFile
    $start.RedirectStandardOutput = $true
    $start.RedirectStandardError = $true
    $start.StandardOutputEncoding = New-Object Text.UTF8Encoding($false)
    $start.StandardErrorEncoding = New-Object Text.UTF8Encoding($false)
    $process = New-Object Diagnostics.Process
    $process.StartInfo = $start
    $null = $process.Start()
    $errors = $process.StandardError.ReadToEndAsync()
    $output = $null
    $stream = $null
    $source = $null
    try {
        if ($OutputFile) {
            $stream = [IO.File]::Create($OutputFile)
            $copy = $process.StandardOutput.BaseStream.CopyToAsync($stream)
        } else { $output = $process.StandardOutput.ReadToEndAsync() }
        if ($InputFile) {
            $source = [IO.File]::OpenRead($InputFile)
            $inputCopy=$source.CopyToAsync($process.StandardInput.BaseStream)
        }
        # Imports need EOF before the client can exit. While streaming, poll the copy with a deadline.
        if ($InputFile) {
            if (-not $inputCopy.Wait($TimeoutSeconds*1000)) { $process.Kill(); throw "Stage '$Stage' input timed out." }
            $source.Dispose(); $source=$null; $process.StandardInput.Close()
        }
        if (-not $process.WaitForExit($TimeoutSeconds*1000)) { $process.Kill(); throw "Stage '$Stage' timed out." }
        $code = $process.ExitCode
        if ($OutputFile) { $copy.GetAwaiter().GetResult() } else { $result = $output.GetAwaiter().GetResult() }
        $null = $errors.GetAwaiter().GetResult()
        if ($code -ne 0) { throw "Stage '$Stage' failed (exit $code); output withheld to protect credentials." }
        if (-not $OutputFile) { return $result.Trim() }
    } finally { if ($source) { $source.Dispose() }; if ($stream) { $stream.Dispose() }; $process.Dispose() }
}

function New-PerfContext {
    param([string]$Project = 'ecommerce2026-perf', [string]$EnvFile = '')
    if ($Project -ne 'ecommerce2026-perf' -and $Project -notmatch '^perf-r3-[a-z0-9-]+$') { throw 'Only perf or task-owned perf-r3 projects are allowed.' }
    $repo = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
    if (-not $EnvFile) { $EnvFile = Join-Path $repo 'main-app/laravel/.env.perf' }
    $EnvFile = [IO.Path]::GetFullPath($EnvFile)
    if ($EnvFile -eq (Join-Path $repo 'main-app\laravel\.env')) { throw 'Demo env is forbidden.' }
    if (-not (Test-Path -LiteralPath $EnvFile -PathType Leaf)) { throw 'Performance env file missing.' }
    $envItem=Get-Item -LiteralPath $EnvFile
    if ($envItem.Name -eq '.env' -or ($envItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Ordinary .env files and env symlinks are forbidden.' }
    # Dedicated interpolation file: Compose never implicitly loads the repo .env.
    $ctx = @{ Project=$Project; Repo=$repo; EnvFile=$EnvFile; Compose=(Join-Path $repo 'docker-compose.perf.yml'); ServiceIds=@{} }
    $ctx.Prefix = $Project
    $ctx
}

function Invoke-PerfCompose {
    param($Context, [string[]]$Arguments, [string]$Stage = 'compose', [string]$InputFile, [string]$OutputFile)
    $oldFile = $env:PERF_ENV_FILE; $oldPrefix = $env:PERF_RESOURCE_PREFIX
    try {
        $env:PERF_ENV_FILE = $Context.EnvFile
        $env:PERF_RESOURCE_PREFIX = if ($Context.Project -eq 'ecommerce2026-perf') { 'ecommerce2026_perf' } else { $Context.Project }
        Invoke-PerfNative -Arguments (@('compose','--env-file',$Context.EnvFile,'-p',$Context.Project,'-f',$Context.Compose) + $Arguments) -Stage $Stage -InputFile $InputFile -OutputFile $OutputFile
    } finally { $env:PERF_ENV_FILE=$oldFile; $env:PERF_RESOURCE_PREFIX=$oldPrefix }
}

function Assert-PerfIdentity {
    param($Context, [string]$Service)
    $id = Invoke-PerfCompose $Context @('ps','-a','-q',$Service) 'container identity'
    if (-not $id -or $id -match '\s') { throw "Missing or ambiguous service: $Service" }
    $obj = @(ConvertFrom-Json (Invoke-PerfNative @('inspect',$id) -Stage 'inspect identity'))[0]
    if ($obj.Config.Labels.'com.docker.compose.project' -ne $Context.Project -or $obj.Config.Labels.'com.docker.compose.service' -ne $Service) { throw 'Container identity mismatch.' }
    foreach ($m in $obj.Mounts) {
        if ($m.Type -eq 'volume') {
            $v = @(ConvertFrom-Json (Invoke-PerfNative @('volume','inspect',$m.Name) -Stage 'volume identity'))[0]
            if ($v.Labels.'com.docker.compose.project' -ne $Context.Project) { throw 'Foreign volume rejected.' }
        }
    }
    if ($null -ne $Context.ServiceIds) { $Context.ServiceIds[$Service]=$id }
    return $id
}

function Invoke-PerfSql {
    param($Context, [ValidateSet('mysql','mysql-replica','postgres')][string]$Engine, [string]$Sql,
        [string]$Database = '', [string]$OutputFile)
    $service=if ($Engine -eq 'postgres') { 'inventory-db' } else { $Engine }
    $id=$Context.ServiceIds[$service]
    if (-not $id) { $id=Assert-PerfIdentity $Context $service }
    if ($Engine -eq 'postgres') {
        if (-not $Database) { $Database='ecommerce_inventory_perf' }
        $args = @('exec','-e','PGCONNECT_TIMEOUT=5',$id,'sh','-ec','PGPASSWORD="$POSTGRES_PASSWORD" exec psql -h127.0.0.1 -X -v ON_ERROR_STOP=1 -U inventory_perf_user "$@"','sql','-d',$Database,'-At','-c',$Sql)
    } else {
        if (-not $Database) { $Database='ecommerce_main_perf' }
        $format = if ($Sql -match '^SHOW REPLICA STATUS') { '--column-names' } else { '--skip-column-names' }
        $args = @('exec',$id,'sh','-ec','MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --protocol=TCP -h127.0.0.1 --connect-timeout=5 --default-character-set=utf8mb4 -B "$@"','sql',$format,$Database,'-e',$Sql)
    }
    Invoke-PerfNative $args -Stage "SQL $Engine" -OutputFile $OutputFile
}

function ConvertTo-PerfCount {
    param([string]$Value)
    if ($Value -notmatch '^\d+$') { throw 'Count query did not return one unsigned integer; refusing to infer emptiness.' }
    return [long]::Parse($Value, [Globalization.CultureInfo]::InvariantCulture)
}

function Wait-PerfReady {
    param([scriptblock]$ReadinessCheck, [int]$TimeoutSeconds = 180, [string]$Stage = 'readiness')
    $watch = [Diagnostics.Stopwatch]::StartNew()
    do {
        try {
            $readyResult=& $ReadinessCheck
            if ($readyResult -is [bool] -and $readyResult) { return }
        } catch { }
        if ($watch.Elapsed.TotalSeconds -ge $TimeoutSeconds) { throw "Timed out waiting for $Stage after $TimeoutSeconds seconds." }
        Start-Sleep -Milliseconds 500
    } while ($true)
}

function Assert-PerfDatabases {
    param($Context)
    $null = Assert-PerfIdentity $Context 'mysql'
    $null = Assert-PerfIdentity $Context 'inventory-db'
    if ((Invoke-PerfSql $Context mysql 'SELECT CONCAT(DATABASE(),CHAR(124),@@server_id,CHAR(124),@@read_only);') -ne 'ecommerce_main_perf|1|0') { throw 'MySQL destination identity mismatch.' }
    if ((Invoke-PerfSql $Context postgres "SELECT current_database()||'|'||current_user||'|'||pg_is_in_recovery();") -ne 'ecommerce_inventory_perf|inventory_perf_user|false') { throw 'PostgreSQL destination identity mismatch.' }
    $myUuid=Invoke-PerfSql $Context mysql 'SELECT @@server_uuid;'
    $pgId=Invoke-PerfSql $Context postgres 'SELECT system_identifier FROM pg_control_system();'
    if ($myUuid -notmatch '^[a-fA-F0-9-]{36}$' -or $pgId -notmatch '^\d+$') { throw 'Database cluster identity could not be verified.' }
    $identity=$myUuid+'|'+$pgId
    if ($Context.ContainsKey('ClusterIdentity') -and $Context.ClusterIdentity -ne $identity) { throw 'Database cluster changed during the operation; destructive work refused.' }
    $Context.ClusterIdentity=$identity
}

function New-PerfTemp {
    $dir = Join-Path ([IO.Path]::GetTempPath()) ('perf-owned-' + [guid]::NewGuid().ToString('N'))
    $null = New-Item -ItemType Directory -Path $dir
    [IO.File]::WriteAllText((Join-Path $dir '.owner'), (Split-Path $dir -Leaf))
    return $dir
}
function Remove-PerfTemp {
    param([string]$Directory)
    $full = [IO.Path]::GetFullPath($Directory)
    $parent = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\','/')
    $leaf = Split-Path $full -Leaf
    if ((Split-Path $full -Parent) -ne $parent -or $leaf -notmatch '^perf-owned-[a-f0-9]{32}$' -or
        [IO.File]::ReadAllText((Join-Path $full '.owner')) -ne $leaf) { throw 'Cleanup refused: directory ownership mismatch.' }
    Remove-Item -LiteralPath $full -Recurse -Force
}

function Stop-PerfWriters {
    param($Context)
    $json = Invoke-PerfCompose $Context @('ps','--format','json') 'writer discovery'
    $running = @()
    foreach ($line in ($json -split "`n")) {
        if (-not $line.Trim()) { continue }
        foreach ($c in @($line | ConvertFrom-Json)) {
            if (-not $c.Service -or -not $c.State) { throw 'Writer inventory is incomplete; refusing snapshot.' }
            if ($c.Service -notin @('mysql','inventory-db','redis')) {
                if ($c.State -in @('paused','removing')) { throw 'Writer in an unsafe transitional state; stabilize it before snapshot.' }
                if ($c.State -in @('running','restarting')) { $running += $c.Service }
                elseif ($c.State -notin @('created','exited','dead')) { throw 'Unknown writer state; refusing snapshot.' }
            }
        }
    }
    foreach ($s in $running) { $null=Assert-PerfIdentity $Context $s }
    if ($running.Count) { $null = Invoke-PerfCompose $Context (@('stop','--timeout','30') + $running) 'quiesce writers' }
    # Re-read actual state, rather than assuming stop succeeded.
    foreach ($s in $running) {
        $id = Assert-PerfIdentity $Context $s
        if ((Invoke-PerfNative @('inspect','--format','{{.State.Running}}',$id) -Stage 'writer stopped') -ne 'false') { throw 'Writer is still running.' }
    }
    return $running
}

function Set-PerfEnvValue {
    param($Context, [string]$Key, [string]$Value)
    if ($Key -notmatch '^[A-Z_]+$' -or $Value -match '[\r\n]') { throw 'Invalid perf env update.' }
    $text = [IO.File]::ReadAllText($Context.EnvFile)
    $text = [regex]::Replace($text, '(?m)^'+[regex]::Escape($Key)+'=.*\r?\n?', '')
    [IO.File]::WriteAllText($Context.EnvFile, $text.TrimEnd() + "`n$Key=$Value`n", (New-Object Text.UTF8Encoding($false)))
}
function Set-PerfReadHost {
    param($Context, [ValidateSet('mysql','mysql-replica')][string]$HostName)
    Set-PerfEnvValue $Context 'DB_READ_HOST' $HostName
    # Process interpolation wins over --env-file; explicitly synchronize it for this invocation.
    $env:DB_READ_HOST = $HostName
}

function Invoke-PerfPhp {
    param($Context, [string]$Code)
    $temp=New-PerfTemp
    try {
        $file=Join-Path $temp 'probe.php'
        [IO.File]::WriteAllText($file,$Code,(New-Object Text.UTF8Encoding($false)))
        Invoke-PerfCompose $Context @('exec','-T','app','php') 'Laravel probe' -InputFile $file
    } finally { Remove-PerfTemp $temp }
}
