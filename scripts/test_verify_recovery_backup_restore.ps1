$ErrorActionPreference = 'Stop'

$scriptPath = Join-Path $PSScriptRoot 'verify_existing_recovery_restore.ps1'
$contents = Get-Content -LiteralPath $scriptPath -Raw

if ($contents -notmatch '\[string\]\$VerificationDatabase') {
    throw 'VerificationDatabase parameter is missing.'
}

if ($contents -notmatch "pg_constraint") {
    throw 'Constraint comparison must use pg_catalog.'
}

if ($contents -notmatch "pg_sequences") {
    throw 'Sequence comparison must use pg_sequences.'
}

if ($contents -match 'createdb.exe|pg_restore.exe|New-Item -ItemType Directory -Path \$VerificationDatabase') {
    throw 'Read-only verifier must not create or restore a database.'
}

Write-Output 'verify_recovery_backup_restore static contract: PASS'
