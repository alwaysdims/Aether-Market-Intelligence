$ErrorActionPreference = 'Stop'
$secure = Read-Host -AsSecureString 'PostgreSQL admin password'
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try {
    $env:DB_USERNAME = 'postgres'
    $env:DB_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
    & php 'E:\Aether-Market-Intelligence\scripts\sync_bei_registry.php' --apply
    exit $LASTEXITCODE
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
    Remove-Item Env:DB_PASSWORD -ErrorAction SilentlyContinue
}