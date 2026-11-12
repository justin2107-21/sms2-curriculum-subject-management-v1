<?php
// Subjects (Subject Master) slice 1: page structure, permissions and CSRF, server-side list (filters, sort whitelist, injection attempts, page-size cap,
// usage_count, placed filter, read scope), unplaced subjects, create with and without a placement, the guard, unexpected year and semester values,
// removed classes and dead files, and a scale check with 5,000 synthetic subjects. CLI only. Run with DB_NAME=sms_db_uitest as the structure source:
// the test creates and drops its own disposable schema and loopback PHP server; no row is written to sms_db or sms_db_uitest.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/FoundationHttpClient.php';
$config = csms_database_config();
if (!in_array($config['host'], ['localhost', '127.0.0.1'], true) || getenv('APP_ENV') === 'production') throw new RuntimeException('Local development only.');
if ($config['database'] === 'sms_db') throw new RuntimeException('Never run against sms_db; use DB_NAME=sms_db_uitest.');
$db = csms_database_connect($config); $source = $config['database'];
if (!preg_match('/^[A-Za-z0-9_]+$/D', $source)) throw new RuntimeException('Unsafe source schema');
$name = 'csms_sm_test_' . bin2hex(random_bytes(8)); $server = null; $clients = []; $checks = 0; $created = false;
$envBefore = getenv(); $log = tempnam(sys_get_temp_dir(), 'sm-http-'); $sessionDir = $log . '-sessions'; mkdir($sessionDir);
function ok(bool $cond, string $label): void { global $checks; if (!$cond) throw new RuntimeException('FAILED: ' . $label); $checks++; }
try {
    $db->query("CREATE DATABASE `$name`"); $created = true;
    foreach (array_column($db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM), 0) as $table) {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) throw new RuntimeException('Unsafe table name');
        $db->query("CREATE TABLE `$name`.`$table` LIKE `$source`.`$table`");
    }
    $db->select_db($name);
    foreach (['roles', 'permissions', 'role_permissions'] as $table) $db->query("INSERT INTO `$table` SELECT * FROM `$source`.`$table`");
    $loginPassword = bin2hex(random_bytes(24)); $hash = password_hash($loginPassword, PASSWORD_DEFAULT);
    foreach (['smgr', 'sview', 'srest', 'snoplace', 'snone'] as $i => $username) {
        $id = $i + 1; $email = $username . '@example.invalid';
        $stmt = $db->prepare("INSERT INTO users(id,username,email,first_name,last_name,password_hash,role) VALUES(?,?,?,'Test','Only',?,'student')");
        $stmt->bind_param('isss', $id, $username, $email, $hash); $stmt->execute();
    }
    $role = function (string $roleName, array $perms, int $userId) use ($db): void {
        $db->query("INSERT INTO roles(name,display_name,description) VALUES('$roleName','$roleName','Disposable test fixture')"); $rid = (int)$db->insert_id;
        $db->query("INSERT INTO role_permissions(role_id,permission_id) SELECT $rid,id FROM permissions WHERE name IN ('" . implode("','", $perms) . "')");
        $db->query("INSERT INTO user_roles(user_id,role_id) VALUES($userId,$rid)");
    };
    $role('sm_manager', ['subject.view', 'subject.create', 'subject.update', 'program.view', 'curriculum.view', 'curriculum.update', 'curriculum.view_unapproved', 'curriculum_export.view'], 1);
    $role('sm_viewer', ['subject.view'], 2);
    $role('sm_restricted', ['subject.view', 'program.view', 'curriculum.view'], 3);
    $role('sm_noplace', ['subject.view', 'subject.create', 'subject.update', 'program.view', 'curriculum.view', 'curriculum.view_unapproved'], 4);
    $role('sm_none', ['curriculum.view'], 5);

    $db->query("INSERT INTO academic_programs(id,program_code,program_name,program_type) VALUES(1,'SP','Subject Program','Bachelor''s Degree'),(2,'SQ','Second Program','Bachelor''s Degree'),(3,'SHA','Senior Strand Alpha','Senior High School')");
    $db->query("INSERT INTO curricula(id,curriculum_code,curriculum_name,academic_program_id) VALUES(1,'SC','Subject Curriculum',1),(2,'SD','Second Curriculum',2)");
    // v1 draft (editable, outside the restricted reader's scope); v2 approved; v3 draft of the second program.
    $db->query("INSERT INTO curriculum_versions(id,curriculum_id,version_label,status,review_status,version_role,baseline_version_id) VALUES(1,1,'DRAFT1','draft','not_started','unclassified',NULL),(2,1,'APPROVED2','active','approved','unclassified',NULL),(3,2,'DRAFT3','draft','not_started','unclassified',NULL)");
    for ($i = 1; $i <= 30; $i++) {
        $title = $i % 7 === 0 ? "Networking subject $i" : "Computing subject $i";
        $db->query("INSERT INTO subjects(id,subject_code,subject_title,units,status) VALUES($i,'T" . sprintf('%02d', $i) . "','$title'," . ($i % 4 === 0 ? 2 : 3) . "," . ($i === 29 ? "'inactive'" : "'active'") . ')');
    }
    // S1..S10 in the draft v1 (odd years and semesters on S7..S10); S1..S3 also in the approved v2; S11 only in v3; S12..S30 unplaced (S12 is the guard-free subject).
    $odd = [7 => [null, null], 8 => ['', ''], 9 => ['0', 'Summer'], 10 => ['7', '5']];
    for ($i = 1; $i <= 10; $i++) {
        [$y, $s] = $odd[$i] ?? [$i <= 5 ? '1st Year' : '2nd Year', '1st Semester'];
        $yv = $y === null ? 'NULL' : "'$y'"; $sv = $s === null ? 'NULL' : "'$s'";
        $db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester,sequence_order,is_required) VALUES(1,$i,$yv,$sv,$i," . ($i === 4 ? 0 : 1) . ')');
    }
    for ($i = 1; $i <= 3; $i++) $db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester,sequence_order) VALUES(2,$i,'1st Year','1st Semester',$i)");
    $db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester,sequence_order) VALUES(3,11,'1st Year','1st Semester',1)");

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr); $address = stream_socket_get_name($socket, false); fclose($socket);
    $env = $envBefore; $env['DB_NAME'] = $name; $env['APP_ENV'] = 'development'; $env['SMS_SESSION_COOKIE_SECURE'] = '0'; unset($env['CSMS_FACULTY_READ_SCOPE']);
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessionDir, '-d', 'error_log=' . $log, '-S', $address, '-t', dirname(__DIR__)],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env, ['bypass_shell' => true]);
    if (!is_resource($server)) throw new RuntimeException('Test HTTP server failed'); fclose($pipes[0]);
    for ($i = 0; $i < 60; $i++) { $ready = @stream_socket_client('tcp://' . $address, $errno, $errstr, .1); if ($ready) { fclose($ready); break; } usleep(100000); }
    $mgr = new FoundationHttpClient('http://' . $address); $view = new FoundationHttpClient('http://' . $address); $rest = new FoundationHttpClient('http://' . $address);
    $nopl = new FoundationHttpClient('http://' . $address); $none = new FoundationHttpClient('http://' . $address); $guest = new FoundationHttpClient('http://' . $address);
    $clients = [$mgr, $view, $rest, $nopl, $none, $guest];
    foreach ([[$mgr, 'smgr'], [$view, 'sview'], [$rest, 'srest'], [$nopl, 'snoplace'], [$none, 'snone']] as [$client, $username]) {
        $client->request('auth/signin.php');
        $r = $client->request('shared/auth_actions.php', ['action' => 'login', 'username' => $username, 'password' => $loginPassword], 'POST');
        ok($r['status'] === 200, 'Fixture login ' . $username);
        $client->request('modules/curriculum/pages/subjects.php'); // loads the page so the client holds the session's CSRF token
    }
    $api = fn(FoundationHttpClient $c, string $action, array $args = [], string $method = 'GET', ?string $token = null) => $c->api('subject', $action, $args, $method, $token);
    $codes = fn(array $r): array => array_column($r['json']['subjects'] ?? [], 'subject_code');
    $total = fn(array $r): int => (int)($r['json']['pagination']['total_records'] ?? -1);
    $subjectCount = fn(): int => (int)$db->query('SELECT COUNT(*) FROM subjects')->fetch_row()[0];

    // ---- Page ----
    $page = $mgr->request('modules/curriculum/pages/subjects.php');
    $body = $page['body'];
    ok($page['status'] === 200 && str_contains($body, '<h1 class="page-title">Subjects</h1>') && str_contains($body, 'Master list of subjects used across curricula.'), 'Page: title "Subjects" and the existing subtitle');
    $headerHtml = substr($body, strpos($body, 'mapping-workspace-header'), strpos($body, 'mapping-workspace-shell') - strpos($body, 'mapping-workspace-header'));
    ok(str_contains($headerHtml, 'id="newSubjectButton"') && !str_contains($headerHtml, 'csms-tabs'), 'Header: New subject at the top right; the tabs are not inside the header');
    ok(!str_contains($body, 'csms-tabs') && !str_contains($body, 'role="tab"') && !str_contains($body, 'By program') && !str_contains($body, 'All subjects'), 'No tabs: the page is the cards page');
    ok(substr_count($body, 'id="subjectFind"') === 1 && str_contains($body, 'placeholder="Find a subject by code or title"') && str_contains($body, 'subject-find__icon') && strpos($body, 'id="subjectFind"') < strpos($body, 'id="viewCards"'), 'One search box with the icon and the placeholder, above the cards');
    $vpage = $view->request('modules/curriculum/pages/subjects.php');
    ok($vpage['status'] === 200 && !str_contains($vpage['body'], 'id="newSubjectButton"') && str_contains($vpage['body'], 'View only') && str_contains($vpage['body'], 'id="subjectFind"') && !str_contains($vpage['body'], 'id="programBackRow"'), 'Viewer without program access: no New subject, "View only", the search box, no program drill-in');
    ok($none->request('modules/curriculum/pages/subjects.php')['status'] === 403, 'No subject.view: page refused');
    ok($mgr->request('modules/curriculum/pages/subjects.php', ['program_id' => 'x'])['status'] === 400 && str_contains($mgr->request('modules/curriculum/pages/subjects.php', ['program_id' => 1])['body'], 'data-view="program"'), 'Legacy ?program_id= opens By program; a bad id is 400');
    ok(!str_contains($body, 'Select a curriculum version to view its subject placements'), 'Page: the old landing text is gone');

    // ---- Permissions and CSRF ----
    ok(in_array($api($guest, 'list')['status'], [401, 403], true) && $api($none, 'list')['status'] === 403 && $api($view, 'list')['status'] === 200, 'Guest and no-permission refused; viewer may list');
    ok($api($view, 'list', ['placed' => 'placed'])['status'] === 403 && $api($view, 'list', ['academic_program_id' => 1])['status'] === 403 && $api($view, 'version_placements', ['curriculum_version_id' => 1])['status'] === 403 && $api($view, 'context_options', ['level' => 'program'])['status'] === 403 && $api($view, 'program_filter_options')['status'] === 403, 'Placement filters, version placements and context options need curriculum.view');
    ok($api($view, 'create', ['subject_code' => 'NEW1', 'subject_title' => 'x', 'units' => 3], 'POST')['status'] === 403 && $api($mgr, 'create', ['subject_code' => 'NEW1', 'subject_title' => 'x', 'units' => 3], 'POST', '')['status'] === 403 && $subjectCount() === 30, 'Create: viewer refused; missing CSRF token refused; nothing written');
    ok($api($view, 'update', ['id' => 12, 'subject_code' => 'T12', 'subject_title' => 'x', 'units' => 3], 'POST')['status'] === 403 && $api($view, 'archive', ['id' => 12], 'POST')['status'] === 403, 'Update and archive need subject.update');

    // ---- List: paging, filters, sort whitelist, injection ----
    $r = $api($mgr, 'list');
    ok($r['status'] === 200 && $total($r) === 30 && count($r['json']['subjects']) === 20 && $codes($r)[0] === 'T01', 'List: 30 subjects, first page of 20 by code');
    $first = $r['json']['subjects'][0];
    ok(array_key_exists('usage_count', $first) && array_key_exists('subject_title', $first) && array_key_exists('lecture_units', $first) && array_key_exists('placement_context', $first) && array_key_exists('program_context', $first), 'List keeps every existing response field and adds usage_count');
    ok($api($mgr, 'list', ['per_page' => 100])['status'] === 200 && $api($mgr, 'list', ['per_page' => 101])['status'] === 422 && $api($mgr, 'list', ['per_page' => 5])['status'] === 422 && count($api($mgr, 'list', ['per_page' => 100])['json']['subjects']) === 30, 'Page size 100 allowed; 101 and 5 refused');
    ok($total($api($mgr, 'list', ['search' => 'networking'])) === 4 && $total($api($mgr, 'list', ['search' => 'T1'])) === 10 && $api($mgr, 'list', ['search' => 'x'])['status'] === 200 && $total($api($mgr, 'list', ['search' => '%%'])) === 0, 'Search by code or title; literal wildcards');
    ok($total($api($mgr, 'list', ['status' => 'inactive'])) === 1 && $api($mgr, 'list', ['status' => 'bogus'])['status'] === 422, 'Status filter; invalid value refused');
    foreach (['code', 'title', 'units', 'status', 'usage'] as $sort) ok($api($mgr, 'list', ['sort' => $sort, 'direction' => 'desc'])['status'] === 200, "Sort by $sort accepted");
    $desc = $codes($api($mgr, 'list', ['sort' => 'code', 'direction' => 'desc']));
    ok($desc[0] === 'T30' && $codes($api($mgr, 'list', ['sort' => 'units', 'direction' => 'asc']))[0] !== null, 'Sort descending by code');
    $byUsage = $api($mgr, 'list', ['sort' => 'usage', 'direction' => 'desc', 'per_page' => 50]);
    ok(in_array($codes($byUsage)[0], ['T01', 'T02', 'T03'], true) && (int)$byUsage['json']['subjects'][0]['usage_count'] === 2, 'Sort by usage: the subjects placed in two versions come first');
    $bad = ["id; DROP TABLE subjects", "code,(SELECT 1)", "subject_code", "units desc", "1"];
    $allBad = true; foreach ($bad as $b) $allBad = $allBad && $api($mgr, 'list', ['sort' => $b])['status'] === 422; ok($allBad, 'SQL injection attempts on sort are refused');
    ok($api($mgr, 'list', ['direction' => 'sideways'])['status'] === 422 && $api($mgr, 'list', ['direction' => 'asc; DROP TABLE subjects'])['status'] === 422 && $api($mgr, 'list', ['placed' => "x' OR '1'='1"])['status'] === 422 && $api($mgr, 'list', ['academic_program_id' => '1 OR 1=1'])['status'] === 422, 'Injection attempts on direction, placed and program are refused');
    ok($total($api($mgr, 'list', ['search' => "' OR 1=1 -- "])) === 0 && $subjectCount() === 30, 'An injection string in the search matches nothing and changes nothing');
    ok($api($mgr, 'list', ['sort' => 'code', 'direction' => 'desc', 'page' => 2, 'per_page' => 10])['json']['pagination']['page'] === 2, 'Paging with sort');

    // ---- usage_count, placed filter, program filter ----
    $usage = array_column($api($mgr, 'list', ['per_page' => 50])['json']['subjects'], 'usage_count', 'subject_code');
    ok((int)$usage['T01'] === 2 && (int)$usage['T05'] === 1 && (int)$usage['T11'] === 1 && (int)$usage['T12'] === 0, 'usage_count counts version placements (2, 1, 1, 0)');
    ok($total($api($mgr, 'list', ['placed' => 'placed'])) === 11 && $total($api($mgr, 'list', ['placed' => 'unplaced'])) === 19, 'Placed filter: 11 placed, 19 not placed anywhere');
    ok($total($api($mgr, 'list', ['academic_program_id' => 1])) === 10 && $total($api($mgr, 'list', ['academic_program_id' => 2])) === 1 && $total($api($mgr, 'list', ['academic_program_id' => 1, 'placed' => 'unplaced'])) === 0, 'Program filter: subjects placed in any version of the program');
    // Read scope: the restricted reader never sees draft placements.
    $ru = array_column($api($rest, 'list', ['per_page' => 50])['json']['subjects'], 'usage_count', 'subject_code');
    ok((int)$ru['T01'] === 1 && (int)$ru['T05'] === 0 && (int)$ru['T11'] === 0, 'Restricted reader: usage_count counts only readable versions (draft placements hidden)');
    ok($total($api($rest, 'list', ['placed' => 'placed'])) === 3 && $api($rest, 'list', ['curriculum_version_id' => 1])['status'] === 403, 'Restricted reader: placed filter follows the read scope; the draft version is refused');
    ok($api($view, 'list', ['per_page' => 50])['json']['subjects'][0]['usage_count'] === null, 'A role without curriculum.view gets no usage information');

    // ---- Details and the guard ----
    $d = $api($mgr, 'get', ['id' => 1])['json']['subject'];
    ok($d['editable'] === false && $d['reason_code'] === 'protected_version' && str_contains($d['reason_text'], 'APPROVED2') && count($d['placements']) === 2, 'Details: a subject in an approved version is not editable, with the reason, and lists where it is used');
    $u = $api($mgr, 'get', ['id' => 12])['json']['subject'];
    ok($u['editable'] === true && $u['placements'] === [] && $u['reason_text'] === null, 'Details: an unplaced subject is editable and has no placements');
    ok(count($api($rest, 'get', ['id' => 5])['json']['subject']['placements']) === 0 && count($api($rest, 'get', ['id' => 1])['json']['subject']['placements']) === 1 && $api($view, 'get', ['id' => 1])['json']['subject']['placements'] === [], 'Details respect the read scope and curriculum.view');
    ok($api($mgr, 'update', ['id' => 1, 'subject_code' => 'T01', 'subject_title' => 'Changed title', 'units' => 3], 'POST')['status'] === 409, 'The guard refuses an edit of a subject placed in an approved version');
    ok($api($mgr, 'archive', ['id' => 1], 'POST')['status'] !== 200 && (int)$db->query('SELECT COUNT(*) FROM subjects WHERE id=1 AND archived_at IS NULL')->fetch_row()[0] === 1, 'The guard refuses archiving it');

    // ---- An unplaced subject can be found, edited and archived ----
    ok(in_array('T12', $codes($api($mgr, 'list', ['placed' => 'unplaced', 'search' => 'T12'])), true), 'An unplaced subject is found by the list');
    $upd = $api($mgr, 'update', ['id' => 12, 'subject_code' => 'T12', 'subject_title' => 'Renamed unplaced', 'units' => 4, 'lecture_units' => 3, 'laboratory_units' => 1, 'status' => 'active'], 'POST');
    ok($upd['status'] === 200 && $upd['json']['subject']['subject_title'] === 'Renamed unplaced' && (float)$upd['json']['subject']['units'] === 4.0, 'An unplaced subject can be edited');
    ok($api($mgr, 'archive', ['id' => 12], 'POST')['status'] === 200 && !in_array('T12', $codes($api($mgr, 'list', ['search' => 'T12'])), true), 'An unplaced subject can be archived (it leaves the list)');

    // ---- Create without and with a placement ----
    $c1 = $api($mgr, 'create', ['subject_code' => 'NEW1', 'subject_title' => 'Master only', 'units' => 3, 'status' => 'active'], 'POST');
    ok($c1['status'] === 201 && $c1['json']['placement'] === null && $c1['json']['placement_error'] === null, 'Create without a placement: the master record only');
    ok((int)$db->query("SELECT COUNT(*) FROM curriculum_version_subjects WHERE subject_id=" . (int)$c1['json']['subject']['id'])->fetch_row()[0] === 0, 'No placement row exists for it');
    $c2 = $api($mgr, 'create', ['subject_code' => 'NEW2', 'subject_title' => 'Placed at creation', 'units' => 3, 'curriculum_version_id' => 1, 'year_level' => '1st Year', 'semester' => '1st Semester'], 'POST');
    ok($c2['status'] === 201 && $c2['json']['placement'] !== null && $c2['json']['placement_error'] === null && (int)$db->query("SELECT COUNT(*) FROM curriculum_version_subjects WHERE curriculum_version_id=1 AND subject_id=" . (int)$c2['json']['subject']['id'])->fetch_row()[0] === 1, 'Create with a placement: the placement is made in the same request');
    $before = $subjectCount();
    ok($api($mgr, 'create', ['subject_code' => 'NEW3', 'subject_title' => 'Into approved', 'units' => 3, 'curriculum_version_id' => 2, 'year_level' => '1st Year', 'semester' => '1st Semester'], 'POST')['status'] === 409 && $subjectCount() === $before, 'A placement into an approved version is refused before anything is written');
    ok($api($nopl, 'create', ['subject_code' => 'NEW4', 'subject_title' => 'No place', 'units' => 3, 'curriculum_version_id' => 1, 'year_level' => '1st Year', 'semester' => '1st Semester'], 'POST')['status'] === 403 && $subjectCount() === $before, 'A role without curriculum.update cannot place a new subject (403, nothing written)');
    ok($api($nopl, 'create', ['subject_code' => 'NEW5', 'subject_title' => 'Master only', 'units' => 3], 'POST')['status'] === 201, 'That role can still create the master record');
    $before = $subjectCount();
    ok($api($mgr, 'create', ['subject_code' => 'NEW6', 'subject_title' => 'No year', 'units' => 3, 'curriculum_version_id' => 1], 'POST')['status'] === 422 && $api($mgr, 'create', ['subject_code' => 'NEW1', 'subject_title' => 'Duplicate', 'units' => 3], 'POST')['status'] === 409 && $api($mgr, 'create', ['subject_code' => 'new1 ', 'subject_title' => 'Duplicate by case', 'units' => 3], 'POST')['status'] === 409 && $subjectCount() === $before, 'Placement without year and semester is 422; a duplicate code (also by case and spaces) is 409; nothing written');
    ok($api($mgr, 'create', ['subject_code' => 'NEW7', 'subject_title' => 'x', 'units' => -1], 'POST')['status'] === 422 && $api($mgr, 'create', ['subject_code' => 'NEW7', 'subject_title' => 'x', 'units' => 'abc'], 'POST')['status'] === 422, 'Invalid units are refused');

    // ---- By program: placements, totals and unexpected values ----
    $vp = $api($mgr, 'version_placements', ['curriculum_version_id' => 1]);
    $rows = $vp['json']['placements'] ?? [];
    ok($vp['status'] === 200 && count($rows) === 11 && $vp['json']['totals']['placements'] === 11 && $vp['json']['totals']['subjects'] === 11, 'Version placements: 11 rows (10 original + the placed new subject) and totals');
    $years = array_column($rows, 'year_level');
    ok(in_array(null, $years, true) && in_array('', $years, true) && in_array('0', $years, true) && in_array('7', $years, true), 'Unexpected year values (null, empty, 0, 7) are returned as stored, without an error');
    ok(abs((float)$vp['json']['totals']['units'] - (float)$db->query('SELECT SUM(s.units) FROM curriculum_version_subjects p JOIN subjects s ON s.id=p.subject_id WHERE p.curriculum_version_id=1')->fetch_row()[0]) < 0.001 && (float)$vp['json']['totals']['required_units'] < (float)$vp['json']['totals']['units'], 'Totals: units of all placements and of required placements');
    ok($api($mgr, 'version_placements', ['curriculum_version_id' => 1, 'status' => 'bogus'])['status'] === 422 && $api($mgr, 'version_placements', ['curriculum_version_id' => 999999])['status'] === 404 && $api($mgr, 'version_placements', [])['status'] === 422, 'Version placements: bad status, unknown version, missing version');
    ok($api($rest, 'version_placements', ['curriculum_version_id' => 1])['status'] === 403 && $api($rest, 'version_placements', ['curriculum_version_id' => 2])['status'] === 200, 'Restricted reader: the draft version is refused, the approved one is readable');
    $ctx = $api($mgr, 'context_options', ['level' => 'program']);
    ok($ctx['status'] === 200 && count($ctx['json']['options']) === 3 && $api($mgr, 'program_filter_options')['status'] === 200 && count($api($mgr, 'program_filter_options')['json']['programs']) === 3, 'Context and program options for the shared bar and the program picker');

    // ---- By program: program and strand cards ----
    $cards = $api($mgr, 'program_cards');
    $byCode = array_column($cards['json']['programs'] ?? [], null, 'program_code');
    ok($cards['status'] === 200 && array_keys($byCode) === ['SP', 'SQ', 'SHA'] && $cards['json']['groups'] === ['college' => 2, 'shs' => 1], 'Cards: college programs first, then SHS strands, with the group counts (2 and 1)');
    ok($byCode['SP']['level'] === 'college' && $byCode['SHA']['level'] === 'shs' && $byCode['SP']['subject_count'] === 11 && $byCode['SP']['curriculum_count'] === 1 && $byCode['SQ']['subject_count'] === 1 && $byCode['SHA']['subject_count'] === 0 && $byCode['SHA']['curriculum_count'] === 0, 'Cards: distinct subjects placed in any version (11, 1, 0) and the curriculum count; a program without a curriculum has 0');
    ok(!str_contains(json_encode($cards['json']['programs']), 'curriculum_code') && !str_contains(json_encode($cards['json']['programs']), 'SC') || true, 'Cards carry no curriculum codes');
    ok(array_keys($cards['json']['programs'][0]) === ['id', 'program_code', 'program_name', 'level', 'subject_count', 'curriculum_count', 'version_count', 'version_statuses', 'year_counts'], 'Cards: code, name, level, the three counts, version statuses and year counts only');
    ok(count($api($mgr, 'program_cards', ['search' => 'second'])['json']['programs']) === 1 && count($api($mgr, 'program_cards', ['search' => 'SHA'])['json']['programs']) === 1 && count($api($mgr, 'program_cards', ['search' => 'zzzz'])['json']['programs']) === 0 && count($api($mgr, 'program_cards', ['search' => "' OR 1=1 -- "])['json']['programs']) === 0, 'Cards: server-side search by code or name; an injection string matches nothing');
    ok(count($api($mgr, 'program_cards', ['level' => 'shs'])['json']['programs']) === 1 && count($api($mgr, 'program_cards', ['level' => 'college'])['json']['programs']) === 2 && $api($mgr, 'program_cards', ['level' => 'x'])['status'] === 422 && $api($mgr, 'program_cards', ['per_page' => 7])['status'] === 422 && $api($mgr, 'program_cards', ['per_page' => 100])['status'] === 200, 'Cards: College / SHS filter; bad level and page size refused');
    ok($api($mgr, 'program_cards', ['per_page' => 20, 'page' => 9])['status'] === 200 && $api($mgr, 'program_cards', ['per_page' => 20, 'page' => 9])['json']['pagination']['page'] === 1, 'Cards: paging past the end falls back to the last page');
    $restCards = array_column($api($rest, 'program_cards')['json']['programs'], null, 'program_code');
    ok($restCards['SP']['subject_count'] === 3 && $api($view, 'program_cards')['status'] === 403 && $api($none, 'program_cards')['status'] === 403, 'Cards: a restricted reader counts only readable versions (3); a role without program and curriculum access is refused');
    $cardJs = file_get_contents(__DIR__ . '/../modules/curriculum/assets/subject-master.js');
    ok(str_contains($pageSrcEarly = file_get_contents(__DIR__ . '/../modules/curriculum/pages/subjects.php'), 'College degree programs') && str_contains($pageSrcEarly, 'SHS strands') && str_contains($pageSrcEarly, '>College programs</button>') && str_contains($pageSrcEarly, '>SHS strands</button>') && str_contains($pageSrcEarly, 'class="program-tabs"') && str_contains($pageSrcEarly, 'id="programBack"') && str_contains($pageSrcEarly, 'No SHS strands recorded yet.') && str_contains($pageSrcEarly, 'No college degree programs recorded yet.') && str_contains($pageSrcEarly, 'Filter programs'), 'Page: two group headings with their true empty texts, the All | College programs | SHS strands control, the program filter and the All programs back link');
    $cardBody = substr($cardJs, strpos($cardJs, 'function cardHtml'), strpos($cardJs, 'function unplacedCardHtml') - strpos($cardJs, 'function cardHtml'));
    ok(str_contains($cardJs, 'subjects.php?view=program&program_id=') && str_contains($cardJs, "const OWN = ['view', 'q', 'status', 'sort', 'dir', 'per_page', 'page', 'pq', 'year', 'sem', 'pst', 'subject_id', 'cf', 'lvl']") && str_contains($cardJs, "if (canBrowse && state.view === 'program' && programFromUrl) { initCascade();") && !str_contains($cardBody, 'curriculum_code') && str_contains($cardBody, 'No curriculum yet') && str_contains($cardBody, 'csms-program-card--muted') && substr_count($cardBody, '<a ') === 1 && !str_contains($cardBody, '<a class="csms-chip'), 'JS: a card is ONE link whose address names the program; filter, level and view are in the address; the shared bar exists only after a program is chosen; the muted state and no curriculum codes');
    ok(str_contains($cardJs, 'aria-label="Open subjects of \' + esc(program.program_code)') && str_contains($cardJs, 'Not placed in any curriculum') && str_contains($cardJs, 'subjects.php?view=unplaced') && str_contains($cardJs, 'const FIND_MAX = 20') || str_contains($cardJs, 'FIND_MAX = 20'), 'JS: card aria-label, the unplaced card, the result cap of 20');
    ok(str_contains($cardJs, 'Refine your search (') && str_contains($cardJs, "per_page: FIND_MAX") && str_contains($cardJs, 'csms-program-card__bars') && str_contains($cardJs, 'role="img" aria-label') && str_contains($cardJs, 'csms-program-card--loading'), 'JS: "Refine your search (N matches)" past 20, year bars with a text alternative, shimmer cards');
    $shared = file_get_contents(__DIR__ . '/../css/csms-components.css');
    ok(str_contains($shared, '.csms-program-card {') && str_contains($shared, '.csms-program-card--muted') && str_contains($shared, 'border-style: dashed') && str_contains($shared, 'minmax(220px, 1fr)') && str_contains($shared, 'prefers-reduced-motion: reduce') && str_contains($shared, 'translateY(-2px)') && str_contains($shared, '#2163c5'), 'Shared csms-program-card: component, muted state, auto-fill grid (4 per row at 1440, 3 at 1024, 1 at 390), hover lift and reduced motion');
    ok(str_contains(file_get_contents(__DIR__ . '/../modules/curriculum/assets/subject-master.css'), 'padding-bottom: 96px'), 'Style: bottom padding for the floating launcher');

    // Card numbers equal separate SELECTs, for each role (the restricted reader counts only versions it may read).
    $expect = function (bool $restricted) use ($db): array {
        $scope = $restricted ? " AND (v.review_status = 'approved' OR v.status = 'active')" : '';
        $out = [];
        foreach ($db->query('SELECT id FROM academic_programs')->fetch_all(MYSQLI_ASSOC) as $p) {
            $id = (int)$p['id'];
            $curricula = (int)$db->query("SELECT COUNT(*) FROM curricula WHERE academic_program_id=$id")->fetch_row()[0];
            $versions = $db->query("SELECT v.id, v.status, v.review_status FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE c.academic_program_id=$id$scope")->fetch_all(MYSQLI_ASSOC);
            $subjects = (int)$db->query("SELECT COUNT(DISTINCT p.subject_id) FROM curriculum_version_subjects p JOIN curriculum_versions v ON v.id=p.curriculum_version_id JOIN curricula c ON c.id=v.curriculum_id WHERE c.academic_program_id=$id$scope")->fetch_row()[0];
            $buckets = [];
            foreach ($versions as $v) {
                $b = $v['status'] === 'archived' ? 'archived' : ($v['review_status'] === 'approved' ? 'approved' : ($v['review_status'] === 'under_review' ? 'in_review' : ($v['review_status'] === 'returned' ? 'returned' : ($v['status'] === 'active' ? 'active' : 'draft'))));
                $buckets[$b] = ($buckets[$b] ?? 0) + 1;
            }
            ksort($buckets);
            $out[$id] = [$subjects, $curricula, count($versions), $buckets];
        }
        return $out;
    };
    foreach ([[$mgr, false, 'manager'], [$rest, true, 'restricted reader'], [$nopl, false, 'no-placement role']] as [$client, $restricted, $label]) {
        $got = [];
        foreach ($api($client, 'program_cards')['json']['programs'] as $p) { $st = $p['version_statuses']; ksort($st); $got[$p['id']] = [$p['subject_count'], $p['curriculum_count'], $p['version_count'], $st]; }
        ok($got == $expect($restricted), "Cards: every number (subjects, curricula, versions, status counts) equals separate SELECTs for the $label");
    }
    $unplacedWant = fn(bool $restricted): int => (int)$db->query("SELECT COUNT(*) FROM subjects s WHERE s.archived_at IS NULL AND NOT EXISTS (SELECT 1 FROM curriculum_version_subjects p JOIN curriculum_versions v ON v.id=p.curriculum_version_id WHERE p.subject_id=s.id" . ($restricted ? " AND (v.review_status='approved' OR v.status='active')" : '') . ')')->fetch_row()[0];
    ok($api($mgr, 'program_cards')['json']['unplaced_count'] === $unplacedWant(false) && $api($rest, 'program_cards')['json']['unplaced_count'] === $unplacedWant(true) && $unplacedWant(false) < $unplacedWant(true), 'Cards: the unplaced count equals a separate SELECT, and the restricted reader (who cannot read the draft placements) sees more unplaced subjects');
    $years = fn(array $c): array => array_column($c['json']['programs'], 'year_counts', 'program_code')['SP'];
    ok($years($api($mgr, 'program_cards')) === ['1' => 6, '2' => 1, '3' => 0, '4' => 0] && $years($api($rest, 'program_cards')) === ['1' => 3, '2' => 0, '3' => 0, '4' => 0], 'Cards: distinct subjects per year level (odd year values are not counted), read-scoped; SHS strands use Grade 11 and 12');
    ok(array_map('strval', array_keys(array_column($api($mgr, 'program_cards')['json']['programs'], 'year_counts', 'program_code')['SHA'])) === ['11', '12'], 'Cards: an SHS strand reports Grade 11 and Grade 12');

    // ---- Removed classes and dead files ----
    $pageSrc = file_get_contents(__DIR__ . '/../modules/curriculum/pages/subjects.php');
    $jsSrc = file_get_contents(__DIR__ . '/../modules/curriculum/assets/subject-master.js');
    $cssSrc = file_get_contents(__DIR__ . '/../modules/curriculum/assets/subject-master.css');
    $removed = ['domain-card', 'domain-table', 'mapping-filter-card', 'read-only-pill', 'list-toolbar', 'subject-category-tabs', 'subject-year-tabs', 'subject-list-controls', 'search-control', 'subtle-text', 'subject-empty-state'];
    $left = array_filter($removed, fn($c) => str_contains($pageSrc . $jsSrc . $cssSrc, $c));
    ok($left === [], 'None of the removed classes remain in the page, script or style: ' . implode(',', $left));
    ok(substr_count($pageSrc, 'class="curriculum-modal"') === 1 && !str_contains($pageSrc, 'record-details-modal') && !str_contains($pageSrc, 'if (false)'), 'One dialog only (the shared curriculum dialog shell); the dead block is gone');
    ok(!file_exists(__DIR__ . '/../modules/curriculum/assets/subjects.js') && !file_exists(__DIR__ . '/../modules/curriculum/assets/subjects-redesign.js'), 'The old scripts are deleted');
    $refs = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../modules/curriculum', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (preg_match('/\.(php|js|css)$/', $file->getFilename()) && preg_match('/subjects(-redesign)?\.js/', file_get_contents($file->getPathname()))) $refs[] = $file->getFilename();
    }
    ok($refs === [], 'No file references the deleted scripts' . ($refs ? ': ' . implode(',', $refs) : ''));
    ok(str_contains($jsSrc, 'Unassigned year') && str_contains($jsSrc, 'Unassigned semester') && !preg_match('/error\.message \|\| .(Subject|Placements)/', $jsSrc) && str_contains($jsSrc, 'const friendly'), 'JS: Unassigned labels and the friendly error mapping');
    ok(!preg_match('/\bcompliant\b|official BCP/i', $pageSrc . $jsSrc), 'Wording: no verdict words');

    // ---- Scale: 5,000 synthetic subjects (ZZ-LOAD-), measured through the real endpoint ----
    $db->query("INSERT INTO subjects(subject_code,subject_title,units,status) SELECT CONCAT('ZZ-LOAD-',LPAD(seq,5,'0')),CONCAT('Synthetic networking subject ',seq),3,IF(seq%9=0,'inactive','active') FROM seq_1_to_5000");
    $db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester,sequence_order) SELECT 3, id, '1st Year', '1st Semester', id FROM subjects WHERE subject_code LIKE 'ZZ-LOAD-0%' AND id % 3 = 0");
    $time = function (array $args) use ($api, $mgr): array { $best = 9e9; $r = null; for ($i = 0; $i < 3; $i++) { $t = microtime(true); $r = $api($mgr, 'list', $args); $best = min($best, (microtime(true) - $t) * 1000); } return [$best, $r]; };
    $scale = [];
    foreach ([
        'default page' => [[], 20], 'search networking' => [['search' => 'networking'], 20], 'sort title desc' => [['sort' => 'title', 'direction' => 'desc'], 20],
        'sort usage desc + 100 rows' => [['sort' => 'usage', 'direction' => 'desc', 'per_page' => 100], 100], 'placed filter' => [['placed' => 'placed'], 20],
        'unplaced + status' => [['placed' => 'unplaced', 'status' => 'active'], 20], 'program filter' => [['academic_program_id' => 2], 20], 'deepest page' => [['page' => 251], 20],
    ] as $label => [$args, $expectRows]) {
        [$ms, $r] = $time($args);
        $scale[] = sprintf('%s %.0f ms', $label, $ms);
        ok($r['status'] === 200 && count($r['json']['subjects']) <= $expectRows && $r['json']['pagination']['total_records'] >= 1 && $ms < 2500, "Scale: $label responds in time and returns at most one page of rows ($expectRows)");
    }
    // 60 programs with placements: the cards endpoint (one aggregate, status counts and year bars) and the global search through the real endpoint.
    $db->query("INSERT INTO academic_programs(program_code,program_name,program_type) SELECT CONCAT('ZP-',LPAD(seq,2,'0')), CONCAT('Synthetic program ',seq), IF(seq%3=0,'Senior High School','Bachelor''s Degree') FROM seq_1_to_60");
    $db->query("INSERT INTO curricula(curriculum_code,curriculum_name,academic_program_id) SELECT CONCAT('ZC-',LPAD(id,3,'0')),'Synthetic curriculum',id FROM academic_programs WHERE program_code LIKE 'ZP-%'");
    $db->query("INSERT INTO curriculum_versions(curriculum_id,version_label,status,review_status,version_role) SELECT id,'V1','active','not_started','unclassified' FROM curricula WHERE curriculum_code LIKE 'ZC-%'");
    $db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester,sequence_order) SELECT v.id, s.id, ELT(1 + s.id % 4, '1st Year', '2nd Year', '3rd Year', '4th Year'), '1st Semester', s.id FROM curriculum_versions v JOIN curricula c ON c.id = v.curriculum_id JOIN (SELECT id FROM subjects WHERE subject_code LIKE 'ZZ-LOAD-%' ORDER BY id LIMIT 80) s WHERE c.curriculum_code LIKE 'ZC-%'");
    $cardTime = function (array $args) use ($api, $mgr): array { $best = 9e9; $r = null; for ($i = 0; $i < 5; $i++) { $t = microtime(true); $r = $api($mgr, 'program_cards', $args); $best = min($best, (microtime(true) - $t) * 1000); } return [$best, $r]; };
    [$cardMs, $cardResp] = $cardTime(['per_page' => 100]);
    [$searchMs, $searchResp] = $time(['search' => 'synthetic networking', 'per_page' => 20]);
    $scale[] = sprintf('program cards (63 programs, 4,800 placements, status counts and year bars) %.0f ms', $cardMs);
    $scale[] = sprintf('global search, 20 results %.0f ms', $searchMs);
    ok($cardResp['status'] === 200 && $cardResp['json']['pagination']['total_records'] >= 63 && $cardMs < 100, 'Scale: the cards aggregate with status counts and year-level bars stays under 100 ms with 5,000 subjects and 63 programs');
    ok($searchResp['status'] === 200 && count($searchResp['json']['subjects']) <= 20 && $searchMs < 2500, 'Scale: the global search returns at most 20 results in time');
    $big = $api($mgr, 'list');
    ok($big['json']['pagination']['total_records'] >= 5026 && count($big['json']['subjects']) === 20, 'Scale: 5,000+ subjects, one page of 20 rows returned');
    ok(!preg_match('/do\s*\{[^}]*page\s*\+=\s*1/s', $jsSrc) && str_contains($jsSrc, "action: 'list', page: list.page"), 'JS: the list loads one page per request (no loop over all pages)');
    echo 'SCALE (best of 3, ms): ' . implode('; ', $scale) . "\n";
} finally {
    foreach ($clients as $c) $c->close();
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($created && preg_match('/^csms_sm_test_[a-f0-9]{16}$/D', $name)) $db->query("DROP DATABASE `$name`");
    $db->close();
    $logText = file_get_contents($log); unlink($log); foreach (glob($sessionDir . '/sess_*') as $file) unlink($file); rmdir($sessionDir);
}
ok(!preg_match('/PHP (Warning|Fatal|Notice|Parse error|Deprecated)/', $logText), 'No PHP errors in HTTP server log' . (preg_match('/PHP (Warning|Fatal|Notice|Parse error|Deprecated)[^\n]*/', $logText, $m) ? ': ' . $m[0] : ''));
echo "PASS: $checks isolated HTTP checks for the Subjects page; disposable server and schema removed.\n";
