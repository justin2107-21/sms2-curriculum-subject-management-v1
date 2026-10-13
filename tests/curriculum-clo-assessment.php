<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/curriculum-revision-fixture.php';
require_once __DIR__.'/../modules/curriculum/services/CurriculumCloAssessmentService.php';
require_once __DIR__.'/../modules/curriculum/services/CurriculumOutcomeMappingService.php';
require_once __DIR__.'/../modules/curriculum/services/CurriculumVersionSubjectService.php';
require_once __DIR__.'/../modules/curriculum/services/SubjectOfferingHistoryService.php';
require_once __DIR__.'/../modules/curriculum/services/CurriculumExportService.php';
$db=protectionDatabase();$base=protectionFixtures($db);$actor=$base['actor'];$f=revisionFixture($db,$actor,'T8-CLO',true,'BSIT');$checks=0;
function cloCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo 'PASS: '.$label.PHP_EOL;}
function cloReject(callable $fn,string $label):void{try{$fn();}catch(DomainValidationException|DomainConflictException|DomainNotFoundException $e){cloCheck(true,$label);return;}throw new RuntimeException('FAIL: accepted '.$label);}
$placement=(int)$f['placements'][0];
$catalogId=protectionInsert($db,"INSERT INTO curriculum_outcome_catalog(source_key,outcome_code,outcome_text,source_order,document_code,document_title,issuing_authority,source_section,printed_page,pdf_page_index,source_sha256) VALUES ('SYNTHETIC-T8','GO1','Synthetic graduate outcome',1,'Synthetic source','Synthetic title','Synthetic issuer','Synthetic section',1,1,REPEAT('A',64))");
$db->query("INSERT INTO curriculum_version_subject_outcomes(curriculum_version_subject_id,outcome_id,rationale,created_by) VALUES ($placement,$catalogId,'Synthetic parent mapping rationale.',$actor)");
$sourceParent=(int)$db->insert_id;
$db->query("INSERT INTO curriculum_version_course_learning_outcomes(curriculum_version_subject_id,clo_code,clo_statement,sequence_order,created_by) VALUES ($placement,'SRC-CLO1','Synthetic source CLO for clone verification.',1,$actor)");
$sourceClo=(int)$db->insert_id;
$db->query("INSERT INTO curriculum_version_clo_outcome_alignments(clo_id,curriculum_version_subject_id,parent_mapping_id,source_mapping_id_snapshot,outcome_id,rationale,created_by) VALUES ($sourceClo,$placement,$sourceParent,$sourceParent,$catalogId,'Synthetic source alignment.',$actor)");
$sourceAlignment=(int)$db->insert_id;
$db->query("INSERT INTO curriculum_version_clo_assessment_plans(clo_id,curriculum_version_subject_id,assessment_method,planned_task,evidence_description,performance_indicator,created_by) VALUES ($sourceClo,$placement,'Synthetic method','Synthetic task','Synthetic evidence','Synthetic criterion',$actor)");
$sourcePlan=(int)$db->insert_id;
$clone=(new CurriculumRevisionService($db))->create(revisionInput($f,'Task 8 inherited revision'),$actor);$newVersion=(int)$clone['version']['id'];
$placement=(int)$db->query("SELECT id FROM curriculum_version_subjects WHERE curriculum_version_id=$newVersion AND subject_id={$f['subjects'][0]}")->fetch_row()[0];
$parent=(int)$db->query("SELECT id FROM curriculum_version_subject_outcomes WHERE curriculum_version_subject_id=$placement AND outcome_id=$catalogId")->fetch_row()[0];
$copied=$db->query("SELECT * FROM curriculum_version_course_learning_outcomes WHERE curriculum_version_subject_id=$placement")->fetch_assoc();
$copiedAlignment=$db->query("SELECT a.*,m.curriculum_version_subject_id parent_placement FROM curriculum_version_clo_outcome_alignments a JOIN curriculum_version_subject_outcomes m ON m.id=a.parent_mapping_id WHERE a.curriculum_version_subject_id=$placement")->fetch_assoc();
$copiedPlan=$db->query("SELECT * FROM curriculum_version_clo_assessment_plans WHERE curriculum_version_subject_id=$placement")->fetch_assoc();
cloCheck($copied && $copied['origin']==='inherited' && $copied['review_state']==='awaiting_review' && (int)$copied['source_clo_id']===$sourceClo,'clone remaps placement and preserves CLO lineage pending review');
cloCheck($copiedAlignment && $copiedAlignment['origin']==='inherited' && $copiedAlignment['review_state']==='awaiting_review' && (int)$copiedAlignment['source_alignment_id']===$sourceAlignment && (int)$copiedAlignment['parent_placement']===$placement,'clone remaps CLO and graduate mapping relationship within target placement');
cloCheck($copiedPlan && $copiedPlan['origin']==='inherited' && $copiedPlan['review_state']==='awaiting_review' && (int)$copiedPlan['source_plan_id']===$sourcePlan,'clone carries planned assessment with provenance and review state');
$comparison=(new SubjectOfferingHistoryService($db))->compareRevision(['curriculum_id'=>$f['curriculum'],'version_b_id'=>$newVersion]);
cloCheck($comparison['clo_assessment']['complete'] && $comparison['clo_assessment']['changes']===['clos'=>[],'clo_alignments'=>[],'clo_plans'=>[]],'history comparison recognizes unchanged inherited Task 8 records by explicit lineage');
$service=new CurriculumCloAssessmentService($db);
$clo=$service->createClo($placement,'CLO2','User-authored synthetic course learning outcome.',2,$actor);
$alignment=$service->align($placement,$clo,$parent,'Synthetic explicit academic rationale.',$actor);
$plan=$service->createPlan($placement,$clo,['assessment_method'=>'Portfolio review','planned_task'=>'Prepare synthetic portfolio','evidence_description'=>'Portfolio artifact','performance_indicator'=>'Criterion is stated','rubric_or_standard'=>''], $actor);
$workspace=$service->workspace($placement);
cloCheck(count($workspace['clos'])===2 && count($workspace['alignments'])===2 && count($workspace['plans'])===2,'placement workspace returns only owned CLO, alignment, and plan records');
cloCheck($workspace['plans'][0]['plan_status']==='planned' && str_contains($workspace['disclaimer'],'do not establish student attainment'),'complete plan is planned and explicitly not attainment');
cloCheck($workspace['alignments'][0]['outcome_code']==='GO1' && (int)$workspace['alignments'][0]['parent_mapping_id']===$parent,'CLO alignment requires the exact existing same-placement graduate-outcome mapping');
$comparison=(new SubjectOfferingHistoryService($db))->compareRevision(['curriculum_id'=>$f['curriculum'],'version_b_id'=>$newVersion]);
cloCheck($comparison['clo_assessment']['complete'] && count($comparison['clo_assessment']['changes']['clos'])===1 && $comparison['clo_assessment']['changes']['clos'][0]['change']==='added','history comparison reports a manually recorded new CLO as added');
cloReject(fn()=>$service->align($placement,$clo,999999,'Forged mapping.',$actor),'forged or foreign parent mapping rejected');
cloReject(fn()=>$service->align($placement,$clo,$parent,'Duplicate.',$actor),'duplicate active alignment rejected');
cloReject(fn()=>(new CurriculumOutcomeMappingService($db))->remove($newVersion,$parent,$actor),'active CLO alignment prevents parent graduate-outcome mapping removal');
$report=(new CurriculumExportService($db))->dataset(['academic_program_id'=>$f['program'],'curriculum_id'=>$f['curriculum'],'curriculum_version_id'=>$newVersion]);
cloCheck(count($report['course_learning_outcomes'])===2 && $report['course_learning_outcomes'][1]['clo_code']==='CLO2','export dataset includes placement-owned CLO and assessment data');
$csv=fopen('php://temp','w+b');(new CurriculumExportService($db))->writeCsv($csv,$report);rewind($csv);$csvText=stream_get_contents($csv);fclose($csv);
cloCheck(str_contains($csvText,'Course Learning Outcomes and Planned Assessment') && str_contains($csvText,'no student attainment is represented'),'CSV labels CLO data as planning records without attainment claims');
$db->query("UPDATE curriculum_versions SET review_status='under_review' WHERE id=$newVersion");
cloReject(fn()=>$service->createClo($placement,'CLO2','Must not be recorded.',2,$actor),'Task 2 lifecycle policy protects under-review version');
$db->query("UPDATE curriculum_versions SET review_status='not_started' WHERE id=$newVersion");
$lockDb=new mysqli(getenv('DB_HOST'),getenv('DB_USER'),getenv('DB_PASS'),getenv('DB_NAME'),(int)getenv('DB_PORT'));
$observer=new mysqli(getenv('DB_HOST'),getenv('DB_USER'),getenv('DB_PASS'),getenv('DB_NAME'),(int)getenv('DB_PORT'));
$lockDb->begin_transaction();$lockDb->query("SELECT id FROM academic_programs WHERE id={$f['program']} FOR UPDATE");
$raceAuditsBefore=(int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='CURRICULUM_CLO_CREATE'")->fetch_row()[0];
$proc=proc_open([PHP_BINARY,__DIR__.'/curriculum-clo-lock-worker.php',(string)$placement,(string)$actor],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),getenv(),['bypass_shell'=>true]);
if(!is_resource($proc))throw new RuntimeException('Could not start CLO lock worker.');fclose($pipes[0]);$ready=fgets($pipes[1]);
if(!is_string($ready)||!preg_match('/^READY (\d+)/',$ready,$workerMatch))throw new RuntimeException('CLO lock worker did not reach its barrier.');$workerThread=(int)$workerMatch[1];$waiting=false;
for($i=0;$i<500;$i++){$waits=$observer->query('SELECT r.trx_mysql_thread_id FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id')->fetch_all(MYSQLI_NUM);
    if(in_array($workerThread,array_map('intval',array_column($waits,0)),true)){$waiting=true;break;}
    $query=$observer->query('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID='.$workerThread)->fetch_row();
    if($query&&str_contains((string)$query[0],'SELECT id FROM academic_programs WHERE id=? FOR UPDATE')){$waiting=true;break;}usleep(10000);}
