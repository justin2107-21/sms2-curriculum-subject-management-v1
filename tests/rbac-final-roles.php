<?php
// Final user types: grants, retired roles, account mapping, users.role ignored. DB_NAME must be sms_db_uitest.
// Writes happen inside a transaction that is rolled back.
if (getenv('DB_NAME') !== 'sms_db_uitest') { fwrite(STDERR, "Refusing: set DB_NAME=sms_db_uitest\n"); exit(1); }
require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/../database/ProvisionalRoles.php';
$conn = csms_database_connect(csms_database_config());
require_once __DIR__ . '/../shared/authorization.php';
$failures = 0; $checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void { $checks++; if (!$ok) { $failures++; echo "FAIL $label\n"; } };

$definitions = ProvisionalRoles::definitions();
$check(array_keys($definitions) === ['super_admin', 'admin', 'staff', 'acad_office', 'acad_faculty'], 'five defined roles');
$allKeys = array_column($conn->query('SELECT name FROM permissions')->fetch_all(MYSQLI_ASSOC), 'name');
$check(count($allKeys) === 51, 'permission count is 51, got ' . count($allKeys));

// Database grants equal the definitions exactly, in both directions.
foreach ($definitions as $role => $definition) {
    $stmt = $conn->prepare('SELECT p.name FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE r.name=?');
    $stmt->bind_param('s', $role); $stmt->execute();
    $actual = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'name'); $stmt->close();
    sort($actual); $expected = $definition['permissions']; sort($expected);
    $check($actual === $expected, "$role grants match: missing " . implode(',', array_diff($expected, $actual)) . ' extra ' . implode(',', array_diff($actual, $expected)));
}
$sa = $definitions['super_admin']['permissions']; sort($sa); $sorted = $allKeys; sort($sorted);
$check($sa === $sorted, 'Super Admin holds every permission');

// Decisions, spelled out so a matrix edit cannot pass silently.
$has = static fn(string $role, string $p): bool => in_array($p, $definitions[$role]['permissions'], true);
$check($has('admin', 'curriculum_export.official') && !$has('admin', 'curriculum_export.draft'), 'Admin official export only');
$check($has('admin', 'user.manage') && !$has('admin', 'rbac.manage'), 'Admin manages accounts, not permissions');
$check($has('acad_office', 'compliance.reference.verify') && $has('super_admin', 'compliance.reference.verify') && !$has('admin', 'compliance.reference.verify') && !$has('staff', 'compliance.reference.verify'), 'reference verify: Acad Office and Super Admin only');
$check($has('acad_office', 'curriculum.review.decide') && !$has('staff', 'curriculum.review.decide') && !$has('admin', 'curriculum.review.decide'), 'decide: Acad Office');
$check($has('staff', 'curriculum.review.submit') && !$has('acad_office', 'curriculum.review.submit'), 'submit: Staff');
foreach (['admin', 'staff', 'acad_office', 'acad_faculty'] as $role) {
    foreach (['student.view', 'student.create', 'student.update', 'student.delete', 'student.status.update', 'rbac.manage', 'settings.view'] as $p) {
        $check(!$has($role, $p) && !in_array($p, $definitions[$role]['grantable_permissions'], true), "$role cannot hold $p");
    }
}
$check(!$has('acad_faculty', 'curriculum.view_unapproved') && !$has('acad_faculty', 'curriculum.clo.manage'), 'Faculty restricted, CLOs read-only');

