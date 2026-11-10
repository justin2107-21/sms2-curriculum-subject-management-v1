<?php
// CLI-only regression for "Assign all placed" (reconcile) and the unassign message.
// Fixtures live in TEMPORARY tables that shadow the permanent ones on this connection, so nothing is written to the database.
// Run only against sms_db_uitest:  DB_NAME=sms_db_uitest php tests/program-assignment-reconcile.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
$config = csms_database_config();
if (!in_array($config['host'], ['localhost', '127.0.0.1'], true) || getenv('APP_ENV') === 'production') throw new RuntimeException('Local development only.');
$conn = csms_database_connect($config);   // also the connection audit_log_required() uses
$dbName = $conn->query('SELECT DATABASE()')->fetch_row()[0];
if (!str_ends_with((string)$dbName, '_uitest')) throw new RuntimeException("Refusing to run on database '$dbName'. Use sms_db_uitest.");
require_once __DIR__ . '/../modules/curriculum/services/ProgramSubjectAssignmentService.php';

$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $message); $checks++; }
function shadow(mysqli $db, array $tables): void {
    foreach ($tables as $t) { $db->query("CREATE TEMPORARY TABLE __tpl LIKE `$t`"); $db->query("CREATE TEMPORARY TABLE `$t` LIKE __tpl"); $db->query('DROP TEMPORARY TABLE __tpl'); }
}
$one = fn(string $sql) => (int)$conn->query($sql)->fetch_row()[0];
shadow($conn, ['academic_programs', 'curricula', 'curriculum_versions', 'subjects', 'curriculum_version_subjects', 'program_subject_assignments', 'audit_logs']);

$conn->query("INSERT INTO academic_programs(id,program_code,program_name,program_type,status) VALUES(1,'P1','Program One','College','active'),(2,'P2','Program Two','College','inactive')");
$conn->query("INSERT INTO curricula(id,curriculum_code,curriculum_name,academic_program_id) VALUES(1,'C1','Curriculum One',1),(2,'C2','Curriculum Two',2)");
$conn->query("INSERT INTO curriculum_versions(id,curriculum_id,version_label) VALUES(1,1,'v1'),(2,1,'v2'),(3,2,'v1')");
foreach (range(1, 9) as $id) $conn->query("INSERT INTO subjects(id,subject_code,subject_title,units) VALUES($id,'S$id','Subject $id',3)");
$conn->query("UPDATE subjects SET status='inactive' WHERE id=4");
// v1 placements: S1 (assigned), S2, S3 (unassigned), S4 (inactive, unassigned), S6 (archived assignment). v2: S1, S5. Program 2: S9.
$conn->query("INSERT INTO program_subject_assignments(id,academic_program_id,subject_id,status) VALUES(1,1,1,'active'),(2,1,6,'archived'),(3,1,7,'active')");
$conn->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES
    (1,1,'1st Year','1st Semester'),(1,2,'1st Year','1st Semester'),(1,3,'2nd Year','2nd Semester'),(1,4,'2nd Year','1st Semester'),(1,6,'3rd Year','1st Semester'),
    (2,1,'4th Year','2nd Semester'),(2,5,'1st Year','1st Semester'),(3,9,'1st Year','1st Semester')");

$service = new ProgramSubjectAssignmentService($conn);
$thrown = function (callable $fn): ?string { try { $fn(); return null; } catch (Throwable $e) { return get_class($e); } };
$codes = fn(array $rows) => array_column($rows, 'subject_code');
$placementCount = fn() => $one('SELECT COUNT(*) FROM curriculum_version_subjects');
$versionsHash = fn() => hash('sha256', json_encode($conn->query('SELECT * FROM curriculum_versions ORDER BY id')->fetch_all(MYSQLI_ASSOC)));
$placementsHash = fn() => hash('sha256', json_encode($conn->query('SELECT * FROM curriculum_version_subjects ORDER BY id')->fetch_all(MYSQLI_ASSOC)));
$audit = fn(string $action) => $one("SELECT COUNT(*) FROM audit_logs WHERE action='$action'");
$assigned = fn() => array_map('intval', array_column($conn->query("SELECT subject_id FROM program_subject_assignments WHERE academic_program_id=1 AND status='active' ORDER BY subject_id")->fetch_all(MYSQLI_ASSOC), 'subject_id'));

