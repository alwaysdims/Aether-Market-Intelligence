@echo off
setlocal
set SCRIPT_DIR=%~dp0
for %%I in ("%SCRIPT_DIR%..") do set ROOT_DIR=%%~fI
set APP_DIR=%ROOT_DIR%\dashboard
set LOG_FILE=%ROOT_DIR%\logs\aether_workers.log
set PHP_BIN=C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe
if not exist "%ROOT_DIR%\logs" mkdir "%ROOT_DIR%\logs"

set WORKER_TOKEN=WTOK_%RANDOM%_%RANDOM%

rem 1. Akuisisi lock atomik dengan verifikasi liveness PID dan owner token
"%PHP_BIN%" "%SCRIPT_DIR%worker_lock.php" acquire --token=%WORKER_TOKEN% >> "%LOG_FILE%" 2>&1
if errorlevel 1 (
    echo [%date% %time%] [SKIP] Worker lain sedang aktif. Token=%WORKER_TOKEN% >> "%LOG_FILE%"
    exit /b 0
)

cd /d "%APP_DIR%"
if errorlevel 1 (
    "%PHP_BIN%" "%SCRIPT_DIR%worker_lock.php" release --token=%WORKER_TOKEN% >> "%LOG_FILE%" 2>&1
    exit /b 1
)

echo [%date% %time%] [START] workers Token=%WORKER_TOKEN% >> "%LOG_FILE%"

rem Heartbeat, consultation, and outbox only. EOD publication remains separately gated.
"%PHP_BIN%" "%SCRIPT_DIR%worker_lock.php" heartbeat --token=%WORKER_TOKEN% >nul 2>&1
set CONSULTATION_EXIT=0
"%PHP_BIN%" "%SCRIPT_DIR%launch_app_writer.php" --consultation-queue >> "%LOG_FILE%" 2>&1
if errorlevel 1 set CONSULTATION_EXIT=1
"%PHP_BIN%" "%SCRIPT_DIR%worker_lock.php" heartbeat --token=%WORKER_TOKEN% >nul 2>&1
set OUTBOX_EXIT=0
"%PHP_BIN%" "%SCRIPT_DIR%launch_app_writer.php" --process-outbox >> "%LOG_FILE%" 2>&1
if errorlevel 1 set OUTBOX_EXIT=1
set EOD_EXIT=HELD
set NARRATIVE_EXIT=HELD

echo [%date% %time%] [FINISH] Token=%WORKER_TOKEN% consultation=%CONSULTATION_EXIT% outbox=%OUTBOX_EXIT% eod=%EOD_EXIT% narrative=%NARRATIVE_EXIT% publication=HELD >> "%LOG_FILE%"

rem Pelepasan aman (hanya menghapus jika token masih cocok; tidak menghapus lock instance baru)
"%PHP_BIN%" "%SCRIPT_DIR%worker_lock.php" release --token=%WORKER_TOKEN% >> "%LOG_FILE%" 2>&1

if %CONSULTATION_EXIT% neq 0 exit /b %CONSULTATION_EXIT%
if %OUTBOX_EXIT% neq 0 exit /b %OUTBOX_EXIT%
exit /b 0
