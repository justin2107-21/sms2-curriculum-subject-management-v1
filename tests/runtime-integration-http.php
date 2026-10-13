<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('zend.exception_ignore_args','1');
require_once __DIR__.'/version-protection-fixture.php';
require_once __DIR__.'/FoundationHttpClient.php';
require_once __DIR__.'/../tools/task9-preview-fixture.php';
$db=protectionDatabase();$checks=0;
function runtimeCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo 'PASS: '.$label.PHP_EOL;}
function runtimeResponse(array $r,int $status,string $label):array{runtimeCheck($r['status']===$status,$label.' HTTP '.$r['status'].' expected '.$status);runtimeCheck(!preg_match('/Fatal error|Warning:|Stack trace|mysqli_sql_exception|SELECT .* FROM/i',$r['body']),$label.' safely encoded');return $r['json']??[];}
$p=proc_open([PHP_BINARY,__DIR__.'/../database/seeds/load_ched_bsit_outcomes.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),getenv(),['bypass_shell'=>true]);fclose($pipes[0]);stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);runtimeCheck(proc_close($p)===0,'original guarded source outcome loader');
$password=bin2hex(random_bytes(20));$viewerPassword=bin2hex(random_bytes(20));$f=task9_preview_fixture($db,$password,$viewerPassword);
$log=tempnam(sys_get_temp_dir(),'task9-http-');$sessionDir=$log.'-sessions';mkdir($sessionDir);$sock=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$address=stream_socket_get_name($sock,false);fclose($sock);
$env=getenv();$env['SMS_SESSION_COOKIE_SECURE']='0';$env['CSMS_DISPOSABLE_PREVIEW']='1';
$server=proc_open([PHP_BINARY,'-d','zend.exception_ignore_args=1','-d','session.save_path='.$sessionDir,'-d','error_log='.$log,'-S',$address,'-t',dirname(__DIR__)],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true]);if(!is_resource($server))throw new RuntimeException('HTTP test server unavailable.');fclose($pipes[0]);$clients=[];
try {
    for($i=0;$i<60;$i++){$socket=@stream_socket_client('tcp://'.$address,$errno,$errstr,.1);if($socket){fclose($socket);break;}usleep(100000);}
    $editor=new FoundationHttpClient('http://'.$address);$viewer=new FoundationHttpClient('http://'.$address);$guest=new FoundationHttpClient('http://'.$address);$clients=[$editor,$viewer,$guest];
    foreach([[$editor,'task9_preview',$password],[$viewer,'task9_viewer',$viewerPassword]] as [$client,$name,$secret]){$client->request('auth/signin.php');runtimeResponse($client->request('shared/auth_actions.php',['action'=>'login','username'=>$name,'password'=>$secret],'POST'),200,'synthetic login');$client->request('modules/curriculum/pages/subject_mapping.php');}
    $args=['curriculum_id'=>$f['curriculum_id'],'per_page'=>50];
    $r=runtimeResponse($editor->api('version','list',$args),200,'fresh 001-025 version endpoint');runtimeCheck(count($r['versions'])===2,'actual reference and revision options returned');
    $r=runtimeResponse($editor->api('version','list',$args+['search'=>'Working']),200,'searchable version endpoint');runtimeCheck(count($r['versions'])===1&&(int)$r['versions'][0]['id']===$f['revision_version_id'],'version search preserves exact identity');
    $r=runtimeResponse($editor->api('assignment','mapping_list',['curriculum_version_id'=>$f['revision_version_id']]),200,'selected-version placements');runtimeCheck(count($r['mappings'])===2,'actual stored placements returned');
    $r=runtimeResponse($editor->api('outcome_mapping','workspace',['curriculum_version_id'=>$f['revision_version_id']]),200,'graduate outcome workspace');runtimeCheck(count($r['outcomes'])===13&&count($r['mappings'])===1,'IT01-IT13 and actual mapping state');
    $r=runtimeResponse($editor->api('clo_assessment','workspace',['placement_id'=>$f['revision_placement_id']]),200,'Task 8 placement workspace');runtimeCheck(count($r['clos'])===1&&$r['clos'][0]['origin']==='inherited'&&count($r['plans'])===1,'inherited CLO and planned assessment returned');
    $r=runtimeResponse($editor->request('dashboard/analytics.php',['version_id'=>$f['revision_version_id']]),200,'dashboard selected version');runtimeCheck((int)$r['metrics']['mapped_subjects']===2,'dashboard content reflects selected version');
    $snapshot=protectionSnapshot($db);
    $r=runtimeResponse($editor->api('program','summary',['id'=>$f['program_id']]),200,'program summary aggregate');
    runtimeCheck($r['summary']['curricula']===1 && $r['summary']['versions']===2 && $r['summary']['placements']===4,'truthful program totals include both versions and version placements');
    runtimeResponse($guest->api('program','summary',['id'=>$f['program_id']]),401,'anonymous counts denied');
    runtimeResponse($editor->api('program','summary',['id'=>999999]),404,'missing program differs from empty');
    runtimeResponse($editor->api('program','summary',['id'=>$f['program_id']],'POST'),405,'counts stay GET-only');
    $r=runtimeResponse($viewer->api('program','summary',['id'=>$f['other_program_id']]),200,'empty program curriculum counts');
    runtimeCheck($r['summary']['curricula']===1 && $r['summary']['versions']===0 && $r['summary']['placements']===0,'valid zero totals are distinct from errors');
    $r=runtimeResponse($viewer->api('version','list',['curriculum_id'=>$f['empty_curriculum_id']]),200,'genuine empty version list');runtimeCheck($r['versions']===[]&&$r['pagination']['total_records']===0,'empty list is explicit successful data');
    runtimeResponse($viewer->api('version','list',$args+['academic_program_id'=>$f['other_program_id']]),422,'cross-program context rejected');
    runtimeResponse($viewer->request('dashboard/analytics.php',['version_id'=>$f['revision_version_id'],'program_id'=>$f['other_program_id']]),422,'forged dashboard program rejected');
    runtimeResponse($viewer->request('dashboard/analytics.php',['version_id'=>$f['revision_version_id']],'POST'),405,'analytics stay GET-only');
    $pages=['curricula','subject_mapping','prerequisite_configuration','electives_manager','program_centric_assignment','subject_history_view','subject_comparison','grade_weighting_setup','validate_curriculum','curriculum_export'];
    foreach($pages as $page) {
        $r=$viewer->request('modules/curriculum/pages/'.$page.'.php',['program_id'=>$f['program_id'],'curriculum_id'=>$f['curriculum_id'],'version_id'=>$f['revision_version_id']]);
        runtimeResponse($r,200,'authenticated navigation '.$page);
        runtimeCheck(str_contains($r['body'],'Disposable synthetic preview'),'preview designation on '.$page);
    }
    $context=['academic_program_id'=>$f['program_id'],'curriculum_id'=>$f['curriculum_id'],'curriculum_version_id'=>$f['revision_version_id']];
    foreach([
        ['requirement','workspace',['curriculum_version_id'=>$f['revision_version_id']]],
        ['elective_group','workspace',['program_id'=>$f['program_id'],'curriculum_id'=>$f['curriculum_id'],'curriculum_version_id'=>$f['revision_version_id']]],
        ['program_assignment','list',['type'=>'program','id'=>$f['program_id']]],
        ['subject_history','list',$context],
        ['equivalency','list',[]],
        ['grade_weighting','placement_options',$context],
        ['compliance','version_options',['curriculum_id'=>$f['curriculum_id']]],
        ['export','preview',$context],
    ] as [$endpoint,$action,$parameters]) runtimeResponse($viewer->api($endpoint,$action,$parameters),200,'module data '.$endpoint);
    runtimeCheck(protectionSnapshot($db)===$snapshot,'read workflow and rejected context requests change no academic, provenance or audit rows');
    $cloBody=['placement_id'=>$f['reference_placement_id'],'clo_code'=>'DENIED','clo_statement'=>'Synthetic forbidden reference write','sequence_order'=>9];
    runtimeResponse($viewer->api('clo_assessment','clo_create',$cloBody,'POST'),403,'viewer cannot write CLOs');
    runtimeResponse($editor->api('clo_assessment','clo_create',$cloBody,'POST',''),403,'missing CSRF denied');
    runtimeResponse($editor->api('clo_assessment','clo_create',$cloBody),405,'GET CLO write denied');
    runtimeResponse($editor->api('clo_assessment','clo_create',$cloBody,'POST'),409,'reference remains protected');
    runtimeCheck(protectionSnapshot($db)===$snapshot,'rejected academic writes preserve data and success audits');
    $created=runtimeResponse($editor->api('clo_assessment','clo_create',array_replace($cloBody,['placement_id'=>$f['revision_placement_id'],'clo_code'=>'SYN-HTTP-CLO']),'POST'),201,'authorized proposed CLO write');
    $cloId=(int)$created['clo_id'];runtimeResponse($editor->api('clo_assessment','clo_withdraw',['placement_id'=>$f['revision_placement_id'],'clo_id'=>$cloId],'POST'),200,'explicit CLO withdrawal retains evidence');
    runtimeCheck((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='CURRICULUM_CLO_CREATE' AND entity_id=$cloId")->fetch_row()[0]===1,'authorized write has exactly one required success audit');
    // Add more than one page of synthetic fixtures to test the old truncated summary path.
    for($i=0;$i<51;$i++){
        $stmt=RepositoryQuery::execute($db,'INSERT INTO curricula(curriculum_code,curriculum_name,academic_program_id) VALUES (?,?,?)','ssi',['SYN-PAGE-'.$i,'Synthetic pagination fixture',$f['other_program_id']]);$stmt->close();$id=(int)$db->insert_id;
        $stmt=RepositoryQuery::execute($db,'INSERT INTO curriculum_versions(curriculum_id,version_label) VALUES (?,?)','is',[$id,'Synthetic pagination version']);$stmt->close();
    }
    $r=runtimeResponse($viewer->api('program','summary',['id'=>$f['other_program_id']]),200,'summary beyond a page');
    runtimeCheck($r['summary']['curricula']===52&&$r['summary']['versions']===51,'aggregate counts are not capped at 50');
    $db->query("UPDATE curriculum_versions SET status='archived' WHERE id=".$f['reference_version_id']);
    $r=runtimeResponse($viewer->api('version','list',$args+['status'=>'draft']),200,'archived version filtering');runtimeCheck(count($r['versions'])===1&&(int)$r['versions'][0]['id']===$f['revision_version_id'],'technical archive filter preserves exact revision identity');
    $db->query("UPDATE curriculum_versions SET status='draft' WHERE id=".$f['reference_version_id']);
    // Fault injection only in this newly created guarded schema; historical migration files are untouched.
    $db->query("ALTER TABLE curriculum_versions CHANGE COLUMN version_role task9_legacy_role ENUM('unclassified','reference','proposed') NOT NULL DEFAULT 'unclassified'");
    try {
        $r=runtimeResponse($editor->api('version','list',$args),503,'missing lifecycle column is a recoverable schema error');
        runtimeCheck(($r['error_code']??'')==='schema_mismatch'&&!isset($r['versions'],$r['pagination']),'schema failure cannot masquerade as zero versions');
        runtimeCheck(!str_contains(strtolower((string)($r['message']??'')),'unknown column')&&!str_contains(strtolower((string)($r['message']??'')),'version_role'),'technical SQL details are not disclosed to the client');
        $r=runtimeResponse($editor->api('program','summary',['id'=>$f['program_id']]),200,'program counts independent from lifecycle listing fault');
        runtimeCheck($r['summary']['versions']===2,'counts still report stored versions truthfully');
    }
    finally { $db->query("ALTER TABLE curriculum_versions CHANGE COLUMN task9_legacy_role version_role ENUM('unclassified','reference','proposed') NOT NULL DEFAULT 'unclassified'"); }
    runtimeResponse($editor->api('version','list',$args),200,'version endpoint after schema restoration');
} finally {
    foreach($clients as $client)$client->close();if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if(is_dir($sessionDir)){foreach(scandir($sessionDir) as $file)if($file!=='.'&&$file!=='..')unlink($sessionDir.DIRECTORY_SEPARATOR.$file);rmdir($sessionDir);}if(is_file($log))unlink($log);
}
echo json_encode(['passed'=>true,'checks'=>$checks,'suite'=>'Task 9 integrated runtime HTTP']).PHP_EOL;
