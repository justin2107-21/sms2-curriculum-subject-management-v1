<?php
// CLI-only regression for the deterministic CIMF Assignment Review findings and the compact AI payload.
// Fixtures live in TEMPORARY tables that shadow the permanent ones, so nothing is written to the database.
// Run only against sms_db_uitest:  DB_NAME=sms_db_uitest php tests/program-assignment-review.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
$config = csms_database_config();
if (!in_array($config['host'], ['localhost', '127.0.0.1'], true) || getenv('APP_ENV') === 'production') throw new RuntimeException('Local development only.');
$conn = csms_database_connect($config);
$dbName = $conn->query('SELECT DATABASE()')->fetch_row()[0];
if (!str_ends_with((string)$dbName, '_uitest')) throw new RuntimeException("Refusing to run on database '$dbName'. Use sms_db_uitest.");
require_once __DIR__ . '/../modules/curriculum/services/CIMFProfileRegistry.php';
require_once __DIR__ . '/../modules/curriculum/services/CIMFGlobalContextBuilder.php';

$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $message); $checks++; }
$tables = ['academic_programs', 'curricula', 'curriculum_versions', 'subjects', 'curriculum_version_subjects', 'program_subject_assignments', 'audit_logs'];
foreach ($tables as $t) { $conn->query("CREATE TEMPORARY TABLE __tpl LIKE `$t`"); $conn->query("CREATE TEMPORARY TABLE `$t` LIKE __tpl"); $conn->query('DROP TEMPORARY TABLE __tpl'); }
$one = fn(string $sql) => (int)$conn->query($sql)->fetch_row()[0];
$snapshot = function () use ($conn, $tables): string { $h = []; foreach ($tables as $t) $h[$t] = hash('sha256', json_encode($conn->query("SELECT * FROM `$t` ORDER BY 1")->fetch_all(MYSQLI_ASSOC))); return json_encode($h); };

$conn->query("INSERT INTO academic_programs(id,program_code,program_name,program_type) VALUES(1,'P1','Program One','College'),(2,'P2','Program Two','College')");
$conn->query("INSERT INTO curricula(id,curriculum_code,curriculum_name,academic_program_id) VALUES(1,'C1','Curriculum One',1),(2,'C2','Curriculum Two',2)");
$conn->query("INSERT INTO curriculum_versions(id,curriculum_id,version_label) VALUES(1,1,'v1'),(2,1,'v2'),(3,2,'v1')");
// S1 assigned+placed v1; S2 placed v1 (active, unassigned); S3 placed v1 (inactive, unassigned); S4 assigned, placed only in v2;
// S5 assigned, placed nowhere; S6 inactive, assigned and placed v1; S7 placed in P2 only; S8..S47 placed v1, active, unassigned (cap test).
foreach (range(1, 47) as $id) $conn->query("INSERT INTO subjects(id,subject_code,subject_title,units) VALUES($id,'S$id','Subject $id',3)");
$conn->query("UPDATE subjects SET status='inactive' WHERE id IN (3,6)");
$conn->query("INSERT INTO program_subject_assignments(academic_program_id,subject_id,status) VALUES(1,1,'active'),(1,4,'active'),(1,5,'active'),(1,6,'active')");
$conn->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES(1,1,'1st Year','1st Semester'),(1,2,'1st Year','1st Semester'),(1,3,'1st Year','2nd Semester'),(2,4,'2nd Year','1st Semester'),(1,6,'3rd Year','1st Semester'),(3,7,'1st Year','1st Semester')");
for ($id = 8; $id <= 47; $id++) $conn->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES(1,$id,'2nd Year','1st Semester')");

$service = new ProgramSubjectAssignmentService($conn);
$thrown = function (callable $fn): ?string { try { $fn(); return null; } catch (Throwable $e) { return get_class($e); } };
$by = fn(array $report) => array_column($report['findings'], null, 'key');
$codes = fn(array $f) => array_column($f['subjects'], 'code');

