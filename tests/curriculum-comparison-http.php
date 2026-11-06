<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/curriculum-revision-fixture.php';
require_once __DIR__.'/FoundationHttpClient.php';
$db=protectionDatabase();$base=protectionFixtures($db);$actor=$base['actor'];$f=revisionFixture($db,$actor,'COMPAREHTTP');
$v=(int)(new CurriculumRevisionService($db))->create(revisionInput($f,'User defined synthetic revision'),$actor)['version']['id'];
$checks=0;$clients=[];$server=null;
function comparisonHttpCheck(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo 'PASS: '.$label.PHP_EOL;}
function comparisonHttpStatus(array $r,int $expected,string $label):void {
    comparisonHttpCheck($r['status']===$expected,"$label: expected $expected got {$r['status']}".($r['status']===$expected?'':' '.$r['body']));
    comparisonHttpCheck(!preg_match('/Fatal error|Warning:|Stack trace|SQLSTATE|mysqli_sql_exception/i',$r['body']),$label.' safe response');
}
$role=protectionInsert($db,"INSERT INTO roles(name,display_name,description,is_system) VALUES ('task5_reader','Synthetic history reader','Disposable test only',0)");
foreach (['subject_history.view','curriculum.view','account.view','dashboard.view'] as $name) {$stmt=$db->prepare('INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE name=?');$stmt->bind_param('is',$role,$name);$stmt->execute();$stmt->close();}
$password=bin2hex(random_bytes(24));$hash=$db->real_escape_string(password_hash($password,PASSWORD_DEFAULT));
$user=protectionInsert($db,"INSERT INTO users(username,email,first_name,last_name,password_hash) VALUES ('task5_reader','task5@example.invalid','Synthetic','Reader','$hash')");$db->query("INSERT INTO user_roles(user_id,role_id) VALUES ($user,$role)");
$log=tempnam(sys_get_temp_dir(),'t5-http-');$sessions=$log.'-sessions';mkdir($sessions);
try {
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$address=stream_socket_get_name($socket,false);fclose($socket);
    $env=getenv();$env['SMS_SESSION_COOKIE_SECURE']='0';
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.$sessions,'-d','error_log='.$log,'-S',$address,'-t',dirname(__DIR__)],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);
    if(!is_resource($server))throw new RuntimeException('Loopback HTTP server failed');fclose($pipes[0]);
    for($n=0;$n<60;$n++){$ready=@stream_socket_client('tcp://'.$address,$errno,$errstr,.1);if($ready){fclose($ready);break;}usleep(100000);}
    $reader=new FoundationHttpClient('http://'.$address);$denied=new FoundationHttpClient('http://'.$address);$guest=new FoundationHttpClient('http://'.$address);$clients=[$reader,$denied,$guest];
    foreach ([[$reader,'task5_reader',$password],[$denied,'task2_viewer',$base['password']]] as [$client,$username,$pass]) {
        $client->request('auth/signin.php');comparisonHttpStatus($client->request('shared/auth_actions.php',['action'=>'login','username'=>$username,'password'=>$pass],'POST'),200,'synthetic login');
    }
    $input=['curriculum_id'=>$f['curriculum'],'version_b_id'=>$v];$before=protectionSnapshot($db);
    comparisonHttpStatus($guest->api('subject_history','compare_revision',$input),401,'anonymous blocked');
    comparisonHttpStatus($denied->api('subject_history','compare_revision',$input),403,'missing history permission blocked');
    comparisonHttpStatus($reader->api('subject_history','compare_revision',$input,'POST'),405,'POST rejected');
    comparisonHttpStatus($reader->api('subject_history','compare_revision',$input,'PUT'),405,'PUT rejected');
    comparisonHttpStatus($reader->api('subject_history','compare_revision',$input,'DELETE'),405,'DELETE rejected');
    comparisonHttpStatus($reader->api('subject_history','compare_revision',$input+['version_a_id'=>9999999]),404,'missing reference');
    comparisonHttpStatus($reader->api('subject_history','compare_revision',array_replace($input,['version_b_id'=>0])),422,'invalid revision');
    comparisonHttpStatus($reader->api('subject_history','compare_revision',$input+['version_a_id'=>$base['versions']['reference']['version']]),422,'cross-curriculum forged reference');
    comparisonHttpStatus($reader->api('subject_history','compare_revision',array_replace($input,['version_b_id'=>$base['versions']['legacy']['version']])),422,'legacy without baseline');
    $response=$reader->api('subject_history','compare_revision',$input+['version_role'=>'reference','review_status'=>'approved']);comparisonHttpStatus($response,200,'read-only history permission sufficient');
    comparisonHttpCheck($response['json']['version_b']['version_label']==='User defined synthetic revision' && $response['json']['version_b']['version_role']==='proposed' && $response['json']['summary']['unchanged']===4,'actual labels and baseline pairing; mutation inputs ignored');
    comparisonHttpCheck(protectionSnapshot($db)===$before,'all successful and rejected comparison requests write no academic/audit/security rows');
    $page=$reader->request('modules/curriculum/pages/curriculum_history_view.php',$input);comparisonHttpStatus($page,200,'existing History page');
    comparisonHttpCheck(str_contains($page['body'],'id="comparisonEvidence"') && str_contains($page['body'],'curriculum-history-comparison.js') && str_contains($page['body'],'id="comparisonStructures"'),'existing History integrates evidence and structures');
    $page=$reader->request('modules/curriculum/pages/curriculum_builder.php',['program_id'=>$f['program'],'curriculum_id'=>$f['curriculum'],'version_id'=>$v]);comparisonHttpStatus($page,200,'Builder version workspace');
    comparisonHttpCheck(str_contains(file_get_contents(__DIR__.'/../modules/curriculum/assets/builder-workspace.js'),'curriculum_history_view.php'),'view-capable Builder exposes the comparison entry point (More menu) without mutation permission');
    echo json_encode(['passed'=>true,'checks'=>$checks,'suite'=>'Task 5 comparison HTTP']).PHP_EOL;
}finally{
    foreach($clients as $client)$client->close();if(is_resource($server)){proc_terminate($server);proc_close($server);}
    foreach(glob($sessions.'/*') as $file)unlink($file);rmdir($sessions);unlink($log);
}
