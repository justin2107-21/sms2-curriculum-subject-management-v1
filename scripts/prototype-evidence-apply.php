<?php
// Evidence-only prerequisite rules for BSIT 2026 (2026-11-13). ONE script, dry-run by default, idempotent.
//
//   Dry run:  DB_NAME=<database> php scripts/prototype-evidence-apply.php
//   Apply:    DB_NAME=<database> php scripts/prototype-evidence-apply.php --apply --confirm-database=<database> --actor-user-id=<id> --backup-file=<existing non-empty file>
//
// What it may write: PE 1 and PE 2 as prerequisites of PE 3 and PE 4 (four rules), through CurriculumSubjectRequirementService (validation, term order
// and audit as in the UI). Source: CMO No. 39 s.2021 Sec. 6.1 (read on the local scan, 2026-11-13): "PE 1 and PE 2 are the core or foundation courses for
// PE 3 and 4". The document does not use the word "prerequisite"; the owner's rule pack treats it as an ordering. The rule table has no source or note
// field, so the source goes into an extra audit entry (SUBJECT_REQUIREMENT_SOURCE_NOTE) per rule. Existing rules are never removed.
// Nothing else is written: no compliance rule (the rule library needs migration 032), no elective group, no outcome link, no equivalency, no weight.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/authorization.php';
$actorId = null;
function current_user_id(): ?int { global $actorId; return $actorId; }
require_once __DIR__ . '/../shared/audit.php';
require_once __DIR__ . '/../modules/curriculum/services/CurriculumSubjectRequirementService.php';
require_once __DIR__ . '/../modules/curriculum/services/AcademicSequenceValidator.php';

$options = [];
foreach (array_slice($argv, 1) as $arg) if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m)) $options[$m[1]] = $m[2] ?? '1';
$fail = static function (string $message, int $code = 1): void { fwrite(STDERR, $message . "\n"); exit($code); };
$apply = isset($options['apply']);
$database = (string)($conn->query('SELECT DATABASE() AS d')->fetch_assoc()['d'] ?? '');
if ($database === '') $fail('No database selected.');
echo "Database: $database\n", $apply ? "Mode: APPLY\n" : "Mode: dry run (nothing is written)\n";
if ($apply) {
    if (($options['confirm-database'] ?? '') !== $database) $fail("Refusing to write: --confirm-database must equal the connected database name ($database).");
    $backup = (string)($options['backup-file'] ?? '');
    if ($backup === '' || !is_file($backup) || filesize($backup) <= 0) $fail('Refusing to write: --backup-file must be an existing, non-empty file.');
    $actorId = (int)($options['actor-user-id'] ?? 0);
    if ($actorId <= 0) $fail('--actor-user-id is required.');
    $stmt = $conn->prepare('SELECT id FROM users WHERE id=? AND is_active=1'); $stmt->bind_param('i', $actorId); $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) $fail('--actor-user-id must be an active user.');
    $stmt->close();
}
$rows = static function (string $sql, string $types = '', array $params = []) use ($conn): array {
    if ($types === '') return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    $stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute();
    $out = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); return $out;
};
$actions = [];
$plan = static function (string $text, callable $run) use (&$actions): void { $actions[] = [$text, $run]; echo "  PLAN $text\n"; };

echo "\n== PE prerequisites (CMO 39 s.2021 Sec. 6.1)\n";
$version = $rows("SELECT v.id FROM curriculum_versions v JOIN curricula c ON c.id = v.curriculum_id JOIN academic_programs ap ON ap.id = c.academic_program_id
    WHERE UPPER(ap.program_code) = 'BSIT' ORDER BY (SELECT COUNT(*) FROM curriculum_version_subjects p WHERE p.curriculum_version_id = v.id) DESC, v.id LIMIT 1")[0] ?? null;
if (!$version) $fail('No BSIT version found.');
$vid = (int)$version['id'];
$subjectId = static fn(string $code) => $rows('SELECT p.subject_id FROM curriculum_version_subjects p JOIN subjects s ON s.id = p.subject_id WHERE p.curriculum_version_id = ? AND REPLACE(UPPER(s.subject_code), " ", "") = ?', 'is', [$vid, $code]);
$service = new CurriculumSubjectRequirementService($conn);
$repo = new CurriculumSubjectRequirementRepository($conn);
foreach (['PE3', 'PE4'] as $target) foreach (['PE1', 'PE2'] as $req) {
    $t = $subjectId($target); $r = $subjectId($req);
    if (count($t) !== 1 || count($r) !== 1) { echo "  SKIP $req -> $target: not both placed once in version $vid\n"; continue; }
    $t = (int)$t[0]['subject_id']; $r = (int)$r[0]['subject_id'];
    $has = (int)$conn->query("SELECT COUNT(*) FROM curriculum_subject_requirements WHERE curriculum_version_id = $vid AND subject_id = $t AND required_subject_id = $r AND requirement_type = 'prerequisite'")->fetch_row()[0];
    if ($has) { echo "  $req is already a prerequisite of $target\n"; continue; }
    $check = AcademicSequenceValidator::validate($repo->allPlacements($vid), [['subject_id' => $t, 'required_subject_id' => $r, 'requirement_type' => 'prerequisite']]);
    if (!$check['valid']) { echo "  SKIP $req -> $target: term order is not valid\n"; continue; }
    $plan("add $req as prerequisite of $target in version $vid", function () use ($service, $vid, $t, $r, $req, $target, &$actorId) {
        $created = $service->create(['curriculum_version_id' => $vid, 'subject_id' => $t, 'required_subject_id' => $r, 'requirement_type' => 'prerequisite'], $actorId);
        audit_log_required('SUBJECT_REQUIREMENT_SOURCE_NOTE', 'curriculum_subject_requirement', (int)($created['id'] ?? 0) ?: null, 'Prerequisite source note recorded.',
            ['source' => 'CMO No. 39 s.2021 Sec. 6.1: PE 1 and PE 2 are the core or foundation courses for PE 3 and 4', 'curriculum_version_id' => $vid, 'rule' => "$req -> $target"]);
    });
}
$before = (int)$conn->query('SELECT COUNT(*) FROM curriculum_subject_requirements')->fetch_row()[0];
echo "\nBefore: prerequisite rules $before\n";
if (!$apply) { echo count($actions) . " planned change(s). Run again with --apply --confirm-database=$database --actor-user-id=<id> --backup-file=<file> to write.\n"; exit(0); }
$done = 0;
foreach ($actions as [$text, $run]) {
    try { $run(); $done++; echo "  DONE $text\n"; } catch (Throwable $e) { echo "  FAILED $text: " . get_class($e) . ': ' . $e->getMessage() . "\n"; }
}
echo "\nApplied $done of " . count($actions) . " change(s). After: prerequisite rules " . (int)$conn->query('SELECT COUNT(*) FROM curriculum_subject_requirements')->fetch_row()[0] . "\n";
exit($done === count($actions) ? 0 : 2);
