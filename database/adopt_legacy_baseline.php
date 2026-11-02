<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/legacy_baseline.php';

$options = getopt('', ['adopt', 'confirm-database:', 'expected-fingerprint:', 'allow-application-database']);
$db = null;
try {
    $config = csms_database_config();
    $target = $options['confirm-database'] ?? null;
    $expected = $options['expected-fingerprint'] ?? null;
    if (!array_key_exists('adopt', $options) || $target !== $config['database'] || !is_string($expected)
        || !preg_match('/^[a-f0-9]{64}$/iD', $expected)) {
        throw new RuntimeException('Adoption requires --adopt, --confirm-database matching the configured database, and a SHA-256 fingerprint.');
    }
    $isDisposable = preg_match('/^(?:csms_docker_test_|sms_db_foundation_test_|sms_db_reconcile_test_)[a-z0-9_]+$/D', $target) === 1;
    if (!$isDisposable && !array_key_exists('allow-application-database', $options)) {
        throw new RuntimeException('Non-disposable adoption requires the explicit --allow-application-database flag.');
    }
    if ($isDisposable && getenv('SMS_TEST_CONFIRM_DATABASE') !== $target) {
        throw new RuntimeException('Disposable adoption requires SMS_TEST_CONFIRM_DATABASE to match the target.');
    }
    $db = csms_database_connect($config);
    if ($db->query("SELECT DATABASE() AS database_name")->fetch_assoc()['database_name'] !== $target) {
        throw new RuntimeException('Connected database does not match the confirmed target.');
    }
    $actual = csms_legacy_baseline_fingerprint($db);
    if (!hash_equals(strtolower($expected), strtolower($actual))) {
        throw new RuntimeException('Legacy baseline fingerprint mismatch; refusing adoption.');
    }
    if ($db->query("SHOW TABLES LIKE 'schema_baseline_adoptions'")->num_rows > 0) {
        $rows = $db->query('SELECT adoption_key,schema_fingerprint,covered_through FROM schema_baseline_adoptions ORDER BY adoption_key')->fetch_all(MYSQLI_ASSOC);
        if (count($rows) !== 1 || $rows[0]['adoption_key'] !== 'legacy_schema_v1'
            || !hash_equals(strtolower($rows[0]['schema_fingerprint']), strtolower($actual))
            || $rows[0]['covered_through'] !== '020_assign_prototype_rbac_roles.sql') {
            throw new RuntimeException('Existing baseline adoption record is invalid; refusing repeat execution.');
        }
        echo json_encode(['adopted'=>true,'already_adopted'=>true,'database'=>$target,'adoption_key'=>'legacy_schema_v1','schema_fingerprint'=>$actual,'covered_through'=>$rows[0]['covered_through']], JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }
    if ($db->query("SHOW TABLES LIKE 'schema_migrations'")->num_rows > 0) {
        throw new RuntimeException('Migration journal already exists; refusing baseline adoption.');
    }
    if (!csms_legacy_baseline_shape_is_expected($db)) {
        throw new RuntimeException('Schema does not match the reviewed 24-table legacy baseline.');
    }
    $db->query("CREATE TABLE schema_baseline_adoptions (
        adoption_key VARCHAR(64) NOT NULL PRIMARY KEY,
        schema_fingerprint CHAR(64) NOT NULL,
        covered_through VARCHAR(190) NOT NULL,
        verified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        responsible_process VARCHAR(255) NOT NULL,
        notes TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $stmt = $db->prepare('INSERT INTO schema_baseline_adoptions(adoption_key,schema_fingerprint,covered_through,responsible_process,notes) VALUES (?,?,?,?,?)');
    $key = 'legacy_schema_v1';
    $covered = '020_assign_prototype_rbac_roles.sql';
    $process = 'CSMS controlled legacy-baseline adoption';
    $notes = 'Verified schema, role, permission, and role-permission state; historical execution of migrations 001-020 is unverified and not claimed.';
    $stmt->bind_param('sssss', $key, $actual, $covered, $process, $notes);
    $stmt->execute();
    echo json_encode(['adopted'=>true,'database'=>$target,'adoption_key'=>$key,'schema_fingerprint'=>$actual,'covered_through'=>$covered], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
