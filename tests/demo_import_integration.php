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
    $program=$db->query('SELECT id,program_code,program_name,program_type,status FROM academic_programs')->fetch_assoc();
    if($program['program_code']!=='BSIT' || $program['program_name']!=='Bachelor of Science in Information Technology'
        || $program['program_type']!=="Bachelor's Degree" || $program['status']!=='active')
        throw new RuntimeException('Imported BSIT classification mismatch.');
    require_once __DIR__.'/../modules/curriculum/repositories/AcademicProgramRepository.php';
    $repo=new AcademicProgramRepository($db);
    $query=['search'=>null,'status'=>null,'program_type'=>null,'page'=>1,'per_page'=>20];
    if(count($repo->paginate($query+['program_category'=>'college'])['records'])!==1
        || count($repo->paginate($query+['program_category'=>'shs'])['records'])!==0)
        throw new RuntimeException('Imported BSIT category mismatch.');
    $db->begin_transaction();
    try {
        $db->query("INSERT INTO academic_programs(program_code,program_name,program_type) VALUES
            ('TEST-SHS','Test SHS','Senior High School'),('TEST-NULL','Test null',NULL),('TEST-EMPTY','Test empty','')");
        if(count($repo->paginate($query+['program_category'=>'college'])['records'])!==1
            || count($repo->paginate($query+['program_category'=>'shs'])['records'])!==1)
            throw new RuntimeException('Null/empty/SHS category mismatch.');
    } finally {$db->rollback();}
    if((int)$db->query("SELECT COUNT(*) FROM users WHERE id=1 AND role='admin'")->fetch_row()[0]!==1
        || (int)$db->query('SELECT COUNT(*) FROM students')->fetch_row()[0]!==0
        || (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='DEMO_DATASET_IMPORT'")->fetch_row()[0]!==1)
        throw new RuntimeException('Protected/admin/audit verification failed.');
    echo "PASS: 103 academic rows, 152 units, existing admin intact, one aggregate audit event.\n";
} elseif($mode==='legacy-fixture') {
    $db->query('UPDATE academic_programs SET program_type=NULL');
    $row=$db->query("SELECT id,metadata_json FROM audit_logs WHERE action='DEMO_DATASET_IMPORT'")->fetch_assoc();
    $metadata=json_decode($row['metadata_json'],true,512,JSON_THROW_ON_ERROR);
    $metadata['manifest_digest']='9676de90c7c5e7e57ad7afb23497ab6c697acb1b91616436401ad57e17b296d0';
    $json=json_encode($metadata,JSON_THROW_ON_ERROR);
    $stmt=$db->prepare('UPDATE audit_logs SET metadata_json=? WHERE id=?');
    $stmt->bind_param('si',$json,$row['id']);$stmt->execute();$stmt->close();
} elseif($mode==='repair-verify') {
    $row=$db->query('SELECT program_type FROM academic_programs')->fetch_row();
    if($row[0]!=="Bachelor's Degree"
        || (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='DEMO_PROGRAM_CLASSIFICATION_REPAIR'")->fetch_row()[0]!==1)
        throw new RuntimeException('Repair or audit count mismatch.');
    require_once __DIR__.'/../modules/curriculum/repositories/AcademicProgramRepository.php';
    $repo=new AcademicProgramRepository($db);
    $college=$repo->paginate(['search'=>null,'status'=>null,'program_type'=>null,
        'program_category'=>'college','page'=>1,'per_page'=>20]);
    if($college['pagination']['total_records']!==1 || $college['records'][0]['program_type']!=="Bachelor's Degree"
        || (int)$college['records'][0]['curriculum_count']!==1)
        throw new RuntimeException('Repaired BSIT is missing from College Programs.');
    foreach(['academic_programs'=>1,'curricula'=>1,'curriculum_versions'=>1,'subjects'=>50,'curriculum_version_subjects'=>50] as $table=>$count)
        if((int)$db->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0]!==$count)
            throw new RuntimeException('Repair changed academic counts.');
    echo "PASS: Repair classification, one audit event, and academic counts verified.\n";
} elseif($mode==='repair-snapshot') {
    $path=$argv[2]??'';
    if($path==='' || !is_dir(dirname($path)))throw new RuntimeException('Snapshot path required.');
    $snapshot=[];
    foreach(['users','roles','permissions','user_roles','role_permissions','students','audit_logs',
        'academic_programs','curricula','curriculum_versions','subjects','curriculum_version_subjects'] as $table) {
        $rows=$db->query("SELECT * FROM `$table` ORDER BY ".(in_array($table,['user_roles','role_permissions'],true)?'1,2':'id'))->fetch_all(MYSQLI_ASSOC);
        if($table==='audit_logs')$rows=array_values(array_filter($rows,
            static fn($row)=>$row['action']!=='DEMO_PROGRAM_CLASSIFICATION_REPAIR'));
        if($table==='academic_programs') {foreach($rows as &$row)unset($row['program_type']);unset($row);}
        $snapshot[$table]=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
    }
    if(($argv[3]??'')==='save')file_put_contents($path,json_encode($snapshot,JSON_THROW_ON_ERROR));
    elseif(($argv[3]??'')==='compare') {
        if($snapshot!==json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR))
            throw new RuntimeException('Protected or non-classification academic row changed.');
        echo "PASS: Protected and academic rows unchanged apart from program_type and one audit event.\n";
    } else throw new RuntimeException('Snapshot action required.');
} elseif($mode==='repair-value') {
    $value=$argv[2]??'';
    if(!in_array($value,['NULL','OTHER'],true))throw new RuntimeException('Invalid test value.');
    $db->query("UPDATE academic_programs SET program_type=".($value==='NULL'?'NULL':"'Associate Degree'"));
} elseif($mode==='repair-identity') {
    $name=$argv[2]??'';
    if(!in_array($name,['wrong','restore'],true))throw new RuntimeException('Invalid identity fixture.');
    $db->query("UPDATE academic_programs SET program_name='".($name==='wrong'?'Unrelated BSIT record':'Bachelor of Science in Information Technology')."'");
} elseif($mode==='repair-ownership') {
    $change=$argv[2]??'';
    if(!in_array($change,['wrong','restore'],true))throw new RuntimeException('Invalid ownership fixture.');
    $row=$db->query("SELECT id,metadata_json FROM audit_logs WHERE action='DEMO_DATASET_IMPORT'")->fetch_assoc();
    $metadata=json_decode($row['metadata_json'],true,512,JSON_THROW_ON_ERROR);
    $metadata['record_ids']['academic_programs'][0]=$change==='wrong'?999999:
        (int)$db->query('SELECT id FROM academic_programs')->fetch_row()[0];
    $json=json_encode($metadata,JSON_THROW_ON_ERROR);
    $stmt=$db->prepare('UPDATE audit_logs SET metadata_json=? WHERE id=?');
    $stmt->bind_param('si',$json,$row['id']);$stmt->execute();$stmt->close();
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
