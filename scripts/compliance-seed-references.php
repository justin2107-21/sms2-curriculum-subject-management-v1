<?php
// Seeds the CHED/DepEd documentary sources the client holds locally and the rule library built from them (migration 032). CLI only.
//
//   Dry run (default, writes nothing):
//     php scripts/compliance-seed-references.php
//   Apply (after a backup):
//     php scripts/compliance-seed-references.php --apply --confirm-database=<database name> --actor-user-id=<id> [--only-rules=CODE,CODE]
//
// Needs DB_NAME (and DB_* if not default) in the environment. The actor must be an active user holding compliance.reference.verify
// and compliance.manage (Super Admin). The script is idempotent: sources that exist are skipped, library rules are upserted by rule_code
// (a re-run reports them as unchanged, and the active flag a manager set is never overwritten).
// It refuses to write unless --confirm-database equals the connected database name, which also protects sms_db from an accidental run.
//
// What it creates, through the normal services (so validation and audit rows are the same as in the UI):
//   * 14 sources, status Draft, each with ONE confirmed document_identity evidence event. The evidence is a locator plus a SHA-256
//     fingerprint reported by the user on 2026-11-03. It is a pointer, not a stored document; institutional adoption is not assessed.
//     Recording that event moves each source to "Source confirmed" through the normal service path.
//   * The library rules from modules/curriculum/services/ComplianceLibraryCatalog.php (compliance_library_rules, migration 032 must be applied):
//     BSIT 146 units, the CMO 25 component table (display only), general education, PE, NSTP, the pending DepEd Senior High rules and the
//     two BCP client-reply rules. The legacy custom BSIT rule is NOT created any more; if it already exists it is left alone (a run does not
//     count it twice). Without migration 032 the sources are still seeded and the library rules are skipped with a message.
//
// Notes about the local files: the DM 036 s.2026, DM 048 s.2025 and DO 017 s.2026 copies carry a "-compressed" suffix in their file name
// and were NOT byte-compared with the copies in the Project folder, so their fingerprints describe the local files only.
// Nothing here says a curriculum is compliant; the sources are documents, and adoption by the institution is a separate question.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/authorization.php';

$actorId = null;
// Audit rows take their actor from current_user_id(); in the CLI that is the named actor.
function current_user_id(): ?int { global $actorId; return $actorId; }
require_once __DIR__ . '/../shared/audit.php';
require_once __DIR__ . '/../modules/curriculum/services/ComplianceReferenceService.php';
require_once __DIR__ . '/../modules/curriculum/services/ComplianceLibraryCatalog.php';
require_once __DIR__ . '/../modules/curriculum/repositories/ComplianceLibraryRepository.php';

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m)) $options[$m[1]] = $m[2] ?? '1';
}
$fail = static function (string $message, int $code = 1): void { fwrite(STDERR, $message . "\n"); exit($code); };
$apply = isset($options['apply']);

$database = (string)($conn->query('SELECT DATABASE() AS d')->fetch_assoc()['d'] ?? '');
if ($database === '') $fail('No database selected.');
echo "Database: $database\n";

const EVIDENCE_NOTE = 'Fingerprint of the local copy reported by the user; pointer only, not a stored document; institutional adoption not assessed';
$REPORTED = 'SHA-256 as reported by the user on 2026-11-03';

