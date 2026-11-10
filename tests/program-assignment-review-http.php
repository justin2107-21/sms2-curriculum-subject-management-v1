<?php
// CIMF Assignment Review over the real global endpoint: permission, findings with the AI off, nothing written.
// sms_db_uitest only. Disposable RCV-* rows are created by SQL and removed afterwards.
//   DB_NAME=sms_db_uitest php tests/program-assignment-review-http.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('DB_NAME') !== 'sms_db_uitest') { fwrite(STDERR, "Refusing: set DB_NAME=sms_db_uitest\n"); exit(1); }
require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/FoundationHttpClient.php';
$db = csms_database_connect(csms_database_config());
$creds = [];
foreach (file(__DIR__ . '/../.env.uitest', FILE_IGNORE_NEW_LINES) as $line) if (preg_match('/^(UITEST_\w+)=(.*)$/', $line, $m)) $creds[$m[1]] = $m[2];
$port = 8098;
$log = tempnam(sys_get_temp_dir(), 'rcv');
// The AI is switched off for this server: the review must still answer with the deterministic findings. No key is read or printed.
$env = getenv(); $env['APP_ENV'] = 'development'; $env['SMS_SESSION_COOKIE_SECURE'] = '0'; $env['CIMF_AI_ENABLED'] = 'false';
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__)], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env);
for ($i = 0; $i < 40; $i++) { $c = @stream_socket_client("tcp://127.0.0.1:$port", $e, $s, .2); if ($c) { fclose($c); break; } usleep(100000); }
$base = "http://127.0.0.1:$port";
$page = 'modules/curriculum/pages/program_centric_assignment.php';
$checks = 0;
function check(bool $ok, string $m): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $m); $checks++; }
function attr(string $html, string $name): string { return preg_match('/' . preg_quote($name, '/') . '="([^"]*)"/', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : ''; }
function review(string $role, string $base, array $creds, array $context, string $action = 'review'): array {
    $c = new FoundationHttpClient($base); $c->request('auth/signin.php');
    check($c->request('shared/auth_actions.php', ['action' => 'login', 'username' => $creds["UITEST_{$role}_USER"], 'password' => $creds["UITEST_{$role}_PASS"]], 'POST')['status'] === 200, "login $role");
    $html = $c->request($GLOBALS['page'])['body'];
    return $c->request('modules/curriculum/http/global_cimf_actions.php', ['operation' => 'analyze', 'action' => $action, 'profile' => attr($html, 'data-profile'), 'route' => attr($html, 'data-route'), 'page_token' => attr($html, 'data-page-token'), 'context' => json_encode($context)], 'POST');
}
$one = fn(string $sql) => (int)$db->query($sql)->fetch_row()[0];
try {
    $db->query("INSERT INTO academic_programs(program_code,program_name,program_type) VALUES('RCV-P','Review Test','College')"); $p = $db->insert_id;
    $db->query("INSERT INTO curricula(curriculum_code,curriculum_name,academic_program_id) VALUES('RCV-C','Review Curriculum',$p)"); $c = $db->insert_id;
    $db->query("INSERT INTO curriculum_versions(curriculum_id,version_label) VALUES($c,'rv1')"); $v = $db->insert_id;
    $s = [];
    foreach (['A', 'B', 'C', 'D'] as $l) { $db->query("INSERT INTO subjects(subject_code,subject_title,units) VALUES('RCV-$l','Review $l',3)"); $s[$l] = $db->insert_id; }
    $db->query("UPDATE subjects SET status='inactive' WHERE id={$s['C']}");
    // A placed + unassigned (active), B assigned + placed nowhere, C placed + unassigned (inactive), D assigned + placed.
    $db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES($v,{$s['A']},'1st Year','1st Semester'),($v,{$s['C']},'1st Year','1st Semester'),($v,{$s['D']},'2nd Year','1st Semester')");
    $db->query("INSERT INTO program_subject_assignments(academic_program_id,subject_id,status) VALUES($p,{$s['B']},'active'),($p,{$s['D']},'active')");
    $tables = ['program_subject_assignments', 'curriculum_version_subjects', 'curriculum_versions', 'curricula', 'academic_programs', 'subjects', 'audit_logs', 'cimf_ai_logs'];
    $snapshot = function () use ($db, $tables, $p, $v): string { $h = []; foreach ($tables as $t) $h[$t] = $db->query("SELECT COUNT(*), COALESCE(MAX(id),0) FROM `$t`" . ($t === 'audit_logs' ? " WHERE action NOT LIKE 'AUTH\_%'" : ''))->fetch_row(); return json_encode($h) . hash('sha256', json_encode($db->query("SELECT * FROM program_subject_assignments WHERE academic_program_id=$p ORDER BY id")->fetch_all(MYSQLI_ASSOC)) . json_encode($db->query("SELECT * FROM curriculum_version_subjects WHERE curriculum_version_id=$v ORDER BY id")->fetch_all(MYSQLI_ASSOC))); };
    $ctx = ['view' => 'program', 'academic_program_id' => $p, 'curriculum_id' => $c, 'curriculum_version_id' => $v];

    $before = $snapshot();
    $r = review('SUPERADMIN', $base, $creds, $ctx);
    check($r['status'] === 200 && ($r['json']['success'] ?? false) && ($r['json']['source'] ?? '') === 'CSMS findings' && !isset($r['json']['analysis']), 'AI off: the review answers with CSMS findings alone: ' . substr($r['body'], 0, 200));
    check(!empty($r['json']['notice']) && str_contains($r['json']['notice'], 'disabled'), 'a clear notice says why there is no AI explanation: ' . ($r['json']['notice'] ?? ''));
    $f = array_column($r['json']['deterministic']['findings'], null, 'key');
    check(array_column($f['placed_not_assigned_active']['subjects'], 'code') === ['RCV-A'] && array_column($f['placed_not_assigned_inactive']['subjects'], 'code') === ['RCV-C'] && array_column($f['assigned_not_placed']['subjects'], 'code') === ['RCV-B'] && array_column($f['inactive_placed']['subjects'], 'code') === ['RCV-C'] && array_column($f['assigned_placed_nowhere']['subjects'], 'code') === ['RCV-B'], 'every finding lists the right codes');
    check($snapshot() === $before, 'the review wrote nothing: assignments, placements, versions, subjects, audit log (apart from the sign-in entry) and AI usage log are unchanged');
    // Other actions and a read-only role use the same read-only path.
    $ex = review('STAFF', $base, $creds, $ctx, 'explain');
    check($ex['status'] === 200 && ($ex['json']['source'] ?? '') === 'CSMS findings', 'Staff (program_assignment.view) can review');
    check(review('ACADFACULTY', $base, $creds, $ctx)['status'] === 403, 'a role without program_assignment.view is refused');
    check($snapshot() === $before, 'still nothing written after the other roles');
    // Context checks.
    $bad = review('SUPERADMIN', $base, $creds, ['view' => 'program', 'academic_program_id' => $p, 'curriculum_id' => $c + 1000, 'curriculum_version_id' => $v]);
    check($bad['status'] === 422, 'a curriculum that does not match the version is refused (422)');
    $noVersion = review('SUPERADMIN', $base, $creds, ['view' => 'program', 'academic_program_id' => $p]);
    check($noVersion['status'] === 200 && !empty($noVersion['json']['deterministic']['notice']) && array_column(array_column($noVersion['json']['deterministic']['findings'], null, 'key')['assigned_placed_nowhere']['subjects'], 'code') === ['RCV-B'], 'without a version only the assignment-only finding is shown, with a notice');
    check($snapshot() === $before, 'still nothing written');
    echo "program-assignment-review-http: $checks checks passed\n";
} finally {
    if (isset($p)) {
        $db->query("DELETE FROM program_subject_assignments WHERE academic_program_id=$p");
        if (isset($v)) $db->query("DELETE FROM curriculum_version_subjects WHERE curriculum_version_id=$v");
        if (isset($c)) { $db->query("DELETE FROM curriculum_versions WHERE curriculum_id=$c"); $db->query("DELETE FROM curricula WHERE id=$c"); }
        $db->query("DELETE FROM subjects WHERE subject_code LIKE 'RCV-%'");
        $db->query("DELETE FROM academic_programs WHERE id=$p");
    }
    proc_terminate($server);
}
