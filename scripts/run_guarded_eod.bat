@echo off
setlocal
powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "%~dp0run_guarded_eod.ps1" %*
exit /b %errorlevel%
