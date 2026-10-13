<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/db.php';
$database = $conn->query('SELECT DATABASE()')->fetch_row()[0];
if (!preg_match('/^(csms_docker_test_|sms_db_foundation_test_deploy_)[a-z0-9_]+$/D', $database)
    || getenv('SMS_TEST_CONFIRM_DATABASE') !== $database || $conn->query('SHOW TABLES')->num_rows !== 0) {
    fwrite(STDERR,"Requires an explicitly confirmed, empty disposable deployment database.\n"); exit(1);
}
$checks = 0;
function deployCheck(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    $checks++; echo 'PASS: ' . $label . PHP_EOL;
}
function deploymentRun(array $args, int $expected): string {
    $process = proc_open(array_merge([PHP_BINARY,__DIR__.'/../database/deploy.php'],$args),
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start migration CLI.'); }
    fclose($pipes[0]); $output=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error=stream_get_contents($pipes[2]); fclose($pipes[2]); $exit=proc_close($process);
    if ($exit !== $expected) {
        // Report only allowlisted runner diagnostics, never raw SQL/connection output.
        preg_match_all('/^Applied [a-z0-9_.]+$/m', $output, $applied);
        foreach ($applied[0] as $line) { fwrite(STDERR, $line . PHP_EOL); }
        if (preg_match('/Deployment migration stopped; type [A-Za-z_]+, code [0-9]+/', $error, $failure)) {
            fwrite(STDERR, $failure[0] . PHP_EOL);
        }
    }
    deployCheck($exit===$expected,'Migration CLI exit '.$expected);
    return $output.$error;
}
function deploymentSnapshot(mysqli $db): string {
    $tables=[];
    foreach($db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM) as [$table]) {
        $rows=array_map(static fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$db->query("SELECT * FROM `$table`")->fetch_all(MYSQLI_ASSOC));
        sort($rows); $tables[$table]=$rows;
    }
    ksort($tables); return hash('sha256',json_encode($tables,JSON_THROW_ON_ERROR));
}
$status=json_decode(deploymentRun(['--status'],0),true);
$expectedMigrations = 1 + count(glob(__DIR__ . '/../database/migrations/[0-9][0-9][0-9]_*.sql'));
deployCheck($status['applied']===0 && count($status['pending'])===$expectedMigrations,'Pending entries match baseline and migration files');
deployCheck($conn->query('SHOW TABLES')->num_rows===0,'Status does not create a journal');
deploymentRun(['--apply','--confirm-database=wrong-target'],1);
deployCheck($conn->query('SHOW TABLES')->num_rows===0,'Wrong confirmation writes nothing');
// An existing schema without a journal must never be implicitly adopted.
$conn->query('CREATE TABLE deployment_sentinel (id INT PRIMARY KEY)');
$conn->query('INSERT INTO deployment_sentinel VALUES (1)');
deploymentRun(['--apply','--confirm-database='.$database],1);
deployCheck($conn->query('SHOW TABLES')->num_rows===1 && (int)$conn->query('SELECT COUNT(*) FROM deployment_sentinel')->fetch_row()[0]===1,'Untracked schema left intact');
$conn->query('DROP TABLE deployment_sentinel');
$lock='csms-deploy-'.substr(hash('sha256',$database),0,48);
$stmt=$conn->prepare('SELECT GET_LOCK(?,0)');$stmt->bind_param('s',$lock);$stmt->execute();
deployCheck((int)$stmt->get_result()->fetch_row()[0]===1,'Test migration lock acquired');$stmt->close();
try { deploymentRun(['--apply','--confirm-database='.$database],1); }
finally { $stmt=$conn->prepare('SELECT RELEASE_LOCK(?)');$stmt->bind_param('s',$lock);$stmt->execute();$stmt->close(); }
deployCheck($conn->query('SHOW TABLES')->num_rows===0,'Concurrent migration blocked before writes');
deploymentRun(['--apply','--confirm-database='.$database],0);
deployCheck($conn->query('SHOW TABLES')->num_rows===33,'32 application tables plus migration journal through 024');
foreach(['users','students','academic_programs','subjects','curricula'] as $table) {
    deployCheck((int)$conn->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0]===0,'No seeded records: '.$table);
}
deployCheck((int)$conn->query("SELECT COUNT(*) FROM schema_migrations WHERE status='applied'")->fetch_row()[0]===$expectedMigrations,'All migrations journaled');
// Migration 022 registers three Task 3 capabilities without granting them.
deployCheck((int)$conn->query('SELECT COUNT(*) FROM permissions')->fetch_row()[0]===43,'Permissions initialized including ungranted clone and outcome-mapping capabilities');
deployCheck((int)$conn->query("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.name='curriculum.version.clone'")->fetch_row()[0]===0,'Clone permission is not automatically granted');
deployCheck((int)$conn->query("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.name IN ('curriculum.reference.attach','compliance.reference.verify','curriculum.reference.classify')")->fetch_row()[0]===0,'Task 3 permissions are not automatically granted');
deployCheck((int)$conn->query("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.name='curriculum.outcome_mapping.manage'")->fetch_row()[0]===0,'Outcome-mapping permission is not automatically granted');
$snapshot=deploymentSnapshot($conn);
deploymentRun(['--apply','--confirm-database='.$database],0);
deployCheck(deploymentSnapshot($conn)===$snapshot,'Second apply changes no data');
$row=$conn->query("SELECT * FROM schema_migrations WHERE migration='000_baseline_schema'")->fetch_assoc();
$conn->query("UPDATE schema_migrations SET checksum=REPEAT('0',64) WHERE migration='000_baseline_schema'");
try { deploymentRun(['--apply','--confirm-database='.$database],1); }
finally { $stmt=$conn->prepare("UPDATE schema_migrations SET checksum=? WHERE migration='000_baseline_schema'");$stmt->bind_param('s',$row['checksum']);$stmt->execute();$stmt->close(); }
deployCheck(deploymentSnapshot($conn)===$snapshot,'Changed checksum refused without data mutation');
$conn->query("UPDATE schema_migrations SET status='running' WHERE migration='000_baseline_schema'");
try { deploymentRun(['--apply','--confirm-database='.$database],1); }
finally { $conn->query("UPDATE schema_migrations SET status='applied' WHERE migration='000_baseline_schema'"); }
deployCheck(deploymentSnapshot($conn)===$snapshot,'Incomplete marker refuses replay');
$conn->query("INSERT INTO schema_migrations(migration,checksum,status) VALUES ('999_unknown',REPEAT('0',64),'applied')");
try { deploymentRun(['--apply','--confirm-database='.$database],1); }
finally { $conn->query("DELETE FROM schema_migrations WHERE migration='999_unknown'"); }
deployCheck(deploymentSnapshot($conn)===$snapshot,'Unknown migration refused');
$status=json_decode(deploymentRun(['--status'],0),true);
deployCheck($status['applied']===$expectedMigrations && $status['pending']===[],'No pending migrations');
echo json_encode(['passed'=>true,'checks'=>$checks]).PHP_EOL;