// ---- Preview: read only, active and inactive separated, only the selected version ----
$before = [$placementCount(), $placementsHash(), $versionsHash(), $one('SELECT COUNT(*) FROM program_subject_assignments'), $one('SELECT COUNT(*) FROM audit_logs')];
$preview = $service->reconcilePreview(1, 1, 1);
check(!array_diff($codes($preview['assignable']), ['S2', 'S3', 'S6']) && $codes($preview['skipped_inactive']) === ['S4'], 'preview: S2, S3, S6 assignable; S4 skipped as inactive');
check(isset($preview['assignable'][0]['year_level'], $preview['assignable'][0]['semester'], $preview['assignable'][0]['units']), 'preview rows carry units, year and semester');
check([$placementCount(), $placementsHash(), $versionsHash(), $one('SELECT COUNT(*) FROM program_subject_assignments'), $one('SELECT COUNT(*) FROM audit_logs')] === $before, 'preview writes nothing');
check(!in_array('S5', $codes($preview['assignable']), true), 'a subject placed only in another version is not in the preview');

// ---- Validation ----
check($thrown(fn() => $service->reconcile(1, 1, 3, 1)) === 'DomainValidationException', 'a version of another program is rejected');
check($thrown(fn() => $service->reconcile(1, 2, 1, 1)) === 'DomainValidationException', 'a curriculum of another program is rejected');
check($thrown(fn() => $service->reconcile(2, 2, 3, 1)) === 'DomainValidationException', 'an inactive program cannot receive assignments');
check($thrown(fn() => $service->reconcile(99, 1, 1, 1)) === 'DomainNotFoundException', 'a missing program');
check($assigned() === [1, 7], 'validation failures assigned nothing');

$cov = $service->context('program', 1, 1);
check($cov['placed_not_assigned'] === 4 && $cov['assignable_count'] === 3, 'coverage: 4 placed-not-assigned, 3 of them active (the button label count): ' . json_encode([$cov['placed_not_assigned'], $cov['assignable_count']]));

// ---- Reconcile: only active, not yet assigned; archived row reactivated; nothing curriculum-side changes ----
$result = $service->reconcile(1, 1, 1, 1);
check($result === ['assigned' => 3, 'skipped_inactive' => 1], 'result: ' . json_encode($result));
check($assigned() === [1, 2, 3, 6, 7], 'assigned S2, S3 and S6 (reactivated); S4 inactive and S5 (other version) untouched: ' . json_encode($assigned()));
check($one('SELECT COUNT(*) FROM program_subject_assignments WHERE academic_program_id=1 AND subject_id=6') === 1 && $one('SELECT id FROM program_subject_assignments WHERE academic_program_id=1 AND subject_id=6') === 2, 'the archived assignment row was reused');
check($one('SELECT COUNT(*) FROM program_subject_assignments WHERE subject_id=4') === 0, 'the inactive subject was never assigned');
check($placementCount() === $before[0] && $placementsHash() === $before[1] && $versionsHash() === $before[2], 'curriculum_version_subjects and curriculum_versions are unchanged');
check($audit('PROGRAM_SUBJECT_ASSIGN') === 3 && $audit('PROGRAM_SUBJECT_RECONCILE') === 1, 'audit: one entry per assignment plus one summary');
$summary = json_decode($conn->query("SELECT metadata_json FROM audit_logs WHERE action='PROGRAM_SUBJECT_RECONCILE'")->fetch_row()[0], true);
check($summary['academic_program_id'] === 1 && $summary['curriculum_version_id'] === 1 && $summary['assigned'] === 3 && $summary['skipped_inactive'] === 1, 'summary audit carries program, version and counts: ' . json_encode($summary));

