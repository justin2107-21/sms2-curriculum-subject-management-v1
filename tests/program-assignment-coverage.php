<?php
// CLI-only regression for the program-first Assignment listing and counts.
// All fixtures live in TEMPORARY tables that shadow the permanent ones on this connection, so nothing is written to the database.
// Run only against sms_db_uitest:  DB_NAME=sms_db_uitest php tests/program-assignment-coverage.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/../modules/curriculum/services/ProgramSubjectAssignmentService.php';
$config = csms_database_config();
if (!in_array($config['host'], ['localhost', '127.0.0.1'], true) || getenv('APP_ENV') === 'production') throw new RuntimeException('Local development only.');
$conn = csms_database_connect($config);
$dbName = $conn->query('SELECT DATABASE()')->fetch_row()[0];
if (!str_ends_with((string)$dbName, '_uitest')) throw new RuntimeException("Refusing to run on database '$dbName'. Use sms_db_uitest.");

$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $message); $checks++; }
function shadow(mysqli $db, array $tables): void {
    foreach ($tables as $t) { $db->query("CREATE TEMPORARY TABLE __tpl LIKE `$t`"); $db->query("CREATE TEMPORARY TABLE `$t` LIKE __tpl"); $db->query('DROP TEMPORARY TABLE __tpl'); }
}
shadow($conn, ['academic_programs', 'curricula', 'curriculum_versions', 'subjects', 'curriculum_version_subjects', 'program_subject_assignments']);

$conn->query("INSERT INTO academic_programs(id,program_code,program_name,program_type) VALUES(1,'P1','Program One','College'),(2,'P2','Program Two','College')");
$conn->query("INSERT INTO curricula(id,curriculum_code,curriculum_name,academic_program_id) VALUES(1,'C1','Curriculum One',1),(2,'C2','Curriculum Two',2)");
$conn->query("INSERT INTO curriculum_versions(id,curriculum_id,version_label) VALUES(1,1,'v1'),(2,1,'v2'),(3,2,'v1')");
foreach (range(1, 7) as $id) $conn->query("INSERT INTO subjects(id,subject_code,subject_title,units) VALUES($id,'S$id','Subject $id',3)");
$conn->query("UPDATE subjects SET status='inactive' WHERE id=6");
// S1 assigned+placed(P1), S2 assigned only, S3 placed only, S4 placed twice (v1,v2) not assigned, S5 nothing,
// S6 (inactive) archived assignment to P1 and placed only in P2, S7 assigned+placed in P2.
$conn->query("INSERT INTO program_subject_assignments(id,academic_program_id,subject_id,status) VALUES(1,1,1,'active'),(2,1,2,'active'),(3,1,6,'archived'),(4,2,7,'active')");
$conn->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES
    (1,1,'1st Year','1st Semester'),(1,3,'1st Year','2nd Semester'),(1,4,'2nd Year','1st Semester'),(2,4,'2nd Year','2nd Semester'),(3,6,'1st Year','1st Semester'),(3,7,'1st Year','1st Semester')");

$service = new ProgramSubjectAssignmentService($conn);
$codes = fn(array $page) => array_column($page['assignments'], 'subject_code');
$thrown = function (callable $fn): ?string { try { $fn(); return null; } catch (Throwable $e) { return get_class($e); } };

// Counts depend on the SELECTED version; assignment is program-level and never depends on it.
// P1 / v1: assigned {1,2}; placed in v1 {1,3,4}; placed-not-assigned {3,4}; assigned-not-placed {2}.
$cov = fn($p, $v = null) => array_values(array_intersect_key($service->context('program', $p, $v), array_flip(['assigned_count', 'placed_count', 'placed_not_assigned', 'assigned_not_placed'])));
check($cov(1, 1) === [2, 3, 2, 1], 'P1 v1 coverage: ' . json_encode($cov(1, 1)));
// P1 / v2: only S4 is placed in v2, so S1 becomes assigned-not-placed and S3 drops out of the table.
check($cov(1, 2) === [2, 1, 1, 2], 'P1 v2 coverage: ' . json_encode($cov(1, 2)));
// P1 / no version: assignment counts are unchanged, nothing is placed.
check($cov(1) === [2, 0, 0, 2], 'P1 without a version: ' . json_encode($cov(1)));
check($cov(2, 3) === [1, 2, 1, 0], 'P2 v3 coverage (archived assignment ignored): ' . json_encode($cov(2, 3)));
check($thrown(fn() => $service->context('program', 1, 3)) === 'DomainValidationException', 'a version of another program is rejected');
check($thrown(fn() => $service->context('program', 1, 'x')) === 'DomainValidationException', 'a malformed version is rejected');
check($service->context('program', 1, 2)['version_id'] === 2 && $service->context('program', 1)['version_id'] === null, 'context reports the selected version');

