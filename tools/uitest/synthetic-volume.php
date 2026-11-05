<?php
// SYNTHETIC VOLUME FOR LOAD TESTING. Runs only against sms_db_uitest.
// Every row it adds is prefixed SYN- (codes, labels) or "SYN synthetic" (audit text) so it cannot be mistaken for real data.
// Remove it by rebuilding the test database: powershell -File tools\uitest\setup-db.ps1, then php tools/uitest/seed-users.php.
// Usage: DB_NAME=sms_db_uitest php tools/uitest/synthetic-volume.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('DB_NAME') !== 'sms_db_uitest') { fwrite(STDERR, "Refusing: set DB_NAME=sms_db_uitest\n"); exit(1); }
require_once __DIR__ . '/guard.php';
csms_uitest_database(getenv('DB_NAME'));
require_once __DIR__ . '/../../shared/database_config.php';
$db = csms_database_connect(csms_database_config());
if (in_array('--remove', $argv, true)) {
    // Removes only SYN- rows, children first. The usual cleanup is rebuilding the test database.
    $v = "(SELECT id FROM curriculum_versions WHERE version_label LIKE 'SYN-V%')";
    foreach (["DELETE FROM curriculum_version_review_events WHERE curriculum_version_id IN $v", "DELETE FROM compliance_validation_runs WHERE curriculum_version_id IN $v",
        "DELETE FROM curriculum_subject_requirements WHERE curriculum_version_id IN $v", "DELETE FROM curriculum_version_subjects WHERE curriculum_version_id IN $v",
        "DELETE FROM audit_logs WHERE description LIKE 'SYN synthetic%'", "DELETE FROM curriculum_versions WHERE version_label LIKE 'SYN-V%'",
        "DELETE FROM curricula WHERE curriculum_code LIKE 'SYN-C%'", "DELETE FROM academic_programs WHERE program_code LIKE 'SYN-P%'"] as $sql) $db->query($sql);
    echo "Synthetic rows removed.
"; exit(0);
}
if ((int)$db->query("SELECT COUNT(*) FROM academic_programs WHERE program_code LIKE 'SYN-%'")->fetch_row()[0] > 0) { fwrite(STDERR, "Synthetic data already present; rebuild the test database first.\n"); exit(1); }

const PROGRAMS = 60, CURRICULA = 300, VERSIONS = 500, PLACEMENTS = 20000, AUDIT = 50000, EVENTS = 2000;
$user = static fn(string $n): int => (int)$db->query("SELECT id FROM users WHERE username='$n'")->fetch_row()[0];
$staff = $user('uitest.staff'); $office = $user('uitest.acadoffice'); $admin = $user('uitest.admin');
$q = static fn(string $s): string => "'" . str_replace("'", "''", $s) . "'";
$bulk = static function (string $head, array $rows) use ($db): void {
    foreach (array_chunk($rows, 1000) as $chunk) $db->query($head . implode(',', $chunk));
};
$t0 = microtime(true);

// Programs: 50 college, 10 senior high.
$rows = [];
for ($i = 1; $i <= PROGRAMS; $i++) $rows[] = '(' . $q(sprintf('SYN-P%02d', $i)) . ',' . $q("SYN synthetic program $i") . ',' . $q($i <= 50 ? 'College' : 'Senior High School') . ",'active',$admin)";
$bulk('INSERT INTO academic_programs(program_code,program_name,program_type,status,created_by) VALUES', $rows);
$programIds = array_column($db->query("SELECT id FROM academic_programs WHERE program_code LIKE 'SYN-P%' ORDER BY program_code")->fetch_all(MYSQLI_ASSOC), 'id');

// Curricula: 5 per program.
$rows = [];
for ($i = 1; $i <= CURRICULA; $i++) $rows[] = '(' . $q(sprintf('SYN-C%04d', $i)) . ',' . $q("SYN synthetic curriculum $i") . ',' . (int)$programIds[intdiv($i - 1, 5)] . ",'active',$admin)";
$bulk('INSERT INTO curricula(curriculum_code,curriculum_name,academic_program_id,status,created_by) VALUES', $rows);
$curriculumIds = array_column($db->query("SELECT id FROM curricula WHERE curriculum_code LIKE 'SYN-C%' ORDER BY curriculum_code")->fetch_all(MYSQLI_ASSOC), 'id');

