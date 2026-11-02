<?php
// Non-destructive validation for a populated restored legacy database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/db.php';

$options = getopt('', ['capture:', 'verify:', 'stage:']);
$database = (string)$conn->query('SELECT DATABASE()')->fetch_row()[0];
if (!preg_match('/^csms_docker_test_task2_restored_[a-z0-9_]+$/D', $database)
    || getenv('DB_HOST') !== '127.0.0.1' || (int)getenv('DB_PORT') === 3306
    || getenv('SMS_TEST_CONFIRM_DATABASE') !== $database) {
    throw new RuntimeException('Requires explicitly confirmed populated restored database on a dedicated loopback port.');
}

function restoredColumns(mysqli $db, string $table): array {
    return array_column($db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $db->real_escape_string($table) . "' ORDER BY ORDINAL_POSITION")->fetch_all(MYSQLI_NUM), 0);
}
function restoredRows(mysqli $db, string $table, ?array $columns = null): array {
    $columns = $columns ?? restoredColumns($db, $table);
    if (!$columns) { throw new RuntimeException("Missing table: $table"); }
    $quoted = array_map(fn($column) => '`' . str_replace('`', '``', $column) . '`', $columns);
    $select = implode(',', $quoted);
    $order = implode(',', $quoted);
    return $db->query("SELECT $select FROM `" . str_replace('`', '``', $table) . "` ORDER BY $order")->fetch_all(MYSQLI_ASSOC);
}
function restoredHash(array $rows): string {
    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
function restoredTables(mysqli $db): array {
    return array_column($db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM), 0);
}
function restoredHasTable(mysqli $db, string $table): bool {
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->bind_param('s', $table); $stmt->execute(); $found = (int)$stmt->get_result()->fetch_row()[0] === 1; $stmt->close(); return $found;
}
function restoredHasColumn(mysqli $db, string $table, string $column): bool {
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->bind_param('ss', $table, $column); $stmt->execute(); $found = (int)$stmt->get_result()->fetch_row()[0] === 1; $stmt->close(); return $found;
}
function restoredHasPermission(mysqli $db, string $name): bool {
    $stmt = $db->prepare('SELECT COUNT(*) FROM permissions WHERE name=?'); $stmt->bind_param('s', $name); $stmt->execute(); $found = (int)$stmt->get_result()->fetch_row()[0] === 1; $stmt->close(); return $found;
}
function restoredAssert(bool $ok, string $message, array &$checks): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $message); }
    $checks[] = $message;
}

$capture = $options['capture'] ?? null;
$verify = $options['verify'] ?? null;
$stage = $options['stage'] ?? '';
if (($capture === null) === ($verify === null)) { throw new RuntimeException('Choose exactly one of --capture or --verify.'); }

