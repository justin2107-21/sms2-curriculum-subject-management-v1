<?php
// Static, no database. Fails if migration 027 or later changes fingerprint-covered state without a matching
// entry in csms_legacy_baseline_declared_changes(). How to add an entry: database/README.md.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../database/legacy_baseline.php';

$failures = 0; $checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void { $checks++; if (!$ok) { $failures++; echo "FAIL $label\n"; } };

// The scanner itself.
$flag = static fn(string $sql, ?array $entry = null): bool => csms_legacy_baseline_undeclared($sql, $entry) !== [];
$check($flag('ALTER TABLE subjects ADD COLUMN subject_category VARCHAR(30);'), 'undeclared column on subjects');
$check(!$flag('ALTER TABLE subjects ADD COLUMN subject_category VARCHAR(30);', ['columns' => ['subjects' => ['subject_category' => []]]]), 'declared column passes');
$check($flag('ALTER TABLE `subjects` ADD COLUMN a INT, ADD COLUMN b INT;', ['columns' => ['subjects' => ['a' => []]]]), 'second column undeclared');
$check($flag('ALTER TABLE subjects ADD a INT;', ['columns' => ['subjects' => ['a' => []]]]), 'ADD without COLUMN is refused');
$check($flag('ALTER TABLE subjects ADD INDEX idx_x (units);'), 'undeclared index');
$check(!$flag('ALTER TABLE subjects ADD INDEX idx_x (units);', ['indexes' => ['subjects' => ['idx_x']]]), 'declared index passes');
$check($flag('ALTER TABLE subjects ADD CONSTRAINT chk_x CHECK (units > 0);'), 'undeclared check');
$check($flag('ALTER TABLE subjects ADD CONSTRAINT fk_x FOREIGN KEY (id) REFERENCES users(id);'), 'undeclared foreign key');
$check($flag('ALTER TABLE users MODIFY email VARCHAR(300);', ['columns' => ['users' => ['email' => []]]]), 'MODIFY cannot be declared');
$check($flag('ALTER TABLE users DROP COLUMN email;'), 'DROP COLUMN is flagged');
$check($flag('CREATE INDEX idx_y ON subjects (units);'), 'CREATE INDEX is flagged');
$check($flag("INSERT INTO permissions (name) VALUES ('x');"), 'new permission undeclared');
$check(!$flag("INSERT INTO permissions (name) VALUES ('x');", ['permissions_added' => ['x']]), 'declared permission passes');
$check($flag("UPDATE roles SET description = 'x';"), 'role edit undeclared');
$check($flag("DELETE FROM role_permissions WHERE role_id = 1;"), 'grant removal undeclared');
$check($flag("INSERT INTO role_permissions (role_id, permission_id) VALUES (1, 2);"), 'grant insert undeclared');
$check($flag("INSERT IGNORE INTO roles (name) VALUES ('x');"), 'INSERT IGNORE on roles undeclared');
$check($flag("SET @s = 'ALTER TABLE subjects ADD COLUMN x INT'; PREPARE q FROM @s; EXECUTE q;"), 'dynamic SQL is flagged');
$check(!$flag("-- ALTER TABLE subjects ADD COLUMN x INT\nCREATE TABLE new_thing (id INT);"), 'comments and new tables are ignored');
$check(!$flag("INSERT INTO subjects (subject_code) VALUES ('ALTER TABLE roles ADD COLUMN x INT;');"), 'data rows of other tables and text in strings are ignored');
$check(!$flag('ALTER TABLE curriculum_version_subject_outcomes ADD COLUMN contribution_level CHAR(1);'), 'table outside the baseline needs no entry');

// The real migrations.
$declared = csms_legacy_baseline_declared_changes();
$files = glob(__DIR__ . '/../database/migrations/*.sql');
sort($files, SORT_STRING);
$names = array_map('basename', $files);
foreach (array_keys($declared) as $name) $check(in_array($name, $names, true), "entry $name has a migration file");
foreach ($files as $file) {
    $name = basename($file);
    $body = preg_replace('/^USE sms_db;\s*$/m', '', str_replace("\r\n", "\n", (string)file_get_contents($file)));
    foreach (csms_legacy_baseline_migration_problems($name, $body) as $problem) $check(false, "$name: $problem");
    $checks++;
}
$check(csms_legacy_baseline_migration_problems('020_old.sql', 'ALTER TABLE subjects ADD COLUMN x INT;') === [], 'migrations before 027 are outside the declaration rule');
$check(csms_legacy_baseline_migration_problems('028_x.sql', 'ALTER TABLE subjects ADD COLUMN x INT;') !== [], 'later undeclared migration is a problem');
$check(csms_legacy_baseline_migration_problems('027_final_user_types.sql', 'SELECT 1;') !== [], 'a changed 027 fails its pinned checksum');
$deploy = (string)file_get_contents(__DIR__ . '/../database/deploy.php');
$precheck = strpos($deploy, 'csms_legacy_baseline_migration_problems');
$check($precheck !== false && $precheck < strpos($deploy, 'CREATE TABLE schema_migrations') && $precheck < strpos($deploy, '$db->multi_query($sql)'), 'deploy.php checks declarations before it changes anything');
echo $failures ? "FAILED $failures of $checks\n" : "PASS $checks checks\n";
exit($failures ? 1 : 0);
