$ErrorActionPreference = 'Stop'

$root = 'E:\Aether-Market-Intelligence'
$database = 'project_bot_recovery_20261110'
$psql = 'C:\Program Files\PostgreSQL\16\bin\psql.exe'
$sql = Join-Path $root 'scripts\audit_recovery_incident_admin.sql'
$receiptDir = Join-Path $root 'storage\incidents\20261114_test_runner_wipe'
$timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$receipt = Join-Path $receiptDir "admin_readonly_audit_$timestamp.txt"

if (-not (Test-Path -LiteralPath $psql)) {
    throw "psql.exe missing: $psql"
}
if (-not (Test-Path -LiteralPath $sql)) {
    throw "Audit SQL missing: $sql"
}
if (-not (Test-Path -LiteralPath $receiptDir)) {
    New-Item -ItemType Directory -Path $receiptDir -Force | Out-Null
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
    & $psql `
        '--host' '127.0.0.1' `
        '--port' '5432' `
        '--username' 'postgres' `
        '--dbname' $database `
        '--set=ON_ERROR_STOP=1' `
        '--file' $sql 2>&1 | Tee-Object -FilePath $receipt
    $exitCode = $LASTEXITCODE
} finally {
    $password = $null
    Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue
}

if ($exitCode -ne 0) {
    throw "Administrator read-only audit failed. Receipt: $receipt"
}

Write-Output "admin_readonly_audit=COMPLETE database=$database receipt=$receipt"
