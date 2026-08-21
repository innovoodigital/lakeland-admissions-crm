@echo off
setlocal

set "PHP_EXE="

where php >nul 2>nul
if not errorlevel 1 set "PHP_EXE=php"

if not defined PHP_EXE if exist "C:\php\php.exe" set "PHP_EXE=C:\php\php.exe"
if not defined PHP_EXE if exist "C:\xampp\php\php.exe" set "PHP_EXE=C:\xampp\php\php.exe"
if not defined PHP_EXE if exist "C:\laragon\bin\php\php.exe" set "PHP_EXE=C:\laragon\bin\php\php.exe"

if not defined PHP_EXE (
  for /d %%D in ("C:\laragon\bin\php\*") do (
    if exist "%%~fD\php.exe" set "PHP_EXE=%%~fD\php.exe"
  )
)

if not defined PHP_EXE (
  for /d %%D in ("C:\wamp64\bin\php\php*") do (
    if exist "%%~fD\php.exe" set "PHP_EXE=%%~fD\php.exe"
  )
)

if not defined PHP_EXE (
  echo PHP was not found.
  echo Install PHP 8.x, XAMPP, WAMP, or Laragon, then run this file again.
  exit /b 1
)

if not exist "config.local.php" (
  copy "config.local.example.php" "config.local.php" >nul
  echo Created config.local.php using default local MySQL settings.
)

"%PHP_EXE%" -r "exit(extension_loaded('pdo_mysql') ? 0 : 1);"
if errorlevel 1 (
  echo PHP is installed, but the pdo_mysql extension is not enabled.
  echo Enable pdo_mysql in php.ini, then run this file again.
  exit /b 1
)

echo Starting Lakeland Admissions Dashboard...
echo Open http://localhost:8000
"%PHP_EXE%" -S localhost:8000 -t "%CD%"
