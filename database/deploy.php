<?php
// Explicit CLI maintenance only. Never called by container startup or HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';

$options = getopt('', ['apply', 'status', 'confirm-database:']);
$apply = array_key_exists('apply', $options);
$db = null;
$lockName = null;
$migration = null;
try {
    $config = csms_database_config();
    if (csms_database_env(['DB_NAME', 'DB_DATABASE', 'SMS_DB_NAME'], '') === ''
        || in_array(strtolower($config['database']), ['mysql', 'sys', 'information_schema', 'performance_schema'], true)) {
        throw new RuntimeException('Set an explicit application database before using this tool.');
    }
    if ($apply && ($options['confirm-database'] ?? null) !== $config['database']) {
        throw new RuntimeException('Apply requires --confirm-database matching the configured database.');
    }
    if ($apply && array_key_exists('status', $options)) {
        throw new RuntimeException('Choose either --apply or --status.');
    }

    // Extract only the two existing baseline CREATE TABLE definitions. Do not
    // execute CREATE DATABASE, USE, or legacy sample INSERTs from students.sql.
    $baseline = file_get_contents(dirname(__DIR__) . '/students.sql');
    if ($baseline === false) { throw new RuntimeException('Cannot read the baseline.'); }
    $baseline = str_replace("\r\n", "\n", $baseline);
    preg_match_all('/^CREATE TABLE IF NOT EXISTS (users|students) \([\s\S]*?^\) ENGINE=InnoDB[^;]*;/m', $baseline, $matches);
    if ($matches[1] !== ['users', 'students']) {
        throw new RuntimeException('Baseline shape changed; manual review is required.');
    }
    $scripts = ['000_baseline_schema' => implode("\n", $matches[0])];
    $files = glob(__DIR__ . '/migrations/[0-9][0-9][0-9]_*.sql');
    sort($files, SORT_STRING);
    if (!$files) { throw new RuntimeException('No migrations found.'); }
    foreach ($files as $file) {
        $sql = file_get_contents($file);
        if ($sql === false) { throw new RuntimeException('Cannot read migration.'); }
        $sql = str_replace("\r\n", "\n", $sql);
        // Already connected to the managed schema. No identifier interpolation.
        $sql = preg_replace('/^USE sms_db;\s*$/m', '', $sql, -1, $uses);
        if ($uses !== 1 || preg_match('/^\s*(?:USE\s|(?:CREATE|DROP|ALTER)\s+DATABASE\s|TRUNCATE\s|DROP\s|DELETE\s)/mi', $sql)) {
            throw new RuntimeException('Migration requires manual safety review.');
        }
        $scripts[basename($file)] = $sql;
    }

    $db = csms_database_connect($config);
    if ($apply) {
        $lockName = 'csms-deploy-' . substr(hash('sha256', $config['database']), 0, 48);
        $stmt = $db->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->bind_param('s', $lockName); $stmt->execute();
        if ((int)$stmt->get_result()->fetch_row()[0] !== 1) {
            $lockName = null;
            throw new RuntimeException('Another migration process is active.');
        }
        $stmt->close();
    }
    $tables = array_column($db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM), 0);
    $hasLedger = in_array('schema_migrations', $tables, true);
    if (!$hasLedger && $tables) {
        throw new RuntimeException('Untracked existing schema: refusing automatic adoption. Back up and review it manually.');
    }
    $applied = [];
    if ($hasLedger) {
        foreach ($db->query('SELECT migration, checksum, status FROM schema_migrations ORDER BY migration')->fetch_all(MYSQLI_ASSOC) as $row) {
            if (!isset($scripts[$row['migration']])) {
                throw new RuntimeException('Unknown applied migration; use the matching application revision.');
            }
            if ($row['status'] !== 'applied') {
                throw new RuntimeException('Incomplete migration recorded; inspect/recover before retrying.');
            }
            $currentChecksum = hash('sha256', $scripts[$row['migration']]);
            // Exact reviewed revisions only. Keep original journal evidence intact.
            // Both the legacy digest AND current source digest must match; this
            // does not authorize modified SQL or replay historical migrations.
            $reviewedRevisions = [
                '005_create_curriculum_subject_requirements.sql' => [
                    '99b54cde70c697fb86745e6ef1ce85d1c02634184189e0f0c85cd0ecd74a61cd',
                    'cde66ce75af406c2ed5ad4c0f8de96ee58d7e0602f16d596b27101ed9b447e77'],
                '009_create_subject_equivalencies.sql' => [
                    'f95310ee20b089be4d975ba5b48ce9337d6de58c864c584b6f590d3cd5111f75',
                    'edb3a4d884aca4654d45e8ef45a9a90d4566344261cfd9c416dd6c42d91289bb'],
                '011_create_compliance_validator.sql' => [
                    'd0c00a39f87f1d1a04b7463af43f2683b8eb935f80985b7d070929d4cc8d8007',
                    'aa6ef478c7da82a207f79a0efaa2780228e42ef0f0b2aa4e79ffb551b32e646b'],
            ];
            $revision = $reviewedRevisions[$row['migration']] ?? null;
            $knownLegacy = $revision !== null
                && hash_equals($revision[0], $row['checksum'])
                && hash_equals($revision[1], $currentChecksum);
            if (!hash_equals($row['checksum'], $currentChecksum) && !$knownLegacy) {
                throw new RuntimeException('Applied migration checksum changed; manual review required.');
            }
            $applied[$row['migration']] = true;
        }
        if (array_keys($applied) !== array_slice(array_keys($scripts), 0, count($applied))) {
            throw new RuntimeException('Migration history is not an ordered prefix; manual review required.');
        }
    }
    $pending = array_diff_key($scripts, $applied);
    if (!$apply) {
        echo json_encode(['applied' => count($applied), 'pending' => array_keys($pending)], JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }
    if (!$hasLedger) {
        $db->query("CREATE TABLE schema_migrations (
            migration VARCHAR(190) NOT NULL PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            status ENUM('running','applied') NOT NULL,
            started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            applied_at TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    foreach ($pending as $migration => $sql) {
        $checksum = hash('sha256', $sql);
        $stmt = $db->prepare("INSERT INTO schema_migrations(migration,checksum,status) VALUES (?,?,'running')");
        $stmt->bind_param('ss', $migration, $checksum); $stmt->execute(); $stmt->close();
        // Marker is committed BEFORE DDL, because MySQL DDL cannot roll back.
        // A crash/error leaves a running marker and all future runs fail closed.
        $db->multi_query($sql);
        do {
            $result = $db->store_result();
            if ($result) { $result->free(); }
        } while ($db->more_results() && $db->next_result());
        $stmt = $db->prepare("UPDATE schema_migrations SET status='applied',applied_at=CURRENT_TIMESTAMP WHERE migration=? AND status='running'");
        $stmt->bind_param('s', $migration); $stmt->execute(); $stmt->close();
        echo 'Applied ' . $migration . PHP_EOL;
    }
    echo 'Deployment schema ready. No users, student samples or demo records were created.' . PHP_EOL;
} catch (Throwable $error) {
    if ($db instanceof mysqli) {
        try { $db->rollback(); } catch (Throwable $ignored) { }
    }
    // SQL driver messages can contain connection values or statement fragments.
    $message = $error instanceof mysqli_sql_exception
        ? 'Database operation failed. Inspect the migration journal; partial DDL may remain.'
        : $error->getMessage();
    error_log('[CSMS] Deployment migration stopped; type ' . get_class($error) . ', code ' . (int)$error->getCode());
    fwrite(STDERR, $message . PHP_EOL);
    $failed = true;
} finally {
    if ($db instanceof mysqli && $lockName !== null) {
        try {
            $stmt = $db->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->bind_param('s', $lockName); $stmt->execute(); $stmt->close();
        } catch (Throwable $ignored) { }
    }
}
exit(isset($failed) ? 1 : 0);
