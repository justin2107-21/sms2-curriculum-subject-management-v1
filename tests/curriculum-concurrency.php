<?php
// Deterministic Task 2.1 concurrency regressions. Disposable schema only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../modules/curriculum/services/GradeWeightingService.php';
require_once __DIR__ . '/../modules/curriculum/services/CurriculumSubjectRequirementService.php';
require_once __DIR__ . '/../modules/curriculum/services/CurriculumVersionSubjectService.php';
require_once __DIR__ . '/../modules/curriculum/services/ElectivesWorkspaceService.php';

$dbName = (string)$conn->query('SELECT DATABASE()')->fetch_row()[0];
$identity = $conn->query('SELECT VERSION() version,@@port port,@@datadir datadir,@@foreign_key_checks fk')->fetch_assoc();
$dataDir = realpath((string)$identity['datadir']);
$tempRoot = realpath(sys_get_temp_dir());
if (!preg_match('/^csms_docker_test_task2_[a-z0-9_]+$/D', $dbName)
    || getenv('SMS_TEST_CONFIRM_DATABASE') !== $dbName
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('APP_ENV') !== 'development'
    || (int)$identity['port'] === 3306 || (int)$identity['fk'] !== 1
    || !$dataDir || !$tempRoot || stripos($dataDir, $tempRoot . DIRECTORY_SEPARATOR) !== 0) {
    throw new RuntimeException('BLOCKED: disposable database identity could not be verified.');
}

function concurrencyCheck(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
}

function concurrencyRecordWorkerResult(string $path, string $result): void
{
    $temporary = $path . '.tmp';
    file_put_contents($temporary, json_encode(['result'=>$result],JSON_THROW_ON_ERROR), LOCK_EX);
    if (!rename($temporary,$path)) throw new RuntimeException('Could not publish worker result.');
    echo $result . PHP_EOL;
}

function concurrencyInsert(string $sql): int
{
    global $conn;
    $conn->query($sql);
    return (int)$conn->insert_id;
}

function concurrencyFixture(string $kind, string $suffix): array
{
    global $conn;
    $p = concurrencyInsert("INSERT INTO academic_programs(program_code,program_name) VALUES ('F1-$suffix','F1 test program')");
    $c = concurrencyInsert("INSERT INTO curricula(curriculum_code,curriculum_name,academic_program_id) VALUES ('F1-$suffix','F1 test curriculum',$p)");
    $v = concurrencyInsert("INSERT INTO curriculum_versions(curriculum_id,version_label,version_role,review_status) VALUES ($c,'F1 test version','unclassified','not_started')");
    $subjects = [];
    for ($i = 1; $i <= 4; $i++) {
        $subjects[] = concurrencyInsert("INSERT INTO subjects(subject_code,subject_title,units) VALUES ('F1-$suffix-$i','F1 subject $i',3)");
    }
    $placements = [];
    foreach ($subjects as $i => $subject) {
        $year = '1st Year';
        $term = '1st Semester';
        if ($kind === 'prerequisite' && $i === 0) $year = '2nd Year';
        if ($kind === 'placement') {
            $year = $i === 0 ? '2nd Year' : '1st Year';
            $term = $i === 0 ? '1st Semester' : '2nd Semester';
        }
        $placements[] = concurrencyInsert("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester,is_required) VALUES ($v,$subject,'$year','$term',1)");
    }
    $out = compact('p','c','v','subjects','placements');
    if ($kind === 'grade' || $kind === 'grade-update' || $kind === 'configuration') {
        $scheme = concurrencyInsert("INSERT INTO grade_weighting_schemes(curriculum_version_subject_id,description) VALUES ({$placements[0]},'Initial description')");
        $initialWeight = $kind === 'grade-update' ? 25 : 50;
        $component = concurrencyInsert("INSERT INTO grade_weighting_components(scheme_id,component_name,weight_percentage,sequence_order) VALUES ($scheme,'Initial',$initialWeight,1)");
        $out['scheme'] = $scheme; $out['component'] = $component;
        if ($kind === 'grade-update') $out['componentB'] = concurrencyInsert("INSERT INTO grade_weighting_components(scheme_id,component_name,weight_percentage,sequence_order) VALUES ($scheme,'Second',25,2)");
    }
    if ($kind === 'prerequisite') {
        $relationship = concurrencyInsert("INSERT INTO curriculum_subject_requirements(curriculum_version_id,subject_id,required_subject_id,requirement_type) VALUES ($v,{$subjects[0]},{$subjects[1]},'prerequisite')");
        $out['relationship'] = $relationship;
    }
    if ($kind === 'elective') {
        $group = concurrencyInsert("INSERT INTO curriculum_elective_groups(curriculum_version_id,group_name,year_level,semester,required_selections) VALUES ($v,'F1 group','1st Year','1st Semester',1)");
        foreach (array_slice($subjects,0,2) as $subject) $conn->query("INSERT INTO curriculum_elective_group_subjects(elective_group_id,curriculum_version_id,subject_id) VALUES ($group,$v,$subject)");
        $out['group'] = $group;
    }
    return $out;
}

