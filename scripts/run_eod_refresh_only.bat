@echo off
REM Aether Market Intelligence - EOD refresh only (recovery database, dedicated roles).
REM Runs hermes:refresh-eod through the guarded writer launcher so the legacy
REM dashboard\.env (project-bot/postgres) is never used.

setlocal
set SCRIPT_DIR=%~dp0
for %%I in ("%SCRIPT_DIR%..") do set ROOT_DIR=%%~fI
set LOG_DIR=%ROOT_DIR%\logs
set LOG_FILE=%LOG_DIR%\scheduler_eod_refresh.log
set PHP_BIN=C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%" 2>nul

if not exist "%PHP_BIN%" (
    echo [%date% %time%] [FATAL] PHP executable tidak ditemukan: %PHP_BIN% >> "%LOG_FILE%" 2>&1
    exit /b 2
)

if not exist "%SCRIPT_DIR%launch_ingestion_writer.php" (
    echo [%date% %time%] [FATAL] launcher tidak ditemukan >> "%LOG_FILE%" 2>&1
    exit /b 3
)

echo =============================================================================== >> "%LOG_FILE%" 2>&1
echo [START] EOD refresh (calendar session, full universe): %date% %time% >> "%LOG_FILE%" 2>&1

"%PHP_BIN%" "%SCRIPT_DIR%launch_ingestion_writer.php" --eod-daily >> "%LOG_FILE%" 2>&1
set REFRESH_EXIT=%errorlevel%

echo [END] EOD refresh exit code: %REFRESH_EXIT% >> "%LOG_FILE%" 2>&1
exit /b %REFRESH_EXIT%
