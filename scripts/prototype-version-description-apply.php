<?php
// Small data fix (2026-11-13). ONE script, dry-run by default, idempotent.
//
//   Dry run:  DB_NAME=<database> php scripts/prototype-version-description-apply.php
//   Apply:    DB_NAME=<database> php scripts/prototype-version-description-apply.php --apply --confirm-database=<database> --actor-user-id=<id> --backup-file=<existing non-empty file>
//
//   1. The description of the real BSIT version (the BSIT version that holds the placed subjects) replaces "Synthetic pre-oral demonstration version ..."
//      with the factual text below. No service updates a version description, so this checks CurriculumVersionEditPolicy::assertEditable and writes
//      an audit entry (CURRICULUM_VERSION_UPDATE) in the same transaction.
//   2. The unplaced subject ROTC (title "English") is deleted ONLY IF its code is in neither database/seeds/bsit_preoral_manifest.json nor
//      database/seeds/bsit_dataset.php, and no row anywhere references it (every column named subject_id, required_subject_id or equivalent_subject_id,
//      placements, equivalencies, electives, program assignments, snapshots). Otherwise it is kept and the reason is printed. Audit entry SUBJECT_DELETE.
// No schema, migration or permission change. Only names, codes, ids and counts are printed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/authorization.php';
$actorId = null;
function current_user_id(): ?int { global $actorId; return $actorId; }
require_once __DIR__ . '/../shared/audit.php';
require_once __DIR__ . '/../modules/curriculum/services/CurriculumVersionEditPolicy.php';

const NEW_DESCRIPTION = "BSIT curriculum following CHED CMO No. 25, s. 2015. Subject list from the team's dataset; BCP approval not verified.";

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

// ---------------------------------------------------------------- 1. version description
echo "\n== 1. BSIT version description\n";
$version = $rows("SELECT v.id, v.version_label, v.description FROM curriculum_versions v JOIN curricula c ON c.id = v.curriculum_id JOIN academic_programs ap ON ap.id = c.academic_program_id
    WHERE UPPER(ap.program_code) = 'BSIT' ORDER BY (SELECT COUNT(*) FROM curriculum_version_subjects p WHERE p.curriculum_version_id = v.id) DESC, v.id LIMIT 1")[0] ?? null;
if (!$version) echo "  no BSIT version found\n";
elseif ((string)$version['description'] === NEW_DESCRIPTION) echo "  version id {$version['id']} already carries the text\n";
else {
    echo "  version id {$version['id']} \"{$version['version_label']}\": current description starts \"" . substr((string)$version['description'], 0, 40) . "...\"\n";
    $plan("set the description of version id {$version['id']}", function () use ($conn, $version) {
        $conn->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($conn))->assertEditable((int)$version['id']);
            $stmt = $conn->prepare('UPDATE curriculum_versions SET description = ? WHERE id = ?');
            $text = NEW_DESCRIPTION; $id = (int)$version['id']; $stmt->bind_param('si', $text, $id); $stmt->execute(); $stmt->close();
            audit_log_required('CURRICULUM_VERSION_UPDATE', 'curriculum_version', $id, 'Curriculum version description updated.', ['version_label' => $version['version_label'], 'field' => 'description']);
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    });
}

// ---------------------------------------------------------------- 2. subject ROTC
echo "\n== 2. Subject ROTC\n";
$subject = $rows("SELECT id, subject_code, subject_title FROM subjects WHERE subject_code = 'ROTC'")[0] ?? null;
if (!$subject) echo "  no subject with code ROTC (already gone)\n";
else {
    $sid = (int)$subject['id'];
    $manifest = (string)@file_get_contents(__DIR__ . '/../database/seeds/bsit_preoral_manifest.json');
    $dataset = (string)@file_get_contents(__DIR__ . '/../database/seeds/bsit_dataset.php');
    $inDataset = (bool)preg_match('/"subject_code"\s*:\s*"ROTC"/i', $manifest) || (bool)preg_match("/['\"]ROTC['\"]/", $dataset);
    $refs = [];
    foreach ($rows("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('subject_id', 'required_subject_id', 'equivalent_subject_id') AND TABLE_NAME NOT IN ('subjects', 'audit_logs') ORDER BY 1, 2") as $d) {
        $n = (int)$conn->query("SELECT COUNT(*) FROM `{$d['t']}` WHERE `{$d['c']}` = $sid")->fetch_row()[0];
        if ($n > 0) $refs["{$d['t']}.{$d['c']}"] = $n;
    }
    echo "  ROTC (id $sid): in the dataset manifest or seed: " . ($inDataset ? 'YES' : 'no') . '; references: ' . ($refs ? json_encode($refs) : 'none') . "\n";
    if ($inDataset || $refs) echo '  KEPT: ' . ($inDataset ? 'its code is in the BSIT dataset' : 'it is referenced') . ".\n";
    else $plan("delete the unplaced subject ROTC (id $sid)", function () use ($conn, $sid, $subject) {
        $conn->begin_transaction();
        try {
            $conn->query("DELETE FROM subjects WHERE id = $sid");
            audit_log_required('SUBJECT_DELETE', 'subject', $sid, 'Unplaced test subject removed by the prototype data cleanup.', ['subject_code' => $subject['subject_code']]);
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    });
}

$before = ['subjects' => (int)$conn->query('SELECT COUNT(*) FROM subjects')->fetch_row()[0]];
echo "\nBefore: " . json_encode($before) . "\n";
if (!$apply) { echo count($actions) . " planned change(s). Run again with --apply --confirm-database=$database --actor-user-id=<id> --backup-file=<file> to write.\n"; exit(0); }
$done = 0;
foreach ($actions as [$text, $run]) {
    try { $run(); $done++; echo "  DONE $text\n"; } catch (Throwable $e) { echo "  FAILED $text: " . get_class($e) . ': ' . $e->getMessage() . "\n"; }
}
echo "\nApplied $done of " . count($actions) . " change(s). After: " . json_encode(['subjects' => (int)$conn->query('SELECT COUNT(*) FROM subjects')->fetch_row()[0]]) . "\n";
exit($done === count($actions) ? 0 : 2);
