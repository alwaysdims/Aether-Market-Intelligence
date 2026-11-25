@echo off
REM Aether Market Intelligence - Automated Laravel Scheduler Runner
REM Executes every minute via Windows Task Scheduler or background runner.

set SCRIPT_DIR=%~dp0
for %%I in ("%SCRIPT_DIR%..") do set ROOT_DIR=%%~fI
cd /d "%ROOT_DIR%\dashboard"
set "RUN_ID=SCHED_%DATE:~-4%%DATE:~4,2%%DATE:~7,2%_%TIME:~0,2%%TIME:~3,2%%TIME:~6,2%"
set "RUN_ID=%RUN_ID: =0%"
set "LOG_FILE=%ROOT_DIR%\dashboard\storage\logs\scheduler_output.log"
>> "%LOG_FILE%" echo {"run_id":"%RUN_ID%","event":"START","started_at":"%DATE% %TIME%"}
"C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe" artisan schedule:run >> "%LOG_FILE%" 2>&1
set "EXIT_CODE=%ERRORLEVEL%"
>> "%LOG_FILE%" echo {"run_id":"%RUN_ID%","event":"END","finished_at":"%DATE% %TIME%","exit_code":%EXIT_CODE%}
exit /b %EXIT_CODE%
