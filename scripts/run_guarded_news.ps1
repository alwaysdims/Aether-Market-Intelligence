$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$Php = 'C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe'
$Writer = Join-Path $Root 'scripts\launch_app_writer.php'
$LogDir = Join-Path $Root 'logs'
$Log = Join-Path $LogDir 'scheduler_news_guarded.log'
$RunDir = Join-Path $Root 'storage\runtime\news-runs'
$Lock = Join-Path $Root 'storage\runtime\news-scheduler.lock'
$TimeoutSeconds = 300
New-Item -ItemType Directory -Force -Path $LogDir,$RunDir | Out-Null
$Token = [guid]::NewGuid().ToString('N')
try { New-Item -ItemType Directory -Path $Lock -ErrorAction Stop | Out-Null; Set-Content -LiteralPath (Join-Path $Lock 'owner') -Value $Token -NoNewline } catch { Add-Content $Log "[$(Get-Date -Format o)] [SKIP] lock exists"; exit 75 }
$RunId = "$(Get-Date -Format yyyyMMdd_HHmmss)_$Token"
$Out = Join-Path $RunDir "$RunId.stdout.log"
$Err = Join-Path $RunDir "$RunId.stderr.log"
$Meta = Join-Path $RunDir "$RunId.meta.json"
try {
    if (!(Test-Path -LiteralPath $Php) -or !(Test-Path -LiteralPath $Writer)) { throw 'runner dependency missing' }
    [ordered]@{run_id=$RunId;token=$Token;account=$env:USERNAME;root=$Root;working_directory=(Join-Path $Root 'dashboard');started_at=(Get-Date -Format o);timeout_seconds=$TimeoutSeconds;mode='--news';limit=5} | ConvertTo-Json | Set-Content $Meta
    Add-Content $Log "[$(Get-Date -Format o)] [START] run_id=$RunId account=$env:USERNAME cwd=$Root timeout=$TimeoutSeconds mode=--news limit=5"
    $proc = Start-Process -FilePath $Php -ArgumentList @($Writer,'--news','--limit=5') -WorkingDirectory (Join-Path $Root 'dashboard') -RedirectStandardOutput $Out -RedirectStandardError $Err -PassThru
    Add-Content $Log "[$(Get-Date -Format o)] [CHILD] run_id=$RunId pid=$($proc.Id) created=$($proc.StartTime.ToString('o')) stdout=$Out stderr=$Err"
    $deadline=(Get-Date).AddSeconds($TimeoutSeconds)
    while (!$proc.HasExited) {
        Start-Sleep -Seconds 10
        $proc.Refresh()
        [ordered]@{run_id=$RunId;child_pid=$proc.Id;checked_at=(Get-Date -Format o);child_exited=$proc.HasExited;stdout_bytes=(Get-Item $Out -ErrorAction SilentlyContinue).Length;stderr_bytes=(Get-Item $Err -ErrorAction SilentlyContinue).Length} | ConvertTo-Json -Compress | Set-Content (Join-Path $RunDir "$RunId.heartbeat.json")
        if ((Get-Date) -ge $deadline) { Add-Content $Log "[$(Get-Date -Format o)] [TIMEOUT] run_id=$RunId child_pid=$($proc.Id)"; exit 124 }
    }
    $proc.Refresh(); $rc=[int]$proc.ExitCode
    Add-Content $Log "[$(Get-Date -Format o)] [END] run_id=$RunId child_pid=$($proc.Id) exit_code=$rc"
    if ($rc -ne 0) { Add-Content $Log "[$(Get-Date -Format o)] [FAILED] run_id=$RunId child_failed=true" }
    exit $rc
} catch { Add-Content $Log "[$(Get-Date -Format o)] [FAILED] run_id=$RunId error=$($_.Exception.Message)"; exit 1 }
finally { $owner=Join-Path $Lock 'owner'; if ((Test-Path $owner) -and ((Get-Content -Raw $owner) -eq $Token)) { Remove-Item $Lock -Recurse -Force } }