// ---- findings for P1 / v1 ----
$before = $snapshot();
$r = $service->reviewFindings(1, 1); $f = $by($r);
check($f['placed_not_assigned_active']['count'] === 41 && in_array('S2', $codes($f['placed_not_assigned_active']), true) && in_array('S47', $codes($f['placed_not_assigned_active']), true), 'active placed-not-assigned: S2 and S8..S47 (' . $f['placed_not_assigned_active']['count'] . ')');
check($codes($f['placed_not_assigned_inactive']) === ['S3'], 'inactive placed-not-assigned: S3');
check($codes($f['assigned_not_placed']) === ['S4', 'S5'], 'assigned, not placed in v1: S4 (v2 only) and S5: ' . json_encode($codes($f['assigned_not_placed'])));
check($codes($f['inactive_placed']) === ['S3', 'S6'], 'inactive subjects placed in v1: S3 and S6: ' . json_encode($codes($f['inactive_placed'])));
check($codes($f['assigned_placed_nowhere']) === ['S5'], 'assigned but placed in no version: S5 only (S4 is placed in v2)');
check($r['version']['label'] === 'v1' && $r['version']['curriculum'] === 'C1' && $r['program']['code'] === 'P1' && $r['notice'] === null, 'the report names program and version');
check($r['totals'] === ['assigned' => 4, 'placed_in_version' => 44], 'totals: ' . json_encode($r['totals']));
check(!in_array('S7', array_merge(...array_map($codes, array_values($f))), true), 'a subject of another program is never listed');

// ---- the other version, and no version ----
$f2 = $by($service->reviewFindings(1, 2));
check($f2['placed_not_assigned_active']['count'] === 0 && $codes($f2['assigned_not_placed']) === ['S1', 'S5', 'S6'] && $codes($f2['assigned_placed_nowhere']) === ['S5'], 'v2 findings follow the selected version');
$none = $service->reviewFindings(1);
$fn = $by($none);
check($none['version'] === null && $none['notice'] !== null && $fn['placed_not_assigned_active']['count'] === 0 && $codes($fn['assigned_placed_nowhere']) === ['S5'], 'no version: only the assignment-only finding, with a notice');
check($thrown(fn() => $service->reviewFindings(1, 3)) === 'DomainValidationException', 'a version of another program is refused');
check($thrown(fn() => $service->reviewFindings(99)) === 'DomainNotFoundException', 'a missing program');

// ---- compact payload for the AI ----
$profile = CIMFProfileRegistry::profiles()['academic_assignment'];
$context = (new CIMFGlobalContextBuilder($conn))->build($profile, 'review', ['view' => 'program', 'academic_program_id' => 1, 'curriculum_id' => 1, 'curriculum_version_id' => 1]);
$examples = array_sum(array_map(fn($x) => count($x['examples']), $context['facts']['findings']));
check($examples <= 30 && count($context['allowed']) <= 30, "at most 30 subjects are sent ($examples examples, " . count($context['allowed']) . ' allowed)');
check($context['facts']['findings'][0]['count'] === 41 && count($context['facts']['findings'][0]['examples']) <= 8, 'counts are exact; examples are capped per finding');
check(strlen(json_encode($context['facts'])) < 6000, 'the payload is small (' . strlen(json_encode($context['facts'])) . ' bytes)');
check(str_contains($context['facts']['authority'], 'Never say anything was assigned') && $context['deterministic']['findings'][0]['count'] === 41, 'the authority note and the full deterministic report are present');
check($context['version_id'] === 1, 'the selected version is carried for usage logging');
$mismatch = $thrown(fn() => (new CIMFGlobalContextBuilder($conn))->build($profile, 'review', ['view' => 'program', 'academic_program_id' => 1, 'curriculum_id' => 2, 'curriculum_version_id' => 1]));
check($mismatch === 'InvalidArgumentException', 'a curriculum that does not match the version is refused');
// The registry still restricts what the review may carry and never offers a write.
check(in_array('curriculum_version_id', $profile['fields'], true) && in_array('assign', $profile['restricted_operations'], true), 'registry: version allowed, assign restricted');

// ---- nothing was written by any of the above ----
check($snapshot() === $before, 'findings and the AI context write nothing (all assignment, placement, version, subject and audit tables unchanged)');
echo "program-assignment-review: $checks checks passed on $dbName\n";
