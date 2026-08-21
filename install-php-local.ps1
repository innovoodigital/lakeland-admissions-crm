$ErrorActionPreference = "Stop"

$phpVersion = "8.4.24"
$url = "https://downloads.php.net/~windows/releases/php-$phpVersion-Win32-vs17-x64.zip"
$zip = Join-Path $env:TEMP "php-$phpVersion-Win32-vs17-x64.zip"
$installDir = "C:\php"

Write-Host "Downloading PHP $phpVersion..."
Invoke-WebRequest -Uri $url -OutFile $zip

Write-Host "Installing PHP to $installDir..."
New-Item -ItemType Directory -Path $installDir -Force | Out-Null
Expand-Archive -Path $zip -DestinationPath $installDir -Force

Write-Host "Creating php.ini..."
Copy-Item "$installDir\php.ini-development" "$installDir\php.ini" -Force

$ini = Get-Content "$installDir\php.ini"
$ini = $ini -replace '^;extension_dir = "ext"', 'extension_dir = "ext"'
$ini = $ini -replace '^;extension=pdo_mysql', 'extension=pdo_mysql'
$ini = $ini -replace '^;extension=mysqli', 'extension=mysqli'
Set-Content "$installDir\php.ini" $ini

Write-Host "Checking PHP..."
& "$installDir\php.exe" -v
& "$installDir\php.exe" -m | Select-String -Pattern '^pdo_mysql$|^mysqli$'

Write-Host "PHP is installed. This project can use C:\php\php.exe via start-local.bat."