// Versions: every curriculum has one, the first 200 have a second. The first 400 versions go through review.
$spec = [];
for ($i = 0; $i < VERSIONS; $i++) {
    $curriculum = $curriculumIds[$i < CURRICULA ? $i : $i - CURRICULA];
    $reviewed = $i < 400;
    $approved = $reviewed && $i % 2 === 0;
    $status = !$reviewed ? ['draft', 'active', 'archived'][$i % 3] : ($approved ? 'active' : 'draft');
    $review = !$reviewed ? 'not_started' : ($approved ? 'approved' : 'under_review');
    $spec[] = ['curriculum' => (int)$curriculum, 'label' => sprintf('SYN-V%04d', $i + 1), 'status' => $status, 'review' => $review];
}
$bulk('INSERT INTO curriculum_versions(curriculum_id,version_label,effective_academic_year,status,version_role,review_status,created_by,updated_at) VALUES',
    array_map(static fn($s, $i) => '(' . $s['curriculum'] . ',' . $q($s['label']) . ",'2026-2027'," . $q($s['status']) . ",'unclassified'," . $q($s['review']) . ",$staff," . $q(date('Y-m-d H:i:s', strtotime('-' . ($i % 300) . ' days'))) . ')', $spec, array_keys($spec)));
$versionIds = array_column($db->query("SELECT id FROM curriculum_versions WHERE version_label LIKE 'SYN-V%' ORDER BY version_label")->fetch_all(MYSQLI_ASSOC), 'id');

// Placements: 40 per version across 4 years x 2 semesters, from the 55 existing subjects.
$subjects = array_column($db->query('SELECT id FROM subjects ORDER BY id LIMIT 55')->fetch_all(MYSQLI_ASSOC), 'id');
$years = ['1st Year', '2nd Year', '3rd Year', '4th Year']; $terms = ['1st Semester', '2nd Semester'];
$rows = []; $edges = [];
foreach ($versionIds as $vi => $versionId) {
    for ($k = 0; $k < 40; $k++) {
        $subject = (int)$subjects[($vi + $k) % count($subjects)];
        $rows[] = "($versionId,$subject," . $q($years[intdiv($k, 10)]) . ',' . $q($terms[intdiv($k % 10, 5)]) . ',' . ($k % 5 + 1) . ',' . ($k % 7 === 0 ? 0 : 1) . ",$staff)";
        // Every subject past the first term needs the subject placed ten slots earlier (a valid, earlier term).
        if ($k >= 10 && $k % 3 === 0) $edges[] = "($versionId,$subject," . (int)$subjects[($vi + $k - 10) % count($subjects)] . ",'prerequisite',$staff)";
    }
}
$bulk('INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester,sequence_order,is_required,created_by) VALUES', $rows);
$bulk('INSERT IGNORE INTO curriculum_subject_requirements(curriculum_version_id,subject_id,required_subject_id,requirement_type,created_by) VALUES', $edges);

// Review events: 5 per reviewed version, always a valid sequence. Approved: submit, return, submit, approve, comment. Under review: submit, return, submit, return, submit. Spread over 330 days.
$rows = [];
for ($i = 0; $i < 400; $i++) {
    $id = (int)$versionIds[$i]; $final = $spec[$i]['review'];
    $steps = [['submit', 'not_started', 'under_review', $staff], ['return', 'under_review', 'returned', $office], ['submit', 'returned', 'under_review', $staff],
        $final === 'approved' ? ['approve', 'under_review', 'approved', $office] : ['return', 'under_review', 'returned', $office],
        $final === 'approved' ? ['comment', 'approved', 'approved', $office] : ['submit', 'returned', 'under_review', $staff]];
    $start = strtotime('-' . (330 - intdiv($i * 330, 400)) . ' days');
    foreach ($steps as $n => [$action, $from, $to, $actor]) {
        $rows[] = "($id,$actor," . $q($action) . ',' . $q($from) . ',' . $q($to) . ",'SYN synthetic review'," . $q(date('Y-m-d H:i:s', $start + $n * 3600 * 20)) . ')';
    }
}
$bulk('INSERT INTO curriculum_version_review_events(curriculum_version_id,actor_user_id,action,previous_status,new_status,comments,created_at) VALUES', $rows);

