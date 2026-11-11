<?php
// Placement edits across every lifecycle state, including archived. CLI only. Run with DB_NAME=sms_db_uitest (structure source);
// it creates and drops its own disposable schema and loopback server. Never run against sms_db.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/FoundationHttpClient.php';
$config = csms_database_config();
if (!in_array($config['host'], ['localhost', '127.0.0.1'], true) || getenv('APP_ENV') === 'production') throw new RuntimeException('Local development only.');
if ($config['database'] === 'sms_db') throw new RuntimeException('Never run against sms_db; use DB_NAME=sms_db_uitest.');
$db = csms_database_connect($config); $source = $config['database'];
if (!preg_match('/^[A-Za-z0-9_]+$/D', $source)) throw new RuntimeException('Unsafe source schema');
$name = 'csms_archived_test_' . bin2hex(random_bytes(8)); $server = null; $clients = []; $checks = 0; $created = false;
$envBefore = getenv(); $log = tempnam(sys_get_temp_dir(), 'av-http-'); $sessionDir = $log . '-sessions'; mkdir($sessionDir);
function ok(bool $cond, string $label): void { global $checks; if (!$cond) throw new RuntimeException('FAILED: ' . $label); $checks++; }
function check(array $r, int $expected, string $label): array {
    ok($r['status'] === $expected, $label . ' expected ' . $expected . ' got ' . $r['status'] . ' ' . substr($r['body'], 0, 160));
    return $r['json'] ?? [];
}
try {
    $db->query("CREATE DATABASE `$name`"); $created = true;
    foreach (array_column($db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM), 0) as $table) {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) throw new RuntimeException('Unsafe table name');
        $db->query("CREATE TABLE `$name`.`$table` LIKE `$source`.`$table`");
    }
    $db->select_db($name);
    foreach (['roles', 'permissions', 'role_permissions'] as $table) $db->query("INSERT INTO `$table` SELECT * FROM `$source`.`$table`");
    $password = bin2hex(random_bytes(24)); $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users(id,username,email,first_name,last_name,password_hash,role) VALUES(1,'vadmin','vadmin@example.invalid','Test','Only',?,'student')");
    $stmt->bind_param('s', $hash); $stmt->execute();
    $db->query("INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE name='super_admin'");
    $db->query("INSERT INTO academic_programs(id,program_code,program_name) VALUES(1,'TP','Test Program')");
    $db->query("INSERT INTO curricula(id,curriculum_code,curriculum_name,academic_program_id) VALUES(1,'TC','Test Curriculum',1)");
    // id: label, status, role, review, baseline
    $versions = [
        1 => ['draft unclassified', 'draft', 'unclassified', 'not_started', null],
        2 => ['active unclassified', 'active', 'unclassified', 'not_started', null],
        3 => ['reference', 'active', 'reference', 'not_started', null],
        4 => ['proposed not_started', 'draft', 'proposed', 'not_started', 3],
        5 => ['proposed under_review', 'draft', 'proposed', 'under_review', 3],
        6 => ['proposed approved', 'draft', 'proposed', 'approved', 3],
        7 => ['archived unclassified', 'archived', 'unclassified', 'not_started', null],
        8 => ['archived proposed', 'archived', 'proposed', 'not_started', 3],
    ];
    foreach ($versions as $id => [$label, $st, $role, $review, $base]) {
        $b = $base === null ? 'NULL' : (string)$base;
        $db->query("INSERT INTO curriculum_versions(id,curriculum_id,version_label,status,version_role,review_status,baseline_version_id) VALUES($id,1,'$label','$st','$role','$review',$b)");
    }
    foreach (range(1, 12) as $s) $db->query("INSERT INTO subjects(id,subject_code,subject_title,units) VALUES($s,'S$s','Subject $s',3)");
    // One existing placement (subject 1) in every version, so update and remove have a target. Placement id = version id.
    foreach (array_keys($versions) as $id) $db->query("INSERT INTO curriculum_version_subjects(id,curriculum_version_id,subject_id,year_level,semester,is_required) VALUES($id,$id,1,'1st Year','1st Semester',1)");

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr); $address = stream_socket_get_name($socket, false); fclose($socket);
    $env = $envBefore; $env['DB_NAME'] = $name; $env['APP_ENV'] = 'development'; $env['SMS_SESSION_COOKIE_SECURE'] = '0';
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessionDir, '-d', 'error_log=' . $log, '-S', $address, '-t', dirname(__DIR__)],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env, ['bypass_shell' => true]);
    if (!is_resource($server)) throw new RuntimeException('Test HTTP server failed'); fclose($pipes[0]);
    for ($i = 0; $i < 60; $i++) { $ready = @stream_socket_client('tcp://' . $address, $errno, $errstr, .1); if ($ready) { fclose($ready); break; } usleep(100000); }
    $admin = new FoundationHttpClient('http://' . $address); $clients = [$admin];
    $admin->request('auth/signin.php');
    check($admin->request('shared/auth_actions.php', ['action' => 'login', 'username' => 'vadmin', 'password' => $password], 'POST'), 200, 'Login');
    $admin->request('modules/curriculum/pages/subject_mapping.php'); // picks up the CSRF token
    $api = fn(string $action, array $data) => $admin->api('assignment', $action, $data, 'POST');
    $fingerprint = fn() => implode('|', array_map(fn($t) => $db->query("CHECKSUM TABLE `$t`")->fetch_row()[1], ['curriculum_version_subjects', 'curriculum_versions', 'curriculum_subject_requirements', 'curriculum_elective_groups']));
    $auditCount = fn() => (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'CURRICULUM_SUBJECT_%'")->fetch_row()[0];

    // Editable states: draft unclassified, active unclassified, proposed not_started. Next free subject per version.
    foreach ([1, 2, 4] as $v) {
        $label = $versions[$v][0];
        check($api('assign', ['curriculum_version_id' => $v, 'subject_id' => 2, 'year_level' => '1st Year', 'semester' => '2nd Semester']), 201, "$label: assign allowed");
        check($api('update', ['id' => $v, 'year_level' => '2nd Year', 'semester' => '1st Semester']), 200, "$label: update allowed");
        $newId = (int)$db->query("SELECT id FROM curriculum_version_subjects WHERE curriculum_version_id=$v AND subject_id=2")->fetch_row()[0];
        check($api('remove', ['id' => $newId]), 200, "$label: remove allowed");
    }
    // Blocked states.
    foreach ([3, 5, 6, 7, 8] as $v) {
        $label = $versions[$v][0]; $before = $fingerprint(); $audits = $auditCount();
        $r1 = check($api('assign', ['curriculum_version_id' => $v, 'subject_id' => 3, 'year_level' => '1st Year', 'semester' => '1st Semester']), 409, "$label: assign blocked");
        $r2 = check($api('update', ['id' => $v, 'year_level' => '3rd Year', 'semester' => '1st Semester']), 409, "$label: update blocked");
        $r3 = check($api('remove', ['id' => $v]), 409, "$label: remove blocked");
        if ($versions[$v][1] === 'archived') {
            foreach ([$r1, $r2, $r3] as $r) ok(str_contains($r['message'] ?? '', 'archived and cannot be changed'), "$label: clear archived message");
        }
        ok($fingerprint() === $before, "$label: no row changed");
        ok($auditCount() === $audits, "$label: no placement audit written");
    }
    // Reads of an archived version keep working (history stays visible).
    check($admin->api('assignment', 'list', ['curriculum_version_id' => 7]), 200, 'Archived version placements can still be read');
    $mm = $admin->api('subject_history', 'version_subjects', ['version_id' => 7]); check($mm, 200, 'Archived version stays visible in Subject Offering History');
    // Pure policy check: editable flag.
    require_once __DIR__ . '/../modules/curriculum/services/CurriculumVersionEditPolicy.php';
    ok(CurriculumVersionEditPolicy::isEditable(['status' => 'archived', 'version_role' => 'unclassified', 'review_status' => 'not_started', 'baseline_version_id' => null]) === false, 'isEditable false for archived');
    ok(CurriculumVersionEditPolicy::isEditable(['status' => 'draft', 'version_role' => 'unclassified', 'review_status' => 'not_started', 'baseline_version_id' => null]) === true, 'isEditable true for draft unclassified');
    ok(CurriculumVersionEditPolicy::isEditable(['version_role' => 'unclassified', 'review_status' => 'not_started', 'baseline_version_id' => null]) === true, 'Rows without a status key are unaffected');
} finally {
    foreach ($clients as $c) $c->close();
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($created && preg_match('/^csms_archived_test_[a-f0-9]{16}$/D', $name)) $db->query("DROP DATABASE `$name`");
    $db->close();
    $logText = file_get_contents($log); unlink($log); foreach (glob($sessionDir . '/sess_*') as $file) unlink($file); rmdir($sessionDir);
}
ok(!preg_match('/PHP (Warning|Fatal|Notice|Parse error)/', $logText), 'No PHP errors in HTTP server log');
echo "PASS: $checks archived-version protection checks; disposable server and schema removed.\n";
