$ErrorActionPreference = 'Stop'
$taskName = 'HermesMarketIntelligence_EOD'
$taskPath = '\'
$targetSession = '2026-11-17'
$root = 'E:\Aether-Market-Intelligence'
$runDir = Join-Path $root 'storage\runtime\eod-runs'
$exportPath = Join-Path $runDir ("{0}.before-retry.xml" -f (Get-Date -Format 'yyyyMMdd_HHmmss'))
$expectedAction = 'wscript.exe'
$expectedArguments = '"E:\Aether-Market-Intelligence\scripts\run_silent.vbs" "E:\Aether-Market-Intelligence\scripts\run_guarded_eod.bat"'

$task = Get-ScheduledTask -TaskName $taskName -TaskPath $taskPath -ErrorAction Stop
$action = $task.Actions | Select-Object -First 1
if ($null -eq $action -or $action.Execute -ne $expectedAction -or $action.Arguments -ne $expectedArguments) {
    throw "owner action mismatch; refusing task update"
}

$xmlText = Export-ScheduledTask -TaskName $taskName -TaskPath $taskPath
if ([string]::IsNullOrWhiteSpace($xmlText)) { throw 'existing task export is empty; refusing task update' }
New-Item -ItemType Directory -Force -Path $runDir | Out-Null
Set-Content -LiteralPath $exportPath -Value $xmlText -Encoding Unicode

[xml]$xml = $xmlText
$namespaceUri = 'http://schemas.microsoft.com/windows/2004/02/mit/task'
$ns = New-Object System.Xml.XmlNamespaceManager($xml.NameTable)
$ns.AddNamespace('t', $namespaceUri)
$calendarTriggers = $xml.SelectNodes('/t:Task/t:Triggers/t:CalendarTrigger', $ns)
if ($calendarTriggers.Count -ne 1) { throw "expected exactly one existing CalendarTrigger; found $($calendarTriggers.Count)" }
$principalBefore = $xml.SelectSingleNode('/t:Task/t:Principals', $ns).OuterXml
$settingsBefore = $xml.SelectSingleNode('/t:Task/t:Settings', $ns).OuterXml
$triggerSnapshot = $calendarTrigger = $calendarTriggers.Item(0).Clone()
$oldRepetition = $triggerSnapshot.SelectSingleNode('t:Repetition', $ns)
if ($null -ne $oldRepetition) { [void]$triggerSnapshot.RemoveChild($oldRepetition) }
$triggerBefore = $triggerSnapshot.OuterXml
$calendarTrigger = $calendarTriggers.Item(0)
$repetition = $calendarTrigger.SelectSingleNode('t:Repetition', $ns)
if ($null -eq $repetition) {
    $repetition = $xml.CreateElement('Repetition', $namespaceUri)
    [void]$calendarTrigger.AppendChild($repetition)
}
function Set-TaskXmlValue {
    param([System.Xml.XmlElement]$Parent, [string]$Name, [string]$Value)
    $node = $Parent.SelectSingleNode("t:$Name", $ns)
    if ($null -eq $node) { $node = $xml.CreateElement($Name, $namespaceUri); [void]$Parent.AppendChild($node) }
    $node.InnerText = $Value
}
Set-TaskXmlValue $repetition 'Interval' 'PT30M'
Set-TaskXmlValue $repetition 'Duration' 'PT08H'
Set-TaskXmlValue $repetition 'StopAtDurationEnd' 'false'

$xmlSettings = New-Object System.Xml.XmlWriterSettings
$xmlSettings.Encoding = New-Object System.Text.UnicodeEncoding($false, $true)
$xmlSettings.Indent = $true
$stringBuilder = New-Object System.Text.StringBuilder
$xmlWriter = [System.Xml.XmlWriter]::Create($stringBuilder, $xmlSettings)
$xml.Save($xmlWriter)
$xmlWriter.Close()
Register-ScheduledTask -TaskName $taskName -TaskPath $taskPath -Xml $stringBuilder.ToString() -Force | Out-Null

