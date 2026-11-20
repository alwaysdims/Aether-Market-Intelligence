param(
    [string]$Path = 'E:\Aether-Market-Intelligence\scripts\apply_operational_writer_grants.ps1'
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $Path)) {
    throw "Missing runner: $Path"
}

$source = Get-Content -Raw -LiteralPath $Path
foreach ($needle in @(
    'Read-Host -AsSecureString',
    'repair_recovery_schema_and_grant_operational_writer.sql',
    'project_bot_recovery_20261110',
    'aether_ingestion',
    '--set=ON_ERROR_STOP=1',
    'PGPASSWORD',
    'Remove-Item Env:PGPASSWORD'
)) {
    if (-not $source.Contains($needle)) {
        throw "Missing runner guard: $needle"
    }
}

foreach ($needle in @('aether_migrator', 'password=', 'Write-Output $password', 'Write-Host $password')) {
    if ($source.Contains($needle)) {
        throw "Forbidden runner content: $needle"
    }
}

Write-Output 'apply_operational_writer_grants_contract=PASS'
