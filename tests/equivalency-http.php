<?php
// Subject Equivalency Tool, slice 1: permissions, CSRF, required reason, canonical pair, duplicate message, archive / restore,
// audit rows, read scope on usage, redirects and the page. CLI only. Run with DB_NAME=sms_db_uitest as the structure source:
// the test creates and drops its own disposable schema and loopback PHP server; no row is written to sms_db or sms_db_uitest.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/FoundationHttpClient.php';
$config = csms_database_config();
if (!in_array($config['host'], ['localhost', '127.0.0.1'], true) || getenv('APP_ENV') === 'production') throw new RuntimeException('Local development only.');
if ($config['database'] === 'sms_db') throw new RuntimeException('Never run against sms_db; use DB_NAME=sms_db_uitest.');
$db = csms_database_connect($config); $source = $config['database'];
if (!preg_match('/^[A-Za-z0-9_]+$/D', $source)) throw new RuntimeException('Unsafe source schema');
$name = 'csms_equiv_test_' . bin2hex(random_bytes(8)); $server = null; $clients = []; $checks = 0; $created = false;
$envBefore = getenv(); $log = tempnam(sys_get_temp_dir(), 'eq-http-'); $sessionDir = $log . '-sessions'; mkdir($sessionDir);
function ok(bool $cond, string $label): void { global $checks; if (!$cond) throw new RuntimeException('FAILED: ' . $label); $checks++; }
$reason = 'Same outcomes and units; reviewed against both syllabi.';
try {
    $db->query("CREATE DATABASE `$name`"); $created = true;
    foreach (array_column($db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM), 0) as $table) {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) throw new RuntimeException('Unsafe table name');
        $db->query("CREATE TABLE `$name`.`$table` LIKE `$source`.`$table`");
    }
    $db->select_db($name);
    foreach (['roles', 'permissions', 'role_permissions'] as $table) $db->query("INSERT INTO `$table` SELECT * FROM `$source`.`$table`");
    $loginPassword = bin2hex(random_bytes(24)); $hash = password_hash($loginPassword, PASSWORD_DEFAULT);
    foreach (['qmanager', 'qviewer', 'qfaculty'] as $i => $username) {
        $id = $i + 1; $email = $username . '@example.invalid';
        $stmt = $db->prepare("INSERT INTO users(id,username,email,first_name,last_name,password_hash,role) VALUES(?,?,?,'Test','Only',?,'student')");
        $stmt->bind_param('isss', $id, $username, $email, $hash); $stmt->execute();
    }
    // Manager: view + manage + unapproved read. Viewer: view only (restricted read scope). Faculty: nothing for this module.
    $role = function (string $roleName, array $perms) use ($db): void {
        $db->query("INSERT INTO roles(name,display_name,description) VALUES('$roleName','$roleName','Disposable test fixture')"); $rid = (int)$db->insert_id;
        $list = "'" . implode("','", $perms) . "'";
        $db->query("INSERT INTO role_permissions(role_id,permission_id) SELECT $rid,id FROM permissions WHERE name IN ($list)");
        return;
    };
    $role('q_manager', ['equivalency.view', 'equivalency.manage', 'curriculum.view', 'curriculum.view_unapproved', 'subject.view']);
    $role('q_viewer', ['equivalency.view', 'curriculum.view', 'subject.view']);
    $role('q_faculty', ['curriculum.view', 'subject.view']);
    $db->query("INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE name='q_manager'");
    $db->query("INSERT INTO user_roles(user_id,role_id) SELECT 2,id FROM roles WHERE name='q_viewer'");
    $db->query("INSERT INTO user_roles(user_id,role_id) SELECT 3,id FROM roles WHERE name='q_faculty'");

    $db->query("INSERT INTO academic_programs(id,program_code,program_name) VALUES(1,'QP','Equivalency Program')");
    $db->query("INSERT INTO curricula(id,curriculum_code,curriculum_name,academic_program_id) VALUES(1,'QC','Equivalency Curriculum',1)");
    $db->query("INSERT INTO curriculum_versions(id,curriculum_id,version_label,effective_academic_year,status,review_status) VALUES(1,1,'APPROVED1','2026-2027','active','approved'),(2,1,'DRAFT2','2027-2028','draft','not_started')");
    foreach ([1 => ['Q101', 'Computing Basics', 3], 2 => ['Q102', 'Computing Fundamentals', 3], 3 => ['Q201', 'Draft Prereq', 3], 4 => ['Q202', 'Approved Prereq', 2]] as $id => [$code, $title, $units])
        $db->query("INSERT INTO subjects(id,subject_code,subject_title,units) VALUES($id,'$code','$title',$units)");
    // Q101 is placed in the approved and the draft version; Q102 only in the approved one.
    foreach ([[1, 1, 1], [2, 2, 1], [3, 4, 1], [4, 1, 2], [5, 3, 2]] as [$pid, $sid, $vid])
        $db->query("INSERT INTO curriculum_version_subjects(id,curriculum_version_id,subject_id,year_level,semester) VALUES($pid,$vid,$sid,'1st Year','1st Semester')");
    $db->query("INSERT INTO curriculum_subject_requirements(curriculum_version_id,subject_id,required_subject_id,requirement_type) VALUES(1,1,4,'prerequisite'),(2,1,3,'prerequisite')");
    // Slice 1b fixture: Q101 requires Q102 (corequisite, approved v1); in the draft v2 Q201 requires Q202 (Q202 is also placed in v2).
    $db->query("INSERT INTO curriculum_version_subjects(id,curriculum_version_id,subject_id,year_level,semester) VALUES(6,2,4,'1st Year','1st Semester')");
    $db->query("INSERT INTO curriculum_subject_requirements(curriculum_version_id,subject_id,required_subject_id,requirement_type) VALUES(1,1,2,'corequisite'),(2,3,4,'prerequisite')");
    // Program 2 has only a draft version that places Q201; program 3 actively assigns Q202 (assignments are program-level, not version data).
    $db->query("INSERT INTO academic_programs(id,program_code,program_name) VALUES(2,'QP2','Draft-only Program'),(3,'QP3','Assignment Program')");
    $db->query("INSERT INTO curricula(id,curriculum_code,curriculum_name,academic_program_id) VALUES(2,'QC2','Draft-only Curriculum',2)");
    $db->query("INSERT INTO curriculum_versions(id,curriculum_id,version_label,status,review_status) VALUES(3,2,'DRAFT3','draft','not_started')");
    $db->query("INSERT INTO curriculum_version_subjects(id,curriculum_version_id,subject_id,year_level,semester) VALUES(7,3,3,'1st Year','1st Semester')");
    $db->query("INSERT INTO program_subject_assignments(academic_program_id,subject_id,status) VALUES(3,4,'active')");

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr); $address = stream_socket_get_name($socket, false); fclose($socket);
    $env = $envBefore; $env['DB_NAME'] = $name; $env['APP_ENV'] = 'development'; $env['SMS_SESSION_COOKIE_SECURE'] = '0'; unset($env['CSMS_FACULTY_READ_SCOPE']);
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessionDir, '-d', 'error_log=' . $log, '-S', $address, '-t', dirname(__DIR__)],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env, ['bypass_shell' => true]);
    if (!is_resource($server)) throw new RuntimeException('Test HTTP server failed'); fclose($pipes[0]);
    for ($i = 0; $i < 60; $i++) { $ready = @stream_socket_client('tcp://' . $address, $errno, $errstr, .1); if ($ready) { fclose($ready); break; } usleep(100000); }
    $manager = new FoundationHttpClient('http://' . $address); $viewer = new FoundationHttpClient('http://' . $address);
    $faculty = new FoundationHttpClient('http://' . $address); $guest = new FoundationHttpClient('http://' . $address);
    $clients = [$manager, $viewer, $faculty, $guest];
    foreach ([[$manager, 'qmanager'], [$viewer, 'qviewer'], [$faculty, 'qfaculty']] as [$client, $username]) {
        $client->request('auth/signin.php');
        $r = $client->request('shared/auth_actions.php', ['action' => 'login', 'username' => $username, 'password' => $loginPassword], 'POST');
        ok($r['status'] === 200, 'Fixture login ' . $username);
    }
    $audit = fn(string $action): int => (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='$action'")->fetch_row()[0];
    $lastMeta = fn(string $action): array => json_decode((string)$db->query("SELECT metadata_json FROM audit_logs WHERE action='$action' ORDER BY id DESC LIMIT 1")->fetch_row()[0], true) ?: [];

    // ---- Page, redirects, permissions ----
    $page = $manager->request('modules/curriculum/pages/subject_equivalency.php');
    ok($page['status'] === 200 && str_contains($page['body'], 'Subject Equivalency Tool') && str_contains($page['body'], 'id="recordDecisionButton"'), 'Manager: one page with the Record decision button');
    ok(!str_contains($page['body'], 'csms-segmented') && !str_contains($page['body'], 'mapping-context-bar') && !preg_match('/equivalency-(selector|summary|usage)-card|existing-record|mapping-fixed-context/', $page['body']), 'Page: no tabs, no Program > Curriculum > Version bar, no old page-specific blocks');
    ok(str_contains($page['body'], 'Advisory record. A decision never changes placements or credits automatically.') && str_contains($page['body'], 'csms-empty') && str_contains($page['body'], 'csms-filter-row') && str_contains($page['body'], 'class="csms-ctable"'), 'Page: csms-info text, csms-empty, filter row and csms-ctable');
    foreach (['subject_comparison.php', 'equivalency_records.php'] as $old) {
        $r = $manager->request('modules/curriculum/pages/' . $old, ['subject_a_id' => 1, 'view' => 'records']);
        ok($r['status'] === 302 && preg_grep('/^Location: subject_equivalency\.php\?subject_a_id=1&view=records/i', $manager->headers), "$old redirects (302) with the query string");
    }
    $vpage = $viewer->request('modules/curriculum/pages/subject_equivalency.php');
    ok($vpage['status'] === 200 && !str_contains($vpage['body'], 'id="recordDecisionButton"') && !str_contains($vpage['body'], 'id="equivalencySave"') && str_contains($vpage['body'], 'Read-only for your role'), 'Viewer: page without manage controls, read-only chip');
    ok($faculty->request('modules/curriculum/pages/subject_equivalency.php')['status'] === 403, 'No equivalency.view: page refused');
    ok($faculty->api('equivalency', 'list')['status'] === 403, 'No equivalency.view: list refused');
    ok(in_array($guest->api('equivalency', 'list')['status'], [401, 403], true), 'Guest refused');

    // ---- Required reason, CSRF, permissions on writes ----
    $pair = ['subject_a_id' => 2, 'subject_b_id' => 1, 'decision_status' => 'equivalent'];
    foreach (['' => 'at least 10', '   short   ' => 'at least 10', str_repeat('x', 4001) => 'at most 4000'] as $bad => $expected) {
        $r = $manager->api('equivalency', 'create', $pair + ['remarks' => $bad], 'POST');
        ok($r['status'] === 422 && str_contains($r['json']['message'] ?? '', $expected), 'Create rejected without a valid reason: ' . ($r['json']['message'] ?? $r['status']));
    }
    ok($audit('SUBJECT_EQUIVALENCY_CREATE') === 0, 'Rejected creates write no audit row');
    ok($manager->api('equivalency', 'create', $pair + ['remarks' => $reason], 'POST', '')['status'] === 403, 'Create needs CSRF');
    ok($viewer->api('equivalency', 'create', $pair + ['remarks' => $reason], 'POST')['status'] === 403, 'Viewer cannot create');
    ok($manager->request('modules/curriculum/http/equivalency_actions.php', ['action' => 'create'] + $pair + ['remarks' => $reason])['status'] === 405, 'Create by GET refused');

    // ---- Create: canonical pair, audit ----
    $r = $manager->api('equivalency', 'create', $pair + ['remarks' => '  ' . $reason . '  '], 'POST');
    ok($r['status'] === 201, 'Manager creates with a reason');
    $rec = $r['json']['equivalency'];
    ok((int)$rec['subject_id'] === 1 && (int)$rec['equivalent_subject_id'] === 2 && $rec['remarks'] === $reason, 'Stored as canonical pair (lower id first), reason trimmed');
    ok($audit('SUBJECT_EQUIVALENCY_CREATE') === 1, 'Create audited');
    $id = (int)$rec['id'];
    $r = $manager->api('equivalency', 'create', ['subject_a_id' => 1, 'subject_b_id' => 2, 'decision_status' => 'not_equivalent', 'remarks' => $reason], 'POST');
    ok($r['status'] === 409 && str_contains($r['json']['message'] ?? '', 'already exists') && str_contains($r['json']['message'] ?? '', 'Edit that record'), 'Duplicate pair (either order) refused with a clear message');

    // ---- Update: reason required, audit with old/new decision and remarks_changed (no text) ----
    $r = $manager->api('equivalency', 'update', ['id' => $id, 'decision_status' => 'needs_review', 'remarks' => 'too short'], 'POST');
    ok($r['status'] === 422, 'Update without a 10-character reason refused');
    ok($manager->api('equivalency', 'update', ['id' => $id, 'decision_status' => 'needs_review', 'remarks' => $reason], 'POST', '')['status'] === 403, 'Update needs CSRF');
    ok($viewer->api('equivalency', 'update', ['id' => $id, 'decision_status' => 'needs_review', 'remarks' => $reason], 'POST')['status'] === 403, 'Viewer cannot update');
    $r = $manager->api('equivalency', 'update', ['id' => $id, 'decision_status' => 'needs_review', 'remarks' => $reason], 'POST');
    $meta = $lastMeta('SUBJECT_EQUIVALENCY_UPDATE');
    ok($r['status'] === 200 && $meta['old_decision_status'] === 'equivalent' && $meta['decision_status'] === 'needs_review' && $meta['remarks_changed'] === false, 'Update audit: old and new decision, remarks unchanged');
    $r = $manager->api('equivalency', 'update', ['id' => $id, 'decision_status' => 'needs_review', 'remarks' => $reason . ' Updated.'], 'POST');
    $meta = $lastMeta('SUBJECT_EQUIVALENCY_UPDATE');
    ok($r['status'] === 200 && $meta['remarks_changed'] === true, 'Update audit flags a changed reason');
    ok(!str_contains((string)$db->query("SELECT GROUP_CONCAT(metadata_json) FROM audit_logs WHERE action LIKE 'SUBJECT_EQUIVALENCY%'")->fetch_row()[0], 'syllabi'), 'Audit rows never contain the reason text');
    // An old row without a reason stays readable; editing it needs a reason.
    $db->query("INSERT INTO subject_equivalencies(subject_id,equivalent_subject_id,decision_status,remarks,reviewed_by,reviewed_at,created_by) VALUES(3,4,'not_equivalent',NULL,1,NOW(),1)");
    $legacy = (int)$db->insert_id;
    $r = $manager->api('equivalency', 'get', ['id' => $legacy]);
    ok($r['status'] === 200 && $r['json']['equivalency']['remarks'] === null, 'A legacy record without a reason is readable');
    ok($manager->api('equivalency', 'update', ['id' => $legacy, 'decision_status' => 'not_equivalent', 'remarks' => ''], 'POST')['status'] === 422, 'Editing a legacy record requires a reason');

    // ---- Archive, restore ----
    ok($manager->api('equivalency', 'archive', ['id' => $id], 'POST', '')['status'] === 403, 'Archive needs CSRF');
    ok($viewer->api('equivalency', 'archive', ['id' => $id], 'POST')['status'] === 403, 'Viewer cannot archive');
    ok($manager->api('equivalency', 'archive', ['id' => $id], 'POST')['status'] === 200 && $audit('SUBJECT_EQUIVALENCY_ARCHIVE') === 1, 'Archive works and is audited');
    $active = $manager->api('equivalency', 'list')['json']['equivalencies'] ?? [];
    $archived = $manager->api('equivalency', 'list', ['status' => 'archived'])['json']['equivalencies'] ?? [];
    ok(!in_array($id, array_map('intval', array_column($active, 'id')), true) && in_array($id, array_map('intval', array_column($archived, 'id')), true), 'Archived filter lists the archived record; the default list does not');
    $r = $manager->api('equivalency', 'create', $pair + ['remarks' => $reason], 'POST');
    ok($r['status'] === 409 && str_contains($r['json']['message'] ?? '', 'Restore it'), 'Creating an archived pair points to Restore');
    ok($manager->api('equivalency', 'update', ['id' => $id, 'decision_status' => 'equivalent', 'remarks' => $reason], 'POST')['status'] === 409, 'An archived record cannot be edited');
    ok($manager->api('equivalency', 'restore', ['id' => $id], 'POST', '')['status'] === 403, 'Restore needs CSRF');
    ok($viewer->api('equivalency', 'restore', ['id' => $id], 'POST')['status'] === 403, 'Viewer cannot restore');
    ok($manager->request('modules/curriculum/http/equivalency_actions.php', ['action' => 'restore', 'id' => $id])['status'] === 405, 'Restore by GET refused');
    $r = $manager->api('equivalency', 'restore', ['id' => $id], 'POST');
    ok($r['status'] === 200 && $r['json']['equivalency']['archived_at'] === null && $audit('SUBJECT_EQUIVALENCY_RESTORE') === 1, 'Restore clears archived_at and is audited');
    ok((int)$db->query("SELECT archived_by IS NULL FROM subject_equivalencies WHERE id=$id")->fetch_row()[0] === 1, 'Restore clears archived_by');
    ok($manager->api('equivalency', 'restore', ['id' => $id], 'POST')['status'] === 409, 'Restoring an active record is refused');

    // ---- Read scope on compare / usage / prerequisites ----
    $cmp = $manager->api('equivalency', 'compare', ['subject_a_id' => 1, 'subject_b_id' => 2]);
    $u = $cmp['json']['usage']['subject_a']['summary'];
    ok($cmp['status'] === 200 && (int)$u['placements'] === 2 && (int)$u['hidden_placements'] === 0 && $cmp['json']['scope_restricted'] === false, 'Manager sees both placements of Q101 (approved and draft)');
    ok($cmp['json']['prerequisites']['subject_a']['total'] === 3, 'Manager sees prerequisites from both versions (and the corequisite link)');
    ok(count($cmp['json']['differences']) === 7, 'Compare returns all seven stored fields');
    $cmp = $viewer->api('equivalency', 'compare', ['subject_a_id' => 1, 'subject_b_id' => 2]);
    $u = $cmp['json']['usage']['subject_a']['summary'];
    ok($cmp['status'] === 200 && (int)$u['placements'] === 1 && (int)$u['curriculum_versions'] === 1 && (int)$u['hidden_placements'] === 1 && $cmp['json']['scope_restricted'] === true, 'Restricted viewer: draft placement hidden and counted');
    ok(!str_contains($cmp['body'], 'DRAFT2') && !str_contains($cmp['body'], 'Draft Prereq'), 'Restricted viewer: no draft version label or draft-only prerequisite in the response');
    ok($cmp['json']['prerequisites']['subject_a']['total'] === 2 && $cmp['json']['prerequisites']['subject_a']['rows'][0]['subject_code'] === 'Q102', 'Restricted viewer: prerequisites from the approved version only (draft-only Q201 left out)');
    ok($faculty->api('equivalency', 'compare', ['subject_a_id' => 1, 'subject_b_id' => 2])['status'] === 403, 'No equivalency.view: compare refused');

    // ---- Slice 1b: server-side filters, sort, page size, dates, program scope, picker, filter options ----
    // Records now: (1,2) active by user 1 with a reason; (3,4) legacy not_equivalent by user 1 without a reason; add (1,3) needs_review by user 2, no reason, 2025-01-15; (2,4) archived equivalent.
    $db->query("INSERT INTO subject_equivalencies(subject_id,equivalent_subject_id,decision_status,remarks,reviewed_by,reviewed_at,created_by) VALUES(1,3,'needs_review',NULL,2,'2025-01-15 12:00:00',2)");
    $db->query("INSERT INTO subject_equivalencies(subject_id,equivalent_subject_id,decision_status,remarks,reviewed_by,reviewed_at,created_by,archived_at,archived_by) VALUES(2,4,'equivalent','Archived test pair, reviewed long ago.',1,'2025-03-01 09:00:00',1,NOW(),1)");
    $list = fn(FoundationHttpClient $c, array $args = []) => $c->api('equivalency', 'list', $args);
    $codes = fn(array $r): array => array_map(fn($x) => $x['subject_a_code'] . '/' . $x['subject_b_code'], $r['json']['equivalencies'] ?? []);
    $total = fn(array $r): int => (int)($r['json']['pagination']['total_records'] ?? -1);
    ok($total($list($manager)) === 3 && $total($list($manager, ['status' => 'archived'])) === 1 && $total($list($manager, ['status' => 'all'])) === 4, 'Status filter: active (default) 3, archived 1, all 4');
    ok($list($manager, ['status' => 'bogus'])['status'] === 422, 'Invalid status rejected');
    ok($total($list($manager, ['decision_status' => 'needs_review'])) === 2 && $list($manager, ['decision_status' => 'pending'])['status'] === 422, 'Decision filter works; unknown decision rejected');
    ok($total($list($manager, ['search' => 'Q101'])) === 2 && $total($list($manager, ['search' => 'draft prereq'])) === 2, 'Search matches the code or title of EITHER subject (case-insensitive)');
    ok($total($list($manager, ['search' => '%%'])) === 0 && $list($manager, ['search' => 'q'])['status'] === 422, 'Search treats wildcards literally; one character is rejected (minimum 2)');
    ok($total($list($manager, ['reason' => 'has'])) === 1 && $total($list($manager, ['reason' => 'missing'])) === 2 && $list($manager, ['reason' => 'maybe'])['status'] === 422, 'Reason filter: has 1, missing 2; invalid value rejected');
    ok($total($list($manager, ['reviewed_by' => 2])) === 1 && $list($manager, ['reviewed_by' => 'x'])['status'] === 422, 'Reviewed by filter works; invalid id rejected');
    ok($total($list($manager, ['reviewed_from' => '2025-01-01', 'reviewed_to' => '2025-01-31'])) === 1 && $total($list($manager, ['reviewed_from' => '2026-01-01'])) === 2, 'Reviewed date range (inclusive end day) works');
    foreach ([['reviewed_from' => '2025-02-30'], ['reviewed_to' => 'yesterday'], ['reviewed_from' => '2026-02-01', 'reviewed_to' => '2026-01-01']] as $bad)
        ok($list($manager, $bad)['status'] === 422, 'Invalid dates rejected: ' . json_encode($bad));
    ok($total($list($manager, ['decision_status' => 'needs_review', 'status' => 'all', 'reason' => 'has'])) === 1, 'Filters combine (AND)');
    // Program filter and read scope
    ok($total($list($manager, ['academic_program_id' => 1])) === 3 && $total($list($manager, ['academic_program_id' => 2])) === 2, 'Program filter: placements in the program (manager sees the draft-only program 2: records with Q201)');
    ok($total($list($viewer, ['academic_program_id' => 2])) === 0 && $total($list($viewer, ['academic_program_id' => 1])) === 3, 'Program filter respects read scope: a restricted role does not match draft-only placements');
    ok($total($list($manager, ['academic_program_id' => 3])) === 1 && $total($list($viewer, ['academic_program_id' => 3])) === 1, 'Program filter also matches an active program assignment');
    ok($list($manager, ['academic_program_id' => 0])['status'] === 422, 'Invalid program id rejected');
    // Sort whitelist, direction, stable order, page size cap
    ok($list($manager, ['sort' => 'remarks'])['status'] === 422 && $list($manager, ['sort' => 'id; DROP TABLE users'])['status'] === 422 && $list($manager, ['direction' => 'sideways'])['status'] === 422, 'Sort: unknown column or direction rejected');
    $asc = $codes($list($manager, ['sort' => 'subject_a', 'direction' => 'asc'])); $desc = $codes($list($manager, ['sort' => 'subject_a', 'direction' => 'desc']));
    ok($asc === array_reverse($desc) && $asc === ['Q101/Q102', 'Q101/Q201', 'Q201/Q202'], 'Sort by Subject A works in both directions (stable tiebreak on id)');
    foreach (['subject_b', 'decision', 'reviewed_at'] as $col) ok($list($manager, ['sort' => $col, 'direction' => 'asc'])['status'] === 200, "Sort by $col accepted");
    $default = $codes($list($manager));
    ok($default[count($default) - 1] === 'Q101/Q201', 'Default order is Reviewed at, newest first (the 2025 record is last)');
    ok($list($manager, ['per_page' => 100])['status'] === 200 && $list($manager, ['per_page' => 101])['status'] === 422 && $list($manager, ['per_page' => 5])['status'] === 422, 'Page size: 100 allowed, 101 and 5 rejected');
    $p2 = $list($manager, ['per_page' => 10, 'page' => 99]);
    ok($p2['status'] === 200 && (int)$p2['json']['pagination']['page'] === 1, 'A page past the end falls back to the last page');
    // Picker search and filter options
    $s = $manager->api('equivalency', 'subject_search', ['search' => 'Q1']);
    ok($s['status'] === 200 && count($s['json']['subjects']) === 2 && $s['json']['needs_more_characters'] === false, 'Subject picker: server-side search returns matches');
    $s = $manager->api('equivalency', 'subject_search', ['search' => 'Q']);
    ok($s['status'] === 200 && $s['json']['subjects'] === [] && $s['json']['needs_more_characters'] === true, 'Subject picker: one character returns nothing (never preloads the Subject Master)');
    $s = $manager->api('equivalency', 'subject_search', []);
    ok($s['status'] === 200 && $s['json']['subjects'] === [], 'Subject picker: no search text returns nothing');
    $fo = $viewer->api('equivalency', 'filter_options');
    ok($fo['status'] === 200 && count($fo['json']['reviewers']) === 2 && count($fo['json']['programs']) === 3, 'Filter options: reviewers who reviewed a record and the programs');
    ok($faculty->api('equivalency', 'filter_options')['status'] === 403, 'Filter options need equivalency.view');
    // Prerequisite warning data (advisory) and read scope
    $c = $manager->api('equivalency', 'compare', ['subject_a_id' => 1, 'subject_b_id' => 2])['json'];
    ok(count($c['requirement_links']) === 1 && (int)$c['requirement_links'][0]['subject_id'] === 1 && (int)$c['requirement_links'][0]['required_subject_id'] === 2 && $c['requirement_links'][0]['requirement_type'] === 'corequisite' && str_contains($c['requirement_links'][0]['versions'], 'APPROVED1'), 'Compare returns the corequisite link (A requires B) with its version');
    $c = $manager->api('equivalency', 'compare', ['subject_a_id' => 4, 'subject_b_id' => 3])['json'];
    ok(count($c['requirement_links']) === 1 && (int)$c['requirement_links'][0]['subject_id'] === 3 && str_contains($c['requirement_links'][0]['versions'], 'DRAFT2'), 'Link in the other direction (either order of A and B) is found');
    $v = $viewer->api('equivalency', 'compare', ['subject_a_id' => 3, 'subject_b_id' => 4]);
    ok($v['status'] === 200 && $v['json']['requirement_links'] === [] && !str_contains($v['body'], 'DRAFT2'), 'Restricted viewer: a link that exists only in a draft version is not returned');
    $c = $manager->api('equivalency', 'compare', ['subject_a_id' => 1, 'subject_b_id' => 2])['json'];
    ok(count($c['prerequisites']['subject_a']['rows']) <= 3 && isset($c['prerequisites']['subject_a']['total']), 'Prerequisites: at most 3 rows plus a total');
    ok(count($c['usage']['subject_a']['records']) <= 10, 'Usage contexts are capped at 10');
    // ---- Dashboard: the dead pending list is gone ----
    $svc = file_get_contents(__DIR__ . '/../dashboard/DashboardAnalyticsService.php') . file_get_contents(__DIR__ . '/../dashboard/DashboardAnalyticsRepository.php');
    ok(!str_contains($svc, 'pending_equivalency') && !str_contains($svc, 'pendingEquivalencies'), 'Dashboard: pending equivalency list removed');
    ok(is_file(__DIR__ . '/../modules/curriculum/http/equivalency_cimf_actions.php') && str_contains(file_get_contents(__DIR__ . '/../modules/curriculum/http/equivalency_cimf_actions.php'), '410'), 'Legacy CIMF stub kept (410)');
} finally {
    foreach ($clients as $c) $c->close();
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($created && preg_match('/^csms_equiv_test_[a-f0-9]{16}$/D', $name)) $db->query("DROP DATABASE `$name`");
    $db->close();
    $logText = file_get_contents($log); unlink($log); foreach (glob($sessionDir . '/sess_*') as $file) unlink($file); rmdir($sessionDir);
}
ok(!preg_match('/PHP (Warning|Fatal|Notice|Parse error|Deprecated)/', $logText), 'No PHP errors in HTTP server log' . (preg_match('/PHP (Warning|Fatal|Notice|Parse error|Deprecated)[^\n]*/', $logText, $m) ? ': ' . $m[0] : ''));
echo "PASS: $checks isolated HTTP checks for the Subject Equivalency Tool; disposable server and schema removed.\n";
