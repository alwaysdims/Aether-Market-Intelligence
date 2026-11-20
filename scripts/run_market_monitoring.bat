@echo off
setlocal
set SCRIPT_DIR=%~dp0
for %%I in ("%SCRIPT_DIR%..") do set ROOT_DIR=%%~fI
set APP_DIR=%ROOT_DIR%\dashboard
set PHP_BIN=C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe
set LOG_FILE=%ROOT_DIR%\logs\market_monitoring.log
if not exist "%ROOT_DIR%\logs" mkdir "%ROOT_DIR%\logs"
cd /d "%APP_DIR%" || exit /b 1
"%PHP_BIN%" artisan hermes:monitor-market >> "%LOG_FILE%" 2>&1
exit /b %errorlevel%
