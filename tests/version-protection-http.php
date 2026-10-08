<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/version-protection-fixture.php';
require_once __DIR__.'/FoundationHttpClient.php';
$db=protectionDatabase();$fixtures=protectionFixtures($db);$checks=0;$clients=[];$server=null;
function httpCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException("FAIL: $label");$checks++;}
function httpStatus(array $r,int $status,string $label):void{
    httpCheck($r['status']===$status,"$label: expected $status received {$r['status']} {$r['body']}");
    httpCheck(!preg_match('/Fatal error|Warning:|Stack trace|mysqli_sql_exception|SQLSTATE/i',$r['body']),"$label safe response");
}
$log=tempnam(sys_get_temp_dir(),'t2-http-');$sessions=$log.'-sessions';mkdir($sessions);
try{
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$address=stream_socket_get_name($socket,false);fclose($socket);
    $env=getenv();$env['SMS_SESSION_COOKIE_SECURE']='0';
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.$sessions,'-d','error_log='.$log,'-S',$address,'-t',dirname(__DIR__)],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);
    if(!is_resource($server))throw new RuntimeException('Loopback HTTP server failed');fclose($pipes[0]);
    for($n=0;$n<60;$n++){$ready=@stream_socket_client('tcp://'.$address,$errno,$errstr,.1);if($ready){fclose($ready);break;}usleep(100000);}
    $admin=new FoundationHttpClient('http://'.$address);$viewer=new FoundationHttpClient('http://'.$address);$guest=new FoundationHttpClient('http://'.$address);$clients=[$admin,$viewer,$guest];
    foreach([[$admin,'task2_admin'],[$viewer,'task2_viewer']] as [$client,$username]){
        $client->request('auth/signin.php');
        httpStatus($client->request('shared/auth_actions.php',['action'=>'login','username'=>$username,'password'=>$fixtures['password']],'POST'),200,'fixture login');
    }
    foreach([$admin,$viewer] as $client)httpStatus($client->request('dashboard/account.php'),200,'refresh authenticated CSRF token');
    httpStatus($guest->api('version','get',['id'=>$fixtures['versions']['reference']['version']]),401,'unauthenticated read');
    foreach(['reference','under_review','approved'] as $state){
        $f=$fixtures['versions'][$state];$i=protectionInputs($f);
        $routes=[
            ['version','rename',['id'=>$f['version'],'version_label'=>'Forged']],
            ['assignment','assign',$i['placement']+['curriculum_version_id'=>$f['version'],'subject_id'=>$f['subjects'][3]]],
            ['assignment','map_subject',$i['placement']+['academic_program_id'=>$f['program'],'curriculum_id'=>$f['curriculum'],'curriculum_version_id'=>$f['version'],'subject_id'=>$f['subjects'][3]]],
            ['assignment','update',$i['placement']+['id'=>$f['placements'][2],'curriculum_version_id'=>$fixtures['versions']['legacy']['version']]],
            ['assignment','update_mapping',$i['placement']+['id'=>$f['placements'][2]]],
            ['assignment','remove',['id'=>$f['placements'][2]]],
            ['requirement','create',['curriculum_version_id'=>$f['version'],'subject_id'=>$f['subjects'][2],'required_subject_id'=>$f['subjects'][0],'requirement_type'=>'corequisite']],
            ['requirement','remove',['id'=>$f['requirement']]],
            ['requirement','update_rule',['curriculum_version_id'=>$f['version'],'subject_id'=>$f['subjects'][2],'expected_ids'=>[],'required_subject_ids'=>[]]],
            ['elective_group','create',$i['context']+$i['group']],
            ['elective_group','update',$i['context']+$i['group']+['id'=>$f['group']]],
            ['elective_group','archive',$i['context']+['id'=>$f['group']]],
            ['grade_weighting','configuration_save',$i['configuration']+['curriculum_version_subject_id'=>$f['placements'][0],'curriculum_version_id'=>$fixtures['versions']['legacy']['version']]],
            ['subject','update',$i['subject']+['id'=>$f['subjects'][0]]],
            ['subject','archive',['id'=>$f['subjects'][0]]],
            ['program','update',$i['program']+['id'=>$f['program']]],
            ['program','archive',['id'=>$f['program']]],
            ['curriculum','update',$i['curriculum']+['id'=>$f['curriculum']]],
            ['curriculum','archive',['id'=>$f['curriculum']]],
            ['compliance','reference_update',$i['reference']+['id'=>$f['reference']]],
            ['compliance','rule_create',$i['rule']],
            ['compliance','rule_update',$i['rule']+['id'=>$f['rule']]],
        ];
        foreach($routes as [$endpoint,$action,$input]){
            $before=protectionSnapshot($db);
            httpStatus($admin->api($endpoint,$action,$input,'POST'),409,"$state $endpoint/$action");
            httpCheck(protectionSnapshot($db)===$before,"$state $endpoint/$action no mutation");
            if($state==='reference'){
                httpStatus($viewer->api($endpoint,$action,$input,'POST'),403,"$endpoint/$action normalized RBAC");
                httpStatus($admin->api($endpoint,$action,$input,'POST',''),403,"$endpoint/$action CSRF");
                httpStatus($admin->api($endpoint,$action,$input),405,"$endpoint/$action method");
                httpCheck(protectionSnapshot($db)===$before,"$endpoint/$action unauthorized no mutation");
            }
        }
        foreach(['program'=>$f['program'],'curriculum'=>$f['curriculum'],'subject'=>$f['subjects'][0],'elective_group'=>$f['group']] as $module=>$id){
            $before=protectionSnapshot($db);
            httpStatus($admin->request('dashboard/archive_actions.php',['action'=>'restore','module'=>$module,'id'=>$id],'POST'),409,"$state restore $module");
            httpCheck(protectionSnapshot($db)===$before,"$state restore $module no mutation");
        }
        httpStatus($admin->api('version','get',['id'=>$f['version']]),200,"$state version read");
        httpStatus($admin->api('subject_history','list',['curriculum_version_id'=>$f['version']]),200,"$state history read");
        httpStatus($admin->api('export','preview',['academic_program_id'=>$f['program'],'curriculum_id'=>$f['curriculum'],'curriculum_version_id'=>$f['version']]),200,"$state export read");
        httpStatus($admin->api('elective_group','workspace',$i['context']),200,"$state elective read");
        httpStatus($admin->api('grade_weighting','scheme_get',['curriculum_version_subject_id'=>$f['placements'][0]]),200,"$state grade read");
        if($f['baseline']!==null)httpStatus($admin->api('subject_history','compare_versions',['curriculum_id'=>$f['curriculum'],'version_a_id'=>$f['version'],'version_b_id'=>$f['baseline']]),200,"$state comparison read");
    }
    foreach(['legacy','draft','returned'] as $state){
        $f=$fixtures['versions'][$state];$i=protectionInputs($f);
        httpStatus($admin->api('version','rename',['id'=>$f['version'],'version_label'=>'Edited '.$state],'POST'),200,"$state rename allowed");
        httpStatus($admin->api('assignment','update',$i['placement']+['id'=>$f['placements'][2]],'POST'),200,"$state mapping allowed");
        httpStatus($admin->api('grade_weighting','configuration_save',$i['configuration']+['curriculum_version_subject_id'=>$f['placements'][0],'curriculum_version_id'=>$f['version']],'POST'),200,"$state grade allowed");
    }
    httpStatus($admin->api('version','rename',['id'=>99999999,'version_label'=>'Missing'],'POST'),404,'missing record');
    httpStatus($admin->api('version','rename',['id'=>1,'version_label'=>''],'POST'),422,'invalid submitted value');
    foreach(['add','remove'] as $action)httpStatus($admin->api('elective_membership',$action,['id'=>1,'elective_group_id'=>1,'subject_id'=>1],'POST'),422,'disabled legacy membership route');
    echo json_encode(['passed'=>true,'checks'=>$checks,'suite'=>'version protection HTTP']).PHP_EOL;
}finally{
    foreach($clients as $client)$client->close();
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    foreach(glob($sessions.'/*') as $file)unlink($file);rmdir($sessions);unlink($log);
}
