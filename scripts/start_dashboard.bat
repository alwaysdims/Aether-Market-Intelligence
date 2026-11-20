@echo off
setlocal
set SCRIPT_DIR=%~dp0
for %%I in ("%SCRIPT_DIR%..") do set ROOT_DIR=%%~fI
set PHP_BIN=C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe
set LAUNCHER=%SCRIPT_DIR%launch_readonly_dashboard.php
set LAUNCH_EXIT=0

if not exist "%PHP_BIN%" (
    echo [ERROR] PHP binary tidak ditemukan: %PHP_BIN%
    set LAUNCH_EXIT=1
    goto finish
)

if not exist "%LAUNCHER%" (
    echo [ERROR] Launcher dashboard tidak ditemukan: %LAUNCHER%
    set LAUNCH_EXIT=2
    goto finish
)

echo =========================================================================
echo  AETHER MARKET INTELLIGENCE - MODE PEMULIHAN HANYA-BACA
echo  URL: http://127.0.0.1:8000
echo =========================================================================
if "%~1"=="" (
    "%PHP_BIN%" "%LAUNCHER%" --serve
) else (
    "%PHP_BIN%" "%LAUNCHER%" %*
)
set LAUNCH_EXIT=%errorlevel%

:finish
if not "%LAUNCH_EXIT%"=="0" (
    echo [ERROR] Dashboard berhenti dengan exit code %LAUNCH_EXIT%.
    echo Salin pesan JSON stage/reason/sqlstate di atas. Jangan kirim credential.
)
exit /b %LAUNCH_EXIT%
