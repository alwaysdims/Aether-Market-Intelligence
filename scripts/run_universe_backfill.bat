@echo off
REM Aether Market Intelligence - Single-Owner Universe Backfill Runner
REM Executes deterministic 1-year OHLCV backfill from IDX Edge PRO with checkpoint recovery.

set SCRIPT_DIR=%~dp0
set ROOT_DIR=E:\Aether-Market-Intelligence
set APP_DIR=%ROOT_DIR%\dashboard
set ROOT_LOG_DIR=%ROOT_DIR%\logs
set LOG_FILE=%ROOT_LOG_DIR%\universe_backfill.log
set LOCK_FILE=%ROOT_LOG_DIR%\universe_backfill.lock
set PHP_BIN=C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe

if not exist "%ROOT_LOG_DIR%" mkdir "%ROOT_LOG_DIR%" 2>nul

echo ============================================================================== >> "%LOG_FILE%" 2>&1
echo [%date% %time%] [BACKFILL_RUNNER] Memeriksa single-owner lock... >> "%LOG_FILE%" 2>&1

if not exist "%LOCK_FILE%" goto :ACQUIRE_LOCK

set /p LOCK_PID=<"%LOCK_FILE%"
tasklist /FI "PID eq %LOCK_PID%" 2>nul | findstr /R /C:"[0-9]" >nul
if errorlevel 1 goto :STALE_LOCK

echo [%date% %time%] [WARN] Backfill sudah berjalan pada PID %LOCK_PID%. Menolak eksekusi ganda. >> "%LOG_FILE%" 2>&1
echo Backfill sudah berjalan pada PID %LOCK_PID%. Menolak eksekusi ganda.
exit /b 0

:STALE_LOCK
echo [%date% %time%] [INFO] Stale lock terdeteksi. Me-reclaim lock. >> "%LOG_FILE%" 2>&1
del "%LOCK_FILE%" 2>nul

:ACQUIRE_LOCK
echo %RANDOM% > "%LOCK_FILE%"

echo [%date% %time%] [START] Memulai backfill universe BEI... >> "%LOG_FILE%" 2>&1

cd /d "%APP_DIR%"
if errorlevel 1 (
    echo [%date% %time%] [FATAL] Gagal berpindah ke direktori: %APP_DIR% >> "%LOG_FILE%" 2>&1
    del "%LOCK_FILE%" 2>nul
    exit /b 1
)

"%PHP_BIN%" artisan hermes:backfill-universe --raw --no-interaction >> "%LOG_FILE%" 2>&1
set EXIT_CODE=%ERRORLEVEL%

echo [%date% %time%] [FINISH] Backfill selesai dengan exit code %EXIT_CODE%. >> "%LOG_FILE%" 2>&1
del "%LOCK_FILE%" 2>nul

exit /b %EXIT_CODE%
