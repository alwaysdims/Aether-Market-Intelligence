@echo off
setlocal
set SCRIPT_DIR=%~dp0
for %%I in ("%SCRIPT_DIR%..") do set ROOT_DIR=%%~fI
set LOG_DIR=%ROOT_DIR%\logs
set LOG_FILE=%LOG_DIR%\scheduled_jobs.log
set PHP_BIN=C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

rem Candidate-only wrapper owns environment, secret decryption, identity gate, and both scheduler calls.
"%PHP_BIN%" "%SCRIPT_DIR%run_guarded_dispatcher.php" >> "%LOG_FILE%" 2>&1
exit /b %errorlevel%
