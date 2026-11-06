<?php
// Curriculum Map editing paths over HTTP. Runs ONLY against sms_db_uitest (own PHP server on 127.0.0.1:8091); never touches sms_db.
// Usage: & C:\xampp\php\php.exe tests\subject-mapping-map-http.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/FoundationHttpClient.php';
const TEST_DB = 'sms_db_uitest';
putenv('DB_NAME=' . TEST_DB);
$_ENV['DB_NAME'] = TEST_DB;
require_once __DIR__ . '/../shared/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if ($conn->query('SELECT DATABASE()')->fetch_row()[0] !== TEST_DB) { fwrite(STDERR, "BLOCKED: not connected to " . TEST_DB . "\n"); exit(2); }
$env = [];
foreach (file(__DIR__ . '/../.env.uitest', FILE_IGNORE_NEW_LINES) as $line) {
    if (preg_match('/^(UITEST_[A-Z]+_(?:USER|PASS))=(.*)$/', $line, $m)) $env[$m[1]] = $m[2];
}
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$one = static fn(string $sql) => $conn->query($sql)->fetch_row()[0] ?? null;

$check((int)$one('SELECT COUNT(*) FROM curriculum_outcome_catalog') >= 13, 'BSIT outcome catalog is not loaded in ' . TEST_DB);
$version = (int)$one("SELECT v.id FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id JOIN academic_programs p ON p.id=c.academic_program_id WHERE p.program_code='BSIT' AND v.version_role='unclassified' AND v.review_status='not_started' AND v.baseline_version_id IS NULL AND EXISTS (SELECT 1 FROM curriculum_version_subjects s WHERE s.curriculum_version_id=v.id) ORDER BY v.id LIMIT 1");
$placement = (int)$one("SELECT p.id FROM curriculum_version_subjects p WHERE p.curriculum_version_id=$version AND NOT EXISTS (SELECT 1 FROM curriculum_version_subject_outcomes m WHERE m.curriculum_version_subject_id=p.id) ORDER BY p.id LIMIT 1");
$outcome = (int)$one('SELECT id FROM curriculum_outcome_catalog ORDER BY source_order LIMIT 1');
$check($version > 0 && $placement > 0 && $outcome > 0, 'Fixture missing: needs an editable BSIT version with an unmapped placement.');
$nonBsit = (int)$one("SELECT v.id FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id JOIN academic_programs p ON p.id=c.academic_program_id WHERE p.program_code<>'BSIT' ORDER BY v.id LIMIT 1");
$actor = (int)$one('SELECT id FROM users ORDER BY id LIMIT 1');

