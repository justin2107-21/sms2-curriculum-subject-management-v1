<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../shared/database_config.php';
$db=csms_database_connect(csms_database_config());
$mode=$argv[1]??'';
if($mode==='setup') {
    $db->query("INSERT INTO users(username,email,first_name,last_name,password_hash,role)
        VALUES('isolated-admin','isolated@example.invalid','Isolated','Admin','test-only-hash','admin')");
    $db->query("INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE name='admin'");
    echo "PASS: Existing admin fixture created only in disposable database.\n";
} elseif($mode==='verify') {
    require_once __DIR__.'/../database/import_demo.php';
    foreach(DEMO_TABLES as $table=>$expected) {
        $actual=(int)$db->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0];
        if($actual!==$expected)throw new RuntimeException($table.' count mismatch.');
    }
    $units=(float)$db->query('SELECT SUM(units) FROM subjects')->fetch_row()[0];
    if($units!==152.0)throw new RuntimeException('Unit total mismatch.');
    if((int)$db->query("SELECT COUNT(*) FROM users WHERE id=1 AND role='admin'")->fetch_row()[0]!==1
        || (int)$db->query('SELECT COUNT(*) FROM students')->fetch_row()[0]!==0
        || (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='DEMO_DATASET_IMPORT'")->fetch_row()[0]!==1)
        throw new RuntimeException('Protected/admin/audit verification failed.');
    echo "PASS: 103 academic rows, 152 units, existing admin intact, one aggregate audit event.\n";
} elseif($mode==='unrelated') {
    $db->query("INSERT INTO academic_programs(program_code,program_name) VALUES('UNRELATED','Isolated collision')");
} elseif($mode==='clear-unrelated') {
    $db->query("DELETE FROM academic_programs WHERE program_code='UNRELATED'");
} elseif($mode==='failure-on') {
    $db->query("CREATE TRIGGER isolated_demo_failure BEFORE INSERT ON subjects FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated injected failure'");
} elseif($mode==='failure-off') {
    $db->query('DROP TRIGGER isolated_demo_failure');
} elseif($mode==='empty') {
    require_once __DIR__.'/../database/import_demo.php';
    foreach(DEMO_TABLES as $table=>$expected)
        if((int)$db->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0]!==0)throw new RuntimeException('Rollback failed: '.$table);
    if((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='DEMO_DATASET_IMPORT'")->fetch_row()[0]!==0)
        throw new RuntimeException('Rollback left audit event.');
    echo "PASS: Failed import left no academic or ownership rows.\n";
} elseif($mode==='lock') {
    $lock='csms_production_demo_import_'.substr(hash('sha256','hf_db_9du6kxvk'),0,32);
    $stmt=$db->prepare('SELECT GET_LOCK(?,0)');$stmt->bind_param('s',$lock);$stmt->execute();
    if((int)$stmt->get_result()->fetch_row()[0]!==1)throw new RuntimeException('Test lock unavailable.');
    $stmt->close();
    try {
        $p=proc_open([PHP_BINARY,dirname(__DIR__).'/database/import_demo.php','--apply',
            '--confirm-dataset=DEV-BSIT-PREORAL-2026'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($p))throw new RuntimeException('Test importer unavailable.');
        fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);
        $error=stream_get_contents($pipes[2]);fclose($pipes[2]);
        if(proc_close($p)===0 || !str_contains($error,'Another demo import is running'))
            throw new RuntimeException('Advisory lock did not block import.');
        echo "PASS: Concurrent import refused by advisory lock.\n";
    } finally {
        $stmt=$db->prepare('SELECT RELEASE_LOCK(?)');$stmt->bind_param('s',$lock);$stmt->execute();$stmt->close();
    }
} else throw new RuntimeException('Unknown isolated test mode.');
