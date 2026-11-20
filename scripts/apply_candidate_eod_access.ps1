$ErrorActionPreference = 'Stop'
$root = 'E:\Aether-Market-Intelligence'
$database = 'project_bot_recovered_20261115_022935'
$psql = 'C:\Program Files\PostgreSQL\16\bin\psql.exe'
$sql = Join-Path $root 'scripts\grant_candidate_eod_access.sql'
$receiptDir = Join-Path $root 'storage\incidents\20261114_test_runner_wipe'
$receipt = Join-Path $receiptDir ("candidate_eod_access_{0}.txt" -f (Get-Date -Format 'yyyyMMdd_HHmmss'))

if (-not (Test-Path -LiteralPath $psql)) { throw "psql.exe missing" }
if (-not (Test-Path -LiteralPath $sql)) { throw "Grant SQL missing" }
New-Item -ItemType Directory -Path $receiptDir -Force | Out-Null

$secure = Read-Host -AsSecureString 'PostgreSQL administrator password (local prompt)'
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try { $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) }
finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }

try {
    $env:PGPASSWORD = $password
    & $psql '--host' '127.0.0.1' '--port' '5432' '--username' 'postgres' '--dbname' $database '--set=ON_ERROR_STOP=1' '--file' $sql 2>&1 | Tee-Object -FilePath $receipt
    $exitCode = $LASTEXITCODE
} finally {
    $password = $null
    Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue
}

if ($exitCode -ne 0) { throw "Candidate EOD grant failed. Receipt: $receipt" }
Write-Output "candidate_eod_access=VERIFIED receipt=$receipt"
