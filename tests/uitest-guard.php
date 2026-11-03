<?php
// DB-free: the UI-test database guard never accepts sms_db.
require_once __DIR__ . '/../tools/uitest/guard.php';

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failures++;
};
$rejects = static function (?string $name): bool {
    try { csms_uitest_database($name); return false; } catch (RuntimeException $e) { return true; }
};

foreach ([null, '', 'sms_db', 'SMS_DB', 'sms_db_', 'sms_db; drop', 'sms_db_UITEST', 'other_db', 'sms_db_uitest`'] as $bad) {
    $check($rejects($bad), 'rejects ' . var_export($bad, true));
}
$check(csms_uitest_database('sms_db_uitest') === 'sms_db_uitest', 'accepts sms_db_uitest');
$check(CSMS_UITEST_DATABASE === 'sms_db_uitest', 'fixed UI-test database name');

exit($failures === 0 ? 0 : 1);
