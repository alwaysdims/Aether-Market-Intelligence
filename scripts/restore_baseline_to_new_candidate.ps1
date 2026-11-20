param(
    [string]$CandidateDatabase = ('project_bot_recovered_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
)

$ErrorActionPreference = 'Stop'
$root = 'E:\Aether-Market-Intelligence'
$expectedHash = 'd0ee1046660fd4222f4773d9d5730107025d241633063ce0c58cd8ec5174d343'
$dump = Join-Path $root 'storage\backups\incident_20261110\project_bot_recovery_20261110_pre_recovery_20261113_124849.dump'
$pgBin = 'C:\Program Files\PostgreSQL\16\bin'
$createdb = Join-Path $pgBin 'createdb.exe'
$pgRestore = Join-Path $pgBin 'pg_restore.exe'
$psql = Join-Path $pgBin 'psql.exe'
$receiptDir = Join-Path $root 'storage\incidents\20261114_test_runner_wipe'
$receipt = Join-Path $receiptDir ("baseline_candidate_restore_{0}.json" -f (Get-Date -Format 'yyyyMMdd_HHmmss'))

if ($CandidateDatabase -notmatch '^project_bot_recovered_[0-9]{8}_[0-9]{6}$') {
    throw 'CandidateDatabase must match project_bot_recovered_yyyyMMdd_HHmmss.'
}
foreach ($path in @($dump, $createdb, $pgRestore, $psql)) {
    if (-not (Test-Path -LiteralPath $path)) { throw "Required file missing: $path" }
}
$hash = (Get-FileHash -LiteralPath $dump -Algorithm SHA256).Hash.ToLowerInvariant()
if ($hash -ne $expectedHash) { throw "ABORT dump SHA-256 mismatch: $hash" }

$secure = Read-Host -AsSecureString 'PostgreSQL administrator password (postgres)'
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try { $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) }
finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }

function Invoke-Pg {
    param([string]$Exe, [string[]]$Arguments)
    $env:PGPASSWORD = $password
    & $Exe @Arguments
    if ($LASTEXITCODE -ne 0) { throw "PostgreSQL command failed: $Exe (exit $LASTEXITCODE)" }
}

try {
    $exists = (& $psql '--host' '127.0.0.1' '--port' '5432' '--username' 'postgres' '--dbname' 'postgres' '--tuples-only' '--no-align' '--command' "SELECT EXISTS (SELECT 1 FROM pg_database WHERE datname = '$CandidateDatabase');").Trim()
    if ($LASTEXITCODE -ne 0) { throw 'Cannot verify candidate database name.' }
    if ($exists -ne 'f') { throw "ABORT candidate database already exists: $CandidateDatabase" }

    Invoke-Pg $createdb @('--host', '127.0.0.1', '--port', '5432', '--username', 'postgres', '--template', 'template0', $CandidateDatabase)
    Invoke-Pg $pgRestore @('--host', '127.0.0.1', '--port', '5432', '--username', 'postgres', '--dbname', $CandidateDatabase, '--exit-on-error', '--no-owner', '--no-privileges', $dump)

    $query = @'
SELECT json_build_object(
  'database', current_database(),
  'role', current_user,
  'tables', (SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE'),
  'indexes', (SELECT count(*) FROM pg_index ix JOIN pg_class tbl ON tbl.oid=ix.indrelid JOIN pg_namespace ns ON ns.oid=tbl.relnamespace WHERE ns.nspname='public'),
  'foreign_keys', (SELECT count(*) FROM pg_constraint con JOIN pg_class tbl ON tbl.oid=con.conrelid JOIN pg_namespace ns ON ns.oid=tbl.relnamespace WHERE ns.nspname='public' AND con.contype='f'),
  'sequences', (SELECT count(*) FROM pg_sequences WHERE schemaname='public'),
  'market_observations', (SELECT count(*) FROM public.market_observations),
  'registries', (SELECT count(*) FROM public.idx_company_registries),
  'max_bar_date', (SELECT max(bar_date)::text FROM public.market_observations),
  'evidence_snapshots', (SELECT count(*) FROM public.evidence_snapshots),
  'prediction_signals', (SELECT count(*) FROM public.prediction_signals)
)::text;
'@
    $raw = & $psql '--host' '127.0.0.1' '--port' '5432' '--username' 'postgres' '--dbname' $CandidateDatabase '--tuples-only' '--no-align' '--quiet' '--command' $query
    if ($LASTEXITCODE -ne 0) { throw 'Candidate verification query failed.' }
    $summary = (($raw -join "`n").Trim() | ConvertFrom-Json)
    $expected = [ordered]@{ tables=39; indexes=163; foreign_keys=15; sequences=34; market_observations=513381; registries=500; max_bar_date='2026-11-06'; evidence_snapshots=151; prediction_signals=16 }
    $matches = [ordered]@{}
    foreach ($key in $expected.Keys) { $matches[$key] = ($summary.$key -eq $expected[$key]) }
    $verified = -not ($matches.Values -contains $false)
    $result = [ordered]@{
        status = if ($verified) { 'VERIFIED_BASELINE_CANDIDATE' } else { 'MISMATCH' }
        candidate_database = $CandidateDatabase
        dump_path = $dump
        dump_sha256 = $hash
        expected = $expected
        restored = $summary
        comparisons = $matches
        grants_applied = $false
        migrations_applied = $false
        old_outbox_redispatched = $false
        secret_printed = $false
    }
    $result | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $receipt -Encoding utf8
    $result | Add-Member -NotePropertyName receipt_path -NotePropertyValue $receipt
    $result | ConvertTo-Json -Depth 5
    if (-not $verified) { exit 3 }
} finally {
    $password = $null
    Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue
}
