<?php
// CLI-only regression. All fixtures shadow permanent tables on this connection.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../shared/database_config.php';
require_once __DIR__.'/../modules/curriculum/services/ElectivesWorkspaceService.php';
$config=csms_database_config();
if (!in_array($config['host'],['localhost','127.0.0.1'],true)||getenv('APP_ENV')==='production') throw new RuntimeException('Local development only.');
$conn=csms_database_connect($config);
$tables=['academic_programs','curricula','curriculum_versions','subjects','curriculum_version_subjects','curriculum_subject_requirements','curriculum_elective_groups','curriculum_elective_group_subjects','audit_logs'];
function fingerprint(mysqli $db,array $tables):array {$out=[];foreach($tables as $t)$out[$t]=hash('sha256',json_encode($db->query("SELECT * FROM `$t` ORDER BY id")->fetch_all(MYSQLI_ASSOC)));return $out;}
$checks=0;
function check(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$before=fingerprint($conn,$tables);$temporary=[];
try {
 foreach($tables as $table){$conn->query("CREATE TEMPORARY TABLE __el_template LIKE `$table`");$conn->query("CREATE TEMPORARY TABLE `$table` LIKE __el_template");$conn->query('DROP TEMPORARY TABLE __el_template');$temporary[]=$table;}
 if(!$conn->query("SHOW COLUMNS FROM curriculum_elective_groups LIKE 'required_selections'")->num_rows){$sql=preg_replace('/^USE sms_db;\s*$/m','',file_get_contents(__DIR__.'/../database/migrations/014_finalize_electives_manager.sql'));$conn->query($sql);}
 $conn->query("INSERT INTO academic_programs(id,program_code,program_name,program_type) VALUES(1,'COL','College','College'),(2,'SHS','SHS','SHS')");
 $conn->query("INSERT INTO curricula(id,curriculum_code,curriculum_name,academic_program_id) VALUES(1,'C','College',1),(2,'S','SHS',2)");
 $conn->query("INSERT INTO curriculum_versions(id,curriculum_id,version_label) VALUES(1,1,'College'),(2,2,'SHS'),(3,1,'Other')");
 foreach(range(1,9) as $id)$conn->query("INSERT INTO subjects(id,subject_code,subject_title,units) VALUES($id,'S$id','Subject $id',3)");
 $conn->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES(1,1,'3rd Year','2nd Semester'),(1,2,'3rd Year','2nd Semester'),(1,3,'4th Year','1st Semester'),(2,4,'Grade 11','1st Semester'),(2,5,'Grade 12','2nd Semester'),(2,6,'Grade 11','1st Semester'),(2,7,'Grade 12','2nd Semester'),(1,8,'3rd Year','2nd Semester'),(1,9,'3rd Year','2nd Semester')");
 $conn->query("INSERT INTO subjects(id,subject_code,subject_title,units) VALUES(10,'S10','Subject 10',3),(11,'S11','Subject 11',3)");
 $conn->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES(1,10,'2nd Year','1st Semester'),(1,11,'1st Year','1st Semester')");
 $conn->query("INSERT INTO curriculum_subject_requirements(curriculum_version_id,subject_id,required_subject_id,requirement_type) VALUES(1,2,1,'prerequisite')");
 $service=new ElectivesWorkspaceService($conn);
 $ctx=['program_id'=>1,'curriculum_id'=>1,'curriculum_version_id'=>1];
 $input=$ctx+['group_name'=>'Professional Elective','year_level'=>'3rd Year','semester'=>'2nd Semester','required_selections'=>1,'subject_ids'=>[1,2],'status'=>'active'];
 $academic=fingerprint($conn,array_slice($tables,0,6));
 $reject=function(callable $fn,string $message)use($conn){$before=fingerprint($conn,['curriculum_elective_groups','curriculum_elective_group_subjects','audit_logs']);try{$fn();throw new RuntimeException('Expected rejection: '.$message);}catch(DomainValidationException|DomainConflictException|DomainNotFoundException $e){check(str_contains($e->getMessage(),$message),'Expected message: '.$message.'; got '.$e->getMessage());}check($before===fingerprint($conn,['curriculum_elective_groups','curriculum_elective_group_subjects','audit_logs']),'Rejected operation is atomic');};
 check(count($service->workspace($ctx)['placements'])===7,'Candidates scoped to selected version');
 check(count($service->workspace($ctx)['prerequisites'])===1,'Read-only prerequisite integration');
 check($service->workspace($ctx)['years']===['1st Year','2nd Year','3rd Year','4th Year'],'Year choices follow natural stored order');
 check($service->workspace($ctx)['terms']===['1st Semester','2nd Semester'],'Terms follow natural stored order');
 check($service->workspace($ctx)['terms_by_year']['3rd Year']===['2nd Semester'],'Term options narrow to selected year');
 check(count($service->contextOptions(['level'=>'curriculum','program_id'=>2])['options'])===1,'Curriculum scoped to program');
 check($service->contextOptions(['level'=>'version','program_id'=>2,'curriculum_id'=>1])['options']===[],'Unrelated versions not exposed');
 $reject(fn()=>$service->workspace(array_replace($ctx,['program_id'=>2])),'belonging');
 $reject(fn()=>$service->save(array_replace($input,['curriculum_version_id'=>0]),1),'positive');
 $reject(fn()=>$service->save(array_replace($input,['subject_ids'=>[1,4]]),1),'no valid placement');
 $reject(fn()=>$service->save(array_replace($input,['subject_ids'=>[1,1]]),1),'Duplicate');
 foreach([0,-1,'abc',1.5] as $bad)$reject(fn()=>$service->save(array_replace($input,['required_selections'=>$bad]),1),'positive');
 $reject(fn()=>$service->save(array_replace($input,['required_selections'=>3]),1),'cannot exceed');
 $reject(fn()=>$service->save(array_replace($input,['subject_ids'=>[]]),1),'at least two');
 $reject(fn()=>$service->save(array_replace($input,['subject_ids'=>[1]]),1),'at least two');
 $reject(fn()=>$service->save(array_replace($input,['subject_ids'=>[1,3]]),1),'Placement mismatch');
 $id=$service->save($input,1)['id'];
 $service->save(array_replace($input,['subject_ids'=>[1,2,8]]),1,$id);
 check(count($service->workspace($ctx)['groups'][0]['members'])===3 && array_sum($service->workspace($ctx)['review']['counts'])===0,'Choose one of three compatible subjects is valid');
 $service->save($input,1,$id);
 $service->save(array_replace($input,['required_selections'=>2,'subject_ids'=>[1,2,8,9]]),1,$id);
 check(count($service->workspace($ctx)['groups'][0]['members'])===4,'Choose two of four is valid');
 $service->save(array_replace($input,['required_selections'=>4,'subject_ids'=>[1,2,8,9]]),1,$id);
 check(count($service->workspace($ctx)['groups'][0]['members'])===4,'Choose all four is allowed with UI warning');
 $reject(fn()=>$service->save(array_replace($input,['required_selections'=>5,'subject_ids'=>[1,2,8,9]]),1,$id),'cannot exceed');
 $service->save($input,1,$id);
 check(count($service->workspace($ctx)['groups'][0]['members'])===2,'Atomic College creation');
 check(array_sum($service->workspace($ctx)['review']['counts'])===0,'Valid group success state');
 $reject(fn()=>$service->save($input,1),'already exists');
 try{$conn->query("INSERT INTO curriculum_elective_group_subjects(elective_group_id,curriculum_version_id,subject_id) VALUES($id,1,1)");throw new RuntimeException('Unique constraint missing');}catch(mysqli_sql_exception $e){check($e->getCode()===1062,'DB uniqueness enforced');}
 $service->save(array_replace($input,['group_name'=>'Updated','subject_ids'=>[1,2]]),1,$id);
 check(count($service->workspace($ctx)['groups'][0]['members'])===2,'Edit removes membership atomically');
 $reject(fn()=>$service->save(array_replace($input,['curriculum_version_id'=>3]),1,$id),'not found');
 $service->archive($ctx+['id'=>$id]);
 $g=$service->workspace($ctx)['groups'][0];check($g['status']==='archived'&&count($g['members'])===2,'Archive preserves records and members');
 foreach([4=>'Grade 11',5=>'Grade 12']as $subject=>$year){$service->save(['program_id'=>2,'curriculum_id'=>2,'curriculum_version_id'=>2,'group_name'=>$year,'year_level'=>$year,'semester'=>$subject===4?'1st Semester':'2nd Semester','required_selections'=>1,'subject_ids'=>[$subject,$subject+2]],1);}
 check(count($service->workspace(['program_id'=>2,'curriculum_id'=>2,'curriculum_version_id'=>2])['groups'])===2,'SHS Grade 11 and 12 work');
 check($academic===fingerprint($conn,array_slice($tables,0,6)),'Electives mutations do not modify subject identity, mapping, or prerequisites');
 $conn->query("UPDATE curriculum_elective_groups SET status='active' WHERE id=$id");
 $conn->query("UPDATE subjects SET status='inactive' WHERE id=1");
 check($service->workspace($ctx)['review']['counts']['inactive']===1,'Inactive members detected');
 $reject(fn()=>$service->save($input,1,$id),'inactive');
 $conn->query("UPDATE curriculum_version_subjects SET year_level='4th Year' WHERE curriculum_version_id=1 AND subject_id=1");
 check($service->workspace($ctx)['review']['counts']['mismatch']===1,'Changed placement mismatch detected');
 $conn->query('DELETE FROM curriculum_version_subjects WHERE curriculum_version_id=1 AND subject_id=1');
 check($service->workspace($ctx)['review']['counts']['unmapped']===1,'Only referenced unmapped member counted');
 $conn->query("DELETE FROM curriculum_elective_group_subjects WHERE elective_group_id=$id");
 $r=$service->workspace($ctx)['review'];check($r['counts']['empty']===1&&$r['counts']['selection']===1&&$r['counts']['unmapped']===0,'Empty group, invalid rule, and unmapped definition');
 foreach(['ELECTIVE_GROUP_CREATE','ELECTIVE_GROUP_UPDATE','ELECTIVE_GROUP_ARCHIVE','ELECTIVE_GROUP_SUBJECT_ADD','ELECTIVE_GROUP_SUBJECT_REMOVE']as $action)check((int)$conn->query("SELECT COUNT(*) FROM audit_logs WHERE action='$action'")->fetch_row()[0]>0,'Audit '.$action);
 $conn->query('DELETE FROM audit_logs');
 $conn->query("ALTER TABLE audit_logs ADD CONSTRAINT audit_fail CHECK(action='IMPOSSIBLE')");
 $beforeFailure=fingerprint($conn,['curriculum_elective_groups','curriculum_elective_group_subjects']);
 try {$service->save(['program_id'=>2,'curriculum_id'=>2,'curriculum_version_id'=>2,'group_name'=>'Audit rollback','year_level'=>'Grade 11','semester'=>'1st Semester','required_selections'=>1,'subject_ids'=>[4,6]],1);throw new RuntimeException('Audit failure expected');}
 catch(mysqli_sql_exception $e){check($e->getCode()===4025,'Audit failure propagated');}
 check($beforeFailure===fingerprint($conn,['curriculum_elective_groups','curriculum_elective_group_subjects']),'Audit failure rolls back group and membership together');
} finally {
 foreach(array_reverse($temporary)as $table)$conn->query("DROP TEMPORARY TABLE `$table`");
 check($before===fingerprint($conn,$tables),'Permanent tables unchanged');$conn->close();
}
$g=['id'=>1,'group_name'=>'Legacy','year_level'=>'3rd Year','semester'=>'2nd Semester','required_selections'=>1,'status'=>'active','members'=>[['subject_id'=>1,'subject_code'=>'S1','subject_status'=>'active','placement_id'=>1,'year_level'=>'3rd Year','semester'=>'2nd Semester'],['subject_id'=>1,'subject_code'=>'S1','subject_status'=>'active','placement_id'=>1,'year_level'=>'3rd Year','semester'=>'2nd Semester']]];
check(ElectivesWorkspaceService::review([$g])['counts']['duplicate']===1,'Legacy duplicate review');
$g['year_level']=null;
check(ElectivesWorkspaceService::review([$g])['counts']['context']===1,'Missing group placement detected');
$g['year_level']='3rd Year';$g['status']='archived';$g['members'][0]['subject_status']='inactive';
check(ElectivesWorkspaceService::review([$g])['counts']['inactive']===0,'Archived groups are excluded from active-subject warning');
echo "PASS: $checks electives service checks; permanent data unchanged.\n";
