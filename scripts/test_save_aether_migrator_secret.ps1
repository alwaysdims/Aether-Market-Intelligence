param(
    [string]$ScriptPath = 'E:\Aether-Market-Intelligence\scripts\save_aether_migrator_secret.ps1'
)

$ErrorActionPreference = 'Stop'
$source = Get-Content -Raw -LiteralPath $ScriptPath

$required = @(
    '[System.Security.Principal.WindowsIdentity]::GetCurrent().Name',
    'icacls.exe',
    '$LASTEXITCODE',
    'FileEncryptedSaved',
    'AclApplied',
    'PostgresLoginVerified',
    'ACL_APPLY_FAILED'
)

foreach ($needle in $required) {
    if (-not $source.Contains($needle)) {
        throw "Missing required credential-helper behavior: $needle"
    }
}

if ($source.Contains('(R,W)')) {
    throw 'Invalid icacls permission tuple remains.'
}

Write-Output 'credential_helper_contract=PASS'
