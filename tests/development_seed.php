<?php
// This test is restricted to a newly bootstrapped disposable database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!preg_match('/^sms_db_foundation_test_[a-z0-9_]+$/D', getenv('SMS_DB_NAME') ?: '')) {
    fwrite(STDERR,"Set SMS_DB_NAME to a fresh sms_db_foundation_test_* database. Never run on sms_db.\n"); exit(1);
}
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/http.php';
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/authorization.php';
require_once __DIR__ . '/../shared/audit.php';
foreach(glob(__DIR__.'/../modules/curriculum/services/*Service.php') as $file) { require_once $file; }
$database=$conn->query('SELECT DATABASE()')->fetch_row()[0];
if ((int)$conn->query('SELECT COUNT(*) FROM users')->fetch_row()[0] !== 0
    || (int)$conn->query('SELECT COUNT(*) FROM academic_programs')->fetch_row()[0] !== 0) {
    throw new RuntimeException('Seed test requires fresh tables. Nothing was reset.');
}
$checks=0;
function seedCheck(bool $ok,string $label): void {
    global $checks;
    if(!$ok) { throw new RuntimeException('FAIL: '.$label); }
    $checks++; echo 'PASS: '.$label.PHP_EOL;
}
function snapshot(mysqli $db): array {
    $snapshot=[];
    foreach($db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM) as [$table]) {
        if($table==='audit_logs') continue;
        $rows=$db->query("SELECT * FROM `$table`")->fetch_all(MYSQLI_ASSOC);
        $encoded=array_map(static fn($row)=>json_encode($row,JSON_THROW_ON_ERROR),$rows);
        sort($encoded); $snapshot[$table]=hash('sha256',implode("\n",$encoded));
    }
    return $snapshot;
}
function runDemo(string $action,int $expected=0): void {
    global $database,$actor;
    $command=[PHP_BINARY,__DIR__.'/../database/seeds/development.php','--action='.$action,'--database='.$database,'--actor='.$actor,'--confirm-development'];
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process)) throw new RuntimeException('Cannot launch CLI seed test.');
    fclose($pipes[0]); $output=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors=stream_get_contents($pipes[2]); fclose($pipes[2]); $exit=proc_close($process);
    if($exit!==$expected) { fwrite(STDERR,$errors); }
    seedCheck($exit===$expected,'Seed tool '.$action.' exit '.$expected);
}
$hash=password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT);
$stmt=$conn->prepare("INSERT INTO users(username,email,first_name,last_name,password_hash,role) VALUES ('DEV-REGTEST-SEED-ACTOR','seed-test@example.invalid','DEV','TEST',?,'admin')");
$stmt->bind_param('s',$hash);$stmt->execute();$actor=(int)$conn->insert_id;$stmt->close();
$conn->query("INSERT INTO user_roles(user_id,role_id) SELECT $actor,id FROM roles WHERE name='admin'");
$_SESSION=['user_id'=>$actor];
seedCheck(in_array('admin',get_user_roles($actor),true),'Isolated existing admin actor');
$conn->query("INSERT INTO academic_programs(program_code,program_name) VALUES ('TEST-FOUNDATION-KEEP','UNRELATED SYNTHETIC SENTINEL')");
$sentinel=(int)$conn->insert_id;
$baseline=snapshot($conn);
// Fail partway through the seed and prove earlier related writes roll back.
$conn->query("CREATE TRIGGER foundation_seed_failure BEFORE INSERT ON subjects FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic rollback test'");
try { runDemo('seed',1); seedCheck(snapshot($conn)===$baseline,'Partial seed failure rolls back'); }
finally { $conn->query('DROP TRIGGER foundation_seed_failure'); }
$conn->query("INSERT INTO academic_programs(program_code,program_name) VALUES ('DEV-DEMO-PROG-A','TEST-FOUNDATION-COLLISION')");
$collision=(int)$conn->insert_id; $collisionSnapshot=snapshot($conn);
runDemo('seed',1); seedCheck(snapshot($conn)===$collisionSnapshot,'Identifier collision preserved, not adopted');
$conn->query("DELETE FROM academic_programs WHERE id=$collision");
runDemo('seed'); runDemo('verify');
$seedSnapshot=snapshot($conn);
runDemo('seed',1); seedCheck(snapshot($conn)===$seedSnapshot,'Accidental rerun changes nothing');
$programs=(new AcademicProgramService($conn))->list(['search'=>'DEV-DEMO-']);
seedCheck($programs['pagination']['total_records']===2,'Demo programs');
$subjects=(new SubjectService($conn))->list(['search'=>'DEV-DEMO-']);
seedCheck($subjects['pagination']['total_records']===6,'Demo subjects');
$curricula=(new CurriculumService($conn))->list(['search'=>'DEV-DEMO-']);
seedCheck($curricula['pagination']['total_records']===2,'Demo curricula');
$program=(int)$conn->query("SELECT id FROM academic_programs WHERE program_code='DEV-DEMO-PROG-A'")->fetch_row()[0];
$curriculum=(int)$conn->query("SELECT id FROM curricula WHERE curriculum_code='DEV-DEMO-CURR-A'")->fetch_row()[0];
$versions=(new CurriculumVersionService($conn))->list($curriculum,[]);
seedCheck($versions['pagination']['total_records']===2,'Demo curriculum versions');
$v1=(int)$conn->query("SELECT id FROM curriculum_versions WHERE curriculum_id=$curriculum AND version_label='DEV-V1'")->fetch_row()[0];
$v2=(int)$conn->query("SELECT id FROM curriculum_versions WHERE curriculum_id=$curriculum AND version_label='DEV-V2'")->fetch_row()[0];
$subject=(int)$conn->query("SELECT id FROM subjects WHERE subject_code='DEV-DEMO-101'")->fetch_row()[0];
$subjectRow=$conn->query("SELECT * FROM subjects WHERE id=$subject")->fetch_assoc();
$conn->query("UPDATE subjects SET subject_title='TEST-FOUNDATION-EDITED' WHERE id=$subject");
$editedSnapshot=snapshot($conn); runDemo('cleanup',1);
seedCheck(snapshot($conn)===$editedSnapshot,'Cleanup refuses edited demo records without partial deletion');
$stmt=$conn->prepare('UPDATE subjects SET subject_title=?,updated_at=? WHERE id=?');
$stmt->bind_param('ssi',$subjectRow['subject_title'],$subjectRow['updated_at'],$subject);$stmt->execute();$stmt->close();
$conn->query("INSERT INTO curricula(curriculum_code,curriculum_name,academic_program_id) VALUES ('TEST-FOUNDATION-EXTERNAL','UNRELATED DEPENDENCY',$program)");
$external=(int)$conn->insert_id;$dependentSnapshot=snapshot($conn);
runDemo('cleanup',1); seedCheck(snapshot($conn)===$dependentSnapshot,'External dependency rolls back entire cleanup');
$conn->query("DELETE FROM curricula WHERE id=$external");
seedCheck((new CurriculumVersionSubjectService($conn))->listMappings(['curriculum_version_id'=>$v1])['pagination']['total_records']===5,'Demo subject mappings');
seedCheck((new CurriculumSubjectRequirementService($conn))->list(['curriculum_version_id'=>$v1])['pagination']['total_records']===3,'Demo prerequisite/corequisite');
$groups=(new CurriculumElectiveGroupService($conn))->list($v1,[]);
seedCheck($groups['pagination']['total_records']===1,'Demo elective group');
seedCheck((new CurriculumElectiveGroupSubjectService($conn))->list((int)$groups['groups'][0]['id'],[])['pagination']['total_records']===2,'Demo elective members');
foreach(['program','subject'] as $view) {
    seedCheck((new AcademicProgramAssignmentService($conn))->listAssignments(['view'=>$view,'search'=>'DEV-DEMO-101'])['pagination']['total_records']===3,'Demo '.$view.' assignment view');
}
$historyService=new SubjectOfferingHistoryService($conn);
seedCheck($historyService->listHistory(['search'=>'DEV-DEMO-101'])['summary']['programs']===2,'Demo shared subject history');
$comparison=$historyService->compareVersions(['curriculum_id'=>$curriculum,'version_a_id'=>$v1,'version_b_id'=>$v2]);
seedCheck($comparison['summary']===['added'=>1,'removed'=>1,'changed'=>1,'unchanged'=>3],'Demo Added/Removed/Changed/Unchanged');
foreach(['pending','equivalent','not_equivalent'] as $decision) {
    seedCheck((new SubjectEquivalencyService($conn))->list(['decision_status'=>$decision])['pagination']['total_records']===1,'Demo review '.$decision);
}
$placement=(int)$conn->query("SELECT id FROM curriculum_version_subjects WHERE curriculum_version_id=$v1 AND subject_id=$subject")->fetch_row()[0];
$scheme=(new GradeWeightingService($conn))->getSchemeForPlacement($placement)['scheme'];
seedCheck($scheme['total_weight']==='100.00' && $scheme['completion_status']==='complete','Demo weights exactly 100');
$run=(new ComplianceValidationService($conn))->run($v1,$actor,true);
seedCheck($run['summary']===['rules_evaluated'=>5,'passed'=>3,'failed'=>1,'not_evaluated'=>1],'Demo synthetic compliance outcomes');
$exportService=new CurriculumExportService($conn);
$export=$exportService->dataset(['academic_program_id'=>$program,'curriculum_id'=>$curriculum,'curriculum_version_id'=>$v1,
    'include_requirements'=>1,'include_electives'=>1,'include_grade_weighting'=>1,'include_compliance'=>1]);
