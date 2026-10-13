<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/curriculum-revision-fixture.php';
require_once __DIR__.'/../modules/curriculum/services/CurriculumOutcomeMappingService.php';
require_once __DIR__.'/../modules/curriculum/services/CurriculumVersionSubjectService.php';
require_once __DIR__.'/../modules/curriculum/services/CurriculumExportService.php';
$db=protectionDatabase();$base=protectionFixtures($db);$actor=$base['actor'];$checks=0;
function outcomeCheck(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$message);$checks++;echo 'PASS: '.$message.PHP_EOL;}
function outcomeReject(callable $fn,string $message):void{try{$fn();}catch(DomainValidationException|DomainConflictException|DomainNotFoundException $e){outcomeCheck(true,$message);return;}throw new RuntimeException('FAIL: accepted '.$message);}
$run=static function():array{$p=proc_open([PHP_BINARY,__DIR__.'/../database/seeds/load_ched_bsit_outcomes.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),getenv(),['bypass_shell'=>true]);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];};
[$exit,$out,$err]=$run();outcomeCheck($exit===0 && str_contains($out,'13 records'),'source-guarded catalog loader');outcomeCheck($run()[0]===0,'catalog loading is idempotent');
$source=json_decode((string)file_get_contents(__DIR__.'/../docs/source-data/ched-cmo25-2015-bsit.json'),true,512,JSON_THROW_ON_ERROR);
$rows=$db->query("SELECT id,outcome_code,outcome_text,source_order,source_sha256 FROM curriculum_outcome_catalog WHERE source_key='CHED-CMO-25-2015-BSIT' ORDER BY source_order")->fetch_all(MYSQLI_ASSOC);
outcomeCheck(count($rows)===13 && array_column($rows,'outcome_code')===array_column($source['intended_program_outcomes']['records'],'id'),'exact ordered IT01–IT13 catalog');
outcomeCheck(array_column($rows,'outcome_text')===array_column($source['intended_program_outcomes']['records'],'text') && count(array_unique(array_column($rows,'source_sha256')))===1,'exact source text and fingerprint');
$originalText=$rows[0]['outcome_text'];$db->query("UPDATE curriculum_outcome_catalog SET outcome_text='Synthetic conflicting alteration' WHERE id=".(int)$rows[0]['id']);
outcomeCheck($run()[0]!==0 && $db->query("SELECT outcome_text FROM curriculum_outcome_catalog WHERE id=".(int)$rows[0]['id'])->fetch_row()[0]==='Synthetic conflicting alteration','loader rejects conflicting catalog data without overwriting it');
$stmt=$db->prepare('UPDATE curriculum_outcome_catalog SET outcome_text=? WHERE id=?');$catalogId=(int)$rows[0]['id'];$stmt->bind_param('si',$originalText,$catalogId);$stmt->execute();$stmt->close();
$f=revisionFixture($db,$actor,'T7-CLONE',true,'BSIT');$first=(int)$rows[0]['id'];$second=(int)$rows[1]['id'];$third=(int)$rows[2]['id'];$p=(int)$f['placements'][0];
$db->query("INSERT INTO curriculum_version_subject_outcomes (curriculum_version_subject_id,outcome_id,rationale,created_by) VALUES ($p,$first,'Synthetic clone fixture mapping.',$actor)");$sourceMapping=(int)$db->insert_id;
$db->query("INSERT INTO curriculum_version_outcome_coverage (curriculum_version_id,outcome_id,assessment_state,recorded_by) VALUES ({$f['version']},$first,'complete',$actor)");
$revision=new CurriculumRevisionService($db);$clone=$revision->create(revisionInput($f,'Task 7 clone'),$actor);$v=(int)$clone['version']['id'];
$copy=$db->query("SELECT m.* FROM curriculum_version_subject_outcomes m JOIN curriculum_version_subjects p ON p.id=m.curriculum_version_subject_id WHERE p.curriculum_version_id=$v")->fetch_assoc();
outcomeCheck($copy && $copy['mapping_origin']==='inherited' && (int)$copy['inherited_from_mapping_id']===$sourceMapping && $copy['rationale']==='Synthetic clone fixture mapping.','clone preserves mapping origin/rationale on new placement');
outcomeCheck((int)$db->query("SELECT COUNT(*) FROM curriculum_version_outcome_coverage WHERE curriculum_version_id=$v")->fetch_row()[0]===0,'clone leaves coverage not assessed');
$placements=$db->query("SELECT id,subject_id FROM curriculum_version_subjects WHERE curriculum_version_id=$v ORDER BY id")->fetch_all(MYSQLI_ASSOC);$one=(int)$placements[0]['id'];$shared=(int)$placements[0]['subject_id'];
$otherSubject=protectionInsert($db,"INSERT INTO subjects(subject_code,subject_title,units) VALUES ('T7-UNRELATED','Synthetic unrelated subject',3)");$other=protectionInsert($db,"INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester,sequence_order,is_required) VALUES ($v,$otherSubject,'4th Year','2nd Semester',99,1)");
$clone2=$revision->create(revisionInput($f,'Task 7 clone two'),$actor);$v2=(int)$clone2['version']['id'];$two=(int)$db->query("SELECT id FROM curriculum_version_subjects WHERE curriculum_version_id=$v2 AND subject_id=$shared")->fetch_row()[0];
$service=new CurriculumOutcomeMappingService($db);$m1=$service->add($v,$one,$second,'Synthetic first mapping rationale.',$actor);$m2=$service->add($v2,$two,$second,'Synthetic second mapping rationale.',$actor);
outcomeCheck($m1!==$m2,'shared subject has independent version-placement mappings');
$status=static fn(int $version,int $id)=>array_values(array_filter((new CurriculumOutcomeMappingService($GLOBALS['db']))->workspace($version)['outcomes'],static fn($r)=>(int)$r['id']===$id))[0]['coverage_status'];
outcomeCheck($status($v,$second)==='not_assessed','recorded mappings do not imply assessment');$service->assess($v,$second,'complete','Synthetic review.',$actor);
outcomeCheck($status($v,$second)==='mapped','complete review with links is Mapped');$m3=$service->add($v,$other,$second,'Second synthetic link.',$actor);
outcomeCheck($status($v,$second)==='incomplete','mapping edit invalidates a completed assessment');$service->remove($v,$m1,$actor);$service->remove($v,$m3,$actor);$service->assess($v,$second,'complete',null,$actor);
outcomeCheck($status($v,$second)==='unmapped','complete review without links is Unmapped');$service->assess($v,$third,'incomplete','Synthetic review.',$actor);
outcomeCheck($status($v,$third)==='incomplete','incomplete assessment stays distinct');
outcomeReject(fn()=>$service->add($v,$two,$third,'Forged placement.',$actor),'cross-version placement rejected');outcomeReject(fn()=>$service->add($f['version'],$p,$third,'Reference edit.',$actor),'reference remains immutable');
$service->add($v,$other,$third,'Protect placement.',$actor);outcomeReject(fn()=>(new CurriculumVersionSubjectService($db))->remove($other),'placement deletion preserves mapping evidence');
$db->query("CREATE TRIGGER task7_outcome_audit_fail BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action='CURRICULUM_OUTCOME_MAPPING_CREATE' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit failure'; END IF; END");$before=(int)$db->query("SELECT COUNT(*) FROM curriculum_version_subject_outcomes WHERE curriculum_version_subject_id=$other AND outcome_id=$first")->fetch_row()[0];$failed=false;
try{$service->add($v,$other,$first,'Audit rollback case.',$actor);}catch(mysqli_sql_exception $e){$failed=true;}finally{$db->query('DROP TRIGGER task7_outcome_audit_fail');}
$after=(int)$db->query("SELECT COUNT(*) FROM curriculum_version_subject_outcomes WHERE curriculum_version_subject_id=$other AND outcome_id=$first")->fetch_row()[0];outcomeCheck($failed && $before===$after,'required audit failure rolls back mapping insert');
$report=(new CurriculumExportService($db))->dataset(['academic_program_id'=>$f['program'],'curriculum_id'=>$f['curriculum'],'curriculum_version_id'=>$v]);
outcomeCheck(count($report['graduate_outcomes'])===13 && $report['graduate_outcomes'][0]['outcome_code']==='IT01' && count($report['graduate_outcomes'][0]['mappings'])===1,'existing export dataset includes source-traceable version outcome mappings');
$csv=fopen('php://temp','w+b');(new CurriculumExportService($db))->writeCsv($csv,$report);rewind($csv);$csvText=stream_get_contents($csv);fclose($csv);
outcomeCheck(str_contains($csvText,'Curriculum mapping does not establish student outcome attainment') && str_contains($csvText,'CHED Memorandum Order No. 25, series of 2015') && str_contains($csvText,'AEB87088118D4341B8F8A173952E77B07AEA3ACD87D140770D73B4B2309FB6CF'),'CSV includes document identity, fingerprint and academic limitation disclaimer');
// Hold the owner lock while a second connection waits, then protect the version before release.
$workerPlacement=(int)$db->query("SELECT id FROM curriculum_version_subjects WHERE curriculum_version_id=$v2 ORDER BY id LIMIT 1")->fetch_row()[0];
$lockDb=new mysqli(getenv('DB_HOST'),getenv('DB_USER'),getenv('DB_PASS'),getenv('DB_NAME'),(int)getenv('DB_PORT'));$lockDb->begin_transaction();$lockDb->query("SELECT id FROM academic_programs WHERE id={$f['program']} FOR UPDATE");
$observer=new mysqli(getenv('DB_HOST'),getenv('DB_USER'),getenv('DB_PASS'),getenv('DB_NAME'),(int)getenv('DB_PORT'));
$proc=proc_open([PHP_BINARY,__DIR__.'/curriculum-outcome-lock-worker.php',(string)$v2,(string)$workerPlacement,(string)$third,(string)$actor],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),getenv(),['bypass_shell'=>true]);fclose($pipes[0]);$ready=fgets($pipes[1]);if(!is_string($ready)||!preg_match('/^READY (\d+)/',$ready,$threadMatch))throw new RuntimeException('Concurrency worker failed before its barrier.');$workerThread=(int)$threadMatch[1];
$locked=false;for($i=0;$i<500;$i++){$waits=$observer->query('SELECT r.trx_mysql_thread_id FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id')->fetch_all(MYSQLI_NUM);if(in_array($workerThread,array_map('intval',array_column($waits,0)),true)){$locked=true;break;}
    $held=$observer->query('SELECT trx_rows_locked FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id='.(int)$lockDb->thread_id)->fetch_row();$pending=$observer->query('SELECT COMMAND,STATE,INFO,TIME FROM information_schema.PROCESSLIST WHERE ID='.$workerThread)->fetch_assoc();
    if($held && (int)$held[0]>0 && $pending && in_array($pending['COMMAND'],['Query','Execute'],true) && (int)$pending['TIME']>=1 && str_contains((string)$pending['INFO'],'SELECT id FROM academic_programs WHERE id=? FOR UPDATE')){$locked=true;break;}usleep(10000);}
if(!$locked){$lockDb->rollback();proc_terminate($proc);$failedOut=stream_get_contents($pipes[1]);$failedErr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($proc);$observer->close();throw new RuntimeException('Second connection did not reach the bounded exact-query lock barrier: '.$failedOut.' '.$failedErr);}
$lockDb->query("UPDATE curriculum_versions SET review_status='under_review' WHERE id=$v2");$lockDb->commit();$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);$observer->close();$lockDb->close();
outcomeCheck($exit===0 && str_contains($stdout,'REJECTED') && $stderr==='','post-wait current lifecycle read rejects protected-version mapping');
echo json_encode(['passed'=>true,'checks'=>$checks,'suite'=>'Task 7 outcome mapping'],JSON_THROW_ON_ERROR).PHP_EOL;