// Retired roles.
foreach (['legacy_admin', 'system_administrator', 'academic_curriculum_staff', 'department_head_curriculum_reviewer', 'faculty_academic_viewer', 'student'] as $role) {
    $row = $conn->query("SELECT is_active FROM roles WHERE name='$role'")->fetch_row();
    $check($row && (int)$row[0] === 0, "$role retired, row kept");
}
// A retired role's leftover grants and assignments give nothing.
foreach ($conn->query("SELECT DISTINCT ur.user_id, r.name FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE r.is_active=0")->fetch_all(MYSQLI_ASSOC) as $row) {
    $check(!in_array($row['name'], get_user_roles((int)$row['user_id']), true), 'retired role not effective: ' . $row['name']);
}
$legacy = (int)$conn->query("SELECT ur.user_id FROM user_roles ur JOIN users u ON u.id=ur.user_id WHERE u.username='admin' LIMIT 1")->fetch_row()[0];
$check(!user_can($legacy, 'rbac.manage') && !user_can($legacy, 'student.view') && user_can($legacy, 'user.manage'), 'admin account lost legacy-only grants');
$takashi = (int)$conn->query("SELECT id FROM users WHERE username='takashii001'")->fetch_row()[0];
$check(get_user_permissions($takashi) === [], 'student-only account has no permissions');
$check((int)$conn->query("SELECT COUNT(*) FROM roles r JOIN roles x ON x.id=r.id WHERE r.user_type='Acad' AND r.is_active=1")->fetch_row()[0] === 2, 'two roles under Acad');

// Requested accounts, when present in this copy.
$expect = ['csms_admin' => 'super_admin', 'kennethbonio' => 'super_admin', 'admin' => 'admin',
    'curriculum_reviewer' => 'acad_office', 'KENNETH123' => 'acad_office', 'faculty_viewer' => 'acad_faculty'];
foreach ($expect as $username => $role) {
    $stmt = $conn->prepare('SELECT GROUP_CONCAT(r.name), MAX(u.is_active) FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id AND r.is_active=1 WHERE u.username=?');
    $stmt->bind_param('s', $username); $stmt->execute(); $row = $stmt->get_result()->fetch_row(); $stmt->close();
    $check($row[0] === $role && (int)$row[1] === 1, "$username is active $role");
}
// Accounts may be re-assigned on the real site later, so the invariant is: every account has exactly one active role, and it is one of the five.
foreach($conn->query("SELECT u.username, u.is_active, GROUP_CONCAT(r.name) roles FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id AND r.is_active=1 WHERE u.username NOT LIKE 'uitest.%' AND u.is_active=1 GROUP BY u.id")->fetch_all(MYSQLI_ASSOC) as $acct){
    $check(in_array($acct['roles'], array_keys($definitions), true), "active account " . $acct['username'] . ' has exactly one of the five roles');
}
$row = $conn->query("SELECT is_active FROM users WHERE username='takashii001'")->fetch_row();
$check($row && (int)$row[0] === 0, 'takashii001 deactivated, kept');

// users.role is not an authorization source: flipping it changes nothing.
$id = (int)$conn->query("SELECT id FROM users WHERE username='uitest.acadfaculty'")->fetch_row()[0];
$conn->begin_transaction();
$conn->query("UPDATE users SET role='admin' WHERE id=$id");
$check(!user_can($id, 'rbac.manage') && !user_can($id, 'user.manage') && !user_can($id, 'audit.view') && user_can($id, 'curriculum.view'), 'users.role=admin grants nothing');
$conn->rollback();
$sa = (int)$conn->query("SELECT id FROM users WHERE username='uitest.superadmin'")->fetch_row()[0];
$conn->begin_transaction();
$conn->query("UPDATE users SET role='student' WHERE id=$sa");
$check(user_can($sa, 'rbac.manage'), 'users.role=student removes nothing');
$conn->rollback();

// Source: no access decision reads users.role.
$sources = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS));
foreach ($sources as $file) {
    $path = str_replace('\\', '/', (string)$file);
    if (!str_ends_with($path, '.php') || preg_match('#/(tests|tools|vendor|database/migrations|\.git)/#', $path)) continue;
    $code = (string)file_get_contents($path);
    $check(!preg_match("/\b(?:u\.|users\.)?role\s*=\s*'admin'|\\\$existing\['role'\]/", $code), 'no users.role decision in ' . $path);
}

// Students are not shown anywhere in CSMS.
foreach (['reports_tab/reports.php', 'home_tab/overview.php', 'shared/sidebar.php', 'shared/ui/sidebar.php', 'dashboard/dashboard.php', 'dashboard/dashboard_data.php'] as $page) {
    $check(!preg_match('/FROM students|student\.view/', (string)file_get_contents(__DIR__ . '/../' . $page)), "$page shows no students");
}

echo $failures ? "FAILED $failures of $checks\n" : "PASS $checks checks\n";
exit($failures ? 1 : 0);
