<?php
// Grade Weighting Setup slices 1 and 2: subjects table (filters, sort whitelist, page-size cap), editable flag per version state, read-only data,
// exact-100 validation, duplicate names, remove configuration (permissions, CSRF, audit with old values, un-place afterwards), update audit
// (old and new lists), read scope on component_list and the other endpoints. CLI only. Run with DB_NAME=sms_db_uitest as the structure source:
// the test creates and drops its own disposable schema and loopback PHP server; no row is written to sms_db or sms_db_uitest.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/FoundationHttpClient.php';
$config = csms_database_config();
if (!in_array($config['host'], ['localhost', '127.0.0.1'], true) || getenv('APP_ENV') === 'production') throw new RuntimeException('Local development only.');
if ($config['database'] === 'sms_db') throw new RuntimeException('Never run against sms_db; use DB_NAME=sms_db_uitest.');
$db = csms_database_connect($config); $source = $config['database'];
if (!preg_match('/^[A-Za-z0-9_]+$/D', $source)) throw new RuntimeException('Unsafe source schema');
$name = 'csms_gw_test_' . bin2hex(random_bytes(8)); $server = null; $clients = []; $checks = 0; $created = false;
$envBefore = getenv(); $log = tempnam(sys_get_temp_dir(), 'gw-http-'); $sessionDir = $log . '-sessions'; mkdir($sessionDir);
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
    foreach (['gmanager', 'gviewer', 'grestricted', 'gfaculty'] as $i => $username) {
        $id = $i + 1; $email = $username . '@example.invalid';
        $stmt = $db->prepare("INSERT INTO users(id,username,email,first_name,last_name,password_hash,role) VALUES(?,?,?,'Test','Only',?,'student')");
        $stmt->bind_param('isss', $id, $username, $email, $hash); $stmt->execute();
    }
    $role = function (string $roleName, array $perms, int $userId) use ($db): void {
        $db->query("INSERT INTO roles(name,display_name,description) VALUES('$roleName','$roleName','Disposable test fixture')"); $rid = (int)$db->insert_id;
        $list = "'" . implode("','", $perms) . "'";
        $db->query("INSERT INTO role_permissions(role_id,permission_id) SELECT $rid,id FROM permissions WHERE name IN ($list)");
        $db->query("INSERT INTO user_roles(user_id,role_id) VALUES($userId,$rid)");
    };
    $role('g_manager', ['grade_weighting.view', 'grade_weighting.manage', 'curriculum.view', 'curriculum.view_unapproved', 'curriculum.update', 'subject.view'], 1);
    $role('g_viewer', ['grade_weighting.view', 'curriculum.view', 'curriculum.view_unapproved'], 2);
    $role('g_restricted', ['grade_weighting.view', 'grade_weighting.manage', 'curriculum.view'], 3);   // manage, but no unapproved read (the academic_curriculum_staff shape)
    $role('g_faculty', ['curriculum.view'], 4);

    $db->query("INSERT INTO academic_programs(id,program_code,program_name) VALUES(1,'GP','Weighting Program')");
    $db->query("INSERT INTO curricula(id,curriculum_code,curriculum_name,academic_program_id) VALUES(1,'GC','Weighting Curriculum',1)");
    // v1 editable draft; v2 approved; v3 archived; v4 under review (proposed on v2); v5 reference.
    $db->query("INSERT INTO curriculum_versions(id,curriculum_id,version_label,status,review_status,version_role,baseline_version_id) VALUES
        (1,1,'DRAFT1','draft','not_started','unclassified',NULL),(2,1,'APPROVED2','active','approved','unclassified',NULL),(3,1,'ARCH3','archived','not_started','unclassified',NULL),
        (4,1,'REVIEW4','draft','under_review','proposed',2),(5,1,'REF5','draft','not_started','reference',NULL)");
    for ($i = 1; $i <= 30; $i++) {
        $code = sprintf('G%02d', $i); $title = $i % 7 === 0 ? "Networks Subject $i" : "Computing Subject $i";
        $db->query("INSERT INTO subjects(id,subject_code,subject_title,units) VALUES($i,'$code','$title',3)");
        [$year, $sem] = $i <= 10 ? ['1st Year', '1st Semester'] : ($i <= 20 ? ['1st Year', '2nd Semester'] : ['2nd Year', '1st Semester']);
        $db->query("INSERT INTO curriculum_version_subjects(id,curriculum_version_id,subject_id,year_level,semester,sequence_order) VALUES($i,1,$i,'$year','$sem',$i)");
    }
    foreach ([101 => 2, 103 => 3, 104 => 4, 105 => 5] as $pid => $vid) $db->query("INSERT INTO curriculum_version_subjects(id,curriculum_version_id,subject_id,year_level,semester,sequence_order) VALUES($pid,$vid,1,'1st Year','1st Semester',1)");
    // Schemes written directly (they cannot be saved through the API on protected versions); weights are test values only.
    $scheme = function (int $placement, array $components) use ($db): int {
        $db->query("INSERT INTO grade_weighting_schemes(curriculum_version_subject_id) VALUES($placement)"); $sid = (int)$db->insert_id;
        foreach ($components as $i => [$cname, $weight]) $db->query("INSERT INTO grade_weighting_components(scheme_id,component_name,weight_percentage,sequence_order) VALUES($sid,'$cname',$weight," . ($i + 1) . ')');
        return $sid;
    };
    $approvedScheme = $scheme(101, [['Part A', '60.00'], ['Part B', '40.00']]);
    $draftIncomplete = $scheme(3, [['Only Part', '60.00']]);   // legacy-style incomplete scheme on v1, subject G03

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr); $address = stream_socket_get_name($socket, false); fclose($socket);
    $env = $envBefore; $env['DB_NAME'] = $name; $env['APP_ENV'] = 'development'; $env['SMS_SESSION_COOKIE_SECURE'] = '0'; unset($env['CSMS_FACULTY_READ_SCOPE']);
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessionDir, '-d', 'error_log=' . $log, '-S', $address, '-t', dirname(__DIR__)],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env, ['bypass_shell' => true]);
    if (!is_resource($server)) throw new RuntimeException('Test HTTP server failed'); fclose($pipes[0]);
    for ($i = 0; $i < 60; $i++) { $ready = @stream_socket_client('tcp://' . $address, $errno, $errstr, .1); if ($ready) { fclose($ready); break; } usleep(100000); }
    $manager = new FoundationHttpClient('http://' . $address); $viewer = new FoundationHttpClient('http://' . $address);
    $restricted = new FoundationHttpClient('http://' . $address); $faculty = new FoundationHttpClient('http://' . $address); $guest = new FoundationHttpClient('http://' . $address);
    $clients = [$manager, $viewer, $restricted, $faculty, $guest];
    foreach ([[$manager, 'gmanager'], [$viewer, 'gviewer'], [$restricted, 'grestricted'], [$faculty, 'gfaculty']] as [$client, $username]) {
        $client->request('auth/signin.php');
        $r = $client->request('shared/auth_actions.php', ['action' => 'login', 'username' => $username, 'password' => $loginPassword], 'POST');
        ok($r['status'] === 200, 'Fixture login ' . $username);
    }
    $api = fn(FoundationHttpClient $c, string $action, array $args = [], string $method = 'GET', ?string $token = null) => $c->api('grade_weighting', $action, $args, $method, $token);
    $ctx = ['program_id' => 1, 'curriculum_id' => 1];
    $audit = fn(string $action): int => (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='$action'")->fetch_row()[0];
    $lastMeta = fn(string $action): array => json_decode((string)$db->query("SELECT metadata_json FROM audit_logs WHERE action='$action' ORDER BY id DESC LIMIT 1")->fetch_row()[0], true) ?: [];

    // ---- Page, wording, permissions ----
    $page = $manager->request('modules/curriculum/pages/grade_weighting_setup.php');
    ok($page['status'] === 200 && substr_count($page['body'], 'class="mapping-context-bar') === 1, 'Page: the shared Program / Curriculum / Version bar');
    ok(str_contains($page['body'], 'Configure assessment component weights per subject in a curriculum version.') && str_contains($page['body'], 'Weights are configured by the institution. No CHED or DepEd weights are applied automatically. Totals must equal 100%.'), 'Page: subtitle and csms-info wording');
    ok(!str_contains($page['body'], 'Demo data') && !stripos($page['body'], 'official BCP') && !str_contains($page['body'], 'weightingPlacementSearch') && !str_contains($page['body'], 'grade-weighting-context-card'), 'Page: no demo sentence, no "official BCP policy", no separate Subject picker, no old context card');
    ok(str_contains($page['body'], 'csms-empty') && str_contains($page['body'], 'csms-filter-row') && str_contains($page['body'], 'class="csms-ctable"') && str_contains($page['body'], 'id="weightingSave"'), 'Page: shared empty state, filter row, table; manager has the editor controls');
    $vpage = $viewer->request('modules/curriculum/pages/grade_weighting_setup.php');
    ok($vpage['status'] === 200 && !str_contains($vpage['body'], 'id="weightingSave"') && str_contains($vpage['body'], 'Read-only for your role'), 'Viewer: page without save and remove controls, read-only chip');
    ok($faculty->request('modules/curriculum/pages/grade_weighting_setup.php')['status'] === 403 && $api($faculty, 'overview', ['curriculum_version_id' => 1])['status'] === 403, 'No grade_weighting.view: page and overview refused');
    ok(in_array($api($guest, 'overview', ['curriculum_version_id' => 1])['status'], [401, 403], true), 'Guest refused');

    // ---- Context: editable flag and reason per version state ----
    $c = fn(FoundationHttpClient $cl, int $v) => $api($cl, 'context', $ctx + ['curriculum_version_id' => $v]);
    $r = $c($manager, 1); ok($r['status'] === 200 && $r['json']['record']['editable'] === true && $r['json']['record']['reason_code'] === null && $r['json']['editable_version_exists'] === true, 'Editable draft version: editable, no reason');
    foreach ([2 => 'approved', 3 => 'archived', 4 => 'under_review', 5 => 'reference'] as $vid => $reason) {
        $r = $c($manager, $vid);
        ok($r['status'] === 200 && $r['json']['record']['editable'] === false && $r['json']['record']['reason_code'] === $reason && $r['json']['record']['reason_text'] !== '', "Version $vid: not editable, reason $reason");
    }
    $r = $c($viewer, 1); ok($r['json']['record']['editable'] === false && $r['json']['record']['reason_code'] === 'role' && $r['json']['editable_version_exists'] === true, 'View-only role: reason "role", no "no editable version" message');
    ok($c($restricted, 1)['status'] === 403, 'Read scope: a role without curriculum.view_unapproved cannot open the draft version');
    $r = $c($restricted, 2); ok($r['status'] === 200 && $r['json']['record']['reason_code'] === 'approved' && $r['json']['editable_version_exists'] === false, 'Restricted role that can manage: approved version readable, and "no editable version" is true for it');
    ok($api($manager, 'context', ['program_id' => 1, 'curriculum_id' => 1])['status'] === 422 && $api($manager, 'context', ['program_id' => 2, 'curriculum_id' => 1, 'curriculum_version_id' => 1])['status'] === 404, 'Context needs the version; a version outside the stated program is not found');
    $cov = $c($manager, 1)['json']['coverage'];
    ok($cov['placements'] === 30 && $cov['configured'] === 0 && $cov['incomplete'] === 1 && $cov['not_configured'] === 29, 'Coverage of the version: 30 placements, 0 configured, 1 incomplete, 29 not configured');
    $terms = $c($manager, 1)['json'];
    ok($terms['years'] === ['1st Year', '2nd Year'] && $terms['semesters'] === ['1st Semester', '2nd Semester'], 'Year and semester options come from the version');

    // ---- Overview: server-side list ----
    $list = fn(FoundationHttpClient $cl, array $args = []) => $api($cl, 'overview', ['curriculum_version_id' => 1] + $args);
    $total = fn(array $r): int => (int)($r['json']['pagination']['total_records'] ?? -1);
    $codes = fn(array $r): array => array_column($r['json']['placements'] ?? [], 'subject_code');
    $r = $list($manager);
    ok($r['status'] === 200 && $total($r) === 30 && count($r['json']['placements']) === 20 && $codes($r)[0] === 'G01', 'Overview: 30 placements, default page of 20 in term order');
    ok($list($manager, ['per_page' => 100])['status'] === 200 && $list($manager, ['per_page' => 101])['status'] === 422 && $list($manager, ['per_page' => 5])['status'] === 422, 'Page size: 100 allowed, 101 and 5 rejected');
    ok($total($list($manager, ['search' => 'networks'])) === 4 && $total($list($manager, ['search' => 'G1'])) === 10 && $list($manager, ['search' => 'g'])['status'] === 422 && $total($list($manager, ['search' => '%%'])) === 0, 'Search by code or title: 2-character minimum, literal wildcards');
    ok($total($list($manager, ['year_level' => '2nd Year'])) === 10 && $total($list($manager, ['semester' => '2nd Semester'])) === 10 && $total($list($manager, ['year_level' => '1st Year', 'semester' => '1st Semester'])) === 10, 'Year and semester filters');
    ok($total($list($manager, ['status' => 'not_configured'])) === 29 && $total($list($manager, ['status' => 'incomplete'])) === 1 && $total($list($manager, ['status' => 'configured'])) === 0 && $list($manager, ['status' => 'bogus'])['status'] === 422, 'Status filter (configured / incomplete / not configured); invalid value rejected');
    $row = array_column($list($manager, ['status' => 'incomplete'])['json']['placements'], null, 'subject_code')['G03'];
    ok($row['component_count'] === 1 && $row['total_weight'] === '60.00' && $row['status'] === 'incomplete', 'The legacy incomplete scheme shows 1 component, 60.00, Incomplete');
    ok($list($manager, ['sort' => 'remarks'])['status'] === 422 && $list($manager, ['sort' => 'id; DROP TABLE users'])['status'] === 422 && $list($manager, ['direction' => 'sideways'])['status'] === 422, 'Sort whitelist: unknown column or direction rejected');
    foreach (['code', 'title', 'term', 'components', 'total', 'status'] as $col) ok($list($manager, ['sort' => $col, 'direction' => 'desc'])['status'] === 200, "Sort by $col accepted");
    ok($codes($list($manager, ['sort' => 'code', 'direction' => 'desc']))[0] === 'G30' && $codes($list($manager, ['sort' => 'components', 'direction' => 'desc']))[0] === 'G03', 'Sort by code and by component count');
    $p = $list($manager, ['page' => 99, 'per_page' => 10]); ok($p['status'] === 200 && (int)$p['json']['pagination']['page'] === 3, 'A page past the end falls back to the last page');
    ok($api($manager, 'overview', [])['status'] === 422 && $api($manager, 'overview', ['curriculum_version_id' => 999])['status'] === 404, 'Overview needs a real version');
    ok($list($restricted)['status'] === 403 && $api($restricted, 'overview', ['curriculum_version_id' => 2])['status'] === 200, 'Read scope on overview: draft refused, approved allowed for the restricted role');

    // ---- Editor data: scheme_get ----
    $g = $api($manager, 'scheme_get', ['curriculum_version_subject_id' => 1]);
    ok($g['status'] === 200 && $g['json']['version']['editable'] === true && $g['json']['scheme'] === null && $g['json']['components'] === [], 'scheme_get on an unconfigured subject of an editable version: editable, no scheme');
    $g = $api($manager, 'scheme_get', ['curriculum_version_subject_id' => 101]);
    ok($g['status'] === 200 && $g['json']['version']['editable'] === false && $g['json']['version']['reason_code'] === 'approved' && count($g['json']['components']) === 2 && $g['json']['components'][0]['component_name'] === 'Part A', 'scheme_get on an approved version: read-only with reason and its components');
    ok($api($viewer, 'scheme_get', ['curriculum_version_subject_id' => 1])['json']['version']['reason_code'] === 'role', 'scheme_get for a view-only role: reason "role"');
    ok($api($restricted, 'scheme_get', ['curriculum_version_subject_id' => 1])['status'] === 403 && $api($restricted, 'scheme_get', ['curriculum_version_subject_id' => 101])['status'] === 200, 'Read scope on scheme_get');

    // ---- component_list: version derived from the scheme, read scope applies ----
    $r = $api($manager, 'component_list', ['scheme_id' => $approvedScheme]); ok($r['status'] === 200 && count($r['json']['components']) === 2, 'component_list: manager reads an approved version scheme');
    $r = $api($restricted, 'component_list', ['scheme_id' => $approvedScheme]); ok($r['status'] === 200, 'component_list: restricted role reads a scheme of an approved version');
    $r = $api($restricted, 'component_list', ['scheme_id' => $draftIncomplete]); ok($r['status'] === 404 && !str_contains($r['body'], 'Only Part'), 'component_list: restricted role gets nothing for a scheme in a draft version');
    ok($api($manager, 'component_list', ['scheme_id' => $draftIncomplete])['status'] === 200 && $api($faculty, 'component_list', ['scheme_id' => $approvedScheme])['status'] === 403, 'component_list: unrestricted role reads the draft scheme; no permission is refused');

    // ---- Save: exact 100, duplicates, CSRF, permissions, protected versions ----
    $save = fn(FoundationHttpClient $cl, int $placement, int $version, array $components, ?string $token = null) => $api($cl, 'configuration_save', ['curriculum_version_subject_id' => $placement, 'curriculum_version_id' => $version, 'components' => json_encode($components)], 'POST', $token);
    $r = $save($manager, 1, 1, [['component_name' => 'A', 'weight_percentage' => '33.3'], ['component_name' => 'B', 'weight_percentage' => '33.3'], ['component_name' => 'C', 'weight_percentage' => '33.3']]);
    ok($r['status'] === 422 && str_contains($r['json']['message'], 'exactly 100'), '33.3 x 3 is refused');
    $r = $save($manager, 1, 1, [['component_name' => 'A', 'weight_percentage' => '33.3'], ['component_name' => 'B', 'weight_percentage' => '33.3'], ['component_name' => 'C', 'weight_percentage' => '33.4']]);
    ok($r['status'] === 200 && array_sum(array_map('floatval', array_column($r['json']['components'], 'weight_percentage'))) === 100.0, '33.3 + 33.3 + 33.4 is accepted (exact decimal arithmetic)');
    ok($save($manager, 2, 1, [['component_name' => 'X', 'weight_percentage' => '60'], ['component_name' => 'x', 'weight_percentage' => '40']])['status'] === 422 && $save($manager, 2, 1, [['component_name' => 'X', 'weight_percentage' => '60'], ['component_name' => 'x', 'weight_percentage' => '40']])['json']['message'] !== '', 'Duplicate names are refused without regard to letter case');
    ok($save($manager, 2, 1, [['component_name' => 'X', 'weight_percentage' => '100.01']])['status'] === 422 && $save($manager, 2, 1, [['component_name' => 'X', 'weight_percentage' => '0']])['status'] === 422 && $save($manager, 2, 1, [['component_name' => 'X', 'weight_percentage' => '12.345']])['status'] === 422 && $save($manager, 2, 1, [['component_name' => '  ', 'weight_percentage' => '100']])['status'] === 422, 'Over 100, zero, three decimals and an empty name are refused');
    ok($save($manager, 2, 1, [['component_name' => 'Only', 'weight_percentage' => '100']], '')['status'] === 403, 'Save needs CSRF');
    ok($save($viewer, 2, 1, [['component_name' => 'Only', 'weight_percentage' => '100']])['status'] === 403, 'A view-only role cannot save');
    ok($save($restricted, 1, 1, [['component_name' => 'Only', 'weight_percentage' => '100']])['status'] === 403, 'A role without draft read access cannot save into a draft (read scope)');
    foreach ([101 => 2, 103 => 3, 104 => 4, 105 => 5] as $pid => $vid) ok($save($manager, $pid, $vid, [['component_name' => 'Only', 'weight_percentage' => '100']])['status'] === 409, "Save is refused on protected version $vid");
    ok($manager->request('modules/curriculum/http/grade_weighting_actions.php', ['action' => 'configuration_save', 'curriculum_version_subject_id' => 2, 'curriculum_version_id' => 1])['status'] === 405, 'Save by GET refused');
    ok((int)$db->query('SELECT COUNT(*) FROM grade_weighting_components WHERE scheme_id=' . $approvedScheme)->fetch_row()[0] === 2, 'Refused saves left the protected configuration untouched');

    // ---- Update audit: old and new lists ----
    $before = $audit('GRADE_WEIGHTING_CONFIGURATION_SAVE');
    $saved = $api($manager, 'scheme_get', ['curriculum_version_subject_id' => 1])['json']['components'];
    $r = $save($manager, 1, 1, [['id' => $saved[0]['id'], 'component_name' => 'Quizzes', 'weight_percentage' => '40'], ['id' => $saved[1]['id'], 'component_name' => 'B', 'weight_percentage' => '60']]);
    $meta = $lastMeta('GRADE_WEIGHTING_CONFIGURATION_SAVE');
    ok($r['status'] === 200 && $audit('GRADE_WEIGHTING_CONFIGURATION_SAVE') === $before + 1, 'Update saved and audited');
    ok($meta['old_total'] === '100.00' && $meta['new_total'] === '100.00' && count($meta['old_components']) === 3 && count($meta['new_components']) === 2
        && $meta['old_components'][0] === ['component_name' => 'A', 'weight_percentage' => '33.30'] && $meta['new_components'][0] === ['component_name' => 'Quizzes', 'weight_percentage' => '40.00'], 'Audit keeps the old and new component lists (names, weights) and totals');
    $u = $lastMeta('GRADE_WEIGHTING_COMPONENT_UPDATE');
    ok(isset($u['old_component_name'], $u['old_weight_percentage']) && $u['component_name'] !== '', 'Per-component update rows carry the old name and weight');

    // ---- Remove configuration (slice 2) ----
    $remove = fn(FoundationHttpClient $cl, int $placement, int $version, ?string $token = null) => $api($cl, 'configuration_remove', ['curriculum_version_subject_id' => $placement, 'curriculum_version_id' => $version], 'POST', $token);
    $un = $manager->api('assignment', 'remove', ['id' => 1], 'POST');
    ok($un['status'] === 409 && str_contains($un['json']['message'] ?? '', 'grade weighting configuration'), 'Before removal: un-placing a configured subject is refused');
    ok($remove($manager, 1, 1, '')['status'] === 403, 'Remove needs CSRF');
    ok($manager->request('modules/curriculum/http/grade_weighting_actions.php', ['action' => 'configuration_remove', 'curriculum_version_subject_id' => 1, 'curriculum_version_id' => 1])['status'] === 405, 'Remove by GET refused');
    ok($remove($viewer, 1, 1)['status'] === 403 && $remove($faculty, 1, 1)['status'] === 403 && $remove($restricted, 1, 1)['status'] === 403, 'Remove needs grade_weighting.manage and read scope');
    foreach ([101 => 2, 103 => 3, 104 => 4, 105 => 5] as $pid => $vid) ok($remove($manager, $pid, $vid)['status'] === 409, "Remove is refused on protected version $vid");
    ok((int)$db->query("SELECT COUNT(*) FROM grade_weighting_schemes WHERE id=$approvedScheme")->fetch_row()[0] === 1, 'A refused removal deletes nothing');
    ok($remove($manager, 4, 1)['status'] === 409 && $remove($manager, 1, 2)['status'] === 422, 'Removing an unconfigured subject or naming the wrong version is refused');
    $auditBefore = $audit('GRADE_WEIGHTING_CONFIGURATION_REMOVE');
    $r = $remove($manager, 1, 1);
    ok($r['status'] === 200 && $audit('GRADE_WEIGHTING_CONFIGURATION_REMOVE') === $auditBefore + 1, 'Remove works and is audited');
    $meta = $lastMeta('GRADE_WEIGHTING_CONFIGURATION_REMOVE');
    ok($meta['component_count'] === 2 && $meta['old_total'] === '100.00' && $meta['old_components'][0] === ['component_name' => 'Quizzes', 'weight_percentage' => '40.00'] && $meta['old_components'][1]['component_name'] === 'B', 'Removal audit keeps the old component names and weights');
    ok((int)$db->query('SELECT COUNT(*) FROM grade_weighting_schemes WHERE curriculum_version_subject_id=1')->fetch_row()[0] === 0 && (int)$db->query('SELECT COUNT(*) FROM grade_weighting_components WHERE scheme_id NOT IN (SELECT id FROM grade_weighting_schemes)')->fetch_row()[0] === 0, 'Scheme and components are both gone (no orphan components)');
    $un = $manager->api('assignment', 'remove', ['id' => 1], 'POST');
    ok($un['status'] === 200, 'After removal the subject can be un-placed from the version again');
    ok($total($list($manager)) === 29, 'The version now has 29 placements');
    $r = $remove($manager, 3, 1); ok($r['status'] === 200 && $total($list($manager, ['status' => 'incomplete'])) === 0, 'A legacy incomplete scheme can be removed too');

    // ---- Dead paths are gone ----
    foreach (['placement_options', 'program_options', 'curriculum_options', 'version_options'] as $dead) ok($api($manager, $dead, ['curriculum_version_id' => 1])['status'] === 400, "Old endpoint $dead is retired");
    $js = file_get_contents(__DIR__ . '/../modules/curriculum/assets/grade-weighting.js');
    ok(!preg_match('/\b(?:20|30|40)\s*,\s*(?:30|40)|PRELIM|DO 015|DM 074/', $js . file_get_contents(__DIR__ . '/../modules/curriculum/pages/grade_weighting_setup.php')), 'No default weights or DO 015 / DM 074 values in the page code');
} finally {
    foreach ($clients as $c) $c->close();
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($created && preg_match('/^csms_gw_test_[a-f0-9]{16}$/D', $name)) $db->query("DROP DATABASE `$name`");
    $db->close();
    $logText = file_get_contents($log); unlink($log); foreach (glob($sessionDir . '/sess_*') as $file) unlink($file); rmdir($sessionDir);
}
ok(!preg_match('/PHP (Warning|Fatal|Notice|Parse error|Deprecated)/', $logText), 'No PHP errors in HTTP server log' . (preg_match('/PHP (Warning|Fatal|Notice|Parse error|Deprecated)[^\n]*/', $logText, $m) ? ': ' . $m[0] : ''));
echo "PASS: $checks isolated HTTP checks for Grade Weighting Setup; disposable server and schema removed.\n";
