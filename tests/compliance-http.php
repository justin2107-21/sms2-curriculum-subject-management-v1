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
    $headerHtml = substr($body, strpos($body, 'mapping-workspace-header'), strpos($body, 'mapping-workspace-shell') - strpos($body, 'mapping-workspace-header'));
    ok(str_contains($headerHtml, 'CHED/DepEd Compliance Validator') && str_contains($headerHtml, 'csms-info__tip') && str_contains($headerHtml, 'They are not an official CHED, DepEd or institutional determination.') && str_contains($headerHtml, 'id="ruleAdd"') && str_contains($headerHtml, 'id="sourceAdd"'), 'Header: title, (i) tooltip with the determination text, and the Add rule / Add source buttons at the top right');
    ok(!str_contains($headerHtml, 'csms-tabs') && strpos($body, 'class="csms-tabs"') !== false && strpos($body, 'class="csms-tabs"') < strpos($body, 'id="complianceContextBar"'), 'Tabs are not inside the header and come before the context bar');
    ok(!str_contains($body, 'Results are internal decision-support output only') && !str_contains($body, 'Evidence is a locator'), 'The long disclaimer line and the evidence line are gone from the page');
    ok(!preg_match('/\bcompliant\b/i', $body), 'Page: no verdict word');
    ok(!stripos($body, 'official BCP'), 'Page: no "official BCP policy"');
    ok(!preg_match('/mapping-filter-card|read-only-pill|curriculum-notice|list-toolbar/', $body) && !preg_match('/domain-card|domain-table/', file_get_contents(__DIR__ . '/../modules/curriculum/pages/compliance_validator.php')), 'Page: no retired classes');
    ok(str_contains($body, 'Run validation') && str_contains($body, 'Run validation to see results for this version') && str_contains($body, 'No rules apply to this program'), 'Page: run button and the two Results empty states');
    $vpage = $viewer->request('modules/curriculum/pages/compliance_validator.php');
    ok($vpage['status'] === 200 && !str_contains($vpage['body'], 'id="runValidation"') && !str_contains($vpage['body'], 'id="ruleAdd"') && str_contains($vpage['body'], 'View only'), 'View-only role: no run, add rule or add source controls');
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

    // ================= Slice 3: rule library (migration 032) =================
    // Migration idempotence: drop the two tables, apply the migration file twice, and the table definitions do not change.
    $migration = preg_replace('/^USE sms_db;\s*$/m', '', file_get_contents(__DIR__ . '/../database/migrations/032_compliance_rule_library.sql'));
    $db->query('DROP TABLE IF EXISTS compliance_library_results'); $db->query('DROP TABLE IF EXISTS compliance_library_rules');
    $db->multi_query($migration); do { if ($r = $db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
    $shape = fn() => $db->query('SHOW CREATE TABLE compliance_library_rules')->fetch_row()[1] . $db->query('SHOW CREATE TABLE compliance_library_results')->fetch_row()[1];
    $first = $shape();
    $db->multi_query($migration); do { if ($r = $db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
    ok($first !== '' && $first === $shape(), 'Migration 032: applied twice, the tables are identical (idempotent)');
    ok(!str_contains(file_get_contents(__DIR__ . '/../database/migrations/032_compliance_rule_library.sql'), 'ALTER TABLE'), 'Migration 032: new tables only, no ALTER of a baseline-covered table');

    // Fixtures: programs with a type, one verified source per catalog code.
    $db->query("INSERT INTO academic_programs(id,program_code,program_name,program_type) VALUES(3,'BSIT','Library BSIT','Bachelor''s Degree'),(4,'BSCS','Other college program','Bachelor''s Degree'),(5,'SHSX','Senior high strand','Senior High School')");
    $db->query("INSERT INTO curricula(id,curriculum_code,curriculum_name,academic_program_id) VALUES(3,'LB','BSIT curriculum',3),(4,'LC','BSCS curriculum',4),(5,'LS','SHS curriculum',5)");
    $db->query("INSERT INTO curriculum_versions(id,curriculum_id,version_label,status,review_status,version_role,baseline_version_id) VALUES(4,3,'LIB-BSIT','draft','not_started','unclassified',NULL),(5,4,'LIB-BSCS','draft','not_started','unclassified',NULL),(6,5,'LIB-SHS','draft','not_started','unclassified',NULL)");
    $db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester,sequence_order) VALUES(4,1,'1st Year','1st Semester',1),(4,2,'1st Year','1st Semester',2),(4,3,'1st Year','1st Semester',3),(5,1,'1st Year','1st Semester',1),(6,1,'1st Year','1st Semester',1)");
    require_once __DIR__ . '/../modules/curriculum/services/ComplianceLibraryCatalog.php';
    require_once __DIR__ . '/../modules/curriculum/repositories/ComplianceLibraryRepository.php';
    $refIds = [];
    foreach (['CMO 25 s.2015' => ['CHED', 'CHED'], 'CMO 20 s.2013' => ['CHED', 'CHED'], 'CMO 39 s.2021' => ['CHED', 'CHED'], 'RA 9163' => ['OTHER', 'Republic of the Philippines'], 'DO 017 s.2026' => ['DEPED', 'DepEd'], 'DO 015 s.2026' => ['DEPED', 'DepEd']] as $code => [$type, $body]) {
        $db->query("INSERT INTO compliance_references(reference_type,reference_code,title,issuing_body,effective_date,source_kind,verification_status,created_by) VALUES('$type','$code','$code','$body','2015-08-01','regulatory_document','verified',1)");
        $refIds[$code] = (int)$db->insert_id;
    }
    $library = new ComplianceLibraryRepository($db);
    $catalog = ComplianceLibraryCatalog::rules();
    $seed = function () use ($library, $catalog, $refIds): array { $o = []; foreach ($catalog as $rule) $o[$rule['rule_code']] = $library->upsert($rule, $rule['reference_code'] === null ? null : $refIds[$rule['reference_code']]); return $o; };
    $one = $seed();
    ok(count($one) === 10 && array_unique(array_values($one)) === ['created'], 'Seed: 10 library rules created');
    $two = $seed();
    ok(array_unique(array_values($two)) === ['unchanged'] && (int)$db->query('SELECT COUNT(*) FROM compliance_library_rules')->fetch_row()[0] === 10, 'Seed: a second pass changes nothing (idempotent), still 10 rows');
    $row = fn(string $code) => $db->query("SELECT * FROM compliance_library_rules WHERE rule_code='$code'")->fetch_assoc();
    ok($row('CMO25-BSIT-MIN-UNITS')['source_page'] === '10' && $row('CMO39-PE')['source_page'] === null && $row('DO017-T9-ACADEMIC')['source_page'] === '16' && $row('DO015-T10-GRADING')['source_page'] === '14', 'Pages recorded only where given (PE and NSTP: none)');
    ok($row('BCP-NSTP1-PREREQ-NSTP2')['compliance_reference_id'] === null && $row('BCP-NSTP1-PREREQ-NSTP2')['source_class'] === 'client_reply' && str_contains($row('BCP-NSTP1-PREREQ-NSTP2')['source_label'], 'not a published policy'), 'Client reply: no reference, labelled "not a published policy"');
    ok(json_decode($row('CMO25-BSIT-MIN-UNITS')['parameters_json'], true) === ['min_units' => 146] && json_decode($row('CMO20-GE-MIN-UNITS')['parameters_json'], true)['min_units'] === 36 && str_contains($row('CMO20-GE-MIN-UNITS')['note'], '54 units') && str_contains($row('CMO20-GE-MIN-UNITS')['note'], 'no verdict'), 'Values as given (146; 36 = 24 + 9 + 3) and the two-figures note');
    ok(!preg_match('/\bcompliant\b|official BCP/i', json_encode($catalog)) && !str_contains(json_encode($catalog), 'transmut') && !str_contains(json_encode($catalog), 'Key Stage'), 'Catalog: no verdict words and none of the excluded items');
    $dep = fn(string $c) => $row($c)['evaluation_mode'];
    ok($dep('DO017-T9-ACADEMIC') === 'pending_bcp' && $dep('DO017-T10-TECHPRO') === 'pending_bcp' && $dep('DO015-T10-GRADING') === 'pending_bcp' && $dep('CMO25-BSIT-COMPONENTS') === 'display_only' && $dep('BCP-NSTP-COMPONENTS') === 'display_only', 'Modes: DepEd pending, the component table and ROTC/CWTS display only');

    // Applicability and statuses through the page's own endpoint.
    $bsit = $run($manager, 4);
    ok($bsit['status'] === 201, 'Run on the BSIT version');
    $bsitRun = $bsit['json']['run'];
    $titles = array_column($bsitRun['results'], 'rule_name_snapshot');
    ok(count($bsitRun['results']) === 5 && in_array('BSIT minimum total units (CMO 25 s.2015 Art. V Sec. 8.1)', $titles, true) && !array_filter($titles, fn($t) => str_contains($t, 'Senior High')) && !array_filter($titles, fn($t) => str_contains($t, 'units by component')), 'BSIT: the 146 rule + the four all-college rules; no DepEd rule, no display-only rule');
    $byTitle = array_column($bsitRun['results'], null, 'rule_name_snapshot');
    $units = $byTitle['BSIT minimum total units (CMO 25 s.2015 Art. V Sec. 8.1)'];
    ok($units['result_label'] === 'Not met' && str_contains($units['actual_value_snapshot'], 'units of all placements: 12') && str_contains($units['actual_value_snapshot'], 'units of required placements:') && str_contains($units['explanation'], 'Elective units count toward the total only up to the required amount.')
        && $units['reference_code'] === 'CMO 25 s.2015' && $units['issuing_body'] === 'CHED' && $units['section_label'] === 'Art. V Sec. 8.1' && $units['source_page'] === '10', 'BSIT 146: Not met, BOTH unit numbers, elective note, source code / body / section / page');
    $ge = $byTitle['General education minimum units (CMO 20 s.2013 Sec. 3)'];
    ok($ge['result_label'] === 'Cannot determine from stored data' && str_contains($ge['missing_data_text'], 'no category') && $ge['source_page'] === '5-6', 'GE: Cannot determine, the missing data is named (no stored category)');
    $nstp = $byTitle['NSTP 1 is a prerequisite of NSTP 2 (BCP reply)'];
    ok($nstp['result_label'] === 'Cannot determine from stored data' && $nstp['reference_code'] === null && $nstp['source_class'] === 'client_reply' && str_contains($nstp['source_label'], 'not a published policy') && str_contains($nstp['missing_data_text'], 'NSTP subjects cannot be identified'), 'NSTP prerequisite (client reply): Cannot determine, names the missing data, labelled as a reply');
    ok($bsitRun['not_met_count'] === 1 && $bsitRun['cannot_determine_count'] === 4 && $bsitRun['met_count'] === 0 && $bsitRun['pending_count'] === 0 && $bsitRun['rules_evaluated'] === 5, 'BSIT counts: 1 Not met, 4 Cannot determine, 0 Met, 0 pending');
    $bscs = $run($manager, 5)['json']['run'];
    ok(count($bscs['results']) === 4 && !array_filter($bscs['results'], fn($r) => str_contains($r['rule_name_snapshot'], 'BSIT minimum')), 'Non-BSIT college program: only the four all-college rules');
    $shs = $run($manager, 6);
    $shsRun = $shs['json']['run'];
    ok($shs['status'] === 201 && count($shsRun['results']) === 3 && array_unique(array_column($shsRun['results'], 'result_status')) === ['PENDING_BCP'] && array_unique(array_column($shsRun['results'], 'result_label')) === ['Pending BCP confirmation'], 'SHS strand: only the three pending rules, labelled "Pending BCP confirmation"');
    ok($shsRun['pending_count'] === 3 && $shsRun['met_count'] === 0 && $shsRun['not_met_count'] === 0 && $shsRun['cannot_determine_count'] === 0 && $shsRun['rules_evaluated'] === 0, 'Pending rules are counted apart and never as Met, Not met or Cannot determine');
    ok(str_contains($shs['json']['message'], '3 rules are pending BCP confirmation and not evaluated.'), 'Run message names the pending rules');
    $stored = $db->query('SELECT rules_evaluated,passed_count,failed_count,not_evaluated_count FROM compliance_validation_runs WHERE id=' . (int)$shsRun['id'])->fetch_assoc();
    ok(array_sum($stored) === 0, 'Stored run row: pending rules are not in the Met / Not met / Cannot determine columns');
    ok(!preg_match('/\bcompliant\b|official BCP|overall/i', json_encode([$bsit['json'], $shs['json']])), 'Library run responses: no verdict words');

    // No double counting, unconfirmed sources, deactivation.
    $db->query("INSERT INTO compliance_rules(compliance_reference_id,rule_name,rule_type,description,numeric_value,academic_program_id,technical_status,created_by) VALUES({$refIds['CMO 25 s.2015']},'Legacy BSIT 146','MIN_TOTAL_UNITS','',146,3,'active',1)");
    ok(count($run($manager, 4)['json']['run']['results']) === 5, 'A legacy custom rule with the same source, program and number is not counted twice');
    $db->query("UPDATE compliance_references SET verification_status='draft' WHERE id={$refIds['CMO 25 s.2015']}");
    ok(count($run($manager, 4)['json']['run']['results']) === 4, 'A library rule whose source is not confirmed is not checked');
    $db->query("UPDATE compliance_references SET verification_status='verified' WHERE id={$refIds['CMO 25 s.2015']}");
    $db->query("DELETE FROM compliance_rules WHERE rule_name='Legacy BSIT 146'");

    // Rule library list and the new endpoints.
    $all = $api($manager, 'rule_list', ['per_page' => 100]);
    $origins = array_count_values(array_column($all['json']['rules'], 'origin'));
    ok($all['status'] === 200 && ($origins['library'] ?? 0) === 10 && ($origins['custom'] ?? 0) === 4 && $all['json']['pagination']['total_records'] === 14, 'Rule list: library and custom rules together, each with an origin (10 + 4)');
    ok($api($manager, 'rule_list', ['origin' => 'library'])['json']['pagination']['total_records'] === 10 && $api($manager, 'rule_list', ['origin' => 'custom'])['json']['pagination']['total_records'] === 4 && $api($manager, 'rule_list', ['origin' => 'x'])['status'] === 422, 'Origin filter (and a bad value is refused)');
    ok($api($manager, 'rule_list', ['applies_to' => 'senior_high'])['json']['pagination']['total_records'] === 3 && $api($manager, 'rule_list', ['applies_to' => 'all_college'])['json']['pagination']['total_records'] === 5 && $api($manager, 'rule_list', ['applies_to' => 'program'])['json']['pagination']['total_records'] === 6, 'Applies-to filter: senior high 3, all college 5 (3 public + 2 reply), one program 6 (2 library + 4 custom)');
    ok($api($manager, 'rule_list', ['rule_type' => 'SHS_HOURS'])['json']['pagination']['total_records'] === 2 && $api($manager, 'rule_list', ['search' => 'nstp'])['json']['pagination']['total_records'] === 3 && $api($manager, 'rule_list', ['per_page' => 10])['json']['pagination']['total_pages'] === 2, 'Type filter, search and server-side paging over both tables');
    $lib = array_column($all['json']['rules'], null, 'rule_code')['CMO25-BSIT-MIN-UNITS'];
    $get = $api($manager, 'library_rule_get', ['id' => $lib['id']]);
    ok($get['status'] === 200 && $get['json']['rule']['reference_code'] === 'CMO 25 s.2015' && $get['json']['rule']['source_page'] === '10' && $api($manager, 'library_rule_get', ['id' => 999999])['status'] === 404, 'Library rule dialog data; unknown id is 404');
    ok($api($none, 'library_rule_get', ['id' => $lib['id']])['status'] === 403 && $api($viewer, 'library_rule_get', ['id' => $lib['id']])['status'] === 200, 'Library rule read needs compliance.view');
    ok($api($viewer, 'library_rule_active', ['id' => $lib['id'], 'active' => '0'], 'POST')['status'] === 403 && $api($manager, 'library_rule_active', ['id' => $lib['id'], 'active' => '0'], 'POST', '')['status'] === 403, 'Deactivating needs compliance.manage and a CSRF token');
    $before = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='COMPLIANCE_LIBRARY_RULE_ACTIVE'")->fetch_row()[0];
    $off = $api($manager, 'library_rule_active', ['id' => $lib['id'], 'active' => '0'], 'POST');
    ok($off['status'] === 200 && $off['json']['rule']['technical_status'] === 'inactive' && (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='COMPLIANCE_LIBRARY_RULE_ACTIVE'")->fetch_row()[0] === $before + 1, 'Manager deactivates a library rule (audited)');
    ok($api($manager, 'library_rule_active', ['id' => $lib['id'], 'active' => '7'], 'POST')['status'] === 422, 'A bad active flag is refused');
    ok(count($run($manager, 4)['json']['run']['results']) === 4, 'A deactivated library rule is not checked');
    $api($manager, 'library_rule_active', ['id' => $lib['id'], 'active' => '1'], 'POST');
    ok($row('CMO25-BSIT-MIN-UNITS')['title'] === 'BSIT minimum total units (CMO 25 s.2015 Art. V Sec. 8.1)' && (int)$row('CMO25-BSIT-MIN-UNITS')['active'] === 1 && json_decode($row('CMO25-BSIT-MIN-UNITS')['parameters_json'], true) === ['min_units' => 146], 'Library values are unchanged by deactivating and activating');

    // Read scope on the new results.
    $bsitRunId = (int)$db->query('SELECT MAX(id) FROM compliance_validation_runs WHERE curriculum_version_id=4')->fetch_row()[0];
    ok($api($restricted, 'run_get', ['id' => $bsitRunId])['status'] === 404 && $api($viewer, 'run_get', ['id' => $bsitRunId])['status'] === 200 && count($api($viewer, 'run_get', ['id' => $bsitRunId])['json']['run']['results']) === 4, 'run_get: library results follow the same read scope');

    // Consumers: Builder target, Dashboard, Export, CIMF.
    $svc = new CurriculumVersionService($db);
    $target = $svc->builderSummary(4)['target'];
    ok($target !== null && abs($target['value'] - 146) < 0.001 && str_contains($target['rule_name'], 'BSIT minimum total units'), 'Builder target: the library 146 rule when its source is verified');
    $db->query("INSERT INTO compliance_rules(compliance_reference_id,rule_name,rule_type,description,numeric_value,academic_program_id,technical_status,created_by) VALUES(1,'Legacy 120','MIN_TOTAL_UNITS','',120,3,'active',1)");
    ok(abs($svc->builderSummary(4)['target']['value'] - 146) < 0.001, 'Builder target: library rule wins over a legacy program rule (BSIT 146 not counted twice)');
    $db->query("UPDATE compliance_references SET verification_status='draft' WHERE id={$refIds['CMO 25 s.2015']}");
    ok(abs($svc->builderSummary(4)['target']['value'] - 120) < 0.001, 'Builder target: falls back to the legacy rule when the library rule\'s source is not verified');
    $db->query("UPDATE compliance_references SET verification_status='verified' WHERE id={$refIds['CMO 25 s.2015']}");
    $db->query("DELETE FROM compliance_rules WHERE rule_name='Legacy 120'");
    $latest4 = $run($manager, 4)['json']['run']; // latest run again with every rule active
    require_once __DIR__ . '/../dashboard/DashboardAnalyticsRepository.php';
    $failing = array_column((new DashboardAnalyticsRepository($db))->complianceFailures(null), 'id');
    ok((new DashboardAnalyticsRepository($db))->complianceFailureCount(null) >= 1 && in_array(4, array_map('intval', $failing), true) && !in_array(6, array_map('intval', $failing), true), 'Dashboard "Not met checks" reads the library result (BSIT version) and ignores a pending-only version');
    require_once __DIR__ . '/../modules/curriculum/services/CurriculumExportService.php';
    $dataset = (new CurriculumExportService($db))->dataset(['academic_program_id' => 5, 'curriculum_id' => 5, 'curriculum_version_id' => 6, 'include_compliance' => '1']);
    $exportRun = $dataset['latest_compliance_run'];
    ok($exportRun['pending_count'] === 3 && $exportRun['met_count'] === 0 && $exportRun['not_met_count'] === 0 && $exportRun['cannot_determine_count'] === 0, 'Export: the latest run carries the pending count apart from Met / Not met / Cannot determine');
    $built = (new CIMFGlobalContextBuilder($db))->build($profile, 'explain', ['run_id' => (int)$shsRun['id'], 'academic_program_id' => 5, 'curriculum_id' => 5, 'curriculum_version_id' => 6]);
    ok(($built['facts']['validation']['pending_count'] ?? null) === 3 && count($built['facts']['validation']['results']) === 3, 'CIMF context: pending count and library results');
    $built = (new CIMFGlobalContextBuilder($db))->build($profile, 'finding', ['run_id' => (int)$latest4['id'], 'finding_id' => array_column($latest4['results'], null, 'rule_name_snapshot')['BSIT minimum total units (CMO 25 s.2015 Art. V Sec. 8.1)']['id'], 'academic_program_id' => 3, 'curriculum_id' => 3, 'curriculum_version_id' => 4]);
    ok(count($built['facts']['validation']['results']) === 1, 'CIMF: a library finding can be selected by its id');
    $js2 = file_get_contents(__DIR__ . '/../modules/curriculum/assets/compliance-validator.js');
    ok(str_contains($js2, 'Pending BCP confirmation') && str_contains($js2, 'Page not recorded') && str_contains($js2, 'library_rule_active'), 'JS: pending chip, "Page not recorded", library dialog actions');

    // ---- Builder verification path for sources, and the retired code ----
    $js = file_get_contents(__DIR__ . '/../modules/curriculum/assets/compliance-validator.js');
    ok(!preg_match('/Internal Compliance Status|\bcompliant\b|official BCP/i', $js . file_get_contents(__DIR__ . '/../modules/curriculum/pages/compliance_validator.php')), 'Page code: no old status wording, no verdict word');
    $css = file_get_contents(__DIR__ . '/../modules/curriculum/assets/compliance-validator.css');
    ok(str_contains($css, '.csms-tabs + .mapping-context-bar { margin-top: 16px; }') && str_contains(file_get_contents(__DIR__ . '/../modules/curriculum/assets/electives-manager.css'), '.mapping-context-bar + .csms-tabs { margin-top: 16px; }'), 'Tabs and context bar keep a 16px gap, the same value Electives Manager uses (the screenshot check measures the bounding boxes)');
    ok(str_contains($js, 'Cannot determine from stored data') && str_contains($js, 'Source confirmed'), 'JS uses the new wording');
    ok(str_contains($js, "const EVIDENCE_TEXT = 'Evidence is a locator and fingerprint recorded by a person. It is not a stored document.'") && str_contains($js, "evidenceHead('evidenceTipDetail'") && str_contains($js, "evidenceHead('evidenceTipForm'"), 'The evidence sentence lives in the source detail dialog and the add/edit source dialog as a csms-info');
} finally {
    foreach ($clients as $c) $c->close();
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($created && preg_match('/^csms_cv_test_[a-f0-9]{16}$/D', $name)) $db->query("DROP DATABASE `$name`");
    $db->close();
    $logText = file_get_contents($log); unlink($log); foreach (glob($sessionDir . '/sess_*') as $file) unlink($file); rmdir($sessionDir);
}
ok(!preg_match('/PHP (Warning|Fatal|Notice|Parse error|Deprecated)/', $logText), 'No PHP errors in HTTP server log' . (preg_match('/PHP (Warning|Fatal|Notice|Parse error|Deprecated)[^\n]*/', $logText, $m) ? ': ' . $m[0] : ''));
echo "PASS: $checks isolated HTTP checks for the Compliance Validator; disposable server and schema removed.\n";
