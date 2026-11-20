param(
    [switch]$PretendOnly
)

$ErrorActionPreference = 'Stop'
$root = 'E:\Aether-Market-Intelligence'
$php = (Get-Command php).Source

$secure = Read-Host -AsSecureString 'PostgreSQL administrator password (postgres)'
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try {
    $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
}

$previous = @{}
foreach ($key in @('DB_CONNECTION','DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD','DASHBOARD_CANDIDATE','DASHBOARD_CANDIDATE_READ_ONLY_HEALTH')) {
    $previous[$key] = [Environment]::GetEnvironmentVariable($key, 'Process')
}

$env:DB_CONNECTION = 'pgsql'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '5432'
$env:DB_DATABASE = 'project_bot_recovery_20261110'
$env:DB_USERNAME = 'postgres'
$env:DB_PASSWORD = $password
$env:DASHBOARD_CANDIDATE = $null
$env:DASHBOARD_CANDIDATE_READ_ONLY_HEALTH = $null

try {
    Write-Output '=== DRY RUN (pretend) ==='
    & $php "$root\scripts\apply_recovery_migrations.php" --pretend
    $pretendExit = $LASTEXITCODE
    if ($pretendExit -ne 0) {
        Write-Output "PRETEND_FAILED exit=$pretendExit"
        exit $pretendExit
    }

    if ($PretendOnly) {
        Write-Output 'PRETEND_ONLY_DONE'
        exit 0
    }

    Write-Output '=== APPLY ==='
    & $php "$root\scripts\apply_recovery_migrations.php" --apply
    $applyExit = $LASTEXITCODE
    Write-Output "APPLY_EXIT=$applyExit"
    exit $applyExit
} finally {
    $password = $null
    Remove-Item Env:DB_PASSWORD -ErrorAction SilentlyContinue
    foreach ($key in $previous.Keys) {
        [Environment]::SetEnvironmentVariable($key, $previous[$key], 'Process')
    }
}
