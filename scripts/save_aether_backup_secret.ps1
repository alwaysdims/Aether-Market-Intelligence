param(
    [Parameter(Mandatory = $true)]
    [string]$Path
)

$parent = Split-Path -Parent $Path
if (-not (Test-Path -LiteralPath $parent)) {
    New-Item -ItemType Directory -Path $parent -Force | Out-Null
}

$secure = Read-Host -AsSecureString 'Password aether_backup'
$secure | ConvertFrom-SecureString | Set-Content -LiteralPath $Path -NoNewline
icacls.exe $Path /inheritance:r /grant:r "$env:USERNAME:(R,W)" | Out-Null
Write-Output 'DPAPI credential saved.'
