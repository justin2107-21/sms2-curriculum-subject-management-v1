<?php
// Owner decisions on the kept prototype items (2026-11-13). ONE script, dry-run by default, idempotent.
//
//   Dry run:  DB_NAME=<database> php scripts/prototype-decisions-apply.php
//   Apply:    DB_NAME=<database> php scripts/prototype-decisions-apply.php --apply --confirm-database=<database> --actor-user-id=<id> --backup-file=<existing non-empty file>
//
//   1. The BSIT 2026 version "CMO No. 62" is relabelled "CMO No. 25, s. 2015" (status stays Draft). CurriculumVersionService::rename.
//   2. The empty version "FINAL" of that curriculum is deleted when nothing references it (otherwise archived).
//   3. The curriculum COMP11 (with its empty version) is deleted when nothing references it (otherwise archived). The program ICT stays.
//   4. ROTC and GE 2 (now unplaced): REPORTED only. They are removed only if no reference exists AND neither code nor title appears in the BSIT
//      dataset (database/seeds/bsit_preoral_manifest.json) or the CMO 25 course tables; any doubt keeps them, and the reason is printed.
//   5. BPM101 and CAP101 (inactive, placed in the BSIT version): set Active (SubjectService::update) when they are in the BSIT dataset; the
//      placements stay. If they are in neither source and unplaced they would be left alone and reported.
// No schema, migration or permission change. Nothing but names, codes and ids is printed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/authorization.php';
$actorId = null;
function current_user_id(): ?int { global $actorId; return $actorId; }
require_once __DIR__ . '/../shared/audit.php';
require_once __DIR__ . '/../modules/curriculum/services/CurriculumVersionService.php';
require_once __DIR__ . '/../modules/curriculum/services/SubjectService.php';

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
$scalar = static fn(string $sql, string $types = '', array $params = []) => array_values(($rows($sql, $types, $params)[0] ?? [null]))[0];
$ids = static fn(array $list): string => $list ? implode(',', array_map('intval', $list)) : '0';
$actions = []; $notes = [];
$say = static function (string $line): void { echo $line, "\n"; };
$plan = static function (string $text, callable $run) use (&$actions, $say): void { $actions[] = [$text, $run]; $say('  PLAN ' . $text); };
$audit = static fn(string $action, string $type, int $id, string $text, array $meta) => audit_log_required($action, $type, $id, $text, $meta);

