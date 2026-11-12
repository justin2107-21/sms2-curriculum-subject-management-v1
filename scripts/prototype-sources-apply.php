<?php
// Factual sources for the BSIT 2026 version (2026-11-13). ONE script, dry-run by default, idempotent.
//
//   Dry run:  DB_NAME=<database> php scripts/prototype-sources-apply.php
//   Apply:    DB_NAME=<database> php scripts/prototype-sources-apply.php --apply --confirm-database=<database> --actor-user-id=<id> --backup-file=<existing non-empty file>
//
// Needs the 14 compliance references of scripts/compliance-seed-references.php (run that first; without migration 032 it writes references only, no rule).
// What it writes, and nothing else:
//   * a locator note on each of the eight sources the BSIT 2026 version uses (compliance_references.notes, through ComplianceReferenceService);
//   * the attachment of those sources to the version (CurriculumVersionReferenceService::attach), CMO 25 as the curriculum source ("school_curriculum")
//     so that "Reference basis" no longer reads "Unknown", the others as regulatory or other;
//   * a source note on the existing elective group description (when that group can be edited).
// Version role, baseline and snapshot capture are NOT touched; nothing here says BCP approved, adopted or complies with anything.
// Every locator below was read from the local reference file on 2026-11-13 (SHA-256 values match the Stage 1 record in the seed script). Where a locator
// could not be verified the source carries no locator and says so. Notes are paraphrases, not quotations.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/authorization.php';
$actorId = null;
function current_user_id(): ?int { global $actorId; return $actorId; }
require_once __DIR__ . '/../shared/audit.php';
require_once __DIR__ . '/../modules/curriculum/services/ComplianceReferenceService.php';
require_once __DIR__ . '/../modules/curriculum/services/CurriculumVersionReferenceService.php';
require_once __DIR__ . '/../modules/curriculum/services/CurriculumElectiveGroupService.php';

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
$actions = []; $notes = [];
$say = static function (string $line): void { echo $line, "\n"; };
$plan = static function (string $text, callable $run) use (&$actions, $say): void { $actions[] = [$text, $run]; $say('  PLAN ' . $text); };

// code => [purpose on the version, locator note]. Locators verified against the local files on 2026-11-13.
$SOURCES = [
    'CMO 25 s.2015' => ['school_curriculum',
        'Locators (checked on the local scan, 2026-11-13): Sec. 8.1 General Description, printed p. 10: BSIT minimum total 146 units, split GE 54, common courses 18, professional courses 48, professional electives 12, additional math 0, PE 8, NSTP 6. '
        . 'BSIT graduate outcomes IT01-IT13: Sec. 6.3, table C, printed pp. 8-9 (IT01-IT10 on p. 8, IT11-IT13 on p. 9). Sec. 6.4 of the same document points to CMO 46 s.2012. Institutional adoption is not verified.'],
    'CMO 20 s.2013' => ['regulatory',
        'Locator (checked on the local scan, 2026-11-13): Sec. 3 Revised Core Courses, printed pp. 5-6: the general education curriculum is a minimum of 36 units, 24 units of core courses, 9 units of elective courses and 3 units on the life and works of Rizal.'],
    'Advisory on CMO 20 (2019)' => ['regulatory',
        'Locator (checked on the local file, 2026-11-13): p. 1, advisory dated 9 December 2019: higher education institutions are to fully implement the 24 units of GE core courses and the 9 units of GE electives of CMO 20 s.2013 from AY 2019-2020 (it also revokes CMO 57 s.2017 and CMO 04 s.2018).'],
    'CMO 39 s.2021' => ['regulatory',
        'Locator (checked on the local scan, 2026-11-13): Sec. 6.1 (pdf p. 6): the tertiary PE program is PE courses with a total of 8 units; PE 1 and PE 2 are the core or foundation courses for PE 3 and PE 4.'],
    'RA 9163' => ['regulatory',
        'Locators (checked on the local text, 2026-11-13): Sec. 4 (NSTP components; a requisite for graduation), Sec. 5 (students complete one of the NSTP components as a requisite for graduation), Sec. 6 (each component is undertaken for an academic period of two semesters).'],
    'Revised IRR of RA 9163' => ['regulatory',
        'No section or page locator: the local file is a scan and the matching provision was not located. Attached as the implementing rules of RA 9163; read the provisions on NSTP duration and components in the document itself.'],
    'CMO 46 s.2012' => ['other',
        'No section or page locator: attached as the outcomes-based and typology-based quality-assurance policy that CMO 25 Sec. 6.4 refers to.'],
    'CHED Handbook on Typology, OBE and ISA' => ['other',
        'No section or page locator: attached as the CHED handbook on typology, outcomes-based education and institutional sustainability assessment (2014), the framework behind the outcomes map.'],
];

