@echo off
setlocal
title RabinFlow Server
cd /d "%~dp0"

:: 1. Find PHP binary
set "PHP_CMD=php"
where php >nul 2>nul
if %errorlevel% neq 0 (
    if exist "C:\xampp\php\php.exe" (
        set "PHP_CMD=C:\xampp\php\php.exe"
    ) else if exist "C:\php\php.exe" (
        set "PHP_CMD=C:\php\php.exe"
    ) else (
        echo [ERROR] PHP executable was not found in your system PATH or C:\xampp\php\php.exe.
        echo Please install or configure PHP to run RabinFlow.
        echo.
        pause
        exit /b 1
    )
)

:: 2. Launch RabinFlow Server via runner
"%PHP_CMD%" bin\serve.php %*

if %errorlevel% neq 0 (
    echo.
    echo Server process ended with code %errorlevel%.
    pause
)
