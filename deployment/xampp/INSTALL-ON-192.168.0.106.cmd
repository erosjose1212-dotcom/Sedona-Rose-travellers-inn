@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Run-SedonaRose.ps1" -Action Install
if errorlevel 1 (
    echo Sedona Rose installation failed. Read the error above before retrying.
    exit /b 1
)
echo Open http://192.168.0.106/login from a device on the local network.
