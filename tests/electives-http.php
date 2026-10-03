<?php
// CLI-only HTTP regression against a random disposable local schema and loopback PHP server.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../shared/database_config.php';
require_once __DIR__.'/FoundationHttpClient.php';
$config=csms_database_config();
if(!in_array($config['host'],['localhost','127.0.0.1'],true)||getenv('APP_ENV')==='production')throw new RuntimeException('Local development only.');
$db=csms_database_connect($config);$source=$config['database'];
if(!preg_match('/^[A-Za-z0-9_]+$/D',$source))throw new RuntimeException('Unsafe source schema');
$name='csms_elective_test_'.bin2hex(random_bytes(8));$server=null;$clients=[];$checks=0;$created=false;
$envBefore=getenv();$log=tempnam(sys_get_temp_dir(),'el-http-');$sessionDir=$log.'-sessions';mkdir($sessionDir);
function checkHttp(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function status(array $r,int $expected,string $label):array{checkHttp($r['status']===$expected,$label.' expected '.$expected.' received '.$r['status'].' '.$r['body']);checkHttp(!preg_match('/Fatal error|Warning:|Stack trace|mysqli_sql_exception/',$r['body']),$label.' safe response');return $r['json']??[];}
try{
 $db->query("CREATE DATABASE `$name`");$created=true;
 foreach(array_column($db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM),0) as $table) {
  if (!preg_match('/^[A-Za-z0-9_]+$/D',$table)) throw new RuntimeException('Unsafe table name');
  $db->query("CREATE TABLE `$name`.`$table` LIKE `$source`.`$table`");
 }
 $db->select_db($name);
 foreach(['roles','permissions','role_permissions'] as $table)$db->query("INSERT INTO `$table` SELECT * FROM `$source`.`$table`");
 $password=bin2hex(random_bytes(24));$hash=password_hash($password,PASSWORD_DEFAULT);
 foreach(['pradmin','prviewer']as $i=>$username){$id=$i+1;$stmt=$db->prepare("INSERT INTO users(id,username,email,first_name,last_name,password_hash,role) VALUES(?,?,?,'Test','Only',?,'student')");$email=$username.'@example.invalid';$stmt->bind_param('isss',$id,$username,$email,$hash);$stmt->execute();}
 $db->query("INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE name='admin'");
 $db->query("INSERT INTO roles(name,description) VALUES('pr_test_viewer','Disposable test fixture')");$viewerRole=(int)$db->insert_id;
 $db->query("INSERT INTO user_roles(user_id,role_id) VALUES(2,$viewerRole)");
 $db->query("INSERT INTO role_permissions(role_id,permission_id) SELECT $viewerRole,id FROM permissions WHERE name IN ('elective.view')");
 $db->query("INSERT INTO academic_programs(id,program_code,program_name) VALUES(1,'T','Test')");
 $db->query("INSERT INTO curricula(id,curriculum_code,curriculum_name,academic_program_id) VALUES(1,'T','Test',1)");
 $db->query("INSERT INTO curriculum_versions(id,curriculum_id,version_label) VALUES(1,1,'V')");
 foreach(range(1,4)as $id){$db->query("INSERT INTO subjects(id,subject_code,subject_title) VALUES($id,'T$id','Test $id')");$db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES(1,$id,'".($id===2?'1st Year':'1st Year')."','1st Semester')");}
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$address=stream_socket_get_name($socket,false);fclose($socket);
 $env=$envBefore;$env['DB_NAME']=$name;$env['APP_ENV']='development';$env['SMS_SESSION_COOKIE_SECURE']='0';
 $server=proc_open([PHP_BINARY,'-d','session.save_path='.$sessionDir,'-d','error_log='.$log,'-S',$address,'-t',dirname(__DIR__)],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);
 if(!is_resource($server))throw new RuntimeException('Test HTTP server failed');fclose($pipes[0]);
 for($i=0;$i<60;$i++){$ready=@stream_socket_client('tcp://'.$address,$errno,$errstr,.1);if($ready){fclose($ready);break;}usleep(100000);}
 $admin=new FoundationHttpClient('http://'.$address);$viewer=new FoundationHttpClient('http://'.$address);$guest=new FoundationHttpClient('http://'.$address);$clients=[$admin,$viewer,$guest];
 $context=['program_id'=>1,'curriculum_id'=>1,'curriculum_version_id'=>1];
 status($guest->api('elective_group','workspace',$context),401,'Unauthenticated workspace');
 foreach([[$admin,'pradmin'],[$viewer,'prviewer']] as [$client,$username]){
  $client->request('auth/signin.php');status($client->request('shared/auth_actions.php',['action'=>'login','username'=>$username,'password'=>$password],'POST'),200,'Fixture login');
  $page=$client->request('modules/curriculum/pages/electives_manager.php');status($page,200,'PHP page renders');
  checkHttp(substr_count($page['body'],'role="tab"')===2,'Exactly two tabs');checkHttp(!str_contains($page['body'],'CURRENT ELECTIVES CONTEXT'),'No redundant context summary');
 }
 $page=$viewer->request('modules/curriculum/pages/electives_manager.php');checkHttp(!str_contains($page['body'],'id="createElectiveGroupButton"'),'Viewer cannot see create control');
 status($viewer->api('elective_group','workspace',$context),200,'Elective-only viewer reads workspace');
 status($viewer->api('elective_group','context_options',['level'=>'program']),200,'Elective-only viewer reads context');
 $input=$context+['group_name'=>'Group','year_level'=>'1st Year','semester'=>'1st Semester','required_selections'=>1,'subject_ids'=>[1,2]];
 foreach(['create','update','archive','remove']as $action){
  status($viewer->api('elective_group',$action,$input+['id'=>1],'POST'),403,'Manage permission '.$action);
  status($admin->api('elective_group',$action,$input+['id'=>1],'POST',''),403,'CSRF '.$action);
  status($admin->api('elective_group',$action,$input+['id'=>1]),405,'No GET mutation '.$action);
 }
 foreach(['add','remove']as $action){status($viewer->api('elective_membership',$action,['id'=>1],'POST'),403,'Legacy membership permission');status($admin->api('elective_membership',$action,['id'=>1],'POST'),422,'Legacy membership cannot bypass validation');}
 $result=status($admin->api('elective_group','create',$input,'POST'),201,'Create group');$id=$result['group']['id'];
 status($admin->api('elective_group','create',$input,'POST'),409,'Duplicate group name');
 foreach([['subject_ids'=>[1,1]],['required_selections'=>3],['required_selections'=>0],['required_selections'=>'bad'],['curriculum_version_id'=>0],['program_id'=>999],['subject_ids'=>[999]],['year_level'=>'4th Year']]as $bad)status($admin->api('elective_group','create',array_replace($input,$bad),'POST'),422,'Invalid write blocked');
 status($admin->api('elective_group','workspace',array_replace($context,['curriculum_id'=>999])),422,'Invalid read context');
 status($admin->api('elective_group','update',array_replace($input,['id'=>$id,'group_name'=>'Updated','subject_ids'=>[1]]),'POST'),200,'Edit atomic membership');
 $workspace=status($admin->api('elective_group','workspace',$context),200,'Workspace review');checkHttp(count($workspace['groups'][0]['members'])===1&&array_sum($workspace['review']['counts'])===0,'Membership and review agree');
 status($admin->api('elective_group','remove',$context+['id'=>$id],'POST'),422,'Hard delete disabled');
 status($admin->api('elective_group','archive',$context+['id'=>$id],'POST'),200,'Archive group');
 checkHttp($db->query("SELECT status FROM curriculum_elective_groups WHERE id=$id")->fetch_row()[0]==='archived','Archived row preserved');
 checkHttp((int)$db->query('SELECT COUNT(*) FROM curriculum_elective_group_subjects')->fetch_row()[0]===1,'Archive preserves membership');
 checkHttp((int)$db->query('SELECT COUNT(*) FROM subjects')->fetch_row()[0]===4,'Subject Master unchanged');
 checkHttp((int)$db->query('SELECT COUNT(*) FROM curriculum_version_subjects')->fetch_row()[0]===4,'Subject Mapping unchanged');
 foreach(['ELECTIVE_GROUP_CREATE','ELECTIVE_GROUP_UPDATE','ELECTIVE_GROUP_ARCHIVE','ELECTIVE_GROUP_SUBJECT_ADD','ELECTIVE_GROUP_SUBJECT_REMOVE']as $action)checkHttp((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='$action'")->fetch_row()[0]>0,'HTTP audit '.$action);
 $db->query("DELETE FROM role_permissions WHERE role_id=$viewerRole");status($viewer->api('elective_group','workspace',$context),403,'Read permission enforced server-side');
 if(in_array('--foundation',$argv,true)) {
  $foundationEnv=$env;$foundationEnv['SMS_TEST_BASE_URL']='http://'.$address;$foundationEnv['SMS_TEST_CONFIRM_DATABASE']=$name;$foundationEnv['SMS_TEST_ADMIN_USER']='pradmin';$foundationEnv['SMS_TEST_ADMIN_PASSWORD']=$password;
  $foundation=proc_open([PHP_BINARY,__DIR__.'/foundation.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$foundationPipes,dirname(__DIR__),$foundationEnv,['bypass_shell'=>true,'create_new_console'=>false]);
  fclose($foundationPipes[0]);$output=stream_get_contents($foundationPipes[1]);$errors=stream_get_contents($foundationPipes[2]);fclose($foundationPipes[1]);fclose($foundationPipes[2]);$exit=proc_close($foundation);
  echo $output; if($errors)fwrite(STDERR,$errors);checkHttp($exit===0,'Full foundation suite');
 }
}finally{
 foreach($clients as $c)$c->close();
 if(is_resource($server)){proc_terminate($server);proc_close($server);}
 if($created&&preg_match('/^csms_elective_test_[a-f0-9]{16}$/D',$name))$db->query("DROP DATABASE `$name`");
 $db->close();
 $logText=file_get_contents($log);unlink($log);foreach(glob($sessionDir.'/sess_*')as $file)unlink($file);rmdir($sessionDir);
}
checkHttp(!preg_match('/PHP (Warning|Fatal|Notice|Parse error)/',$logText),'No PHP errors in HTTP server log');
echo "PASS: $checks isolated HTTP checks: authenticated page, view/manage RBAC, CSRF, no GET mutations, create/update/archive, validation, audits; disposable server and schema removed.\n";
