<?php
// The legacy fingerprint accepts declared changes and still detects unexpected ones. sms_db_uitest only.
// Pure cases change an in-memory copy of the state. End-to-end cases change the test database, run the real
// deploy.php --status in a separate process, and restore each change.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('DB_NAME') !== 'sms_db_uitest') { fwrite(STDERR, "Refusing: set DB_NAME=sms_db_uitest\n"); exit(1); }
require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/../database/legacy_baseline.php';
$db = csms_database_connect(csms_database_config());
if ($db->query('SELECT DATABASE()')->fetch_row()[0] !== 'sms_db_uitest') exit(1);

$failures = 0; $checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void { $checks++; if (!$ok) { $failures++; echo "FAIL $label\n"; } };
$stored = $db->query('SELECT schema_fingerprint FROM schema_baseline_adoptions')->fetch_row()[0];
$check($stored === '7b1d7009bd961389ecb1397e20b76eea4deb7ffd144d62802beee64ff5d17593', 'stored record is the original 2 Nov fingerprint');
$check(hash_equals($stored, csms_legacy_baseline_fingerprint($db)), 'current state matches the stored fingerprint');

$state = csms_legacy_baseline_state($db);
$changes = csms_legacy_baseline_applied_changes($db);
$hash = static fn(array $s, ?array $c = null): string => csms_legacy_baseline_hash(csms_legacy_baseline_normalize($s, $c ?? $changes));
$detects = static function (callable $mutate, string $label) use ($state, $hash, $stored, $check): void {
    $copy = $state; $mutate($copy);
    $check(!hash_equals($stored, $hash($copy)), "detected: $label");
};
$check(!hash_equals($stored, csms_legacy_baseline_hash($state)), 'without the declared changes the state does not match');
$check(!hash_equals($stored, $hash($state, [])), 'no applied entry means the strict check');
$roleIndex = static function (array $s, string $name): int { foreach ($s['roles'] as $i => $r) if ($r['name'] === $name) return $i; throw new RuntimeException($name); };
$legacyGrant = static function (array $s) use ($roleIndex): int { $id = $s['roles'][$roleIndex($s, 'student')]['id']; foreach ($s['role_permissions'] as $i => $r) if ($r['role_id'] === $id) return $i; throw new RuntimeException('grant'); };
$permId = static function (array $s, string $name): string { foreach ($s['permissions'] as $p) if ($p['name'] === $name) return $p['id']; throw new RuntimeException($name); };

$detects(function (&$s) use ($roleIndex) { $s['roles'][$roleIndex($s, 'student')]['name'] = 'student2'; }, 'legacy role renamed');
$detects(function (&$s) use ($roleIndex) { $s['roles'][$roleIndex($s, 'student')]['description'] = 'x'; }, 'legacy role description edited');
$detects(function (&$s) use ($roleIndex) { unset($s['roles'][$roleIndex($s, 'student')]); }, 'legacy role deleted');
$detects(function (&$s) use ($roleIndex) { $s['roles'][] = ['id' => '9999', 'name' => 'surprise', 'description' => null]; }, 'undeclared role added');
$detects(function (&$s) use ($legacyGrant) { unset($s['role_permissions'][$legacyGrant($s)]); }, 'legacy grant removed');
$detects(function (&$s) use ($roleIndex, $permId) { $s['role_permissions'][] = ['role_id' => $s['roles'][$roleIndex($s, 'student')]['id'], 'permission_id' => $permId($s, 'user.manage')]; }, 'new permission granted to a legacy role');
$detects(function (&$s) { $s['permissions'][0]['description'] = 'x'; }, 'legacy permission edited');
$detects(function (&$s) { $s['permissions'][] = ['id' => '9999', 'name' => 'surprise', 'module' => 'x', 'description' => null]; }, 'undeclared permission added');
$detects(function (&$s) { $s['columns'][] = ['TABLE_NAME' => 'subjects', 'COLUMN_NAME' => 'surprise', 'ORDINAL_POSITION' => '99', 'COLUMN_TYPE' => 'int']; }, 'undeclared column on subjects');
$detects(function (&$s) { foreach ($s['columns'] as $i => $c) if ($c['TABLE_NAME'] === 'roles' && $c['COLUMN_NAME'] === 'is_active') $s['columns'][$i]['COLUMN_TYPE'] = 'int(11)'; }, 'roles.is_active has the wrong type');
$detects(function (&$s) { foreach ($s['columns'] as $i => $c) if ($c['TABLE_NAME'] === 'roles' && $c['COLUMN_NAME'] === 'user_type') unset($s['columns'][$i]); }, 'declared column missing');
$detects(function (&$s) use ($roleIndex) { unset($s['roles'][$roleIndex($s, 'staff')]); }, 'declared new role missing');
$detects(function (&$s) { foreach ($s['permissions'] as $i => $p) if ($p['name'] === 'user.manage') unset($s['permissions'][$i]); }, 'declared new permission missing');
$detects(function (&$s) use ($roleIndex) { $s['roles'][$roleIndex($s, 'student')]['name'] = 'legacy_admin'; }, 'second role renamed to legacy_admin');
$detects(function (&$s) use ($roleIndex) { $s['roles'][$roleIndex($s, 'legacy_admin')]['name'] = 'old_admin'; }, 'declared rename missing');

