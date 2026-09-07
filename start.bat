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

REM --- Is anything already listening? -----------------------------------
REM  A leftover python.exe from an earlier session keeps port 5001 and a new
REM  process silently loses the race. The old one may hold an OLD secret, so
REM  every request fails the HMAC check and every verdict comes back "could
REM  not check" - which looks like a broken model rather than a stale process.
REM
REM  But "port busy" and "stale process" are not the same thing, and the first
REM  version of this check treated them as one: it aborted the whole script,
REM  so a perfectly healthy ML service left running from earlier stopped
REM  Laravel from starting at all. That is worse than the problem it solved.
REM  Each service is now handled on its own, and a port already serving is a
REM  reason to skip that one service rather than to give up.
set "START_ML=1"
set "START_WEB=1"

netstat -ano | findstr /r /c:"LISTENING" | findstr ":5001 " >nul
if not errorlevel 1 (
    set "START_ML="
    echo   [ok] Port 5001 already serving - leaving the ML service alone
)

netstat -ano | findstr /r /c:"LISTENING" | findstr ":8000 " >nul
if not errorlevel 1 (
    set "START_WEB="
    echo   [ok] Port 8000 already serving - leaving the web server alone
)

REM --- ML service (Flask, loopback only) -------------------------------
if not defined START_ML goto :skip_ml
echo   [..] Starting ML service on http://127.0.0.1:5001
REM  The quotes around the assignment are load-bearing. Written as
REM      set ML_SERVICE_SECRET=%ML_SECRET% && ...
REM  batch assigns everything up to the "&&" INCLUDING the space before it, so
REM  the service came up holding the secret with a trailing space. Laravel signs
REM  with the clean value, every signed request fails the HMAC check with a 401,
REM  and Laravel degrades to a partial verdict -- so /health keeps reporting
REM  "ok, models_loaded true" while every single verdict on the site reads
REM  "We could not check this properly". It cost three debugging sessions.
REM
REM  set "VAR=value" && ...  delimits the value with the quotes, so the space
REM  before the "&&" falls outside it.
start "ScamSense ML service" cmd /k "cd /d %~dp0ml-service && set "ML_SERVICE_SECRET=%ML_SECRET%" && .venv\Scripts\python.exe wsgi.py"
:skip_ml

REM --- Laravel ---------------------------------------------------------
if not defined START_WEB goto :skip_web
echo   [..] Starting Laravel on http://127.0.0.1:8000
start "ScamSense web" cmd /k "cd /d %~dp0backend && php artisan serve --port=8000"
:skip_web

REM --- Does the chain actually answer? ----------------------------------
REM  A listening port is not a working service. The failure this catches is
REM  a process holding a DIFFERENT secret: it is up, it has loaded both
REM  models, /health reports "ok" - and every signed request comes back 401,
REM  so every verdict on the site reads "We could not check this properly".
REM  Only a real signed request can tell the difference, which is what the
REM  doctor sends. Running it here means a broken chain is found now rather
REM  than in front of an audience.
echo.
echo   [..] Waiting for both services, then checking the chain end to end
REM  ping rather than timeout: `timeout /t` refuses to run when stdin is
REM  redirected and aborts with "Input redirection is not supported", which
REM  breaks this script from a pipe or a scheduled task. ping does not care.
ping -n 13 127.0.0.1 >nul 2>&1

pushd "%~dp0backend"
php artisan scamsense:doctor
set "DOCTOR=%errorlevel%"
popd

echo.
if not "%DOCTOR%"=="0" (
    echo   [!] The chain is NOT answering. The site will load, but every verdict
    echo       will say "We could not check this properly".
    echo.
    echo       Read the failing line above - it names the cause. The usual one
    echo       is an ML service left running from an earlier session holding an
    echo       out-of-date secret. Stop it and run this script again:
    echo.
    echo           Get-CimInstance Win32_Process ^| Where-Object { $_.CommandLine -match 'wsgi.py' } ^| Stop-Process -Force
    echo.
    pause
    exit /b 1
)

echo   Ready. Visit:
echo.
echo       http://127.0.0.1:8000              the public site
echo       http://127.0.0.1:8000/admin        the dashboard
echo       http://127.0.0.1:8000/admin/moderation   the moderation queue
echo.
echo   Leave the service windows open while you work.
echo   Close them to stop the services.
echo.
pause
