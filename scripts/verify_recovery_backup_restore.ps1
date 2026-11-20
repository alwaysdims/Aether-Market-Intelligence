param(
    [Parameter(Mandatory = $true)]
    [ValidateScript({ Test-Path -LiteralPath $_ -PathType Leaf })]
    [string]$DumpPath,

    [string]$SourceDatabase = 'project_bot_recovery_20261110',
    [string]$SourceRole = 'aether_backup',
    [string]$SourceCredentialPath = "$env:LOCALAPPDATA\Aether-Market-Intelligence\recovery\credentials\aether_backup.dpapi.txt"
)

$ErrorActionPreference = 'Stop'
$pgBin = 'C:\Program Files\PostgreSQL\16\bin'
$psql = Join-Path $pgBin 'psql.exe'
$createdb = Join-Path $pgBin 'createdb.exe'
$pgRestore = Join-Path $pgBin 'pg_restore.exe'
$decryptor = Join-Path $PSScriptRoot 'decrypt_ingestion_secret.ps1'
$timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$verifyDatabase = "aether_backup_verify_$timestamp"
$receiptDirectory = 'E:\Aether-Market-Intelligence\storage\incidents\20261110_operational_data_loss'
$receiptPath = Join-Path $receiptDirectory "recovery-backup-restore-verification-$timestamp.json"

foreach ($binary in @($psql, $createdb, $pgRestore, $decryptor)) {
    if (-not (Test-Path -LiteralPath $binary)) {
        throw "Required file unavailable: $binary"
    }
}

$adminSecure = Read-Host -AsSecureString 'PostgreSQL administrator password'
$adminPtr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($adminSecure)
try {
    $adminPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($adminPtr)
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($adminPtr)
}

$sourceSecure = Get-Content -Raw -LiteralPath $SourceCredentialPath | ConvertTo-SecureString
$sourcePtr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($sourceSecure)
try {
    $sourcePassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($sourcePtr)
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($sourcePtr)
}

function Invoke-Pg {
    param([string]$Password, [string]$Exe, [string[]]$Arguments)

    $previous = $env:PGPASSWORD
    try {
        $env:PGPASSWORD = $Password
        & $Exe @Arguments
        if ($LASTEXITCODE -ne 0) {
            throw "PostgreSQL command failed: $Exe (exit $LASTEXITCODE)"
        }
    } finally {
        if ($null -eq $previous) { Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue } else { $env:PGPASSWORD = $previous }
    }
}

try {
    Invoke-Pg $adminPassword $createdb @('--host', '127.0.0.1', '--port', '5432', '--username', 'postgres', '--template', 'template0', $verifyDatabase)
    Invoke-Pg $adminPassword $pgRestore @('--host', '127.0.0.1', '--port', '5432', '--username', 'postgres', '--dbname', $verifyDatabase, '--exit-on-error', '--no-owner', '--no-privileges', $DumpPath)

    $sourceQuery = @"
SELECT json_build_object(
  'database', current_database(),
  'tables', (SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE'),
  'indexes', (SELECT count(*) FROM pg_indexes WHERE schemaname='public'),
  'foreign_keys', (SELECT count(*) FROM information_schema.table_constraints WHERE table_schema='public' AND constraint_type='FOREIGN KEY'),
  'sequences', (SELECT count(*) FROM information_schema.sequences WHERE sequence_schema='public'),
  'market_observations', (SELECT count(*) FROM public.market_observations),
  'registries', (SELECT count(*) FROM public.idx_company_registries),
  'max_bar_date', (SELECT max(bar_date)::text FROM public.market_observations),
  'evidence_snapshots', (SELECT count(*) FROM public.evidence_snapshots),
  'prediction_signals', (SELECT count(*) FROM public.prediction_signals)
)::text;
"@
    $targetQuery = $sourceQuery.Replace($SourceDatabase, $verifyDatabase)

    $sourceJson = Invoke-Pg $sourcePassword $psql @('--host', '127.0.0.1', '--port', '5432', '--username', $SourceRole, '--dbname', $SourceDatabase, '--tuples-only', '--no-align', '--command', $sourceQuery)
    $targetJson = Invoke-Pg $adminPassword $psql @('--host', '127.0.0.1', '--port', '5432', '--username', 'postgres', '--dbname', $verifyDatabase, '--tuples-only', '--no-align', '--command', $targetQuery)

    $source = $sourceJson | ConvertFrom-Json
    $target = $targetJson | ConvertFrom-Json
    $match = $source.tables -eq $target.tables -and $source.indexes -eq $target.indexes -and $source.foreign_keys -eq $target.foreign_keys -and $source.sequences -eq $target.sequences -and $source.market_observations -eq $target.market_observations -and $source.registries -eq $target.registries -and $source.max_bar_date -eq $target.max_bar_date -and $source.evidence_snapshots -eq $target.evidence_snapshots -and $source.prediction_signals -eq $target.prediction_signals

    if (-not (Test-Path -LiteralPath $receiptDirectory)) {
        New-Item -ItemType Directory -Path $receiptDirectory -Force | Out-Null
    }

    $result = [pscustomobject]@{
        status = if ($match) { 'VERIFIED' } else { 'MISMATCH' }
        source_database = $SourceDatabase
        verification_database = $verifyDatabase
        dump_path = $DumpPath
        dump_bytes = (Get-Item -LiteralPath $DumpPath).Length
        sha256 = (Get-FileHash -LiteralPath $DumpPath -Algorithm SHA256).Hash.ToLowerInvariant()
        source = $source
        restored = $target
        secret_printed = $false
    }
    $result | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $receiptPath -Encoding utf8
    $result | Add-Member -NotePropertyName receipt_path -NotePropertyValue $receiptPath
    $result | ConvertTo-Json -Depth 5

    if (-not $match) { exit 3 }
} finally {
    $adminPassword = ''
    $sourcePassword = ''
}
