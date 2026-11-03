<?php
// Logged-in UI testing only ever targets a disposable sms_db_* database, never sms_db.
const CSMS_UITEST_DATABASE = 'sms_db_uitest';

function csms_uitest_database(?string $name): string
{
    if ($name === null || preg_match('/^sms_db_[a-z0-9_]+$/D', $name) !== 1 || $name === 'sms_db') {
        throw new RuntimeException('UI tests need a disposable sms_db_* database, never sms_db.');
    }
    return $name;
}

// CLI: php guard.php <name> exits 0 only when the name passes the guard.
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        echo csms_uitest_database($argv[1] ?? null), PHP_EOL;
        exit(0);
    } catch (RuntimeException $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
