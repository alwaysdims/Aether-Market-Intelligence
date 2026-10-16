@echo off
REM Hermes Market Intelligence - Automated Laravel Scheduler Runner
REM Executes every minute via Windows Task Scheduler or background runner.

cd /d "E:\Project-bot\laravel-app"
"C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe" artisan schedule:run >> "E:\Project-bot\laravel-app\storage\logs\scheduler_output.log" 2>&1
