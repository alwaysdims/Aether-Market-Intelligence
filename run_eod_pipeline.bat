@echo off
REM Aether Market Intelligence - Direct EOD Pipeline Runner
REM Scheduled at 16:05 WIB (Monday-Friday) on Windows Task Scheduler.

cd /d "E:\Project-bot\laravel-app"
"C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe" artisan hermes:sync-eod --trigger=scheduled >> "E:\Project-bot\laravel-app\storage\logs\eod_pipeline_output.log" 2>&1
"C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe" artisan hermes:evaluate-horizon >> "E:\Project-bot\laravel-app\storage\logs\eod_pipeline_output.log" 2>&1