// Tables and columns that point at a version, a placement, a curriculum or a program (found from the schema, so nothing is missed).
$dependents = $rows("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
    AND (COLUMN_NAME REGEXP '(^|_)version_id$' OR COLUMN_NAME IN ('curriculum_version_subject_id', 'curriculum_id'))
    AND TABLE_NAME NOT IN ('audit_logs', 'curriculum_versions', 'curricula') ORDER BY 1, 2");
// Everything that references the given versions/curriculum, other than the versions' own rows. Placements count as references here
// (an "empty" version must have none).
$references = function (array $versionIds, ?int $curriculumId) use ($dependents, $scalar, $ids, $rows): array {
    $out = [];
    $placementIds = array_map('intval', array_column($rows('SELECT id FROM curriculum_version_subjects WHERE curriculum_version_id IN (' . $ids($versionIds) . ')'), 'id'));
    foreach ($dependents as $d) {
        $list = match (true) { $d['c'] === 'curriculum_version_subject_id' => $placementIds, $d['c'] === 'curriculum_id' => $curriculumId ? [$curriculumId] : [], default => $versionIds };
        if (!$list) continue;
        $n = (int)$scalar("SELECT COUNT(*) FROM `{$d['t']}` WHERE `{$d['c']}` IN (" . $ids($list) . ')');
        if ($n > 0) $out["{$d['t']}.{$d['c']}"] = $n;
    }
    // Versions of other curricula that use these versions as a baseline.
    $n = (int)$scalar('SELECT COUNT(*) FROM curriculum_versions WHERE baseline_version_id IN (' . $ids($versionIds) . ') AND id NOT IN (' . $ids($versionIds) . ')');
    if ($n > 0) $out['curriculum_versions.baseline_version_id'] = $n;
    return $out;
};

// ---------------------------------------------------------------- the real BSIT curriculum (by data)
$say("\n== Real BSIT curriculum");
$real = null;
$bsit = $rows("SELECT c.id, c.curriculum_code, c.curriculum_name, (SELECT COUNT(*) FROM curriculum_version_subjects p JOIN curriculum_versions v ON v.id = p.curriculum_version_id WHERE v.curriculum_id = c.id) AS placed
    FROM curricula c JOIN academic_programs ap ON ap.id = c.academic_program_id WHERE UPPER(ap.program_code) = 'BSIT' ORDER BY c.id");
$big = array_values(array_filter($bsit, fn($c) => (int)$c['placed'] >= 10));
if (count($big) === 1) { $real = $big[0]; $say("  curriculum id {$real['id']} {$real['curriculum_code']} ({$real['placed']} placed)"); }
else $say('  AMBIGUOUS or missing: ' . count($big) . ' BSIT curricula with 10+ placements; nothing changed for items 1, 2 and 5.');
$realId = $real ? (int)$real['id'] : 0;
$main = $real ? ($rows('SELECT v.id, v.version_label, v.status FROM curriculum_versions v WHERE v.curriculum_id = ? ORDER BY (SELECT COUNT(*) FROM curriculum_version_subjects p WHERE p.curriculum_version_id = v.id) DESC, v.id LIMIT 1', 'i', [$realId])[0] ?? null) : null;

// ---------------------------------------------------------------- 1. version label
$say("\n== 1. Version label");
const NEW_LABEL = 'CMO No. 25, s. 2015';
if (!$main) $say('  skipped');
elseif ($main['version_label'] === NEW_LABEL) $say("  version id {$main['id']} already labelled \"" . NEW_LABEL . '"');
else {
    $versionService = new CurriculumVersionService($conn);
    $plan("relabel version id {$main['id']} \"{$main['version_label']}\" ({$main['status']}) to \"" . NEW_LABEL . '"; status stays ' . $main['status'], function () use ($versionService, $main, &$actorId) {
        $versionService->rename((int)$main['id'], ['version_label' => NEW_LABEL], $actorId);
    });
}

// ---------------------------------------------------------------- 2. the empty version "FINAL"
$say("\n== 2. Empty version \"FINAL\"");
$deleteVersions = function (string $label, array $versionIds, ?int $curriculumId, array $meta) use ($conn, $audit, $ids): callable {
    return function () use ($conn, $label, $versionIds, $curriculumId, $audit, $meta) {
        $conn->begin_transaction();
        try {
            foreach ($versionIds as $vid) {
                $conn->query("DELETE FROM curriculum_versions WHERE id = $vid");
                $audit('CURRICULUM_VERSION_DELETE', 'curriculum_version', (int)$vid, "$label removed by the prototype decisions.", $meta);
            }
            if ($curriculumId) {
                $conn->query("DELETE FROM curricula WHERE id = $curriculumId");
                $audit('CURRICULUM_DELETE', 'curriculum', $curriculumId, "$label removed by the prototype decisions.", $meta);
            }
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    };
};
$archiveVersion = function (int $vid, string $label) use ($conn, $audit): callable {
    return function () use ($conn, $vid, $label, $audit) {
        $conn->begin_transaction();
        try {
            $conn->query("UPDATE curriculum_versions SET status = 'archived' WHERE id = $vid AND status <> 'archived'");
            $audit('CURRICULUM_VERSION_ARCHIVE', 'curriculum_version', $vid, "$label archived by the prototype decisions (referenced elsewhere).", []);
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    };
};
if ($real) {
    $final = $rows("SELECT id, version_label, status FROM curriculum_versions WHERE curriculum_id = ? AND TRIM(UPPER(version_label)) = 'FINAL'", 'i', [$realId]);
    if (!$final) $say('  no version "FINAL" (already gone)');
    foreach ($final as $v) {
        $refs = $references([(int)$v['id']], null);
        $say("  version id {$v['id']} ({$v['status']}): references " . ($refs ? json_encode($refs) : 'none (no placements, mappings, prerequisites, snapshots, comparisons)'));
        if ($refs) { $plan("ARCHIVE version id {$v['id']} FINAL (referenced)", $archiveVersion((int)$v['id'], 'Version FINAL')); $notes[] = "Version FINAL archived, not deleted: " . json_encode($refs); }
        else $plan("DELETE version id {$v['id']} FINAL", $deleteVersions('Empty version FINAL', [(int)$v['id']], null, ['curriculum_id' => $realId, 'version_label' => $v['version_label']]));
    }
}

// ---------------------------------------------------------------- 3. curriculum COMP11
$say("\n== 3. Curriculum COMP11");
$comp = $rows("SELECT c.id, c.curriculum_code, c.academic_program_id, ap.program_code FROM curricula c JOIN academic_programs ap ON ap.id = c.academic_program_id WHERE UPPER(c.curriculum_code) = 'COMP11'");
if (!$comp) $say('  no curriculum COMP11 (already gone)');
foreach ($comp as $c) {
    $vids = array_map('intval', array_column($rows('SELECT id FROM curriculum_versions WHERE curriculum_id = ?', 'i', [(int)$c['id']]), 'id'));
    $refs = $references($vids, (int)$c['id']);
    $say("  curriculum id {$c['id']} (program {$c['program_code']}), versions " . count($vids) . ': references ' . ($refs ? json_encode($refs) : 'none'));
    if ($refs) {
        $plan("ARCHIVE curriculum id {$c['id']} COMP11 (referenced)", function () use ($conn, $c, $audit, &$actorId) {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("UPDATE curricula SET archive_previous_status = status, status = 'inactive', archived_at = NOW(), archived_by = ? WHERE id = ? AND archived_at IS NULL");
                $stmt->bind_param('ii', $actorId, $c['id']); $stmt->execute();
                $audit('CURRICULUM_ARCHIVE', 'curriculum', (int)$c['id'], 'Curriculum COMP11 archived by the prototype decisions (referenced elsewhere).', []);
                $conn->commit();
            } catch (Throwable $e) { $conn->rollback(); throw $e; }
        });
        $notes[] = "Curriculum COMP11 archived, not deleted: " . json_encode($refs);
    } else $plan("DELETE curriculum id {$c['id']} COMP11 with " . count($vids) . ' empty version(s)', $deleteVersions('Curriculum COMP11', $vids, (int)$c['id'], ['curriculum_code' => $c['curriculum_code']]));
}
$ict = $rows("SELECT id, (SELECT COUNT(*) FROM curricula c WHERE c.academic_program_id = ap.id) AS n FROM academic_programs ap WHERE UPPER(program_code) = 'ICT'")[0] ?? null;
if ($ict) { $left = (int)$ict['n'] - count(array_filter($comp, fn($c) => (int)$c['academic_program_id'] === (int)$ict['id'])); $say("  program ICT stays; curricula after this step: $left" . ($left === 0 ? ' (no curriculum yet)' : '')); }

// ---------------------------------------------------------------- 4 and 5. subjects, evidence
$say("\n== 4 and 5. Subjects (evidence)");
$manifest = json_decode((string)@file_get_contents(__DIR__ . '/../database/seeds/bsit_preoral_manifest.json'), true)['subjects'] ?? [];
$norm = static fn(string $s): string => preg_replace('/\s+/', ' ', strtolower(trim($s)));
$byCode = []; $byTitle = [];
foreach ($manifest as $s) { $byCode[$norm($s['subject_code'])] = $s['subject_code']; $byTitle[$norm($s['subject_name'])] = $s['subject_code']; }
$say('  dataset subjects read: ' . count($manifest));
// CMO 25 Sec. 8.1 general-education table (printed p. 10) names these categories; read from the local scan on 2026-11-13.
$cmo25Terms = ['english', 'filipino', 'mathematics', 'natural sciences', 'life & works of rizal', 'philippine history & culture'];
$subjectService = new SubjectService($conn);
foreach (['ROTC', 'GE 2'] as $code) {
    $s = $rows('SELECT id, subject_code, subject_title, status FROM subjects WHERE subject_code = ?', 's', [$code])[0] ?? null;
    if (!$s) { $say("  $code: not in the master list (already gone)"); continue; }
    $sid = (int)$s['id'];
    $placed = (int)$scalar('SELECT COUNT(*) FROM curriculum_version_subjects WHERE subject_id = ?', 'i', [$sid]);
    $eq = (int)$scalar('SELECT COUNT(*) FROM subject_equivalencies WHERE subject_id = ? OR equivalent_subject_id = ?', 'ii', [$sid, $sid]);
    $el = (int)$scalar('SELECT COUNT(*) FROM curriculum_elective_group_subjects WHERE subject_id = ?', 'i', [$sid]);
    $pa = (int)$scalar('SELECT COUNT(*) FROM program_subject_assignments WHERE subject_id = ?', 'i', [$sid]);
    $a = ($placed + $eq + $el + $pa) === 0;
    $reasons = [];
    if (isset($byCode[$norm($s['subject_code'])])) $reasons[] = 'code matches dataset code ' . $byCode[$norm($s['subject_code'])];
    elseif (isset($byCode[str_replace(' ', '', $norm($s['subject_code']))])) $reasons[] = 'code without the space equals dataset code ' . $byCode[str_replace(' ', '', $norm($s['subject_code']))];
    if (isset($byTitle[$norm($s['subject_title'])])) $reasons[] = 'title matches dataset subject ' . $byTitle[$norm($s['subject_title'])];
    if (in_array($norm($s['subject_title']), $cmo25Terms, true)) $reasons[] = 'title "' . $s['subject_title'] . '" is a general-education category in CMO 25 Sec. 8.1 (printed p. 10)';
    if ($code === 'ROTC') $reasons[] = 'ROTC is an NSTP component named in RA 9163 Sec. 4 and in the client reply (Q5: ROTC and CWTS are offered)';
    $say("  $code (id $sid, {$s['status']}): placements $placed, equivalencies $eq, elective $el, program assignments $pa -> condition (a) " . ($a ? 'met' : 'NOT met') . '; condition (b) ' . ($reasons ? 'NOT met: ' . implode('; ', $reasons) : 'met'));
    if (!$a || $reasons) $notes[] = "Subject $code kept: " . (!$a ? 'it is referenced' : implode('; ', $reasons)) . '.';
    else $notes[] = "Subject $code meets both conditions but removal is not implemented in this script; decide separately.";
}
foreach (['BPM101', 'CAP101'] as $code) {
    $s = $rows('SELECT * FROM subjects WHERE subject_code = ?', 's', [$code])[0] ?? null;
    if (!$s) { $say("  $code: not found"); continue; }
    $inDataset = isset($byCode[$norm($code)]) && $norm($byCode[$norm($code)]) === $norm($code);
    $titleMatch = isset($byTitle[$norm($s['subject_title'])]);
    $placed = (int)$scalar('SELECT COUNT(*) FROM curriculum_version_subjects WHERE subject_id = ?', 'i', [(int)$s['id']]);
    $say("  $code (id {$s['id']}, {$s['status']}): in the BSIT dataset by code " . ($inDataset ? 'yes' : 'no') . ', by title ' . ($titleMatch ? 'yes' : 'no') . ", placements $placed");
    if (!$inDataset && !$titleMatch) { $notes[] = "$code is not in the BSIT dataset; left unchanged (CMO 25 course list not machine-readable here)."; continue; }
    if ($s['status'] === 'active') { $say("    already Active"); continue; }
    $plan("set subject $code Active (dataset subject; the placement stays)", function () use ($subjectService, $s) {
        $subjectService->update((int)$s['id'], ['subject_code' => $s['subject_code'], 'subject_title' => $s['subject_title'], 'description' => $s['description'], 'units' => $s['units'],
            'lecture_units' => $s['lecture_units'], 'laboratory_units' => $s['laboratory_units'], 'status' => 'active']);
    });
}

// ---------------------------------------------------------------- counts and apply
$tables = ['academic_programs', 'curricula', 'curriculum_versions', 'curriculum_version_subjects', 'subjects'];
$count = static function () use ($conn, $tables): array { $o = []; foreach ($tables as $t) $o[$t] = (int)$conn->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0]; return $o; };
$before = $count();
$realPlaced = $realId ? (int)$conn->query("SELECT COUNT(*) FROM curriculum_version_subjects p JOIN curriculum_versions v ON v.id = p.curriculum_version_id WHERE v.curriculum_id = $realId")->fetch_row()[0] : 0;
echo "\n== Kept / reported\n"; foreach (array_unique($notes) as $n) echo "  - $n\n";
echo "\nBefore: " . json_encode($before) . "; BSIT 2026 placements: $realPlaced\n";
if (!$apply) { echo count($actions) . " planned change(s). Run again with --apply --confirm-database=$database --actor-user-id=<id> --backup-file=<file> to write.\n"; exit(0); }
$done = 0;
foreach ($actions as [$text, $run]) {
    try { $run(); $done++; echo "  DONE $text\n"; } catch (Throwable $e) { echo "  FAILED $text: " . get_class($e) . ': ' . $e->getMessage() . "\n"; }
}
$after = $count();
$placedAfter = $realId ? (int)$conn->query("SELECT COUNT(*) FROM curriculum_version_subjects p JOIN curriculum_versions v ON v.id = p.curriculum_version_id WHERE v.curriculum_id = $realId")->fetch_row()[0] : 0;
echo "\nApplied $done of " . count($actions) . " change(s).\nAfter:  " . json_encode($after) . "; BSIT 2026 placements: $placedAfter (" . ($placedAfter === $realPlaced ? 'unchanged' : 'CHANGED') . ")\n";
foreach ($tables as $t) if ($before[$t] !== $after[$t]) echo "  $t: {$before[$t]} -> {$after[$t]}\n";
exit($done === count($actions) ? 0 : 2);
