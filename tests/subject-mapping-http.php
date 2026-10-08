<?php
// CLI-only HTTP regression against a random disposable local schema and loopback PHP server.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../shared/database_config.php';
require_once __DIR__.'/FoundationHttpClient.php';
$config=csms_database_config();
if(!in_array($config['host'],['localhost','127.0.0.1'],true)||getenv('APP_ENV')==='production')throw new RuntimeException('Local development only.');
$db=csms_database_connect($config);$source=$config['database'];
if(!preg_match('/^[A-Za-z0-9_]+$/D',$source))throw new RuntimeException('Unsafe source schema');
$name='csms_mapping_test_'.bin2hex(random_bytes(8));$server=null;$clients=[];$checks=0;$created=false;
$envBefore=getenv();$log=tempnam(sys_get_temp_dir(),'pr-http-');$sessionDir=$log.'-sessions';mkdir($sessionDir);
function checkHttp(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function status(array $r,int $expected,string $label):array{checkHttp($r['status']===$expected,$label.' expected '.$expected.' received '.$r['status'].' '.$r['body']);checkHttp(!preg_match('/Fatal error|Warning:|Stack trace|mysqli_sql_exception/',$r['body']),$label.' safe response');return $r['json']??[];}
try{
 $db->query("CREATE DATABASE `$name`");$created=true;
 foreach(['users','roles','permissions','user_roles','role_permissions','audit_logs','academic_programs','curricula','curriculum_versions','subjects','curriculum_version_subjects','curriculum_subject_requirements','curriculum_elective_group_subjects','grade_weighting_schemes','curriculum_version_subject_snapshots'] as $table)$db->query("CREATE TABLE `$name`.`$table` LIKE `$source`.`$table`");
 $db->select_db($name);
 foreach(['roles','permissions','role_permissions'] as $table)$db->query("INSERT INTO `$table` SELECT * FROM `$source`.`$table`");
 $password=bin2hex(random_bytes(24));$hash=password_hash($password,PASSWORD_DEFAULT);
 foreach(['pradmin','prviewer']as $i=>$username){$id=$i+1;$stmt=$db->prepare("INSERT INTO users(id,username,email,first_name,last_name,password_hash,role) VALUES(?,?,?,'Test','Only',?,'student')");$email=$username.'@example.invalid';$stmt->bind_param('isss',$id,$username,$email,$hash);$stmt->execute();}
 $db->query("INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE name='admin'");
 $db->query("INSERT INTO roles(name,display_name,description) VALUES('pr_test_viewer','Synthetic Viewer','Disposable test fixture')");$viewerRole=(int)$db->insert_id;
 $db->query("INSERT INTO user_roles(user_id,role_id) VALUES(2,$viewerRole)");
 $db->query("INSERT INTO role_permissions(role_id,permission_id) SELECT $viewerRole,id FROM permissions WHERE name IN ('prerequisite.view','program.view','curriculum.view')");
 $db->query("INSERT INTO academic_programs(id,program_code,program_name) VALUES(1,'T','Test')");
 $db->query("INSERT INTO curricula(id,curriculum_code,curriculum_name,academic_program_id) VALUES(1,'T','Test',1)");
 $db->query("INSERT INTO curriculum_versions(id,curriculum_id,version_label) VALUES(1,1,'V')");
 foreach(range(1,4)as $id){$db->query("INSERT INTO subjects(id,subject_code,subject_title) VALUES($id,'T$id','Test $id')");$db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES(1,$id,'".($id===2?'3rd Year':'1st Year')."','1st Semester')");}
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$address=stream_socket_get_name($socket,false);fclose($socket);
 $env=$envBefore;$env['DB_NAME']=$name;$env['APP_ENV']='development';$env['SMS_SESSION_COOKIE_SECURE']='0';
 $server=proc_open([PHP_BINARY,'-d','session.save_path='.$sessionDir,'-d','error_log='.$log,'-S',$address,'-t',dirname(__DIR__)],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);
 if(!is_resource($server))throw new RuntimeException('Test HTTP server failed');fclose($pipes[0]);
 for($i=0;$i<60;$i++){$ready=@stream_socket_client('tcp://'.$address,$errno,$errstr,.1);if($ready){fclose($ready);break;}usleep(100000);}
 $admin=new FoundationHttpClient('http://'.$address);$viewer=new FoundationHttpClient('http://'.$address);$guest=new FoundationHttpClient('http://'.$address);$clients=[$admin,$viewer,$guest];
 status($guest->api('requirement','workspace',['curriculum_version_id'=>1]),401,'Unauthenticated workspace');
 foreach([[$admin,'pradmin'],[$viewer,'prviewer']] as [$client,$username]){$client->request('auth/signin.php');status($client->request('shared/auth_actions.php',['action'=>'login','username'=>$username,'password'=>$password],'POST'),200,'Fixture login');$page=$client->request('modules/curriculum/pages/prerequisite_configuration.php');status($page,200,'Rendered PHP page');checkHttp(str_contains($page['body'],'Validation Review'),'Three-tab page served');}
 $page=$viewer->request('modules/curriculum/pages/prerequisite_configuration.php');checkHttp(!str_contains($page['body'],'id="addRequirementButton"'),'Read-only page excludes management controls');
 status($viewer->api('requirement','workspace',['curriculum_version_id'=>1]),200,'Viewer reads rules');
 $input=['curriculum_version_id'=>1,'subject_id'=>2,'required_subject_id'=>1,'requirement_type'=>'prerequisite'];
 foreach(['create','update_rule','remove']as $action){status($viewer->api('requirement',$action,$input+['id'=>1],'POST'),403,'Viewer denied '.$action);status($admin->api('requirement',$action,$input+['id'=>1],'POST',''),403,'CSRF denied '.$action);status($admin->api('requirement',$action,$input+['id'=>1]),405,'GET denied '.$action);}
 $a=status($admin->api('requirement','create',$input,'POST'),201,'Create prerequisite');$id=$a['relationship']['id'];
 status($admin->api('requirement','create',$input,'POST'),409,'Duplicate HTTP rejection');
 status($admin->api('requirement','create',array_replace($input,['subject_id'=>1]),'POST'),422,'Self HTTP rejection');
 status($admin->api('requirement','create',array_replace($input,['subject_id'=>1,'required_subject_id'=>2]),'POST'),422,'Cycle HTTP rejection');
 status($admin->api('requirement','update_rule',['curriculum_version_id'=>1,'subject_id'=>2,'required_subject_ids'=>[1,3],'expected_ids'=>[$id]],'POST'),200,'Atomic multi-rule update');
 $workspace=status($admin->api('requirement','workspace',['curriculum_version_id'=>1]),200,'Workspace HTTP');checkHttp(count($workspace['relationships'])===2,'Two prerequisites visible over HTTP');
 $original=$db->query('SELECT year_level,semester FROM curriculum_version_subjects WHERE id=1')->fetch_assoc();
 $invalidMove=['id'=>1,'year_level'=>'3rd Year','semester'=>'2nd Semester','is_required'=>'1'];
 $preview=status($admin->api('assignment','preview_placement',$invalidMove),200,'Preview outgoing dependency');
 checkHttp(!$preview['validation']['valid'] && $preview['validation']['severity']==='block','Preview blocks invalid sequence');
 status($admin->api('assignment','update_mapping',$invalidMove,'POST'),422,'JS bypass outgoing placement rejected');
 status($admin->api('assignment','update_mapping',array_replace($invalidMove,['id'=>2,'year_level'=>'1st Year','semester'=>'1st Semester']),'POST'),422,'JS bypass incoming placement rejected');
 checkHttp($original===$db->query('SELECT year_level,semester FROM curriculum_version_subjects WHERE id=1')->fetch_assoc(),'Rejected placement unchanged');
 status($admin->api('requirement','create',array_replace($input,['subject_id'=>4,'required_subject_id'=>3,'confirm_sequencing'=>'1']),'POST'),422,'Same-period prerequisite cannot be overridden');
 status($admin->api('requirement','create',array_replace($input,['subject_id'=>4,'required_subject_id'=>2,'confirm_sequencing'=>'1']),'POST'),422,'Later prerequisite cannot be overridden');
 status($admin->api('requirement','update_rule',['curriculum_version_id'=>1,'subject_id'=>1,'required_subject_ids'=>[4]],'POST'),422,'Same-period rule edit rejected');
 status($viewer->api('assignment','preview_placement',$invalidMove),403,'Placement preview RBAC');
 status($admin->api('requirement','remove',['id'=>$id],'POST'),200,'Confirmed edge removal');
 checkHttp((int)$db->query('SELECT COUNT(*) FROM subjects')->fetch_row()[0]===4,'HTTP mutations preserve subjects');checkHttp((int)$db->query('SELECT COUNT(*) FROM curriculum_version_subjects')->fetch_row()[0]===4,'HTTP mutations preserve placements');
 foreach(['SUBJECT_REQUIREMENT_CREATE','SUBJECT_REQUIREMENT_UPDATE','SUBJECT_REQUIREMENT_REMOVE']as $action)checkHttp((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='$action'")->fetch_row()[0]>0,'HTTP audit '.$action);

 $mappingPage=$admin->request('modules/curriculum/pages/subject_mapping.php');status($mappingPage,200,'Mapping page');
 checkHttp(substr_count($mappingPage['body'],'role="tab"')===3,'Exactly three mapping tabs');
 checkHttp(!str_contains($mappingPage['body'],'Relationship View'),'No relationship tab');
 checkHttp(str_contains($mappingPage['body'],'Organize subjects within curriculum versions.'),'Final subtitle');
 status($guest->api('assignment','mapping_list',['curriculum_version_id'=>1]),401,'Mapping guest rejected');
 $placement=['academic_program_id'=>1,'curriculum_id'=>1,'curriculum_version_id'=>1,'subject_id'=>4,'year_level'=>'2nd Year','semester'=>'2nd Semester','is_required'=>'0'];
 foreach(['map_subject','update_mapping','remove'] as $action){
  status($viewer->api('assignment',$action,$placement+['id'=>4],'POST'),403,'Mapping RBAC '.$action);
  status($admin->api('assignment',$action,$placement+['id'=>4],'POST',''),403,'Mapping CSRF '.$action);
  status($admin->api('assignment',$action,$placement+['id'=>4]),405,'Mapping method '.$action);
 }
 foreach(['year_level','semester','is_required'] as $field){$invalid=$placement;$invalid[$field]='';status($admin->api('assignment','map_subject',$invalid,'POST'),422,'Missing '.$field);status($admin->api('assignment','update_mapping',$invalid+['id'=>4],'POST'),422,'Edit missing '.$field);}
 status($admin->api('assignment','map_subject',array_replace($placement,['curriculum_version_id'=>'']),'POST'),422,'Missing version');
 status($admin->api('assignment','map_subject',$placement,'POST'),409,'Same subject elsewhere rejected');
 $db->query('UPDATE curriculum_version_subjects SET sequence_order=7 WHERE id=4');
 status($admin->api('assignment','update_mapping',$placement+['id'=>4,'sequence_order'=>99,'subject_code'=>'CHANGED','units'=>99,'subject_id'=>999],'POST'),200,'Edit placement');
 $stored=$db->query('SELECT * FROM curriculum_version_subjects WHERE id=4')->fetch_assoc();
 checkHttp($stored['year_level']==='2nd Year' && $stored['semester']==='2nd Semester' && (int)$stored['is_required']===0,'Placement values persisted');
 checkHttp((int)$stored['sequence_order']===7 && (int)$stored['subject_id']===4,'Only placement fields edited');
 status($admin->api('assignment','remove',['id'=>4],'POST'),200,'Remove placement');
 checkHttp((int)$db->query('SELECT COUNT(*) FROM subjects WHERE id=4')->fetch_row()[0]===1,'Removal preserves Subject Master');
 status($admin->api('assignment','map_subject',$placement,'POST'),201,'Place existing subject');
 status($admin->api('assignment','map_subject',$placement,'POST'),409,'Exact duplicate rejected');
 foreach(['CURRICULUM_SUBJECT_ASSIGN','CURRICULUM_SUBJECT_UPDATE','CURRICULUM_SUBJECT_REMOVE'] as $action)checkHttp((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='$action'")->fetch_row()[0]>0,'Mapping audit '.$action);
 foreach(['placement.created','placement.updated','placement.removed'] as $event) {
  $stmt=$db->prepare('SELECT COUNT(*) FROM audit_logs WHERE metadata_json LIKE ?');$pattern='%'.$event.'%';$stmt->bind_param('s',$pattern);$stmt->execute();
  checkHttp((int)$stmt->get_result()->fetch_row()[0]>0,'Placement audit metadata '.$event);$stmt->close();
 }
 // Synthetic cross-context trace fixtures. Existing/local academic data is never modified.
 $db->query("INSERT INTO academic_programs(id,program_code,program_name) VALUES(2,'OTHER','Other program')");
 $db->query("INSERT INTO curricula(id,curriculum_code,curriculum_name,academic_program_id) VALUES(2,'DEMO','Synthetic demo',1),(3,'OTHER','Other curriculum',2)");
 $db->query("INSERT INTO curriculum_versions(id,curriculum_id,version_label,status) VALUES(2,1,'OLD','archived'),(3,2,'PREORAL','draft'),(4,3,'OTHER','active')");
 $db->query("INSERT INTO subjects(id,subject_code,subject_title,status) VALUES(5,'BPM101','Synthetic trace subject','active'),(6,'UNUSED','Synthetic unplaced','active'),(7,'OUTSIDE','Synthetic other scope','active'),(8,'INACTIVE','Synthetic inactive','inactive')");
 foreach([1,2,3,4] as $v)$db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES($v,5,'3rd Year','2nd Semester')");
 $db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES(4,7,'1st Year','1st Semester'),(3,8,'1st Year','1st Semester')");
 $auditBefore=(int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0];
 foreach([
  [[],4], [['academic_program_id'=>1],3], [['academic_program_id'=>1,'curriculum_id'=>2],1],
  [['academic_program_id'=>1,'curriculum_id'=>2,'curriculum_version_id'=>3],1]
 ] as [$scope,$count]) {
  $trace=status($admin->api('assignment','mapping_list',$scope+['subject_id'=>5]),200,'Scoped trace placements');
  checkHttp(count($trace['mappings'])===$count,'Exact scoped trace count');
  foreach($trace['mappings'] as $p)foreach($scope as $key=>$value)checkHttp((int)$p[$key]===$value,'No out-of-context placement');
  $suggestions=status($admin->api('subject','list',$scope+['search'=>'BPM101']),200,'Scoped trace suggestions');
  checkHttp(array_column($suggestions['subjects'],'id')===[5],'Subjects unique across multiple placements');
 }
 $scope=['academic_program_id'=>1,'curriculum_id'=>2,'curriculum_version_id'=>3];
 foreach(['UNUSED','OUTSIDE'] as $search) {
  $suggestions=status($admin->api('subject','list',$scope+['search'=>$search]),200,'Out-of-context search');
  checkHttp($suggestions['subjects']===[],'No unrelated fallback');
 }
 $injected=status($admin->api('assignment','mapping_list',$scope+['subject_id'=>7]),200,'Injected out-of-context subject');
 checkHttp($injected['mappings']===[],'Injected subject exposes no outside placements');
 $inconsistent=status($admin->api('subject','list',array_replace($scope,['academic_program_id'=>2])),200,'Contradictory trace scope');
 checkHttp($inconsistent['subjects']===[],'Conflicting scope never broadens results');
 $global=status($admin->api('subject','list',['search'=>'UNUSED']),200,'Global unplaced subject search');
 checkHttp(count($global['subjects'])===1,'Global mode includes unplaced Subject Master');
 $normal=status($admin->api('subject','list',$scope+['search'=>'INACTIVE']),200,'Existing visibility convention');
 checkHttp(count($normal['subjects'])===1,'Unfiltered inspection retains existing inactive visibility');
 $active=status($admin->api('subject','list',$scope+['search'=>'INACTIVE','status'=>'active']),200,'Existing active visibility filter');
 checkHttp($active['subjects']===[],'Active filter is enforced by repository');
 $available=status($admin->api('assignment','placement_subject_options',$scope),200,'Available placement subjects');
 checkHttp(!in_array(5,array_column($available['subjects'],'id')) && in_array(6,array_column($available['subjects'],'id')),'Selector excludes placed but includes unplaced existing subjects');
 checkHttp((int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0]===$auditBefore,'Trace and selector reads are not audited');
 status($admin->api('assignment','placement_subject_options',array_replace($scope,['academic_program_id'=>2])),422,'Invalid placement selector context');
 status($viewer->api('assignment','placement_subject_options',$scope),403,'Available subjects RBAC');
 status($viewer->api('subject','list',$scope),403,'Subject Trace requires Subject Master view');
 foreach(['academic_program_id','curriculum_id'] as $field)status($admin->api('assignment','map_subject',array_replace($placement,[$field=>'']),'POST'),422,'Complete placement context required');
 status($admin->api('assignment','map_subject',array_replace($placement,['curriculum_id'=>2]),'POST'),422,'Mismatched placement context rejected');
 $db->query("DELETE FROM role_permissions WHERE role_id=$viewerRole");status($viewer->api('requirement','workspace',['curriculum_version_id'=>1]),403,'View permission enforced server-side');
}finally{
 foreach($clients as $c)$c->close();
 if(is_resource($server)){proc_terminate($server);proc_close($server);}
 if($created&&preg_match('/^csms_mapping_test_[a-f0-9]{16}$/D',$name))$db->query("DROP DATABASE `$name`");
 $db->close();
 $logText=file_get_contents($log);unlink($log);foreach(glob($sessionDir.'/sess_*')as $file)unlink($file);rmdir($sessionDir);
}
checkHttp(!preg_match('/PHP (Warning|Fatal|Notice|Parse error)/',$logText),'No PHP errors in HTTP server log');
echo "PASS: $checks isolated mapping and prerequisite HTTP checks: authenticated page, view/manage RBAC, CSRF, no GET mutations, create/update/remove, validation, audits; disposable server and schema removed.\n";
