param(
    [Parameter(Mandatory = $true)]
    [string]$VerificationDatabase,

    [string]$SourceDatabase = 'project_bot_recovery_20261110',

    [string]$AdminUser = 'postgres'
)

$ErrorActionPreference = 'Stop'
$psql = 'C:\Program Files\PostgreSQL\16\bin\psql.exe'
$timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$receiptDirectory = 'E:\Aether-Market-Intelligence\storage\incidents\20261110_operational_data_loss'
$receiptPath = Join-Path $receiptDirectory "recovery-existing-restore-verification-$timestamp.json"

if (-not (Test-Path -LiteralPath $psql)) {
    throw "Required file unavailable: $psql"
}

if ($SourceDatabase -eq $VerificationDatabase) {
    throw 'SourceDatabase and VerificationDatabase must differ.'
}

$adminSecure = Read-Host -AsSecureString 'PostgreSQL administrator password'
$adminPtr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($adminSecure)
try {
    $adminPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($adminPtr)
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($adminPtr)
}

function Invoke-AdminPsql {
    param(
        [Parameter(Mandatory = $true)][string]$Database,
        [Parameter(Mandatory = $true)][string]$Query
    )

    $previous = $env:PGPASSWORD
    try {
        $env:PGPASSWORD = $adminPassword
        $output = & $psql '--host' '127.0.0.1' '--port' '5432' '--username' $AdminUser '--dbname' $Database '--tuples-only' '--no-align' '--quiet' '--command' $Query 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw "PostgreSQL query failed against $Database (exit $LASTEXITCODE)"
        }

        return (($output -join "`n").Trim())
    } finally {
        if ($null -eq $previous) {
            Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue
        } else {
            $env:PGPASSWORD = $previous
        }
    }
}

function Invoke-AdminJson {
    param(
        [Parameter(Mandatory = $true)][string]$Database,
        [Parameter(Mandatory = $true)][string]$Query
    )

    $raw = Invoke-AdminPsql $Database $Query
    if ([string]::IsNullOrWhiteSpace($raw)) {
        throw "Empty JSON result from $Database."
    }

    return $raw | ConvertFrom-Json
}

function Quote-Identifier {
    param([Parameter(Mandatory = $true)][string]$Identifier)

    return '"' + $Identifier.Replace('"', '""') + '"'
}

function Get-TableCounts {
    param([Parameter(Mandatory = $true)][string]$Database)

    $raw = Invoke-AdminPsql $Database @'
SELECT table_name
FROM information_schema.tables
WHERE table_schema = 'public'
  AND table_type = 'BASE TABLE'
ORDER BY table_name;
'@
    $tables = @($raw -split "`n" | Where-Object { $_ -ne '' })
    $counts = [ordered]@{}

    foreach ($table in $tables) {
        $qualifiedTable = 'public.' + (Quote-Identifier $table)
        $counts[$table] = [Int64](Invoke-AdminPsql $Database "SELECT count(*) FROM $qualifiedTable;")
    }

    return $counts
}

