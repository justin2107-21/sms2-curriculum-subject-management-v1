<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/version-protection-fixture.php';require_once __DIR__.'/FoundationHttpClient.php';
$db=protectionDatabase();protectionFixtures($db);
$program=protectionInsert($db,"INSERT INTO academic_programs(program_code,program_name) VALUES ('T8HTTP','Synthetic Task 8 program')");
$curr=protectionInsert($db,"INSERT INTO curricula(curriculum_code,curriculum_name,academic_program_id) VALUES ('T8HTTP','Synthetic Task 8 curriculum',$program)");
$version=protectionInsert($db,"INSERT INTO curriculum_versions(curriculum_id,version_label) VALUES ($curr,'Synthetic editable version')");
$subject=protectionInsert($db,"INSERT INTO subjects(subject_code,subject_title) VALUES ('T8HTTP1','Synthetic HTTP subject')");
$placement=protectionInsert($db,"INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id) VALUES ($version,$subject)");
$db->query("INSERT INTO roles(name,display_name,description) VALUES ('t8_viewer','T8 viewer','Synthetic'),('t8_clo_editor','T8 CLO editor','Synthetic'),('t8_plan_editor','T8 plan editor','Synthetic')");
$roles=$db->query("SELECT id,name FROM roles WHERE name LIKE 't8_%' ORDER BY name")->fetch_all(MYSQLI_ASSOC);$role=[];foreach($roles as $r)$role[$r['name']]=(int)$r['id'];
$db->query("INSERT INTO role_permissions(role_id,permission_id) SELECT {$role['t8_viewer']},id FROM permissions WHERE name='curriculum.view'");
$db->query("INSERT INTO role_permissions(role_id,permission_id) SELECT {$role['t8_clo_editor']},id FROM permissions WHERE name IN ('curriculum.view','curriculum.clo.manage')");
$db->query("INSERT INTO role_permissions(role_id,permission_id) SELECT {$role['t8_plan_editor']},id FROM permissions WHERE name IN ('curriculum.view','curriculum.assessment_plan.manage')");
$password=bin2hex(random_bytes(20));$hash=password_hash($password,PASSWORD_DEFAULT);$users=[];
foreach(['t8viewer'=>'t8_viewer','t8clo'=>'t8_clo_editor','t8plan'=>'t8_plan_editor'] as $name=>$roleName){$email=$name.'@example.invalid';$stmt=$db->prepare("INSERT INTO users(username,email,first_name,last_name,password_hash) VALUES (?,?, 'Synthetic','Test',?)");$stmt->bind_param('sss',$name,$email,$hash);$stmt->execute();$user=(int)$db->insert_id;$stmt->close();$db->query("INSERT INTO user_roles(user_id,role_id) VALUES ($user,{$role[$roleName]})");$users[$name]=$user;}
$log=tempnam(sys_get_temp_dir(),'t8http-');$sessions=$log.'-sessions';mkdir($sessions);$sock=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$address=stream_socket_get_name($sock,false);fclose($sock);$env=getenv();$env['SMS_SESSION_COOKIE_SECURE']='0';$server=proc_open([PHP_BINARY,'-d','session.save_path='.$sessions,'-d','error_log='.$log,'-S',$address,'-t',dirname(__DIR__)],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true]);if(!is_resource($server))throw new RuntimeException('HTTP test server failed.');fclose($pipes[0]);$clients=[];
try{
    for($i=0;$i<60;$i++){$ready=@stream_socket_client('tcp://'.$address,$errno,$errstr,.1);if($ready){fclose($ready);break;}usleep(100000);}
    $viewer=new FoundationHttpClient('http://'.$address);$clo=new FoundationHttpClient('http://'.$address);$plan=new FoundationHttpClient('http://'.$address);$guest=new FoundationHttpClient('http://'.$address);$clients=[$viewer,$clo,$plan,$guest];
    if($guest->api('clo_assessment','workspace',['placement_id'=>$placement])['status']!==401)throw new RuntimeException('Guest CLO read was allowed.');
    foreach([[$viewer,'t8viewer'],[$clo,'t8clo'],[$plan,'t8plan']] as [$client,$name]){$client->request('auth/signin.php');$login=$client->request('shared/auth_actions.php',['action'=>'login','username'=>$name,'password'=>$password],'POST');if($login['status']!==200)throw new RuntimeException('Synthetic login failed.');$page=$client->request('modules/curriculum/pages/subject_mapping.php');if($page['status']!==200||!str_contains($page['body'],'cloAssessmentHeading')||!str_contains($page['body'],'data-clo-api='))throw new RuntimeException('Task 8 section is not integrated in Subject Mapping.');}
    $read=$viewer->api('clo_assessment','workspace',['placement_id'=>$placement]);if($read['status']!==200)throw new RuntimeException('Authorized viewer read denied.');
    $cloBody=['placement_id'=>$placement,'clo_code'=>'HTTP-CLO1','clo_statement'=>'Synthetic HTTP course learning outcome.','sequence_order'=>1];
    if($viewer->api('clo_assessment','clo_create',$cloBody,'POST')['status']!==403)throw new RuntimeException('Read-only viewer created a CLO.');
    if($clo->api('clo_assessment','clo_create',$cloBody,'POST','')['status']!==403)throw new RuntimeException('Missing CSRF accepted for CLO creation.');
    $getMutation=$clo->api('clo_assessment','clo_create',$cloBody);if($getMutation['status']!==405)throw new RuntimeException('GET CLO mutation returned '.$getMutation['status'].': '.$getMutation['body']);
    $created=$clo->api('clo_assessment','clo_create',$cloBody,'POST');if($created['status']!==201)throw new RuntimeException('Authorized CLO creation failed: '.$created['status'].' '.(string)$created['body']);$cloId=(int)$created['json']['clo_id'];
    $planBody=['placement_id'=>$placement,'clo_id'=>$cloId,'assessment_method'=>'Synthetic review','performance_indicator'=>'Synthetic criterion'];
    if($clo->api('clo_assessment','plan_add',$planBody,'POST')['status']!==403)throw new RuntimeException('CLO editor also received ungranted assessment-plan permission.');
    $planned=$plan->api('clo_assessment','plan_add',$planBody,'POST');if($planned['status']!==201)throw new RuntimeException('Authorized planned-assessment creation failed: '.$planned['status'].' '.(string)$planned['body']);
    $workspace=$viewer->api('clo_assessment','workspace',['placement_id'=>$placement]);if($workspace['status']!==200 || count($workspace['json']['clos'])!==1 || count($workspace['json']['plans'])!==1)throw new RuntimeException('Viewer cannot read committed Task 8 workspace.');
    if(array_column($db->query("SELECT DISTINCT r.name FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id JOIN roles r ON r.id=rp.role_id WHERE p.name IN ('curriculum.clo.manage','curriculum.assessment_plan.manage') AND r.name IN ('super_admin','admin','staff','acad_office','acad_faculty') ORDER BY r.name")->fetch_all(MYSQLI_ASSOC),'name')!==['staff','super_admin'])throw new RuntimeException('New high-impact permissions received broad default role grants.');
}finally{foreach($clients as $client)$client->close();if(is_resource($server)){proc_terminate($server);proc_close($server);}foreach([$log,$sessions] as $path){if(is_dir($path)){foreach(scandir($path) as $file)if($file!=='.'&&$file!=='..')@unlink($path.DIRECTORY_SEPARATOR.$file);@rmdir($path);}else @unlink($path);}}
echo "PASS: CLO/assessment HTTP authentication, independent RBAC, CSRF, POST-only mutations, authorized writes, and no broad default grants.\n";
