<?php
// Final-defense prototype data (2026-11-13). ONE script, dry-run by default.
//
//   Dry run (read-only, prints the plan):
//     DB_NAME=<database> php scripts/prototype-data-finalize.php
//   Apply (after a backup):
//     DB_NAME=<database> php scripts/prototype-data-finalize.php --apply --confirm-database=<database> --actor-user-id=<id> --backup-file=<existing non-empty file>
//
// What it does, in this order, and nothing else:
//   A) Programs: the team-provided college programs and SHS strands (NOT client-confirmed). Matched by code, case-insensitively; a missing code
//      is created (active), an existing one only gets its name corrected. Through AcademicProgramService (validation and audit as in the UI).
//   B) Renames the real BSIT curriculum (the BSIT curriculum that holds the placed subjects, found by data) to "BSIT 2026"
//      (client Q1: "program code + start year"). Through CurriculumService. Code BSIT-2026, name BSIT 2026.
//   C) NSTP 1 as prerequisite of NSTP 2 (client Q6) in the versions of that curriculum that hold both, through
//      CurriculumSubjectRequirementService, only when the term order is valid. Source note goes to an extra audit entry.
//   D) Removes test data. Every candidate gets a dependency report; a candidate referenced by anything beyond its own placements is archived,
//      not deleted; one transaction per candidate, an audit entry per deleted or archived row. The real BSIT curriculum is never touched.
// No schema, migration or permission change. Row content is never printed beyond codes, names and ids.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/authorization.php';

$actorId = null;
// Audit rows take their actor from current_user_id(); in the CLI that is the named actor.
function current_user_id(): ?int { global $actorId; return $actorId; }
require_once __DIR__ . '/../shared/audit.php';
require_once __DIR__ . '/../modules/curriculum/services/AcademicProgramService.php';
require_once __DIR__ . '/../modules/curriculum/services/CurriculumService.php';
require_once __DIR__ . '/../modules/curriculum/services/CurriculumSubjectRequirementService.php';
require_once __DIR__ . '/../modules/curriculum/services/AcademicSequenceValidator.php';

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m)) $options[$m[1]] = $m[2] ?? '1';
}
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
    echo 'Backup file: ' . basename($backup) . ' (' . filesize($backup) . " bytes)\n";
}

// ---------------------------------------------------------------- helpers
$rows = static function (string $sql, string $types = '', array $params = []) use ($conn): array {
    if ($types === '') return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    $stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute();
    $out = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); return $out;
};
$scalar = static fn(string $sql, string $types = '', array $params = []) => array_values(($rows($sql, $types, $params)[0] ?? [null]))[0];
$ids = static fn(array $list): string => $list ? implode(',', array_map('intval', $list)) : '0';
$log = [];      // report lines
$actions = [];  // [description, callable]
$decide = [];   // "needs your decision"
$say = static function (string $line) use (&$log): void { $log[] = $line; echo $line, "\n"; };
$plan = static function (string $text, callable $run) use (&$actions, $say): void { $actions[] = [$text, $run]; $say('  PLAN ' . $text); };

// ---------------------------------------------------------------- the team-provided program list (not client-confirmed)
$college = [
    'BSIT' => 'Bachelor of Science in Information Technology', 'BSHM' => 'Bachelor of Science in Hospitality Management',
    'BSAIS' => 'Bachelor of Science in Accounting Information System', 'BSTM' => 'Bachelor of Science in Tourism Management',
    'BSOA' => 'Bachelor of Science in Office Administration', 'BSENTREP' => 'Bachelor of Science in Entrepreneurship',
    'BSBA' => 'Bachelor of Science in Business Administration', 'BLIS' => 'Bachelor of Library and Information Science',
    'BSCPE' => 'Bachelor of Science in Computer Engineering', 'BSP' => 'Bachelor of Science in Psychology',
    'BSCRIM' => 'Bachelor of Science in Criminology', 'BPED' => 'Bachelor of Science in Physical Education',
    'BTLED' => 'Bachelor of Science in Technological and Livelihood Education', 'BEED' => 'Bachelor of Science in Elementary Education',
    'BSED' => 'Bachelor of Science in Secondary Education',
];
// Strands: the code is also the name unless the team wrote a longer name. Each is its own flat entry (no TVL parent).
$shs = ['HUMSS' => null, 'GAS' => null, 'PA' => null, 'STEM' => null, 'ABM' => null, 'STEM-PBM' => null, 'SSHS-ACADEMIC' => null, 'SSHS-TECHPRO' => null,
    'ICT-CP' => null, 'ICT-HW' => null, 'ICT-ANIMATION' => 'ICT Animation', 'ICT-VISUAL-GRAPHICS' => 'ICT Visual Graphics', 'ICT-CCS' => null,
    'HECF' => null, 'HEHRS' => null, 'HEHO' => null, 'HETEM' => null];
