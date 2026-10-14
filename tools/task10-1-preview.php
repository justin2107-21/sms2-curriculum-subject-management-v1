<?php
declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$s=json_decode((string)file_get_contents($argv[1]??''),true,512,JSON_THROW_ON_ERROR);
$mode=$argv[2]??'check';$stateDir=realpath(dirname($argv[1]??''));$root=realpath(__DIR__.'/..');
if(($s['kind']??'')!=='CSMS_TASK10_1_DISPOSABLE_PREVIEW' || realpath($s['root']??'')!==$root
    || !$stateDir || basename($stateDir)!=='task10-1-preview' || basename(dirname($stateDir))!=='CSMS-SMS2'
    || !preg_match('/^[a-f0-9]{32}$/D',$s['marker']??'')
    || !preg_match('/^csms_docker_test_task7_run_101_[a-f0-9]{32}$/D',$s['database']??'')
    || realpath($s['data']??'')!==realpath($stateDir.'/data')
    || ($s['dbPort']??3306)===3306 || ($s['dbPort']??0)<1024) throw new RuntimeException('Unsafe preview manifest.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root',$s['dbPassword'],null,(int)$s['dbPort']);
$identity=$db->query('SELECT VERSION() version,@@port port,@@datadir datadir,@@foreign_key_checks fk')->fetch_assoc();
if(!str_contains($identity['version'],'MariaDB') || (int)$identity['port']!==(int)$s['dbPort'] || (int)$identity['fk']!==1
    || strcasecmp((string)realpath($identity['datadir']),(string)realpath($s['data']))!==0) throw new RuntimeException('BLOCKED: disposable server identity mismatch.');
foreach(['DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$s['dbPort'],'DB_USER'=>'root','DB_PASS'=>$s['dbPassword'],'DB_NAME'=>$s['database'],'APP_ENV'=>'development','SMS_TEST_CONFIRM_DATABASE'=>$s['database'],'CSMS_TASK7_TEMP_ROOT'=>$stateDir,'SMS_SESSION_COOKIE_SECURE'=>'0','CIMF_AI_ENABLED'=>'false'] as $key=>$value)putenv($key.'='.$value);
function preview_cli(array $arguments):void {
    $p=proc_open(array_merge([PHP_BINARY],$arguments),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),getenv(),['bypass_shell'=>true]);
    if(!is_resource($p))throw new RuntimeException('Preview subprocess could not start.');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
    if($exit!==0){fwrite(STDERR,$out.$err);throw new RuntimeException('Preview preparation failed; existing databases were not selected.');}
    echo $out;
}
if($mode==='init') {
    $stmt=$db->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$stmt->bind_param('s',$s['database']);$stmt->execute();$exists=$stmt->get_result()->num_rows>0;$stmt->close();
    if($exists)throw new RuntimeException('Refusing to overwrite an existing preview schema. Use explicit Reset.');
    // Database identifier was constrained above; server/datadir independently verified before DDL.
    $db->query('CREATE DATABASE `'.$s['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    preview_cli([$root.'/database/deploy.php','--apply','--confirm-database='.$s['database']]);
    preview_cli([$root.'/database/seeds/load_ched_bsit_outcomes.php']);
    require_once $root.'/shared/db.php';
    require_once __DIR__.'/task10-1-preview-fixture.php';
    $fixture=task10_1_preview_fixture($conn,$s['loginPassword'],$s['viewerPassword']);
    file_put_contents($stateDir.'/fixture.json',json_encode($fixture,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
}
if(!in_array($mode,['check','init','status'],true))throw new RuntimeException('Unknown preview operation.');
if($mode!=='check') {
    $db->select_db($s['database']);
    if($db->query('SELECT DATABASE()')->fetch_row()[0]!==$s['database'])throw new RuntimeException('Preview database identity mismatch.');
    $pending=(int)$db->query("SELECT COUNT(*) FROM schema_migrations WHERE status='applied'")->fetch_row()[0];
    if($pending!==26)throw new RuntimeException('Preview requires the baseline and migrations 001-025.');
}
echo 'SAFE PREVIEW '.json_encode(['database'=>$s['database'],'port'=>(int)$identity['port'],'datadir'=>realpath($identity['datadir']),'foreign_key_checks'=>(int)$identity['fk'],'version'=>$identity['version']],JSON_UNESCAPED_SLASHES).PHP_EOL;
