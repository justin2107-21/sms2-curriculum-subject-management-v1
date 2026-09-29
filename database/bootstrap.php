<?php
// CLI-only fresh-install helper. Never upgrades or resets an existing schema.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$options = getopt('', ['database:', 'confirm-empty']);
$target = $options['database'] ?? '';
if (!is_string($target) || !preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $target)
    || !array_key_exists('confirm-empty', $options)
    || in_array($target, ['mysql', 'sys', 'information_schema', 'performance_schema'], true)) {
    fwrite(STDERR, "Usage: php database/bootstrap.php --database=NEW_EMPTY_DATABASE --confirm-empty\n");
    exit(1);
}

$db = null;
try {
    // Applied SQL remains unchanged. Retarget only its explicit database commands.
    $files = array_merge([dirname(__DIR__) . '/students.sql'], glob(__DIR__ . '/migrations/*.sql'));
    $scripts = [];
    foreach ($files as $file) {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Cannot read ' . basename($file));
        }
        $sql = preg_replace('/^USE sms_db;\s*$/m', 'USE `' . $target . '`;', $sql, -1, $uses);
        if ($uses !== 1) {
            throw new RuntimeException('Unexpected database directive in ' . basename($file));
        }
        if (basename($file) === 'students.sql') {
            $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS sms_db\s+CHARACTER SET utf8mb4\s+COLLATE utf8mb4_unicode_ci;/i', '', $sql, -1, $creates);
            if ($creates !== 1) {
                throw new RuntimeException('Unexpected baseline database directive.');
            }
        }
        $scripts[$file] = $sql;
    }
    $db = new mysqli(getenv('SMS_DB_HOST') ?: 'localhost', getenv('SMS_DB_USER') ?: 'root', getenv('SMS_DB_PASS') ?: '');
    $db->set_charset('utf8mb4');
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
    $stmt->bind_param('s', $target);
    $stmt->execute();
    if ((int)$stmt->get_result()->fetch_row()[0] !== 0) {
        throw new RuntimeException('Refusing non-empty database: ' . $target);
    }
    $db->query('CREATE DATABASE IF NOT EXISTS `' . $target . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    foreach ($scripts as $file => $sql) {
        $db->multi_query($sql);
        do {
            $result = $db->store_result();
            if ($result) {
                $result->free();
            }
        } while ($db->more_results() && $db->next_result());
        echo 'Applied ' . basename($file) . PHP_EOL;
    }
    echo "Fresh schema ready: $target. No administrator password was seeded.\n";
} catch (Throwable $error) {
    if ($db instanceof mysqli) {
        try { $db->rollback(); } catch (Throwable $ignored) { }
    }
    // CLI operator only; never print credentials or SQL statement contents.
    fwrite(STDERR, 'Bootstrap stopped (' . get_class($error) . '): ' . ($error instanceof mysqli_sql_exception ? 'Database operation failed; inspect schema before retrying.' : $error->getMessage()) . "\nDDL already applied is not rolled back. No existing database was dropped.\n");
    exit(1);
}
