<?php
// One connection configuration for HTTP, migrations and development CLI tools.
function csms_database_env(array $names, string $fallback): string
{
    foreach ($names as $name) {
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            return $value;
        }
    }
    return $fallback;
}

function csms_database_config(): array
{
    $port = csms_database_env(['DB_PORT', 'SMS_DB_PORT'], '3306');
    if (!preg_match('/^[0-9]{1,5}$/D', $port) || (int)$port < 1 || (int)$port > 65535) {
        throw new RuntimeException('Invalid database connection configuration.');
    }
    $config = [
        'host' => csms_database_env(['DB_HOST', 'SMS_DB_HOST'], 'localhost'),
        'user' => csms_database_env(['DB_USER', 'DB_USERNAME', 'SMS_DB_USER'], 'root'),
        'password' => csms_database_env(['DB_PASS', 'DB_PASSWORD', 'SMS_DB_PASS'], ''),
        'database' => csms_database_env(['DB_NAME', 'DB_DATABASE', 'SMS_DB_NAME'], 'sms_db'),
        'port' => (int)$port,
    ];
    if (getenv('APP_ENV') === 'production'
        && (in_array(strtolower($config['host']), ['localhost', '127.0.0.1', '::1'], true)
            || $config['user'] === 'root' || $config['password'] === ''
            || csms_database_env(['DB_NAME', 'DB_DATABASE', 'SMS_DB_NAME'], '') === '')) {
        throw new RuntimeException('Production database configuration is required.');
    }
    return $config;
}

function csms_database_connect(array $config, bool $selectDatabase = true): mysqli
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $db = new mysqli($config['host'], $config['user'], $config['password'],
            $selectDatabase ? $config['database'] : null, $config['port']);
        $db->set_charset('utf8mb4');
        return $db;
    } catch (mysqli_sql_exception $error) {
        // Neither server logs nor exceptions need to contain connection values.
        error_log('[CSMS] Database connection failed; driver code ' . (int)$error->getCode());
        throw new RuntimeException('Database connection unavailable.');
    }
}
