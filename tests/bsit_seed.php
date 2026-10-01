<?php
// Destructive tests only on the separate random-port XAMPP test instance.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../shared/database_config.php';
require_once __DIR__.'/../database/seeds/bsit_dataset.php';
$c=csms_database_config();
BsitDataset::require($c['host']==='127.0.0.1' && $c['port']!==3306 && getenv('SMS_TEST_LOCAL_FK')==='1'
    && preg_match('/^sms_db_foundation_test_[a-f0-9]{12}$/D',$c['database'])===1,'Isolated test database required.');
$conn=csms_database_connect($c);
BsitDataset::require((int)$conn->query('SELECT COUNT(*) FROM users')->fetch_row()[0]===0,'Fresh test actor scope required.');
$checks=0;
function bsitCheck(bool $ok,string $label): void {global $checks;BsitDataset::require($ok,$label);$checks++;echo "PASS: $label\n";}
function bsitState(mysqli $db): array {
    $state=[];foreach(array_merge(BsitDataset::ORDER,array_diff(BsitDataset::PROTECTED,['audit_logs'])) as $t) {
        $rows=$db->query("SELECT * FROM $t ORDER BY ".(in_array($t,['user_roles','role_permissions'],true)?'1,2':'id'))->fetch_all(MYSQLI_ASSOC);
        $state[$t]=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
    }return $state;
}
function bsitRefuses(callable $fn,string $label): void {
    global $conn;$before=bsitState($conn);$audit=(int)$conn->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0];$failed=false;
    try{$fn();}catch(Throwable $e){$failed=true;}
    bsitCheck($failed,$label.' rejected');
    bsitCheck(bsitState($conn)===$before && (int)$conn->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0]===$audit,$label.' leaves records/audits unchanged');
}
$hash=password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT);
$s=$conn->prepare("INSERT INTO users(username,email,first_name,last_name,password_hash,role) VALUES ('BSIT-TEST-ACTOR','bsit-test@example.invalid','TEST','ACTOR',?,'admin')");
$s->bind_param('s',$hash);$s->execute();$actor=(int)$conn->insert_id;$s->close();
$conn->query("INSERT INTO user_roles(user_id,role_id) SELECT $actor,id FROM roles WHERE name='admin'");
$conn->query("INSERT INTO academic_programs(program_code,program_name) VALUES ('TEST-KEEP','UNRELATED SENTINEL')");
$sentinel=(int)$conn->insert_id;$baseline=bsitState($conn);
$tool=new BsitDataset($conn,$actor,BsitDataset::manifest());
$conn->query("CREATE TRIGGER bsit_test_failure BEFORE INSERT ON subjects FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='TEST rollback'");
try{bsitRefuses(fn()=>$tool->run('seed'),'Partial insertion failure');}
finally{$conn->query('DROP TRIGGER bsit_test_failure');}
$conn->query("INSERT INTO academic_programs(program_code,program_name) VALUES ('BSIT','TEST COLLISION')");$collision=(int)$conn->insert_id;
bsitRefuses(fn()=>$tool->run('seed'),'Unowned code collision');$conn->query("DELETE FROM academic_programs WHERE id=$collision");
$seed=$tool->run('seed');bsitCheck(array_sum($seed['counts'])===103 && $seed['units']===152,'103 seeded rows / 152 units');
bsitCheck($tool->run('verify')['unchanged']===true,'Stored exact values verified');
$before=bsitState($conn);$audit=(int)$conn->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0];
bsitCheck($tool->run('seed')['unchanged']===true && bsitState($conn)===$before
    && (int)$conn->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0]===$audit,'Second seed is a true no-op, no duplicate audit');