if(!$waiting){$lockDb->rollback();proc_terminate($proc);throw new RuntimeException('CLO writer did not wait on the expected version-owner lock.');}
$lockDb->query("UPDATE curriculum_versions SET review_status='under_review' WHERE id=$newVersion");$lockDb->commit();
$workerOut=stream_get_contents($pipes[1]);$workerErr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$workerExit=proc_close($proc);$observer->close();$lockDb->close();
$raceAuditsAfter=(int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='CURRICULUM_CLO_CREATE'")->fetch_row()[0];
cloCheck($workerExit===0&&str_contains($workerOut,'REJECTED')&&$workerErr===''&&$raceAuditsBefore===$raceAuditsAfter&&(int)$db->query("SELECT COUNT(*) FROM curriculum_version_course_learning_outcomes WHERE curriculum_version_subject_id=$placement AND clo_code='RACE-CLO'")->fetch_row()[0]===0,'post-wait current lifecycle read rejects Task 8 CLO write without data or success audit');
$db->query("UPDATE curriculum_versions SET review_status='not_started' WHERE id=$newVersion");
cloReject(fn()=>(new CurriculumCloAssessmentService($db))->createClo((int)$f['placements'][0],'REF','Cannot write reference.',1,$actor),'Task 2 lifecycle policy protects immutable reference');
cloReject(fn()=>(new CurriculumVersionSubjectService($db))->remove($placement),'placement deletion preserves CLO evidence');
$service->withdrawPlan($placement,$clo,$plan,$actor);$service->withdrawAlignment($placement,$alignment,$actor);
$service->withdrawClo($placement,$clo,$actor);
cloCheck($db->query("SELECT record_state FROM curriculum_version_course_learning_outcomes WHERE id=$clo")->fetch_row()[0]==='withdrawn' && $db->query("SELECT parent_mapping_id FROM curriculum_version_clo_outcome_alignments WHERE id=$alignment")->fetch_row()[0]===null && (int)$db->query("SELECT source_mapping_id_snapshot FROM curriculum_version_clo_outcome_alignments WHERE id=$alignment")->fetch_row()[0]===$parent,'withdrawal retains append-history while detaching active parent FK');
cloReject(fn()=>$service->align($placement,$clo,$parent,'Must reject withdrawn CLO.',$actor),'withdrawn CLO cannot receive a new alignment');
$before=(int)$db->query("SELECT COUNT(*) FROM curriculum_version_course_learning_outcomes WHERE curriculum_version_subject_id=$placement AND clo_code='AUDIT-FAIL'")->fetch_row()[0];
$db->query("CREATE TRIGGER task8_clo_audit_fail BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action='CURRICULUM_CLO_CREATE' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit failure'; END IF; END");$auditFailed=false;
try{$service->createClo($placement,'AUDIT-FAIL','Should roll back with audit.',99,$actor);}catch(mysqli_sql_exception $e){$auditFailed=true;}finally{$db->query('DROP TRIGGER task8_clo_audit_fail');}
$after=(int)$db->query("SELECT COUNT(*) FROM curriculum_version_course_learning_outcomes WHERE curriculum_version_subject_id=$placement AND clo_code='AUDIT-FAIL'")->fetch_row()[0];
cloCheck($auditFailed && $before===$after,'required audit failure rolls back CLO creation atomically');
echo json_encode(['passed'=>true,'checks'=>$checks,'suite'=>'Task 8 CLO assessment alignment'],JSON_THROW_ON_ERROR).PHP_EOL;
