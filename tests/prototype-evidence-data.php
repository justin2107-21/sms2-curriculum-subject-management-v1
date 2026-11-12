<?php
// Evidence-only data checks. CLI only, read-only. Run on a CLONE where scripts/prototype-evidence-apply.php and migration 029 were applied:
//   DB_NAME=<clone> php tests/prototype-evidence-data.php
// Proves what was added (PE prerequisite rules with their source note in the audit trail, migration 029 tables without rows) and what was NOT added
// (no compliance rule, no SHS rule, no elective group, no outcome link, no equivalency, no weight change, the version stays Draft).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
$config = csms_database_config();
if ($config['database'] === 'sms_db') { fwrite(STDERR, "Refusing: run this on a clone, not sms_db.\n"); exit(1); }
$db = csms_database_connect($config); $checks = 0;
function ok(bool $cond, string $label): void { global $checks; if (!$cond) throw new RuntimeException('FAILED: ' . $label); $checks++; }
$one = static fn(string $sql) => $db->query($sql)->fetch_row()[0] ?? null;
$vid = (int)$one("SELECT v.id FROM curriculum_versions v JOIN curricula c ON c.id = v.curriculum_id WHERE c.curriculum_code = 'BSIT-2026' LIMIT 1");

// Added: the four PE rules, each with its source in the audit trail; the older rules are untouched.
$rules = $db->query("SELECT a.subject_code t, b.subject_code r FROM curriculum_subject_requirements q JOIN subjects a ON a.id = q.subject_id JOIN subjects b ON b.id = q.required_subject_id WHERE q.curriculum_version_id = $vid AND q.requirement_type = 'prerequisite'")->fetch_all(MYSQLI_ASSOC);
$pairs = array_map(fn($x) => $x['r'] . '>' . $x['t'], $rules);
ok(count($rules) === 12, 'twelve prerequisite rules: the 8 earlier ones plus 4 PE rules');
foreach (['PE1>PE3', 'PE2>PE3', 'PE1>PE4', 'PE2>PE4', 'NSTP1>NSTP2', 'BPM101>CAP101', 'PM101>BPM101', 'ITE1>ITE4'] as $pair) ok(in_array($pair, $pairs, true), "rule $pair exists");
ok((int)$one("SELECT COUNT(*) FROM audit_logs WHERE action = 'SUBJECT_REQUIREMENT_SOURCE_NOTE' AND metadata_json LIKE '%CMO No. 39 s.2021 Sec. 6.1%'") === 4, 'each PE rule has a source note naming CMO 39 Sec. 6.1');
ok((int)$one("SELECT COUNT(*) FROM audit_logs WHERE action = 'SUBJECT_REQUIREMENT_CREATE'") >= 4, 'the PE rules were created through the service (audit entries)');

// Added: migration 029 (Offering History tables), with no rows.
foreach (['api_clients', 'api_rejected_items', 'reported_offerings'] as $t) ok($db->query("SHOW TABLES LIKE '$t'")->num_rows === 1, "table $t exists (migration 029)");
ok((int)$one('SELECT COUNT(*) FROM reported_offerings') === 0 && (int)$one('SELECT COUNT(*) FROM api_clients') === 0, 'no offering row and no API client was added');

// Not added.
ok((int)$one('SELECT COUNT(*) FROM compliance_rules') === 0, 'no compliance rule');
ok($db->query("SHOW TABLES LIKE 'compliance_library_rules'")->num_rows === 0 || (int)$one("SELECT COUNT(*) FROM compliance_library_rules WHERE applies_to_kind = 'senior_high' OR evaluation_mode = 'pending_bcp' OR rule_code LIKE 'DO0%'") === 0, 'no SHS, DepEd or pending library rule exists (the rule library, if applied, holds only the listed college rules)');
ok((int)$one('SELECT COUNT(*) FROM curriculum_elective_groups') === 1, 'no elective group was added (only the earlier archived GE group)');
ok((int)$one('SELECT COUNT(*) FROM curriculum_version_subject_outcomes') === 0, 'no subject-to-outcome link was imported');
ok((int)$one('SELECT COUNT(*) FROM subject_equivalencies') === 1, 'no equivalency was added');
ok((int)$one('SELECT COUNT(*) FROM grade_weighting_schemes') === 3, 'no grade weighting scheme was added');
ok($one("SELECT status FROM curriculum_versions WHERE id = $vid") === 'draft', 'the BSIT 2026 version stays Draft');
ok((int)$one("SELECT COUNT(*) FROM curriculum_versions WHERE status = 'active'") === 0, 'nothing was activated');

// Idempotent
$out = []; exec('"' . PHP_BINARY . '" ' . escapeshellarg(__DIR__ . '/../scripts/prototype-evidence-apply.php') . ' 2>&1', $out, $rc);
ok($rc === 0 && str_contains(implode("\n", $out), '0 planned change(s)'), 'a second dry run of prototype-evidence-apply.php plans 0 changes');
echo "PASS: $checks checks (evidence-only data: added, not added, idempotency).\n";
