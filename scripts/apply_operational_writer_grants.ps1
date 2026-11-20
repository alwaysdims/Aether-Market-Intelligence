$ErrorActionPreference = 'Stop'

$root = 'E:\Aether-Market-Intelligence'
$database = 'project_bot_recovery_20261110'
$role = 'aether_ingestion'
$psql = 'C:\Program Files\PostgreSQL\16\bin\psql.exe'
$sql = Join-Path $root 'scripts\repair_recovery_schema_and_grant_operational_writer.sql'

if (-not (Test-Path -LiteralPath $psql)) {
    throw "psql.exe missing: $psql"
}
if (-not (Test-Path -LiteralPath $sql)) {
    throw "Grant script missing: $sql"
}

$secure = Read-Host -AsSecureString 'PostgreSQL administrator password (postgres)'
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try {
    $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
}

try {
    $env:PGPASSWORD = $password
    $output = & $psql `
        '--host' '127.0.0.1' `
        '--port' '5432' `
        '--username' 'postgres' `
        '--dbname' $database `
        '--set=ON_ERROR_STOP=1' `
        '--file' $sql 2>&1
    $exitCode = $LASTEXITCODE
} finally {
    $password = $null
    Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue
}

if ($exitCode -ne 0) {
    $output | Write-Output
    throw "Operational writer grant failed. Exit=$exitCode"
}

$output | Write-Output
Write-Output "operational_writer_grants=APPLIED database=$database role=$role"
