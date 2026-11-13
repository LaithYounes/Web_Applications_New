#Requires -Version 5.1
function Assert-PerfIdentifier([string]$Name) {
    if ($Name -notmatch '^[a-zA-Z0-9_]+$') { throw 'Unsupported SQL identifier.' }
}
function Get-PerfFingerprint {
    param($Context, [string]$Engine, [string]$Database, [string]$WorkDir, [switch]$ReplicaComparison)
    Assert-PerfIdentifier $Database
    $mysql=$Engine -ne 'postgres'
    if ($mysql) {
        $extra=Invoke-PerfSql $Context $Engine "SELECT (SELECT COUNT(*) FROM information_schema.views WHERE table_schema=DATABASE())+(SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema=DATABASE())+(SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE())+(SELECT COUNT(*) FROM information_schema.events WHERE event_schema=DATABASE());" $Database
    } else {
        $extra=Invoke-PerfSql $Context postgres "SELECT (SELECT COUNT(*) FROM pg_tables WHERE schemaname NOT IN ('public','pg_catalog','information_schema'))+(SELECT COUNT(*) FROM pg_views WHERE schemaname='public')+(SELECT COUNT(*) FROM pg_matviews WHERE schemaname='public')+(SELECT COUNT(*) FROM pg_proc WHERE pronamespace='public'::regnamespace);" $Database
    }
    if ((ConvertTo-PerfCount $extra) -ne 0) { throw 'Unsupported extra schema objects; extend fingerprint validation before backing up/restoring this schema.' }
    $sql=if ($mysql) { "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME;" }
         else { "SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename;" }
    $tables=@((Invoke-PerfSql $Context $Engine $sql $Database) -split '\r?\n' | Where-Object { $_ })
    $required=if ($mysql) { @('migrations','users','products','orders','order_items') } else { @('inventory_stock','inventory_reservations') }
    foreach ($t in $required) { if ($tables -notcontains $t) { throw "Required table missing: $Engine/$t" } }
    $result=@()
    foreach ($t in $tables) {
        Assert-PerfIdentifier $t
        $count=ConvertTo-PerfCount (Invoke-PerfSql $Context $Engine "SELECT COUNT(*) FROM $t;" $Database)
        if ($mysql) {
            $schema=Invoke-PerfSql $Context $Engine "SHOW CREATE TABLE $t;" $Database
            # MySQL dump/reload makes an implicit column charset explicit. COLLATE still
            # identifies the charset completely, so normalize only this redundant spelling.
            $schema=[regex]::Replace($schema,' CHARACTER SET [a-z0-9_]+(?= COLLATE [a-z0-9_]+)','')
            if ($ReplicaComparison) { $schema=[regex]::Replace($schema,' AUTO_INCREMENT=\d+','') }
            $columns=@((Invoke-PerfSql $Context $Engine "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$t' ORDER BY ORDINAL_POSITION;" $Database) -split '\r?\n')
            foreach ($c in $columns) { Assert-PerfIdentifier $c }
            $cols=($columns | ForEach-Object { [char]96+$_+[char]96 }) -join ','
            $rows="SELECT SHA2(CAST(JSON_ARRAY($cols) AS CHAR CHARACTER SET utf8mb4),256) AS h FROM $t ORDER BY h;"
        } else {
            $schema=Invoke-PerfSql $Context postgres @"
SELECT 'column|'||ordinal_position||'|'||column_name||'|'||data_type||'|'||is_nullable||'|'||coalesce(column_default,'')||'|'||coalesce(character_maximum_length::text,'') FROM information_schema.columns WHERE table_schema='public' AND table_name='$t' ORDER BY ordinal_position;
SELECT 'constraint|'||conname||'|'||pg_get_constraintdef(oid)||'|'||convalidated FROM pg_constraint WHERE conrelid=('public.$t')::regclass ORDER BY conname;
SELECT 'index|'||indexname||'|'||indexdef FROM pg_indexes WHERE schemaname='public' AND tablename='$t' ORDER BY indexname;
"@ $Database
            # PostgreSQL 16 dump/reparse moves the same varchar-to-text cast from
            # an enum array onto its elements. Normalize ONLY these two exact known
            # status-check definitions; changed values/operators remain mismatches.
            $enumChecks=@(
                "CHECK (((status)::text = ANY ((ARRAY['ACTIVE'::character varying, 'RELEASED'::character varying, 'COMMITTED'::character varying])::text[])))",
                "CHECK (((status)::text = ANY (ARRAY[('ACTIVE'::character varying)::text, ('RELEASED'::character varying)::text, ('COMMITTED'::character varying)::text])))"
            )
            if ($t -eq 'inventory_reservations' -and -not @($enumChecks | Where-Object { $schema.Contains($_) }).Count) { throw 'Required reservation status constraint is missing or changed.' }
            foreach ($definition in $enumChecks) { $schema=$schema.Replace($definition,"CHECK (status IN ('ACTIVE','RELEASED','COMMITTED'))") }
            $rows="SELECT encode(sha256(convert_to(row_to_json(t)::text,'UTF8')),'hex') AS h FROM public.$t t ORDER BY h;"
        }
        $schemaFile=Join-Path $WorkDir 'schema.txt'
        [IO.File]::WriteAllText($schemaFile,$schema,(New-Object Text.UTF8Encoding($false)))
        $dataFile=Join-Path $WorkDir 'row-hashes.txt'
        $null=Invoke-PerfSql $Context $Engine $rows $Database -OutputFile $dataFile
        $result += [ordered]@{ table=$t; count=$count; schema=(Get-FileHash -LiteralPath $schemaFile -Algorithm SHA256).Hash; data=(Get-FileHash -LiteralPath $dataFile -Algorithm SHA256).Hash }
    }
    if ($mysql) {
        $index=Invoke-PerfSql $Context $Engine "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='orders' AND index_name='orders_created_at_index';" $Database
        if ((ConvertTo-PerfCount $index) -ne 1) { throw 'Required orders performance index missing.' }
        # FOREIGN_KEY_CHECKS=1 does not retroactively validate imported rows.
        $checks=Invoke-PerfSql $Context $Engine @"
SELECT CONCAT('SELECT COUNT(*) FROM ',TABLE_NAME,' c LEFT JOIN ',REFERENCED_TABLE_NAME,' p ON ',
GROUP_CONCAT(CONCAT('c.',COLUMN_NAME,'=p.',REFERENCED_COLUMN_NAME) ORDER BY ORDINAL_POSITION SEPARATOR ' AND '),
' WHERE ',GROUP_CONCAT(CONCAT('c.',COLUMN_NAME,' IS NOT NULL') ORDER BY ORDINAL_POSITION SEPARATOR ' AND '),
' AND p.',MIN(REFERENCED_COLUMN_NAME),' IS NULL;')
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
GROUP BY TABLE_NAME,REFERENCED_TABLE_NAME,CONSTRAINT_NAME ORDER BY TABLE_NAME,CONSTRAINT_NAME;
"@ $Database
        foreach ($check in ($checks -split '\r?\n' | Where-Object { $_ })) {
            if ((ConvertTo-PerfCount (Invoke-PerfSql $Context $Engine $check $Database)) -ne 0) { throw 'MySQL foreign-key orphan validation failed.' }
        }
    } else {
        $checks=Invoke-PerfSql $Context postgres "SELECT COUNT(*) FROM pg_constraint WHERE connamespace='public'::regnamespace AND conname IN ('chk_inventory_total_quantity','chk_inventory_reserved_quantity','uk_inventory_stock_product','uk_reservation_product','inventory_reservations_status_check') AND convalidated;" $Database
        if ((ConvertTo-PerfCount $checks) -ne 5) { throw 'Required inventory check/unique constraints missing.' }
        $invalid=Invoke-PerfSql $Context postgres "SELECT COUNT(*) FROM pg_constraint WHERE connamespace='public'::regnamespace AND NOT convalidated;" $Database
        if ((ConvertTo-PerfCount $invalid) -ne 0) { throw 'PostgreSQL has unvalidated constraints.' }
        $invalid=Invoke-PerfSql $Context postgres "SELECT COUNT(*) FROM inventory_stock WHERE total_quantity<0 OR reserved_quantity<0 OR reserved_quantity>total_quantity; SELECT COUNT(*) FROM inventory_reservations WHERE quantity<=0 OR status NOT IN ('ACTIVE','RELEASED','COMMITTED');" $Database
        if ($invalid -notmatch '^0\r?\n0$') { throw 'Inventory quantity/status validation failed.' }
        $seqs=Invoke-PerfSql $Context postgres "SELECT sequencename FROM pg_sequences WHERE schemaname='public' ORDER BY sequencename;" $Database
        foreach ($s in ($seqs -split '\r?\n' | Where-Object { $_ })) {
            Assert-PerfIdentifier $s
            $state=Invoke-PerfSql $Context postgres "SELECT last_value||'|'||is_called FROM $s;" $Database
            $result += [ordered]@{ sequence=$s; state=$state }
        }
    }
    # Capture indexes, checks, unique keys, FK definitions and every row, including non-order tables.
    return ,$result
}
function Assert-PerfFingerprint {
    param($Expected,$Actual,[string]$Stage)
    $a=ConvertTo-Json -InputObject @($Expected) -Depth 12 -Compress
    $b=ConvertTo-Json -InputObject @($Actual) -Depth 12 -Compress
    if ($a -cne $b) {
        $different=@()
        foreach ($entry in @($Expected)) {
            $table=if ($entry -is [Collections.IDictionary]) { $entry['table'] } else { $entry.table }
            $sequence=if ($entry -is [Collections.IDictionary]) { $entry['sequence'] } else { $entry.sequence }
            $other=@($Actual | Where-Object { if ($table) { $_.table -eq $table } else { $_.sequence -eq $sequence } })
            $label=if ($table) { $table } else { $sequence }
            if ($other.Count -ne 1) { $different += "$label/missing"; continue }
            foreach ($field in @('count','schema','data','state')) {
                $wanted=if ($entry -is [Collections.IDictionary]) { $entry[$field] } else { $entry.$field }
                $found=if ($other[0] -is [Collections.IDictionary]) { $other[0][$field] } else { $other[0].$field }
                if ("$wanted" -cne "$found") { $different += "$label/$field" }
            }
        }
        # Object/field names only; never emit row values, credentials or dump contents.
        throw "Fingerprint mismatch: $Stage [$($different -join ', ')] (schema, data, counts or sequence state)."
    }
}
function Export-PerfDatabases {
    param($Context,[string]$Directory)
    $sql=Join-Path $Directory 'mysql.sql'
    $gz=Join-Path $Directory 'mysql.sql.gz'
    $pg=Join-Path $Directory 'postgres.dump'
    $remote='/tmp/perf-owned-'+[guid]::NewGuid().ToString('N')+'.dump'
    $pgId=Assert-PerfIdentity $Context 'inventory-db'
    $null=Invoke-PerfCompose $Context @('exec','-T','mysql','sh','-ec','MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --default-character-set=utf8mb4 --single-transaction --routines --triggers --hex-blob --no-tablespaces ecommerce_main_perf') 'MySQL dump' -OutputFile $sql
    $input=[IO.File]::OpenRead($sql); $output=[IO.File]::Create($gz)
    $zip=New-Object IO.Compression.GZipStream($output,[IO.Compression.CompressionMode]::Compress)
    try { $input.CopyTo($zip) } finally { $zip.Dispose(); $output.Dispose(); $input.Dispose() }
    Remove-Item -LiteralPath $sql -Force
    try {
        $null=Invoke-PerfCompose $Context @('exec','-T','inventory-db','pg_dump','-U','inventory_perf_user','-d','ecommerce_inventory_perf','--no-owner','--no-privileges','-Fc','-f',$remote) 'PostgreSQL dump'
        $null=Invoke-PerfNative @('cp',($pgId+':'+$remote),$pg) -Stage 'PostgreSQL dump copy'
    } finally { $null=Invoke-PerfCompose $Context @('exec','-T','inventory-db','rm','-f',$remote) 'dump cleanup' }
}
function Expand-PerfMysql {
    param([string]$Source,[string]$Destination)
    $input=[IO.File]::OpenRead($Source); $out=[IO.File]::Create($Destination)
    $gz=New-Object IO.Compression.GZipStream($input,[IO.Compression.CompressionMode]::Decompress)
    try { $gz.CopyTo($out) } finally { $gz.Dispose(); $input.Dispose(); $out.Dispose() }
    if ((Get-Item -LiteralPath $Destination).Length -eq 0) { throw 'Empty MySQL dump.' }
    $reader=[IO.File]::OpenText($Destination)
    try {
        while ($null -ne ($line=$reader.ReadLine())) {
            if ($line -match '^\s*(USE\s|(?:CREATE|DROP|ALTER)\s+DATABASE\b|SOURCE\s|\\!|system\s)' -or $line -match '^\s*/\*!\d*\s*(USE\s|(?:CREATE|DROP|ALTER)\s+DATABASE\b)') { throw 'Database-changing/client commands in MySQL dump are forbidden.' }
        }
    } finally { $reader.Dispose() }
}
function Import-PerfMysql {
    param($Context,[string]$Database,[string]$File)
    Assert-PerfIdentifier $Database
    $null=Invoke-PerfCompose $Context @('exec','-T','mysql','sh','-ec','MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --binary-mode --default-character-set=utf8mb4 "$@"','import',$Database) 'MySQL import' -InputFile $File
}
function Import-PerfPostgres {
    param($Context,[string]$Database,[string]$File)
    Assert-PerfIdentifier $Database
    $id=Assert-PerfIdentity $Context 'inventory-db'
    $remote='/tmp/perf-owned-'+[guid]::NewGuid().ToString('N')+'.dump'
    try {
        $null=Invoke-PerfNative @('cp',$File,($id+':'+$remote)) -Stage 'PostgreSQL restore copy'
        $null=Invoke-PerfCompose $Context @('exec','-T','inventory-db','pg_restore','-U','inventory_perf_user','-d',$Database,'--exit-on-error','--single-transaction','--no-owner','--no-privileges',$remote) 'PostgreSQL restore'
    } finally { $null=Invoke-PerfCompose $Context @('exec','-T','inventory-db','rm','-f',$remote) 'restore file cleanup' }
}
function Read-PerfManifest {
    param([string]$Directory)
    $root=(Resolve-Path -LiteralPath $Directory).Path
    $file=Join-Path $root 'MANIFEST.json'
    $manifestItem=Get-Item -LiteralPath $file
    if ($manifestItem.PSIsContainer -or ($manifestItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Manifest must be a regular file.' }
    $m=Get-Content -LiteralPath $file -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($m.version -ne 2 -or $m.mysql_database -ne 'ecommerce_main_perf' -or $m.postgres_database -ne 'ecommerce_inventory_perf' -or
        -not $m.mysql_fingerprint -or -not $m.postgres_fingerprint -or @($m.files).Count -ne 2) { throw 'Invalid/unsupported manifest (version 2 with both fingerprints and two checksums required).' }
    $names=@()
    foreach ($f in $m.files) {
        # Literal leaf names only. No path traversal, ADS, absolute paths, wildcard expansion or symlinks.
        if (-not $f.name -or $f.name -match '[\\/:*?\"<>|]' -or $f.name -in @('.','..') -or $f.name -match '[. ]$' -or $f.sha256 -notmatch '^[A-Fa-f0-9]{64}$' -or $names -contains $f.name) { throw 'Invalid manifest file entry.' }
        $names += $f.name
        $p=Join-Path $root $f.name
        $item=Get-Item -LiteralPath $p
        if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Only regular backup files are accepted.' }
        if ((Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash -ne $f.sha256) { throw 'Backup checksum mismatch.' }
    }
    if ($names -notcontains $m.mysql_dump -or $names -notcontains $m.postgres_dump -or $m.mysql_dump -eq $m.postgres_dump -or $m.mysql_dump -notmatch '\.sql\.gz$' -or $m.postgres_dump -notmatch '\.dump$') { throw 'Missing required dump checksums or invalid format.' }
    return $m
}
