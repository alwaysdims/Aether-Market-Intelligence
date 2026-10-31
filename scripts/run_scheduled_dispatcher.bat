@echo off
setlocal
set SCRIPT_DIR=%~dp0
for %%I in ("%SCRIPT_DIR%..") do set ROOT_DIR=%%~fI
set APP_DIR=%ROOT_DIR%\dashboard
set LOG_FILE=%ROOT_DIR%\logs\scheduled_jobs.log
set PHP_BIN=C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe
if not exist "%ROOT_DIR%\logs" mkdir "%ROOT_DIR%\logs"

cd /d "%APP_DIR%"
if errorlevel 1 exit /b 1

rem 1. Jalankan Laravel Scheduler (pengatur waktu tunggal untuk outbox, konsultasi, dan laporan harian)
"%PHP_BIN%" artisan schedule:run >> "%ROOT_DIR%\logs\laravel_schedule.log" 2>&1

rem 2. Dedicated lightweight dispatcher untuk pekerjaan terjadwal pengguna (durable scheduled jobs)
"%PHP_BIN%" artisan hermes:process-scheduled-jobs --live >> "%LOG_FILE%" 2>&1
exit /b %errorlevel%
