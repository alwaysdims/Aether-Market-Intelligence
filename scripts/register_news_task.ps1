$ErrorActionPreference = 'Stop'
$action = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument '"E:\Aether-Market-Intelligence\scripts\run_silent.vbs" "E:\Aether-Market-Intelligence\scripts\run_guarded_news.bat"'
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 30) -RepetitionDuration (New-TimeSpan -Days 3650)
$settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Minutes 5) -MultipleInstances IgnoreNew -StartWhenAvailable
$principal = New-ScheduledTaskPrincipal -UserId 'ASUS' -LogonType Interactive -RunLevel Limited
Register-ScheduledTask -TaskName 'AetherMarketIntelligence_News' -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
Get-ScheduledTask -TaskName 'AetherMarketIntelligence_News' | Select-Object TaskName,State,@{n='Execute';e={$_.Actions.Execute}},@{n='Arguments';e={$_.Actions.Arguments}},@{n='MultipleInstances';e={$_.Settings.MultipleInstances}},@{n='ExecutionTimeLimit';e={$_.Settings.ExecutionTimeLimit}},@{n='Trigger';e={($_.Triggers|ForEach-Object StartBoundary)-join ','}} | ConvertTo-Json -Compress
