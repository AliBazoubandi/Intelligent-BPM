# RabinFlow PowerShell Launcher
$ErrorActionPreference = "Stop"
Set-Location -Path $PSScriptRoot

# Locate PHP
$phpBin = "php"
if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    if (Test-Path "C:\xampp\php\php.exe") {
        $phpBin = "C:\xampp\php\php.exe"
    } elseif (Test-Path "C:\php\php.exe") {
        $phpBin = "C:\php\php.exe"
    } else {
        Write-Error "PHP executable not found in PATH or C:\xampp\php\php.exe."
        exit 1
    }
}

& $phpBin "$PSScriptRoot\bin\serve.php" @args