// [code, title, type, issuing body, effective date or null, file name, sha256]
$sources = [
    ['CMO 25 s.2015', 'CMO 25 s.2015', 'CHED', 'CHED', '2015-08-01', 'CMO 25 s.2015.pdf', 'AEB87088118D4341B8F8A173952E77B07AEA3ACD87D140770D73B4B2309FB6CF'],
    ['CMO 20 s.2013', 'CMO 20 s.2013 General Education', 'CHED', 'CHED', '2013-06-28', 'CMO 20 s.2013.pdf', 'B7ECC91940FB46B4D25F99A6E39F12FF9B3A9E166C0F5D00165C98E52E9526D1'],
    ['Advisory on CMO 20 (2019)', 'Advisory on CMO 20 s.2013', 'CHED', 'CHED', '2019-12-09', 'Advisory on CMO 20.pdf', 'FE7D4091A4ECE100C91A84C09633CF31EE0BFA0D05F8C3B9D03418FDC89D3FA3'],
    ['CMO 39 s.2021', 'CMO 39 s.2021 Tertiary Physical Education (PATHFIT)', 'CHED', 'CHED', '2021-12-01', 'CMO 39 s.2021.pdf', '98F7E56400789E4E4A0DA598C9F86A01F4657504AB0A582AE7EDD565EE504B8E'],
    ['CMO 46 s.2012', 'CMO 46 s.2012 Outcomes-Based Quality Assurance', 'CHED', 'CHED', '2012-12-01', 'CMO 46 s.2012.pdf', 'DCE0EE5732C684DD6840ADCB6187F408A760394F57FE93AB75B956D0BBA39EC4'],
    ['CHED Handbook on Typology, OBE and ISA', 'CHED Handbook on Typology, Outcomes-Based Education and Institutional Sustainability Assessment', 'CHED', 'CHED', '2014-01-01', 'CHED Handbook on Typology OBE and ISA.pdf', '4A11392FE2FB48B3413E9D6ACF669CFF12B1B86F5355B6B2A26B9E37C58C6D05'],
    ['RA 9163', 'RA 9163 National Service Training Program (NSTP) Act of 2001', 'OTHER', 'Republic of the Philippines (Republic Act)', null, 'RA 9163.pdf', 'AC3182BBCC95D383A1F1DE191547AB0C44CF49AEADD022F9AA98FBFB0A9AF384'],
    ['Revised IRR of RA 9163', 'Revised Implementing Rules and Regulations of RA 9163', 'CHED', 'CHED / TESDA / DND', '2021-11-23', 'Revised IRR of RA 9163.pdf', '69ADBDEDD88A42BEDB36E523FF02AAAA77B84DB2AE4ED4ADAA3465BEEA83782E'],
    ['DM 012 s.2026', 'DM 012 s.2026 Strengthened Senior High School Curriculum', 'DEPED', 'DepEd', '2026-02-27', 'DM 012 s.2026.pdf', 'E1E4ADF2553E4175F7CA847E742C0AAD41F92EB68C5C4808830EC0027F31BCC3'],
    ['DM 036 s.2026', 'DM 036 s.2026', 'DEPED', 'DepEd', '2026-05-28', 'DM 036 s.2026-compressed.pdf', '9D010B411C941EFDA25FA9F1A1BA6EFBEA821955F95C2B6FCDEF22B47EB733D7'],
    ['DM 048 s.2025', 'DM 048 s.2025', 'DEPED', 'DepEd', null, 'DM 048 s.2025-compressed.pdf', 'A7BADFF715F4E7625090E2B41D3F1A5C70672CD9957589EB82FCFE60BD9B1A44'],
    ['DM 074 s.2025', 'DM 074 s.2025 Interim Grading', 'DEPED', 'DepEd', '2025-09-02', 'DM 074 s.2025.pdf', '4A046C13F74C7C786E089513F4FEF23C6A971E1F69DE1ED926BF66849515614D'],
    ['DO 015 s.2026', 'DO 015 s.2026 Grading, Assessment and Awards', 'DEPED', 'DepEd', '2026-06-04', 'DO 015 s.2026.pdf', '1E47FB52DA8485D361617B29335DA5EE1D939176CFBCF0B6EC3EFA21B0CDC91A'],
    ['DO 017 s.2026', 'DO 017 s.2026 Strengthened Senior High School Curriculum', 'DEPED', 'DepEd', '2026-06-04', 'DO 017 s.2026-compressed.pdf', '0041B870F4923F7503757690A9E5955DCB0B800B4FD949B245A852F30EB030EC'],
];

$one = static function (mysqli $c, string $sql, string $types = '', array $params = []): ?array {
    $st = $c->prepare($sql);
    if ($types !== '') $st->bind_param($types, ...$params);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
};

