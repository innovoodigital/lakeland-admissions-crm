<?php
if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
    return;
}

function env_value(string $key, string $default): string {
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
}

// Database Configuration
define('DB_HOST', env_value('DB_HOST', 'localhost'));
define('DB_NAME', env_value('DB_NAME', ''));
define('DB_USER', env_value('DB_USER', ''));
define('DB_PASS', env_value('DB_PASS', ''));

// Application Configuration
define('SITE_TITLE', env_value('SITE_TITLE', 'Lakeland Admissions Dashboard'));
define('SCHOOL_NAME', env_value('SCHOOL_NAME', 'Lakeland Inter-American School'));
define('MONTHLY_VISIT_GOAL', (int) env_value('MONTHLY_VISIT_GOAL', '20'));
define('MONTHLY_CONVERSION_GOAL', (int) env_value('MONTHLY_CONVERSION_GOAL', '5'));

// Base Path
// Leave as '/' because the application is installed in the subdomain root.
define('BASE_PATH', env_value('BASE_PATH', ''));
