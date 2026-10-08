<?php
// Phase 1 Task 1: schema/FK regression against an explicitly confirmed
// DISPOSABLE database upgraded through migration 021. Never run on production.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/db.php';

$database = (string)$conn->query('SELECT DATABASE()')->fetch_row()[0];
if (!preg_match('/^(csms_docker_test_|sms_db_foundation_test_lifecycle_)[a-z0-9_]+$/D', $database)
    || getenv('SMS_TEST_CONFIRM_DATABASE') !== $database) {
    fwrite(STDERR, "Refusing non-disposable or unconfirmed database.\n");
    exit(1);
}
$checks = 0;
function lifecycleCheck(bool $result, string $message): void {
    global $checks;
    if (!$result) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++;
    echo 'PASS: ' . $message . PHP_EOL;
}
function lifecycleReject(mysqli $db, string $sql, array $codes, string $label): void {
    try {
        $db->query($sql);
    } catch (mysqli_sql_exception $error) {
        lifecycleCheck(in_array((int)$error->getCode(), $codes, true), $label);
        return;
    }
    throw new RuntimeException('FAIL: invalid operation accepted: ' . $label);
}
function lifecycleHasTable(mysqli $db, string $name): bool {
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $found = (int)$stmt->get_result()->fetch_row()[0] === 1;
    $stmt->close();
    return $found;
}
function lifecycleHasConstraint(mysqli $db, string $table, string $name, string $kind): bool {
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?
          AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?');
    $stmt->bind_param('sss', $table, $name, $kind);
    $stmt->execute();
    $found = (int)$stmt->get_result()->fetch_row()[0] === 1;
    $stmt->close();
    return $found;
}
function lifecycleKeyColumns(mysqli $db, string $table, string $key): array {
    $stmt = $db->prepare('SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
        ORDER BY ORDINAL_POSITION');
    $stmt->bind_param('ss', $table, $key);
    $stmt->execute();
    $columns = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'COLUMN_NAME');
    $stmt->close();
    return $columns;
}
function lifecycleCount(mysqli $db, string $table): int {
    // Only predetermined identifiers are passed to this helper.
    return (int)$db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetch_row()[0];
}
$tables = [
    'curriculum_version_references',
    'curriculum_version_personnel',
    'curriculum_version_review_events',
    'curriculum_version_subject_snapshots',
];
foreach ($tables as $table) {
    lifecycleCheck(lifecycleHasTable($conn, $table), 'New table exists: ' . $table);
}
foreach ([
    'chk_cv_version_role', 'chk_cv_review_status', 'chk_cv_role_baseline',
] as $name) {
    lifecycleCheck(lifecycleHasConstraint($conn, 'curriculum_versions', $name, 'CHECK'), 'Lifecycle CHECK: ' . $name);
}
lifecycleCheck(
    lifecycleKeyColumns($conn, 'curriculum_versions', 'fk_cv_baseline_same_curriculum')
        === ['baseline_version_id', 'curriculum_id'],
    'Baseline composite FK local columns'
);
lifecycleCheck(
    lifecycleKeyColumns($conn, 'curriculum_versions', 'uq_cv_id_curriculum')
        === ['id', 'curriculum_id'],
    'Referenced version composite unique key'
);
foreach ([
    'curriculum_version_references' => ['fk_cvr_version','fk_cvr_reference','fk_cvr_linked_by'],
    'curriculum_version_personnel' => ['fk_cvp_version','fk_cvp_user','fk_cvp_assigned_by'],
    'curriculum_version_review_events' => ['fk_cvre_version','fk_cvre_actor'],
    'curriculum_version_subject_snapshots' => ['fk_cvss_placement'],
] as $table => $keys) {
    foreach ($keys as $key) {
        lifecycleCheck(lifecycleHasConstraint($conn, $table, $key, 'FOREIGN KEY'), $table . ' FK ' . $key);
    }
}
foreach ([
    'curriculum_version_references' => ['chk_cvr_purpose','chk_cvr_verification'],
    'curriculum_version_personnel' => ['chk_cvp_responsibility','chk_cvp_active'],
    'curriculum_version_review_events' => ['chk_cvre_action','chk_cvre_previous','chk_cvre_new'],
    'curriculum_version_subject_snapshots' => ['chk_cvss_units','chk_cvss_method'],
] as $table => $checksExpected) {
    foreach ($checksExpected as $key) {
        lifecycleCheck(lifecycleHasConstraint($conn, $table, $key, 'CHECK'), $table . ' CHECK ' . $key);
    }
}
$expectedSql = file_get_contents(__DIR__ . '/../database/migrations/021_create_curriculum_lifecycle_foundation.sql');
if ($expectedSql === false) { throw new RuntimeException('Cannot read migration 021'); }
$normalized = preg_replace('/^USE sms_db;\s*$/m', '', str_replace("\r\n", "\n", $expectedSql));
$stmt = $conn->prepare("SELECT checksum FROM schema_migrations WHERE migration = '021_create_curriculum_lifecycle_foundation.sql' AND status = 'applied'");
$stmt->execute();
$journal = $stmt->get_result()->fetch_row();
$stmt->close();
lifecycleCheck($journal !== null && hash_equals((string)$journal[0], hash('sha256', $normalized)), 'Applied journal matches exact reviewed migration 021');
foreach (['users','academic_programs','curricula','curriculum_versions','subjects','curriculum_version_subjects','compliance_references',...$tables] as $table) {
    lifecycleCheck(lifecycleCount($conn, $table) === 0, 'Isolated fixture table empty: ' . $table);
}
$originalForeignKeys = $conn->query("SELECT DELETE_RULE, UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='fk_cv_baseline_same_curriculum'")->fetch_assoc();
lifecycleCheck($originalForeignKeys !== null && $originalForeignKeys['DELETE_RULE'] === 'RESTRICT' && $originalForeignKeys['UPDATE_RULE'] === 'RESTRICT', 'Baseline FK prevents parent identity changes and deletions');

