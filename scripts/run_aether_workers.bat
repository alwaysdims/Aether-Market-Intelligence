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

rem Heartbeat & Prioritas 1: Backfill Antrean BEI (Pekerjaan Berat)
"%PHP_BIN%" "%SCRIPT_DIR%worker_lock.php" heartbeat --token=%WORKER_TOKEN% >nul 2>&1
"%PHP_BIN%" artisan idx:process-queue --limit=3 >> "%LOG_FILE%" 2>&1
set IDX_EXIT=%errorlevel%

rem Heartbeat & Prioritas 2: Antrean Narasi Gemini Background (Rate-Limited, Prioritas Terendah)
"%PHP_BIN%" "%SCRIPT_DIR%worker_lock.php" heartbeat --token=%WORKER_TOKEN% >nul 2>&1
"%PHP_BIN%" artisan idx:process-narratives --limit=2 >> "%LOG_FILE%" 2>&1
set NARRATIVE_EXIT=%errorlevel%

echo [%date% %time%] [FINISH] Token=%WORKER_TOKEN% idx=%IDX_EXIT% narrative=%NARRATIVE_EXIT% >> "%LOG_FILE%"

rem Pelepasan aman (hanya menghapus jika token masih cocok; tidak menghapus lock instance baru)
"%PHP_BIN%" "%SCRIPT_DIR%worker_lock.php" release --token=%WORKER_TOKEN% >> "%LOG_FILE%" 2>&1

if %IDX_EXIT% neq 0 exit /b %IDX_EXIT%
if %NARRATIVE_EXIT% neq 0 exit /b %NARRATIVE_EXIT%
exit /b 0
