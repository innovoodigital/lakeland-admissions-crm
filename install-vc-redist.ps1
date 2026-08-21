$ErrorActionPreference = "Stop"

$installer = Join-Path $env:TEMP "vc_redist.x64.exe"

Write-Host "Downloading Microsoft Visual C++ Redistributable..."
Invoke-WebRequest -Uri "https://aka.ms/vs/17/release/vc_redist.x64.exe" -OutFile $installer

Write-Host "Installing Microsoft Visual C++ Redistributable..."
Start-Process -FilePath $installer -ArgumentList "/install", "/quiet", "/norestart" -Wait

Write-Host "Checking PHP again..."
& "C:\php\php.exe" -v
& "C:\php\php.exe" -m | Select-String -Pattern '^pdo_mysql$|^mysqli$'
