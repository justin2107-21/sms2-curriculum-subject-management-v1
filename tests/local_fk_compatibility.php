<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../shared/database_config.php';
$config=csms_database_config();
if ($config['host']!=='127.0.0.1' || $config['port']===3306 || getenv('SMS_TEST_LOCAL_FK')!=='1') {
    throw new RuntimeException('Isolated local test server required.');
}
$db=csms_database_connect($config,false);
$prefix='csms_docker_test_local_'.bin2hex(random_bytes(6));
$created=[];
function localFkRun(string $file,array $args=[]): void {
    $process=proc_open(array_merge([PHP_BINARY,__DIR__.'/'.$file],$args),[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);
    if (proc_close($process)!==0) { throw new RuntimeException('Local compatibility regression failed: '.$file); }
}
try {
    echo 'Local isolated engine: '.$db->server_info.PHP_EOL;
    foreach (['fresh','legacy'] as $mode) {
        $name=$prefix.'_'.$mode;
        $db->query('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created[]=$name; putenv('DB_NAME='.$name); putenv('SMS_TEST_CONFIRM_DATABASE='.$name);
        if ($mode==='fresh') { localFkRun('deployment_migrations.php'); }
        localFkRun('academic_fk_compatibility.php',$mode==='legacy'?['--legacy']:[]);
    }
} finally {
    foreach ($created as $name) {
        if (!preg_match('/^csms_docker_test_local_[a-f0-9]{12}_(fresh|legacy)$/D',$name)) { throw new RuntimeException('Unsafe cleanup target'); }
        $db->query('DROP DATABASE `'.$name.'`');
    }
    echo 'PASS: Isolated local schemas removed.'.PHP_EOL;
}