$existing = [];
foreach ($sources as $s) $existing[$s[0]] = $one($conn, 'SELECT id, verification_status FROM compliance_references WHERE reference_type=? AND reference_code=?', 'ss', [$s[2], $s[0]]);
$library = new ComplianceLibraryRepository($conn);
$libraryReady = $library->available();
$catalog = ComplianceLibraryCatalog::rules();
// --only-rules=CODE,CODE limits the library rules that are seeded (the owner's reduced scope); without it every catalog rule is seeded as before.
if (isset($options['only-rules'])) { $only = array_filter(array_map('trim', explode(',', (string)$options['only-rules']))); $unknown = array_diff($only, array_column($catalog, 'rule_code')); if ($unknown) $fail('Unknown rule code: ' . implode(', ', $unknown)); $catalog = array_values(array_filter($catalog, fn($r) => in_array($r['rule_code'], $only, true))); }
$referenceId = static function (?string $code) use ($one, $conn): ?int {
    if ($code === null) return null;
    $row = $one($conn, 'SELECT id FROM compliance_references WHERE reference_code = ? ORDER BY id LIMIT 1', 's', [$code]);
    return $row ? (int)$row['id'] : null;
};

echo ($apply ? 'APPLY' : 'DRY RUN (nothing is written)') . "\n";
foreach ($sources as $s) echo sprintf("  source %-40s %s\n", $s[0], $existing[$s[0]] ? 'exists, skipped' : 'would be created, Draft + confirmed document_identity evidence');
if (!$libraryReady) echo "  library rules: migration 032 is not applied in this database, so none can be written (sources are still seeded).\n";
else foreach ($catalog as $rule) echo sprintf("  rule   %-26s %s\n", $rule['rule_code'], $library->findByCode($rule['rule_code']) ? 'exists, will be checked for changes' : 'would be created');

if (!$apply) { echo "Run again with --apply --confirm-database=$database --actor-user-id=<id> to write.\n"; exit(0); }

if (($options['confirm-database'] ?? '') !== $database) $fail("Refusing to write: --confirm-database must equal the connected database name ($database).");
$actorId = (int)($options['actor-user-id'] ?? 0);
if ($actorId <= 0) $fail('--actor-user-id is required.');
$actor = $one($conn, 'SELECT id FROM users WHERE id=? AND is_active=1', 'i', [$actorId]);
if (!$actor) $fail('--actor-user-id must be an active user.');
foreach (['compliance.reference.verify', 'compliance.manage'] as $permission) {
    if (!user_can($actorId, $permission)) $fail("The actor does not hold $permission.");
}

$references = new ComplianceReferenceService($conn);
$created = 0; $skipped = 0;
foreach ($sources as $s) {
    if ($existing[$s[0]]) { $skipped++; continue; }
    $reference = $references->create([
        'reference_type' => $s[2], 'source_kind' => 'regulatory_document', 'reference_code' => $s[0], 'title' => $s[1], 'issuing_body' => $s[3],
        'effective_date' => $s[4] ?? '', 'verification_status' => 'draft',
    ], $actorId);
    $references->recordEvidence((int)$reference['id'], [
        'verification_subject' => 'document_identity', 'result' => 'confirmed',
        'evidence_locator' => 'File ' . $s[5] . '; ' . $REPORTED, 'evidence_sha256' => $s[6], 'rationale' => EVIDENCE_NOTE,
    ], $actorId);
    $created++;
    echo "  created {$s[0]}\n";
}
echo "Sources created: $created, skipped: $skipped\n";

if (!$libraryReady) { echo "Library rules skipped: migration 032 is not applied in this database.\n"; exit(0); }
$counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
foreach ($catalog as $rule) {
    $reference = $referenceId($rule['reference_code']);
    if ($rule['reference_code'] !== null && $reference === null) { echo "  rule {$rule['rule_code']} skipped: source {$rule['reference_code']} is not recorded.\n"; continue; }
    $outcome = $library->upsert($rule, $reference);
    $counts[$outcome]++;
    if ($outcome !== 'unchanged') {
        $row = $library->findByCode($rule['rule_code']);
        audit_log_required('COMPLIANCE_LIBRARY_RULE_SEED', 'compliance_library_rule', (int)$row['id'], 'Library rule ' . $outcome . ' by the seed script.', ['rule_code' => $rule['rule_code'], 'outcome' => $outcome]);
        echo "  rule {$rule['rule_code']} $outcome\n";
    }
}
echo "Library rules created: {$counts['created']}, updated: {$counts['updated']}, unchanged: {$counts['unchanged']}\n";
