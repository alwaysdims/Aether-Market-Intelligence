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

REM 1. Pengumpulan Data Forward Terverifikasi (Market, News, Macro)
python "%ROOT_DIR%\services\forward_data_collector.py" >> "%LOG_FILE%" 2>&1
if errorlevel 1 (
    echo [%date% %time%] [WARNING] forward_data_collector menemui kendala minor, melanjutkan sync-eod >> "%ROOT_LOG_FILE%" 2>&1
)

"%PHP_BIN%" artisan hermes:sync-eod --trigger=scheduled >> "%LOG_FILE%" 2>&1
set SYNC_EXIT=%errorlevel%
if %SYNC_EXIT% neq 0 (
    echo [%date% %time%] [RUNNER] [ERROR] hermes:sync-eod gagal [exit code: %SYNC_EXIT%]. Evaluasi horizon dibatalkan. >> "%ROOT_LOG_FILE%" 2>&1
    echo [%date% %time%] [ERROR] hermes:sync-eod gagal dengan exit code %SYNC_EXIT%. Evaluasi horizon dibatalkan. >> "%LOG_FILE%" 2>&1
    exit /b %SYNC_EXIT%
)
echo [%date% %time%] [RUNNER] hermes:sync-eod berhasil [exit code: 0] >> "%ROOT_LOG_FILE%" 2>&1

"%PHP_BIN%" artisan hermes:evaluate-horizon >> "%LOG_FILE%" 2>&1
set EVAL_EXIT=%errorlevel%
if %EVAL_EXIT% neq 0 (
    echo [%date% %time%] [RUNNER] [ERROR] hermes:evaluate-horizon gagal [exit code: %EVAL_EXIT%]. >> "%ROOT_LOG_FILE%" 2>&1
    echo [%date% %time%] [ERROR] hermes:evaluate-horizon gagal dengan exit code %EVAL_EXIT%. >> "%LOG_FILE%" 2>&1
    exit /b %EVAL_EXIT%
)
echo [%date% %time%] [RUNNER] hermes:evaluate-horizon berhasil [exit code: 0] >> "%ROOT_LOG_FILE%" 2>&1

REM 3. Pemantauan Pasar & Deteksi Expiry 14 Hari
"%PHP_BIN%" artisan hermes:monitor-market >> "%LOG_FILE%" 2>&1
echo [%date% %time%] [RUNNER] hermes:monitor-market selesai [exit code: %errorlevel%] >> "%ROOT_LOG_FILE%" 2>&1

REM 3b. Sinkronisasi Terkontrol Histori EOD & Broker Summary IDX Edge (Canary BBCA)
"%PHP_BIN%" artisan hermes:sync-idx-edge-history BBCA --limit=20 >> "%LOG_FILE%" 2>&1
"%PHP_BIN%" artisan hermes:sync-idx-edge-broker BBCA >> "%LOG_FILE%" 2>&1
echo [%date% %time%] [RUNNER] hermes:sync-idx-edge-history & broker selesai >> "%ROOT_LOG_FILE%" 2>&1

REM 4. Pemrosesan Antrean Outbox Telegram
"%PHP_BIN%" artisan hermes:process-outbox --live >> "%LOG_FILE%" 2>&1
echo [%date% %time%] [RUNNER] hermes:process-outbox selesai [exit code: %errorlevel%] >> "%ROOT_LOG_FILE%" 2>&1

echo [FINISH] EOD Pipeline Selesai Sukses: %date% %time% >> "%LOG_FILE%" 2>&1
echo [%date% %time%] [RUNNER] [FINISH] Task Scheduler EOD Pipeline selesai sukses [exit code: 0] >> "%ROOT_LOG_FILE%" 2>&1
exit /b 0