$id=$seed['owned_ids']['subjects'][0];$row=$conn->query("SELECT * FROM subjects WHERE id=$id")->fetch_assoc();
$conn->query("UPDATE subjects SET subject_title='TEST MODIFIED' WHERE id=$id");
bsitRefuses(fn()=>$tool->run('cleanup'),'Changed ownership fingerprint');
$s=$conn->prepare('UPDATE subjects SET subject_title=?,updated_at=? WHERE id=?');$s->bind_param('ssi',$row['subject_title'],$row['updated_at'],$id);$s->execute();$s->close();
$event=$conn->query("SELECT id,metadata_json FROM audit_logs WHERE action='DEVELOPMENT_SEED_CREATE' ORDER BY id DESC LIMIT 1")->fetch_assoc();
foreach(['bad-entry','wrong-table','wrong-id'] as $bad) {
    $meta=json_decode($event['metadata_json'],true);
    if($bad==='bad-entry')$meta['records']['subjects'][$id]='corrupted';
    elseif($bad==='wrong-table')unset($meta['records']['curricula']);
    else {$meta['records']['academic_programs']=[$sentinel=>str_repeat('0',64)];}
    $json=json_encode($meta);$eid=(int)$event['id'];
    $s=$conn->prepare('UPDATE audit_logs SET metadata_json=? WHERE id=?');$s->bind_param('si',$json,$eid);$s->execute();$s->close();
    bsitRefuses(fn()=>$tool->run('cleanup'),'Corrupt provenance '.$bad);
    $json=$event['metadata_json'];$s=$conn->prepare('UPDATE audit_logs SET metadata_json=? WHERE id=?');$s->bind_param('si',$json,$eid);$s->execute();$s->close();
}
$program=$seed['owned_ids']['academic_programs'][0];
$conn->query("INSERT INTO curricula(curriculum_code,curriculum_name,academic_program_id) VALUES ('TEST-EXTERNAL','TEST dependency',$program)");$external=(int)$conn->insert_id;
bsitRefuses(fn()=>$tool->run('cleanup'),'External dependency');$conn->query("DELETE FROM curricula WHERE id=$external");
require __DIR__.'/bsit_reads.php';
$tool->run('cleanup');bsitCheck(bsitState($conn)===$baseline,'Cleanup preserves unrelated sentinel and system records');
bsitRefuses(fn()=>$tool->run('cleanup'),'Repeated cleanup');
$tool->run('seed');$tool->run('cleanup');bsitCheck(bsitState($conn)===$baseline,'Reseed and cleanup safe');
$conn->query("DELETE FROM academic_programs WHERE id=$sentinel");
// Approved legacy fixture in this isolated database only, with exact source identities.
$legacy=['academic_programs'=>[
    [28,'ITE4','IT Elective IV'],[47,'PE','Physical Education'],[50,'cap101','capstone1'],[51,'IAS','INTEGRATION'],
    [52,'ASDFASDF','ADSFASF'],[53,'ASDFA','ASDFA'],[54,'ASDFAS','ASDFASDFA'],[55,'ASDFASDFASDF','ASDFASDFASDF'],[56,'ASDFASF','ASDFASDF']],
    'subjects'=>[[17,'ITE4','IT Elective 4'],[54,'GE 9','RIZAL']]];
foreach($legacy as $table=>$rows){
    $fields=$table==='subjects'?'subject_code,subject_title':'program_code,program_name';
    $s=$conn->prepare("INSERT INTO $table(id,$fields) VALUES (?,?,?)");
    foreach($rows as [$lid,$code,$name]){$s->bind_param('iss',$lid,$code,$name);$s->execute();}$s->close();
}
$conn->query("INSERT INTO curricula(id,curriculum_code,curriculum_name,academic_program_id) VALUES (34,'PE','PHYSICAL EDUCATION',47)");
$conn->begin_transaction();$plan=$tool->legacyPlan();$conn->rollback();
$bad=$plan;$bad['records']['subjects'][17]=str_repeat('0',64);
bsitRefuses(fn()=>$tool->run('replace',$bad),'Stale cleanup plan');
$conn->query("CREATE TRIGGER bsit_test_failure BEFORE INSERT ON subjects FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='TEST rollback'");
try{bsitRefuses(fn()=>$tool->run('replace',$plan),'Replacement rollback restores deleted legacy rows');}
finally{$conn->query('DROP TRIGGER bsit_test_failure');}
$replaced=$tool->run('replace',$plan);bsitCheck($replaced['units']===152,'Approved legacy replacement succeeds atomically');
bsitCheck((int)$conn->query('SELECT COUNT(*) FROM academic_programs')->fetch_row()[0]===1,'Nine legacy programs replaced by one BSIT');
$tool->run('cleanup');
echo "PASS: $checks BSIT isolated safety checks; disposable database removed by parent harness.\n";
