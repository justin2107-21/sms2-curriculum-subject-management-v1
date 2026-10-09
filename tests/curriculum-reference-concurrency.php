<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (($argv[1] ?? '') === 'worker') {
    require_once __DIR__.'/../shared/db.php';
    require_once __DIR__.'/../modules/curriculum/services/CurriculumVersionReferenceService.php';
    [, , $mode,$version,$source,$actor,$resultFile] = $argv;
    $conn->query('SET SESSION innodb_lock_wait_timeout=12');
    echo 'READY '.$conn->thread_id.PHP_EOL; flush();
    try {
        $service = new CurriculumVersionReferenceService($conn);
        if ($mode === 'classify') $service->classifyReference((int)$version,(int)$source,'ched_sample',true,(int)$actor);
        elseif ($mode === 'attach') $service->attach(['curriculum_version_id'=>(int)$version,'compliance_reference_id'=>(int)$source,'reference_purpose'=>'school_curriculum'],(int)$actor);
        file_put_contents($resultFile,'SUCCESS');
        exit(0);
    } catch (DomainConflictException $e) { file_put_contents($resultFile,'CONFLICT '.$e->getMessage()); exit(0); }
      catch (Throwable $e) { file_put_contents($resultFile,'ERROR '.get_class($e).' '.$e->getMessage()); exit(2); }
}
require_once __DIR__ . '/version-protection-fixture.php';
require_once __DIR__ . '/../modules/curriculum/services/CurriculumVersionReferenceService.php';
require_once __DIR__ . '/../modules/curriculum/services/ComplianceReferenceService.php';
$db=protectionDatabase();$actor=protectionFixtures($db)['actor'];$checks=0;
function task3ConcurrencyCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;}
function task3ConcurrencyVersion(mysqli $db,string $suffix):array{
    $p=protectionInsert($db,"INSERT INTO academic_programs(program_code,program_name) VALUES ('T3C-$suffix','Concurrency test')");
    $c=protectionInsert($db,"INSERT INTO curricula(curriculum_code,curriculum_name,academic_program_id) VALUES ('T3C-$suffix','Concurrency curriculum',$p)");
    $v=protectionInsert($db,"INSERT INTO curriculum_versions(curriculum_id,version_label) VALUES ($c,'Concurrency version')");
    $s=protectionInsert($db,"INSERT INTO subjects(subject_code,subject_title,units) VALUES ('T3C-$suffix-S','Original subject',3)");
    $placement=protectionInsert($db,"INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES ($v,$s,'1st Year','1st Semester')");
    return ['program'=>$p,'curriculum'=>$c,'version'=>$v,'subject'=>$s,'placement'=>$placement];
}
function task3ConcurrencySource(ComplianceReferenceService $service,int $actor,string $code):array{
    $source=$service->create(['reference_type'=>'CHED','source_kind'=>'ched_sample_curriculum','reference_code'=>$code,'title'=>'Concurrency sample','issuing_body'=>'CHED','source_url'=>'https://example.invalid/sample','verification_status'=>'draft'],$actor);
    $service->recordEvidence((int)$source['id'],['verification_subject'=>'document_identity','result'=>'not_assessed','rationale'=>'Concurrency fixture only.'],$actor);
    return $source;
}
function task3Spawn(string $mode,int $version,int $source,int $actor):array{
    $file=tempnam(sys_get_temp_dir(),'t3-worker-');unlink($file);
    $proc=proc_open([PHP_BINARY,__FILE__,'worker',$mode,(string)$version,(string)$source,(string)$actor,$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,getenv(),['bypass_shell'=>true,'create_new_console'=>false]);
    if(!is_resource($proc))throw new RuntimeException('Could not start concurrency worker');fclose($pipes[0]);
    $ready=fgets($pipes[1]);if(!is_string($ready)||!preg_match('/^READY (\d+)/',trim($ready),$match))throw new RuntimeException('Concurrency worker did not establish its database connection');
    return ['proc'=>$proc,'pipes'=>$pipes,'file'=>$file,'thread_id'=>(int)$match[1]];
}
function task3AwaitLockQuery(mysqli $observer,array $workers,array $fragments):void{
    for($i=0;$i<500;$i++){
        $observed=[];
        foreach($workers as $index=>$worker){
            $stmt=$observer->prepare('SELECT COMMAND,STATE,INFO,TIME FROM information_schema.PROCESSLIST WHERE ID=?');
            $thread=$worker['thread_id'];$stmt->bind_param('i',$thread);$stmt->execute();$process=$stmt->get_result()->fetch_assoc();$stmt->close();
            if($process && in_array($process['COMMAND'],['Query','Execute'],true) && (int)$process['TIME']>=1
                && str_contains((string)$process['INFO'],$fragments[$index]))$observed[]=$index;
        }
        if(count($observed)===count($workers))return;
        $alive=0;foreach($workers as $worker)if(proc_get_status($worker['proc'])['running'])$alive++;
        if(!$alive)throw new RuntimeException('Concurrency workers exited before lock waits were observed');
        usleep(10000);
    }
    $threads=implode(',',array_map(fn($worker)=>(string)$worker['thread_id'],$workers));
    $processes=$observer->query("SELECT ID,COMMAND,STATE,INFO FROM information_schema.PROCESSLIST WHERE ID IN ($threads)")->fetch_all(MYSQLI_ASSOC);
    throw new RuntimeException('Expected InnoDB lock waits were not observed: '.json_encode($processes));
}
function task3Finish(array $worker):string{
    $out=stream_get_contents($worker['pipes'][1]);$err=stream_get_contents($worker['pipes'][2]);fclose($worker['pipes'][1]);fclose($worker['pipes'][2]);
    $status=proc_close($worker['proc']);$result=file_exists($worker['file'])?file_get_contents($worker['file']):'';unlink($worker['file']);
    if($status!==0)throw new RuntimeException("Concurrency worker failed: $result $out $err");return $result;
}
$referenceService=new CurriculumVersionReferenceService($db);$sourceService=new ComplianceReferenceService($db);
$observer=csms_database_connect(csms_database_config());

// Two authorized classification attempts contend on the same source and owner chain.
$f=task3ConcurrencyVersion($db,'DUAL');$source=task3ConcurrencySource($sourceService,$actor,'T3C-DUAL');
$referenceService->attach(['curriculum_version_id'=>$f['version'],'compliance_reference_id'=>$source['id'],'reference_purpose'=>'school_curriculum'],$actor);
$db->begin_transaction();$db->query("SELECT id FROM academic_programs WHERE id={$f['program']} FOR UPDATE");
$workerA=task3Spawn('classify',$f['version'],(int)$source['id'],$actor);
$workerB=task3Spawn('classify',$f['version'],(int)$source['id'],$actor);
try{task3AwaitLockQuery($observer,[$workerA,$workerB],['academic_programs WHERE id=? FOR UPDATE','SELECT * FROM compliance_references']);}finally{$db->commit();}
$results=[task3Finish($workerA),task3Finish($workerB)];sort($results);
task3ConcurrencyCheck(count(array_filter($results,fn($r)=>$r==='SUCCESS'))===1 && count(array_filter($results,fn($r)=>str_starts_with($r,'CONFLICT')))===1,'concurrent classification produces one reference and one protected-state conflict');
task3ConcurrencyCheck($db->query("SELECT version_role FROM curriculum_versions WHERE id={$f['version']}")->fetch_row()[0]==='reference' && (int)$db->query("SELECT COUNT(*) FROM curriculum_version_subject_snapshots s JOIN curriculum_version_subjects p ON p.id=s.curriculum_version_subject_id WHERE p.curriculum_version_id={$f['version']}")->fetch_row()[0]===1,'concurrent classification preserves exactly one complete capture');

// A source made inactive while attachment waits must be rechecked as a current read.
$g=task3ConcurrencyVersion($db,'INACTIVE');$inactive=task3ConcurrencySource($sourceService,$actor,'T3C-INACTIVE');
$db->begin_transaction();$db->query("SELECT id FROM compliance_references WHERE id={$inactive['id']} FOR UPDATE");
$db->query("UPDATE compliance_references SET verification_status='inactive' WHERE id={$inactive['id']}");
$attachWorker=task3Spawn('attach',$g['version'],(int)$inactive['id'],$actor);
try{task3AwaitLockQuery($observer,[$attachWorker],['SELECT * FROM compliance_references']);}finally{$db->commit();}
$attachResult=task3Finish($attachWorker);
task3ConcurrencyCheck(str_starts_with($attachResult,'CONFLICT') && (int)$db->query("SELECT COUNT(*) FROM curriculum_version_references WHERE curriculum_version_id={$g['version']}")->fetch_row()[0]===0,'attachment waiting on a source update rejects newly inactive source');

// A Subject Master update commits while classification waits; its locking read captures the committed value.
$h=task3ConcurrencyVersion($db,'SUBJECT');$subjectSource=task3ConcurrencySource($sourceService,$actor,'T3C-SUBJECT');
$referenceService->attach(['curriculum_version_id'=>$h['version'],'compliance_reference_id'=>$subjectSource['id'],'reference_purpose'=>'school_curriculum'],$actor);
$db->begin_transaction();$db->query("SELECT id FROM subjects WHERE id={$h['subject']} FOR UPDATE");
$db->query("UPDATE subjects SET subject_title='Committed while classification waits' WHERE id={$h['subject']}");
$classifyWorker=task3Spawn('classify',$h['version'],(int)$subjectSource['id'],$actor);
try{task3AwaitLockQuery($observer,[$classifyWorker],['subjects WHERE id=? FOR UPDATE']);}finally{$db->commit();}
$classifyResult=task3Finish($classifyWorker);
$captured=$db->query("SELECT s.subject_title_snapshot FROM curriculum_version_subject_snapshots s WHERE s.curriculum_version_subject_id={$h['placement']}")->fetch_row();
task3ConcurrencyCheck($classifyResult==='SUCCESS' && $captured[0]==='Committed while classification waits','classification captures post-wait current Subject Master values');

echo json_encode(['passed'=>true,'checks'=>$checks,'suite'=>'Task 3 deterministic InnoDB concurrency'],JSON_THROW_ON_ERROR).PHP_EOL;
$observer->close();