function concurrencyWorker(array $args): void
{
    global $conn;
    $kind = $args[0]; $isolation = strtoupper($args[1]);
    $resultPath = (string)end($args);
    $conn->query('SET SESSION TRANSACTION ISOLATION LEVEL ' . ($isolation === 'READ COMMITTED' ? 'READ COMMITTED' : 'REPEATABLE READ'));
    $conn->query('SET SESSION innodb_lock_wait_timeout=12');
    echo 'READY ' . $conn->thread_id . PHP_EOL;
    flush();
    try {
        if ($kind === 'grade') {
            (new GradeWeightingService($conn))->createComponent(['scheme_id'=>(int)$args[2],'component_name'=>'Concurrent B','weight_percentage'=>'30','sequence_order'=>2], 0);
            throw new RuntimeException('Grade addition unexpectedly succeeded.');
        } elseif ($kind === 'grade-update') {
            (new GradeWeightingService($conn))->updateComponent((int)$args[2],['component_name'=>'Concurrent B','weight_percentage'=>'60','sequence_order'=>2]);
            throw new RuntimeException('Concurrent grade update unexpectedly succeeded.');
        } elseif ($kind === 'prerequisite') {
            (new CurriculumSubjectRequirementService($conn))->updateRule([
                'curriculum_version_id'=>(int)$args[2], 'subject_id'=>(int)$args[3],
                'required_subject_ids'=>[(int)$args[4]], 'expected_ids'=>[(int)$args[5]],
            ], 0);
        } elseif ($kind === 'elective') {
            (new ElectivesWorkspaceService($conn))->save([
                'program_id'=>(int)$args[2], 'curriculum_id'=>(int)$args[3], 'curriculum_version_id'=>(int)$args[4],
                'group_name'=>'F1 group', 'year_level'=>'1st Year', 'semester'=>'1st Semester',
                'required_selections'=>1, 'status'=>'active', 'subject_ids'=>[(int)$args[5],(int)$args[6],(int)$args[9]],
            ], (int)getenv('F1_TEST_ACTOR'), (int)$args[7]);
            concurrencyRecordWorkerResult($resultPath,'SUCCESS'); exit(0);
        } elseif ($kind === 'placement') {
            (new CurriculumVersionSubjectService($conn))->updatePlacement((int)$args[2], [
                'year_level'=>'3rd Year','semester'=>'1st Semester','sequence_order'=>1,'is_required'=>1,
            ]);
        } elseif ($kind === 'configuration') {
            (new GradeWeightingService($conn))->saveConfiguration((int)$args[2],(int)$args[3],[
                'components'=>[['id'=>(int)$args[4],'component_name'=>'Initial','weight_percentage'=>'100']],
            ],0);
            concurrencyRecordWorkerResult($resultPath,'SUCCESS'); exit(0);
        }
        throw new RuntimeException('Expected validation or conflict was not raised.');
    } catch (DomainValidationException $e) {
        if (in_array($kind,['grade','grade-update'],true) && str_contains($e->getMessage(),'Total component weight')) { concurrencyRecordWorkerResult($resultPath,'REJECTED'); exit(0); }
        if ($kind === 'placement' && str_contains($e->getMessage(),'prerequisite')) { concurrencyRecordWorkerResult($resultPath,'REJECTED'); exit(0); }
        concurrencyRecordWorkerResult($resultPath,'ERROR ' . get_class($e) . ': ' . $e->getMessage()); exit(2);
    } catch (DomainConflictException $e) {
        if ($kind === 'prerequisite' && str_contains($e->getMessage(),'rule changed')) { concurrencyRecordWorkerResult($resultPath,'CONFLICT'); exit(0); }
        concurrencyRecordWorkerResult($resultPath,'ERROR ' . get_class($e) . ': ' . $e->getMessage()); exit(2);
    } catch (Throwable $e) {
        concurrencyRecordWorkerResult($resultPath,'ERROR ' . get_class($e) . ': ' . $e->getMessage()); exit(2);
    }
}

