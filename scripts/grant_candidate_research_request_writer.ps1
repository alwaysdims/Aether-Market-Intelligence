[CmdletBinding()]
param(
    [string]$Database = 'project_bot_recovered_20261115_022935'
)

$ErrorActionPreference = 'Stop'
$psql = 'C:\Program Files\PostgreSQL\16\bin\psql.exe'
$root = 'E:\Aether-Market-Intelligence'
$receiptDir = Join-Path $root 'storage\incidents\20261116_dashboard_research'
$timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$receipt = Join-Path $receiptDir "research_requests_writer_acl_$timestamp.json"

if ($Database -notmatch '^project_bot_recovered_[0-9]{8}_[0-9]{6}$') { throw 'REFUSE: database must be uniquely named recovered candidate.' }
if (-not (Test-Path -LiteralPath $psql)) { throw "Required file missing: $psql" }
New-Item -ItemType Directory -Path $receiptDir -Force | Out-Null

$secure = Read-Host -AsSecureString 'PostgreSQL administrator password (postgres)'
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try { $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) }
finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }

$sql = @"
\set ON_ERROR_STOP on
BEGIN;
DO `$guard$
BEGIN
    IF current_database() <> '$Database' THEN RAISE EXCEPTION 'REFUSE: connected database mismatch: %', current_database(); END IF;
    IF current_user <> 'postgres' THEN RAISE EXCEPTION 'REFUSE: administrator role required'; END IF;
END
`$guard$;
GRANT USAGE ON SCHEMA public TO aether_ingestion;
GRANT SELECT, INSERT, UPDATE ON TABLE public.research_requests TO aether_ingestion;
GRANT USAGE, SELECT ON SEQUENCE public.research_requests_id_seq TO aether_ingestion;
REVOKE DELETE, TRUNCATE, REFERENCES, TRIGGER ON TABLE public.research_requests FROM aether_ingestion;
DO `$verify$
BEGIN
    IF NOT has_table_privilege('aether_ingestion', 'public.research_requests', 'SELECT,INSERT,UPDATE') THEN RAISE EXCEPTION 'VERIFY FAILED: research_requests DML missing'; END IF;
    IF NOT has_sequence_privilege('aether_ingestion', 'public.research_requests_id_seq', 'USAGE,SELECT') THEN RAISE EXCEPTION 'VERIFY FAILED: research_requests sequence missing'; END IF;
    IF has_table_privilege('aether_ingestion', 'public.research_requests', 'DELETE,TRUNCATE') THEN RAISE EXCEPTION 'VERIFY FAILED: destructive privilege present'; END IF;
END
`$verify$;
COMMIT;
SELECT json_build_object(
    'status', 'RESEARCH_REQUEST_WRITER_GRANTED',
    'database', current_database(),
    'executed_by', current_user,
    'role', 'aether_ingestion',
    'object', 'public.research_requests',
    'dml', has_table_privilege('aether_ingestion', 'public.research_requests', 'SELECT,INSERT,UPDATE'),
    'sequence', has_sequence_privilege('aether_ingestion', 'public.research_requests_id_seq', 'USAGE,SELECT'),
    'destructive', has_table_privilege('aether_ingestion', 'public.research_requests', 'DELETE,TRUNCATE'),
    'secret_printed', false
)::text;
"@

try {
    $env:PGPASSWORD = $password
    $output = $sql | & $psql '--host' '127.0.0.1' '--port' '5432' '--username' 'postgres' '--dbname' $Database '--no-align' '--tuples-only' '--quiet' 2>&1
    $exitCode = $LASTEXITCODE
    if ($exitCode -ne 0) { throw "psql failed (exit $exitCode)" }
    $line = ($output | Where-Object { $_ -match '^\{' } | Select-Object -Last 1)
    if (-not $line) { throw 'ACL writer read-back JSON missing' }
    $line | Set-Content -LiteralPath $receipt -Encoding UTF8
    Write-Output $line
    Write-Output "receipt=$receipt"
} finally {
    $password = $null
    Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue
}
