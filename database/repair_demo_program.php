<?php
// One-time production repair. Apache denies /database/ and this entry point requires CLI.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/import_demo.php';

const DEMO_PRE_CLASSIFICATION_DIGEST = '9676de90c7c5e7e57ad7afb23497ab6c697acb1b91616436401ad57e17b296d0';
const DEMO_REPAIR_ACTION = 'DEMO_PROGRAM_CLASSIFICATION_REPAIR';

function demoRepairRun(array $args): string {
    demoRequire($args===['--apply','--confirm-dataset='.BsitDataset::ID],
        'Use --apply --confirm-dataset='.BsitDataset::ID.'.');
    demoRequire(getenv('APP_ENV')==='production' && getenv('CSMS_RUN_DEMO_REPAIR')==='1'
        && getenv('CSMS_DEMO_REPAIR_CONFIRM_DATASET')===BsitDataset::ID
        && getenv('CSMS_DEMO_REPAIR_CONFIRM_MODE')==='PROGRAM-CLASSIFICATION',
        'Production demo repair confirmations are required.');
    $config=csms_database_config();
    $confirmed=getenv('CSMS_DEMO_REPAIR_CONFIRM_DATABASE');
    demoRequire($confirmed===DEMO_TARGET_DATABASE && $config['database']===$confirmed
        && csms_database_env(['DB_NAME','DB_DATABASE','SMS_DB_NAME'],'')===$confirmed,
        'Database confirmation mismatch.');
    $actor=filter_var(getenv('CSMS_DEMO_REPAIR_ACTOR_ID'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    demoRequire($actor!==false,'Valid existing admin actor ID required.');
    demoRequire(hash_equals(DEMO_MANIFEST_SHA256,hash_file('sha256',__DIR__.'/seeds/bsit_preoral_manifest.json')),
        'Reviewed manifest digest mismatch.');
    $manifest=BsitDataset::manifest();
    $expected=$manifest['planned_structure']['academic_program'];
    $db=csms_database_connect($config);$locked=false;$transaction=false;
    try {
        demoRequire(demoScalar($db,'SELECT DATABASE()')===$confirmed,'Connected database mismatch.');
        $lock='csms_production_demo_import_'.substr(hash('sha256',$confirmed),0,32);
        $s=$db->prepare('SELECT GET_LOCK(?,0)');$s->bind_param('s',$lock);$s->execute();
        $locked=(int)$s->get_result()->fetch_row()[0]===1;$s->close();
        demoRequire($locked,'Another demo import or repair is running.');
        $s=$db->prepare("SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id
            JOIN roles r ON r.id=ur.role_id WHERE u.id=? AND r.name IN ('super_admin','admin')");
        $s->bind_param('i',$actor);$s->execute();$admin=$s->get_result()->fetch_row();$s->close();
        demoRequire($admin!==null,'Existing admin actor required.');
        $db->begin_transaction();$transaction=true;
        $s=$db->prepare("SELECT metadata_json FROM audit_logs WHERE action='DEMO_DATASET_IMPORT'
            AND entity_type='production_demo_dataset' ORDER BY id FOR UPDATE");
        $s->execute();$events=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        demoRequire(count($events)===1,'Exactly one controlled demo ownership audit event required.');
        $owned=json_decode($events[0]['metadata_json'],true,512,JSON_THROW_ON_ERROR);
        demoRequire(($owned['dataset_id']??null)===BsitDataset::ID
            && ($owned['manifest_digest']??null)===DEMO_PRE_CLASSIFICATION_DIGEST
            && ($owned['counts']??null)===DEMO_TABLES,
            'Controlled demo ownership metadata mismatch.');
        $ownedIds=$owned['record_ids']??null;
        demoRequire(is_array($ownedIds) && array_keys($ownedIds)===array_keys(array_filter(DEMO_TABLES)),
            'Demo ownership IDs missing.');
        foreach($ownedIds as $table=>$ids) {
            demoRequire(is_array($ids) && count($ids)===DEMO_TABLES[$table],
                'Demo ownership count mismatch.');
            foreach($ids as $id) demoRequire(is_int($id) && $id>0,'Invalid owned record ID.');
            $actual=array_map('intval',array_column($db->query("SELECT id FROM `$table` ORDER BY id FOR UPDATE")->fetch_all(MYSQLI_ASSOC),'id'));
            $expectedIds=$ids;sort($expectedIds);
            demoRequire($actual===$expectedIds,'Demo ownership IDs mismatch.');
        }
        foreach(DEMO_TABLES as $table=>$count)
            demoRequire((int)demoScalar($db,"SELECT COUNT(*) FROM `$table`")===$count,'Demo academic count mismatch.');
        $programId=$ownedIds['academic_programs'][0];
        $s=$db->prepare('SELECT id,program_code,program_name,program_type,status FROM academic_programs WHERE id=? FOR UPDATE');
        $s->bind_param('i',$programId);$s->execute();$program=$s->get_result()->fetch_assoc();$s->close();
        demoRequire($program!==null && (int)$program['id']===$programId
            && $program['program_code']===$expected['program_code']
            && $program['program_name']===$expected['program_name']
            && $program['status']===$expected['status'],
            'Owned demo program identity mismatch.');
        $s=$db->prepare('SELECT id FROM curricula WHERE academic_program_id=?');
        $s->bind_param('i',$programId);$s->execute();$curricula=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        demoRequire(count($curricula)===1 && (int)$curricula[0]['id']===$ownedIds['curricula'][0],
            'Owned curriculum link mismatch.');
        demoVerifyRows($db,$manifest,$ownedIds['curriculum_versions'][0]);
        $s=$db->prepare('SELECT entity_type,entity_id FROM audit_logs WHERE action=? FOR UPDATE');
        $action=DEMO_REPAIR_ACTION;$entity='academic_program';
        $s->bind_param('s',$action);$s->execute();
        $repairs=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        demoRequire(count($repairs)<=1 && (!$repairs || ($repairs[0]['entity_type']===$entity
            && (int)$repairs[0]['entity_id']===$programId)),
            'Classification repair audit mismatch; manual review required.');
        if($program['program_type']===$expected['program_type']) {
            $db->rollback();$transaction=false;
            return 'Demo BSIT program classification already correct; no changes made.';
        }
        demoRequire(!$repairs,'Repair audit already exists but classification differs; manual review required.');
        demoRequire($program['program_type']===null || trim($program['program_type'])==='',
            'Demo BSIT program classification differs from expected state; manual review required.');
        $s=$db->prepare("UPDATE academic_programs SET program_type=?, updated_at=updated_at
            WHERE id=? AND (program_type IS NULL OR TRIM(program_type)='')");
        $s->bind_param('si',$expected['program_type'],$programId);$s->execute();
        demoRequire($s->affected_rows===1,'Demo program changed during repair; rolling back.');$s->close();
        $s=$db->prepare('SELECT program_type FROM academic_programs WHERE id=?');
        $s->bind_param('i',$programId);$s->execute();$after=$s->get_result()->fetch_row();$s->close();
        demoRequire($after!==null && $after[0]===$expected['program_type'],'Repair verification failed.');
        require_once __DIR__.'/../shared/audit.php';
        $GLOBALS['conn']=$db;$_SESSION=['user_id'=>$actor];
        audit_log_required(DEMO_REPAIR_ACTION,'academic_program',$programId,
            'Controlled demo BSIT program degree level corrected.',[
                'dataset_id'=>BsitDataset::ID,'program_id'=>$programId,
                'old_program_type'=>$program['program_type'],'new_program_type'=>$expected['program_type']]);
        $db->commit();$transaction=false;
        return 'Controlled demo BSIT program classification repaired and verified.';
    } catch(Throwable $e) {if($transaction)$db->rollback();throw $e;}
    finally {
        if($locked) {$s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$lock);$s->execute();$s->close();}
        $db->close();
    }
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    try {echo demoRepairRun(array_slice($argv,1)).PHP_EOL;}
    catch(Throwable $e) {
        fwrite(STDERR,($e instanceof mysqli_sql_exception || $e instanceof JsonException
            ? 'Demo repair failed safely; database and ownership need review.' : $e->getMessage()).PHP_EOL);
        exit(1);
    }
}
