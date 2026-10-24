@echo off
REM Aether Market Intelligence - Automated Laravel Scheduler Runner
REM Executes every minute via Windows Task Scheduler or background runner.

set SCRIPT_DIR=%~dp0
for %%I in ("%SCRIPT_DIR%..") do set ROOT_DIR=%%~fI
cd /d "%ROOT_DIR%\dashboard"
"C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe" artisan schedule:run >> "%ROOT_DIR%\dashboard\storage\logs\scheduler_output.log" 2>&1