$conn->begin_transaction();
try {
    $conn->query("INSERT INTO users(username,email,first_name,last_name,password_hash)
        VALUES ('schema_fixture_021','schema_fixture_021@example.invalid','Test','Actor','not-a-login-hash')");
    $actor = (int)$conn->insert_id;
    $conn->query("INSERT INTO academic_programs(program_code,program_name) VALUES ('TEST-P21','Schema Test Program')");
    $program = (int)$conn->insert_id;
    $conn->query("INSERT INTO curricula(curriculum_code,curriculum_name,academic_program_id)
        VALUES ('TEST-C21A','First Test Curriculum',$program)");
    $curriculum = (int)$conn->insert_id;
    $conn->query("INSERT INTO curricula(curriculum_code,curriculum_name,academic_program_id)
        VALUES ('TEST-C21B','Second Test Curriculum',$program)");
    $otherCurriculum = (int)$conn->insert_id;
    $conn->query("INSERT INTO curriculum_versions(curriculum_id,version_label) VALUES ($curriculum,'Reference')");
    $baseline = (int)$conn->insert_id;
    $default = $conn->query("SELECT version_role,review_status,baseline_version_id,status FROM curriculum_versions WHERE id=$baseline")->fetch_assoc();
    lifecycleCheck($default === ['version_role'=>'unclassified','review_status'=>'not_started','baseline_version_id'=>null,'status'=>'draft'], 'Neutral defaults preserve legacy version semantics');
    $conn->query("UPDATE curriculum_versions SET version_role='reference' WHERE id=$baseline");
    $conn->query("INSERT INTO curriculum_versions(curriculum_id,version_label,version_role,review_status,baseline_version_id)
        VALUES ($curriculum,'Proposal','proposed','draft',$baseline)");
    $proposal = (int)$conn->insert_id;
    $conn->query("INSERT INTO curriculum_versions(curriculum_id,version_label,version_role) VALUES ($otherCurriculum,'Other Reference','reference')");
    $foreignBaseline = (int)$conn->insert_id;
    lifecycleReject($conn, "UPDATE curriculum_versions SET baseline_version_id=$foreignBaseline WHERE id=$proposal", [1452], 'Cross-curriculum baseline rejected');
    lifecycleReject($conn, "INSERT INTO curriculum_versions(curriculum_id,version_label,version_role) VALUES ($curriculum,'No Baseline','proposed')", [3819,4025], 'Proposed version requires baseline');
    lifecycleReject($conn, "UPDATE curriculum_versions SET version_role='unclassified' WHERE id=$proposal", [3819,4025], 'Non-proposed version cannot retain baseline');
    lifecycleReject($conn, "UPDATE curriculum_versions SET review_status='unverified' WHERE id=$proposal", [3819,4025], 'Unknown review status rejected');
    lifecycleReject($conn, "UPDATE curriculum_versions SET version_role='approved' WHERE id=$proposal", [3819,4025], 'Unknown version role rejected');
    $conn->query("INSERT INTO compliance_references(reference_type,title,issuing_body) VALUES ('OTHER','Fixture Reference','Test')");
    $reference = (int)$conn->insert_id;
    $conn->query("INSERT INTO curriculum_version_references(curriculum_version_id,compliance_reference_id,reference_purpose,verification_status_snapshot,linked_by)
        VALUES ($baseline,$reference,'school_curriculum','draft',$actor)");
    lifecycleReject($conn, "INSERT INTO curriculum_version_references(curriculum_version_id,compliance_reference_id,reference_purpose,verification_status_snapshot)
        VALUES ($baseline,$reference,'school_curriculum','draft')", [1062], 'Duplicate version reference rejected');
    lifecycleReject($conn, "INSERT INTO curriculum_version_references(curriculum_version_id,compliance_reference_id,reference_purpose,verification_status_snapshot)
        VALUES ($baseline,$reference,'invented','draft')", [3819,4025], 'Unknown reference purpose rejected');
    lifecycleReject($conn, "INSERT INTO curriculum_version_references(curriculum_version_id,compliance_reference_id,reference_purpose,verification_status_snapshot)
        VALUES ($baseline,999999999,'regulatory','draft')", [1452], 'Unknown source reference rejected');
    $conn->query("INSERT INTO curriculum_version_personnel(curriculum_version_id,user_id,responsibility,assigned_by) VALUES ($proposal,$actor,'preparer',$actor)");
    lifecycleReject($conn, "INSERT INTO curriculum_version_personnel(curriculum_version_id,user_id,responsibility) VALUES ($proposal,$actor,'preparer')", [1062], 'Duplicate personnel responsibility rejected');
    lifecycleReject($conn, "INSERT INTO curriculum_version_personnel(curriculum_version_id,user_id,responsibility) VALUES ($proposal,$actor,'unverified_role')", [3819,4025], 'Unknown personnel responsibility rejected');
    lifecycleReject($conn, "UPDATE curriculum_version_personnel SET is_active=3 WHERE curriculum_version_id=$proposal", [3819,4025], 'Invalid active flag rejected');
    $conn->query("INSERT INTO curriculum_version_review_events(curriculum_version_id,actor_user_id,action,previous_status,new_status)
        VALUES ($proposal,$actor,'submit','draft','under_review')");
    lifecycleReject($conn, "INSERT INTO curriculum_version_review_events(curriculum_version_id,actor_user_id,action,previous_status,new_status)
        VALUES ($proposal,$actor,'invented','draft','under_review')", [3819,4025], 'Unknown review event rejected');
    $conn->query("INSERT INTO subjects(subject_code,subject_title,units) VALUES ('TEST-S21','Fixture Subject',3)");
    $subject = (int)$conn->insert_id;
    $conn->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id) VALUES ($baseline,$subject)");
    $placement = (int)$conn->insert_id;
    $conn->query("INSERT INTO curriculum_version_subject_snapshots(curriculum_version_subject_id,subject_code_snapshot,subject_title_snapshot,units_snapshot,capture_method)
        VALUES ($placement,'TEST-S21','Fixture Subject',3,'reference_capture')");
    lifecycleReject($conn, "INSERT INTO curriculum_version_subject_snapshots(curriculum_version_subject_id,subject_code_snapshot,subject_title_snapshot,units_snapshot,capture_method)
        VALUES ($placement,'TEST-S21','Fixture Subject',3,'clone')", [1062], 'One snapshot per placement enforced');
    lifecycleReject($conn, "UPDATE curriculum_version_subject_snapshots SET units_snapshot=-1 WHERE curriculum_version_subject_id=$placement", [3819,4025], 'Negative snapshot units rejected');
    lifecycleReject($conn, "UPDATE curriculum_version_subject_snapshots SET capture_method='unknown' WHERE curriculum_version_subject_id=$placement", [3819,4025], 'Unknown snapshot capture method rejected');
    lifecycleReject($conn, "DELETE FROM curriculum_versions WHERE id=$baseline", [1451], 'Referencing proposal protects baseline deletion');
    lifecycleReject($conn, "DELETE FROM compliance_references WHERE id=$reference", [1451], 'Linked compliance source deletion restricted');
    lifecycleReject($conn, "DELETE FROM curriculum_version_subjects WHERE id=$placement", [1451], 'Snapshotted placement deletion restricted');
} finally {
    $conn->rollback();
}
foreach (['users','academic_programs','curricula','curriculum_versions','subjects','curriculum_version_subjects','compliance_references',...$tables] as $table) {
    lifecycleCheck(lifecycleCount($conn, $table) === 0, 'All fixture rows rolled back: ' . $table);
}
echo json_encode(['passed'=>true,'checks'=>$checks,'migration'=>'021'], JSON_THROW_ON_ERROR) . PHP_EOL;