if (($argv[1] ?? '') === '--worker') {
    concurrencyWorker(array_slice($argv,2));
    exit(0);
}

function awaitOwnerLockWait(string $kind, string $isolation, array $workerArgs, int $programId, callable $stageA): string
{
    global $conn;
    // A and B are the only transaction connections. This separate observer
    // checks B's process-list entry while A retains the same owner row lock.
    $observer = csms_database_connect(csms_database_config());
    $conn->query('SET SESSION TRANSACTION ISOLATION LEVEL ' . ($isolation === 'READ COMMITTED' ? 'READ COMMITTED' : 'REPEATABLE READ'));
    $conn->begin_transaction();
    try {
        $stageA();
        $stmt = $conn->prepare('SELECT id FROM academic_programs WHERE id=? FOR UPDATE');
        $stmt->bind_param('i',$programId); $stmt->execute();
        if (!$stmt->get_result()->fetch_row()) throw new RuntimeException('A could not retain the owning program lock.');
        $stmt->close();
        $parentThread = (int)$conn->thread_id;
        $tempRoot = getenv('CSMS_TASK2_TEMP_ROOT');
        if (!$tempRoot || !is_dir($tempRoot)) throw new RuntimeException('Isolated temporary root is unavailable.');
        $resultPath = rtrim($tempRoot,'\\/') . DIRECTORY_SEPARATOR . 'f1-worker-' . bin2hex(random_bytes(12)) . '.json';
        $command = array_merge([PHP_BINARY, __FILE__, '--worker', $kind, $isolation], array_map('strval',$workerArgs),[$resultPath]);
        $process = proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if (!is_resource($process)) throw new RuntimeException('Could not start concurrency worker.');
        fclose($pipes[0]);
        $ready = fgets($pipes[1]);
        if (!is_string($ready) || !preg_match('/^READY (\d+)/',trim($ready),$match)) throw new RuntimeException('Worker did not report its database connection.');
        $workerThread = (int)$match[1];
        $deadline = microtime(true) + 8.0;
        $observed = false;
        while (microtime(true) < $deadline) {
            $stmt = $observer->prepare('SELECT COMMAND,STATE,INFO,TIME FROM information_schema.PROCESSLIST WHERE ID=?');
            $stmt->bind_param('i',$workerThread); $stmt->execute();
            $processInfo = $stmt->get_result()->fetch_assoc(); $stmt->close();
            $ownershipSql = 'academic_programs WHERE id=? FOR UPDATE';
            $observed = $processInfo && in_array($processInfo['COMMAND'],['Query','Execute'],true)
                && (int)$processInfo['TIME'] >= 1 && str_contains((string)$processInfo['INFO'],$ownershipSql);
            if ($observed) break;
            usleep(20000);
        }
        if (!$observed) {
            $transactions = $observer->query("SELECT trx_mysql_thread_id,trx_state,trx_query FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id IN ($workerThread,$parentThread)")->fetch_all(MYSQLI_ASSOC);
            $waits = $observer->query('SELECT requesting_trx_id,blocking_trx_id FROM information_schema.INNODB_LOCK_WAITS')->fetch_all(MYSQLI_ASSOC);
            $processInfo = $observer->query("SELECT ID,COMMAND,STATE,INFO FROM information_schema.PROCESSLIST WHERE ID=$workerThread")->fetch_assoc();
            throw new RuntimeException('Worker did not execute the ownership lock query while Transaction A retained the owner row. Threads=' . json_encode([$workerThread,$parentThread]) . ' transactions=' . json_encode($transactions) . ' waits=' . json_encode($waits) . ' process=' . json_encode($processInfo));
        }
        $conn->commit();
        $observer->close(); $observer = null;
        $resultDeadline = microtime(true) + 10.0;
        while (!is_file($resultPath) && microtime(true) < $resultDeadline) usleep(20000);
        $payload = is_file($resultPath) ? json_decode((string)file_get_contents($resultPath),true) : null;
        if (is_file($resultPath)) unlink($resultPath);
        if (is_file($resultPath . '.tmp')) unlink($resultPath . '.tmp');
        $exit = proc_close($process);
        if ($exit !== 0 || !is_array($payload) || !isset($payload['result'])) throw new RuntimeException('Worker failed or did not publish a result.');
        return (string)$payload['result'];
    } catch (Throwable $e) {
        $conn->rollback();
        if (isset($observer) && $observer instanceof mysqli) $observer->close();
        if (isset($resultPath) && is_file($resultPath)) unlink($resultPath);
        if (isset($resultPath) && is_file($resultPath . '.tmp')) unlink($resultPath . '.tmp');
        if (isset($process) && is_resource($process)) {
            foreach ([$pipes[1] ?? null,$pipes[2] ?? null] as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_terminate($process); proc_close($process);
        }
        throw $e;
    }
}

$checks = 0;
$password = bin2hex(random_bytes(24));
$username = 'f1_' . bin2hex(random_bytes(6));
$escapedPassword = $conn->real_escape_string(password_hash($password,PASSWORD_DEFAULT));
$actorId = concurrencyInsert("INSERT INTO users(username,email,first_name,last_name,password_hash) VALUES ('$username','$username@example.invalid','F1','Test','$escapedPassword')");
putenv('F1_TEST_ACTOR=' . $actorId);
foreach (['REPEATABLE READ','READ COMMITTED'] as $isolation) {
    foreach (['grade','grade-update','prerequisite','elective','placement'] as $kind) {
        $f = concurrencyFixture($kind, strtolower(str_replace(' ','',$isolation)).'_'.$kind);
        if ($kind === 'grade') {
            $result = awaitOwnerLockWait($kind,$isolation,[$f['scheme']],$f['p'],function() use ($f,$actorId) {
                global $conn; (new CurriculumVersionEditPolicy($conn))->assertEditable($f['v']);
                $id=(new GradeWeightingRepository($conn))->createComponent(['scheme_id'=>$f['scheme'],'component_name'=>'Concurrent A','weight_percentage'=>'30','sequence_order'=>2],$actorId);
                audit_log_required('GRADE_WEIGHTING_COMPONENT_CREATE','grade_weighting_component',$id,'Concurrent A component created.');
            });
            $rows=$conn->query("SELECT weight_percentage FROM grade_weighting_components WHERE scheme_id={$f['scheme']} ORDER BY id")->fetch_all(MYSQLI_NUM);
            $audits=(int)$conn->query("SELECT COUNT(*) FROM audit_logs WHERE action='GRADE_WEIGHTING_COMPONENT_CREATE' AND entity_type='grade_weighting_component' AND entity_id IN (SELECT id FROM grade_weighting_components WHERE scheme_id={$f['scheme']})")->fetch_row()[0];
            concurrencyCheck($result==='REJECTED' && array_map('strval',array_column($rows,0))===['50.00','30.00'] && $audits===1,'grade total remains 80%; rejected B leaves no row or audit under '.$isolation); $checks++;
        } elseif ($kind === 'grade-update') {
            $result=awaitOwnerLockWait($kind,$isolation,[$f['componentB']],$f['p'],function() use ($f,$actorId) {
                global $conn;(new CurriculumVersionEditPolicy($conn))->assertEditable($f['v']);
                $repo=new GradeWeightingRepository($conn);$repo->findComponentById($f['component'],true);$repo->findSchemeById($f['scheme'],true);
                $repo->updateComponent($f['component'],['component_name'=>'Concurrent A','weight_percentage'=>'60','sequence_order'=>1]);
                audit_log_required('GRADE_WEIGHTING_COMPONENT_UPDATE','grade_weighting_component',$f['component'],'A updated component weight.');
            });
            $rows=$conn->query("SELECT weight_percentage FROM grade_weighting_components WHERE scheme_id={$f['scheme']} ORDER BY id")->fetch_all(MYSQLI_NUM);
            $audits=(int)$conn->query("SELECT COUNT(*) FROM audit_logs WHERE action='GRADE_WEIGHTING_COMPONENT_UPDATE' AND entity_id IN (SELECT id FROM grade_weighting_components WHERE scheme_id={$f['scheme']})")->fetch_row()[0];
            concurrencyCheck($result==='REJECTED' && array_map('strval',array_column($rows,0))===['60.00','25.00'] && $audits===1,'concurrent component update uses current other weight and rolls B back under '.$isolation); $checks++;
        } elseif ($kind === 'prerequisite') {
            $target=$f['subjects'][0];$old=$f['relationship'];$aRequired=$f['subjects'][2];$bRequired=$f['subjects'][3];
            $result = awaitOwnerLockWait($kind,$isolation,[$f['v'],$target,$bRequired,$old],$f['p'],function() use ($f,$target,$aRequired,$old,$actorId) {
                global $conn; (new CurriculumVersionEditPolicy($conn))->assertEditable($f['v']);
                $repo=new CurriculumSubjectRequirementRepository($conn);$repo->lockVersion($f['v']);
                if(!$repo->delete($old)) throw new RuntimeException('A could not remove initial prerequisite.');
                $id=$repo->create(['curriculum_version_id'=>$f['v'],'subject_id'=>$target,'required_subject_id'=>$aRequired,'requirement_type'=>'prerequisite'],$actorId);
                audit_log_required('SUBJECT_REQUIREMENT_REMOVE','curriculum_subject_requirement',$old,'A removed old prerequisite.',['version_id'=>$f['v']]);
                audit_log_required('SUBJECT_REQUIREMENT_CREATE','curriculum_subject_requirement',$id,'A created replacement prerequisite.',['version_id'=>$f['v']]);
            });
            $rows=$conn->query("SELECT required_subject_id FROM curriculum_subject_requirements WHERE curriculum_version_id={$f['v']} AND subject_id=$target AND requirement_type='prerequisite'")->fetch_all(MYSQLI_NUM);
            $auditCount=(int)$conn->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('SUBJECT_REQUIREMENT_REMOVE','SUBJECT_REQUIREMENT_CREATE') AND entity_type='curriculum_subject_requirement' AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.version_id'))='{$f['v']}'")->fetch_row()[0];
            concurrencyCheck($result==='CONFLICT' && array_map('intval',array_column($rows,0))===[$aRequired] && $auditCount===2,'prerequisite conflict preserves only A and its two truthful audit events under '.$isolation); $checks++;
        } elseif ($kind === 'elective') {
            $result=awaitOwnerLockWait($kind,$isolation,[$f['p'],$f['c'],$f['v'],$f['subjects'][0],$f['subjects'][1],$f['group'],$f['subjects'][2],$f['subjects'][3]],$f['p'],function() use ($f,$actorId) {
                global $conn;(new CurriculumVersionEditPolicy($conn))->assertEditable($f['v']);
                (new ElectivesWorkspaceRepository($conn))->addMember($f['group'],$f['v'],$f['subjects'][2],$actorId);
                audit_log_required('ELECTIVE_GROUP_SUBJECT_ADD','curriculum_elective_group',$f['group'],'A added subject 3.', ['subject_id'=>$f['subjects'][2]]);
            });
            $rows=$conn->query("SELECT subject_id FROM curriculum_elective_group_subjects WHERE elective_group_id={$f['group']} ORDER BY subject_id")->fetch_all(MYSQLI_NUM);
            $expected=[$f['subjects'][0],$f['subjects'][1],$f['subjects'][3]];sort($expected);
            $adds=(int)$conn->query("SELECT COUNT(*) FROM audit_logs WHERE action='ELECTIVE_GROUP_SUBJECT_ADD' AND entity_id={$f['group']}")->fetch_row()[0];
            $removes=(int)$conn->query("SELECT COUNT(*) FROM audit_logs WHERE action='ELECTIVE_GROUP_SUBJECT_REMOVE' AND entity_id={$f['group']}")->fetch_row()[0];
            concurrencyCheck($result==='SUCCESS' && array_map('intval',array_column($rows,0))===$expected && $adds===2 && $removes===1,'elective replaces membership set with truthful add/remove audits under '.$isolation); $checks++;
        } else {
            $target=$f['subjects'][0];$required=$f['subjects'][1];
            $edge=awaitOwnerLockWait('placement',$isolation,[$f['placements'][1]],$f['p'],function() use ($f,$target,$required,$actorId) {
                global $conn;(new CurriculumVersionEditPolicy($conn))->assertEditable($f['v']);
                $repo=new CurriculumSubjectRequirementRepository($conn);$repo->lockVersion($f['v']);
                $id=$repo->create(['curriculum_version_id'=>$f['v'],'subject_id'=>$target,'required_subject_id'=>$required,'requirement_type'=>'prerequisite'],$actorId);
                audit_log_required('SUBJECT_REQUIREMENT_CREATE','curriculum_subject_requirement',$id,'A created placement prerequisite.');
            });
            $row=$conn->query("SELECT year_level FROM curriculum_version_subjects WHERE id={$f['placements'][1]}")->fetch_row();
            $updates=(int)$conn->query("SELECT COUNT(*) FROM audit_logs WHERE action='CURRICULUM_SUBJECT_UPDATE' AND entity_id={$f['placements'][1]}")->fetch_row()[0];
            concurrencyCheck($edge==='REJECTED' && $row[0]==='1st Year' && $updates===0,'placement update sees current prerequisite and leaves no false audit under '.$isolation); $checks++;
        }
    }
}

// A full configuration save must keep the description committed while B waits.
$f=concurrencyFixture('configuration','configuration_rr');
$result=awaitOwnerLockWait('configuration','REPEATABLE READ',[$f['placements'][0],$f['v'],$f['component']],$f['p'],function() use ($f) {
    global $conn;(new CurriculumVersionEditPolicy($conn))->assertEditable($f['v']);
    (new GradeWeightingRepository($conn))->updateScheme($f['scheme'],'Committed while B waits');
    audit_log_required('GRADE_WEIGHTING_SCHEME_UPDATE','grade_weighting_scheme',$f['scheme'],'A changed scheme description.');
});
$row=$conn->query("SELECT description FROM grade_weighting_schemes WHERE id={$f['scheme']}")->fetch_row();
$weight=$conn->query("SELECT weight_percentage FROM grade_weighting_components WHERE id={$f['component']}")->fetch_row();
concurrencyCheck($result==='SUCCESS' && $row[0]==='Committed while B waits' && $weight[0]==='100.00','full configuration preserves current description and exact 100% weight'); $checks++;

echo "PASS: $checks deterministic two-connection concurrency checks; database identity " . json_encode($identity) . PHP_EOL;