$verifiedXml = Export-ScheduledTask -TaskName $taskName -TaskPath $taskPath
$verifiedTask = Get-ScheduledTask -TaskName $taskName -TaskPath $taskPath -ErrorAction Stop
$taskInfo = Get-ScheduledTaskInfo -TaskName $taskName -TaskPath $taskPath -ErrorAction Stop
$verifiedAction = $verifiedTask.Actions | Select-Object -First 1
[xml]$verifiedDoc = $verifiedXml
$verifiedNs = New-Object System.Xml.XmlNamespaceManager($verifiedDoc.NameTable)
$verifiedNs.AddNamespace('t', $namespaceUri)
$verifiedCalendar = $verifiedDoc.SelectNodes('/t:Task/t:Triggers/t:CalendarTrigger', $verifiedNs)
if ($verifiedCalendar.Count -ne 1 -or $verifiedCalendar.Item(0).SelectNodes('t:Repetition', $verifiedNs).Count -ne 1) { throw 'calendar trigger/repetition count changed unexpectedly' }
$verifiedRepetition = $verifiedCalendar.Item(0).SelectSingleNode('t:Repetition', $verifiedNs)
$expectedInterval = [TimeSpan]::FromMinutes(30)
$expectedDuration = [TimeSpan]::FromHours(8)
$actualInterval = [System.Xml.XmlConvert]::ToTimeSpan($verifiedRepetition.SelectSingleNode('t:Interval', $verifiedNs).InnerText)
$actualDuration = [System.Xml.XmlConvert]::ToTimeSpan($verifiedRepetition.SelectSingleNode('t:Duration', $verifiedNs).InnerText)
$stopNode = $verifiedRepetition.SelectSingleNode('t:StopAtDurationEnd', $verifiedNs)
$actualStopAtDurationEnd = if ($null -eq $stopNode) { $false } else { [System.Xml.XmlConvert]::ToBoolean($stopNode.InnerText) }
if ($actualInterval -ne $expectedInterval -or $actualDuration -ne $expectedDuration -or $actualStopAtDurationEnd -ne $false) { throw "retry repetition verification failed: expected interval=$expectedInterval duration=$expectedDuration stop_at_duration_end=False; actual interval=$actualInterval duration=$actualDuration stop_at_duration_end=$actualStopAtDurationEnd" }
if ($verifiedDoc.SelectSingleNode('/t:Task/t:Principals', $verifiedNs).OuterXml -ne $principalBefore) { throw 'principal changed unexpectedly' }
if ($verifiedDoc.SelectSingleNode('/t:Task/t:Settings', $verifiedNs).OuterXml -ne $settingsBefore) { throw 'settings changed unexpectedly' }
$verifiedTrigger = $verifiedCalendar.Item(0).Clone()
[void]$verifiedTrigger.RemoveChild($verifiedTrigger.SelectSingleNode('t:Repetition', $verifiedNs))
if ($verifiedTrigger.OuterXml -ne $triggerBefore) { throw 'daily trigger changed unexpectedly' }
if ($null -eq $verifiedAction -or $verifiedAction.Execute -ne $expectedAction -or $verifiedAction.Arguments -ne $expectedArguments) { throw 'owner action changed unexpectedly' }
if ($null -eq $taskInfo.NextRunTime -or $taskInfo.NextRunTime -le (Get-Date)) { throw "NextRunTime is not in the future: $($taskInfo.NextRunTime)" }
[ordered]@{
    task_name = $verifiedTask.TaskName
    task_path = $verifiedTask.TaskPath
    registration_status = 'UPDATED_AND_READ_BACK'
    target_session = $targetSession
    checkpoint_scope = 'PRESERVED_EXTERNAL_CHECKPOINT'
    export_path = $exportPath
    action = $verifiedAction.Arguments
    state = [string]$verifiedTask.State
    multiple_instances = [string]$verifiedTask.Settings.MultipleInstances
    execution_time_limit = [string]$verifiedTask.Settings.ExecutionTimeLimit
    trigger_count = @($verifiedTask.Triggers).Count
    repetition_interval = 'PT30M'
    repetition_duration = 'PT08H'
    stop_at_duration_end = $false
    next_run_time = $taskInfo.NextRunTime.ToString('o')
    last_run_time = $taskInfo.LastRunTime.ToString('o')
    last_task_result = $taskInfo.LastTaskResult
} | ConvertTo-Json -Compress