// ---------------------------------------------------------------- the version
$say("\n== The BSIT 2026 version");
$main = $rows("SELECT v.id, v.version_label, v.status, v.version_role, v.review_status, v.baseline_version_id, c.curriculum_code FROM curriculum_versions v JOIN curricula c ON c.id = v.curriculum_id JOIN academic_programs ap ON ap.id = c.academic_program_id
    WHERE UPPER(ap.program_code) = 'BSIT' ORDER BY (SELECT COUNT(*) FROM curriculum_version_subjects p WHERE p.curriculum_version_id = v.id) DESC, v.id LIMIT 1")[0] ?? null;
if (!$main) $fail('No BSIT version found.');
$versionId = (int)$main['id'];
echo "  version id $versionId \"{$main['version_label']}\" of {$main['curriculum_code']}: status {$main['status']}, role {$main['version_role']} (left unchanged)\n";

// ---------------------------------------------------------------- the references
$say("\n== Sources (locator notes and attachment)");
$referenceService = new ComplianceReferenceService($conn);
$attachService = new CurriculumVersionReferenceService($conn);
$missing = [];
foreach ($SOURCES as $code => [$purpose, $note]) {
    $ref = $rows('SELECT * FROM compliance_references WHERE reference_code = ? ORDER BY id LIMIT 1', 's', [$code])[0] ?? null;
    if (!$ref) { $missing[] = $code; $say("  MISSING source $code (run scripts/compliance-seed-references.php --apply first)"); continue; }
    $rid = (int)$ref['id'];
    if (trim((string)$ref['notes']) !== $note) {
        $plan("write the locator note on source $code (id $rid)", function () use ($referenceService, $rid, $ref, $note) {
            $referenceService->update($rid, ['reference_type' => $ref['reference_type'], 'source_kind' => $ref['source_kind'], 'supersedes_reference_id' => $ref['supersedes_reference_id'],
                'reference_code' => $ref['reference_code'], 'title' => $ref['title'], 'issuing_body' => $ref['issuing_body'], 'source_url' => $ref['source_url'],
                'effective_date' => $ref['effective_date'] ?? '', 'notes' => $note, 'verification_status' => $ref['verification_status']]);
        });
    } else $say("  note on $code already written");
    $linked = (int)$conn->query("SELECT COUNT(*) FROM curriculum_version_references WHERE curriculum_version_id = $versionId AND compliance_reference_id = $rid AND reference_purpose = '$purpose'")->fetch_row()[0];
    if (!$linked) {
        if ($main['version_role'] !== 'unclassified' || $main['review_status'] !== 'not_started' || $main['baseline_version_id'] !== null) { $say("  SKIP attach $code: the version is classified or in review"); $notes[] = "$code not attached: version is not an unclassified, not-started version."; continue; }
        $plan("attach $code to version $versionId as '$purpose'" . ($purpose === 'school_curriculum' ? ' (this sets "Reference basis")' : ''), function () use ($attachService, $versionId, $rid, $purpose, &$actorId) {
            $attachService->attach(['curriculum_version_id' => $versionId, 'compliance_reference_id' => $rid, 'reference_purpose' => $purpose], $actorId);
        });
    } else $say("  $code already attached as '$purpose'");
}
if ($missing && $apply) $fail('Refusing to write: sources missing (' . implode(', ', $missing) . '). Run scripts/compliance-seed-references.php --apply first.');

// ---------------------------------------------------------------- elective group note
$say("\n== Electives Manager");
$group = $rows('SELECT id, group_name, description, status FROM curriculum_elective_groups WHERE curriculum_version_id = ? ORDER BY id LIMIT 1', 'i', [$versionId])[0] ?? null;
$electiveNote = 'Source for the requirement: CMO 20 s.2013 Sec. 3 (printed pp. 5-6): 9 units of general education electives, reaffirmed by the 2019 CHED advisory. The course list in this group is the dataset selection, not a list required by CHED.';
if (!$group) $say('  no elective group in this version');
elseif (trim((string)$group['description']) === $electiveNote) $say("  group id {$group['id']} already carries the source note");
else {
    $groupService = new CurriculumElectiveGroupService($conn);
    $plan("write the source note on elective group id {$group['id']} \"{$group['group_name']}\" (status {$group['status']}; the service may refuse an archived group)", function () use ($groupService, $group, $electiveNote) {
        $groupService->update((int)$group['id'], ['group_name' => $group['group_name'], 'description' => $electiveNote]);
    });
}
$notes[] = 'Professional electives (CMO 25 Sec. 8.1, 12 units): no professional-elective group exists, so nothing was written for it.';

// ---------------------------------------------------------------- report-only
$say("\n== Report only (no field, nothing written)");
$rules = (int)$conn->query('SELECT COUNT(*) FROM curriculum_subject_requirements')->fetch_row()[0];
$say("  Prerequisites: $rules live rule(s); the table has no source or note field, so none of them carries a source on screen (NSTP 1 -> NSTP 2 is sourced in its audit entry: BCP client reply Q6, signed 2026-11-13).");
$say('  Subject Mapping outcome set: curriculum_outcome_catalog already carries document, section, printed page and SHA-256 for IT01-IT13 (CMO 25 Sec. 6.3, pp. 8-9).');
$say('  Grade Weighting, Equivalency, Offering History: no source exists (client declined; CHED sets no college weights); nothing invented, no weight changed.');
$say('  DepEd orders and memoranda: not attached anywhere (no SHS curricula exist).');
echo "\n== Notes\n"; foreach (array_unique($notes) as $n) echo "  - $n\n";

$counts = static fn(): array => ['references' => (int)$GLOBALS['conn']->query('SELECT COUNT(*) FROM compliance_references')->fetch_row()[0], 'version_references' => (int)$GLOBALS['conn']->query("SELECT COUNT(*) FROM curriculum_version_references WHERE curriculum_version_id = $GLOBALS[versionId]")->fetch_row()[0],
    'notes_written' => (int)$GLOBALS['conn']->query("SELECT COUNT(*) FROM compliance_references WHERE notes LIKE 'Locator%' OR notes LIKE 'Locators%' OR notes LIKE 'No section%'")->fetch_row()[0]];
$before = $counts();
echo "\nBefore: " . json_encode($before) . "\n";
if (!$apply) { echo count($actions) . " planned change(s). Run again with --apply --confirm-database=$database --actor-user-id=<id> --backup-file=<file> to write.\n"; exit(0); }
$done = 0;
foreach ($actions as [$text, $run]) {
    try { $run(); $done++; echo "  DONE $text\n"; } catch (Throwable $e) { echo "  FAILED $text: " . get_class($e) . ': ' . $e->getMessage() . "\n"; }
}
echo "\nApplied $done of " . count($actions) . " change(s).\nAfter:  " . json_encode($counts()) . "\n";
exit($done === count($actions) ? 0 : 2);
