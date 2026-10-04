<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$import=dirname(__DIR__).'/database/import_demo.php';
$base=$_ENV;
foreach (['APP_ENV','CSMS_RUN_DEMO_IMPORT','CSMS_DEMO_IMPORT_CONFIRM_DATABASE',
    'CSMS_DEMO_IMPORT_CONFIRM_DATASET','CSMS_DEMO_IMPORT_CONFIRM_MODE','CSMS_DEMO_IMPORT_ACTOR_ID',
    'DB_NAME','DB_HOST','DB_USER','DB_PASS'] as $key) unset($base[$key]);
$base+=['APP_ENV'=>'production','DB_HOST'=>'example.invalid','DB_USER'=>'demo','DB_PASS'=>'placeholder',
    'DB_NAME'=>'hf_db_9du6kxvk','CSMS_RUN_DEMO_IMPORT'=>'1',
    'CSMS_DEMO_IMPORT_CONFIRM_DATABASE'=>'hf_db_9du6kxvk',
    'CSMS_DEMO_IMPORT_CONFIRM_DATASET'=>'DEV-BSIT-PREORAL-2026',
    'CSMS_DEMO_IMPORT_CONFIRM_MODE'=>'PRODUCTION-DEMO','CSMS_DEMO_IMPORT_ACTOR_ID'=>'1'];
function guardCase(string $label,array $args,array $env,string $expected): void {
    global $import;
    $p=proc_open(array_merge([PHP_BINARY,$import],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
    if(!is_resource($p))throw new RuntimeException('Process unavailable.');
    fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);
    $error=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($p);
    if($code===0 || !str_contains($error,$expected))throw new RuntimeException($label.' failed.');
    echo 'PASS: '.$label.PHP_EOL;
}
try {
    guardCase('No arguments cannot mutate',[],$base,'Use --dry-run');
    $env=$base;unset($env['CSMS_RUN_DEMO_IMPORT']);
    guardCase('Missing import flag',['--dry-run'],$env,'confirmations are required');
    $env=$base;$env['CSMS_DEMO_IMPORT_CONFIRM_DATABASE']='wrong';
    guardCase('Wrong database confirmation',['--dry-run'],$env,'Database confirmation mismatch');
    $env=$base;$env['CSMS_DEMO_IMPORT_CONFIRM_DATASET']='wrong';
    guardCase('Wrong dataset confirmation',['--dry-run'],$env,'confirmations are required');
    $env=$base;$env['CSMS_DEMO_IMPORT_CONFIRM_MODE']='wrong';
    guardCase('Wrong mode confirmation',['--dry-run'],$env,'confirmations are required');
    $env=$base;$env['CSMS_DEMO_IMPORT_ACTOR_ID']='invalid';
    guardCase('Invalid actor',['--dry-run'],$env,'admin actor ID required');
    $env=$base;$env['CSMS_BOOTSTRAP_ADMIN']='1';
    guardCase('Provisioning flag refused',['--dry-run'],$env,'Admin provisioning must be disabled');
    require_once dirname(__DIR__).'/database/seeds/bsit_dataset.php';
    $m=BsitDataset::manifest();
    $m['subjects'][0]['total_units']='invalid';
    try {BsitDataset::manifest($m);throw new RuntimeException('Invalid manifest accepted.');}
    catch(RuntimeException $e) {if($e->getMessage()==='Invalid manifest accepted.')throw $e;}
    echo "PASS: Invalid manifest refused\n";
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    if($socket===false)throw new RuntimeException('HTTP guard test port unavailable.');
    $address=stream_socket_get_name($socket,false);fclose($socket);
    $port=(int)substr(strrchr($address,':'),1);
    $server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',dirname(__DIR__)],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$serverPipes);
    if(!is_resource($server))throw new RuntimeException('HTTP guard test server unavailable.');
    fclose($serverPipes[0]);
    try {
        $status=0;
        for($attempt=0;$attempt<30;$attempt++) {
            $body=@file_get_contents('http://127.0.0.1:'.$port.'/database/repair_demo_program.php');
            $headers=$http_response_header??[];
            if($headers) {$status=(int)(preg_match('/^HTTP\/\d\.\d\s+(\d+)/',$headers[0],$match)?$match[1]:0);break;}
            usleep(100000);
        }
        if($status!==404 || $body!==false && $body!=='')throw new RuntimeException('Repair HTTP access was not blocked.');
        echo "PASS: Repair HTTP access denied before database connection.\n";
    } finally {proc_terminate($server);fclose($serverPipes[1]);fclose($serverPipes[2]);proc_close($server);}
} catch(Throwable $e) {fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
