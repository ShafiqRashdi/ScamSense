@echo off
REM ===================================================================
REM  ScamSense - start everything
REM
REM  Double-click this file, or run "start.bat" from a terminal.
REM  It opens two windows, one per service, and leaves them running.
REM  Close those windows (or press Ctrl+C in them) to stop.
REM
REM  MySQL is a Windows service and starts on boot, so it is not
REM  launched here - only checked.
REM ===================================================================

echo.
echo   ScamSense - starting services
echo   =============================
echo.

REM --- MySQL must be running before Laravel can talk to the database ---
sc query MYSQL80 | find "RUNNING" >nul
if errorlevel 1 (
    echo   [!] MySQL is NOT running.
    echo.
    echo       Start it from an ADMINISTRATOR PowerShell:
    echo           net start MYSQL80
    echo.
    echo       To make it start automatically on boot, run once:
    echo           Set-Service MYSQL80 -StartupType Automatic
    echo.
    pause
    exit /b 1
)
echo   [ok] MySQL is running

REM --- Read the shared HMAC secret out of backend/.env -----------------
REM  Both services must use the identical value, so it is read from one
REM  place rather than duplicated.
set "ML_SECRET="
for /f "usebackq tokens=1,* delims==" %%A in ("%~dp0backend\.env") do (
    if "%%A"=="ML_SERVICE_SECRET" set "ML_SECRET=%%B"
)
if "%ML_SECRET%"=="" (
    echo   [!] ML_SERVICE_SECRET is missing from backend\.env
    echo       The ML service will reject every request without it.
    pause
    exit /b 1
)
echo   [ok] Shared secret loaded from backend\.env

REM --- ML service (Flask, loopback only) -------------------------------
echo   [..] Starting ML service on http://127.0.0.1:5001
start "ScamSense ML service" cmd /k "cd /d %~dp0ml-service && set ML_SERVICE_SECRET=%ML_SECRET% && .venv\Scripts\python.exe wsgi.py"

REM --- Laravel ---------------------------------------------------------
echo   [..] Starting Laravel on http://127.0.0.1:8000
start "ScamSense web" cmd /k "cd /d %~dp0backend && php artisan serve --port=8000"

echo.
echo   Two windows have opened. Give them a few seconds, then visit:
echo.
echo       http://127.0.0.1:8000              the public site
echo       http://127.0.0.1:8000/admin/moderation   the moderation queue
echo.
echo   Leave both windows open while you work.
echo   Close them to stop the services.
echo.
pause
