@echo off
REM ==============================================================================
REM Aether Market Intelligence - Periodic Operational Cycle Runner
REM Runs: Expiry Reconciliation & Outbox Dispatcher
REM Safe for 24/7 execution (including weekends & holidays).
REM ==============================================================================

set SCRIPT_DIR=%~dp0
for %%I in ("%SCRIPT_DIR%..") do set ROOT_DIR=%%~fI
set APP_DIR=%ROOT_DIR%\dashboard
set ROOT_LOG_DIR=%ROOT_DIR%\logs
set ROOT_LOG_FILE=%ROOT_LOG_DIR%\operational_cycle.log
set PHP_BIN=C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe

if not exist "%ROOT_LOG_DIR%" mkdir "%ROOT_LOG_DIR%" 2>nul
if not exist "%APP_DIR%" (
    echo [%date% %time%] [FATAL] Direktori kerja tidak ditemukan: %APP_DIR% >> "%ROOT_LOG_FILE%" 2>&1
    exit /b 1
)

cd /d "%APP_DIR%"
if errorlevel 1 exit /b 1

echo [%date% %time%] [CYCLE] [START] Periodic operational cycle dimulai >> "%ROOT_LOG_FILE%" 2>&1

REM 1. Market Monitoring & 14-Day Expiry Reconciliation
"%PHP_BIN%" artisan hermes:monitor-market >> "%ROOT_LOG_FILE%" 2>&1
set MONITOR_EXIT=%errorlevel%

REM 2. Outbox Queue Processing & Backoff Retries (Independent from monitor outcome)
"%PHP_BIN%" artisan hermes:process-outbox --live >> "%ROOT_LOG_FILE%" 2>&1
set OUTBOX_EXIT=%errorlevel%

REM 3. In-memory & connection pool warm-up to prevent cold-start timeouts
"%PHP_BIN%" artisan hermes:telegram-chat --text="/status" --user=5923895922 --update-id=cycle_warmup --message-id=0 --raw >nul 2>&1

REM 4. Process pending on-demand research queue
"%PHP_BIN%" artisan aether:process-research-queue --limit=5 >> "%ROOT_LOG_FILE%" 2>&1

echo [%date% %time%] [CYCLE] [FINISH] Selesai (Monitor: %MONITOR_EXIT%, Outbox: %OUTBOX_EXIT%) >> "%ROOT_LOG_FILE%" 2>&1
exit /b 0
