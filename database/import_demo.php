<?php
// One-time, CLI-only production import. This file is also blocked by Apache.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../shared/database_config.php';
require_once __DIR__.'/seeds/bsit_dataset.php';

const DEMO_MANIFEST_SHA256 = '0dba87f7ac2e1ad3022545b9a7fd551517a707ef69faa796a6d2a0c84490a3b3';
const DEMO_TARGET_DATABASE = 'hf_db_9du6kxvk';
const DEMO_TABLES = ['academic_programs'=>1,'subjects'=>50,'curricula'=>1,'curriculum_versions'=>1,
    'curriculum_version_subjects'=>50,'curriculum_subject_requirements'=>0,
    'curriculum_elective_groups'=>0,'curriculum_elective_group_subjects'=>0];
const DEMO_PROTECTED = ['users','roles','permissions','user_roles','role_permissions','students'];

function demoRequire(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function demoScalar(mysqli $db, string $sql): string { return (string)$db->query($sql)->fetch_row()[0]; }
function demoProcess(string $file, array $args): array {
    $p=proc_open(array_merge([PHP_BINARY,__DIR__.'/'.$file],$args),
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    demoRequire(is_resource($p),'Schema check unavailable.');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);
    stream_get_contents($pipes[2]);fclose($pipes[2]);
    demoRequire(proc_close($p)===0,'Schema check failed.');
    $report=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    demoRequire(is_array($report),'Invalid schema report.');
    return $report;
}
function demoSnapshot(mysqli $db): array {
    $result=[];
    foreach(DEMO_PROTECTED as $table) {
        $order=in_array($table,['user_roles','role_permissions'],true)?'1,2':'id';
        $rows=$db->query("SELECT * FROM `$table` ORDER BY $order")->fetch_all(MYSQLI_ASSOC);
        $result[$table]=[count($rows),hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
    }
    return $result;
}
function demoInsert(mysqli $db,string $table,array $values): int {
    demoRequire(isset(DEMO_TABLES[$table]) && DEMO_TABLES[$table]>0,'Unapproved insert table.');
    $columns=array_keys($values);
    foreach($columns as $column) demoRequire((bool)preg_match('/^[a-z_]+$/D',$column),'Invalid column.');
    $stmt=$db->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
    $args=array_values($values);$stmt->bind_param(str_repeat('s',count($args)),...$args);$stmt->execute();
    $id=(int)$db->insert_id;$stmt->close();return $id;
}
function demoVerifyRows(mysqli $db,array $manifest,int $version): void {
    $rows=$db->query("SELECT s.subject_code,s.subject_title,s.units,s.lecture_units,s.laboratory_units,s.description,
        p.year_level,p.semester,p.is_required,p.curriculum_version_id FROM curriculum_version_subjects p
        JOIN subjects s ON s.id=p.subject_id ORDER BY s.subject_code")->fetch_all(MYSQLI_ASSOC);
    $byCode=array_column($manifest['subjects'],null,'subject_code');$units=0;
    demoRequire(count($rows)===50,'Placement count mismatch.');
    foreach($rows as $row) {
        $source=$byCode[$row['subject_code']]??null;
        demoRequire($source!==null && $row['subject_title']===$source['subject_name']
            && (int)round((float)$row['units']*100)===(int)round($source['total_units']*100)
            && $row['lecture_units']===null && $row['laboratory_units']===null && $row['description']===null
            && $row['year_level']===$source['year_level'] && $row['semester']===$source['semester']
            && (int)$row['is_required']===1 && (int)$row['curriculum_version_id']===$version,
            'Stored subject or placement differs from manifest.');
        $units+=(int)round((float)$row['units']*100);unset($byCode[$row['subject_code']]);
    }
    demoRequire($units===15200 && !$byCode,'Manifest units or unique codes mismatch.');
}
function demoRun(array $args): string {
    $apply=in_array('--apply',$args,true);$dry=in_array('--dry-run',$args,true);
    demoRequire(count($args)===($apply?2:1) && ($apply xor $dry)
        && (!$apply || in_array('--confirm-dataset='.BsitDataset::ID,$args,true)),
        'Use --dry-run or --apply --confirm-dataset='.BsitDataset::ID.'.');
    demoRequire(getenv('APP_ENV')==='production' && getenv('CSMS_RUN_DEMO_IMPORT')==='1'
        && getenv('CSMS_DEMO_IMPORT_CONFIRM_MODE')==='PRODUCTION-DEMO'
        && getenv('CSMS_DEMO_IMPORT_CONFIRM_DATASET')===BsitDataset::ID,
        'Production demo import confirmations are required.');
    demoRequire(getenv('CSMS_BOOTSTRAP_ADMIN')!=='1','Admin provisioning must be disabled for demo import.');
    $config=csms_database_config();
    $confirmed=getenv('CSMS_DEMO_IMPORT_CONFIRM_DATABASE');
    demoRequire(is_string($confirmed) && $confirmed===DEMO_TARGET_DATABASE && $confirmed===$config['database']
        && csms_database_env(['DB_NAME','DB_DATABASE','SMS_DB_NAME'],'')===$confirmed,
        'Database confirmation mismatch.');
    $actor=filter_var(getenv('CSMS_DEMO_IMPORT_ACTOR_ID'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    demoRequire($actor!==false,'Valid existing admin actor ID required.');
    $manifestPath=__DIR__.'/seeds/bsit_preoral_manifest.json';
    demoRequire(hash_equals(DEMO_MANIFEST_SHA256,hash_file('sha256',$manifestPath)),'Reviewed manifest digest mismatch.');
    $manifest=BsitDataset::manifest();
    $digest=BsitDataset::digest($manifest);
    $db=csms_database_connect($config);$locked=false;$transaction=false;
    try {
        demoRequire(demoScalar($db,'SELECT DATABASE()')===$confirmed,'Connected database mismatch.');
        $lock='csms_production_demo_import_'.substr(hash('sha256',$confirmed),0,32);
        $s=$db->prepare('SELECT GET_LOCK(?,0)');$s->bind_param('s',$lock);$s->execute();
        $locked=(int)$s->get_result()->fetch_row()[0]===1;$s->close();
        demoRequire($locked,'Another demo import is running.');
        $status=demoProcess('deploy.php',['--status']);
        demoRequire(($status['pending']??null)===[] && ($status['applied']??0)===16,'Expected complete migration ledger.');
        $migrations=array_column($db->query("SELECT migration FROM schema_migrations WHERE status='applied' ORDER BY migration")->fetch_all(MYSQLI_ASSOC),'migration');
        demoRequire(count($migrations)===16 && in_array('014_finalize_electives_manager.sql',$migrations,true)
            && in_array('015_central_archives.sql',$migrations,true),'Migrations 014 and 015 required.');
        $inspection=demoProcess('inspect.php',[]);
        demoRequire(($inspection['issues']??null)===[],'Schema inspection has issues.');
        $tables=array_keys($inspection['counts']??[]);
        foreach(array_merge(array_keys(DEMO_TABLES),DEMO_PROTECTED,['audit_logs']) as $table)
            demoRequire(in_array($table,$tables,true),'Required table missing.');
        $s=$db->prepare("SELECT id FROM users WHERE id=? AND role='admin'");
        $s->bind_param('i',$actor);$s->execute();$user=$s->get_result()->fetch_assoc();$s->close();
        $s=$db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.name='admin'");
        $s->bind_param('i',$actor);$s->execute();$role=$s->get_result()->fetch_row();$s->close();
        demoRequire($user!==null && $role!==null,'Existing active admin actor required.');
        $db->begin_transaction();$transaction=true;
        $protected=demoSnapshot($db);
        $s=$db->prepare("SELECT metadata_json FROM audit_logs WHERE action='DEMO_DATASET_IMPORT' AND entity_type='production_demo_dataset' ORDER BY id DESC FOR UPDATE");
        $s->execute();$events=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        demoRequire(count($events)<=1,'Multiple demo ownership events; manual review required.');
        $counts=[];foreach(DEMO_TABLES as $table=>$expected)$counts[$table]=(int)demoScalar($db,"SELECT COUNT(*) FROM `$table`");
        if($events) {
            $owned=json_decode($events[0]['metadata_json'],true,512,JSON_THROW_ON_ERROR);
            demoRequire(($owned['dataset_id']??null)===BsitDataset::ID
                && ($owned['manifest_digest']??null)===$digest
                && ($owned['counts']??null)===DEMO_TABLES
                && $counts===DEMO_TABLES,'Existing demo ownership/data mismatch; manual review required.');
            $ownedIds=$owned['record_ids']??null;
            demoRequire(is_array($ownedIds) && array_keys($ownedIds)===array_keys(array_filter(DEMO_TABLES)),
                'Demo ownership IDs missing.');
            foreach($ownedIds as $table=>$list) {
                demoRequire(is_array($list) && count($list)===DEMO_TABLES[$table],'Demo ownership count mismatch.');
                $actual=array_map('intval',array_column($db->query("SELECT id FROM `$table` ORDER BY id")->fetch_all(MYSQLI_ASSOC),'id'));
                $expectedIds=array_map('intval',$list);sort($expectedIds);
                demoRequire($actual===$expectedIds,'Demo ownership IDs mismatch.');
            }
            demoVerifyRows($db,$manifest,(int)$ownedIds['curriculum_versions'][0]);
            $db->rollback();$transaction=false;
            return 'Controlled demo dataset already present; no changes made.';
        }
        demoRequire(!array_filter($counts),'Production academic data is not empty. Demo import refused.');
        if($dry) {$db->rollback();$transaction=false;return 'Dataset: '.BsitDataset::ID."\nTarget: confirmed\nAcademic tables empty: yes\nSubjects to import: 50\nCurricula: 1\nVersions: 1\nPlacements: 50\nTotal units: 152\nReady: yes";}
        $p=$manifest['planned_structure'];$ids=[];
        $ids['academic_programs'][]=$program=demoInsert($db,'academic_programs',[
            'program_code'=>$p['academic_program']['program_code'],'program_name'=>$p['academic_program']['program_name'],
            'program_type'=>null,'status'=>'active','created_by'=>$actor]);
        $subjectIds=[];
        foreach($manifest['subjects'] as $subject) {
            $id=demoInsert($db,'subjects',['subject_code'=>$subject['subject_code'],'subject_title'=>$subject['subject_name'],
                'description'=>null,'units'=>$subject['total_units'],'lecture_units'=>null,'laboratory_units'=>null,
                'status'=>'active','created_by'=>$actor]);
            $ids['subjects'][]=$id;$subjectIds[$subject['subject_code']]=$id;
        }
        $ids['curricula'][]=$curriculum=demoInsert($db,'curricula',[
            'curriculum_code'=>$p['curriculum']['curriculum_code'],'curriculum_name'=>$p['curriculum']['curriculum_name'],
            'academic_program_id'=>$program,'status'=>'active','created_by'=>$actor]);
        $ids['curriculum_versions'][]=$version=demoInsert($db,'curriculum_versions',[
            'curriculum_id'=>$curriculum,'version_label'=>$p['curriculum_version']['version_label'],
            'effective_academic_year'=>null,'description'=>$p['curriculum_version']['description'],
            'status'=>'draft','created_by'=>$actor]);
        foreach($manifest['subjects'] as $subject) $ids['curriculum_version_subjects'][]=demoInsert($db,'curriculum_version_subjects',[
            'curriculum_version_id'=>$version,'subject_id'=>$subjectIds[$subject['subject_code']],
            'year_level'=>$subject['year_level'],'semester'=>$subject['semester'],
            'sequence_order'=>null,'is_required'=>1,'created_by'=>$actor]);
        foreach(DEMO_TABLES as $table=>$expected)
            demoRequire((int)demoScalar($db,"SELECT COUNT(*) FROM `$table`")===$expected,'Inserted count mismatch.');
        demoVerifyRows($db,$manifest,$version);
        demoRequire(demoSnapshot($db)===$protected,'Protected data changed.');
        require_once __DIR__.'/../shared/audit.php';
        $GLOBALS['conn']=$db;$_SESSION=['user_id'=>(int)$actor];
        audit_log_required('DEMO_DATASET_IMPORT','production_demo_dataset',null,
            'Controlled synthetic BSIT defense dataset imported.',[
                'dataset_id'=>BsitDataset::ID,'manifest_digest'=>$digest,'counts'=>DEMO_TABLES,
                'record_ids'=>$ids,'total_units'=>152,'timestamp'=>gmdate('c')]);
        $db->commit();$transaction=false;
        return 'Controlled demo dataset imported and verified: 50 subjects, 50 placements, 152 units.';
    } catch(Throwable $e) {if($transaction)$db->rollback();throw $e;}
    finally {
        if($locked) {$s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$lock);$s->execute();$s->close();}
        $db->close();
    }
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    try {echo demoRun(array_slice($argv,1)).PHP_EOL;}
    catch(Throwable $e) {
        fwrite(STDERR,($e instanceof mysqli_sql_exception || $e instanceof JsonException
            ? 'Demo import failed safely; database and manifest need review.' : $e->getMessage()).PHP_EOL);
        exit(1);
    }
}
