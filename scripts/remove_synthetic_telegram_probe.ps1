param(
    [switch]$Apply
)

$ErrorActionPreference = 'Stop'

$root = 'E:\Aether-Market-Intelligence'
$database = 'project_bot_recovery_20261110'
$updateId = '999999991'
$messageId = '999999992'
$inboundText = '/status'
$psql = 'C:\Program Files\PostgreSQL\16\bin\psql.exe'
$archiveDir = Join-Path $root 'storage\incidents\20261110_operational_data_loss\telegram_probe_cleanup'

if (-not (Test-Path -LiteralPath $psql)) {
    throw "psql.exe missing: $psql"
}

$secure = Read-Host -AsSecureString 'PostgreSQL administrator password (postgres)'
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try {
    $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
}

function Invoke-AdminPsql([string]$Sql, [string]$Label) {
    $sqlPath = Join-Path ([System.IO.Path]::GetTempPath()) ("aether-telegram-probe-$Label-"+[Guid]::NewGuid().ToString('N')+'.sql')
    $encoding = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($sqlPath, $Sql, $encoding)

    try {
        $previousErrorActionPreference = $ErrorActionPreference
        $ErrorActionPreference = 'Continue'
        try {
            $output = & $psql '--host' '127.0.0.1' '--port' '5432' '--username' 'postgres' '--dbname' $database '--set=ON_ERROR_STOP=1' '--tuples-only' '--no-align' '--quiet' '--file' $sqlPath 2>&1
            $exitCode = $LASTEXITCODE
        } finally {
            $ErrorActionPreference = $previousErrorActionPreference
        }
        if ($exitCode -ne 0) {
            New-Item -ItemType Directory -Force -Path $archiveDir | Out-Null
            $diagnosticPath = Join-Path $archiveDir ("psql-$Label-failure-"+(Get-Date -Format 'yyyyMMdd-HHmmss')+'.sql')
            Move-Item -LiteralPath $sqlPath -Destination $diagnosticPath -Force
            $sqlPath = $null
            $output | Write-Output
            throw "PostgreSQL command failed. Exit=$exitCode. Diagnostic SQL retained: $diagnosticPath"
        }

        return $output
    } finally {
        if ($null -ne $sqlPath -and (Test-Path -LiteralPath $sqlPath)) {
            Remove-Item -LiteralPath $sqlPath -Force
        }
    }
}

