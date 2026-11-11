<?php
// Subject Offering History: read scope, context endpoints, listing, timeline and "writes nothing".
// CLI only. Run with DB_NAME=sms_db_uitest (the structure source). It creates and drops its own disposable schema and
// loopback PHP server; neither sms_db nor sms_db_uitest rows are written.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/FoundationHttpClient.php';
$config = csms_database_config();
if (!in_array($config['host'], ['localhost', '127.0.0.1'], true) || getenv('APP_ENV') === 'production') throw new RuntimeException('Local development only.');
if ($config['database'] === 'sms_db') throw new RuntimeException('Never run against sms_db; use DB_NAME=sms_db_uitest.');
$db = csms_database_connect($config); $source = $config['database'];
if (!preg_match('/^[A-Za-z0-9_]+$/D', $source)) throw new RuntimeException('Unsafe source schema');
$name = 'csms_history_test_' . bin2hex(random_bytes(8)); $server = null; $clients = []; $checks = 0; $created = false;
$envBefore = getenv(); $log = tempnam(sys_get_temp_dir(), 'oh-http-'); $sessionDir = $log . '-sessions'; mkdir($sessionDir);
function ok(bool $cond, string $label): void { global $checks; if (!$cond) throw new RuntimeException('FAILED: ' . $label); $checks++; }
function status(array $r, int $expected, string $label): array {
    ok($r['status'] === $expected, $label . ' expected ' . $expected . ' received ' . $r['status'] . ' ' . substr($r['body'], 0, 200));
    ok(!preg_match('/Fatal error|Warning:|Stack trace|mysqli_sql_exception/', $r['body']), $label . ' safe response');
    return $r['json'] ?? [];
}
function fingerprint(mysqli $db): string {
    $out = [];
    foreach (['subjects', 'curricula', 'curriculum_versions', 'curriculum_version_subjects', 'curriculum_version_subject_snapshots', 'academic_programs', 'curriculum_subject_requirements', 'curriculum_elective_groups'] as $table) {
        $row = $db->query("CHECKSUM TABLE `$table`")->fetch_row(); $out[] = $table . ':' . $row[1];
    }
    return implode('|', $out);
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
    foreach (['hadmin', 'hrestricted', 'hfull'] as $i => $username) {
        $id = $i + 1; $email = $username . '@example.invalid';
        $stmt = $db->prepare("INSERT INTO users(id,username,email,first_name,last_name,password_hash,role) VALUES(?,?,?,'Test','Only',?,'student')");
        $stmt->bind_param('isss', $id, $username, $email, $hash); $stmt->execute();
    }
    $fixtureRole = 'super_admin';
    $stmt = $db->prepare('INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE name=?'); $stmt->bind_param('s', $fixtureRole); $stmt->execute(); $stmt->close();
    // Restricted reader: history permission only (no curriculum.view_unapproved). Full reader: the same plus view_unapproved.
    $db->query("INSERT INTO roles(name,display_name,description) VALUES('h_restricted','Restricted','Disposable test fixture')"); $restrictedRole = (int)$db->insert_id;
    $db->query("INSERT INTO roles(name,display_name,description) VALUES('h_full','Full','Disposable test fixture')"); $fullRole = (int)$db->insert_id;
    $db->query("INSERT INTO user_roles(user_id,role_id) VALUES(2,$restrictedRole),(3,$fullRole)");
    $db->query("INSERT INTO role_permissions(role_id,permission_id) SELECT $restrictedRole,id FROM permissions WHERE name IN ('subject_history.view','curriculum.view')");
    $db->query("INSERT INTO role_permissions(role_id,permission_id) SELECT $fullRole,id FROM permissions WHERE name IN ('subject_history.view','curriculum.view','curriculum.view_unapproved')");

    // Data. Program 1 > curriculum 1 with versions: 1 active, 2 draft/not approved (out of scope for restricted), 3 draft/approved, 4 archived.
    // Subject T1 is placed in versions 1, 2 and 3 (same code) and T2 only in version 1; T3 only in version 2.
    $db->query("INSERT INTO academic_programs(id,program_code,program_name) VALUES(1,'TP','Test Program'),(2,'OP','Other Program')");
    $db->query("INSERT INTO curricula(id,curriculum_code,curriculum_name,academic_program_id) VALUES(1,'TC','Test Curriculum',1),(2,'OC','Other Curriculum',2)");
    $db->query("INSERT INTO curriculum_versions(id,curriculum_id,version_label,effective_academic_year,status,review_status) VALUES
        (1,1,'V1','2024-2025','active','not_started'),(2,1,'V2',NULL,'draft','not_started'),(3,1,'V3','2026-2027','draft','approved'),(4,1,'V0','2020-2021','archived','not_started'),(5,2,'O1',NULL,'active','not_started')");
    foreach ([1 => ['T1', 'Test 1', 3], 2 => ['T2', 'Test 2', 3], 3 => ['T3', 'Test 3', 2]] as $id => [$code, $title, $units]) {
        $db->query("INSERT INTO subjects(id,subject_code,subject_title,units,lecture_units,laboratory_units) VALUES($id,'$code','$title',$units,$units,0)");
    }
    $place = [[1, 1, 1, '1st Year', '1st Semester'], [2, 1, 2, '1st Year', '2nd Semester'], [3, 2, 1, '2nd Year', '1st Semester'], [4, 2, 3, '1st Year', '1st Semester'],
              [5, 3, 1, '2nd Year', '2nd Semester'], [6, 4, 1, '1st Year', '1st Semester'], [7, 5, 1, '1st Year', '1st Semester']];
    foreach ($place as [$pid, $ver, $sub, $yr, $sem]) $db->query("INSERT INTO curriculum_version_subjects(id,curriculum_version_id,subject_id,year_level,semester) VALUES($pid,$ver,$sub,'$yr','$sem')");
    // One captured snapshot (placement 3, version 2, with a different title than the Subject Master) so Captured vs Current can be told apart.
    $db->query("INSERT INTO curriculum_version_subject_snapshots(curriculum_version_subject_id,subject_code_snapshot,subject_title_snapshot,units_snapshot,capture_method,snapshot_complete) VALUES(3,'T1','Old Test 1 Title',4,'clone',1)");

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr); $address = stream_socket_get_name($socket, false); fclose($socket);
    $env = $envBefore; $env['DB_NAME'] = $name; $env['APP_ENV'] = 'development'; $env['SMS_SESSION_COOKIE_SECURE'] = '0';
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessionDir, '-d', 'error_log=' . $log, '-S', $address, '-t', dirname(__DIR__)],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env, ['bypass_shell' => true]);
    if (!is_resource($server)) throw new RuntimeException('Test HTTP server failed'); fclose($pipes[0]);
    for ($i = 0; $i < 60; $i++) { $ready = @stream_socket_client('tcp://' . $address, $errno, $errstr, .1); if ($ready) { fclose($ready); break; } usleep(100000); }
    $admin = new FoundationHttpClient('http://' . $address); $restricted = new FoundationHttpClient('http://' . $address);
    $full = new FoundationHttpClient('http://' . $address); $guest = new FoundationHttpClient('http://' . $address);
    $clients = [$admin, $restricted, $full, $guest];
    foreach ([[$admin, 'hadmin'], [$restricted, 'hrestricted'], [$full, 'hfull']] as [$client, $username]) {
        $client->request('auth/signin.php');
        status($client->request('shared/auth_actions.php', ['action' => 'login', 'username' => $username, 'password' => $password], 'POST'), 200, 'Fixture login ' . $username);
    }
    $before = fingerprint($db);

    // ---- Slice 1: access, page, context bar, read scope ----
    status($guest->api('subject_history', 'list', []), 401, 'Guest list');
    status($admin->api('subject_history', 'list', [], 'POST', ''), 405, 'No POST on history endpoint');
    foreach (['hadmin' => $admin, 'hrestricted' => $restricted] as $user => $client) {
        $page = $client->request('modules/curriculum/pages/subject_history_view.php');
        status($page, 200, 'Page renders for ' . $user);
        ok(str_contains($page['body'], 'Subject Offering History'), 'Page title');
        ok(substr_count($page['body'], 'class="mapping-context-bar"') === 1 && str_contains($page['body'], 'id="programSearch"') && str_contains($page['body'], 'id="versionSearch"'), 'Shared context bar markup');
        ok(!str_contains($page['body'], 'Protected reference') && !str_contains($page['body'], 'recorded baseline'), 'No internal wording');
        ok(!str_contains($page['body'], 'role="tab"'), 'No tab bar');
    }
    $compare = $admin->request('modules/curriculum/pages/curriculum_history_view.php');
    status($compare, 200, 'Compare page still renders');
    ok(!str_contains($compare['body'], 'Protected reference') && !str_contains($compare['body'], 'recorded baseline'), 'Compare page has no internal wording');

    $allIds = fn(array $r) => array_map('intval', array_column($r['history'] ?? [], 'placement_id'));
    $x = $allIds(status($admin->api('subject_history', 'list', ['per_page' => 50]), 200, 'Super admin list')); sort($x);
    ok($x === [1, 2, 3, 4, 5, 6, 7], 'Super admin sees every version');
    $x = $allIds(status($full->api('subject_history', 'list', ['per_page' => 50]), 200, 'Full reader list')); sort($x);
    ok($x === [1, 2, 3, 4, 5, 6, 7], 'Reader with view_unapproved sees every version');
    $x = $allIds(status($restricted->api('subject_history', 'list', ['per_page' => 50]), 200, 'Restricted list')); sort($x);
    ok($x === [1, 2, 5, 7], 'Restricted reader sees only active or approved versions (placements 1,2,5,7); draft V2 (3,4) and archived V0 (6) are out of scope... got ' . implode(',', $x));
    $r = status($restricted->api('subject_history', 'list', ['per_page' => 50]), 200, 'Restricted summary');
    ok((int)$r['summary']['stored_placements'] === 4 && (int)$r['pagination']['total_records'] === 4, 'Restricted summary and paging count only scoped rows');
    foreach ([['list', ['curriculum_version_id' => 2]], ['details', ['placement_id' => 3]], ['compare_versions', ['curriculum_id' => 1, 'version_a_id' => 1, 'version_b_id' => 2]],
              ['compare_revision', ['curriculum_id' => 1, 'version_a_id' => 1, 'version_b_id' => 2]], ['context', ['program_id' => 1, 'curriculum_id' => 1, 'version_id' => 2]]] as [$action, $input]) {
        $res = $restricted->api('subject_history', $action, $input);
        ok(in_array($res['status'], [403, 404], true), 'Restricted reader blocked on out-of-scope ' . $action . ' (got ' . $res['status'] . ')');
        ok(!str_contains($res['body'], 'Old Test 1 Title') && !str_contains($res['body'], 'T3'), 'Out-of-scope ' . $action . ' leaks nothing');
    }
    status($full->api('subject_history', 'details', ['placement_id' => 3]), 200, 'Full reader opens out-of-scope placement');
    $r = status($restricted->api('subject_history', 'version_options', ['curriculum_id' => 1]), 200, 'Restricted version options');
    $labels = array_column($r['versions'], 'version_label'); sort($labels);
    ok($labels === ['V1', 'V3'], 'Version options hide out-of-scope versions: ' . implode(',', $labels));
    $r = status($restricted->api('subject_history', 'context_options', ['level' => 'version', 'program_id' => 1, 'curriculum_id' => 1]), 200, 'Restricted context versions');
    $labels = array_column($r['options'], 'code'); sort($labels);
    ok($labels === ['V1', 'V3'], 'Context bar hides out-of-scope versions: ' . implode(',', $labels));
    $r = status($full->api('subject_history', 'context_options', ['level' => 'version', 'program_id' => 1, 'curriculum_id' => 1]), 200, 'Full context versions');
    ok(count($r['options']) === 4, 'Full reader sees all four versions in the bar');
    $r = status($restricted->api('subject_history', 'context', ['program_id' => 1, 'curriculum_id' => 1, 'version_id' => 1]), 200, 'Context for in-scope version');
    ok($r['record']['version_label'] === 'V1' && $r['record']['version_status'] === 'active' && $r['record']['effective_academic_year'] === '2024-2025', 'Context record carries status and stored effectivity');
    status($restricted->api('subject_history', 'context', ['program_id' => 2, 'curriculum_id' => 1, 'version_id' => 1]), 404, 'Context rejects a wrong program/curriculum/version chain');
    $wm = $admin->request('modules/curriculum/http/working_context_actions.php', ['action' => 'read', 'module' => 'history', 'version_id' => 1, 'curriculum_id' => 1, 'program_id' => 1]);
    ok($wm['status'] === 200 && ($wm['json']['context']['version']['id'] ?? $wm['json']['context']['version_id'] ?? null) !== null, 'Working context key "history" resolves');
    status($guest->request('modules/curriculum/http/working_context_actions.php', ['action' => 'read', 'module' => 'history']), 401, 'Working context requires login');

    // ---- Slice 2 checks are appended below by the slice 2 change ----
    // @@SLICE2@@

    ok(fingerprint($db) === $before, 'No curriculum, subject or program table changed by any read');
} finally {
    foreach ($clients as $c) $c->close();
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($created && preg_match('/^csms_history_test_[a-f0-9]{16}$/D', $name)) $db->query("DROP DATABASE `$name`");
    $db->close();
    $logText = file_get_contents($log); unlink($log); foreach (glob($sessionDir . '/sess_*') as $file) unlink($file); rmdir($sessionDir);
}
ok(!preg_match('/PHP (Warning|Fatal|Notice|Parse error)/', $logText), 'No PHP errors in HTTP server log');
echo "PASS: $checks isolated HTTP checks for Subject Offering History; disposable server and schema removed.\n";
