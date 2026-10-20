@echo off
setlocal
title Start Sedona Rose
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0deployment\xampp\Run-SedonaRose.ps1" -Action Start
set "ROSE_START_EXIT=%ERRORLEVEL%"
echo.
if not "%ROSE_START_EXIT%"=="0" echo Sedona Rose could not be started. Read the message above.
pause
exit /b %ROSE_START_EXIT%