try {
    $env:PGPASSWORD = $password

    $discoverySql = @"
SELECT json_build_object(
    'database', current_database(),
    'role', current_user,
    'conversation_count', (SELECT count(*) FROM public.telegram_conversations WHERE telegram_update_id = '$updateId'),
    'candidate_count', (SELECT count(*) FROM public.telegram_conversations WHERE telegram_update_id = '$updateId' AND provenance->>'inbound_message_id' = '$messageId' AND inbound_text = '$inboundText' AND provenance->>'outbound_message_id' IS NULL),
    'candidates', COALESCE((SELECT json_agg(json_build_object('id', id, 'chat_id', chat_id, 'user_id', user_id, 'inbound_message_id', provenance->>'inbound_message_id', 'outbound_message_id', provenance->>'outbound_message_id', 'created_at', created_at)) FROM public.telegram_conversations WHERE telegram_update_id = '$updateId'), '[]'::json),
    'archive_record', (SELECT to_jsonb(t) FROM public.telegram_conversations AS t WHERE telegram_update_id = '$updateId'),
    'scheduled_job_count', (SELECT count(*) FROM public.scheduled_telegram_jobs WHERE telegram_update_id = '$updateId' OR inbound_message_id = '$messageId'),
    'outbox_count', (SELECT count(*) FROM public.telegram_outbox WHERE metadata->>'telegram_update_id' = '$updateId' OR reply_to_message_id = '$messageId'),
    'notification_reference_count', (SELECT count(*) FROM public.notification_logs WHERE channel = 'telegram' AND message_payload LIKE '%$updateId%')
)::text;
"@

    $discovery = ((Invoke-AdminPsql $discoverySql 'discovery' | Where-Object { $_ -match '^\{' } | Select-Object -Last 1) | ConvertFrom-Json)
    if ($discovery.database -ne $database -or $discovery.role -ne 'postgres') {
        throw 'REFUSE: PostgreSQL identity mismatch.'
    }
    if ($discovery.conversation_count -ne 1 -or $discovery.candidate_count -ne 1) {
        throw "REFUSE: expected one exact synthetic conversation; found conversation_count=$($discovery.conversation_count), candidate_count=$($discovery.candidate_count)."
    }
    if ($discovery.scheduled_job_count -ne 0 -or $discovery.outbox_count -ne 0 -or $discovery.notification_reference_count -ne 0) {
        throw "REFUSE: related delivery state exists: jobs=$($discovery.scheduled_job_count), outbox=$($discovery.outbox_count), notification_references=$($discovery.notification_reference_count)."
    }

    $scope = [ordered]@{
        database = $discovery.database
        role = $discovery.role
        conversation_count = [int]$discovery.conversation_count
        candidate_count = [int]$discovery.candidate_count
        candidates = @($discovery.candidates | ForEach-Object { [ordered]@{ id = [int]$_.id; chat_id = $_.chat_id; user_id = $_.user_id; inbound_message_id = $_.inbound_message_id; has_outbound_receipt = [bool]$_.outbound_message_id; created_at = $_.created_at } })
        related = [ordered]@{ scheduled_jobs = [int]$discovery.scheduled_job_count; outbox = [int]$discovery.outbox_count; notification_references = [int]$discovery.notification_reference_count }
    }

    if (-not $Apply) {
        [ordered]@{ status = 'synthetic_telegram_probe_dry_run'; scope = $scope; apply_command = 'Re-run with -Apply only after reviewing this exact scope.' } | ConvertTo-Json -Depth 6 -Compress
        exit 0
    }

    New-Item -ItemType Directory -Force -Path $archiveDir | Out-Null
    $archivePath = Join-Path $archiveDir ('synthetic-telegram-probe-'+(Get-Date -Format 'yyyyMMdd-HHmmss')+'.json')
    $archiveObject = [ordered]@{
        reason = 'Archive synthetic Telegram bridge probe before deleting exact test record; no real inbound, outbound, job, outbox, or notification receipt exists.'
        status = 'PENDING_DELETE'
        archived_at_utc = [DateTime]::UtcNow.ToString('o')
        scope = $scope
        record = $discovery.archive_record
    }
    $json = $archiveObject | ConvertTo-Json -Depth 12
    $encoding = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($archivePath, $json, $encoding)
    if ((Get-Item -LiteralPath $archivePath).Length -le 0) {
        throw 'REFUSE: archive write failed before deletion.'
    }
    try {
        $archived = Get-Content -LiteralPath $archivePath -Raw | ConvertFrom-Json
    } catch {
        throw "REFUSE: archive JSON unreadable before deletion: $($_.Exception.Message)"
    }
    if ([string]$archived.scope.database -ne $database -or
        [string]$archived.scope.role -ne 'postgres' -or
        [int]$archived.scope.conversation_count -ne 1 -or
        [int]$archived.scope.candidate_count -ne 1 -or
        [string]$archived.record.telegram_update_id -ne $updateId -or
        [string]$archived.record.provenance.inbound_message_id -ne $messageId -or
        [string]$archived.record.inbound_text -ne $inboundText -or
        $null -ne $archived.record.provenance.outbound_message_id) {
        throw 'REFUSE: archive identity mismatch before deletion.'
    }

    $deleteSql = @'
BEGIN;
DO $$
DECLARE
    deleted_count integer;
    related_count integer;
BEGIN
    IF current_database() <> '$database' OR current_user <> 'postgres' THEN
        RAISE EXCEPTION 'identity mismatch';
    END IF;
    PERFORM pg_advisory_xact_lock(hashtext('aether-synthetic-telegram-probe-$updateId'));
    SELECT count(*) INTO related_count FROM public.scheduled_telegram_jobs WHERE telegram_update_id = '$updateId' OR inbound_message_id = '$messageId';
    SELECT related_count + count(*) INTO related_count FROM public.telegram_outbox WHERE metadata->>'telegram_update_id' = '$updateId' OR reply_to_message_id = '$messageId';
    SELECT related_count + count(*) INTO related_count FROM public.notification_logs WHERE channel = 'telegram' AND message_payload LIKE '%$updateId%';
    IF related_count <> 0 THEN
        RAISE EXCEPTION 'related delivery state exists: %', related_count;
    END IF;
    DELETE FROM public.telegram_conversations
    WHERE telegram_update_id = '$updateId'
      AND provenance->>'inbound_message_id' = '$messageId'
      AND inbound_text = '$inboundText'
      AND provenance->>'outbound_message_id' IS NULL;
    GET DIAGNOSTICS deleted_count = ROW_COUNT;
    IF deleted_count <> 1 THEN
        RAISE EXCEPTION 'expected exactly one synthetic conversation, deleted %', deleted_count;
    END IF;
END
$$;
COMMIT;
SELECT count(*) AS remaining_synthetic_probe FROM public.telegram_conversations WHERE telegram_update_id = '$updateId';
'@

    $deleteSql = $deleteSql.Replace('$database', $database).Replace('$updateId', $updateId).Replace('$messageId', $messageId).Replace('$inboundText', $inboundText)

    $output = Invoke-AdminPsql $deleteSql 'delete'
    if (($output | Select-Object -Last 1).Trim() -ne '0') {
        $output | Write-Output
        throw "Synthetic Telegram cleanup failed. Archive retained: $archivePath"
    }

    [ordered]@{ status = 'synthetic_telegram_probe_removed'; archive_path = $archivePath; remaining_synthetic_probe = 0 } | ConvertTo-Json -Compress
} finally {
    $password = $null
    Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue
}