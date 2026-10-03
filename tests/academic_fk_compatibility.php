<?php
// Disposable-schema CLI regression only; never included in production images.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/db.php';
$schema = $conn->query('SELECT DATABASE()')->fetch_row()[0];
if (!preg_match('/^csms_docker_test_[a-z0-9_]+$/D', $schema)
    || getenv('SMS_TEST_CONFIRM_DATABASE') !== $schema) {
    throw new RuntimeException('Explicit disposable database confirmation required.');
}
$legacy = in_array('--legacy', $argv, true);
$checks = 0;
function fkCheck(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: '.$label); }
    $checks++; echo 'PASS: '.$label.PHP_EOL;
}
function fkScript(string $sql): void {
    global $conn;
    $conn->multi_query($sql);
    do { if ($result=$conn->store_result()) { $result->free(); } }
    while ($conn->more_results() && $conn->next_result());
}
function fkSql(string $file): string {
    return preg_replace('/^USE sms_db;\s*$/m', '', str_replace("\r\n", "\n", file_get_contents($file)));
}
function fkDeploy(int $expected = 0): void {
    global $schema;
    $process=proc_open([PHP_BINARY,__DIR__.'/../database/deploy.php','--apply','--confirm-database='.$schema],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]);
    stream_get_contents($pipes[2]); fclose($pipes[2]);
    fkCheck(proc_close($process)===$expected,'Controlled deployment exit '.$expected);
}
function fkRows(): string {
    global $conn;
    $all=[];
    foreach ($conn->query('SHOW TABLES')->fetch_all(MYSQLI_NUM) as [$table]) {
        if ($table==='schema_migrations') { continue; }
        $rows=array_map('json_encode',$conn->query("SELECT * FROM `$table`")->fetch_all(MYSQLI_ASSOC));
        sort($rows); $all[$table]=$rows;
    }
    ksort($all); return hash('sha256',json_encode($all));
}
function fkReject(string $sql, array $codes, string $label): void {
    global $conn;
    try { $conn->query($sql); } catch (mysqli_sql_exception $e) {
        fkCheck(in_array($e->getCode(),$codes,true),$label); return;
    }
    throw new RuntimeException('FAIL: accepted invalid operation: '.$label);
}
if ($legacy) {
    fkCheck($conn->query('SHOW TABLES')->num_rows===0,'Legacy fixture requires empty schema');
    $baseline=str_replace("\r\n","\n",file_get_contents(__DIR__.'/../students.sql'));
    preg_match_all('/^CREATE TABLE IF NOT EXISTS (users|students) \([\s\S]*?^\) ENGINE=InnoDB[^;]*;/m',$baseline,$matches);
    fkCheck($matches[1]===['users','students'],'Baseline schema only, no sample data');
    $scripts=['000_baseline_schema'=>implode("\n",$matches[0])];
    foreach (glob(__DIR__.'/../database/migrations/*.sql') as $file) {
        if (substr(basename($file),0,3)>='013') { continue; }
        $sql=fkSql($file);
        if (in_array(substr(basename($file),0,3),['005','009','011'],true)) {
            $sql=str_replace('ON UPDATE RESTRICT ON DELETE RESTRICT','ON UPDATE CASCADE ON DELETE RESTRICT',$sql);
        }
        $scripts[basename($file)]=$sql;
    }
    foreach ($scripts as $sql) { fkScript($sql); }
    $conn->query("CREATE TABLE schema_migrations (migration VARCHAR(190) PRIMARY KEY,checksum CHAR(64) NOT NULL,status ENUM('running','applied') NOT NULL,started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,applied_at TIMESTAMP NULL DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $stmt=$conn->prepare("INSERT INTO schema_migrations(migration,checksum,status,applied_at) VALUES (?,?,'applied',CURRENT_TIMESTAMP)");
    foreach ($scripts as $name=>$sql) { $hash=hash('sha256',$sql);$stmt->bind_param('ss',$name,$hash);$stmt->execute(); }
    $stmt->close();
}
$tables=['compliance_rules','compliance_references','curriculum_subject_requirements','subject_equivalencies','curriculum_version_subjects','curriculum_versions','curricula','subjects','academic_programs'];
foreach ($tables as $table) { fkCheck((int)$conn->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0]===0,'Empty test fixture table '.$table); }
$before=fkRows();
$owned=[];
function fkInsert(string $table,string $sql): int {
    global $conn,$owned;
    $conn->query($sql); $id=(int)$conn->insert_id; $owned[$table][]=$id; return $id;
}
try {
    $p=fkInsert('academic_programs',"INSERT INTO academic_programs(program_code,program_name) VALUES ('TEST-FK','Test FK Program')");
    $c=fkInsert('curricula',"INSERT INTO curricula(curriculum_code,curriculum_name,academic_program_id) VALUES ('TEST-FK','Test FK Curriculum',$p)");
    $v=fkInsert('curriculum_versions',"INSERT INTO curriculum_versions(curriculum_id,version_label) VALUES ($c,'TEST-V1')");
    $w=fkInsert('curriculum_versions',"INSERT INTO curriculum_versions(curriculum_id,version_label) VALUES ($c,'TEST-V2')");
    $subjects=[];
    foreach (['A','B','C'] as $code) { $subjects[]=fkInsert('subjects',"INSERT INTO subjects(subject_code,subject_title) VALUES ('TEST-FK-$code','Test FK $code')"); }
    [$a,$b,$d]=$subjects;
    foreach ([$a,$b] as $s) { fkInsert('curriculum_version_subjects',"INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id) VALUES ($v,$s)"); }
    fkInsert('curriculum_version_subjects',"INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id) VALUES ($w,$d)");
    fkInsert('curriculum_subject_requirements',"INSERT INTO curriculum_subject_requirements(curriculum_version_id,subject_id,required_subject_id,requirement_type) VALUES ($v,$a,$b,'prerequisite')");
    fkInsert('subject_equivalencies',"INSERT INTO subject_equivalencies(subject_id,equivalent_subject_id) VALUES ($a,$b)");
    $ref=fkInsert('compliance_references',"INSERT INTO compliance_references(reference_type,title,issuing_body) VALUES ('OTHER','Test FK Reference','Test Issuer')");
    fkInsert('compliance_rules',"INSERT INTO compliance_rules(compliance_reference_id,rule_name,rule_type,subject_id,academic_program_id) VALUES ($ref,'Test FK Program Rule','REQUIRED_SUBJECT',$a,$p)");
    fkInsert('compliance_rules',"INSERT INTO compliance_rules(compliance_reference_id,rule_name,rule_type,subject_id,curriculum_id) VALUES ($ref,'Test FK Curriculum Rule','REQUIRED_SUBJECT',$b,$c)");
    $rows=fkRows();
    $shapeSql="SELECT TABLE_NAME,CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME";
    $shape=$conn->query($shapeSql)->fetch_all(MYSQLI_ASSOC);
    $indexSql="SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX";
    $indexes=$conn->query($indexSql)->fetch_all(MYSQLI_ASSOC);
    $autoSql="SELECT TABLE_NAME,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('curriculum_subject_requirements','subject_equivalencies','compliance_rules') ORDER BY TABLE_NAME";
    $auto=$conn->query($autoSql)->fetch_all(MYSQLI_ASSOC);
    $constraints=$conn->query("SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME")->fetch_all(MYSQLI_ASSOC);
    $keysBefore=$conn->query("SELECT CONSTRAINT_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY CONSTRAINT_NAME")->fetch_all(MYSQLI_ASSOC);
    if ($legacy) {
        fkCheck(count(array_filter($keysBefore,static fn($r)=>$r['UPDATE_RULE']==='CASCADE'))>0,'Legacy cascading FKs exist');
        fkDeploy();
        fkCheck(fkRows()===$rows,'Upgrade preserves every application row');
        fkCheck($shape===$conn->query($shapeSql)->fetch_all(MYSQLI_ASSOC),'All CHECK expressions preserved');
        $upgradedIndexes=$conn->query($indexSql)->fetch_all(MYSQLI_ASSOC);
        $electiveIndex=array_values(array_filter($upgradedIndexes,static fn($r)=>$r['TABLE_NAME']==='curriculum_elective_groups' && $r['INDEX_NAME']==='idx_elective_version_status'));
        fkCheck(array_column($electiveIndex,'COLUMN_NAME')===['curriculum_version_id','status'],'Electives migration adds version/status index');
        $originalIndexes=array_values(array_filter($upgradedIndexes,static fn($r)=>!($r['TABLE_NAME']==='curriculum_elective_groups' && $r['INDEX_NAME']==='idx_elective_version_status')));
        fkCheck($indexes===$originalIndexes,'All pre-existing UNIQUE and ordinary indexes preserved');
        fkCheck($auto===$conn->query($autoSql)->fetch_all(MYSQLI_ASSOC),'Affected AUTO_INCREMENT values preserved');
        fkCheck($constraints===$conn->query("SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME")->fetch_all(MYSQLI_ASSOC),'CHECK/UNIQUE/FK names and types preserved');
        foreach ($scripts as $name=>$sql) {
            $stmt=$conn->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');$stmt->bind_param('s',$name);$stmt->execute();
            fkCheck($stmt->get_result()->fetch_row()[0]===hash('sha256',$sql),'Historical journal evidence unchanged: '.$name);$stmt->close();
        }
        fkDeploy(); fkCheck(fkRows()===$rows,'Upgrade rerun preserves data');
        $target='005_create_curriculum_subject_requirements.sql';
        $conn->query("UPDATE schema_migrations SET checksum=REPEAT('0',64) WHERE migration='$target'");
        try { fkDeploy(1); fkCheck(fkRows()===$rows,'Unknown historical digest rejected without data changes'); }
        finally { $hash=hash('sha256',$scripts[$target]);$stmt=$conn->prepare('UPDATE schema_migrations SET checksum=? WHERE migration=?');$stmt->bind_param('ss',$hash,$target);$stmt->execute();$stmt->close(); }
    }
    $affected=['fk_curriculum_subject_requirements_target_placement','fk_curriculum_subject_requirements_required_placement','fk_subject_equivalencies_subject','fk_subject_equivalencies_equivalent_subject','fk_compliance_rules_subject','fk_compliance_rules_program','fk_compliance_rules_curriculum'];
    $keys=$conn->query("SELECT CONSTRAINT_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY CONSTRAINT_NAME")->fetch_all(MYSQLI_ASSOC);
    fkCheck(count($keys)===41,'All 41 foreign keys retained');
    foreach ($keys as $key) {
        if (in_array($key['CONSTRAINT_NAME'],$affected,true)) { fkCheck($key['UPDATE_RULE']==='RESTRICT' && $key['DELETE_RULE']==='RESTRICT',$key['CONSTRAINT_NAME'].' restricts update/delete'); }
        elseif ($legacy) { fkCheck(in_array($key,$keysBefore,true),'Unrelated FK unchanged: '.$key['CONSTRAINT_NAME']); }
    }
    fkReject("INSERT INTO curriculum_subject_requirements(curriculum_version_id,subject_id,required_subject_id,requirement_type) VALUES ($v,$a,$a,'prerequisite')",[4025,3819],'Self prerequisite rejected by CHECK');
    fkReject("INSERT INTO curriculum_subject_requirements(curriculum_version_id,subject_id,required_subject_id,requirement_type) VALUES ($v,$b,$a,'invalid')",[4025,3819],'Requirement type CHECK');
    fkReject("INSERT INTO curriculum_subject_requirements(curriculum_version_id,subject_id,required_subject_id,requirement_type) VALUES ($v,$a,$d,'prerequisite')",[1452],'Same-version composite FK enforced');
    fkReject("INSERT INTO curriculum_subject_requirements(curriculum_version_id,subject_id,required_subject_id,requirement_type) VALUES ($v,$a,$b,'prerequisite')",[1062],'Duplicate prerequisite rejected');
    fkReject("DELETE FROM curriculum_version_subjects WHERE curriculum_version_id=$v AND subject_id=$b",[1451],'Referenced placement deletion rejected');
    fkReject("UPDATE curriculum_version_subjects SET subject_id=$d WHERE curriculum_version_id=$v AND subject_id=$b",[1451],'Placement identity cannot cascade');
    fkReject("INSERT INTO subject_equivalencies(subject_id,equivalent_subject_id) VALUES ($b,$a)",[4025,3819],'Inverse equivalency rejected');
    fkReject("INSERT INTO subject_equivalencies(subject_id,equivalent_subject_id) VALUES ($a,$a)",[4025,3819],'Self equivalency rejected');
    fkReject("INSERT INTO subject_equivalencies(subject_id,equivalent_subject_id) VALUES ($a,$b)",[1062],'Duplicate equivalency rejected');
    fkReject("UPDATE subject_equivalencies SET decision_status='invalid' WHERE subject_id=$a",[4025,3819],'Equivalency decision CHECK');
    fkReject("UPDATE compliance_rules SET academic_program_id=$p,curriculum_id=$c WHERE compliance_reference_id=$ref",[4025,3819],'Compliance scope CHECK');
    fkReject("UPDATE compliance_rules SET subject_id=NULL WHERE compliance_reference_id=$ref",[4025,3819],'Compliance parameter CHECK');
    // Isolate equivalency protection from placement foreign keys.
    $conn->query('DELETE FROM compliance_rules WHERE id IN ('.implode(',',$owned['compliance_rules']).')');
    $owned['compliance_rules']=[];
    $conn->query('DELETE FROM curriculum_subject_requirements WHERE id IN ('.implode(',',$owned['curriculum_subject_requirements']).')');
    $owned['curriculum_subject_requirements']=[];
    $conn->query('DELETE FROM curriculum_version_subjects WHERE id IN ('.implode(',',$owned['curriculum_version_subjects']).')');
    $owned['curriculum_version_subjects']=[];
    fkReject("DELETE FROM subjects WHERE id=$a",[1451],'Equivalency alone protects subject deletion');
    fkReject("UPDATE subjects SET id=id+1000000 WHERE id=$a",[1451],'Equivalency blocks identity cascade');
} finally {
    foreach ($tables as $table) {
        if (!empty($owned[$table])) { $conn->query("DELETE FROM `$table` WHERE id IN (".implode(',',$owned[$table]).')'); }
    }
    fkCheck(fkRows()===$before,'All artificial rows removed; existing rows unchanged');
}
echo json_encode(['passed'=>true,'checks'=>$checks,'legacy_upgrade'=>$legacy]).PHP_EOL;