const COLLEGE_TYPE = "Bachelor's Degree";
const SHS_TYPE = 'Senior High School';

// ---------------------------------------------------------------- A) programs
$say("\n== A) Programs (team-provided list, not client-confirmed)");
$existing = [];
foreach ($rows('SELECT id, program_code, program_name, program_type, status, archived_at FROM academic_programs') as $p) $existing[strtolower($p['program_code'])] = $p;
$programService = new AcademicProgramService($conn);
$counts = ['create' => 0, 'rename' => 0, 'same' => 0];
$nameEqualsCode = [];
foreach (['college' => $college, 'shs' => $shs] as $level => $list) {
    foreach ($list as $code => $name) {
        $wanted = $name ?? $code;
        if ($name === null) $nameEqualsCode[] = $code;
        $type = $level === 'shs' ? SHS_TYPE : COLLEGE_TYPE;
        $have = $existing[strtolower($code)] ?? null;
        if ($have === null) {
            $counts['create']++;
            $plan("create $level program $code", function () use ($programService, $code, $wanted, $type, &$actorId) {
                $programService->create(['program_code' => $code, 'program_name' => $wanted, 'program_type' => $type, 'status' => 'active'], $actorId);
            });
        } elseif ($have['archived_at'] !== null) {
            $say("  SKIP $code: the existing program is archived (restore it from Settings > Archives first)");
            $decide[] = "Program $code exists but is archived; not changed.";
        } elseif ($have['program_name'] !== $wanted) {
            $counts['rename']++;
            $plan("correct the name of {$have['program_code']} (id {$have['id']}) to \"$wanted\"", function () use ($programService, $have, $wanted) {
                $programService->update((int)$have['id'], ['program_code' => $have['program_code'], 'program_name' => $wanted, 'program_type' => $have['program_type'], 'status' => $have['status']]);
            });
        } else $counts['same']++;
    }
}
$say("  programs: {$counts['create']} to create, {$counts['rename']} to rename, {$counts['same']} already correct");
$say('  strands whose full name is the code (the team gave no longer name): ' . implode(', ', $nameEqualsCode));
$listed = array_map('strtolower', array_merge(array_keys($college), array_keys($shs)));
foreach ($existing as $key => $p) if (!in_array($key, $listed, true)) $say("  NOTE existing program not in the list: {$p['program_code']} (id {$p['id']}, {$p['program_type']}); left as is unless it is a test record in D");

