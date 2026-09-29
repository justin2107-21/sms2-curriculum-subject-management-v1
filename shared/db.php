<?php
require_once __DIR__ . '/database_config.php';
$databaseConfig = csms_database_config();
define('DB_HOST', $databaseConfig['host']);
define('DB_USER', $databaseConfig['user']);
define('DB_PASS', $databaseConfig['password']);
define('DB_NAME', $databaseConfig['database']);
define('DB_PORT', $databaseConfig['port']);
$conn = csms_database_connect($databaseConfig);
unset($databaseConfig);
