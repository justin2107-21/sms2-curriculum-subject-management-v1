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
    // A revised version with a recorded original: V3 (proposed) was created from V0 (reference), same curriculum.
    $db->query("UPDATE curriculum_versions SET version_role='reference' WHERE id=4");
    $db->query("UPDATE curriculum_versions SET version_role='proposed', baseline_version_id=4 WHERE id=3");
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
    // ---- Slice 2: version subjects listing and timeline ----
    $r = status($admin->api('subject_history', 'version_subjects', ['version_id' => 1]), 200, 'Version subjects');
    $byCode = []; foreach ($r['subjects'] as $s) $byCode[$s['subject_code']] = $s;
    ok(array_keys($byCode) === ['T1', 'T2'] || (count($byCode) === 2 && isset($byCode['T1'], $byCode['T2'])), 'Lists exactly the subjects placed in the selected version');
    ok((int)$r['pagination']['total_records'] === 2 && $r['version']['status'] === 'active', 'Paging total and version status');
    ok($byCode['T1']['version_count'] === 4 && $byCode['T2']['version_count'] === 1, 'Appears-in counts every version of the program (T1 in V1,V2,V3,V0 = 4; T2 = 1; other program excluded)');
    ok($byCode['T1']['snapshot_complete'] === 0 && $byCode['T1']['subject_title'] === 'Test 1', 'No snapshot: flagged and current values returned');
    ok($byCode['T1']['year_level'] === '1st Year' && $byCode['T1']['semester'] === '1st Semester', 'Year and semester returned');
    $r = status($admin->api('subject_history', 'version_subjects', ['version_id' => 2]), 200, 'Version with a snapshot');
    $snap = null; foreach ($r['subjects'] as $s) if ($s['subject_code'] === 'T1') $snap = $s;
    ok($snap && $snap['snapshot_complete'] === 1 && $snap['subject_title'] === 'Old Test 1 Title' && (float)$snap['units'] === 4.0, 'Complete snapshot supplies title and units');
    $r = status($admin->api('subject_history', 'version_subjects', ['version_id' => 1, 'search' => 'T2']), 200, 'Search');
    ok(count($r['subjects']) === 1 && $r['subjects'][0]['subject_code'] === 'T2', 'Search filters server-side');
    $r = status($admin->api('subject_history', 'version_subjects', ['version_id' => 1, 'per_page' => 10, 'page' => 2]), 200, 'Page beyond end');
    ok($r['subjects'] === [] || (int)$r['pagination']['page'] === 1, 'Paging is server-side and bounded');
    status($admin->api('subject_history', 'version_subjects', ['version_id' => 1, 'per_page' => 7]), 422, 'Invalid page size rejected');
    status($admin->api('subject_history', 'version_subjects', ['version_id' => 999]), 404, 'Unknown version');
    status($admin->api('subject_history', 'version_subjects', []), 422, 'Version required');
    $r = status($restricted->api('subject_history', 'version_subjects', ['version_id' => 1]), 200, 'Restricted reader, in-scope version');
    $t1 = null; foreach ($r['subjects'] as $s) if ($s['subject_code'] === 'T1') $t1 = $s;
    ok($t1 && $t1['version_count'] === 2, 'Restricted count excludes out-of-scope versions (V1 and V3 only)');
    $res = $restricted->api('subject_history', 'version_subjects', ['version_id' => 2]);
    ok(in_array($res['status'], [403, 404], true) && !str_contains($res['body'], 'Old Test 1 Title'), 'Restricted reader cannot list an out-of-scope version');

    $r = status($admin->api('subject_history', 'timeline', ['placement_id' => 1]), 200, 'Timeline');
    $vers = array_column($r['timeline'], 'version_label');
    ok(count($vers) === 4 && !in_array('O1', $vers, true), 'Timeline covers all four versions of the program and none of another program: ' . implode(',', $vers));
    ok($r['subject']['current_code'] === 'T1' && $r['program']['code'] === 'TP' && $r['selected_placement_id'] === 1, 'Timeline header facts');
    $by = []; foreach ($r['timeline'] as $row) $by[$row['version_label']] = $row;
    ok($by['V1']['effective_academic_year'] === '2024-2025' && $by['V2']['effective_academic_year'] === null, 'Effectivity is reported exactly as stored (null when not stored)');
    ok($by['V1']['version_status'] === 'active' && $by['V0']['version_status'] === 'archived' && $by['V2']['version_status'] === 'draft', 'Status per version');
    ok($by['V1']['is_selected'] === true && $by['V2']['is_selected'] === false, 'Selected placement marked');
    ok($by['V2']['snapshot_complete'] === 1 && $by['V2']['subject_title'] === 'Old Test 1 Title' && $by['V1']['snapshot_complete'] === 0 && $by['V1']['subject_title'] === null, 'Captured values only where a snapshot exists; none are filled from the Subject Master');
    ok(isset($by['V1']['placement_created_at'], $by['V1']['is_required'], $by['V1']['year_level'], $by['V1']['curriculum_code']), 'Timeline carries stored date, type, term and curriculum');
    $r = status($restricted->api('subject_history', 'timeline', ['placement_id' => 1]), 200, 'Restricted timeline');
    $vers = array_column($r['timeline'], 'version_label'); sort($vers);
    ok($vers === ['V1', 'V3'] && !str_contains(json_encode($r), 'Old Test 1 Title'), 'Restricted timeline lists only readable versions and leaks no snapshot');
    $res = $restricted->api('subject_history', 'timeline', ['placement_id' => 3]);
    ok(in_array($res['status'], [403, 404], true), 'Restricted reader cannot open a timeline from an out-of-scope placement');
    status($admin->api('subject_history', 'timeline', ['placement_id' => 999]), 404, 'Unknown placement');
    status($guest->api('subject_history', 'timeline', ['placement_id' => 1]), 401, 'Guest timeline');
    status($admin->api('subject_history', 'timeline', ['placement_id' => 1], 'POST', ''), 405, 'Timeline is GET-only');
    $page = $admin->request('modules/curriculum/pages/subject_history_view.php');
    ok(!str_contains($page['body'], 'Graduate Outcomes') && str_contains($page['body'], 'View in Subject Mapping') && str_contains($page['body'], 'Appears in'), 'Dialog: no Graduate Outcomes button, Subject Mapping link kept; Appears in column');

    // ---- Compare Curriculum Versions page (polish 2) ----
    $legacy = $admin->request('modules/curriculum/pages/curriculum_history_view.php', ['curriculum_id' => 1, 'version_b_id' => 3]);
    ok($legacy['status'] === 302, 'Builder-style link (curriculum_id + version_b_id) is redirected');
    $loc = ''; foreach ($admin->headers as $h) if (stripos($h, 'location:') === 0) $loc = trim(substr($h, 9));
    ok(str_contains($loc, 'program_id=1') && str_contains($loc, 'curriculum_id=1') && str_contains($loc, 'version_id=3') && !str_contains($loc, 'version_b_id'), 'Redirect carries program, curriculum and version for the context bar: ' . $loc);
    $page = $admin->request('modules/curriculum/pages/curriculum_history_view.php', ['program_id' => 1, 'curriculum_id' => 1, 'version_id' => 3, 'subject_id' => 1]);
    status($page, 200, 'Compare page renders');
    ok(!str_contains($page['body'], '<select') && str_contains($page['body'], 'id="versionSearch"') && str_contains($page['body'], 'subject-back-link') && !str_contains($page['body'], 'historyCimfButton'), 'Compare page: shared bar, no stacked selects, shared back link, no CIMF button');
    $r = status($admin->api('subject_history', 'context', ['program_id' => 1, 'curriculum_id' => 1, 'version_id' => 3]), 200, 'Context of the revised version');
    ok($r['record']['version_role'] === 'proposed' && (int)$r['record']['baseline_version_id'] === 4, 'Context exposes role and recorded original');
    $r = status($admin->api('subject_history', 'compare_revision', ['curriculum_id' => 1, 'version_b_id' => 3]), 200, 'compare_revision resolves the recorded original');
    ok((int)$r['version_a']['id'] === 4 && (int)$r['version_b']['id'] === 3 && isset($r['summary']['added']), 'Original resolved automatically; summary counters present');
    $res = $admin->api('subject_history', 'compare_revision', ['curriculum_id' => 1, 'version_b_id' => 1]);
    ok($res['status'] === 422, 'A version without a recorded original is refused by the endpoint (the page shows its own empty state)');
    $res = $restricted->api('subject_history', 'compare_revision', ['curriculum_id' => 1, 'version_b_id' => 3]);
    ok(in_array($res['status'], [403, 404], true), 'Restricted reader cannot compare against an out-of-scope original');
    $old = $admin->request('modules/curriculum/pages/subject_offering_history.php', ['view' => 'curriculum']);
    ok($old['status'] === 302, 'Legacy subject_offering_history.php address still redirects');

    // ---- Slice 3: stored lifecycle events on the timeline (read-only) ----
    $db->query("INSERT INTO curriculum_version_review_events(curriculum_version_id,actor_user_id,action,previous_status,new_status) VALUES (3,1,'submit','not_started','under_review'),(3,1,'approve','under_review','approved')");
    $r = status($admin->api('subject_history', 'timeline', ['placement_id' => 1]), 200, 'Timeline with lifecycle');
    $by = []; foreach ($r['timeline'] as $row) $by[$row['version_label']] = $row;
    ok(array_column($by['V3']['lifecycle'], 'action') === ['submit', 'approve'], 'Lifecycle: stored review events of V3 in order');
    ok($by['V3']['lifecycle'][1]['new_status'] === 'approved' && $by['V3']['lifecycle'][1]['actor_name'] !== null, 'Lifecycle carries status change and actor name');
    ok($by['V1']['lifecycle'] === [], 'A version without review events has an empty lifecycle');

    // ---- Slice 3: CIMF "Summarize History" (deterministic findings; the AI only explains them) ----
    require_once __DIR__ . '/../modules/curriculum/services/CIMFProfileRegistry.php';
    require_once __DIR__ . '/../modules/curriculum/services/CIMFConfig.php';
    require_once __DIR__ . '/../modules/curriculum/services/CIMFGlobalContextBuilder.php';
    $profile = CIMFProfileRegistry::profiles()['subject_history'];
    ok(array_keys($profile['actions'])[0] === 'summarize' && !in_array('create', array_keys($profile['actions']), true), 'Profile offers summarize and no write action');
    $before = fingerprint($db);
    $auditBefore = (int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0];
    $aiBefore = (int)$db->query('SELECT COUNT(*) FROM cimf_ai_logs')->fetch_row()[0];
    $input = ['subject_id' => 1, 'academic_program_id' => 1, 'curriculum_id' => 1, 'curriculum_version_id' => 3];
    // Deterministic numbers for T1 in program TP: V1, V2, V3, V0 (archived) = 4 appearances; 3 year/term moves; V1, V3, V0 have no snapshot; V2 has no effectivity.
    $built = (new CIMFGlobalContextBuilder($db))->build($profile, 'summarize', $input);
    $det = $built['deterministic'];
    $counts = []; foreach ($det['findings'] as $f) $counts[$f['key']] = $f['count'];
    ok($counts === ['appears' => 4, 'moved' => 3, 'type_changed' => 0, 'no_snapshot' => 3, 'no_effectivity' => 1], 'Findings are computed by PHP: ' . json_encode($counts));
    ok($det['version_count'] === 4 && str_contains($det['notice'], 'not class offerings'), 'Findings state the version count and the not-an-offering notice');
    ok(!str_contains(json_encode($built['facts']), 'offered in') && isset($built['facts']['findings'][1]['examples'][0]['code']), 'AI payload is compact: findings with examples, no full dump');
    ok(count($built['allowed']) === 1 && isset($built['allowed'][1]), 'Only the selected subject is allowed in the AI response');

    $page = $admin->request('modules/curriculum/pages/subject_history_view.php');
    status($page, 200, 'Page with CIMF panel');
    ok(str_contains($page['body'], 'historySummarizeButton') && preg_match('/data-page-token="([^"]+)"/', $page['body'], $tok) === 1, 'Summarize button and CIMF page token rendered');
    $token = html_entity_decode($tok[1], ENT_QUOTES);
    $cimf = static fn(FoundationHttpClient $c, string $op, string $action, array $context, string $pageToken): array => $c->request('modules/curriculum/http/global_cimf_actions.php', [
        'operation' => $op, 'route' => 'modules/curriculum/pages/subject_history_view.php', 'profile' => 'subject_history', 'action' => $action,
        'page_token' => $pageToken, 'context' => json_encode($context), 'question' => ''], 'POST');
    $r = $cimf($admin, 'context', 'summarize', $input, $token);
    status($r, 200, 'CIMF context loads for the summarize action');
    ok(!str_contains($r['body'], 'Fatal') && str_contains($r['body'], 'Subject History'), 'CIMF context is a read');
    status($cimf($admin, 'context', 'summarize', $input, 'not-a-token'), 422, 'CIMF refuses a forged page token');
    status($cimf($admin, 'context', 'create', $input, $token), 422, 'CIMF refuses a write action');
    status($cimf($admin, 'context', 'summarize', $input + ['curriculum_version_history' => 1], $token), 422, 'CIMF refuses unknown context fields');
    $guestCimf = $cimf($guest, 'context', 'summarize', $input, $token);
    ok(in_array($guestCimf['status'], [401, 403], true), 'Guest cannot use CIMF on this page');
    // AI is configured only through the environment. Calling the analyze operation with AI on would contact a provider,
    // so the HTTP analyze check runs only when AI is off; the findings path is covered above without any provider call.
    if (CIMFConfig::status(CIMFConfig::load()) !== 'SUCCESS') {
        $a = $cimf($admin, 'analyze', 'summarize', $input, $token);
        status($a, 200, 'AI off: deterministic findings are returned');
        ok(isset($a['json']['data']['deterministic']) || str_contains($a['body'], '"deterministic"'), 'AI off: findings are shown with a notice');
        ok(!str_contains($a['body'], '"analysis"'), 'AI off: no AI analysis is produced');
    } else {
        echo "NOTE: AI is configured in this environment; the HTTP analyze check was skipped so no provider is called.
";
    }
    ok(fingerprint($db) === $before, 'Summarize writes no curriculum, subject, program or snapshot rows');
    ok((int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0] === $auditBefore, 'Summarize writes no audit rows');
    ok((int)$db->query('SELECT COUNT(*) FROM cimf_ai_logs')->fetch_row()[0] === $aiBefore, 'Summarize writes no AI usage rows while AI is off');
    $js = file_get_contents(__DIR__ . '/../modules/curriculum/assets/offering-history.js');
    ok(!preg_match('/POST|method:/', $js), 'Offering History script issues no POST');

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
