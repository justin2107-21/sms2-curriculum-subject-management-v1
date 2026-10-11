<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/curriculum-revision-fixture.php';
if (($argv[1]??'')==='worker') {
    require_once __DIR__.'/../shared/db.php';
    $name=$conn->query('SELECT DATABASE()')->fetch_row()[0];
    if (!preg_match('/^csms_docker_test_task2_[a-z0-9_]+$/D',$name) || getenv('SMS_TEST_CONFIRM_DATABASE')!==$name || getenv('DB_HOST')!=='127.0.0.1' || (int)getenv('DB_PORT')===3306) throw new RuntimeException('Unsafe worker database.');
    [,,$mode,$encoded,$label,$actor,$file]=$argv;$f=json_decode(base64_decode($encoded),true);
    $conn->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $conn->query('SET SESSION innodb_lock_wait_timeout=12');
    echo 'READY '.$conn->thread_id.PHP_EOL;flush();
    try {
        if ($mode==='clone') {
            $result=(new CurriculumRevisionService($conn))->create(revisionInput($f,$label),(int)$actor);
            file_put_contents($file,'SUCCESS '.(int)$result['version']['id']);
        } else {
            (new ComplianceReferenceService($conn))->update($f['sourceId'],['reference_type'=>'CHED','source_kind'=>'ched_sample_curriculum','reference_code'=>'SYNTHETIC-'.$label,
                'title'=>'Changed through guarded source service','issuing_body'=>'Synthetic test issuer','verification_status'=>'draft']);
            file_put_contents($file,'SUCCESS');
        }
    } catch (DomainConflictException $e) {file_put_contents($file,'CONFLICT '.$e->getMessage());}
      catch (mysqli_sql_exception $e) {file_put_contents($file,'SQLERROR '.$e->getCode());}
      catch (Throwable $e) {file_put_contents($file,'ERROR '.get_class($e).' '.$e->getMessage());}
    exit;
}
$db=protectionDatabase();$actor=protectionFixtures($db)['actor'];$observer=csms_database_connect(csms_database_config());$checks=0;
function revisionConcurrencyCheck(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo 'PASS: '.$label.PHP_EOL;}
function revisionSpawn(array $f,string $label,int $actor,string $mode='clone'):array {
    $file=tempnam(sys_get_temp_dir(),'t4-worker-');unlink($file);
    $proc=proc_open([PHP_BINARY,__FILE__,'worker',$mode,base64_encode(json_encode($f)),$label,(string)$actor,$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,getenv(),['bypass_shell'=>true,'create_new_console'=>false]);
    if(!is_resource($proc))throw new RuntimeException('Worker launch failed');fclose($pipes[0]);
    $line=fgets($pipes[1]);if(!is_string($line)||!preg_match('/^READY (\d+)/',$line,$m))throw new RuntimeException('Worker failed before ready');
    return ['proc'=>$proc,'pipes'=>$pipes,'file'=>$file,'thread'=>(int)$m[1]];
}
function revisionWait(mysqli $observer,array $workers,string $lockedQuery):void {
    for($i=0;$i<500;$i++) {
        $waiting=[];
        foreach($observer->query('SELECT r.trx_mysql_thread_id AS thread_id FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id')->fetch_all(MYSQLI_ASSOC) as $row) $waiting[]=(int)$row['thread_id'];
        if(!array_diff(array_column($workers,'thread'),$waiting)) {echo 'OBSERVED INNODB_LOCK_WAITS '.implode(',',array_column($workers,'thread')).PHP_EOL;return;}
        // MariaDB 10.4 sometimes waits in index-statistics code without exporting
        // that wait through INNODB_LOCK_WAITS. Keep the same retained row blocker,
        // exact pending-query barrier, and after-release outcome assertions.
        // Never treat elapsed time alone or a completed worker as interleaving proof.
        global $db;
        $blocker=$db->thread_id;
        $held=$observer->query("SELECT trx_rows_locked FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=$blocker")->fetch_row();
        $observed=[];
        foreach($workers as $worker) {
            $thread=$worker['thread'];
            $p=$observer->query("SELECT COMMAND,STATE,INFO,TIME FROM information_schema.PROCESSLIST WHERE ID=$thread")->fetch_assoc();
            if($held && (int)$held[0]>0 && $p && in_array($p['COMMAND'],['Query','Execute'],true)
                && (int)$p['TIME']>=1 && str_contains((string)$p['INFO'],$lockedQuery) && !is_file($worker['file'])) $observed[]=$thread;
        }
        if(count($observed)===count($workers)) {
            echo 'OBSERVED retained-row blocker + pending exact query '.implode(',',$observed).' (engine wait view not exported)'.PHP_EOL;return;
        }
        usleep(10000);
    }
    $threads=implode(',',array_column($workers,'thread'));
    $processes=$observer->query("SELECT ID,COMMAND,STATE,INFO FROM information_schema.PROCESSLIST WHERE ID IN ($threads)")->fetch_all(MYSQLI_ASSOC);
    $results=[];foreach($workers as $w)$results[]=is_file($w['file'])?file_get_contents($w['file']):'pending';
    throw new RuntimeException('Required InnoDB lock waits were not observed: '.json_encode([$processes,$results]));
}
function revisionFinish(array $worker):string {
    $out=stream_get_contents($worker['pipes'][1]);$err=stream_get_contents($worker['pipes'][2]);fclose($worker['pipes'][1]);fclose($worker['pipes'][2]);
    $exit=proc_close($worker['proc']);$result=is_file($worker['file'])?file_get_contents($worker['file']):'';if(is_file($worker['file']))unlink($worker['file']);
    if($exit!==0||$result===''||str_starts_with($result,'ERROR'))throw new RuntimeException('Worker failed '.$result.' '.$out.' '.$err);
    return $result;
}
function revisionWaitSourceQuery(mysqli $observer,array $worker,bool $clone=false):void {
    // MariaDB can wait during index statistics before registering an InnoDB trx.
    // Observe that exact guarded query separately; do not call it an engine wait.
    $thread=$worker['thread'];
    for($i=0;$i<500;$i++) {
        $p=$observer->query("SELECT COMMAND,INFO,TIME FROM information_schema.PROCESSLIST WHERE ID=$thread")->fetch_assoc();
        $sql=$clone?'SELECT * FROM compliance_references WHERE id = ? LIMIT 1 FOR UPDATE':'SELECT id FROM compliance_references WHERE id=? FOR UPDATE';
        if($p && in_array($p['COMMAND'],['Query','Execute'],true) && (int)$p['TIME']>=1 && str_contains((string)$p['INFO'],$sql)) {
            if(is_file($worker['file']))throw new RuntimeException('Worker completed before source barrier release.');
            echo 'OBSERVED source query pending behind the retained source row lock: '.$thread.PHP_EOL;return;
        }
        usleep(10000);
    }
    throw new RuntimeException('Guarded source query did not block.');
}
foreach (['distinct','duplicate','rollback'] as $mode) {
    $f=revisionFixture($db,$actor,strtoupper($mode));$before=revisionSourceDigest($db,$f);
    if($mode==='rollback')$db->query("CREATE TRIGGER t4_concurrency_fail BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action='CURRICULUM_REVISION_CREATE' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic concurrent rollback'; END IF; END");
    $rowCounts=protectionSnapshot($db);
    // First clone owns source and waits on this subject; second clone must then
    // wait on the first clone's source. Both remain concurrent until release.
    $db->begin_transaction();$db->query("SELECT id FROM subjects WHERE id={$f['subjects'][0]} FOR UPDATE");
    $a=revisionSpawn($f,'Concurrent '.$mode.' A',$actor);
    try {
        revisionWait($observer,[$a],'SELECT id FROM subjects WHERE id=? FOR UPDATE');
        $b=revisionSpawn($f,'Concurrent '.$mode.($mode==='duplicate'?' A':' B'),$actor);
        revisionWaitSourceQuery($observer,$b,true);
    } finally {$db->commit();}
    $results=[revisionFinish($a),revisionFinish($b)];
    $success=count(array_filter($results,fn($r)=>str_starts_with($r,'SUCCESS')));
    if($mode==='distinct') {
        revisionConcurrencyCheck($success===2 && $results[0]!==$results[1],'simultaneous distinct revisions both commit with independent IDs');
        foreach($results as $result) {
            $id=(int)substr($result,8);
            revisionConcurrencyCheck((int)$db->query("SELECT COUNT(*) FROM curriculum_version_subjects WHERE curriculum_version_id=$id")->fetch_row()[0]===4
                && (int)$db->query("SELECT COUNT(*) FROM curriculum_subject_requirements WHERE curriculum_version_id=$id")->fetch_row()[0]===4,'concurrent revision owns complete placements and requirements');
        }
    } elseif($mode==='duplicate') revisionConcurrencyCheck($success===1 && count(array_filter($results,fn($r)=>str_starts_with($r,'CONFLICT')))===1,'duplicate-label race commits exactly once');
    else {
        $db->query('DROP TRIGGER t4_concurrency_fail');
        revisionConcurrencyCheck($success===0 && count(array_filter($results,fn($r)=>str_starts_with($r,'SQLERROR')))===2 && protectionSnapshot($db)===$rowCounts,'concurrent audit failures roll back every row and success audit');
    }
    revisionConcurrencyCheck(revisionSourceDigest($db,$f)===$before,'concurrent '.$mode.' preserves reference and shared records');
}
foreach (['document','subject'] as $kind) {
    $f=revisionFixture($db,$actor,'STALE-'.strtoupper($kind));
    $db->begin_transaction();
    // Simulate out-of-band corruption in disposable data to prove current-read validation;
    // legitimate source/master edits are separately tested against the protected service.
    if($kind==='document')$db->query("UPDATE compliance_references SET title='Committed changed document' WHERE id={$f['sourceId']}");
    else $db->query("UPDATE subjects SET subject_title='Committed changed master' WHERE id={$f['subjects'][0]}");
    $worker=revisionSpawn($f,'Stale '.$kind,$actor);
    try {
        if($kind==='document')revisionWaitSourceQuery($observer,$worker,true);
        else revisionWait($observer,[$worker],'SELECT id FROM subjects WHERE id=? FOR UPDATE');
    } finally {$db->commit();}
    $result=revisionFinish($worker);
    revisionConcurrencyCheck(str_starts_with($result,'CONFLICT') && (int)$db->query("SELECT COUNT(*) FROM curriculum_versions WHERE baseline_version_id={$f['version']}")->fetch_row()[0]===0,'post-wait '.$kind.' validation rejects stale consistent-read data');
}
$f=revisionFixture($db,$actor,'GUARDED');$digest=revisionSourceDigest($db,$f);
$db->begin_transaction();$db->query("SELECT id FROM academic_programs WHERE id={$f['program']} FOR UPDATE");
$clone=revisionSpawn($f,'Guarded mutation revision',$actor);
try {
    revisionWait($observer,[$clone],'SELECT id FROM academic_programs WHERE id=? FOR UPDATE');
    $mutation=revisionSpawn($f,'GUARDED',$actor,'source_update');
    revisionWaitSourceQuery($observer,$mutation);
} finally {$db->commit();}
$cloneResult=revisionFinish($clone);$mutationResult=revisionFinish($mutation);
revisionConcurrencyCheck(str_starts_with($cloneResult,'SUCCESS') && str_starts_with($mutationResult,'CONFLICT') && revisionSourceDigest($db,$f)===$digest,'clone and authorized source edit serialize; protected source edit fails without changes');
$observer->close();
echo json_encode(['passed'=>true,'checks'=>$checks,'suite'=>'Task 4 deterministic concurrency','isolation'=>'REPEATABLE READ','observed_waits'=>true],JSON_THROW_ON_ERROR).PHP_EOL;
