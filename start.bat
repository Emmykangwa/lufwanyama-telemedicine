@echo off
REM ======================================================================
REM  Lufwanyama Telemedicine - start the system
REM  Double-click this file to run the app. Leave the window open while
REM  you use it; closing the window stops the web server.
REM ======================================================================
setlocal
set MARIADB=C:\mariadb
set PHP=C:\php\php.exe
set PROJECT=%~dp0

echo.
echo  Lufwanyama Telemedicine
echo  =======================
echo.

REM --- start MariaDB only if it is not already listening on 3306 --------
netstat -ano | findstr /R /C:"127.0.0.1:3306 .*LISTENING" >nul 2>&1
if %errorlevel%==0 (
  echo  [1/2] Database already running on port 3306.
) else (
  echo  [1/2] Starting the database...
  start "Telemedicine Database" /min "%MARIADB%\bin\mariadbd.exe" --datadir=%MARIADB%\data --port=3306 --bind-address=127.0.0.1
  REM give it a few seconds to come up
  ping -n 5 127.0.0.1 >nul
)

echo  [2/2] Starting the web server...
echo.
echo  Open your browser at:  http://localhost:8000
echo.
echo  Test accounts (password for all: Test@1234):
echo     Administrator            0970000001
echo     Health worker (Centre A) 0970000002
echo     Doctor (Centre A)        0970000004
echo     Patient                  0970000006
echo.
echo  Keep this window open. Close it (or press Ctrl+C) to stop the app.
echo.

REM open the browser after a short delay, then run the server (blocks)
start "" http://localhost:8000
cd /d "%PROJECT%"
"%PHP%" -S localhost:8000 router.php

endlocal