function Get-Snapshot {
    param([Parameter(Mandatory = $true)][string]$Database)

    $identity = Invoke-AdminJson $Database @'
SELECT json_build_object(
  'database', current_database(),
  'user', current_user,
  'schema', current_schema(),
  'server_version_num', current_setting('server_version_num')
)::text;
'@
    if ($identity.database -ne $Database -or $identity.user -ne $AdminUser) {
        throw "Connection identity mismatch for $Database."
    }

    $summary = Invoke-AdminJson $Database @'
SELECT json_build_object(
  'tables', (SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'),
  'indexes', (SELECT count(*) FROM pg_index ix JOIN pg_class table_rel ON table_rel.oid = ix.indrelid JOIN pg_namespace n ON n.oid = table_rel.relnamespace WHERE n.nspname = 'public'),
  'foreign_keys', (SELECT count(*) FROM pg_constraint con JOIN pg_class table_rel ON table_rel.oid = con.conrelid JOIN pg_namespace n ON n.oid = table_rel.relnamespace WHERE n.nspname = 'public' AND con.contype = 'f'),
  'sequences', (SELECT count(*) FROM pg_sequences WHERE schemaname = 'public'),
  'market_observations', (SELECT count(*) FROM public.market_observations),
  'registries', (SELECT count(*) FROM public.idx_company_registries),
  'max_bar_date', (SELECT max(bar_date)::text FROM public.market_observations),
  'evidence_snapshots', (SELECT count(*) FROM public.evidence_snapshots),
  'prediction_signals', (SELECT count(*) FROM public.prediction_signals)
)::text;
'@

    $constraints = Invoke-AdminJson $Database @'
SELECT coalesce(json_agg(item ORDER BY item->>'key'), '[]'::json)::text
FROM (
  SELECT json_build_object(
    'key', n.nspname || '.' || table_rel.relname || '.' || con.conname,
    'type', con.contype,
    'definition', pg_get_constraintdef(con.oid, true)
  ) AS item
  FROM pg_constraint con
  JOIN pg_class table_rel ON table_rel.oid = con.conrelid
  JOIN pg_namespace n ON n.oid = table_rel.relnamespace
  WHERE n.nspname = 'public'
    AND con.contype IN ('p', 'u', 'f', 'c', 'x')
) q;
'@

    $notNull = Invoke-AdminJson $Database @'
SELECT coalesce(json_agg(item ORDER BY item->>'key'), '[]'::json)::text
FROM (
  SELECT json_build_object(
    'key', n.nspname || '.' || table_rel.relname || '.' || attr.attname,
    'not_null', attr.attnotnull
  ) AS item
  FROM pg_attribute attr
  JOIN pg_class table_rel ON table_rel.oid = attr.attrelid
  JOIN pg_namespace n ON n.oid = table_rel.relnamespace
  WHERE n.nspname = 'public'
    AND attr.attnum > 0
    AND NOT attr.attisdropped
    AND attr.attnotnull
) q;
'@

    $indexes = Invoke-AdminJson $Database @'
SELECT coalesce(json_agg(item ORDER BY item->>'key'), '[]'::json)::text
FROM (
  SELECT json_build_object(
    'key', n.nspname || '.' || table_rel.relname || '.' || index_rel.relname,
    'definition', pg_get_indexdef(index_rel.oid)
  ) AS item
  FROM pg_index ix
  JOIN pg_class index_rel ON index_rel.oid = ix.indexrelid
  JOIN pg_class table_rel ON table_rel.oid = ix.indrelid
  JOIN pg_namespace n ON n.oid = table_rel.relnamespace
  WHERE n.nspname = 'public'
) q;
'@

    $sequences = Invoke-AdminJson $Database @'
SELECT coalesce(json_agg(item ORDER BY item->>'key'), '[]'::json)::text
FROM (
  SELECT json_build_object(
    'key', schemaname || '.' || sequencename,
    'start_value', start_value,
    'min_value', min_value,
    'max_value', max_value,
    'increment_by', increment_by,
    'cycle', cycle,
    'cache_size', cache_size,
    'last_value', last_value
  ) AS item
  FROM pg_sequences
  WHERE schemaname = 'public'
) q;
'@

    return [pscustomobject]@{
        identity = $identity
        summary = $summary
        table_counts = Get-TableCounts $Database
        constraints = $constraints
        not_null_constraints = $notNull
        indexes = $indexes
        sequences = $sequences
    }
}

function Convert-CanonicalJson {
    param([Parameter(Mandatory = $true)]$Value)

    return $Value | ConvertTo-Json -Depth 30 -Compress
}

try {
    $source = Get-Snapshot $SourceDatabase
    $restored = Get-Snapshot $VerificationDatabase
    $comparisons = [ordered]@{
        server_version = $source.identity.server_version_num -eq $restored.identity.server_version_num
        summary = (Convert-CanonicalJson $source.summary) -eq (Convert-CanonicalJson $restored.summary)
        table_counts = (Convert-CanonicalJson $source.table_counts) -eq (Convert-CanonicalJson $restored.table_counts)
        constraints = (Convert-CanonicalJson $source.constraints) -eq (Convert-CanonicalJson $restored.constraints)
        not_null_constraints = (Convert-CanonicalJson $source.not_null_constraints) -eq (Convert-CanonicalJson $restored.not_null_constraints)
        indexes = (Convert-CanonicalJson $source.indexes) -eq (Convert-CanonicalJson $restored.indexes)
        sequences = (Convert-CanonicalJson $source.sequences) -eq (Convert-CanonicalJson $restored.sequences)
    }
    $verified = -not ($comparisons.Values -contains $false)

    if (-not (Test-Path -LiteralPath $receiptDirectory)) {
        New-Item -ItemType Directory -Path $receiptDirectory -Force | Out-Null
    }

    $result = [pscustomobject]@{
        status = if ($verified) { 'VERIFIED' } else { 'MISMATCH' }
        mode = 'VERIFY_EXISTING_READ_ONLY'
        source_database = $SourceDatabase
        verification_database = $VerificationDatabase
        comparisons = $comparisons
        source = $source
        restored = $restored
        secret_printed = $false
    }
    $result | ConvertTo-Json -Depth 40 | Set-Content -LiteralPath $receiptPath -Encoding utf8
    $result | Add-Member -NotePropertyName receipt_path -NotePropertyValue $receiptPath
    $result | ConvertTo-Json -Depth 40

    if (-not $verified) {
        exit 3
    }
} finally {
    $adminPassword = ''
}
