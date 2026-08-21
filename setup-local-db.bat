@echo off
setlocal

set "MYSQL_EXE="

where mysql >nul 2>nul
if not errorlevel 1 set "MYSQL_EXE=mysql"

if not defined MYSQL_EXE if exist "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" set "MYSQL_EXE=C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe"
if not defined MYSQL_EXE if exist "C:\xampp\mysql\bin\mysql.exe" set "MYSQL_EXE=C:\xampp\mysql\bin\mysql.exe"
if not defined MYSQL_EXE if exist "C:\laragon\bin\mysql\mysql-8.0\bin\mysql.exe" set "MYSQL_EXE=C:\laragon\bin\mysql\mysql-8.0\bin\mysql.exe"

if not defined MYSQL_EXE (
  echo MySQL client was not found.
  echo Install MySQL, XAMPP, WAMP, or Laragon, then run this file again.
  exit /b 1
)

set "MYSQL_USER=root"
set /p MYSQL_PASS=Enter local MySQL root password, or press Enter if blank: 

set "MYSQL_AUTH=-u%MYSQL_USER%"
if not "%MYSQL_PASS%"=="" set "MYSQL_AUTH=-u%MYSQL_USER% -p%MYSQL_PASS%"

echo Creating local database lakeland_crm...
"%MYSQL_EXE%" %MYSQL_AUTH% -e "CREATE DATABASE IF NOT EXISTS lakeland_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 (
  echo Could not connect to MySQL. Make sure the MySQL service is running and the password is correct.
  exit /b 1
)

echo Importing schema...
"%MYSQL_EXE%" %MYSQL_AUTH% lakeland_crm < "sql\schema.sql"
if errorlevel 1 exit /b 1

echo Importing sample leads...
"%MYSQL_EXE%" %MYSQL_AUTH% lakeland_crm < "sql\seed_leads.sql"
if errorlevel 1 exit /b 1

set "LOCAL_DB_PASS=%MYSQL_PASS%"
powershell -NoProfile -ExecutionPolicy Bypass -File "write-local-config.ps1"

echo Done.
echo Local app database: lakeland_crm
echo Default login: admin / admin123
