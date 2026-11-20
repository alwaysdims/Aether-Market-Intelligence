<?php

$path = __DIR__.'/remove_synthetic_telegram_probe.ps1';
$source = file_get_contents($path);

if ($source === false) {
    fwrite(STDERR, "missing cleanup script\n");
    exit(1);
}

foreach ([
    '[switch]$Apply',
    "'999999991'",
    "'999999992'",
    'if (-not $Apply)',
    "synthetic_telegram_probe_dry_run",
    "Archive synthetic Telegram bridge probe before deleting exact test record",
    "telegram_probe_cleanup",
    'New-Object System.Text.UTF8Encoding($false)',
    '[System.IO.File]::WriteAllText($archivePath, $json, $encoding)',
    "ConvertFrom-Json",
    "archive identity mismatch",
    "--file",
    'DO $$',
    "Diagnostic SQL retained",
    "ErrorActionPreference = 'Continue'",
    "BEGIN;",
    "COMMIT;",
    "--set=ON_ERROR_STOP=1",
    "expected exactly one synthetic conversation",
    "remaining_synthetic_probe",
    "outbound_message_id",
    "telegram_outbox",
    "scheduled_telegram_jobs",
    "notification_logs",
    "Remove-Item Env:PGPASSWORD",
] as $needle) {
    if (! str_contains($source, $needle)) {
        fwrite(STDERR, "missing cleanup guard: {$needle}\n");
        exit(1);
    }
}

foreach (['TRUNCATE', 'DELETE FROM public.telegram_outbox', 'DELETE FROM public.scheduled_telegram_jobs', "telegram_update_id LIKE '999%'"] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        fwrite(STDERR, "forbidden cleanup scope: {$forbidden}\n");
        exit(1);
    }
}

echo "remove_synthetic_telegram_probe_contract=PASS\n";