seedCheck(count($export['subjects'])===5 && count($export['requirements'])===3 && $export['latest_compliance_run']===null,'Demo export all optional sections (a run that evaluated demo rules is not exported)');
$csv=fopen('php://temp','w+b');$exportService->writeCsv($csv,$export);
seedCheck(ftell($csv)>0,'Demo CSV generated in memory');fclose($csv);
$auditCount=(int)$conn->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0];
runDemo('cleanup');
seedCheck(snapshot($conn)===$baseline,'Cleanup preserves every unrelated table row and all schema tables');
seedCheck((int)$conn->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0]===$auditCount+1,'Cleanup retains audit evidence');
runDemo('cleanup',1); seedCheck(snapshot($conn)===$baseline,'Repeat cleanup fails harmlessly');
runDemo('seed');runDemo('cleanup');seedCheck(snapshot($conn)===$baseline,'Reload/cleanup cycle safe');
$conn->query("DELETE FROM academic_programs WHERE id=$sentinel");
$conn->query("DELETE FROM user_roles WHERE user_id=$actor");
$conn->query("DELETE FROM users WHERE id=$actor");
echo json_encode(['passed'=>true,'checks'=>$checks,'database'=>$database,'note'=>'Temporary test actor and sentinel removed; audit retained until disposable database is dropped.']).PHP_EOL;
