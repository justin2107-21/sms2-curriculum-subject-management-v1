<?php
// Compliance Slice 3 data checks (migration 032 and the six seeded library rules). CLI only. Run on a CLONE where migration 032, the seed
// (scripts/compliance-seed-references.php --only-rules=...) and scripts/prototype-compliance-validate.php were applied:
//   DB_NAME=<clone> php tests/compliance-slice3-data.php
// Writes nothing except what the idempotency checks would write if they were not idempotent (an upsert that reports "unchanged"). Refuses sms_db.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
$config = csms_database_config();
if ($config['database'] === 'sms_db') { fwrite(STDERR, "Refusing: run this on a clone, not sms_db.\n"); exit(1); }
$db = csms_database_connect($config); $checks = 0;
function ok(bool $cond, string $label): void { global $checks; if (!$cond) throw new RuntimeException('FAILED: ' . $label); $checks++; }
$one = static fn(string $sql) => $db->query($sql)->fetch_row()[0] ?? null;
$GLOBALS['conn'] = $db;
require_once __DIR__ . '/../modules/curriculum/services/ComplianceValidationService.php';
require_once __DIR__ . '/../modules/curriculum/services/ComplianceLibraryCatalog.php';

// Migration 032: both tables exist, and applying the file again changes nothing (every statement is guarded).
foreach (['compliance_library_rules', 'compliance_library_results'] as $t) ok($db->query("SHOW TABLES LIKE '$t'")->num_rows === 1, "table $t exists");
$rulesBefore = (int)$one('SELECT COUNT(*) FROM compliance_library_rules'); $resultsBefore = (int)$one('SELECT COUNT(*) FROM compliance_library_results');
$sql = preg_replace('/^USE [^;]+;/m', '', (string)file_get_contents(__DIR__ . '/../database/migrations/032_compliance_rule_library.sql'));
$db->multi_query($sql); do { if ($r = $db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
ok((int)$one('SELECT COUNT(*) FROM compliance_library_rules') === $rulesBefore && (int)$one('SELECT COUNT(*) FROM compliance_library_results') === $resultsBefore, 'migration 032 applied a second time changes no row');
ok((int)$one("SELECT COUNT(*) FROM schema_migrations WHERE migration = '032_compliance_rule_library.sql' AND status = 'applied'") === 1, 'migration 032 is journaled once');

// Exactly the six listed rules; none of the excluded kinds.
$expected = ['BCP-NSTP1-PREREQ-NSTP2', 'CMO20-GE-MIN-UNITS', 'CMO25-BSIT-MIN-UNITS', 'CMO25-BSIT-PROF-ELECTIVES', 'CMO39-PE', 'RA9163-NSTP'];
$codes = array_column($db->query('SELECT rule_code FROM compliance_library_rules ORDER BY rule_code')->fetch_all(MYSQLI_ASSOC), 'rule_code');
ok($codes === $expected, 'the seed created only the six listed rules');
ok((int)$one("SELECT COUNT(*) FROM compliance_library_rules WHERE applies_to_kind = 'senior_high' OR evaluation_mode = 'pending_bcp' OR rule_code LIKE 'DO0%'") === 0, 'no SHS, DepEd or pending rule exists, so none is active');
ok((int)$one('SELECT COUNT(*) FROM compliance_rules') === 0, 'the legacy rule table holds no rule (the legacy BSIT rule was not created)');
$rows = $db->query('SELECT l.*, r.reference_code, r.verification_status FROM compliance_library_rules l LEFT JOIN compliance_references r ON r.id = l.compliance_reference_id')->fetch_all(MYSQLI_ASSOC);
foreach ($rows as $r) {
    ok(trim((string)$r['section_label']) !== '' && ($r['compliance_reference_id'] !== null ? $r['verification_status'] === 'verified' : $r['source_class'] === 'client_reply'), "{$r['rule_code']} has a section locator and a verified reference (or is the labelled client reply)");
    ok(!preg_match('/\bcompliant\b|\bapproved\b|official BCP|overall/i', $r['title'] . ' ' . $r['requirement_text'] . ' ' . $r['note']), "{$r['rule_code']} says nothing about compliance, approval or an official policy");
}
$by = array_column($rows, null, 'rule_code');
ok(str_contains($by['CMO25-BSIT-MIN-UNITS']['section_label'], '8.1') && $by['CMO25-BSIT-MIN-UNITS']['source_page'] === '10' && str_contains($by['CMO39-PE']['section_label'], '6.1') && str_contains($by['CMO20-GE-MIN-UNITS']['section_label'], 'Sec. 3') && !str_contains($by['CMO20-GE-MIN-UNITS']['section_label'], 'Sec. 4') && str_contains($by['RA9163-NSTP']['section_label'], 'Secs. 4-6'), 'locators are the ones read in the local files (CMO 25 Sec. 8.1 p. 10, CMO 39 Sec. 6.1, CMO 20 Sec. 3, RA 9163 Secs. 4-6)');

// Idempotent seed: the catalog rules upsert as "unchanged".
$library = new ComplianceLibraryRepository($db);
$refs = array_column($db->query('SELECT id, reference_code FROM compliance_references')->fetch_all(MYSQLI_ASSOC), 'id', 'reference_code');
$outcomes = [];
foreach (ComplianceLibraryCatalog::rules() as $rule) if (in_array($rule['rule_code'], $expected, true)) $outcomes[] = $library->upsert($rule, $rule['reference_code'] === null ? null : $refs[$rule['reference_code']]);
ok(array_unique($outcomes) === ['unchanged'] && count($outcomes) === 6, 'a second seed pass plans 0 changes (all six unchanged)');
$out = []; exec('"' . PHP_BINARY . '" ' . escapeshellarg(__DIR__ . '/../scripts/prototype-compliance-validate.php') . ' 2>&1', $out, $rc);
ok($rc === 0 && str_contains(implode("\n", $out), '0 planned change(s)'), 'a second dry run of the validation script plans 0 changes');

// A rule with no data returns Cannot determine or Not met, never a pass.
$service = new ComplianceValidationService($db);
$eval = new ReflectionMethod($service, 'evaluateLibraryRule'); $eval->setAccessible(true);
$empty = ['version_id' => 999999999, 'total_units' => '0', 'required_units' => '0', 'mapped_subject_count' => 0];
$status = fn(string $code) => $eval->invoke($service, $by[$code], $empty)[0];
ok($status('CMO39-PE') === 'NOT_EVALUATED' && $status('RA9163-NSTP') === 'NOT_EVALUATED' && $status('BCP-NSTP1-PREREQ-NSTP2') === 'NOT_EVALUATED' && $status('CMO20-GE-MIN-UNITS') === 'NOT_EVALUATED' && $status('CMO25-BSIT-PROF-ELECTIVES') === 'NOT_EVALUATED', 'with no placements the PE, NSTP, prerequisite, GE and professional-electives rules are Cannot determine');
ok($status('CMO25-BSIT-MIN-UNITS') === 'FAIL', 'with 0 units the 146-unit rule is Not met, never a pass');
ok($eval->invoke($service, $by['CMO20-GE-MIN-UNITS'], ['version_id' => 42, 'total_units' => '157', 'required_units' => '157'])[0] === 'NOT_EVALUATED', 'GE stays Cannot determine even with data (no subject classification)');

// The stored run: real results behind the numbers.
$vid = (int)$one("SELECT v.id FROM curriculum_versions v JOIN curricula c ON c.id = v.curriculum_id WHERE c.curriculum_code = 'BSIT-2026'");
$run = (int)$one("SELECT MAX(r.id) FROM compliance_validation_runs r JOIN compliance_library_results lr ON lr.validation_run_id = r.id WHERE r.curriculum_version_id = $vid");
$res = array_column($db->query("SELECT l.rule_code, lr.status, lr.actual_text FROM compliance_library_results lr JOIN compliance_library_rules l ON l.id = lr.library_rule_id WHERE lr.validation_run_id = $run")->fetch_all(MYSQLI_ASSOC), null, 'rule_code');
ok(count($res) === 6, 'the stored run holds one result per rule (6)');
ok($res['CMO25-BSIT-MIN-UNITS']['status'] === 'PASS' && str_contains($res['CMO25-BSIT-MIN-UNITS']['actual_text'], 'units of all placements: 157') && str_contains($res['CMO25-BSIT-MIN-UNITS']['actual_text'], 'units of required placements: 157'), 'C1: Met with both unit numbers (157 and 157 against 146)');
ok($res['CMO39-PE']['status'] === 'PASS' && str_contains($res['CMO39-PE']['actual_text'], 'PE units found: 8') && $res['RA9163-NSTP']['status'] === 'PASS' && str_contains($res['RA9163-NSTP']['actual_text'], 'NSTP units found: 6') && $res['BCP-NSTP1-PREREQ-NSTP2']['status'] === 'PASS', 'C4, C5 and the client rule: Met with the numbers behind them');
ok($res['CMO20-GE-MIN-UNITS']['status'] === 'NOT_EVALUATED' && $res['CMO25-BSIT-PROF-ELECTIVES']['status'] === 'NOT_EVALUATED', 'C3 and C2: Cannot determine from stored data');
ok((int)$one("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'COMPLIANCE_VALIDATION%' OR action LIKE 'COMPLIANCE_RUN%'") >= 1, 'the run wrote an audit entry');

// Electives: nothing was added (the group model cannot hold four terms).
ok((int)$one('SELECT COUNT(*) FROM curriculum_elective_groups') === 1, 'no Professional Electives group exists (only the earlier archived GE group)');
ok($one("SELECT status FROM curriculum_versions WHERE id = $vid") === 'draft', 'the BSIT 2026 version stays Draft');
echo "PASS: $checks checks (Compliance slice 3: migration 032, six rules with locators, results with their numbers, idempotency).\n";
