@echo off
REM Aether Market Intelligence - Session Recovery Runner (2026-11-04)
REM Detached background execution to avoid bash terminal timeout.

set ROOT_DIR=E:\Aether-Market-Intelligence
set APP_DIR=%ROOT_DIR%\dashboard
set PHP_BIN=C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe
set LOG_FILE=%ROOT_DIR%\logs\session_recovery_20261104.log

if not exist "%ROOT_DIR%\logs" mkdir "%ROOT_DIR%\logs" 2>nul

echo ============================================================================== >> "%LOG_FILE%" 2>&1
echo [%date% %time%] [START] Memulai pemulihan sesi 2026-11-04... >> "%LOG_FILE%" 2>&1

cd /d "%APP_DIR%"
"%PHP_BIN%" artisan hermes:refresh-eod --session=2026-11-04 >> "%LOG_FILE%" 2>&1

echo [%date% %time%] [DONE] Pemulihan sesi 2026-11-04 selesai [exit: %errorlevel%] >> "%LOG_FILE%" 2>&1
