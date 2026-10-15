@echo off
REM ======================================================================
REM  Lufwanyama Telemedicine - stop the system
REM  Stops the web server, then the MySQL server on port 3306.
REM ======================================================================
setlocal
set MYSQLBIN=

echo Stopping the web server...
for /f "tokens=5" %%p in ('netstat -ano ^| findstr /R /C:"127.0.0.1:8000 .*LISTENING"') do taskkill /F /PID %%p >nul 2>&1

REM Find a mysqladmin to ask the server to shut down cleanly.
if exist "C:\mysql\bin\mysqladmin.exe" set "MYSQLBIN=C:\mysql\bin"
if not defined MYSQLBIN for /d %%d in ("C:\Program Files\MySQL\MySQL Server*") do if exist "%%~d\bin\mysqladmin.exe" set "MYSQLBIN=%%~d\bin"
if not defined MYSQLBIN if exist "C:\xampp\mysql\bin\mysqladmin.exe" set "MYSQLBIN=C:\xampp\mysql\bin"

echo Stopping MySQL...
if defined MYSQLBIN (
  "%MYSQLBIN%\mysqladmin.exe" -h 127.0.0.1 -P 3306 -u root shutdown >nul 2>&1
  if errorlevel 1 taskkill /F /IM mysqld.exe >nul 2>&1
) else (
  taskkill /F /IM mysqld.exe >nul 2>&1
)

echo Done.
ping -n 2 127.0.0.1 >nul
endlocal
