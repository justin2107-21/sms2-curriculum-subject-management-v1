<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/curriculum-revision-fixture.php';
require_once __DIR__.'/FoundationHttpClient.php';
$db=protectionDatabase();$base=protectionFixtures($db);$actor=$base['actor'];$f=revisionFixture($db,$actor,'HTTP');
$checks=0;$clients=[];$server=null;
function revisionHttpCheck(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo 'PASS: '.$label.PHP_EOL;}
function revisionHttpStatus(array $r,int $expected,string $label):void {
    revisionHttpCheck($r['status']===$expected,"$label: expected $expected got {$r['status']}" . ($r['status']===$expected?'':' '. $r['body']));
    revisionHttpCheck(!preg_match('/Fatal error|Warning:|Stack trace|SQLSTATE|mysqli_sql_exception/i',$r['body']),$label.' safe response');
}
// Deliberate grants to synthetic accounts only, never default or institutional roles.
$role=protectionInsert($db,"INSERT INTO roles(name,display_name,description,is_system) VALUES ('task4_clone','Synthetic revision editor','Disposable test only',0)");
$permissions=['curriculum.version.clone','curriculum.view','curriculum.update','subject.view','prerequisite.manage','prerequisite.view','elective.manage','elective.view','grade_weighting.manage','grade_weighting.view','dashboard.view','account.view','account.update','subject_history.view','curriculum_export.view'];
foreach($permissions as $name){$stmt=$db->prepare('INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE name=?');$stmt->bind_param('is',$role,$name);$stmt->execute();$stmt->close();}
$password=bin2hex(random_bytes(24));$hash=$db->real_escape_string(password_hash($password,PASSWORD_DEFAULT));
$user=protectionInsert($db,"INSERT INTO users(username,email,first_name,last_name,password_hash) VALUES ('task4_clone','task4@example.invalid','Synthetic','Editor','$hash')");
$db->query("INSERT INTO user_roles(user_id,role_id) VALUES ($user,$role)");
$log=tempnam(sys_get_temp_dir(),'t4-http-');$sessions=$log.'-sessions';mkdir($sessions);
try {
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$address=stream_socket_get_name($socket,false);fclose($socket);
    $env=getenv();$env['SMS_SESSION_COOKIE_SECURE']='0';
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.$sessions,'-d','error_log='.$log,'-S',$address,'-t',dirname(__DIR__)],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);
    if(!is_resource($server))throw new RuntimeException('Loopback HTTP server failed');fclose($pipes[0]);
    for($n=0;$n<60;$n++){$ready=@stream_socket_client('tcp://'.$address,$errno,$errstr,.1);if($ready){fclose($ready);break;}usleep(100000);}
    $editor=new FoundationHttpClient('http://'.$address);$ordinary=new FoundationHttpClient('http://'.$address);$guest=new FoundationHttpClient('http://'.$address);$clients=[$editor,$ordinary,$guest];
    foreach([[$editor,'task4_clone',$password],[$ordinary,'task2_admin',$base['password']]] as [$client,$username,$pass]) {
        $client->request('auth/signin.php');revisionHttpStatus($client->request('shared/auth_actions.php',['action'=>'login','username'=>$username,'password'=>$pass],'POST'),200,'synthetic login');$client->request('dashboard/account.php');
    }
    $input=revisionInput($f,'HTTP revision');$before=protectionSnapshot($db);
    revisionHttpStatus($guest->api('version','clone_reference',$input,'POST'),401,'anonymous clone');
    revisionHttpStatus($ordinary->api('version','clone_reference',$input,'POST'),403,'ordinary admin lacks explicit clone permission');
    revisionHttpStatus($editor->api('version','clone_reference',$input,'POST',''),403,'missing CSRF');
    revisionHttpStatus($editor->api('version','clone_reference',$input,'POST','invalid'),403,'invalid CSRF');
    revisionHttpStatus($editor->api('version','clone_reference',$input),405,'GET mutation rejected');
    revisionHttpStatus($ordinary->api('version','clone_preview',$input),403,'preview requires clone capability');
    revisionHttpCheck(protectionSnapshot($db)===$before,'unauthorized requests change no database rows');
    revisionHttpStatus($editor->api('version','clone_reference',array_replace($input,['version_label'=>'']),'POST'),422,'invalid label');
    revisionHttpStatus($editor->api('version','clone_reference',array_replace($input,['curriculum_id'=>$base['versions']['legacy']['curriculum']]),'POST'),409,'wrong curriculum');
    revisionHttpStatus($editor->api('version','clone_reference',array_replace($input,['source_version_id'=>9999999]),'POST'),404,'missing source');
    foreach(['legacy','draft','under_review','approved'] as $state) revisionHttpStatus($editor->api('version','clone_reference',revisionInput($base['versions'][$state],'Invalid '.$state),'POST'),409,'ineligible '.$state);
    revisionHttpCheck(protectionSnapshot($db)===$before,'failed validation leaves all rows unchanged');
    $preview=$editor->api('version','clone_preview',$input);revisionHttpStatus($preview,200,'eligible preview');
    revisionHttpCheck($preview['json']['preview']['counts']['requirements']===4 && $preview['json']['preview']['counts']['components']===2,'preview includes optional copy counts');
    $digest=revisionSourceDigest($db,$f);
    $response=$editor->api('version','clone_reference',$input+['version_role'=>'reference','review_status'=>'approved','status'=>'active','baseline_version_id'=>9999999,'compliance_reference_id'=>9999999],'POST');
    revisionHttpStatus($response,201,'authorized clone');$v=(int)$response['json']['version']['id'];
    revisionHttpCheck($response['json']['version']['version_role']==='proposed' && $response['json']['version']['review_status']==='not_started' && $response['json']['version']['status']==='draft' && (int)$response['json']['version']['baseline_version_id']===$f['version'],'forged lifecycle/source fields cannot override');
    $w=$editor->api('version_reference','workspace',['curriculum_version_id'=>$v]);revisionHttpStatus($w,200,'new workspace');
    revisionHttpCheck($w['json']['workspace']['editable']===true && $w['json']['workspace']['references'][0]['reference_title_snapshot']==='Synthetic document title for revision tests' && $w['json']['workspace']['references'][0]['source_kind_snapshot']==='ched_sample_curriculum' && $w['json']['workspace']['institutional_adoption_label']==='Institutional adoption/approval not verified','actual title, sample kind, editable proposal and unverified adoption returned');
    $placement=$db->query("SELECT id FROM curriculum_version_subjects WHERE curriculum_version_id=$v AND subject_id={$f['subjects'][3]}")->fetch_row()[0];
    revisionHttpStatus($editor->api('assignment','update_mapping',['id'=>$placement,'year_level'=>'2nd Year','semester'=>'1st Semester','sequence_order'=>40,'is_required'=>0],'POST'),200,'new proposal mapping editable');
    revisionHttpStatus($editor->api('requirement','update_rule',['curriculum_version_id'=>$v,'subject_id'=>$f['subjects'][3],'expected_ids'=>[],'required_subject_ids'=>[$f['subjects'][0]]],'POST'),200,'new proposal prerequisite editable');
    $group=$db->query("SELECT id FROM curriculum_elective_groups WHERE curriculum_version_id=$v")->fetch_row()[0];
    revisionHttpStatus($editor->api('elective_group','update',['id'=>$group,'program_id'=>$f['program'],'curriculum_id'=>$f['curriculum'],'curriculum_version_id'=>$v,'group_name'=>'HTTP revision elective','year_level'=>'2nd Year','semester'=>'1st Semester','required_selections'=>2,'subject_ids'=>[$f['subjects'][2],$f['subjects'][3]]],'POST'),200,'new proposal elective editable');
    $p0=$db->query("SELECT id FROM curriculum_version_subjects WHERE curriculum_version_id=$v AND subject_id={$f['subjects'][0]}")->fetch_row()[0];
    revisionHttpStatus($editor->api('grade_weighting','configuration_save',['curriculum_version_subject_id'=>$p0,'curriculum_version_id'=>$v,'components'=>[['component_name'=>'New configuration','weight_percentage'=>'100']]],'POST'),200,'new proposal weighting editable');
    revisionHttpStatus($editor->api('assignment','update_mapping',['id'=>$f['placements'][3],'year_level'=>'2nd Year','semester'=>'1st Semester','is_required'=>0],'POST'),409,'reference remains protected');
    revisionHttpStatus($editor->api('subject_history','list',['curriculum_version_id'=>$v]),200,'snapshot-aware history still readable');
    revisionHttpStatus($editor->api('export','preview',['academic_program_id'=>$f['program'],'curriculum_id'=>$f['curriculum'],'curriculum_version_id'=>$v]),200,'new revision export preview');
    revisionHttpCheck(revisionSourceDigest($db,$f)===$digest,'HTTP clone and later edits preserve source and global data');
    $params=['program_id'=>$f['program'],'curriculum_id'=>$f['curriculum'],'version_id'=>$f['version']];
    $page=$editor->request('modules/curriculum/pages/curricula.php',$params);revisionHttpStatus($page,200,'Builder creation form');
    revisionHttpCheck(str_contains($page['body'],'id="cloneReferenceForm"') && str_contains($page['body'],'Create Curriculum Revision'),'authorized Builder contains existing-style compact form');
    $page=$ordinary->request('modules/curriculum/pages/curricula.php',$params);revisionHttpCheck(!str_contains($page['body'],'id="cloneReferenceForm"'),'ordinary admin has no clone form');
    echo json_encode(['passed'=>true,'checks'=>$checks,'suite'=>'Task 4 revision HTTP'],JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    foreach($clients as $client)$client->close();if(is_resource($server)){proc_terminate($server);proc_close($server);}
    foreach(glob($sessions.'/*') as $file)unlink($file);rmdir($sessions);unlink($log);
}
