@echo off
REM Aether Market Intelligence - Direct EOD Pipeline Runner (Hardened)
REM Scheduled at 16:05 WIB (Monday-Friday) on Windows Task Scheduler.

set SCRIPT_DIR=%~dp0
for %%I in ("%SCRIPT_DIR%..") do set ROOT_DIR=%%~fI
set APP_DIR=%ROOT_DIR%\dashboard
set ROOT_LOG_DIR=%ROOT_DIR%\logs
set ROOT_LOG_FILE=%ROOT_LOG_DIR%\scheduler_eod.log
set APP_LOG_DIR=%APP_DIR%\storage\logs
set LOG_FILE=%APP_LOG_DIR%\eod_pipeline_output.log
set PHP_BIN=C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe

if not exist "%ROOT_LOG_DIR%" mkdir "%ROOT_LOG_DIR%" 2>nul
if not exist "%APP_LOG_DIR%" mkdir "%APP_LOG_DIR%" 2>nul

echo [%date% %time%] [RUNNER] [START] Task Scheduler EOD Pipeline dimulai >> "%ROOT_LOG_FILE%" 2>&1

if not exist "%APP_DIR%" (
    echo [%date% %time%] [FATAL] Direktori kerja tidak ditemukan: %APP_DIR% >> "%ROOT_LOG_FILE%" 2>&1
    echo [%date% %time%] [FATAL] Direktori kerja tidak ditemukan: %APP_DIR%
    exit /b 1
)

cd /d "%APP_DIR%"
if errorlevel 1 (
    echo [%date% %time%] [FATAL] Gagal berpindah ke direktori: %APP_DIR% >> "%ROOT_LOG_FILE%" 2>&1
    echo [%date% %time%] [FATAL] Gagal berpindah ke direktori: %APP_DIR%
    exit /b 1
)

REM STOP-THE-LINE: legacy batch cannot bypass guarded candidate writer.
echo [%date% %time%] [HELD] Legacy EOD pipeline disabled; use launch_ingestion_writer.php only after target gate passes. >> "%ROOT_LOG_FILE%" 2>&1
exit /b 78

REM Unreachable legacy commands retained as incident evidence.
REM Do not remove or reactivate without explicit target/runtime review.


if not exist "%PHP_BIN%" (
    echo [%date% %time%] [FATAL] PHP executable tidak ditemukan: %PHP_BIN% >> "%ROOT_LOG_FILE%" 2>&1
    echo [%date% %time%] [FATAL] PHP executable tidak ditemukan: %PHP_BIN% >> "%LOG_FILE%" 2>&1
    exit /b 2
)

REM Verifikasi keberadaan direktori log
set LOG_DIR=%APP_DIR%\storage\logs
if not exist "%LOG_DIR%" (
    mkdir "%LOG_DIR%" 2>nul
    if errorlevel 1 (
        echo [%date% %time%] [FATAL] Direktori log tidak dapat dibuat: %LOG_DIR%
        exit /b 3
    )
)

echo =============================================================================== >> "%LOG_FILE%" 2>&1
if errorlevel 1 (
    echo [%date% %time%] [FATAL] Gagal menulis ke log file: %LOG_FILE%
    exit /b 3
)
echo [START] EOD Pipeline: %date% %time% >> "%LOG_FILE%" 2>&1
echo =============================================================================== >> "%LOG_FILE%" 2>&1

REM 1. Pembaruan Otomatis EOD Harian & Broker Summary Seluruh Emiten via IDX Edge PRO
"%PHP_BIN%" artisan hermes:refresh-eod --broker >> "%LOG_FILE%" 2>&1
set REFRESH_EXIT=%errorlevel%
if %REFRESH_EXIT% neq 0 (
    echo [%date% %time%] [WARNING] hermes:refresh-eod melaporkan backlog/waiting data sesi [exit code: %REFRESH_EXIT%] >> "%ROOT_LOG_FILE%" 2>&1
) else (
    echo [%date% %time%] [RUNNER] hermes:refresh-eod dan broker selesai sukses [exit code: 0] >> "%ROOT_LOG_FILE%" 2>&1
)

REM 2. Lanjutkan Backlog Universe Backfill jika masih ada ticker PENDING (Otomatis Resume Sesuai Budget Harian)
"%PHP_BIN%" artisan hermes:backfill-universe --raw --no-interaction >> "%LOG_FILE%" 2>&1
echo [%date% %time%] [RUNNER] hermes:backfill-universe backlog resume selesai >> "%ROOT_LOG_FILE%" 2>&1

REM 3. Evaluasi Horizon & Prediksi Pasar Pasca Pembaruan EOD
"%PHP_BIN%" artisan hermes:evaluate-horizon >> "%LOG_FILE%" 2>&1
set EVAL_EXIT=%errorlevel%
if %EVAL_EXIT% neq 0 (
    echo [%date% %time%] [RUNNER] [ERROR] hermes:evaluate-horizon gagal [exit code: %EVAL_EXIT%]. >> "%ROOT_LOG_FILE%" 2>&1
    echo [%date% %time%] [ERROR] hermes:evaluate-horizon gagal dengan exit code %EVAL_EXIT%. >> "%LOG_FILE%" 2>&1
) else (
    echo [%date% %time%] [RUNNER] hermes:evaluate-horizon berhasil [exit code: 0] >> "%ROOT_LOG_FILE%" 2>&1
)

REM 4. Pemantauan Pasar & Deteksi Expiry 14 Hari
"%PHP_BIN%" artisan hermes:monitor-market >> "%LOG_FILE%" 2>&1
echo [%date% %time%] [RUNNER] hermes:monitor-market selesai [exit code: %errorlevel%] >> "%ROOT_LOG_FILE%" 2>&1

REM 5. Pemrosesan Antrean Outbox Telegram
"%PHP_BIN%" artisan hermes:process-outbox --live >> "%LOG_FILE%" 2>&1
echo [%date% %time%] [RUNNER] hermes:process-outbox selesai [exit code: %errorlevel%] >> "%ROOT_LOG_FILE%" 2>&1

echo [FINISH] EOD Pipeline Selesai Sukses: %date% %time% >> "%LOG_FILE%" 2>&1
echo [%date% %time%] [RUNNER] [FINISH] Task Scheduler EOD Pipeline selesai sukses [exit code: 0] >> "%ROOT_LOG_FILE%" 2>&1
exit /b 0
