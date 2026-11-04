@echo off
setlocal
title RabinFlow Server Stopper
cd /d "%~dp0"

echo Stopping any running RabinFlow web server instances on port 8000...

for /f "tokens=5" %%a in ('netstat -ano -p tcp ^| findstr ":8000 " ^| findstr "LISTENING"') do (
    echo Terminating PID %%a on port 8000...
    taskkill /F /PID %%a >nul 2>nul
)

echo.
echo Checking port 8000 status...
netstat -ano -p tcp | findstr ":8000 " | findstr "LISTENING" >nul 2>nul
if %errorlevel% neq 0 (
    echo [SUCCESS] RabinFlow web server has been stopped.
) else (
    echo [NOTICE] Port 8000 may still be shutting down or owned by another process.
)

timeout /t 3 >nul
