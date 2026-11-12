<?php
// Final prototype data checks (decisions + sources). CLI only. Run on a CLONE that already had scripts/prototype-decisions-apply.php,
// scripts/compliance-seed-references.php and scripts/prototype-sources-apply.php applied:
//   DB_NAME=<clone> php tests/prototype-final-data.php
// Read-only: it writes nothing (the two scripts are only run as dry runs). Refuses sms_db.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
$config = csms_database_config();
if ($config['database'] === 'sms_db') { fwrite(STDERR, "Refusing: run this on a clone, not sms_db.\n"); exit(1); }
$db = csms_database_connect($config); $checks = 0;
function ok(bool $cond, string $label): void { global $checks; if (!$cond) throw new RuntimeException('FAILED: ' . $label); $checks++; }
$one = static fn(string $sql) => $db->query($sql)->fetch_row()[0] ?? null;

// Decisions
$v = $db->query("SELECT v.id, v.version_label, v.status, v.version_role, v.review_status, v.baseline_version_id FROM curriculum_versions v JOIN curricula c ON c.id = v.curriculum_id WHERE c.curriculum_code = 'BSIT-2026' ORDER BY v.id")->fetch_all(MYSQLI_ASSOC);
ok(count($v) === 1 && $v[0]['version_label'] === 'CMO No. 25, s. 2015', 'the BSIT 2026 version is labelled "CMO No. 25, s. 2015"');
ok($v[0]['status'] === 'draft' && $v[0]['version_role'] === 'unclassified' && $v[0]['review_status'] === 'not_started' && $v[0]['baseline_version_id'] === null, 'status stays Draft; role Unclassified; no baseline');
$vid = (int)$v[0]['id'];
ok((int)$one("SELECT COUNT(*) FROM curriculum_version_subjects WHERE curriculum_version_id = $vid") === 52, 'the 52 placements are unchanged');
ok((int)$one("SELECT COUNT(*) FROM curriculum_versions WHERE UPPER(TRIM(version_label)) = 'FINAL'") === 0, 'the empty version FINAL is gone');
ok((int)$one("SELECT COUNT(*) FROM curricula WHERE UPPER(curriculum_code) = 'COMP11'") === 0 && (int)$one("SELECT COUNT(*) FROM academic_programs WHERE UPPER(program_code) = 'ICT'") === 1, 'the curriculum COMP11 is gone and the program ICT stays');
ok((int)$one("SELECT COUNT(*) FROM subjects WHERE subject_code IN ('BPM101','CAP101') AND status = 'active'") === 2
    && (int)$one("SELECT COUNT(*) FROM curriculum_version_subjects p JOIN subjects s ON s.id = p.subject_id WHERE p.curriculum_version_id = $vid AND s.subject_code IN ('BPM101','CAP101')") === 2, 'BPM101 and CAP101 are Active and still placed');
ok((int)$one("SELECT COUNT(*) FROM subjects WHERE subject_code IN ('ROTC','GE 2')") === 2, 'ROTC and GE 2 are kept (the removal conditions are not met)');
ok((int)$one("SELECT COUNT(*) FROM audit_logs WHERE action IN ('CURRICULUM_VERSION_RENAME','CURRICULUM_VERSION_DELETE','CURRICULUM_DELETE','SUBJECT_UPDATE')") >= 5, 'the decisions wrote audit entries');

// Sources
$links = $db->query("SELECT cvr.reference_purpose p, cr.reference_code c, cr.notes n, cr.source_kind k FROM curriculum_version_references cvr JOIN compliance_references cr ON cr.id = cvr.compliance_reference_id WHERE cvr.curriculum_version_id = $vid")->fetch_all(MYSQLI_ASSOC);
$by = array_column($links, null, 'c');
ok(count($links) === 8, 'eight sources are attached to the version');
ok(($by['CMO 25 s.2015']['p'] ?? '') === 'school_curriculum' && ($by['CMO 25 s.2015']['k'] ?? '') === 'regulatory_document', 'CMO 25 is the curriculum source, so "Reference basis" reads Regulatory document');
ok(count(array_filter($links, fn($l) => $l['p'] === 'school_curriculum')) === 1, 'exactly one source is the curriculum source');
ok(str_contains($by['CMO 25 s.2015']['n'], 'Sec. 8.1') && str_contains($by['CMO 25 s.2015']['n'], '146') && str_contains($by['CMO 25 s.2015']['n'], 'IT01-IT13'), 'CMO 25 carries the Sec. 8.1 and IT01-IT13 locators');
ok(str_contains($by['CMO 20 s.2013']['n'], 'Sec. 3') && str_contains($by['CMO 39 s.2021']['n'], 'Sec. 6.1') && str_contains($by['RA 9163']['n'], 'Sec. 6') && str_contains($by['Advisory on CMO 20 (2019)']['n'], 'p. 1'), 'CMO 20, CMO 39, RA 9163 and the advisory carry verified locators');
ok(str_contains($by['Revised IRR of RA 9163']['n'], 'No section or page locator') && str_contains($by['CMO 46 s.2012']['n'], 'No section or page locator') && str_contains($by['CHED Handbook on Typology, OBE and ISA']['n'], 'No section or page locator'), 'sources whose locator could not be verified say so');
ok(count(array_filter($links, fn($l) => preg_match('/BCP (has )?(approved|adopted)|adopted by BCP|approved by BCP|is compliant|complies/i', (string)$l['n']))) === 0, 'no note claims BCP approval, adoption or compliance');
ok((int)$one("SELECT COUNT(*) FROM curriculum_version_subject_snapshots") === 0, 'snapshot capture was not touched');
ok((int)$one("SELECT COUNT(*) FROM compliance_rules") === 0, 'no compliance rule was created');
ok((int)$one("SELECT COUNT(*) FROM audit_logs WHERE action = 'CURRICULUM_VERSION_REFERENCE_ATTACH'") >= 8 && (int)$one("SELECT COUNT(*) FROM audit_logs WHERE action = 'COMPLIANCE_REFERENCE_UPDATE'") >= 8, 'attachments and locator notes wrote audit entries');

// The page shows the locator
$js = file_get_contents(__DIR__ . '/../modules/curriculum/assets/builder-workspace.js');
$repo = file_get_contents(__DIR__ . '/../modules/curriculum/repositories/CurriculumVersionReferenceRepository.php');
ok(str_contains($repo, 'cr.notes AS current_notes') && str_contains($js, 'item.current_notes') && str_contains($js, 'No locator on file.') && str_contains($js, 'Institutional adoption/approval not verified'), 'the Builder source list shows the locator, says "No locator on file." when empty, and keeps the adoption text');

// Idempotent: a second run plans nothing
foreach (['prototype-decisions-apply.php', 'prototype-sources-apply.php'] as $script) {
    $out = []; exec('"' . PHP_BINARY . '" ' . escapeshellarg(__DIR__ . '/../scripts/' . $script) . ' 2>&1', $out, $rc);
    ok($rc === 0 && str_contains(implode("\n", $out), '0 planned change(s)'), "a second dry run of $script plans 0 changes");
}
echo "PASS: $checks checks (final prototype data: decisions, sources, idempotency).\n";
