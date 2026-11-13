<?php
// Electives term slots (2026-11-13): the group service refuses a pool of one subject, so the four "IT Elective N (term slot)" groups are NOT created.
// CLI only, CLONE only (refuses sms_db). Rolls the attempt back: it asserts that nothing was written and that units/placements are untouched.
//   DB_NAME=<clone> php tests/elective-slots-refusal.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/../shared/db.php';
function current_user_id(): ?int { return 22; }
require_once __DIR__ . '/../shared/audit.php';
require_once __DIR__ . '/../modules/curriculum/services/ElectivesWorkspaceService.php';
$db = $conn; $name = $db->query('SELECT DATABASE() d')->fetch_assoc()['d'];
if ($name === 'sms_db') { fwrite(STDERR, "Refusing: run on a clone.\n"); exit(1); }
$checks = 0; function ok(bool $c, string $l): void { global $checks; if (!$c) throw new RuntimeException("FAILED: $l"); $checks++; }
$v = $db->query("SELECT v.id vid, c.id cid, c.academic_program_id pid FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE c.curriculum_code='BSIT-2026'")->fetch_assoc();
$vid = (int)$v['vid'];
$fp = fn() => md5(json_encode([
    $db->query("SELECT subject_id,year_level,semester,is_required FROM curriculum_version_subjects WHERE curriculum_version_id=$vid ORDER BY subject_id")->fetch_all(),
    $db->query('SELECT id,subject_code,units,subject_title FROM subjects ORDER BY id')->fetch_all(),
]));
$groups = fn() => (int)$db->query("SELECT COUNT(*) FROM curriculum_elective_groups WHERE curriculum_version_id=$vid")->fetch_row()[0];
$audit = fn() => (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'ELECTIVE_GROUP%'")->fetch_row()[0];
$slots = $db->query("SELECT s.id, s.subject_code, p.year_level, p.semester FROM curriculum_version_subjects p JOIN subjects s ON s.id=p.subject_id WHERE p.curriculum_version_id=$vid AND s.subject_title LIKE 'IT ELECTIVE %' ORDER BY s.subject_code")->fetch_all(MYSQLI_ASSOC);
ok(count($slots) === 4, 'four IT ELECTIVE subjects are placed in BSIT 2026');
$before = [$fp(), $groups(), $audit()]; $svc = new ElectivesWorkspaceService($db);
foreach ($slots as $i => $s) {
    try {
        $svc->save(['program_id' => $v['pid'], 'curriculum_id' => $v['cid'], 'curriculum_version_id' => $vid, 'group_name' => 'IT Elective ' . ($i + 1) . ' (term slot)', 'year_level' => $s['year_level'], 'semester' => $s['semester'], 'required_selections' => 1, 'subject_ids' => [(int)$s['id']], 'status' => 'active'], 22);
        ok(false, "{$s['subject_code']}: a pool of one must be refused");
    } catch (DomainValidationException $e) { ok(str_contains($e->getMessage(), 'at least two'), "{$s['subject_code']}: refused with the 'at least two' rule"); }
}
ok($groups() === $before[1] && $audit() === $before[2], 'no group and no audit entry was written');
ok($fp() === $before[0], 'units, titles and placements are unchanged');
echo "OK: $checks checks on $name\n";
