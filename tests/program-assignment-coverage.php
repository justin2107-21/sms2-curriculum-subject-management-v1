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

// Counts for P1: assigned {1,2}, placed {1,3,4}, placed-not-assigned {3,4}, assigned-not-placed {2}.
$c = $service->context('program', 1);
check($c['assigned_count'] === 2 && $c['placed_count'] === 3 && $c['placed_not_assigned'] === 2 && $c['assigned_not_placed'] === 1, 'P1 coverage counts: ' . json_encode($c));
$c2 = $service->context('program', 2);
check($c2['assigned_count'] === 1 && $c2['placed_count'] === 2 && $c2['placed_not_assigned'] === 1 && $c2['assigned_not_placed'] === 0, 'P2 coverage counts (archived assignment ignored): ' . json_encode($c2));

// Union listing and filters. S5 (neither) and S6 (archived, not placed in P1) never appear.
$all = $service->list(1, 'program', []);
check($codes($all) === ['S1', 'S2', 'S3', 'S4'] && $all['pagination']['total_records'] === 4, 'union lists assigned and placed subjects only: ' . json_encode($codes($all)));
check($codes($service->list(1, 'program', ['filter' => 'assigned'])) === ['S1', 'S2'], 'filter assigned');
check($codes($service->list(1, 'program', ['filter' => 'placed'])) === ['S1', 'S3', 'S4'], 'filter placed');
check($codes($service->list(1, 'program', ['filter' => 'placed_not_assigned'])) === ['S3', 'S4'], 'filter placed not assigned');
check($codes($service->list(1, 'program', ['filter' => 'assigned_not_placed'])) === ['S2'], 'filter assigned not placed');
check($codes($service->list(1, 'program', ['search' => 'subject 3'])) === ['S3'], 'search by title');
check($thrown(fn() => $service->list(1, 'program', ['filter' => 'nonsense'])) === 'DomainValidationException', 'unknown filter rejected');
$byCode = array_column($all['assignments'], null, 'subject_code');
check($byCode['S1']['assigned'] && $byCode['S1']['placed'] && $byCode['S1']['assignment_id'] === 1, 'S1 assigned and placed');
check($byCode['S2']['assigned'] && !$byCode['S2']['placed'] && $byCode['S2']['placements'] === [], 'S2 assigned only, no placements');
check(!$byCode['S3']['assigned'] && $byCode['S3']['placed'] && $byCode['S3']['assignment_id'] === null, 'S3 placed only');
check(count($byCode['S4']['placements']) === 2 && $byCode['S4']['placements'][0]['curriculum_code'] === 'C1' && $byCode['S4']['placements'][0]['version_label'] === 'v1', 'S4 has two placements, first is C1 v1');
$p = $service->list(1, 'program', ['per_page' => 10, 'page' => 1]);
check($p['pagination']['per_page'] === 10 && $p['pagination']['total_pages'] === 1, 'paging metadata');
check($service->list(1, 'program', ['per_page' => 10, 'page' => 2])['assignments'] === [], 'page past the end is empty');

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
