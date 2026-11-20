param(
    [string]$Database = 'project_bot_recovered_20261115_022935'
)

$ErrorActionPreference = 'Stop'
$root = 'E:\Aether-Market-Intelligence'
$php = 'C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe'
$psql = 'C:\Program Files\PostgreSQL\16\bin\psql.exe'
$preflightSql = Join-Path $root 'scripts\apply_candidate_preflight_access.sql'
$accessSql = Join-Path $root 'scripts\apply_candidate_database_access.sql'
$migrationPhp = Join-Path $root 'scripts\apply_recovery_migrations.php'
$runtimeRoot = Join-Path $env:LOCALAPPDATA 'Aether-Market-Intelligence\recovery'
$cachePath = Join-Path $runtimeRoot 'bootstrap-cache\candidate-migration-config.php'
$receiptDir = Join-Path $root 'storage\incidents\20261114_test_runner_wipe'
$timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$receipt = Join-Path $receiptDir "candidate_activation_$timestamp.txt"

if ($Database -notmatch '^project_bot_recovered_[0-9]{8}_[0-9]{6}$') {
    throw 'REFUSE: Database must be a recovered candidate name.'
}
foreach ($path in @($php, $psql, $preflightSql, $accessSql, $migrationPhp)) {
    if (-not (Test-Path -LiteralPath $path)) { throw "Required file missing: $path" }
}
if (-not (Test-Path -LiteralPath (Split-Path -Parent $cachePath))) {
    New-Item -ItemType Directory -Path (Split-Path -Parent $cachePath) -Force | Out-Null
}
if (-not (Test-Path -LiteralPath $receiptDir)) {
    New-Item -ItemType Directory -Path $receiptDir -Force | Out-Null
}

$secure = Read-Host -AsSecureString 'PostgreSQL administrator password (postgres)'
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try { $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) }
finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }

function Invoke-PsqlFile {
    param([string]$DatabaseName, [string]$FilePath)
    $env:PGPASSWORD = $password
    & $psql '--host' '127.0.0.1' '--port' '5432' '--username' 'postgres' '--dbname' $DatabaseName '--set=ON_ERROR_STOP=1' '--file' $FilePath 2>&1
    if ($LASTEXITCODE -ne 0) { throw "psql file failed: $FilePath (exit $LASTEXITCODE)" }
}

function Invoke-PsqlQuery {
    param([string]$DatabaseName, [string]$Query)
    $env:PGPASSWORD = $password
    $output = & $psql '--host' '127.0.0.1' '--port' '5432' '--username' 'postgres' '--dbname' $DatabaseName '--tuples-only' '--no-align' '--quiet' '--command' $Query 2>&1
    if ($LASTEXITCODE -ne 0) { throw "psql query failed: $DatabaseName (exit $LASTEXITCODE)" }
    return $output
}

try {
    $exists = (Invoke-PsqlQuery 'postgres' "SELECT EXISTS (SELECT 1 FROM pg_database WHERE datname = '$Database');").Trim()
    if ($exists -ne 't') { throw "REFUSE: candidate database does not exist: $Database" }

    "=== CANDIDATE PRE-FLIGHT ACCESS ===" | Tee-Object -FilePath $receipt
    Invoke-PsqlFile $Database $preflightSql | Tee-Object -FilePath $receipt -Append

    $env:AETHER_ACTIVE_DATABASE = $Database
    $env:APP_CONFIG_CACHE = $cachePath
    $env:DB_CONNECTION = 'pgsql'
    $env:DB_HOST = '127.0.0.1'
    $env:DB_PORT = '5432'
    $env:DB_DATABASE = $Database
    $env:DB_USERNAME = 'postgres'
    $env:DB_PASSWORD = $password
    $env:DASHBOARD_CANDIDATE = $null
    $env:DASHBOARD_CANDIDATE_READ_ONLY_HEALTH = $null

    "=== MIGRATION PRETEND ===" | Tee-Object -FilePath $receipt -Append
    & $php $migrationPhp '--pretend' 2>&1 | Tee-Object -FilePath $receipt -Append
    if ($LASTEXITCODE -ne 0) { throw "Migration pretend failed (exit $LASTEXITCODE)" }

    "=== MIGRATION APPLY ===" | Tee-Object -FilePath $receipt -Append
    & $php $migrationPhp '--apply' 2>&1 | Tee-Object -FilePath $receipt -Append
    if ($LASTEXITCODE -ne 0) { throw "Migration apply failed (exit $LASTEXITCODE)" }

    "=== CANDIDATE MINIMUM ACCESS ===" | Tee-Object -FilePath $receipt -Append
    Invoke-PsqlFile $Database $accessSql | Tee-Object -FilePath $receipt -Append

    $readback = @'
SELECT json_build_object(
  'status', 'CANDIDATE_ACTIVATION_READBACK',
  'database', current_database(),
  'role', current_user,
  'schema', current_schema(),
  'tables', (SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE'),
  'migrations', (SELECT count(*) FROM public.migrations),
  'runtime_connect', has_database_privilege('aether_runtime', current_database(), 'CONNECT'),
  'ingestion_connect', has_database_privilege('aether_ingestion', current_database(), 'CONNECT'),
  'backup_connect', has_database_privilege('aether_backup', current_database(), 'CONNECT'),
  'test_runner_candidate_connect', has_database_privilege('aether_test_runner', current_database(), 'CONNECT'),
  'test_runner_disposable_connect', has_database_privilege('aether_test_runner', 'aether_test_isolated', 'CONNECT'),
  'runtime_test_connect', has_database_privilege('aether_runtime', 'aether_test_isolated', 'CONNECT'),
  'ingestion_test_connect', has_database_privilege('aether_ingestion', 'aether_test_isolated', 'CONNECT'),
  'backup_test_connect', has_database_privilege('aether_backup', 'aether_test_isolated', 'CONNECT'),
  'runtime_market_write', has_table_privilege('aether_runtime', 'public.market_observations', 'INSERT,UPDATE,DELETE,TRUNCATE'),
  'runtime_market_select', has_table_privilege('aether_runtime', 'public.market_observations', 'SELECT'),
  'telegram_jobs_writer', has_table_privilege('aether_ingestion', 'public.scheduled_telegram_jobs', 'SELECT,INSERT,UPDATE'),
  'telegram_outbox_writer', has_table_privilege('aether_ingestion', 'public.telegram_outbox', 'SELECT,INSERT,UPDATE'),
  'market_observations', (SELECT count(*) FROM public.market_observations),
  'registries', (SELECT count(*) FROM public.idx_company_registries),
  'news_items', (SELECT count(*) FROM public.news_items),
  'scheduled_jobs', (SELECT count(*) FROM public.scheduled_telegram_jobs),
  'telegram_outbox', (SELECT count(*) FROM public.telegram_outbox)
)::text;
'@
    "=== FINAL READBACK ===" | Tee-Object -FilePath $receipt -Append
    Invoke-PsqlQuery $Database $readback | Tee-Object -FilePath $receipt -Append
    "candidate_activation=COMPLETE database=$Database receipt=$receipt" | Tee-Object -FilePath $receipt -Append
} finally {
    $password = $null
    Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue
    Remove-Item Env:DB_PASSWORD -ErrorAction SilentlyContinue
}