// ---------------------------------------------------------------- B) the real BSIT curriculum
$say("\n== B) Real BSIT curriculum");
$bsit = $existing['bsit'] ?? null;
$real = null;
if (!$bsit) $say('  BSIT program not found; nothing to rename.');
else {
    $candidates = $rows('SELECT c.id, c.curriculum_code, c.curriculum_name, c.status, (SELECT COUNT(*) FROM curriculum_version_subjects p JOIN curriculum_versions v ON v.id = p.curriculum_version_id WHERE v.curriculum_id = c.id) AS placed
        FROM curricula c WHERE c.academic_program_id = ? ORDER BY c.id', 'i', [(int)$bsit['id']]);
    $big = array_values(array_filter($candidates, fn($c) => (int)$c['placed'] >= 10));
    foreach ($candidates as $c) $say("  BSIT curriculum id {$c['id']}: code {$c['curriculum_code']}, name \"{$c['curriculum_name']}\", {$c['placed']} placed");
    if (count($big) === 1) {
        $real = $big[0];
        $versions = $rows('SELECT id, version_label, status, (SELECT COUNT(*) FROM curriculum_version_subjects p WHERE p.curriculum_version_id = v.id) AS placed FROM curriculum_versions v WHERE curriculum_id = ?', 'i', [(int)$real['id']]);
        foreach ($versions as $v) $say("    version id {$v['id']} \"{$v['version_label']}\": status {$v['status']}, {$v['placed']} placed");
        if (!array_filter($versions, fn($v) => $v['status'] === 'active')) $decide[] = "The real BSIT curriculum (id {$real['id']}) has NO Active version; the version holding its placed subjects is " . implode('/', array_column(array_filter($versions, fn($v) => (int)$v['placed'] > 0), 'status')) . '. Status not changed (not requested).';
        $codeTaken = $scalar('SELECT COUNT(*) FROM curricula WHERE curriculum_code = ? AND id <> ?', 'si', ['BSIT-2026', (int)$real['id']]);
        if ($real['curriculum_name'] === 'BSIT 2026' && $real['curriculum_code'] === 'BSIT-2026') $say('  already named BSIT 2026');
        elseif ((int)$codeTaken > 0) $say('  SKIP: curriculum code BSIT-2026 is already used by another curriculum; nothing changed.');
        else {
            $curriculumService = new CurriculumService($conn);
            $plan("rename curriculum id {$real['id']} to name \"BSIT 2026\", code BSIT-2026 (was {$real['curriculum_code']})", function () use ($curriculumService, $real, $bsit) {
                $curriculumService->update((int)$real['id'], ['curriculum_code' => 'BSIT-2026', 'curriculum_name' => 'BSIT 2026', 'academic_program_id' => (int)$bsit['id'], 'status' => $real['status']]);
            });
        }
    } else {
        $say('  AMBIGUOUS: ' . count($big) . ' BSIT curricula hold 10 or more placed subjects (ids ' . implode(', ', array_column($big, 'id')) . '). Nothing renamed.');
        $decide[] = 'Real BSIT curriculum could not be identified by data; candidates: ids ' . implode(', ', array_column($big, 'id')) . '.';
    }
}

// ---------------------------------------------------------------- C) NSTP 1 -> NSTP 2
$say("\n== C) NSTP prerequisite (client reply Q6)");
if ($real) {
    $reqService = new CurriculumSubjectRequirementService($conn);
    $reqRepo = new CurriculumSubjectRequirementRepository($conn);
    $found = false;
    foreach ($rows('SELECT id, version_label FROM curriculum_versions WHERE curriculum_id = ? ORDER BY id', 'i', [(int)$real['id']]) as $v) {
        $vid = (int)$v['id'];
        $find = fn(string $n) => $rows("SELECT p.subject_id FROM curriculum_version_subjects p JOIN subjects s ON s.id = p.subject_id WHERE p.curriculum_version_id = ? AND (REPLACE(UPPER(s.subject_code), ' ', '') = ? OR UPPER(s.subject_title) LIKE ?)", 'iss', [$vid, "NSTP$n", "NATIONAL SERVICE TRAINING PROGRAM $n%"]);
        $a = $find('1'); $b = $find('2');
        if (count($a) !== 1 || count($b) !== 1) continue;
        $found = true; $s1 = (int)$a[0]['subject_id']; $s2 = (int)$b[0]['subject_id'];
        $already = (int)$scalar("SELECT COUNT(*) FROM curriculum_subject_requirements WHERE curriculum_version_id = ? AND subject_id = ? AND required_subject_id = ? AND requirement_type = 'prerequisite'", 'iii', [$vid, $s2, $s1]);
        if ($already) { $say("  version id $vid: NSTP 1 is already a prerequisite of NSTP 2"); continue; }
        $check = AcademicSequenceValidator::validate($reqRepo->allPlacements($vid), [['subject_id' => $s2, 'required_subject_id' => $s1, 'requirement_type' => 'prerequisite']]);
        if (!$check['valid']) { $say("  version id $vid: NOT added, term order is not valid (NSTP 1 must be in an earlier term than NSTP 2)"); $decide[] = "NSTP prerequisite not added in version $vid: term order invalid."; continue; }
        $plan("add NSTP 1 (subject $s1) as prerequisite of NSTP 2 (subject $s2) in version id $vid", function () use ($reqService, $vid, $s1, $s2, &$actorId) {
            $created = $reqService->create(['curriculum_version_id' => $vid, 'subject_id' => $s2, 'required_subject_id' => $s1, 'requirement_type' => 'prerequisite'], $actorId);
            audit_log_required('SUBJECT_REQUIREMENT_SOURCE_NOTE', 'curriculum_subject_requirement', (int)($created['id'] ?? 0) ?: null,
                'Prerequisite source note recorded.', ['source' => 'BCP client reply Q6 (signed 2026-11-13)', 'curriculum_version_id' => $vid]);
        });
    }
    if (!$found) $say('  NSTP 1 and NSTP 2 are not both placed in the real BSIT curriculum; nothing created.');
} else $say('  skipped (no real BSIT curriculum identified)');

// ---------------------------------------------------------------- D) test data
$say("\n== D) Test data");
$realId = $real ? (int)$real['id'] : 0;
// Tables that reference a curriculum version, a placement or a curriculum (found from the schema, so nothing is missed).
$dependents = $rows("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
    AND COLUMN_NAME IN ('curriculum_version_id', 'curriculum_version_subject_id', 'baseline_version_id', 'curriculum_id', 'academic_program_id')
    AND TABLE_NAME NOT IN ('curriculum_version_subjects', 'audit_logs') ORDER BY 1, 2");
$dependencyReport = function (string $kind, array $versionIds, array $placementIds, array $curriculumIds, array $programIds) use ($dependents, $scalar, $ids): array {
    $out = [];
    foreach ($dependents as $d) {
        $t = $d['t']; $c = $d['c'];
        if ($t === 'curriculum_versions' && $c === 'curriculum_id') continue;      // the candidate's own versions
        if ($t === 'curricula' && $c === 'academic_program_id' && $kind === 'curriculum') continue;
        $list = match ($c) { 'curriculum_version_id', 'baseline_version_id' => $versionIds, 'curriculum_version_subject_id' => $placementIds, 'curriculum_id' => $curriculumIds, default => $programIds };
        if (!$list) continue;
        // For baseline_version_id only references from versions OUTSIDE the candidate count.
        $extra = ($t === 'curriculum_versions' && $c === 'baseline_version_id') ? ' AND id NOT IN (' . $ids($versionIds) . ')' : '';
        $n = (int)$scalar("SELECT COUNT(*) FROM `$t` WHERE `$c` IN (" . $ids($list) . ")$extra");
        if ($n > 0) $out["$t.$c"] = $n;
    }
    return $out;
};
$delete = [];   // candidates: ['label','kind','ids'...]
$audit = static fn(string $action, string $type, int $id, string $text, array $meta) => audit_log_required($action, $type, $id, $text, $meta);

// Curriculum candidates: test name patterns, plus the BSCRIM demo curriculum named in the task. The real BSIT curriculum is excluded.
$pattern = '/(\bdemo\b|pre[\s-]?oral|\btest\b)/i';
$exact = static fn(string $v): bool => in_array(strtolower(trim($v)), ['123', '1', 'bsit demo'], true);
$curriculumCandidates = [];
foreach ($rows('SELECT c.id, c.curriculum_code, c.curriculum_name, c.status, c.archived_at, ap.program_code FROM curricula c JOIN academic_programs ap ON ap.id = c.academic_program_id ORDER BY c.id') as $c) {
    if ((int)$c['id'] === $realId) continue;
    $versions = $rows('SELECT id, version_label FROM curriculum_versions WHERE curriculum_id = ?', 'i', [(int)$c['id']]);
    $reason = null;
    if (preg_match($pattern, $c['curriculum_code'] . ' ' . $c['curriculum_name']) || $exact($c['curriculum_code']) || $exact($c['curriculum_name'])) $reason = 'name or code matches a test pattern';
    foreach ($versions as $v) if (!$reason && (preg_match($pattern, $v['version_label']) || $exact($v['version_label']))) $reason = 'a version label matches a test pattern';
    if (!$reason && strcasecmp($c['program_code'], 'BSCRIM') === 0) foreach ($versions as $v) if (preg_match('/^(ROTC|CMO No\. ?62)/i', $v['version_label'])) $reason = 'BSCRIM demo curriculum named in the task (version "' . $v['version_label'] . '")';
    if ($reason) $curriculumCandidates[] = $c + ['reason' => $reason, 'versions' => $versions];
}
$candidateVersionIds = [];
$candidateSubjectIds = [];
foreach ($curriculumCandidates as $c) {
    foreach ($c['versions'] as $cv) $candidateVersionIds[] = (int)$cv['id'];
}
foreach ($curriculumCandidates as $c) {
    $vids = array_map('intval', array_column($c['versions'], 'id'));
    $pids = array_map('intval', array_column($rows('SELECT id FROM curriculum_version_subjects WHERE curriculum_version_id IN (' . $ids($vids) . ')'), 'id'));
    $deps = $dependencyReport('curriculum', $vids, $pids, [(int)$c['id']], []);
    $say("  candidate curriculum id {$c['id']} {$c['curriculum_code']} (program {$c['program_code']}): {$c['reason']}; versions " . count($vids) . ', placements ' . count($pids) . '; other references: ' . ($deps ? json_encode($deps) : 'none'));
    $subjectIds = array_map('intval', array_column($rows('SELECT DISTINCT subject_id FROM curriculum_version_subjects WHERE curriculum_version_id IN (' . $ids($vids) . ')'), 'subject_id'));
    foreach ($subjectIds as $sid) $candidateSubjectIds[$sid] = true;
    if ($deps) {
        $plan("ARCHIVE curriculum id {$c['id']} (referenced elsewhere)", function () use ($conn, $c, $vids, $audit, &$actorId) {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("UPDATE curricula SET archive_previous_status = status, status = 'inactive', archived_at = NOW(), archived_by = ? WHERE id = ? AND archived_at IS NULL");
                $stmt->bind_param('ii', $actorId, $c['id']); $stmt->execute();
                $audit('CURRICULUM_ARCHIVE', 'curriculum', (int)$c['id'], 'Test curriculum archived by the prototype data cleanup.', ['curriculum_code' => $c['curriculum_code']]);
                $conn->commit();
            } catch (Throwable $e) { $conn->rollback(); throw $e; }
        });
        $decide[] = "Curriculum {$c['curriculum_code']} (id {$c['id']}) archived, not deleted: " . json_encode($deps) . '.';
        continue;
    }
    $plan("DELETE curriculum id {$c['id']} {$c['curriculum_code']} with " . count($vids) . ' version(s) and ' . count($pids) . ' placement(s)', function () use ($conn, $c, $vids, $pids, $audit, $rows, $ids) {
        $conn->begin_transaction();
        try {
            foreach ($rows('SELECT p.id, p.curriculum_version_id v, s.subject_code FROM curriculum_version_subjects p JOIN subjects s ON s.id = p.subject_id WHERE p.id IN (' . $ids($pids) . ')') as $p) {
                $conn->query('DELETE FROM curriculum_version_subjects WHERE id = ' . (int)$p['id']);
                $audit('CURRICULUM_VERSION_SUBJECT_DELETE', 'curriculum_version_subject', (int)$p['id'], 'Test placement removed by the prototype data cleanup.', ['curriculum_version_id' => (int)$p['v'], 'subject_code' => $p['subject_code']]);
            }
            foreach ($vids as $vid) {
                $conn->query("DELETE FROM curriculum_versions WHERE id = $vid");
                $audit('CURRICULUM_VERSION_DELETE', 'curriculum_version', $vid, 'Test curriculum version removed by the prototype data cleanup.', ['curriculum_id' => (int)$c['id']]);
            }
            $conn->query('DELETE FROM curricula WHERE id = ' . (int)$c['id']);
            $audit('CURRICULUM_DELETE', 'curriculum', (int)$c['id'], 'Test curriculum removed by the prototype data cleanup.', ['curriculum_code' => $c['curriculum_code']]);
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    });
}
// Version-level test labels inside curricula that are kept: reported, never touched.
foreach ($rows('SELECT v.id, v.version_label, c.curriculum_code FROM curriculum_versions v JOIN curricula c ON c.id = v.curriculum_id ORDER BY v.id') as $v) {
    $inCandidate = in_array((int)$v['id'], $candidateVersionIds, true);
    if (!$inCandidate && (preg_match($pattern, $v['version_label']) || $exact($v['version_label']))) $decide[] = "Version \"{$v['version_label']}\" (id {$v['id']}) of curriculum {$v['curriculum_code']} looks like test data but its curriculum is kept; not touched.";
}

// Program candidate: ASD (name equals code, inactive test program).
foreach ($rows("SELECT id, program_code, program_name FROM academic_programs WHERE UPPER(TRIM(program_code)) = UPPER(TRIM(program_name)) AND archived_at IS NULL AND UPPER(program_code) = 'ASD'") as $p) {
    $pid = (int)$p['id'];
    $curIds = array_map('intval', array_column($rows('SELECT id FROM curricula WHERE academic_program_id = ?', 'i', [$pid]), 'id'));
    $deps = $dependencyReport('program', [], [], [], [$pid]);
    $say("  candidate program id $pid {$p['program_code']} (name equals code): curricula " . count($curIds) . '; references: ' . ($deps ? json_encode($deps) : 'none'));
    if ($deps) {
        $plan("ARCHIVE program id $pid {$p['program_code']} (referenced elsewhere)", function () use ($conn, $pid, $p, $audit, &$actorId) {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("UPDATE academic_programs SET archive_previous_status = status, status = 'inactive', archived_at = NOW(), archived_by = ? WHERE id = ? AND archived_at IS NULL");
                $stmt->bind_param('ii', $actorId, $pid); $stmt->execute();
                $audit('PROGRAM_ARCHIVE', 'academic_program', $pid, 'Test program archived by the prototype data cleanup.', ['program_code' => $p['program_code']]);
                $conn->commit();
            } catch (Throwable $e) { $conn->rollback(); throw $e; }
        });
        $decide[] = "Program {$p['program_code']} archived, not deleted: " . json_encode($deps) . '.';
    } else {
        $plan("DELETE program id $pid {$p['program_code']}", function () use ($conn, $pid, $p, $audit) {
            $conn->begin_transaction();
            try {
                $conn->query("DELETE FROM academic_programs WHERE id = $pid");
                $audit('PROGRAM_DELETE', 'academic_program', $pid, 'Test program removed by the prototype data cleanup.', ['program_code' => $p['program_code']]);
                $conn->commit();
            } catch (Throwable $e) { $conn->rollback(); throw $e; }
        });
    }
}

// Test equivalency DM102 / SA101 (the subjects stay; only the equivalency row goes).
$eqRefs = $rows("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'subject_equivalencies'");
foreach ($rows("SELECT e.id, a.subject_code ac, b.subject_code bc FROM subject_equivalencies e JOIN subjects a ON a.id = e.subject_id JOIN subjects b ON b.id = e.equivalent_subject_id
        WHERE (a.subject_code = 'DM102' AND b.subject_code = 'SA101') OR (a.subject_code = 'SA101' AND b.subject_code = 'DM102')") as $e) {
    $refs = 0; foreach ($eqRefs as $r) $refs += (int)$scalar("SELECT COUNT(*) FROM `{$r['t']}` WHERE `{$r['c']}` = " . (int)$e['id']);
    $say("  candidate equivalency id {$e['id']} ({$e['ac']} / {$e['bc']}): references $refs");
    if ($refs) { $decide[] = "Equivalency {$e['ac']}/{$e['bc']} referenced elsewhere; kept."; continue; }
    $plan("DELETE equivalency row id {$e['id']} ({$e['ac']} / {$e['bc']})", function () use ($conn, $e, $audit) {
        $conn->begin_transaction();
        try {
            $conn->query('DELETE FROM subject_equivalencies WHERE id = ' . (int)$e['id']);
            $audit('SUBJECT_EQUIVALENCY_DELETE', 'subject_equivalency', (int)$e['id'], 'Test equivalency removed by the prototype data cleanup.', ['subjects' => $e['ac'] . '/' . $e['bc']]);
            $conn->commit();
        } catch (Throwable $e2) { $conn->rollback(); throw $e2; }
    });
}
// BPM101 / CAP101: only if the real BSIT curriculum does not place them.
foreach (['BPM101', 'CAP101'] as $code) {
    $s = $rows('SELECT id, status FROM subjects WHERE subject_code = ?', 's', [$code])[0] ?? null;
    if (!$s) continue;
    $inReal = (int)$scalar('SELECT COUNT(*) FROM curriculum_version_subjects p JOIN curriculum_versions v ON v.id = p.curriculum_version_id WHERE p.subject_id = ? AND v.curriculum_id = ?', 'ii', [(int)$s['id'], $realId]);
    $say("  $code ({$s['status']}): placed in the real BSIT curriculum: " . ($inReal ? 'yes, KEPT' : 'no'));
    if ($inReal) $decide[] = "$code is inactive but placed in the real BSIT curriculum ($inReal placement(s)); kept.";
    else $decide[] = "$code is not placed in the real BSIT curriculum; not removed in this run (subject deletion is outside the requested steps).";
}
// Subjects that become unplaced once the candidate placements go.
foreach (array_keys($candidateSubjectIds) as $sid) {
    $elsewhere = (int)$scalar('SELECT COUNT(*) FROM curriculum_version_subjects WHERE subject_id = ? AND curriculum_version_id NOT IN (' . $ids($candidateVersionIds) . ')', 'i', [(int)$sid]);
    if (!$elsewhere) { $sub = $rows('SELECT subject_code, subject_title FROM subjects WHERE id = ?', 'i', [(int)$sid])[0]; $decide[] = "Subject {$sub['subject_code']} \"{$sub['subject_title']}\" (id $sid) will have no placement after the cleanup; kept (subject deletion is not requested)."; }
}
// Kept on purpose.
foreach ($rows("SELECT c.id, c.curriculum_code, ap.program_code, (SELECT COUNT(*) FROM curriculum_version_subjects p JOIN curriculum_versions v ON v.id = p.curriculum_version_id WHERE v.curriculum_id = c.id) AS placed FROM curricula c JOIN academic_programs ap ON ap.id = c.academic_program_id") as $c) {
    $isCandidate = in_array((int)$c['id'], array_map(fn($x) => (int)$x['id'], $curriculumCandidates), true);
    if ((int)$c['id'] !== $realId && !$isCandidate) $decide[] = "Curriculum {$c['curriculum_code']} (id {$c['id']}, program {$c['program_code']}, {$c['placed']} placed) does not match the test patterns; kept.";
}
if ($realId) foreach ($rows('SELECT id, version_label, status, (SELECT COUNT(*) FROM curriculum_version_subjects p WHERE p.curriculum_version_id = v.id) AS placed FROM curriculum_versions v WHERE curriculum_id = ? AND id NOT IN (SELECT curriculum_version_id FROM curriculum_version_subjects)', 'i', [$realId]) as $v) $decide[] = "Version \"{$v['version_label']}\" (id {$v['id']}, {$v['status']}, no placements) of the real BSIT curriculum kept: not a test pattern.";
$say("\nReport-only, NOT changed: demo grade weights 30/30/40, effectivity 2100, compliance seed, other equivalency rows, users, the Revision feature (the client says BCP makes no revisions; it creates another curriculum).");

// ---------------------------------------------------------------- counts, decisions, apply
$tables = ['academic_programs', 'curricula', 'curriculum_versions', 'curriculum_version_subjects', 'curriculum_subject_requirements', 'subject_equivalencies', 'subjects'];
$count = static function () use ($conn, $tables): array { $o = []; foreach ($tables as $t) $o[$t] = (int)$conn->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0]; return $o; };
$before = $count();
$realPlaced = $realId ? (int)$conn->query("SELECT COUNT(*) FROM curriculum_version_subjects p JOIN curriculum_versions v ON v.id = p.curriculum_version_id WHERE v.curriculum_id = $realId")->fetch_row()[0] : 0;
echo "\n== Needs your decision / kept\n";
foreach (array_values(array_unique($decide)) as $d) echo "  - $d\n";
echo "\nBefore: " . json_encode($before) . "; real BSIT placements: $realPlaced\n";
if (!$apply) { echo count($actions) . " planned change(s). Run again with --apply --confirm-database=$database --actor-user-id=<id> --backup-file=<file> to write.\n"; exit(0); }

$done = 0;
foreach ($actions as [$text, $run]) {
    try { $run(); $done++; echo "  DONE $text\n"; }
    catch (Throwable $e) { echo "  FAILED $text: " . get_class($e) . ': ' . $e->getMessage() . "\n"; }
}
$after = $count();
$realPlacedAfter = $realId ? (int)$conn->query("SELECT COUNT(*) FROM curriculum_version_subjects p JOIN curriculum_versions v ON v.id = p.curriculum_version_id WHERE v.curriculum_id = $realId")->fetch_row()[0] : 0;
echo "\nApplied $done of " . count($actions) . " change(s).\nAfter:  " . json_encode($after) . "; real BSIT placements: $realPlacedAfter (" . ($realPlacedAfter === $realPlaced ? 'unchanged' : 'CHANGED') . ")\n";
foreach ($tables as $t) if ($before[$t] !== $after[$t]) echo "  $t: {$before[$t]} -> {$after[$t]}\n";
exit($done === count($actions) ? 0 : 2);
