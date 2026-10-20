@echo off
setlocal
title Stop Sedona Rose
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0deployment\xampp\Run-SedonaRose.ps1" -Action Stop
set "ROSE_STOP_EXIT=%ERRORLEVEL%"
echo.
if not "%ROSE_STOP_EXIT%"=="0" echo Sedona Rose could not be stopped. Read the message above.
pause
exit /b %ROSE_STOP_EXIT%
