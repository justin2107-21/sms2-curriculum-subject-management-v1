<?php
// CLI-only HTTP regression against a random disposable local schema and loopback PHP server.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../shared/database_config.php';
require_once __DIR__.'/FoundationHttpClient.php';
$config=csms_database_config();
if(!in_array($config['host'],['localhost','127.0.0.1'],true)||getenv('APP_ENV')==='production')throw new RuntimeException('Local development only.');
$db=csms_database_connect($config);$source=$config['database'];
if(!preg_match('/^[A-Za-z0-9_]+$/D',$source))throw new RuntimeException('Unsafe source schema');
$name='csms_prereq_test_'.bin2hex(random_bytes(8));$server=null;$clients=[];$checks=0;$created=false;
$envBefore=getenv();$log=tempnam(sys_get_temp_dir(),'pr-http-');$sessionDir=$log.'-sessions';mkdir($sessionDir);
function checkHttp(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function status(array $r,int $expected,string $label):array{checkHttp($r['status']===$expected,$label.' expected '.$expected.' received '.$r['status'].' '.$r['body']);checkHttp(!preg_match('/Fatal error|Warning:|Stack trace|mysqli_sql_exception/',$r['body']),$label.' safe response');return $r['json']??[];}
try{
 $db->query("CREATE DATABASE `$name`");$created=true;
 foreach(['users','roles','permissions','user_roles','role_permissions','audit_logs','academic_programs','curricula','curriculum_versions','subjects','curriculum_version_subjects','curriculum_subject_requirements'] as $table)$db->query("CREATE TABLE `$name`.`$table` LIKE `$source`.`$table`");
 $db->select_db($name);
 foreach(['roles','permissions','role_permissions'] as $table)$db->query("INSERT INTO `$table` SELECT * FROM `$source`.`$table`");
 $password=bin2hex(random_bytes(24));$hash=password_hash($password,PASSWORD_DEFAULT);
 foreach(['pradmin','prviewer']as $i=>$username){$id=$i+1;$stmt=$db->prepare("INSERT INTO users(id,username,email,first_name,last_name,password_hash,role) VALUES(?,?,?,'Test','Only',?,'student')");$email=$username.'@example.invalid';$stmt->bind_param('isss',$id,$username,$email,$hash);$stmt->execute();}
 $db->query("INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE name='super_admin'");
 $db->query("INSERT INTO roles(name,display_name,description) VALUES('pr_test_viewer','Synthetic Viewer','Disposable test fixture')");$viewerRole=(int)$db->insert_id;
 $db->query("INSERT INTO user_roles(user_id,role_id) VALUES(2,$viewerRole)");
 $db->query("INSERT INTO role_permissions(role_id,permission_id) SELECT $viewerRole,id FROM permissions WHERE name IN ('prerequisite.view','program.view','curriculum.view','curriculum.view_unapproved')");
 $db->query("INSERT INTO academic_programs(id,program_code,program_name) VALUES(1,'T','Test')");
 $db->query("INSERT INTO curricula(id,curriculum_code,curriculum_name,academic_program_id) VALUES(1,'T','Test',1)");
 $db->query("INSERT INTO curriculum_versions(id,curriculum_id,version_label) VALUES(1,1,'V')");
 foreach(range(1,4)as $id){$db->query("INSERT INTO subjects(id,subject_code,subject_title) VALUES($id,'T$id','Test $id')");$db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES(1,$id,'".($id===2?'3rd Year':'1st Year')."','1st Semester')");}
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$address=stream_socket_get_name($socket,false);fclose($socket);
 $env=$envBefore;$env['DB_NAME']=$name;$env['APP_ENV']='development';$env['SMS_SESSION_COOKIE_SECURE']='0';$env['CIMF_AI_ENABLED']='false';
 $server=proc_open([PHP_BINARY,'-d','session.save_path='.$sessionDir,'-d','error_log='.$log,'-S',$address,'-t',dirname(__DIR__)],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);
 if(!is_resource($server))throw new RuntimeException('Test HTTP server failed');fclose($pipes[0]);
 for($i=0;$i<60;$i++){$ready=@stream_socket_client('tcp://'.$address,$errno,$errstr,.1);if($ready){fclose($ready);break;}usleep(100000);}
 $admin=new FoundationHttpClient('http://'.$address);$viewer=new FoundationHttpClient('http://'.$address);$guest=new FoundationHttpClient('http://'.$address);$clients=[$admin,$viewer,$guest];
 status($guest->api('requirement','workspace',['curriculum_version_id'=>1]),401,'Unauthenticated workspace');
 foreach([[$admin,'pradmin'],[$viewer,'prviewer']] as [$client,$username]){$client->request('auth/signin.php');status($client->request('shared/auth_actions.php',['action'=>'login','username'=>$username,'password'=>$password],'POST'),200,'Fixture login');$page=$client->request('modules/curriculum/pages/prerequisite_configuration.php');status($page,200,'Rendered PHP page');checkHttp(str_contains($page['body'],'Validation Review'),'Three-tab page served');}
 $page=$viewer->request('modules/curriculum/pages/prerequisite_configuration.php');checkHttp(!str_contains($page['body'],'id="addRequirementButton"'),'Read-only page excludes management controls');
 checkHttp(str_contains($page['body'],'id="csmsCimfPanel"') && str_contains($page['body'],'js/cimf-panel.js'),'Global CIMF panel leaves prerequisite page available');
 status($guest->api('cimf','prerequisite_review',['curriculum_version_id'=>1],'POST'),401,'CIMF requires authentication');
 status($viewer->api('cimf','prerequisite_review',['curriculum_version_id'=>1]),405,'CIMF requires POST');
 status($viewer->api('cimf','prerequisite_review',['curriculum_version_id'=>1],'POST',''),403,'CIMF requires CSRF');
 $retired=status($viewer->api('cimf','prerequisite_review',['curriculum_version_id'=>1],'POST'),410,'Legacy modal endpoint retired');
 checkHttp(($retired['status']??'')==='LEGACY_INTERFACE_RETIRED','Retired endpoint directs clients to global panel');
 $dom=new DOMDocument();@$dom->loadHTML($page['body']);$panel=(new DOMXPath($dom))->query('//*[@id="csmsCimfPanel"]')->item(0);
 $global=['operation'=>'analyze','profile'=>$panel->getAttribute('data-profile'),'route'=>$panel->getAttribute('data-route'),'page_token'=>$panel->getAttribute('data-page-token'),'action'=>'prerequisite_review','context'=>json_encode(['academic_program_id'=>1,'curriculum_id'=>1,'curriculum_version_id'=>1])];
 status($guest->request('modules/curriculum/http/global_cimf_actions.php',$global,'POST'),401,'Global CIMF authentication');
 status($viewer->request('modules/curriculum/http/global_cimf_actions.php',$global),405,'Global CIMF POST');
 status($viewer->request('modules/curriculum/http/global_cimf_actions.php',$global,'POST',''),403,'Global CIMF CSRF');
 status($viewer->request('modules/curriculum/http/global_cimf_actions.php',array_replace($global,['page_token'=>'forged']),'POST'),422,'Global CIMF page binding');
 $disabled=status($viewer->request('modules/curriculum/http/global_cimf_actions.php',$global,'POST'),503,'Global CIMF disabled');
 checkHttp(($disabled['status']??'')==='AI_DISABLED','Provider-aware disabled status');
 $academicBefore=$db->query('SELECT * FROM curriculum_version_subjects ORDER BY id')->fetch_all(MYSQLI_ASSOC);
 status($viewer->request('modules/curriculum/http/global_cimf_actions.php',array_replace($global,['operation'=>'context']),'POST'),200,'Global CIMF read-only context');
 checkHttp($db->query('SELECT * FROM curriculum_version_subjects ORDER BY id')->fetch_all(MYSQLI_ASSOC)===$academicBefore,'CIMF context preserves placements');
 status($viewer->api('requirement','workspace',['curriculum_version_id'=>1]),200,'Viewer reads rules');
 $input=['curriculum_version_id'=>1,'subject_id'=>2,'required_subject_id'=>1,'requirement_type'=>'prerequisite'];
 foreach(['create','update_rule','remove']as $action){status($viewer->api('requirement',$action,$input+['id'=>1],'POST'),403,'Viewer denied '.$action);status($admin->api('requirement',$action,$input+['id'=>1],'POST',''),403,'CSRF denied '.$action);status($admin->api('requirement',$action,$input+['id'=>1]),405,'GET denied '.$action);}
 $a=status($admin->api('requirement','create',$input,'POST'),201,'Create prerequisite');$id=$a['relationship']['id'];
 status($admin->api('requirement','create',$input,'POST'),409,'Duplicate HTTP rejection');
 status($admin->api('requirement','create',array_replace($input,['subject_id'=>1]),'POST'),422,'Self HTTP rejection');
 status($admin->api('requirement','create',array_replace($input,['subject_id'=>1,'required_subject_id'=>2]),'POST'),422,'Cycle HTTP rejection');
 status($admin->api('requirement','update_rule',['curriculum_version_id'=>1,'subject_id'=>2,'required_subject_ids'=>[1,3],'expected_ids'=>[$id]],'POST'),200,'Atomic multi-rule update');
 $workspace=status($admin->api('requirement','workspace',['curriculum_version_id'=>1]),200,'Workspace HTTP');checkHttp(count($workspace['relationships'])===2,'Two prerequisites visible over HTTP');
  $positions=array_column($workspace['placements'],'period_position','subject_id');checkHttp(count($positions)===4&&!in_array(null,$positions,true)&&count(array_unique($positions))>=1,'Workspace placements carry a numeric period_position');
 $statuses=array_column($workspace['placements'],'subject_status','subject_id');checkHttp(count($statuses)===4&&count(array_unique($statuses))===1&&$statuses[1]==='active','Workspace placements carry subject_status (active by default)');
 $db->query("UPDATE subjects SET status='inactive' WHERE id=3");$inactive=status($admin->api('requirement','workspace',['curriculum_version_id'=>1]),200,'Workspace with a non-active subject');$statusMap=array_column($inactive['placements'],'subject_status','subject_id');checkHttp($statusMap[3]==='inactive'&&$statusMap[1]==='active','subject_status reports the real subject status');$db->query("UPDATE subjects SET status='active' WHERE id=3");
 $db->query("UPDATE curriculum_version_subjects SET semester='Summer' WHERE subject_id=4");$odd=status($admin->api('requirement','workspace',['curriculum_version_id'=>1]),200,'Workspace with unverifiable term');$oddPositions=array_column($odd['placements'],'period_position','subject_id');checkHttp($oddPositions[4]===null,'Unknown term gives a null period_position');
 $db->query("UPDATE curriculum_version_subjects SET semester=(SELECT s FROM (SELECT semester s FROM curriculum_version_subjects WHERE subject_id=3) t) WHERE subject_id=4");
 status($admin->api('requirement','remove',['id'=>$id],'POST'),200,'Confirmed edge removal');
 checkHttp((int)$db->query('SELECT COUNT(*) FROM subjects')->fetch_row()[0]===4,'HTTP mutations preserve subjects');checkHttp((int)$db->query('SELECT COUNT(*) FROM curriculum_version_subjects')->fetch_row()[0]===4,'HTTP mutations preserve placements');
 foreach(['SUBJECT_REQUIREMENT_CREATE','SUBJECT_REQUIREMENT_UPDATE','SUBJECT_REQUIREMENT_REMOVE']as $action)checkHttp((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='$action'")->fetch_row()[0]>0,'HTTP audit '.$action);
 $db->query("DELETE FROM role_permissions WHERE role_id=$viewerRole");status($viewer->api('requirement','workspace',['curriculum_version_id'=>1]),403,'View permission enforced server-side');
}finally{
 foreach($clients as $c)$c->close();
 if(is_resource($server)){proc_terminate($server);proc_close($server);}
 if($created&&preg_match('/^csms_prereq_test_[a-f0-9]{16}$/D',$name))$db->query("DROP DATABASE `$name`");
 $db->close();
 $logText=file_get_contents($log);unlink($log);foreach(glob($sessionDir.'/sess_*')as $file)unlink($file);rmdir($sessionDir);
}
checkHttp(!preg_match('/PHP (Warning|Fatal|Notice|Parse error)/',$logText),'No PHP errors in HTTP server log');
echo "PASS: $checks isolated HTTP checks: authenticated page, view/manage RBAC, CSRF, no GET mutations, create/update/remove, validation, audits; disposable server and schema removed.\n";
