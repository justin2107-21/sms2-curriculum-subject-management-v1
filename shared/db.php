<?php
// Database connection. Local XAMPP defaults are retained for template
// compatibility; deployment credentials should be supplied through the
// environment and must never be committed.
$dbHost = getenv('SMS_DB_HOST');
$dbUser = getenv('SMS_DB_USER');
$dbPass = getenv('SMS_DB_PASS');
$dbName = getenv('SMS_DB_NAME');

define('DB_HOST', $dbHost !== false ? $dbHost : 'localhost');
define('DB_USER', $dbUser !== false ? $dbUser : 'root');       // local XAMPP fallback
define('DB_PASS', $dbPass !== false ? $dbPass : '');           // local XAMPP fallback
define('DB_NAME', $dbName !== false ? $dbName : 'sms_db');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $error) {
    if (function_exists('log_application_error')) {
        log_application_error('Database connection failed', $error);
    } else {
        error_log('[SMS2] Database connection failed: ' . $error->getMessage());
    }

    throw new RuntimeException('Database connection unavailable.', 0, $error);
}