// Validation runs for the versions under review.
$rows = [];
for ($i = 1; $i < 400; $i += 2) $rows[] = '(' . (int)$versionIds[$i] . ',12,' . (8 + $i % 3) . ',' . ($i % 3) . ",1,$staff)";
$bulk('INSERT INTO compliance_validation_runs(curriculum_version_id,rules_evaluated,passed_count,failed_count,not_evaluated_count,run_by) VALUES', $rows);
$db->query("UPDATE compliance_validation_runs SET passed_count = rules_evaluated - failed_count - not_evaluated_count WHERE curriculum_version_id IN (SELECT id FROM curriculum_versions WHERE version_label LIKE 'SYN-V%')");

// Audit rows: 50,000 over 120 days, plus export entries (with the review state stored) and some role changes.
$rows = [];
for ($i = 0; $i < AUDIT; $i++) {
    $action = ['CURRICULUM_VERSION_UPDATE', 'SUBJECT_UPDATE', 'ELECTIVE_UPDATE', 'GRADE_WEIGHTING_UPDATE'][$i % 4];
    $rows[] = "($staff," . $q($action) . ",'curriculum_version'," . (int)$versionIds[$i % VERSIONS] . ",'SYN synthetic audit row'," . $q(date('Y-m-d H:i:s', strtotime('-' . ($i % 120) . ' days -' . ($i % 86400) . ' seconds'))) . ')';
}
$bulk('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,description,created_at) VALUES', $rows);
$rows = [];
for ($i = 0; $i < 200; $i += 2) {
    $meta = json_encode(['curriculum_version_id' => (int)$versionIds[$i], 'version_label' => $spec[$i]['label'], 'review_status' => $spec[$i]['review'], 'format' => 'csv']);
    $rows[] = "($admin,'CURRICULUM_EXPORT_GENERATE','curriculum_version'," . (int)$versionIds[$i] . ",'SYN synthetic export'," . $q($meta) . ')';
}
$bulk('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,description,metadata_json) VALUES', $rows);
$rows = [];
for ($i = 0; $i < 60; $i++) $rows[] = "($admin,'RBAC_ROLE_ASSIGNMENT','user',$staff,'SYN synthetic role assignment')";
$bulk('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,description) VALUES', $rows);

$count = static fn(string $sql): int => (int)$db->query($sql)->fetch_row()[0];
printf("Synthetic volume added in %.1f s: %d programs, %d curricula, %d versions, %d placements, %d prerequisite rules, %d review events, %d audit rows (all SYN-/\"SYN synthetic\").\n",
    microtime(true) - $t0, $count("SELECT COUNT(*) FROM academic_programs WHERE program_code LIKE 'SYN-%'"), $count("SELECT COUNT(*) FROM curricula WHERE curriculum_code LIKE 'SYN-%'"),
    $count("SELECT COUNT(*) FROM curriculum_versions WHERE version_label LIKE 'SYN-%'"),
    $count("SELECT COUNT(*) FROM curriculum_version_subjects WHERE curriculum_version_id IN (SELECT id FROM curriculum_versions WHERE version_label LIKE 'SYN-%')"),
    $count("SELECT COUNT(*) FROM curriculum_subject_requirements WHERE curriculum_version_id IN (SELECT id FROM curriculum_versions WHERE version_label LIKE 'SYN-%')"),
    $count("SELECT COUNT(*) FROM curriculum_version_review_events WHERE comments='SYN synthetic review'"),
    $count("SELECT COUNT(*) FROM audit_logs WHERE description LIKE 'SYN synthetic%'"));
