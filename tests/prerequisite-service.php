<?php
// Real repository/service regression using connection-local temporary tables only.
// Permanent tables are fingerprinted before and after. No academic seed is modified.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/../modules/curriculum/services/CurriculumSubjectRequirementService.php';
require_once __DIR__ . '/../modules/curriculum/services/CurriculumVersionSubjectService.php';
$config = csms_database_config();
if (!in_array($config['host'], ['localhost','127.0.0.1'], true) || getenv('APP_ENV') === 'production') throw new RuntimeException('Local development database required.');
$conn = csms_database_connect($config);
$tables = ['academic_programs','curricula','curriculum_versions','subjects','curriculum_version_subjects','curriculum_subject_requirements','audit_logs'];
$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function fingerprints(mysqli $db, array $tables): array { $out=[]; foreach($tables as $t) $out[$t]=hash('sha256',json_encode($db->query("SELECT * FROM `$t` ORDER BY id")->fetch_all(MYSQLI_ASSOC)));return $out; }
$before = fingerprints($conn,$tables); $temporary=[];
try {
    foreach($tables as $table) { $conn->query("CREATE TEMPORARY TABLE __pr_template LIKE `$table`"); $conn->query("CREATE TEMPORARY TABLE `$table` LIKE __pr_template"); $conn->query("DROP TEMPORARY TABLE __pr_template"); $temporary[]=$table; }
    $conn->query("INSERT INTO academic_programs(id,program_code,program_name,program_type) VALUES (1,'TEST','Test College','College'),(2,'SHS','Test SHS','SHS')");
    $conn->query("INSERT INTO curricula(id,curriculum_code,curriculum_name,academic_program_id) VALUES (1,'T','Test',1),(2,'S','SHS',2)");
    $conn->query("INSERT INTO curriculum_versions(id,curriculum_id,version_label) VALUES(1,1,'V1'),(2,2,'SHS')");
    foreach(range(1,6) as $id) {
        $conn->query("INSERT INTO subjects(id,subject_code,subject_title,units) VALUES ($id,'T$id','Subject $id',3)");
        $year=$id.' Year';$term=$id===6?'NULL':"'1st Semester'";
        $conn->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES (1,$id,'$year',$term)");
        $year=$id===5?'Grade 12':'Grade 11'; $shsTerm=$id===1?'1st Semester':'2nd Semester';
        $conn->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES (2,$id,'$year','$shsTerm')");
    }
    $academicBefore=fingerprints($conn,array_slice($tables,0,5));
    $service=new CurriculumSubjectRequirementService($conn);
    $create=fn($t,$r,$extra=[])=>$service->create($extra+['curriculum_version_id'=>1,'subject_id'=>$t,'required_subject_id'=>$r,'requirement_type'=>'prerequisite'],1);
    $reject=function(callable $fn,string $message)use($conn){$before=fingerprints($conn,['curriculum_subject_requirements','audit_logs']);try{$fn();throw new RuntimeException('Expected rejection: '.$message);}catch(DomainValidationException|DomainConflictException $e){check(str_contains($e->getMessage(),$message),'Exact rejection: '.$message);}check($before===fingerprints($conn,['curriculum_subject_requirements','audit_logs']),'Rejected writes and audits must roll back');};
    check(count($service->workspace(1)['placements'])===6,'Version placements loaded');
    check($service->workspace(1)['relationships']===[],'No code-based inferred rules');
    $reject(fn()=>$create(1,1),'A subject cannot be its own prerequisite.');
    $reject(fn()=>$create(1,999),'not mapped');
    $a=$create(2,1);check($a['subject_code']==='T2' && $a['required_subject_code']==='T1','Subject identity joins');
    $reject(fn()=>$create(2,1),'This prerequisite relationship already exists.');
    $reject(fn()=>$create(1,2),'This relationship would create a circular prerequisite chain.');
    $b=$create(3,2);$c=$create(4,3);
    $reject(fn()=>$create(1,4),'This relationship would create a circular prerequisite chain.');
    $d=$create(3,1);check(count($service->workspace(1)['relationships'])===4,'Multiple prerequisites and branching stored');
    $mappingService = new CurriculumVersionSubjectService($conn);
    $placementId = fn($subject) => (int)$conn->query("SELECT id FROM curriculum_version_subjects WHERE curriculum_version_id=1 AND subject_id=$subject")->fetch_row()[0];
    $move = fn($subject, $year, $semester='1st Semester') => $mappingService->updatePlacement($placementId($subject), ['year_level'=>$year,'semester'=>$semester,'is_required'=>'1'], true);
    $beforeMoves = fingerprints($conn, ['curriculum_version_subjects']);
    $reject(fn()=>$move(2,'4 Year'),'earlier academic period');
    $reject(fn()=>$move(2,'1 Year'),'same period');
    $reject(fn()=>$move(2,'3 Year'),'same period');
    $reject(fn()=>$move(2,'Custom'),'Sequence cannot be verified');
    check($beforeMoves===fingerprints($conn,['curriculum_version_subjects']),'Blocked placement edits preserve all placements');
    $preview=$mappingService->previewPlacement($placementId(2),['year_level'=>'2 Year','semester'=>'2nd Semester','is_required'=>'1']);
    check($preview['valid'] && $preview['severity']==='warning' && count($preview['relationships'])===2,'Both direct directions previewed');
    $move(2,'2 Year','2nd Semester'); check(true,'Ordered dependency placement update saved');
    $move(2,'2 Year');
    $move(5,'8th Year'); check(true,'Unrelated placement can move normally'); $move(5,'5 Year');
    // Restore fixture timestamps before the existing academic-record fingerprint check.
    $conn->query('UPDATE curriculum_version_subjects SET updated_at=created_at WHERE curriculum_version_id=1 AND subject_id IN (2,5)');
    check(count($service->workspace(2)['relationships'])===0,'Version scope');
    $reject(fn()=>$create(2,5),'earlier academic period');
    $reject(fn()=>$create(2,5,['confirm_sequencing'=>'1']),'earlier academic period');
    $conn->query("INSERT INTO curriculum_subject_requirements(curriculum_version_id,subject_id,required_subject_id,requirement_type) VALUES(1,2,5,'prerequisite')"); $warning=['id'=>$conn->insert_id];
    check(count($service->workspace(1)['review']['sequencing'])===1,'Actual sequencing warning count');
    $reject(fn()=>$create(2,6),'Sequence cannot be verified');
    $reject(fn()=>$create(6,1),'Sequence cannot be verified');
    $service->remove((int)$warning['id']);check(count($service->workspace(1)['review']['sequencing'])===0,'Removal refreshes warnings');
    $expected=[(int)$b['id'],(int)$d['id']];
    $update=fn($ids,$extras=[])=>$service->updateRule($extras+['curriculum_version_id'=>1,'subject_id'=>3,'required_subject_ids'=>$ids,'expected_ids'=>$expected],1);
    $reject(fn()=>$update([1,1]),'This prerequisite relationship already exists.');
    $reject(fn()=>$update([3]),'A subject cannot be its own prerequisite.');
    $reject(fn()=>$update([4]),'This relationship would create a circular prerequisite chain.');
    $reject(fn()=>$update([1],['expected_ids'=>[]]),'This rule changed.');
    $reject(fn()=>$update([6]),'Sequence cannot be verified');
    $update([1]);check(count($service->workspace(1)['relationships'])===3,'Atomic edit removes only selected edge');
    $retained=$conn->query('SELECT id FROM curriculum_subject_requirements WHERE subject_id=3')->fetch_row()[0];check((int)$retained===(int)$d['id'],'Unchanged edge IDs preserved');
    $core=$service->create(['curriculum_version_id'=>1,'subject_id'=>3,'required_subject_id'=>2,'requirement_type'=>'corequisite'],1);
    $service->updateRule(['curriculum_version_id'=>1,'subject_id'=>3,'expected_ids'=>[$d['id']],'required_subject_ids'=>[]],1);
    check((int)$conn->query("SELECT COUNT(*) FROM curriculum_subject_requirements WHERE requirement_type='corequisite'")->fetch_row()[0]===1,'Corequisites preserved by rule edit');
    check(count($service->workspace(1)['relationships'])===2,'Corequisites excluded from prerequisite workspace');
    check($academicBefore===fingerprints($conn,array_slice($tables,0,5)),'All subject, placement, curriculum records restored after authorized placement tests');
    $events=array_column($conn->query('SELECT metadata_json FROM audit_logs')->fetch_all(MYSQLI_ASSOC),'metadata_json');
    foreach(['prerequisite.created','prerequisite.updated','prerequisite.removed'] as $event)check(str_contains(implode(' ',$events),$event),'Audit event '.$event);
    $shs=$create(2,1,['curriculum_version_id'=>2]);check($shs['subject_year_level']==='Grade 11','SHS placement identity');
    $reject(fn()=>$create(2,5,['curriculum_version_id'=>2]),'earlier academic period');
    $reject(fn()=>$create(2,5,['curriculum_version_id'=>2,'confirm_sequencing'=>'1']),'earlier academic period');
    // Simulate a legacy relationship whose placement was made incomplete after creation.
    $conn->query("UPDATE curriculum_version_subjects SET semester=NULL WHERE curriculum_version_id=1 AND subject_id=1");
    check(count($service->workspace(1)['review']['missing'])===1,'Legacy incomplete placement detected');
    $conn->query("UPDATE curriculum_version_subjects SET semester='1st Semester' WHERE curriculum_version_id=1 AND subject_id=1");
    // Audit failure must roll back the relationship too.
    $conn->query('TRUNCATE TABLE audit_logs');
    $conn->query('ALTER TABLE audit_logs ADD CONSTRAINT test_no_audit CHECK (1=0)');
    $count=(int)$conn->query('SELECT COUNT(*) FROM curriculum_subject_requirements')->fetch_row()[0];
    try {$create(5,1);throw new RuntimeException('Audit failure expected');}catch(mysqli_sql_exception $e){check(true,'Audit failure propagated');}
    check($count===(int)$conn->query('SELECT COUNT(*) FROM curriculum_subject_requirements')->fetch_row()[0],'Audit failure rolls back rule');
    $beforeAuditMove=fingerprints($conn,['curriculum_version_subjects']);
    try {$move(5,'6th Year');throw new RuntimeException('Placement audit failure expected');}catch(mysqli_sql_exception $e){check(true,'Placement audit failure propagated');}
    check($beforeAuditMove===fingerprints($conn,['curriculum_version_subjects']),'Audit failure rolls back placement');
} finally {
    foreach(array_reverse($temporary) as $table)$conn->query("DROP TEMPORARY TABLE `$table`");
    check($before===fingerprints($conn,$tables),'Permanent academic and audit tables are byte-for-byte unchanged');
    $conn->close();
}
// Pure malformed-data checks do not need to bypass production constraints.
$p=fn($id,$year='1st Year',$term='1st Semester')=>['subject_id'=>$id,'year_level'=>$year,'semester'=>$term];
$e=fn($t,$r)=>['subject_id'=>$t,'required_subject_id'=>$r];
$r=PrerequisiteGraph::review([$p(1),$p(2),$p(3)],[$e(2,1),$e(3,2),$e(1,3),$e(2,1)]);
check(count($r['cycles'])===1 && $r['cycles'][0]===[1,2,3,1],'Legacy indirect cycle path follows required-to-target direction');
check(count($r['duplicates'])===1,'Duplicate pair counted');
check(count(PrerequisiteGraph::review([$p(1),$p(2)],[$e(1,2),$e(2,1)])['cycles'])===1,'Direct cycle detected');
check(count(PrerequisiteGraph::review([$p(1)],[$e(1,1)])['cycles'])===1,'Legacy self loop detected');
check(count(PrerequisiteGraph::review([$p(1)],[$e(2,1)])['missing'])===1,'Missing mapped endpoint detected');
check(!PrerequisiteGraph::after($p(1,'Grade 11'),$p(2,'Grade 12')),'SHS earlier grade is not warned');
check(!PrerequisiteGraph::after($p(1,'Custom'),$p(2)),'Unknown labels are not assigned invented policy');
echo "PASS: $checks prerequisite checks; connection-local temporary fixtures removed; permanent data unchanged.\n";
