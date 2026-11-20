$ErrorActionPreference = 'Stop'
$Mode = '--eod-daily'
$Session = $null
$Limit = $null
$RateLimit = 30
$Tickers = $null
$RetryOnly = $false
$CanonicalGap = $false
$TriggerSource = $null
$Manifest = $null
$MaxBatches = 3
$BudgetSeconds = 1680
for ($i = 0; $i -lt $args.Count; $i++) {
    switch -Regex ($args[$i]) {
        '^--mode=(.+)$' { $Mode = $Matches[1] }
        '^--session=(.+)$' { $Session = $Matches[1] }
        '^--limit=(\d+)$' { $Limit = [int]$Matches[1] }
        '^--rpm=(\d+)$' { $RateLimit = [int]$Matches[1] }
        '^--tickers=(.+)$' { $Tickers = $Matches[1] }
        '^--retry-only$' { $RetryOnly = $true }
        '^--canonical-gap$' { $CanonicalGap = $true }
        '^--trigger-source=(.+)$' { $TriggerSource = $Matches[1] }
        '^--manifest=(.+)$' { $Manifest = $Matches[1] }
    }
}
if ($Mode -eq '--batch') {
    if ($null -eq $Session -or $null -eq $Limit -or $Limit -lt 1 -or $Limit -gt 20) { throw 'batch requires --session=YYYY-MM-DD and --limit=1..20' }
    $TimeoutSeconds = 600
    if ($null -eq $Manifest) { throw 'batch requires --manifest=path' }
    if (!(Test-Path -LiteralPath $Manifest)) { throw "manifest not found: $Manifest" }
    $manifestObject = Get-Content -Raw -LiteralPath $Manifest | ConvertFrom-Json
    if ($null -eq $manifestObject.tickers -or $manifestObject.tickers.Count -lt 1 -or $manifestObject.tickers.Count -gt $Limit) { throw 'manifest ticker count exceeds batch limit' }
    if ($null -ne $Session -and [string]$manifestObject.target_session -ne [string]$Session) { throw 'manifest session mismatch' }
    $Tickers = (($manifestObject.tickers | ForEach-Object { [string]$_ }) -join ',')
} elseif ($Mode -eq '--eod-daily') {
    # Task limit PT30M; reserve 60s for child exit, checkpoint, terminal log, and owner-lock release.
    $TimeoutSeconds = 1740
} else {
    throw "unsupported mode: $Mode"
}
$Root = Split-Path -Parent $PSScriptRoot
$Php = 'C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe'
$Writer = Join-Path $Root 'scripts\launch_ingestion_writer.php'
$Orchestrator = Join-Path $Root 'scripts\eod_auto_orchestrator.php'
$LogDir = Join-Path $Root 'logs'
$Log = Join-Path $LogDir 'scheduler_eod_guarded.log'
$RunDir = Join-Path $Root 'storage\runtime\eod-runs'
$Lock = Join-Path $Root 'storage\runtime\eod-scheduler.lock'
New-Item -ItemType Directory -Force -Path $LogDir,$RunDir | Out-Null
$Token = [guid]::NewGuid().ToString('N')
try {
    New-Item -ItemType Directory -Path $Lock -ErrorAction Stop | Out-Null
    Set-Content -LiteralPath (Join-Path $Lock 'owner') -Value $Token -NoNewline
} catch {
    Add-Content $Log "[$(Get-Date -Format o)] [SKIP] lock exists"
    exit 75
}
$RunId = "$(Get-Date -Format yyyyMMdd_HHmmss)_$Token"
$Out = Join-Path $RunDir "$RunId.stdout.log"
$Err = Join-Path $RunDir "$RunId.stderr.log"
$Meta = Join-Path $RunDir "$RunId.meta.json"
$proc = $null
try {
    if (!(Test-Path -LiteralPath $Php) -or !(Test-Path -LiteralPath $Writer) -or !(Test-Path -LiteralPath $Orchestrator)) { throw 'runner dependency missing' }
    $metaObject = [ordered]@{ run_id=$RunId; token=$Token; pid=$PID; account=$env:USERNAME; root=$Root; working_directory=(Join-Path $Root 'dashboard'); started_at=(Get-Date -Format o); scheduled_at=(Get-Date -Format o); trigger_source=$TriggerSource; timezone='Asia/Jakarta'; resolved_session=$Session; timeout_seconds=$TimeoutSeconds; mode=$Mode; session=$Session; limit=$Limit; tickers=$Tickers; canonical_gap=$CanonicalGap; retry_only=$RetryOnly; rate_limit=$RateLimit }
    $metaObject | ConvertTo-Json | Set-Content -LiteralPath $Meta
    Add-Content $Log "[$(Get-Date -Format o)] [START] run_id=$RunId account=$env:USERNAME cwd=$Root timeout=$TimeoutSeconds mode=$Mode session=$Session limit=$Limit canonical_gap=$CanonicalGap retry_only=$RetryOnly rate_limit=$RateLimit"
    $writerArgs = @($Writer, $Mode, "--rpm=$RateLimit")
    if ($null -ne $Session) { $writerArgs += "--session=$Session" }
    if ($null -ne $Limit) { $writerArgs += "--limit=$Limit" }
    if ($null -ne $Tickers) { $writerArgs += "--tickers=$Tickers" }
    if ($RetryOnly) { $writerArgs += "--retry-only" }
    if ($CanonicalGap) { $writerArgs += "--canonical-gap" }
    if ($null -ne $TriggerSource) { $writerArgs += "--trigger-source=$TriggerSource" }
    $childFile = $Php
    $childArgs = $writerArgs
    if ($Mode -eq '--eod-daily') {
        $childArgs = @($Orchestrator, "--rpm=$RateLimit", '--max-batches=3', '--budget-seconds=1680', '--trigger-source=TASK_SCHEDULER_EOD', "--scheduled-at=$((Get-Date -Format o))", "--parent-run-id=$RunId")
    }
    $proc = Start-Process -FilePath $childFile -ArgumentList $childArgs -WorkingDirectory $Root -RedirectStandardOutput $Out -RedirectStandardError $Err -PassThru
    Add-Content $Log "[$(Get-Date -Format o)] [CHILD] pid=$($proc.Id) created=$($proc.StartTime.ToString('o')) stdout=$Out stderr=$Err"
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while (!$proc.HasExited) {
        Start-Sleep -Seconds 30
        $proc.Refresh()
        $heartbeat = [ordered]@{ run_id=$RunId; child_pid=$proc.Id; checked_at=(Get-Date -Format o); child_exited=$proc.HasExited; stdout_bytes=(Get-Item $Out -ErrorAction SilentlyContinue).Length; stderr_bytes=(Get-Item $Err -ErrorAction SilentlyContinue).Length }
        $heartbeat | ConvertTo-Json -Compress | Set-Content -LiteralPath (Join-Path $RunDir "$RunId.heartbeat.json")
        if ((Get-Date) -ge $deadline) {
            $terminal = [ordered]@{ run_id=$RunId; status='INTERRUPTED'; reason='RUNNER_TIMEOUT'; child_pid=$proc.Id; observed_at=(Get-Date -Format o); child_exited=$proc.HasExited; exit_code=124 }
            $terminal | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $RunDir "$RunId.terminal.json")
            Add-Content $Log "[$(Get-Date -Format o)] [TIMEOUT] run_id=$RunId child_pid=$($proc.Id) terminal_status=INTERRUPTED reason=RUNNER_TIMEOUT"
            exit 124
        }
    }
    if (-not $proc.HasExited) {
        $terminal = [ordered]@{ run_id=$RunId; status='INTERRUPTED'; reason='CHILD_STATE_UNAVAILABLE'; child_pid=$proc.Id; observed_at=(Get-Date -Format o); exit_code=1 }
        $terminal | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $RunDir "$RunId.terminal.json")
        Add-Content $Log "[$(Get-Date -Format o)] [INTERRUPTED] run_id=$RunId child_pid=$($proc.Id) reason=CHILD_STATE_UNAVAILABLE"
        exit 1
    }
    $proc.Refresh()
    $rc = [int]$proc.ExitCode
    $semanticStatus = 'FAILED'
    $semanticLine = Get-Content -LiteralPath $Out -ErrorAction SilentlyContinue | Select-String -Pattern 'EOD semantic_status=(COMPLETE|WAITING|FAILED) exit_code=(\d+) reason=([A-Z_]+)' | Select-Object -Last 1
    $semanticReasonsLine = Get-Content -LiteralPath $Out -ErrorAction SilentlyContinue | Select-String -Pattern 'EOD semantic_reasons=([A-Z_,]+)' | Select-Object -Last 1
    if ($semanticLine -and $semanticLine.Matches.Count -gt 0) {
        $semanticStatus = $semanticLine.Matches[0].Groups[1].Value
        $rc = [int]$semanticLine.Matches[0].Groups[2].Value
        $semanticReason = $semanticLine.Matches[0].Groups[3].Value
    } else {
        $semanticReason = 'NO_TERMINAL_SEMANTIC_RECORD'
    }
    $semanticReasons = if ($semanticReasonsLine -and $semanticReasonsLine.Matches.Count -gt 0) { $semanticReasonsLine.Matches[0].Groups[1].Value } else { $semanticReason }
    $summaryLine = Get-Content -LiteralPath $Out -ErrorAction SilentlyContinue | Select-String -Pattern 'EOD summary selected_scope_complete=(true|false) .*?batch_count=(\d+) attempted=(\d+) already_fresh=(\d+) updated=(\d+) canonical_verified=(\d+) metric_verified=(\d+) waiting=(\d+) failed=(\d+) universe_remaining=(\d+) semantic_reason=([A-Z_]+) exit_code=(\d+)' | Select-Object -Last 1
    $checkpointLine = Get-Content -LiteralPath $Out -ErrorAction SilentlyContinue | Select-String -Pattern 'EOD checkpoint_path=(\S+) resolved_session=(\d{4}-\d{2}-\d{2}) trigger_source=(\S+) timezone=(\S+)' | Select-Object -Last 1
    $receipt = [ordered]@{ run_id=$RunId; child_run_id=$null; parent_run_id=$null; status=$semanticStatus; semantic_status=$semanticStatus; semantic_reason=$semanticReason; semantic_reasons=$semanticReasons; exit_code=$rc; selected_scope_complete=$null; batch_count=$null; attempted=$null; already_fresh=$null; updated=$null; canonical_verified=$null; metric_verified=$null; waiting=$null; failed=$null; universe_remaining=$null; target_session=$null; resolved_session=$null; trigger_source=$null; timezone=$null; source_hashes=$null; batch_results=@(); stdout_path=$Out; stderr_path=$Err; meta_path=$Meta; observed_at=(Get-Date -Format o) }
    if ($summaryLine -and $summaryLine.Matches.Count -gt 0) {
        $match = $summaryLine.Matches[0]
        $receipt.selected_scope_complete = [bool]::Parse($match.Groups[1].Value)
        $receipt.batch_count = [int]$match.Groups[2].Value
        $receipt.attempted = [int]$match.Groups[3].Value
        $receipt.already_fresh = [int]$match.Groups[4].Value
        $receipt.updated = [int]$match.Groups[5].Value
        $receipt.canonical_verified = [int]$match.Groups[6].Value
        $receipt.metric_verified = [int]$match.Groups[7].Value
        $receipt.waiting = [int]$match.Groups[8].Value
        $receipt.failed = [int]$match.Groups[9].Value
        $receipt.universe_remaining = [int]$match.Groups[10].Value
        $receipt.semantic_reason = $match.Groups[11].Value
        $receipt.exit_code = [int]$match.Groups[12].Value
    }
    if ($checkpointLine -and $checkpointLine.Matches.Count -gt 0) {
        $checkpointMatch = $checkpointLine.Matches[0]
        $checkpointPath = $checkpointMatch.Groups[1].Value -replace '/', '\\'
        if (Test-Path -LiteralPath $checkpointPath) {
            $checkpoint = Get-Content -Raw -LiteralPath $checkpointPath | ConvertFrom-Json
            $receipt.child_run_id = [string]$checkpoint.run_id
            $receipt.parent_run_id = [string]$checkpoint.parent_run_id
            $receipt.target_session = [string]$checkpoint.target_session
            $receipt.resolved_session = [string]$checkpoint.resolved_session
            $receipt.trigger_source = [string]$checkpoint.trigger_source
            $receipt.timezone = [string]$checkpoint.timezone
            $receipt.source_hashes = $checkpoint.source_hashes
            $receipt.batch_results = @($checkpoint.batch_results)
        }
    }
    $receipt | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath (Join-Path $RunDir "$RunId.terminal.json")
    Add-Content $Log "[$(Get-Date -Format o)] [END] run_id=$RunId child_pid=$($proc.Id) semantic_status=$semanticStatus reason=$semanticReason reasons=$semanticReasons exit_code=$rc receipt=$(Join-Path $RunDir "$RunId.terminal.json")"
    if ($rc -eq 75) {
        Add-Content $Log "[$(Get-Date -Format o)] [WAITING] run_id=$RunId semantic_status=$semanticStatus reason=$semanticReason exit_code=$rc"
    } elseif ($rc -ne 0) {
        Add-Content $Log "[$(Get-Date -Format o)] [FAILED] run_id=$RunId child_failed=true semantic_status=$semanticStatus exit_code=$rc"
    }
    exit $rc
} catch {
    $terminal = [ordered]@{ run_id=$RunId; status='FAILED'; reason='RUNNER_EXCEPTION'; observed_at=(Get-Date -Format o); error=$_.Exception.Message; exit_code=1 }
    $terminal | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $RunDir "$RunId.terminal.json")
    Add-Content $Log "[$(Get-Date -Format o)] [FAILED] run_id=$RunId reason=RUNNER_EXCEPTION error=$($_.Exception.Message)"
    exit 1
} finally {
    $ownerPath = Join-Path $Lock 'owner'
    if ((Test-Path -LiteralPath $ownerPath) -and ((Get-Content -Raw -LiteralPath $ownerPath) -eq $Token)) { Remove-Item -LiteralPath $Lock -Recurse -Force }
}
