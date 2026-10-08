@echo off
REM ======================================================================
REM  Lufwanyama Telemedicine - stop the system
REM  Stops both the web server and the database.
REM ======================================================================
echo Stopping the web server...
for /f "tokens=5" %%p in ('netstat -ano ^| findstr /R /C:"127.0.0.1:8000 .*LISTENING"') do taskkill /F /PID %%p >nul 2>&1

echo Stopping the database...
"C:\mariadb\bin\mariadb-admin.exe" -h 127.0.0.1 -P 3306 -u root shutdown >nul 2>&1
if errorlevel 1 taskkill /F /IM mariadbd.exe >nul 2>&1

echo Done.
ping -n 2 127.0.0.1 >nul
