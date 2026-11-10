<?php
// "Assign all placed" over real HTTP: permission, CSRF, one transaction, audit, placements untouched.
// sms_db_uitest only. It creates disposable RCNT-* rows through SQL, calls the real endpoints, and removes the rows afterwards
// (audit_logs is append-only, so its entries stay).   DB_NAME=sms_db_uitest php tests/program-assignment-reconcile-http.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('DB_NAME') !== 'sms_db_uitest') { fwrite(STDERR, "Refusing: set DB_NAME=sms_db_uitest\n"); exit(1); }
require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/FoundationHttpClient.php';
$db = csms_database_connect(csms_database_config());
$creds = [];
foreach (file(__DIR__ . '/../.env.uitest', FILE_IGNORE_NEW_LINES) as $line) if (preg_match('/^(UITEST_\w+)=(.*)$/', $line, $m)) $creds[$m[1]] = $m[2];
$port = 8097;
$log = tempnam(sys_get_temp_dir(), 'rcnt');
$env = getenv(); $env['APP_ENV'] = 'development'; $env['SMS_SESSION_COOKIE_SECURE'] = '0'; $env['CIMF_AI_ENABLED'] = 'false';
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__)], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env);
for ($i = 0; $i < 40; $i++) { $c = @stream_socket_client("tcp://127.0.0.1:$port", $e, $s, .2); if ($c) { fclose($c); break; } usleep(100000); }
$base = "http://127.0.0.1:$port";
$A = 'modules/curriculum/http/program_assignment_actions.php';
$page = 'modules/curriculum/pages/program_centric_assignment.php';
$checks = 0;
function check(bool $ok, string $m): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $m); $checks++; }
function login(string $role, string $base, array $creds): FoundationHttpClient {
    $c = new FoundationHttpClient($base); $c->request('auth/signin.php');
    $r = $c->request('shared/auth_actions.php', ['action' => 'login', 'username' => $creds["UITEST_{$role}_USER"], 'password' => $creds["UITEST_{$role}_PASS"]], 'POST');
    check($r['status'] === 200, "login $role"); $c->request($GLOBALS['page']); return $c;
}
$one = fn(string $sql) => (int)$db->query($sql)->fetch_row()[0];
$ids = [];
try {
    // ---- fixture: program P (active) with curriculum C and versions V1, V2; subjects A (assigned), B, C, D (inactive) placed in V1; E only in V2 ----
    $db->query("INSERT INTO academic_programs(program_code,program_name,program_type) VALUES('RCNT-P','Reconcile Test','College')"); $p = $db->insert_id;
    $db->query("INSERT INTO curricula(curriculum_code,curriculum_name,academic_program_id) VALUES('RCNT-C','Reconcile Curriculum',$p)"); $c = $db->insert_id;
    $db->query("INSERT INTO curriculum_versions(curriculum_id,version_label) VALUES($c,'rv1'),($c,'rv2')"); $v1 = $db->insert_id; $v2 = $v1 + 1;
    $s = [];
    foreach (['A', 'B', 'C', 'D', 'E'] as $l) { $db->query("INSERT INTO subjects(subject_code,subject_title,units) VALUES('RCNT-$l','Reconcile $l',3)"); $s[$l] = $db->insert_id; }
    $db->query("UPDATE subjects SET status='inactive' WHERE id={$s['D']}");
    $db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES($v1,{$s['A']},'1st Year','1st Semester'),($v1,{$s['B']},'1st Year','2nd Semester'),($v1,{$s['C']},'2nd Year','1st Semester'),($v1,{$s['D']},'2nd Year','2nd Semester'),($v2,{$s['E']},'1st Year','1st Semester'),($v2,{$s['A']},'4th Year','2nd Semester')");
    $db->query("INSERT INTO program_subject_assignments(academic_program_id,subject_id,status) VALUES($p,{$s['A']},'active')");
    $q = ['program_id' => $p, 'curriculum_id' => $c, 'version_id' => $v1];
    $placements = fn() => $one("SELECT COUNT(*) FROM curriculum_version_subjects WHERE curriculum_version_id IN ($v1,$v2)");
    $versions = fn() => hash('sha256', json_encode($db->query("SELECT * FROM curriculum_versions WHERE id IN ($v1,$v2) ORDER BY id")->fetch_all(MYSQLI_ASSOC)));
    $assignedCount = fn() => $one("SELECT COUNT(*) FROM program_subject_assignments WHERE academic_program_id=$p AND status='active'");
    $audit = fn(string $a) => $one("SELECT COUNT(*) FROM audit_logs WHERE action='$a' AND JSON_EXTRACT(metadata_json,'$.academic_program_id')=$p");
    $before = [$placements(), $versions()];

    // ---- permission: no program_assignment.manage means no preview and no reconcile ----
    foreach (['STAFF', 'ACADOFFICE'] as $role) {
        $r = login($role, $base, $creds);
        check($r->request($A, ['action' => 'reconcile_preview'] + $q)['status'] === 403, "$role cannot preview");
        check($r->request($A, ['action' => 'reconcile'] + $q, 'POST')['status'] === 403, "$role cannot reconcile");
    }
    check($assignedCount() === 1, 'denied roles changed nothing');

    $sa = login('SUPERADMIN', $base, $creds);
    // ---- CSRF ----
    check(in_array($sa->request($A, ['action' => 'reconcile'] + $q, 'POST', '')['status'], [403, 419], true), 'missing CSRF token is rejected');
    check(in_array($sa->request($A, ['action' => 'reconcile'] + $q, 'POST', 'not-the-token')['status'], [403, 419], true), 'wrong CSRF token is rejected');
    check($sa->request($A, ['action' => 'reconcile'] + $q)['status'] === 405, 'reconcile is POST only');
    check($assignedCount() === 1, 'rejected requests changed nothing');

    // ---- validation: curriculum and version must belong together ----
    $other = $sa->request($A, ['action' => 'reconcile', 'program_id' => $p, 'curriculum_id' => $c, 'version_id' => $one('SELECT MIN(id) FROM curriculum_versions WHERE id NOT IN (' . "$v1,$v2" . ')')], 'POST');
    check($other['status'] === 422 && $assignedCount() === 1, 'a version outside the program and curriculum is refused');

    // ---- preview ----
    $pv = $sa->request($A, ['action' => 'reconcile_preview'] + $q)['json']['preview'];
    $codes = fn(array $rows) => array_column($rows, 'subject_code');
    check($codes($pv['assignable']) === ['RCNT-B', 'RCNT-C'] && $codes($pv['skipped_inactive']) === ['RCNT-D'], 'preview: B and C assignable, D skipped as inactive: ' . json_encode([$codes($pv['assignable']), $codes($pv['skipped_inactive'])]));
    check($assignedCount() === 1, 'preview writes nothing');
    $cov = $sa->request($A, ['action' => 'context', 'type' => 'program', 'id' => $p, 'version_id' => $v1])['json']['record'];
    check($cov['placed_not_assigned'] === 3 && $cov['assignable_count'] === 2, 'coverage carries the assignable count (button label)');

    // ---- reconcile ----
    $r = $sa->request($A, ['action' => 'reconcile'] + $q, 'POST');
    check($r['status'] === 201 && $r['json']['message'] === 'Assigned 2. Skipped 1 inactive.', 'result message: ' . $r['body']);
    check($assignedCount() === 3 && $one("SELECT COUNT(*) FROM program_subject_assignments WHERE academic_program_id=$p AND subject_id IN ({$s['B']},{$s['C']}) AND status='active'") === 2, 'B and C assigned');
    check($one("SELECT COUNT(*) FROM program_subject_assignments WHERE subject_id IN ({$s['D']},{$s['E']})") === 0, 'inactive D and other-version E were not assigned');
    check([$placements(), $versions()] === $before, 'placements and versions unchanged');
    check($audit('PROGRAM_SUBJECT_ASSIGN') === 2 && $audit('PROGRAM_SUBJECT_RECONCILE') === 1, 'audit: 2 assignment entries and 1 summary');
    // ---- repeat ----
    $again = $sa->request($A, ['action' => 'reconcile'] + $q, 'POST');
    check($again['status'] === 200 && $again['json']['message'] === 'Assigned 0. Skipped 1 inactive.' && $assignedCount() === 3 && $audit('PROGRAM_SUBJECT_ASSIGN') === 2 && $audit('PROGRAM_SUBJECT_RECONCILE') === 1, 'second run changes nothing and logs nothing');

    // ---- unassign message names the blocking curricula and versions ----
    $aId = $one("SELECT id FROM program_subject_assignments WHERE academic_program_id=$p AND subject_id={$s['A']}");
    $rm = $sa->request($A, ['action' => 'remove', 'id' => $aId], 'POST');
    check($rm['status'] === 409 && str_contains($rm['json']['message'], 'RCNT-C / rv1, 1st Year 1st Semester') && str_contains($rm['json']['message'], 'RCNT-C / rv2, 4th Year 2nd Semester'), 'unassign names both blocking versions: ' . $rm['body']);
    check($assignedCount() === 3, 'blocked unassign changed nothing');
    echo "program-assignment-reconcile-http: $checks checks passed\n";
} finally {
    if (isset($p)) {
        $db->query("DELETE FROM program_subject_assignments WHERE academic_program_id=$p");
        if (isset($v1)) $db->query("DELETE FROM curriculum_version_subjects WHERE curriculum_version_id IN ($v1," . ($v1 + 1) . ')');
        if (isset($c)) { $db->query("DELETE FROM curriculum_versions WHERE curriculum_id=$c"); $db->query("DELETE FROM curricula WHERE id=$c"); }
        $db->query("DELETE FROM subjects WHERE subject_code LIKE 'RCNT-%'");
        $db->query("DELETE FROM academic_programs WHERE id=$p");
    }
    proc_terminate($server);
}
