@echo off
REM Aether Market Intelligence - Web Dashboard Launcher
REM Only starts the Laravel HTTP Web Server on port 8000.
REM No AI inference, no database writes, no scheduler modification.

set SCRIPT_DIR=%~dp0
for %%I in ("%SCRIPT_DIR%..") do set ROOT_DIR=%%~fI
set DASHBOARD_DIR=%ROOT_DIR%\dashboard
set PHP_BIN=C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe

if not exist "%DASHBOARD_DIR%" (
    echo [ERROR] Direktori dashboard tidak ditemukan: %DASHBOARD_DIR%
    pause
    exit /b 1
)

if not exist "%PHP_BIN%" (
    echo [ERROR] PHP binary tidak ditemukan: %PHP_BIN%
    pause
    exit /b 1
)

echo =========================================================================
echo  AETHER MARKET INTELLIGENCE - DASHBOARD WEB CONTROL PLANE
echo =========================================================================
echo  Root Folder: %ROOT_DIR%
echo  Dashboard  : %DASHBOARD_DIR%
echo  URL Akses  : http://localhost:8000
echo =========================================================================
echo  Tekan Ctrl+C untuk menghentikan web server.
echo =========================================================================

cd /d "%DASHBOARD_DIR%"
"%PHP_BIN%" artisan serve --host=127.0.0.1 --port=8000
