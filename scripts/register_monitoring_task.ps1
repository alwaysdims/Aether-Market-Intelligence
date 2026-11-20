$ErrorActionPreference='Stop'
$task='AetherMarketMonitoring_15min'
$script='E:\Aether-Market-Intelligence\scripts\run_silent.vbs'
$batch='E:\Aether-Market-Intelligence\scripts\run_guarded_monitoring.bat'
$action=New-ScheduledTaskAction -Execute 'wscript.exe' -Argument ('"{0}" "{1}"' -f $script,$batch) -WorkingDirectory 'E:\Aether-Market-Intelligence\scripts'
$old=Get-ScheduledTask -TaskName $task
$settings=New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Minutes 5) -MultipleInstances IgnoreNew -StartWhenAvailable
$principal=New-ScheduledTaskPrincipal -UserId 'ASUS' -LogonType Interactive -RunLevel Limited
Register-ScheduledTask -TaskName $task -Action $action -Trigger $old.Triggers -Settings $settings -Principal $principal -Force|Out-Null
Enable-ScheduledTask -TaskName $task|Out-Null
[xml]$xml=(Get-ScheduledTask -TaskName $task).Xml
$xml.Task.Actions.Exec|Select-Object Command,Arguments,WorkingDirectory|ConvertTo-Json -Compress