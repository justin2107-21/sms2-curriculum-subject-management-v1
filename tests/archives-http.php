<?php
// Isolated HTTP checks for Settings > Archives, RBAC and CSRF.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/FoundationHttpClient.php';
$config=csms_database_config();
if (!in_array($config['host'], ['localhost','127.0.0.1'], true) || getenv('APP_ENV') === 'production') throw new RuntimeException('Local development only.');
$db=csms_database_connect($config); $source=$config['database'];
if (!preg_match('/^[A-Za-z0-9_]+$/D', $source)) throw new RuntimeException('Unsafe source schema.');
$name='csms_archive_http_'.bin2hex(random_bytes(8)); $server=null; $clients=[]; $created=false; $checks=0;
$log=tempnam(sys_get_temp_dir(),'archive-http-'); $sessions=$log.'-sessions'; mkdir($sessions);
function verifyArchive(bool $condition,string $label):void { global $checks; if(!$condition) throw new RuntimeException($label); $checks++; }
function expectArchive(array $response,int $status,string $label):array {
    verifyArchive($response['status']===$status,$label.' returned '.$response['status'].' expected '.$status.' '.$response['body']);
    return $response['json']??[];
}
try {
    $db->query("CREATE DATABASE `$name`"); $created=true;
    foreach (array_column($db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM),0) as $table) {
        if (!preg_match('/^[A-Za-z0-9_]+$/D',$table)) throw new RuntimeException('Unsafe table name.');
        $db->query("CREATE TABLE `$name`.`$table` LIKE `$source`.`$table`");
    }
    $db->select_db($name);
    foreach (['roles','permissions','role_permissions'] as $table) $db->query("INSERT INTO `$table` SELECT * FROM `$source`.`$table`");
    $password=bin2hex(random_bytes(20)); $hash=password_hash($password,PASSWORD_DEFAULT);
    foreach (['archiveadmin','archiveviewer'] as $i=>$username) {
        $id=$i+1; $email=$username.'@example.invalid';
        $stmt=$db->prepare("INSERT INTO users(id,username,email,first_name,last_name,password_hash,role) VALUES(?,?,?,'Test','User',?,'student')");
        $stmt->bind_param('isss',$id,$username,$email,$hash);$stmt->execute();$stmt->close();
    }
    $db->query("INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE name='admin'");
    $db->query("INSERT INTO roles(name,display_name) VALUES('archive_test_viewer','Archive Viewer')"); $viewerRole=(int)$db->insert_id;
    $db->query("INSERT INTO user_roles(user_id,role_id) VALUES(2,$viewerRole)");
    $db->query("INSERT INTO role_permissions(role_id,permission_id) SELECT $viewerRole,id FROM permissions WHERE name IN ('account.view','archive.view')");
    $db->query("INSERT INTO academic_programs(id,program_code,program_name,status) VALUES(1,'BSIT','Information Technology','active')");
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);
    $env=getenv();$env['DB_NAME']=$name;$env['APP_ENV']='development';$env['SMS_SESSION_COOKIE_SECURE']='0';
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.$sessions,'-d','error_log='.$log,'-S',$address,'-t',dirname(__DIR__)],
        [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);
    if(!is_resource($server))throw new RuntimeException('HTTP server failed.');fclose($pipes[0]);
    for($i=0;$i<60;$i++){ $ready=@stream_socket_client('tcp://'.$address,$errno,$error,.1);if($ready){fclose($ready);break;}usleep(100000); }
    $admin=new FoundationHttpClient('http://'.$address);$viewer=new FoundationHttpClient('http://'.$address);$guest=new FoundationHttpClient('http://'.$address);$clients=[$admin,$viewer,$guest];
    expectArchive($guest->request('dashboard/archive_actions.php',['action'=>'list']),401,'Guest list');
    foreach ([[$admin,'archiveadmin'],[$viewer,'archiveviewer']] as [$client,$username]) {
        $client->request('auth/signin.php');
        expectArchive($client->request('shared/auth_actions.php',['action'=>'login','username'=>$username,'password'=>$password],'POST'),200,'Login');
        $page=$client->request('dashboard/account.php?tab=archives');
        verifyArchive($page['status']===200 && str_contains($page['body'],'id="archivesPanel"'),'Archive tab renders');
    }
    expectArchive($viewer->request('dashboard/archive_actions.php',['action'=>'list']),200,'Viewer list');
    expectArchive($viewer->request('dashboard/archive_actions.php',['action'=>'restore','module'=>'program','id'=>1],'POST'),403,'Viewer restore');
    expectArchive($admin->request('modules/curriculum/http/program_actions.php',['action'=>'archive','id'=>1],'POST',''),403,'Archive CSRF');
    expectArchive($admin->request('modules/curriculum/http/program_actions.php',['action'=>'archive','id'=>1],'POST'),200,'Archive program');
    $list=expectArchive($admin->request('dashboard/archive_actions.php',['action'=>'list','module'=>'program','search'=>'BSIT','date'=>'today']),200,'Archive search');
    verifyArchive($list['total']===1 && $list['records'][0]['identifier']==='BSIT','Archived program listed');
    $detail=expectArchive($admin->request('dashboard/archive_actions.php',['action'=>'details','module'=>'program','id'=>1]),200,'Archive details');
    verifyArchive($detail['record']['can_restore']===true,'Restore allowed');
    expectArchive($admin->request('dashboard/archive_actions.php',['action'=>'restore','module'=>'program','id'=>1],'POST',''),403,'Restore CSRF');
    expectArchive($admin->request('dashboard/archive_actions.php',['action'=>'restore','module'=>'program','id'=>0],'POST'),400,'Invalid ID');
    expectArchive($admin->request('dashboard/archive_actions.php',['action'=>'restore','module'=>'users','id'=>1],'POST'),400,'Invalid module');
    expectArchive($admin->request('dashboard/archive_actions.php',['action'=>'restore','module'=>'program','id'=>1],'POST'),200,'Restore program');
    expectArchive($admin->request('dashboard/archive_actions.php',['action'=>'restore','module'=>'program','id'=>1],'POST'),409,'Already restored');
    verifyArchive($db->query("SELECT status FROM academic_programs WHERE id=1")->fetch_row()[0]==='active','Program active again');
    verifyArchive((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('PROGRAM_ARCHIVE','PROGRAM_RESTORE')")->fetch_row()[0]===2,'Audit events recorded');
} finally {
    foreach($clients as $client)$client->close();
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if($created && preg_match('/^csms_archive_http_[a-f0-9]{16}$/D',$name)) $db->query("DROP DATABASE `$name`");
    $db->close();
    $logText=file_get_contents($log);unlink($log);foreach(glob($sessions.'/sess_*') as $file)unlink($file);rmdir($sessions);
}
verifyArchive(!preg_match('/PHP (Warning|Fatal|Notice|Parse error)/',$logText),'No PHP errors');
echo "PASS: $checks isolated archive HTTP checks.\n";