// Allowed by design: grants on the five new roles are outside the fingerprint (see tools/rbac-drift-report.php).
$copy = $state; $copy['role_permissions'][] = ['role_id' => $copy['roles'][$roleIndex($copy, 'staff')]['id'], 'permission_id' => $permId($copy, 'dashboard.view') . '0'];
$check(hash_equals($stored, $hash($copy)), 'grants on new roles stay outside the fingerprint');
// A future entry works the same way.
$copy = $state; $copy['columns'][] = ['TABLE_NAME' => 'subjects', 'COLUMN_NAME' => 'subject_category', 'ORDINAL_POSITION' => '99', 'COLUMN_TYPE' => 'varchar(30)', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => 'NULL'];
$future = $changes + ['028_x.sql' => ['columns' => ['subjects' => ['subject_category' => ['COLUMN_TYPE' => 'varchar(30)', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => 'NULL']]]]];
$check(hash_equals($stored, $hash($copy, $future)), 'a declared later column is accepted');

// End to end through the real deploy.php.
$status = static function (): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../database/deploy.php') . ' --status 2>&1';
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
};
$refused = static function (string $do, string $undo, string $label) use ($db, $status, $check): void {
    $db->query($do);
    try {
        [$code, $out] = $status();
        $check($code !== 0 && str_contains($out, 'Legacy baseline adoption record is invalid'), "deploy refuses: $label");
    } finally {
        $db->query($undo);
    }
};
[$code, $out] = $status();
$check($code === 0, 'deploy.php --status accepts the current state: ' . $out);

$role = $db->query("SELECT id, description FROM roles WHERE name='student'")->fetch_assoc();
$description = $db->real_escape_string((string)$role['description']);
$refused("UPDATE roles SET description='tampered' WHERE id={$role['id']}", "UPDATE roles SET description='$description' WHERE id={$role['id']}", 'legacy role edited');
$grant = $db->query("SELECT rp.role_id, rp.permission_id FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE r.name='student' AND p.name NOT IN ('" . implode("','", csms_legacy_baseline_future_permissions()) . "') LIMIT 1")->fetch_assoc();
$refused("DELETE FROM role_permissions WHERE role_id={$grant['role_id']} AND permission_id={$grant['permission_id']}", "INSERT INTO role_permissions(role_id,permission_id) VALUES({$grant['role_id']},{$grant['permission_id']})", 'legacy grant removed');
$refused('ALTER TABLE subjects ADD COLUMN surprise_col INT NULL', 'ALTER TABLE subjects DROP COLUMN surprise_col', 'undeclared column');
$ledger = $db->query("SELECT checksum FROM schema_migrations WHERE migration='027_final_user_types.sql'")->fetch_row()[0];
$refused("UPDATE schema_migrations SET checksum=REPEAT('0',64) WHERE migration='027_final_user_types.sql'", "UPDATE schema_migrations SET checksum='$ledger' WHERE migration='027_final_user_types.sql'", '027 ledger checksum changed');
$refused("UPDATE schema_baseline_adoptions SET schema_fingerprint=REPEAT('0',64)", "UPDATE schema_baseline_adoptions SET schema_fingerprint='$stored'", 'stored fingerprint altered');
$refused("INSERT INTO schema_baseline_adoptions(adoption_key,schema_fingerprint,covered_through,responsible_process,notes) VALUES('extra','$stored','x','test','test')", "DELETE FROM schema_baseline_adoptions WHERE adoption_key='extra'", 'second adoption row');

[$code, $out] = $status();
$check($code === 0, 'deploy.php --status accepts the restored state: ' . $out);
echo $failures ? "FAILED $failures of $checks\n" : "PASS $checks checks\n";
exit($failures ? 1 : 0);
