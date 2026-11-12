<?php
// CHED/DepEd Compliance Validator slice 1: one page with four views and three retired pages (302), result wording (Met / Not met / Cannot
// determine), source on every result, units explanation with both numbers, DEV-DEMO rows hidden by default and kept out of runs, history,
// Dashboard-style counts and CIMF, permissions and CSRF, read scope on run_get and history_list, Builder target needing a verified source,
// CIMF context using real fields. CLI only. Run with DB_NAME=sms_db_uitest as the structure source: the test creates and drops its own
// disposable schema and loopback PHP server; no row is written to sms_db or sms_db_uitest.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/FoundationHttpClient.php';
$config = csms_database_config();
if (!in_array($config['host'], ['localhost', '127.0.0.1'], true) || getenv('APP_ENV') === 'production') throw new RuntimeException('Local development only.');
if ($config['database'] === 'sms_db') throw new RuntimeException('Never run against sms_db; use DB_NAME=sms_db_uitest.');
$db = csms_database_connect($config); $source = $config['database'];
if (!preg_match('/^[A-Za-z0-9_]+$/D', $source)) throw new RuntimeException('Unsafe source schema');
$name = 'csms_cv_test_' . bin2hex(random_bytes(8)); $server = null; $clients = []; $checks = 0; $created = false;
$envBefore = getenv(); $log = tempnam(sys_get_temp_dir(), 'cv-http-'); $sessionDir = $log . '-sessions'; mkdir($sessionDir);
function ok(bool $cond, string $label): void { global $checks; if (!$cond) throw new RuntimeException('FAILED: ' . $label); $checks++; }
$actingUser = null;
if (!function_exists('current_user_id')) { function current_user_id(): ?int { global $actingUser; return $actingUser; } }
try {
    $db->query("CREATE DATABASE `$name`"); $created = true;
    foreach (array_column($db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM), 0) as $table) {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) throw new RuntimeException('Unsafe table name');
        $db->query("CREATE TABLE `$name`.`$table` LIKE `$source`.`$table`");
    }
    $db->select_db($name);
    foreach (['roles', 'permissions', 'role_permissions'] as $table) $db->query("INSERT INTO `$table` SELECT * FROM `$source`.`$table`");
    $loginPassword = bin2hex(random_bytes(24)); $hash = password_hash($loginPassword, PASSWORD_DEFAULT);
    foreach (['cmanager', 'cviewer', 'crestricted', 'cnone'] as $i => $username) {
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
    $role('c_manager', ['compliance.view', 'compliance.run', 'compliance.manage', 'compliance.reference.verify', 'curriculum.view', 'curriculum.view_unapproved', 'subject.view'], 1);
    $role('c_viewer', ['compliance.view', 'curriculum.view', 'curriculum.view_unapproved'], 2);
    $role('c_restricted', ['compliance.view', 'compliance.run', 'curriculum.view'], 3);   // no unapproved read
    $role('c_none', ['curriculum.view'], 4);

    $db->query("INSERT INTO academic_programs(id,program_code,program_name) VALUES(1,'CP','Compliance Program'),(2,'NP','Program without sources')");
    $db->query("INSERT INTO curricula(id,curriculum_code,curriculum_name,academic_program_id) VALUES(1,'CC','Compliance Curriculum',1),(2,'NC','No-source Curriculum',2)");
    // v1 draft (outside the restricted reader's scope); v2 approved; v3 draft in the program with no rules.
    $db->query("INSERT INTO curriculum_versions(id,curriculum_id,version_label,status,review_status,version_role,baseline_version_id) VALUES
        (1,1,'DRAFT1','draft','not_started','unclassified',NULL),(2,1,'APPROVED2','active','approved','unclassified',NULL),(3,2,'DRAFT3','draft','not_started','unclassified',NULL)");
    $db->query("INSERT INTO subjects(id,subject_code,subject_title,units) VALUES(1,'S1','Subject One',3),(2,'S2','Subject Two',4),(3,'S3','Subject Three',5)");
    $db->query("INSERT INTO curriculum_version_subjects(id,curriculum_version_id,subject_id,year_level,semester,sequence_order) VALUES
        (1,1,1,'1st Year','1st Semester',1),(2,1,2,'1st Year','1st Semester',2),(3,2,1,'1st Year','1st Semester',1),(4,2,2,'1st Year','1st Semester',2),(5,2,3,'1st Year','1st Semester',3)");
    $db->query("INSERT INTO compliance_references(id,reference_type,reference_code,title,issuing_body,effective_date,source_kind,verification_status,created_by) VALUES
        (1,'CHED','CMO TEST s.2015','Test memorandum','CHED','2015-08-01','regulatory_document','verified',1),
        (2,'CHED','DRAFT TEST','A draft source','CHED',NULL,'regulatory_document','draft',1),
        (3,'OTHER','DEV-DEMO-REFERENCE','Demo','DEVELOPMENT TEST SOURCE',NULL,'unknown','verified',1)");
    $db->query("INSERT INTO compliance_rules(id,compliance_reference_id,rule_name,rule_type,description,numeric_value,subject_id,academic_program_id,curriculum_id,technical_status,created_by) VALUES
        (1,1,'Rule A minimum 5 units','MIN_TOTAL_UNITS','',5,NULL,1,NULL,'active',1),
        (2,1,'Rule B minimum 10 units','MIN_TOTAL_UNITS','',10,NULL,1,NULL,'active',1),
        (3,2,'Rule C on a draft source','MIN_TOTAL_UNITS','',1,NULL,1,NULL,'active',1),
        (4,3,'DEV-DEMO MIN TOTAL','MIN_TOTAL_UNITS','',1,NULL,NULL,1,'active',1),
        (5,1,'Rule E requires S3','REQUIRED_SUBJECT','',NULL,3,1,NULL,'active',1)");

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr); $address = stream_socket_get_name($socket, false); fclose($socket);
    $env = $envBefore; $env['DB_NAME'] = $name; $env['APP_ENV'] = 'development'; $env['SMS_SESSION_COOKIE_SECURE'] = '0'; unset($env['CSMS_FACULTY_READ_SCOPE']);
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessionDir, '-d', 'error_log=' . $log, '-S', $address, '-t', dirname(__DIR__)],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env, ['bypass_shell' => true]);
    if (!is_resource($server)) throw new RuntimeException('Test HTTP server failed'); fclose($pipes[0]);
    for ($i = 0; $i < 60; $i++) { $ready = @stream_socket_client('tcp://' . $address, $errno, $errstr, .1); if ($ready) { fclose($ready); break; } usleep(100000); }
    $manager = new FoundationHttpClient('http://' . $address); $viewer = new FoundationHttpClient('http://' . $address);
    $restricted = new FoundationHttpClient('http://' . $address); $none = new FoundationHttpClient('http://' . $address); $guest = new FoundationHttpClient('http://' . $address);
    $clients = [$manager, $viewer, $restricted, $none, $guest];
    foreach ([[$manager, 'cmanager'], [$viewer, 'cviewer'], [$restricted, 'crestricted'], [$none, 'cnone']] as [$client, $username]) {
        $client->request('auth/signin.php');
        $r = $client->request('shared/auth_actions.php', ['action' => 'login', 'username' => $username, 'password' => $loginPassword], 'POST');
        ok($r['status'] === 200, 'Fixture login ' . $username);
    }
    $api = fn(FoundationHttpClient $c, string $action, array $args = [], string $method = 'GET', ?string $token = null) => $c->api('compliance', $action, $args, $method, $token);
    $run = fn(FoundationHttpClient $c, int $version, array $extra = [], ?string $token = null) => $api($c, 'validation_run', ['curriculum_version_id' => $version] + $extra, 'POST', $token);

    // ---- One page, four views, retired pages ----
    $page = $manager->request('modules/curriculum/pages/compliance_validator.php');
    $body = $page['body'];
    ok($page['status'] === 200 && str_contains($body, 'CHED/DepEd Compliance Validator'), 'Page: title');
    foreach (['results', 'rules', 'sources', 'history'] as $view) ok(str_contains($body, 'data-view="' . $view . '"'), "Page: segmented view $view");
    ok(str_contains($body, 'Rules come from the sources listed on each rule. Results show what the stored curriculum data does or does not show. They are not an official CHED, DepEd or institutional determination.'), 'Page: csms-info text');
    ok(!preg_match('/\bcompliant\b/i', $body), 'Page: no verdict word');
    ok(!stripos($body, 'official BCP'), 'Page: no "official BCP policy"');
    ok(!preg_match('/mapping-filter-card|read-only-pill|curriculum-notice|list-toolbar/', $body) && !preg_match('/domain-card|domain-table/', file_get_contents(__DIR__ . '/../modules/curriculum/pages/compliance_validator.php')), 'Page: no retired classes');
    ok(str_contains($body, 'Run validation') && str_contains($body, 'Run validation to see results for this version') && str_contains($body, 'No rules apply to this program'), 'Page: run button and the two Results empty states');
    $vpage = $viewer->request('modules/curriculum/pages/compliance_validator.php');
    ok($vpage['status'] === 200 && !str_contains($vpage['body'], 'id="runValidation"') && !str_contains($vpage['body'], 'id="ruleAdd"') && str_contains($vpage['body'], 'Read-only for your role'), 'View-only role: no run, add rule or add source controls');
    ok($none->request('modules/curriculum/pages/compliance_validator.php')['status'] === 403, 'No compliance.view: page refused');
    foreach (['validate_curriculum' => 'results', 'compliance_rules' => 'rules', 'compliance_references' => 'sources', 'validation_history' => 'history'] as $old => $view) {
        $r = $manager->request("modules/curriculum/pages/$old.php?curriculum_version_id=1");
        $location = ''; foreach ($manager->headers as $h) if (stripos($h, 'location:') === 0) $location = trim(substr($h, 9));
        ok($r['status'] === 302 && str_contains($location, 'compliance_validator.php') && str_contains($location, "view=$view"), "Old page $old redirects to view $view");
    }

    // ---- Permissions and CSRF ----
    ok(in_array($api($guest, 'rule_list')['status'], [401, 403], true) && $api($none, 'rule_list')['status'] === 403 && $api($none, 'context_options', ['level' => 'program'])['status'] === 403, 'Guest and no-permission role refused');
    ok($api($viewer, 'context_options', ['level' => 'program'])['status'] === 200, 'context_options for the shared bar');
    ok($run($viewer, 1)['status'] === 403, 'View-only role cannot run validation');
    ok($run($manager, 1, [], '')['status'] === 403, 'Run without a CSRF token refused');
    ok($api($viewer, 'rule_create', ['rule_name' => 'x'], 'POST')['status'] === 403, 'View-only role cannot create rules');

    // ---- Lists: demo hidden by default, paging, filters ----
    $rules = $api($manager, 'rule_list');
    ok($rules['status'] === 200 && $rules['json']['pagination']['total_records'] === 4 && !in_array('DEV-DEMO MIN TOTAL', array_column($rules['json']['rules'], 'rule_name'), true), 'Rule library: DEV-DEMO rule hidden by default');
    $withDemo = $api($manager, 'rule_list', ['include_demo' => '1']);
    ok($withDemo['json']['pagination']['total_records'] === 5 && in_array(1, array_column(array_filter($withDemo['json']['rules'], fn($r) => $r['rule_name'] === 'DEV-DEMO MIN TOTAL'), 'is_demo'), false), 'Rule library: "Include demo rules" shows it, flagged is_demo');
    ok($api($manager, 'rule_list', ['per_page' => 101])['status'] === 422 && $api($manager, 'rule_list', ['per_page' => 100])['status'] === 200, 'Page size 100 allowed, 101 refused');
    ok($api($manager, 'rule_list', ['rule_type' => 'REQUIRED_SUBJECT'])['json']['pagination']['total_records'] === 1 && $api($manager, 'rule_list', ['search' => 'draft source'])['json']['pagination']['total_records'] === 1, 'Rule filters: type and search');
    $refs = $api($manager, 'reference_list');
    ok($refs['json']['pagination']['total_records'] === 2 && $api($manager, 'reference_list', ['include_demo' => '1'])['json']['pagination']['total_records'] === 3 && $api($manager, 'reference_list', ['verification_status' => 'verified'])['json']['pagination']['total_records'] === 1, 'Sources: demo hidden by default, status filter');
    $detail = $api($manager, 'rule_get', ['id' => 1])['json']['rule'];
    ok($detail['reference_code'] === 'CMO TEST s.2015' && $detail['issuing_body'] === 'CHED' && (string)$detail['reference_year'] === '2015', 'Rule detail carries source code, issuing body and year');

    // ---- Run: wording, source on every row, units explanation, demo and draft sources not evaluated ----
    $r = $run($manager, 1);
    ok($r['status'] === 201 && $r['json']['message'] === 'Checked 3 rules from confirmed sources.', 'Run: "Checked 3 rules from confirmed sources." (draft source and demo rule not evaluated)');
    $runRow = $r['json']['run'];
    ok($runRow['met_count'] === 1 && $runRow['not_met_count'] === 2 && $runRow['cannot_determine_count'] === 0 && count($runRow['results']) === 3, 'Run: counts Met 1, Not met 2, Cannot determine 0');
    $byName = array_column($runRow['results'], null, 'rule_name_snapshot');
    ok($byName['Rule A minimum 5 units']['result_label'] === 'Met' && $byName['Rule B minimum 10 units']['result_label'] === 'Not met' && $byName['Rule E requires S3']['result_label'] === 'Not met', 'Result labels: Met / Not met');
    ok($byName['Rule A minimum 5 units']['result_status'] === 'PASS' && $byName['Rule B minimum 10 units']['result_status'] === 'FAIL', 'Stored values unchanged (PASS / FAIL)');
    foreach ($runRow['results'] as $res) ok($res['reference_code'] === 'CMO TEST s.2015' && $res['issuing_body'] === 'CHED' && (string)$res['reference_year'] === '2015', 'Result row carries the source: ' . $res['rule_name_snapshot']);
    $b = $byName['Rule B minimum 10 units'];
    ok(str_contains($b['actual_value_snapshot'], 'units of all placements: 7') && str_contains($b['actual_value_snapshot'], 'units of required placements:') && str_contains($b['requirement_snapshot'], '10')
        && str_contains($b['explanation'], 'Elective units count toward the total only up to the required amount.'), 'Units rule: both numbers (all placements, required placements) and the elective note');
    $json = json_encode($r['json']);
    ok(!preg_match('/\bcompliant\b/i', $json) && !str_contains($json, 'overall'), 'Run response: no verdict wording');
    $withDemoRun = $run($manager, 1, ['include_demo' => '1']);
    ok($withDemoRun['json']['message'] === 'Checked 4 rules from confirmed sources.', 'Opt-in demo rules run only when asked');
    $none3 = $run($manager, 3);
    ok($none3['status'] === 200 && $none3['json']['run'] === null && $none3['json']['message'] === 'No rules apply to this program. No CHED/DepEd source for it is recorded in this system yet.', 'Program with no source: "No rules apply" message and no stored run');

    // ---- History and read scope ----
    $demoRunId = (int)$withDemoRun['json']['run']['id']; $plainRunId = (int)$runRow['id'];
    $hist = $api($manager, 'history_list');
    $ids = array_map('intval', array_column($hist['json']['runs'], 'id'));
    ok(in_array($plainRunId, $ids, true) && !in_array($demoRunId, $ids, true), 'History: a run that evaluated demo rules is hidden by default');
    ok(in_array($demoRunId, array_map('intval', array_column($api($manager, 'history_list', ['include_demo' => '1'])['json']['runs'], 'id')), true), 'History: "Include demo" shows it');
    ok($api($manager, 'run_get', ['id' => $plainRunId])['status'] === 200 && $api($viewer, 'run_get', ['id' => $plainRunId])['status'] === 200, 'run_get: unrestricted readers see the draft version\'s run');
    ok($api($restricted, 'run_get', ['id' => $plainRunId])['status'] === 404, 'run_get: a reader without curriculum.view_unapproved gets 404 for a draft version\'s run');
    ok(!in_array($plainRunId, array_map('intval', array_column($api($restricted, 'history_list')['json']['runs'] ?? [], 'id')), true), 'history_list: the draft version\'s runs are not listed for the restricted reader');
    $approved = $run($manager, 2);
    $approvedRun = (int)$approved['json']['run']['id'];
    ok($approved['json']['run']['met_count'] === 3 && $approved['json']['run']['not_met_count'] === 0, 'Approved version (S1+S2+S3 = 12 units, S3 placed): all three Met');
    ok($api($restricted, 'run_get', ['id' => $approvedRun])['status'] === 200 && in_array($approvedRun, array_map('intval', array_column($api($restricted, 'history_list')['json']['runs'], 'id')), true), 'Restricted reader sees runs of the approved version');
    ok($run($restricted, 1)['status'] === 404 || $run($restricted, 1)['status'] === 403, 'Restricted reader cannot run against a draft version');

    // ---- In-process: Dashboard counts, Export, Builder target, CIMF context ----
    $GLOBALS['conn'] = $db; $actingUser = 1;
    require_once __DIR__ . '/../shared/authorization.php';
    require_once __DIR__ . '/../modules/curriculum/services/CurriculumVersionService.php';
    $summary = (new CurriculumVersionService($db))->builderSummary(1);
    ok($summary['target'] !== null && abs($summary['target']['value'] - 5) < 0.001, 'Builder target comes from a verified source (rule A or B)');
    $db->query('UPDATE compliance_references SET verification_status=\'draft\' WHERE id=1');
    ok((new CurriculumVersionService($db))->builderSummary(1)['target'] === null, 'Builder target: no target when the rule\'s source is not verified (demo and draft sources never set one)');
    $db->query('UPDATE compliance_references SET verification_status=\'verified\' WHERE id=1');
    $db->query("UPDATE compliance_rules SET technical_status='inactive' WHERE id IN (1,2,5)");
    ok((new CurriculumVersionService($db))->builderSummary(1)['target'] === null, 'Builder target: the DEV-DEMO rule on a verified demo source is never the target');
    $db->query("UPDATE compliance_rules SET technical_status='active' WHERE id IN (1,2,5)");

    require_once __DIR__ . '/../modules/curriculum/services/CIMFProfileRegistry.php';
    require_once __DIR__ . '/../modules/curriculum/services/CIMFConfig.php';
    require_once __DIR__ . '/../modules/curriculum/services/CIMFGlobalContextBuilder.php';
    $profile = CIMFProfileRegistry::profiles()['compliance_validator'];
    $built = (new CIMFGlobalContextBuilder($db))->build($profile, 'rule', ['rule_id' => 1, 'academic_program_id' => 1, 'curriculum_id' => 1, 'curriculum_version_id' => 1]);
    $rule = $built['facts']['configured_rule'] ?? [];
    ok(($rule['issuing_body'] ?? null) === 'CHED' && ($rule['reference_code'] ?? null) === 'CMO TEST s.2015' && (float)($rule['numeric_value'] ?? 0) === 5.0 && !array_key_exists('requirement_value', $rule), 'CIMF rule context uses real fields (issuing_body, numeric_value, reference_code)');
    $built = (new CIMFGlobalContextBuilder($db))->build($profile, 'explain', ['run_id' => $plainRunId, 'academic_program_id' => 1, 'curriculum_id' => 1, 'curriculum_version_id' => 1]);
    $validation = $built['facts']['validation'] ?? [];
    ok(($validation['not_met_count'] ?? null) === 2 && ($validation['met_count'] ?? null) === 1 && !array_key_exists('needs_review_count', $validation), 'CIMF run context: Met / Not met / Cannot determine counts, no needs_review_count');
    $threw = false;
    try { (new CIMFGlobalContextBuilder($db))->build($profile, 'rule', ['rule_id' => 4, 'academic_program_id' => 1, 'curriculum_id' => 1, 'curriculum_version_id' => 1]); } catch (InvalidArgumentException $e) { $threw = true; }
    ok($threw, 'CIMF refuses a DEV-DEMO rule');
    $threw = false;
    try { (new CIMFGlobalContextBuilder($db))->build($profile, 'reference', ['reference_id' => 3, 'academic_program_id' => 1, 'curriculum_id' => 1, 'curriculum_version_id' => 1]); } catch (InvalidArgumentException $e) { $threw = true; }
    ok($threw, 'CIMF refuses a DEV-DEMO source');

    // ---- Builder verification path for sources, and the retired code ----
    $js = file_get_contents(__DIR__ . '/../modules/curriculum/assets/compliance-validator.js');
    ok(!preg_match('/Internal Compliance Status|\bcompliant\b|official BCP/i', $js . file_get_contents(__DIR__ . '/../modules/curriculum/pages/compliance_validator.php')), 'Page code: no old status wording, no verdict word');
    ok(str_contains($js, 'Cannot determine from stored data') && str_contains($js, 'Source confirmed'), 'JS uses the new wording');
} finally {
    foreach ($clients as $c) $c->close();
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($created && preg_match('/^csms_cv_test_[a-f0-9]{16}$/D', $name)) $db->query("DROP DATABASE `$name`");
    $db->close();
    $logText = file_get_contents($log); unlink($log); foreach (glob($sessionDir . '/sess_*') as $file) unlink($file); rmdir($sessionDir);
}
ok(!preg_match('/PHP (Warning|Fatal|Notice|Parse error|Deprecated)/', $logText), 'No PHP errors in HTTP server log' . (preg_match('/PHP (Warning|Fatal|Notice|Parse error|Deprecated)[^\n]*/', $logText, $m) ? ': ' . $m[0] : ''));
echo "PASS: $checks isolated HTTP checks for the Compliance Validator; disposable server and schema removed.\n";
