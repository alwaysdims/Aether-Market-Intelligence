param(
    [string]$Path = (Join-Path $env:LOCALAPPDATA 'Aether-Market-Intelligence\recovery\credentials\aether_migrator.dpapi.txt'),
    [string]$Database = 'project_bot_recovery_20261110',
    [string]$Role = 'aether_migrator'
)

$ErrorActionPreference = 'Stop'
$identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$psql = 'C:\Program Files\PostgreSQL\16\bin\psql.exe'
$status = [ordered]@{
    WindowsIdentity = $identity
    FileEncryptedSaved = $false
    AclApplied = $false
    PostgresLoginVerified = $false
}

if ($Database -ne 'project_bot_recovery_20261110' -or $Role -ne 'aether_migrator') {
    throw 'REFUSE: helper is limited to aether_migrator on project_bot_recovery_20261110.'
}

$parent = Split-Path -Parent $Path
if (-not (Test-Path -LiteralPath $parent)) {
    New-Item -ItemType Directory -Path $parent -Force | Out-Null
}

$tempPath = Join-Path $parent ('.aether_migrator_'+[guid]::NewGuid().ToString('N')+'.tmp')
$secure = Read-Host -AsSecureString 'Password role aether_migrator'
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try {
    $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
}

try {
    New-Item -ItemType File -LiteralPath $tempPath -Force | Out-Null
    & icacls.exe $tempPath /inheritance:r /grant:r "${identity}:(R)" /grant "${identity}:(W)" | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw 'ACL_APPLY_FAILED: icacls returned a non-zero exit code.'
    }

    $acl = Get-Acl -LiteralPath $tempPath
    $grantedRights = [System.Security.AccessControl.FileSystemRights]0
    foreach ($entry in $acl.Access | Where-Object {
        $_.IdentityReference.Value -eq $identity -and
        $_.AccessControlType -eq [System.Security.AccessControl.AccessControlType]::Allow
    }) {
        $grantedRights = $grantedRights -bor $entry.FileSystemRights
    }
    if ((($grantedRights -band [System.Security.AccessControl.FileSystemRights]::Read) -eq 0) -or
        (($grantedRights -band [System.Security.AccessControl.FileSystemRights]::Write) -eq 0) -or
        $acl.AreAccessRulesProtected -ne $true) {
        throw 'ACL_APPLY_FAILED: expected account read/write access or inheritance protection is absent.'
    }
    $status.AclApplied = $true

    $secure | ConvertFrom-SecureString | Set-Content -LiteralPath $tempPath -NoNewline -Encoding ascii
    if ((Get-Item -LiteralPath $tempPath).Length -le 0) {
        throw 'ENCRYPTED_FILE_SAVE_FAILED: encrypted credential file is empty.'
    }
    $status.FileEncryptedSaved = $true

    if (-not (Test-Path -LiteralPath $psql)) {
        throw "POSTGRES_LOGIN_NOT_VERIFIED: psql.exe not found at $psql. Existing credential was not replaced."
    }

    $previousPassword = $env:PGPASSWORD
    try {
        $env:PGPASSWORD = $password
        $login = & $psql '--host' '127.0.0.1' '--port' '5432' '--username' $Role '--dbname' $Database '--tuples-only' '--no-align' '--quiet' '--command' 'SELECT current_database() || ''|'' || current_user' 2>&1
        if ($LASTEXITCODE -ne 0 -or ($login | Select-Object -Last 1).Trim() -ne "$Database|$Role") {
            throw 'POSTGRES_LOGIN_FAILED: encrypted candidate and ACL succeeded; existing credential was not replaced.'
        }
        $status.PostgresLoginVerified = $true
    } finally {
        if ($null -eq $previousPassword) {
            Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue
        } else {
            $env:PGPASSWORD = $previousPassword
        }
    }

    Move-Item -LiteralPath $tempPath -Destination $Path -Force
    $status | ConvertTo-Json -Compress
    exit 0
} catch {
    $status | ConvertTo-Json -Compress
    Write-Error $_.Exception.Message
    exit 1
} finally {
    $password = $null
    $secure = $null
    if (Test-Path -LiteralPath $tempPath) {
        Remove-Item -LiteralPath $tempPath -Force -ErrorAction SilentlyContinue
    }
}
