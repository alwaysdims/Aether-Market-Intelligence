@echo off
REM Root Launcher for Aether Market Intelligence EOD Pipeline
set SCRIPT_DIR=%~dp0
call "%SCRIPT_DIR%scripts\run_eod_pipeline.bat" %*
exit /b %errorlevel%