$critical = ['academic_programs','curricula','curriculum_versions','curriculum_version_subjects','subjects','users','students','roles','permissions','role_permissions','user_roles'];
$plannedPermissions = ['curriculum.reference.attach','compliance.reference.verify','curriculum.reference.classify','curriculum.version.clone','curriculum.outcome_mapping.manage','curriculum.clo.manage','curriculum.assessment_plan.manage'];
if ($capture !== null) {
    $manifest = ['database'=>$database,'host'=>getenv('DB_HOST'),'port'=>(int)getenv('DB_PORT'),'server_version'=>$conn->query('SELECT VERSION()')->fetch_row()[0],'tables'=>[],'critical'=>[]];
    foreach (restoredTables($conn) as $table) {
        $columns = restoredColumns($conn, $table);
        $rows = restoredRows($conn, $table, $columns);
        $manifest['tables'][$table] = ['columns'=>$columns,'rows'=>count($rows),'hash'=>restoredHash($rows)];
    }
    foreach ($critical as $table) { $manifest['critical'][$table] = $manifest['tables'][$table]; }
    file_put_contents($capture, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
    echo json_encode(['captured'=>true,'database'=>$database,'tables'=>count($manifest['tables']),'critical_tables'=>count($critical)], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

$manifest = json_decode((string)file_get_contents($verify), true, 512, JSON_THROW_ON_ERROR);
if (($manifest['database'] ?? '') !== $database || (int)($manifest['port'] ?? 0) !== (int)getenv('DB_PORT')) {
    throw new RuntimeException('Manifest identity does not match the confirmed restored target.');
}
$checks = [];
foreach ($critical as $table) {
    $rows = restoredRows($conn, $table, $manifest['critical'][$table]['columns']);
    if ($table === 'permissions') {
        $rows = array_values(array_filter($rows, static fn(array $row): bool => !in_array($row['name'], $plannedPermissions, true)));
    }
    restoredAssert(count($rows) === (int)$manifest['critical'][$table]['rows'], "Existing row count preserved: $table", $checks);
    restoredAssert(hash_equals($manifest['critical'][$table]['hash'], restoredHash($rows)), "Existing row fingerprint preserved: $table", $checks);
}
restoredAssert(restoredHasTable($conn, 'schema_baseline_adoptions'), 'Baseline adoption ledger exists', $checks);
restoredAssert(restoredHasTable($conn, 'schema_migrations'), 'Migration journal exists', $checks);
$journal = array_column($conn->query("SELECT migration FROM schema_migrations WHERE status='applied' ORDER BY migration")->fetch_all(MYSQLI_NUM), 0);
$expected = ['021_create_curriculum_lifecycle_foundation.sql','022_curriculum_reference_provenance.sql','023_curriculum_revision_clone_permission.sql','024_curriculum_outcome_mapping.sql','025_curriculum_clo_assessment_alignment.sql'];
$stageIndex = array_search($stage, $expected, true);
if ($stageIndex === false) { throw new RuntimeException('Unknown --stage.'); }
foreach (array_slice($expected, 0, $stageIndex + 1) as $migration) { restoredAssert(in_array($migration, $journal, true), "Journal applied: $migration", $checks); }
$stageTables = [
    0 => ['curriculum_version_references','curriculum_version_personnel','curriculum_version_review_events','curriculum_version_subject_snapshots'],
    1 => ['compliance_reference_evidence_events'],
    2 => [], 3 => ['curriculum_outcome_catalog','curriculum_version_subject_outcomes','curriculum_version_outcome_coverage'],
    4 => ['curriculum_version_course_learning_outcomes','curriculum_version_clo_outcome_alignments','curriculum_version_clo_assessment_plans'],
];
foreach ($stageTables[$stageIndex] as $table) { restoredAssert(restoredHasTable($conn, $table), "Expected table exists: $table", $checks); }
$stageColumns = [
    0 => [['curriculum_versions','baseline_version_id'],['curriculum_versions','version_role'],['curriculum_versions','review_status']],
    1 => [['compliance_references','source_kind'],['compliance_references','supersedes_reference_id'],['curriculum_version_subject_snapshots','lecture_units_snapshot'],['curriculum_version_subject_snapshots','laboratory_units_snapshot']],
    2 => [], 3 => [], 4 => [],
];
foreach ($stageColumns[$stageIndex] as [$table,$column]) { restoredAssert(restoredHasColumn($conn, $table, $column), "Expected column exists: $table.$column", $checks); }
$permissionsByStage = [
    0 => [], 1 => ['curriculum.reference.attach','compliance.reference.verify','curriculum.reference.classify'],
    2 => ['curriculum.version.clone'], 3 => ['curriculum.outcome_mapping.manage'],
    4 => ['curriculum.clo.manage','curriculum.assessment_plan.manage'],
];
foreach ($permissionsByStage[$stageIndex] as $permission) { restoredAssert(restoredHasPermission($conn, $permission), "Expected permission exists: $permission", $checks); }
echo json_encode(['passed'=>true,'stage'=>$stage,'checks'=>count($checks),'database'=>$database], JSON_THROW_ON_ERROR) . PHP_EOL;
