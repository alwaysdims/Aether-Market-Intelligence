$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$taskName = 'AetherMarketIntelligence_Dashboard'
$bat = Join-Path $PSScriptRoot 'start_dashboard.bat'
$logonUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name

if (-not (Test-Path -LiteralPath $bat)) { throw "Dashboard launcher missing: $bat" }
$php = 'C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe'
$launcher = Join-Path $PSScriptRoot 'launch_readonly_dashboard.php'
if (-not (Test-Path -LiteralPath $php)) { throw "PHP binary missing: $php" }
if (-not (Test-Path -LiteralPath $launcher)) { throw "Dashboard launcher missing: $launcher" }

$action = New-ScheduledTaskAction -Execute $php -Argument ('"{0}" --serve' -f $launcher) -WorkingDirectory $root
$settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$principal = New-ScheduledTaskPrincipal -UserId $logonUser -LogonType Interactive -RunLevel Limited
Register-ScheduledTask -TaskName $taskName -Action $action -Settings $settings -Principal $principal -Description 'Aether read-only recovery dashboard owner; localhost 127.0.0.1:8000; no credentials in arguments.' | Out-Null

$task = Get-ScheduledTask -TaskName $taskName
[pscustomobject]@{
    TaskName = $task.TaskName
    State = $task.State
    Execute = $task.Actions.Execute
    Arguments = $task.Actions.Arguments
    WorkingDirectory = $task.Actions.WorkingDirectory
    RunAs = $task.Principal.UserId
    MultipleInstances = $task.Settings.MultipleInstances
    ExecutionTimeLimit = $task.Settings.ExecutionTimeLimit
} | ConvertTo-Json -Compress
