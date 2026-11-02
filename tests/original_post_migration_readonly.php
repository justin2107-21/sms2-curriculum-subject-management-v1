<?php
// Read-only post-migration verification for the explicitly confirmed local sms_db.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/db.php';
$manifestPath = getopt('', ['manifest:'])['manifest'] ?? null;
if (!is_string($manifestPath) || !is_file($manifestPath)) { throw new RuntimeException('A pre-migration manifest is required.'); }
$identity = $conn->query('SELECT DATABASE() db,@@hostname host,@@port port,@@version version')->fetch_assoc();
if ($identity['db'] !== 'sms_db' || !in_array(strtolower((string)getenv('DB_HOST')), ['localhost','127.0.0.1'], true) || (int)getenv('DB_PORT') !== 3306) {
    throw new RuntimeException('Refusing non-local sms_db target.');
}
$manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
function originalColumns(mysqli $db, string $table): array {
    return array_column($db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $db->real_escape_string($table) . "' ORDER BY ORDINAL_POSITION")->fetch_all(MYSQLI_NUM), 0);
}
function originalRows(mysqli $db, string $table, array $columns): array {
    $quoted = array_map(fn($c) => '`' . str_replace('`','``',$c) . '`', $columns);
    $list = implode(',', $quoted); $safe = str_replace('`','``',$table);
    return $db->query("SELECT $list FROM `$safe` ORDER BY $list")->fetch_all(MYSQLI_ASSOC);
}
function originalHash(array $rows): string { return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
$planned = ['curriculum.reference.attach','compliance.reference.verify','curriculum.reference.classify','curriculum.version.clone','curriculum.outcome_mapping.manage','curriculum.clo.manage','curriculum.assessment_plan.manage'];
$checks = 0;
foreach (['academic_programs','curricula','curriculum_versions','curriculum_version_subjects','subjects','users','students','roles','permissions','role_permissions','user_roles'] as $table) {
    $rows = originalRows($conn, $table, $manifest['critical'][$table]['columns']);
    if ($table === 'permissions') { $rows = array_values(array_filter($rows, static fn(array $r): bool => !in_array($r['name'], $planned, true))); }
    if (count($rows) !== (int)$manifest['critical'][$table]['rows'] || !hash_equals($manifest['critical'][$table]['hash'], originalHash($rows))) {
        throw new RuntimeException("Existing record fingerprint changed: $table");
    }
    $checks++;
}
$adoption = $conn->query("SELECT adoption_key,schema_fingerprint,covered_through FROM schema_baseline_adoptions")->fetch_assoc();
if (!$adoption || $adoption['adoption_key'] !== 'legacy_schema_v1' || $adoption['schema_fingerprint'] !== '7b1d7009bd961389ecb1397e20b76eea4deb7ffd144d62802beee64ff5d17593' || $adoption['covered_through'] !== '020_assign_prototype_rbac_roles.sql') throw new RuntimeException('Baseline adoption record invalid.');
$checks++;
$journal = $conn->query("SELECT migration,status FROM schema_migrations ORDER BY migration")->fetch_all(MYSQLI_ASSOC);
$expected = ['021_create_curriculum_lifecycle_foundation.sql','022_curriculum_reference_provenance.sql','023_curriculum_revision_clone_permission.sql','024_curriculum_outcome_mapping.sql','025_curriculum_clo_assessment_alignment.sql'];
if (count($journal) !== 5 || array_column($journal,'migration') !== $expected || count(array_filter($journal, fn($r) => $r['status'] !== 'applied')) !== 0) throw new RuntimeException('Migration journal does not contain exactly applied 021-025.');
$checks++;
if ((int)$conn->query('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetch_row()[0] !== 37) throw new RuntimeException('Unexpected final table count.');
$checks++;
foreach ($planned as $permission) { $stmt=$conn->prepare('SELECT COUNT(*) FROM permissions WHERE name=?');$stmt->bind_param('s',$permission);$stmt->execute();if((int)$stmt->get_result()->fetch_row()[0]!==1)throw new RuntimeException("Missing permission: $permission");$stmt->close();$checks++; }
if ((int)$conn->query("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.name IN ('" . implode("','", $planned) . "')")->fetch_row()[0] !== 0) throw new RuntimeException('Unexpected default grant for a new capability.');
$checks++;
echo json_encode(['passed'=>true,'checks'=>$checks,'identity'=>$identity,'note'=>'read-only; no fixtures or catalog rows written'], JSON_THROW_ON_ERROR) . PHP_EOL;
