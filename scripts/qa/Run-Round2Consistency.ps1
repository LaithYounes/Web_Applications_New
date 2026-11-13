param(
    [string]$EvidenceDirectory = (Join-Path $env:TEMP ('round2-evidence-' + [guid]::NewGuid().ToString('N')))
)

$ErrorActionPreference = 'Stop'
$repo = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$id = 'round2-' + [guid]::NewGuid().ToString('N').Substring(0, 12)
$network = "$id-net"
$primary = "$id-primary"
$replica = "$id-replica"
$redis = "$id-redis"
$database = $id.Replace('-', '_')
$tempRoot = Join-Path $env:TEMP $id
$null = New-Item -ItemType Directory -Path $tempRoot
$null = New-Item -ItemType Directory -Force -Path $EvidenceDirectory
$password = [guid]::NewGuid().ToString('N')
$envFile = Join-Path $tempRoot 'mysql.env'
@("MYSQL_ROOT_PASSWORD=$password", 'MYSQL_ROOT_HOST=%', "MYSQL_DATABASE=$database") | Set-Content -LiteralPath $envFile
$containers = @()
$networkCreated = $false
$oldManifest = $env:ROUND2_TEST_MANIFEST

function Docker-Checked([string[]]$Arguments) {
    $result = & docker @Arguments 2>&1
    if ($LASTEXITCODE -ne 0) { throw "Docker operation failed: $($Arguments[0])" }
    return $result
}

function Sql([string]$Container, [string]$Query) {
    $result = $Query | & docker exec -i $Container sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -B' 2>&1
    if ($LASTEXITCODE -ne 0) { throw "SQL failed on isolated container $Container" }
    return $result
}

try {
    $null = Docker-Checked @('network', 'create', '--label', "codex.round2=$id", $network)
    $networkCreated = $true
    foreach ($spec in @(@($primary, 'primary', '101'), @($replica, 'replica', '102'))) {
        $null = Docker-Checked @('run', '-d', '--name', $spec[0], '--label', "codex.round2=$id",
            '--network', $network, '--network-alias', $spec[1], '--env-file', $envFile,
            '--tmpfs', '/var/lib/mysql:rw', '-p', '127.0.0.1::3306', 'mysql:8.4',
            "--server-id=$($spec[2])", '--log-bin=mysql-bin', '--binlog-format=ROW',
            '--gtid-mode=ON', '--enforce-gtid-consistency=ON')
        $containers += $spec[0]
    }
    $null = Docker-Checked @('run', '-d', '--name', $redis, '--label', "codex.round2=$id",
        '--network', $network, '--tmpfs', '/data:rw', '-p', '127.0.0.1::6379',
        'redis:7-alpine', 'redis-server', '--save', '', '--appendonly', 'no')
    $containers += $redis
    foreach ($container in @($primary, $replica)) {
        $ready = $false
        for ($attempt = 0; $attempt -lt 90; $attempt++) {
            try { $null = Sql $container 'SELECT 1;'; $ready = $true; break } catch { Start-Sleep -Milliseconds 1000 }
        }
        if (-not $ready) { throw "MySQL not ready: $container" }
    }
    $null = Sql $replica "CHANGE REPLICATION SOURCE TO SOURCE_HOST='primary', SOURCE_USER='root', SOURCE_PASSWORD='$password', SOURCE_AUTO_POSITION=1, GET_SOURCE_PUBLIC_KEY=1; START REPLICA; SET GLOBAL read_only=ON; SET GLOBAL super_read_only=ON;"
    $manifest = @{
        run_id = $id; database = $database; password = $password
        primary_port = [int]((Docker-Checked @('port', $primary, '3306/tcp')) -split ':')[-1]
        replica_port = [int]((Docker-Checked @('port', $replica, '3306/tcp')) -split ':')[-1]
        redis_port = [int]((Docker-Checked @('port', $redis, '6379/tcp')) -split ':')[-1]
        primary_uuid = [string](Sql $primary 'SELECT @@server_uuid;')
        replica_uuid = [string](Sql $replica 'SELECT @@server_uuid;')
    }
    $manifestPath = Join-Path $tempRoot 'manifest.json'
    $manifest | ConvertTo-Json | Set-Content -LiteralPath $manifestPath
    $env:ROUND2_TEST_MANIFEST = $manifestPath
    Push-Location (Join-Path $repo 'main-app/laravel')
    try {
        & php vendor/phpunit/phpunit/phpunit --colors=never --log-junit (Join-Path $EvidenceDirectory 'mysql-integration.xml') tests/Integration/CatalogOrdersMySqlTest.php 2>&1 |
            Tee-Object -FilePath (Join-Path $EvidenceDirectory 'mysql-integration.log')
        if ($LASTEXITCODE -ne 0) { throw 'Round 2 MySQL integration failed; see evidence log.' }
    } finally { Pop-Location }
} finally {
    $env:ROUND2_TEST_MANIFEST = $oldManifest
    # Remove only resources created by this invocation and verified by label.
    foreach ($container in $containers) {
        $label = & docker inspect --format '{{ index .Config.Labels "codex.round2" }}' $container 2>$null
        if ($LASTEXITCODE -eq 0 -and $label -eq $id) {
            $null = Docker-Checked @('rm', '-f', '-v', $container)
        }
    }
    if ($networkCreated) {
        $label = & docker network inspect --format '{{ index .Labels "codex.round2" }}' $network
        if ($label -eq $id) { $null = Docker-Checked @('network', 'rm', $network) }
    }
    $resolvedTemp = [IO.Path]::GetFullPath($tempRoot)
    $expectedTemp = [IO.Path]::GetFullPath((Join-Path $env:TEMP $id))
    if ($resolvedTemp -eq $expectedTemp -and $id -match '^round2-[a-f0-9]{12}$') {
        Remove-Item -LiteralPath $resolvedTemp -Recurse -Force
    }
    [ordered]@{ run_id = $id; containers = $containers; network = $network; cleaned = $true } |
        ConvertTo-Json | Set-Content -LiteralPath (Join-Path $EvidenceDirectory 'cleanup.json')
}
