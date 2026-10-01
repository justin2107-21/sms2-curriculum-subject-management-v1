<?php
// Explicit local-XAMPP operator entrypoint. No web route or production override.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../../shared/database_config.php';
require_once __DIR__.'/bsit_dataset.php';

function bsitLocalConfig(array $c): void {
    BsitDataset::require(in_array(strtolower($c['host']),['localhost','127.0.0.1','::1'],true)
        && $c['port']===3306 && $c['database']==='sms_db'
        && in_array(strtolower(getenv('APP_ENV')?:'development'),['development','local','test'],true)
        && !getenv('DATABASE_URL'),'Only explicit local XAMPP sms_db is permitted.');
}
function bsitBackup(string $path): array {
    $real=realpath($path);$root=realpath('C:/Users/esquitajustin/Documents/csms-local-backups');
    BsitDataset::require($real!==false && $root!==false && dirname($real)===$root
        && preg_match('/^sms_db_preoral_before_final_phase2_[0-9]{8}_[0-9]{6}\.sql$/D',basename($real))===1,
        'Verified backup outside the repository/web root is required.');
    BsitDataset::require(filesize($real)>0 && filemtime($real)>=time()-86400,'A fresh backup (within 24 hours) is required.');
    $sql=file_get_contents($real);
    BsitDataset::require(preg_match_all('/^CREATE TABLE /m',$sql)===22 && strpos($sql,'-- Dump completed on')!==false,'Incomplete backup.');
    return ['backup_path'=>$real,'backup_sha256'=>hash_file('sha256',$real)];
}
function bsitMain(): void {
    $o=getopt('',['action:','database:','actor:','confirm-development','backup:','plan:']);
    $action=$o['action']??'';
    BsitDataset::require(in_array($action,['plan','replace','seed','verify','cleanup'],true)
        && ($o['database']??'')==='sms_db' && array_key_exists('confirm-development',$o),
        'Usage: --action=plan|replace|seed|verify|cleanup --database=sms_db --actor=ID --confirm-development [--backup=PATH --plan=PATH]');
    $actor=filter_var($o['actor']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    BsitDataset::require($actor!==false,'An existing admin actor is required.');
    $m=BsitDataset::manifest(); // Invalid/unfinished manifest fails BEFORE connection or cleanup.
    $c=csms_database_config();bsitLocalConfig($c);
    $db=csms_database_connect($c);
    $server=$db->query('SELECT DATABASE() d, @@datadir p, VERSION() v')->fetch_assoc();
    BsitDataset::require($server['d']==='sms_db'
        && strtolower(str_replace('\\','/',$server['p']))==='c:/xampp/mysql/data/'
        && strpos($server['v'],'MariaDB')!==false,'Verified local XAMPP server required.');
    $keys=['fk_curriculum_subject_requirements_target_placement','fk_curriculum_subject_requirements_required_placement',
        'fk_subject_equivalencies_subject','fk_subject_equivalencies_equivalent_subject','fk_compliance_rules_subject',
        'fk_compliance_rules_program','fk_compliance_rules_curriculum'];
    $fks=$db->query('SELECT CONSTRAINT_NAME,UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()')->fetch_all(MYSQLI_ASSOC);
    $good=0;foreach($fks as $fk)if(in_array($fk['CONSTRAINT_NAME'],$keys,true)&&$fk['UPDATE_RULE']==='RESTRICT')$good++;
    BsitDataset::require(count($fks)===41 && $good===7,'Expected 41 FKs and seven migration-013 RESTRICT keys.');
    $tool=new BsitDataset($db,(int)$actor,$m);
    $backup=$action==='verify'?[]:bsitBackup((string)($o['backup']??''));
    if($action==='plan') {
        $path=(string)($o['plan']??'');$parent=realpath(dirname($path));
        BsitDataset::require($parent===dirname($backup['backup_path']) && !file_exists($path)
            && preg_match('/^bsit_phase2_plan_[0-9_]+\.json$/D',basename($path))===1,'New plan file must be beside the external backup.');
        $db->begin_transaction();
        try {$plan=array_merge($tool->legacyPlan(),$backup);$db->rollback();}
        catch(Throwable $e){$db->rollback();throw $e;}
        BsitDataset::require(file_put_contents($path,json_encode($plan,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX)!==false,'Cannot save review plan.');
        echo "PASS: Read-only cleanup plan saved outside the web root; no database changes.\n";
    } else {
        $plan=null;
        if($action==='replace') {
            $path=realpath((string)($o['plan']??''));
            BsitDataset::require($path!==false && dirname($path)===dirname($backup['backup_path']),'External cleanup plan required.');
            $plan=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
            BsitDataset::require(($plan['backup_sha256']??null)===$backup['backup_sha256'],'Backup/plan mismatch.');
        }
        echo json_encode($tool->run($action,$plan),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
    }
    $db->close();
}
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    try {bsitMain();}
    catch(Throwable $e) {
        // Driver/JSON details are unnecessary and may disclose source/connection data.
        fwrite(STDERR,($e instanceof mysqli_sql_exception || $e instanceof JsonException
            ? 'BSIT operation failed safely; check constraints/ownership. No partial transaction committed.'
            : $e->getMessage()).PHP_EOL);
        exit(1);
    }
}
