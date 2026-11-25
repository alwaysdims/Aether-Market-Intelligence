[CmdletBinding()]
param(
    [string]$Database = 'project_bot_recovered_20261115_022935',
    [switch]$Apply
)

$ErrorActionPreference = 'Stop'
$psql = 'C:\Program Files\PostgreSQL\16\bin\psql.exe'
$root = 'E:\Aether-Market-Intelligence'
$role = 'aether_ingestion'
$receiptDir = Join-Path $root 'storage\runtime\acl-receipts'
$timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$receipt = Join-Path $receiptDir "runtime_minimum_acl_$timestamp.json"

if ($Database -notmatch '^project_bot_recovered_[0-9]{8}_[0-9]{6}$') { throw 'REFUSE: database must be uniquely named recovered candidate.' }
if (-not (Test-Path -LiteralPath $psql)) { throw "Required file missing: $psql" }

$selectTables = @(
    'research_requests', 'evidence_snapshots', 'market_analyses', 'analysis_runs',
    'company_fundamentals', 'user_watchlists', 'post_horizon_evaluations',
    'system_health_checks', 'scheduled_telegram_jobs', 'telegram_conversations',
    'telegram_outbox', 'notification_logs', 'market_observations',
    'idx_company_registries', 'prediction_signals', 'monitoring_events'
)
$writerTables = @('research_requests', 'evidence_snapshots', 'market_analyses', 'analysis_runs', 'scheduled_telegram_jobs', 'telegram_conversations', 'telegram_outbox', 'notification_logs', 'system_health_checks')

Write-Output "Target database: $Database"
Write-Output "Target role: $role"
Write-Output 'Minimum grants: USAGE on schema public; SELECT on application read tables.'
Write-Output ('Writer grants: SELECT, INSERT, UPDATE on: ' + ($writerTables -join ', '))
Write-Output 'Forbidden: DELETE, TRUNCATE, REFERENCES, TRIGGER, DDL, SUPERUSER.'
if (-not $Apply) {
    Write-Output 'Review only. Re-run with -Apply to execute local masked password prompt.'
    exit 0
}

New-Item -ItemType Directory -Path $receiptDir -Force | Out-Null
$secure = Read-Host -AsSecureString 'PostgreSQL administrator password (postgres)'
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try { $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) }
finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }

$selectSql = ($selectTables | ForEach-Object { "GRANT SELECT ON TABLE public.$_ TO $role;" }) -join "`n"
$writerSql = ($writerTables | ForEach-Object { "GRANT INSERT, UPDATE ON TABLE public.$_ TO $role; REVOKE DELETE, TRUNCATE, REFERENCES, TRIGGER ON TABLE public.$_ FROM $role;" }) -join "`n"
$verifySql = ($selectTables | ForEach-Object { "SELECT '$_' AS table_name, has_table_privilege('$role', 'public.$_', 'SELECT') AS select_priv, has_table_privilege('$role', 'public.$_', 'INSERT,UPDATE') AS dml_priv, has_table_privilege('$role', 'public.$_', 'DELETE,TRUNCATE') AS destructive_priv;" }) -join "`n"
$sql = @"
\set ON_ERROR_STOP on
BEGIN;
DO `$guard$
BEGIN
    IF current_database() <> '$Database' THEN RAISE EXCEPTION 'REFUSE: connected database mismatch'; END IF;
    IF current_user <> 'postgres' THEN RAISE EXCEPTION 'REFUSE: administrator role required'; END IF;
END
`$guard$;
GRANT USAGE ON SCHEMA public TO $role;
$selectSql
$writerSql
COMMIT;
$verifySql
"@

try {
    $env:PGPASSWORD = $password
    $output = $sql | & $psql '--host' '127.0.0.1' '--port' '5432' '--username' 'postgres' '--dbname' $Database '--no-align' '--tuples-only' 2>&1
    if ($LASTEXITCODE -ne 0) { throw "psql failed (exit $LASTEXITCODE)" }
    $output | Set-Content -LiteralPath $receipt -Encoding UTF8
    Write-Output "Read-back receipt: $receipt"
} finally {
    $password = $null
    Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue
}
