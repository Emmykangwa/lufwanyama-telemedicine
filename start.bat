@echo off
REM ======================================================================
REM  Lufwanyama Telemedicine - start the system
REM  Double-click this file to run the app. Leave the window open while
REM  you use it; closing the window stops the web server.
REM ======================================================================
setlocal
set PHP=C:\php\php.exe
set PROJECT=%~dp0
set MYSQLBIN=
set MYSQLOPTS=

echo.
echo  Lufwanyama Telemedicine
echo  =======================
echo.

REM --- [1/2] the MySQL database ----------------------------------------
REM Skip starting a server if one is already listening on port 3306.
netstat -ano | findstr /R /C:":3306 .*LISTENING" >nul 2>&1
if %errorlevel%==0 (
  echo  [1/2] MySQL already running on port 3306.
  goto :webserver
)

REM Look for a MySQL server, standalone install first, then XAMPP's.
if exist "C:\mysql\bin\mysqld.exe" (
  set "MYSQLBIN=C:\mysql\bin"
  if exist "C:\mysql\my.ini" set "MYSQLOPTS=--defaults-file=C:\mysql\my.ini"
)
if not defined MYSQLBIN for /d %%d in ("C:\Program Files\MySQL\MySQL Server*") do if exist "%%~d\bin\mysqld.exe" set "MYSQLBIN=%%~d\bin"
if not defined MYSQLBIN if exist "C:\xampp\mysql\bin\mysqld.exe" (
  set "MYSQLBIN=C:\xampp\mysql\bin"
  set "MYSQLOPTS=--defaults-file=C:\xampp\mysql\bin\my.ini"
)

if not defined MYSQLBIN (
  echo  [1/2] No MySQL server was found.
  echo.
  echo        Looked in:  C:\mysql\bin
  echo                    C:\Program Files\MySQL\MySQL Server*\bin
  echo                    C:\xampp\mysql\bin
  echo.
  echo        Install MySQL, or start your own server on port 3306,
  echo        then run this file again.  See SETUP.md.
  echo.
  pause
  exit /b 1
)

echo  [1/2] Starting MySQL from %MYSQLBIN% ...
start "Telemedicine Database (MySQL)" /min "%MYSQLBIN%\mysqld.exe" %MYSQLOPTS% --port=3306
REM give the server a few seconds to come up
ping -n 6 127.0.0.1 >nul

:webserver
echo  [2/2] Starting the web server...
echo.
echo  Open your browser at:  http://localhost:8000
echo.
echo  Demo accounts (created by: php tools\demo_accounts.php)
echo     Administrator   0970000001   Admin@2026
echo     Health worker   0970000002   Health@2026
echo     Doctor          0970000003   Doctor@2026
echo     Patient         0970000004   Patient@2026
echo.
echo  Keep this window open. Close it (or press Ctrl+C) to stop the app.
echo.

REM open the browser after a short delay, then run the server (blocks)
start "" http://localhost:8000
cd /d "%PROJECT%"
"%PHP%" -S localhost:8000 router.php

endlocal