// ---- Idempotent ----
$stateAfter = [$assigned(), $one('SELECT COUNT(*) FROM program_subject_assignments'), $audit('PROGRAM_SUBJECT_ASSIGN'), $audit('PROGRAM_SUBJECT_RECONCILE'), $placementsHash()];
check($service->reconcile(1, 1, 1, 1) === ['assigned' => 0, 'skipped_inactive' => 1], 'second run assigns nothing');
check([$assigned(), $one('SELECT COUNT(*) FROM program_subject_assignments'), $audit('PROGRAM_SUBJECT_ASSIGN'), $audit('PROGRAM_SUBJECT_RECONCILE'), $placementsHash()] === $stateAfter, 'second run changes nothing, not even the audit log');
// A different version reconciles on its own: S5 is placed only in v2.
check($service->reconcile(1, 1, 2, 1) === ['assigned' => 1, 'skipped_inactive' => 0] && $assigned() === [1, 2, 3, 5, 6, 7], 'v2 reconcile assigns S5 only');

// ---- Rollback: force the audit insert to fail after the assignment insert ----
$conn->query("INSERT INTO subjects(id,subject_code,subject_title,units) VALUES(10,'S10','Subject 10',3)");
$conn->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES(1,10,'1st Year','2nd Semester')");
$conn->query("SET SESSION sql_mode='STRICT_ALL_TABLES'");
$conn->query('ALTER TABLE audit_logs ADD COLUMN forced_failure INT NOT NULL');
$snapshot = [$assigned(), $one('SELECT COUNT(*) FROM program_subject_assignments'), $one('SELECT COUNT(*) FROM audit_logs'), $placementsHash()];
check($thrown(fn() => $service->reconcile(1, 1, 1, 1)) !== null, 'a failing insert raises');
check([$assigned(), $one('SELECT COUNT(*) FROM program_subject_assignments'), $one('SELECT COUNT(*) FROM audit_logs'), $placementsHash()] === $snapshot, 'everything rolled back: no assignment row, no audit row, placements unchanged');
check($one('SELECT COUNT(*) FROM program_subject_assignments WHERE subject_id=10') === 0, 'S10 was not left assigned');
$conn->query('ALTER TABLE audit_logs DROP COLUMN forced_failure');
check($service->reconcile(1, 1, 1, 1) === ['assigned' => 1, 'skipped_inactive' => 1] && $one('SELECT COUNT(*) FROM program_subject_assignments WHERE subject_id=10') === 1, 'after the failure is removed the same run succeeds');

// ---- Unassign: the server names the blocking curricula and versions ----
$s1 = $one('SELECT id FROM program_subject_assignments WHERE academic_program_id=1 AND subject_id=1');
try { $service->remove($s1); check(false, 'unassign of a placed subject must be blocked'); }
catch (DomainConflictException $e) {
    $m = $e->getMessage();
    check(str_contains($m, 'S1') && str_contains($m, 'C1 / v1, 1st Year 1st Semester') && str_contains($m, 'C1 / v2, 4th Year 2nd Semester'), 'unassign message lists both blocking versions with terms: ' . $m);
}
check($one("SELECT COUNT(*) FROM program_subject_assignments WHERE id=$s1 AND status='active'") === 1, 'the blocked unassign left the assignment active');
check(ProgramSubjectAssignmentService::placementBlockMessage('X1', array_fill(0, 7, ['curriculum_code' => 'C', 'version_label' => 'v', 'year_level' => '1st Year', 'semester' => '1st Semester'])) === 'X1 cannot be unassigned. Placed in ' . implode('; ', array_fill(0, 5, 'C / v, 1st Year 1st Semester')) . ' and 2 more. Remove it from those curriculum plans first.', 'long lists are cut at five with a count');

echo "program-assignment-reconcile: $checks checks passed on $dbName\n";