$log = sys_get_temp_dir() . '/map-http.log';
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:8091', '-t', dirname(__DIR__, 2)], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $pipes, null, ['DB_NAME' => TEST_DB, 'SMS_SESSION_COOKIE_SECURE' => '0', 'CIMF_AI_ENABLED' => 'false'] + getenv(), ['bypass_shell' => true]);
$cleanup = [];
$restoreRole = null;
try {
    for ($i = 0; $i < 60; $i++) { if (@fsockopen('127.0.0.1', 8091, $en, $es, .2)) break; usleep(100000); }
    $base = 'http://127.0.0.1:8091/' . basename(dirname(__DIR__));
    $login = static function (string $role) use ($base, $env, $check): FoundationHttpClient {
        $c = new FoundationHttpClient($base);
        $c->request('auth/signin.php');
        $r = $c->request('shared/auth_actions.php', ['action' => 'login', 'username' => $env["UITEST_{$role}_USER"], 'password' => $env["UITEST_{$role}_PASS"]], 'POST');
        $check($r['status'] === 200, "Login failed for $role");
        $c->request('modules/curriculum/pages/subject_mapping.php');
        return $c;
    };
    $editor = $login('SUPERADMIN');
    $staff = $login('STAFF');
    $viewer = $login('ACADOFFICE');
    $body = ['curriculum_version_id' => $version, 'placement_id' => $placement, 'outcome_id' => $outcome, 'rationale' => 'Map HTTP test rationale.'];

    // Read: the workspace carries what the matrix needs; editable is true on this version.
    $r = $editor->api('outcome_mapping', 'workspace', ['curriculum_version_id' => $version]);
    $check($r['status'] === 200 && count($r['json']['outcomes']) >= 13 && $r['json']['context']['editable'] === true, 'Workspace read for editor failed.');
    $check(isset($r['json']['placements'][0]['placement_id']), 'Workspace placements lost placement_id.');
    $check($viewer->api('outcome_mapping', 'workspace', ['curriculum_version_id' => $version])['status'] === 200, 'Read-only role could not read the workspace.');
    // Write permission: only curriculum.outcome_mapping.manage.
    $check($viewer->api('outcome_mapping', 'add', $body, 'POST')['status'] === 403, 'Read-only role was allowed to add a mapping.');
    // The rationale is required (empty and whitespace only).
    foreach (['', '   '] as $blank) {
        $r = $editor->api('outcome_mapping', 'add', ['rationale' => $blank] + $body, 'POST');
        $check(in_array($r['status'], [400, 422], true), 'Blank rationale was accepted: ' . $r['status']);
    }
    $check((int)$one("SELECT COUNT(*) FROM curriculum_version_subject_outcomes WHERE curriculum_version_subject_id=$placement") === 0, 'A refused add still wrote a row.');
    // Add (Staff holds the permission too); a duplicate is refused.
    $r = $staff->api('outcome_mapping', 'add', $body, 'POST');
    $check($r['status'] === 201, 'Editor add failed: ' . $r['status'] . ' ' . $r['body']);
    $mapping = (int)$r['json']['mapping_id'];
    $cleanup[] = "DELETE FROM curriculum_version_clo_outcome_alignments WHERE parent_mapping_id=$mapping OR source_mapping_id_snapshot=$mapping";
    $cleanup[] = "DELETE FROM curriculum_version_course_learning_outcomes WHERE clo_code='MAPHTTP' AND curriculum_version_subject_id=$placement";
    $cleanup[] = "DELETE FROM curriculum_version_subject_outcomes WHERE id=$mapping";
    $r = $editor->api('outcome_mapping', 'add', $body, 'POST');
    $check(in_array($r['status'], [409, 422], true), 'Duplicate mapping was accepted: ' . $r['status']);
    $w = $editor->api('outcome_mapping', 'workspace', ['curriculum_version_id' => $version])['json'];
    $row = array_values(array_filter($w['mappings'], static fn($m) => (int)$m['id'] === $mapping))[0] ?? null;
    $check($row && (int)$row['placement_id'] === $placement && (int)$row['outcome_id'] === $outcome && $row['rationale'] === 'Map HTTP test rationale.', 'Added mapping missing from the workspace.');
    // Remove is refused while an active CLO alignment depends on it; the server message comes back.
    $conn->query("INSERT INTO curriculum_version_course_learning_outcomes (curriculum_version_subject_id,clo_code,clo_statement,sequence_order,created_by) VALUES ($placement,'MAPHTTP','Map HTTP test CLO',1,$actor)");
    $clo = (int)$conn->insert_id;
    $conn->query("INSERT INTO curriculum_version_clo_outcome_alignments (clo_id,curriculum_version_subject_id,parent_mapping_id,source_mapping_id_snapshot,outcome_id,rationale,created_by) VALUES ($clo,$placement,$mapping,$mapping,$outcome,'Map HTTP test alignment',$actor)");
    $r = $editor->api('outcome_mapping', 'remove', ['curriculum_version_id' => $version, 'mapping_id' => $mapping], 'POST');
    $check($r['status'] === 409 && str_contains((string)$r['json']['message'], 'Withdraw dependent CLO alignments'), 'Dependent-CLO refusal missing: ' . $r['status'] . ' ' . $r['body']);
    $check((int)$one("SELECT COUNT(*) FROM curriculum_version_subject_outcomes WHERE id=$mapping") === 1, 'Refused remove deleted the mapping.');
    $check($viewer->api('outcome_mapping', 'remove', ['curriculum_version_id' => $version, 'mapping_id' => $mapping], 'POST')['status'] === 403, 'Read-only role removed a mapping.');
    // Once the dependency is gone, remove works.
    $conn->query("DELETE FROM curriculum_version_clo_outcome_alignments WHERE parent_mapping_id=$mapping");
    $conn->query("DELETE FROM curriculum_version_course_learning_outcomes WHERE id=$clo");
    $r = $editor->api('outcome_mapping', 'remove', ['curriculum_version_id' => $version, 'mapping_id' => $mapping], 'POST');
    $check($r['status'] === 200, 'Remove failed: ' . $r['status'] . ' ' . $r['body']);
    // Non-BSIT program: catalog refusal.
    if ($nonBsit) {
        $r = $editor->api('outcome_mapping', 'workspace', ['curriculum_version_id' => $nonBsit]);
        $check(in_array($r['status'], [400, 422], true) && str_contains((string)$r['json']['message'], 'only for BSIT'), 'Non-BSIT catalog refusal missing: ' . $r['status']);
    }
    // A reference version is not editable: editable=false and the server refuses with 409.
    $restoreRole = [$version, (string)$one("SELECT version_role FROM curriculum_versions WHERE id=$version")];
    $conn->query("UPDATE curriculum_versions SET version_role='reference' WHERE id=$version");
    $ctx = $editor->api('outcome_mapping', 'workspace', ['curriculum_version_id' => $version])['json']['context'] ?? [];
    $check(($ctx['editable'] ?? null) === false, 'Reference version reported as editable.');
    $r = $editor->api('outcome_mapping', 'add', $body, 'POST');
    $check($r['status'] === 409, 'Add on a reference version returned ' . $r['status']);
    $conn->query("UPDATE curriculum_versions SET version_role='{$restoreRole[1]}' WHERE id={$restoreRole[0]}");
    $restoreRole = null;
    echo 'PASS: Curriculum Map editing over HTTP on ' . TEST_DB . ": read, RBAC (403 read-only), required rationale, add, duplicate refusal, dependent-CLO removal refusal with server message, remove, non-BSIT refusal, reference version refused (409).\n";
} finally {
    if ($restoreRole) $conn->query("UPDATE curriculum_versions SET version_role='{$restoreRole[1]}' WHERE id={$restoreRole[0]}");
    foreach ($cleanup as $sql) { try { $conn->query($sql); } catch (Throwable $e) {} }
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
}
