<?php
// One real validation of BSIT 2026 against the seeded library rules (2026-11-13). Dry run by default, idempotent.
//
//   Dry run:  DB_NAME=<database> php scripts/prototype-compliance-validate.php
//   Apply:    DB_NAME=<database> php scripts/prototype-compliance-validate.php --apply --confirm-database=<database> --actor-user-id=<id> --backup-file=<existing non-empty file>
//
// The run goes through ComplianceValidationService::run, the same path as the "Run validation" button (so the run is stored in History with its
// results and an audit entry). Nothing is hand-written. It plans a run only if the BSIT version has no run that already holds library results.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/authorization.php';
$actorId = null;
function current_user_id(): ?int { global $actorId; return $actorId; }
require_once __DIR__ . '/../shared/audit.php';
require_once __DIR__ . '/../modules/curriculum/services/ComplianceValidationService.php';

$options = [];
foreach (array_slice($argv, 1) as $arg) if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m)) $options[$m[1]] = $m[2] ?? '1';
$fail = static function (string $message, int $code = 1): void { fwrite(STDERR, $message . "\n"); exit($code); };
$apply = isset($options['apply']);
$database = (string)($conn->query('SELECT DATABASE() AS d')->fetch_assoc()['d'] ?? '');
if ($database === '') $fail('No database selected.');
echo "Database: $database\n", $apply ? "Mode: APPLY\n" : "Mode: dry run (nothing is written)\n";
if (!$conn->query("SHOW TABLES LIKE 'compliance_library_rules'")->num_rows) $fail('The rule library (migration 032) is not applied in this database.');
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
$version = $conn->query("SELECT v.id FROM curriculum_versions v JOIN curricula c ON c.id = v.curriculum_id JOIN academic_programs ap ON ap.id = c.academic_program_id
    WHERE UPPER(ap.program_code) = 'BSIT' ORDER BY (SELECT COUNT(*) FROM curriculum_version_subjects p WHERE p.curriculum_version_id = v.id) DESC, v.id LIMIT 1")->fetch_assoc();
if (!$version) $fail('No BSIT version found.');
$vid = (int)$version['id'];
$rules = (int)$conn->query('SELECT COUNT(*) FROM compliance_library_rules WHERE active = 1')->fetch_row()[0];
$existing = (int)$conn->query("SELECT COUNT(DISTINCT r.id) FROM compliance_validation_runs r JOIN compliance_library_results lr ON lr.validation_run_id = r.id WHERE r.curriculum_version_id = $vid")->fetch_row()[0];
echo "Version id $vid; active library rules: $rules; runs holding library results: $existing\n";
if ($existing > 0) { echo "0 planned change(s): a run with library results already exists.\n"; exit(0); }
if ($rules === 0) { echo "0 planned change(s): no library rule is seeded.\n"; exit(0); }
echo "  PLAN run one validation of version id $vid through the service\n";
if (!$apply) { echo "1 planned change(s). Run again with --apply --confirm-database=$database --actor-user-id=<id> --backup-file=<file> to write.\n"; exit(0); }
$result = (new ComplianceValidationService($conn))->run($vid, $actorId, false);
$summary = $result['summary'] ?? [];
echo 'DONE run id ' . (int)($result['run']['id'] ?? 0) . '; summary: ' . json_encode($summary) . "\n";
foreach (($result['results'] ?? []) as $r) echo '  - ' . ($r['rule_name_snapshot'] ?? '') . ' => ' . ($r['result_label'] ?? $r['result_status'] ?? '') . ' | ' . ($r['actual_value_snapshot'] ?? '') . "\n";