// Union listing and filters (P1 / v1). S5 (neither) and S6 (archived, not in P1) never appear.
$q = ['version_id' => 1];
$all = $service->list(1, 'program', $q);
check($codes($all) === ['S1', 'S2', 'S3', 'S4'] && $all['pagination']['total_records'] === 4, 'union lists assigned and placed subjects only: ' . json_encode($codes($all)));
check($codes($service->list(1, 'program', $q + ['filter' => 'assigned'])) === ['S1', 'S2'], 'filter assigned');
check($codes($service->list(1, 'program', $q + ['filter' => 'placed'])) === ['S1', 'S3', 'S4'], 'filter placed');
check($codes($service->list(1, 'program', $q + ['filter' => 'placed_not_assigned'])) === ['S3', 'S4'], 'filter placed not assigned');
check($codes($service->list(1, 'program', $q + ['filter' => 'assigned_not_placed'])) === ['S2'], 'filter assigned not placed');
check($codes($service->list(1, 'program', $q + ['search' => 'subject 3'])) === ['S3'], 'search by title');
check($thrown(fn() => $service->list(1, 'program', $q + ['filter' => 'nonsense'])) === 'DomainValidationException', 'unknown filter rejected');
$byCode = array_column($all['assignments'], null, 'subject_code');
check($byCode['S1']['assigned'] && $byCode['S1']['placed'] && $byCode['S1']['assignment_id'] === 1, 'S1 assigned and placed');
check($byCode['S2']['assigned'] && !$byCode['S2']['placed'] && $byCode['S2']['placements'] === [], 'S2 assigned only, no placements');
check(!$byCode['S3']['assigned'] && $byCode['S3']['placed'] && $byCode['S3']['assignment_id'] === null, 'S3 placed only');
check(count($byCode['S4']['placements']) === 1 && $byCode['S4']['placements'][0]['year_level'] === '2nd Year' && $byCode['S4']['placements'][0]['semester'] === '1st Semester', 'S4 shows its term in v1 only');
// Same program, version 2: the Placed column follows the version while Assignment stays program-level.
$v2 = $service->list(1, 'program', ['version_id' => 2]);
$v2By = array_column($v2['assignments'], null, 'subject_code');
check($codes($v2) === ['S1', 'S2', 'S4'], 'v2 rows: assigned plus placed in v2: ' . json_encode($codes($v2)));
check($v2By['S1']['assigned'] && !$v2By['S1']['placed'] && $v2By['S4']['placed'] && $v2By['S4']['placements'][0]['semester'] === '2nd Semester', 'v2: S1 not placed, S4 term from v2');
check($codes($service->list(1, 'program', ['version_id' => 2, 'filter' => 'placed_not_assigned'])) === ['S4'], 'v2 placed not assigned');
// No version: only assigned subjects are listed.
check($codes($service->list(1, 'program', [])) === ['S1', 'S2'], 'no version: assigned subjects only');
check($thrown(fn() => $service->list(1, 'program', ['version_id' => 3])) === 'DomainValidationException', 'list rejects a version of another program');
$p = $service->list(1, 'program', $q + ['per_page' => 10, 'page' => 1]);
check($p['pagination']['per_page'] === 10 && $p['pagination']['total_pages'] === 1, 'paging metadata');
check($service->list(1, 'program', $q + ['per_page' => 10, 'page' => 2])['assignments'] === [], 'page past the end is empty');

// Where used.
$u = $service->usage(1);
check(count($u['assigned']) === 1 && $u['assigned'][0]['program_code'] === 'P1' && count($u['placements']) === 1, 'usage of S1');
$u6 = $service->usage(6);
check($u6['assigned'] === [] && count($u6['placements']) === 1 && $u6['placements'][0]['program_code'] === 'P2', 'usage of S6: archived assignment not listed, placed in P2');
check($thrown(fn() => $service->usage(999)) === 'DomainNotFoundException', 'usage of a missing subject');

// Assign picker: only active, not yet assigned subjects (placed-but-unassigned stays available).
$avail = array_column($service->search('subject', '', 'program', 1, true), 'subject_code');
sort($avail);
check($avail === ['S3', 'S4', 'S5', 'S7'], 'assign picker excludes inactive and already-assigned: ' . json_encode($avail));
check(in_array('S6', array_column($service->search('subject', '', 'program', 1, false), 'subject_code'), true), 'context picker still lists inactive subjects');

// Server rules unchanged: inactive subject cannot be assigned; a placed subject cannot be unassigned.
check($thrown(fn() => $service->assign('program', 1, [6], 1)) === 'DomainValidationException', 'inactive subject cannot be assigned');
check($thrown(fn() => $service->remove(1)) === 'DomainConflictException', 'unassign blocked while the subject is placed');
check((int)$conn->query("SELECT COUNT(*) FROM program_subject_assignments WHERE id=1 AND status='active'")->fetch_row()[0] === 1, 'blocked unassign left the assignment active');

echo "program-assignment-coverage: $checks checks passed on $dbName\n";
