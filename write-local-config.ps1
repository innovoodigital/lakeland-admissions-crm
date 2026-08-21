$ErrorActionPreference = "Stop"

$password = [Environment]::GetEnvironmentVariable("LOCAL_DB_PASS")
if ($null -eq $password) {
    $password = ""
}

$phpPassword = $password.Replace("\", "\\").Replace("'", "\'")

$content = @"
<?php
// Local development settings. Do not upload this file to cPanel.

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'lakeland_crm');
define('DB_USER', 'root');
define('DB_PASS', '$phpPassword');

define('SITE_TITLE', 'Lakeland Admissions Dashboard');
define('SCHOOL_NAME', 'Lakeland Inter-American School');
define('MONTHLY_VISIT_GOAL', 20);
define('MONTHLY_CONVERSION_GOAL', 5);
define('BASE_PATH', '');
"@

Set-Content -Path "config.local.php" -Value $content
