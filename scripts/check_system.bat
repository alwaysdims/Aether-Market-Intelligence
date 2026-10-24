@echo off
REM Aether Market Intelligence - System Diagnostic & Health Checker (Read-Only)
REM Strictly read-only: zero database writes, zero AI requests, zero Telegram alerts.

set SCRIPT_DIR=%~dp0
for %%I in ("%SCRIPT_DIR%..") do set ROOT_DIR=%%~fI
set DASHBOARD_DIR=%ROOT_DIR%\dashboard
set PHP_BIN=C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe

echo =========================================================================
echo  AETHER MARKET INTELLIGENCE - SISTEM DIAGNOSTIK DAN KESEHATAN (READ-ONLY)
echo =========================================================================
echo  Root Proyek  : %ROOT_DIR%
echo  Waktu Sistem : %date% %time%
echo =========================================================================

if not exist "%DASHBOARD_DIR%" (
    echo [ERROR] Folder dashboard tidak ditemukan: %DASHBOARD_DIR%
    exit /b 1
)

if not exist "%PHP_BIN%" (
    echo [ERROR] PHP binary tidak ditemukan: %PHP_BIN%
    exit /b 1
)

cd /d "%DASHBOARD_DIR%"
"%PHP_BIN%" artisan hermes:monitor

echo.
echo =========================================================================
echo  STATUS TASK SCHEDULER WINDOWS
echo =========================================================================
schtasks /query /tn "HermesMarketIntelligence_EOD" /fo list | findstr /i "TaskName Status Last Next Task"

echo =========================================================================
echo  PEMERIKSAAN KESEHATAN SELESAI (Nol Modifikasi Data / Nol Request AI)
echo =========================================================================
