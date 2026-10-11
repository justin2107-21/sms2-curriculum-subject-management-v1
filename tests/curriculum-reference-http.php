<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/version-protection-fixture.php';
require_once __DIR__ . '/FoundationHttpClient.php';
$db = protectionDatabase();
$fixtures = protectionFixtures($db);
$checks = 0; $clients = []; $server = null;
function task3HttpCheck(bool $ok,string $label):void { global $checks; if(!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; }
function task3HttpStatus(array $response,int $expected,string $label):void {
    task3HttpCheck($response['status']===$expected,"$label expected $expected, got {$response['status']}: {$response['body']}");
    task3HttpCheck(!preg_match('/Fatal error|Warning:|Stack trace|mysqli_sql_exception|SQLSTATE/i',$response['body']),"$label has safe response");
}
function task3HttpInsert(mysqli $db,string $sql):int { $db->query($sql); return (int)$db->insert_id; }
$password=bin2hex(random_bytes(24));$hash=$db->real_escape_string(password_hash($password,PASSWORD_DEFAULT));
$roles=[];
foreach(['task3_editor','task3_reader'] as $name){$label=$name==='task3_editor'?'Task 3 authorized editor':'Task 3 read-only viewer';$role=task3HttpInsert($db,"INSERT INTO roles(name,display_name,description,is_system) VALUES ('$name','$label','Synthetic Task 3 test role',0)");$roles[$name]=$role;}
$permissionSets=[
    'task3_editor'=>['curriculum.view','curriculum.update','compliance.view','compliance.manage','dashboard.view','account.view','account.update','curriculum.reference.attach','compliance.reference.verify','curriculum.reference.classify'],
    'task3_reader'=>['curriculum.view','compliance.view','dashboard.view','account.view','account.update'],
];
foreach($permissionSets as $roleName=>$permissions)foreach($permissions as $permission){$stmt=$db->prepare('INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE name=?');$stmt->bind_param('is',$roles[$roleName],$permission);$stmt->execute();task3HttpCheck($stmt->affected_rows===1,"explicit test grant $roleName/$permission");$stmt->close();}
$users=[];
foreach(['task3_editor','task3_reader'] as $name){$users[$name]=task3HttpInsert($db,"INSERT INTO users(username,email,first_name,last_name,password_hash) VALUES ('$name','$name@example.invalid','Task','Three','$hash')");$db->query("INSERT INTO user_roles(user_id,role_id) VALUES ({$users[$name]},{$roles[$name]})");}
$program=task3HttpInsert($db,"INSERT INTO academic_programs(program_code,program_name) VALUES ('T3-HTTP','Synthetic Task 3 program')");
$curriculum=task3HttpInsert($db,"INSERT INTO curricula(curriculum_code,curriculum_name,academic_program_id) VALUES ('T3-HTTP','Synthetic Task 3 curriculum',$program)");
$version=task3HttpInsert($db,"INSERT INTO curriculum_versions(curriculum_id,version_label) VALUES ($curriculum,'HTTP version')");
$subject=task3HttpInsert($db,"INSERT INTO subjects(subject_code,subject_title,units,lecture_units,laboratory_units) VALUES ('T3-HTTP-S','Synthetic HTTP subject',3,2,1)");
$db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES ($version,$subject,'1st Year','1st Semester')");
$source=task3HttpInsert($db,"INSERT INTO compliance_references(reference_type,source_kind,reference_code,title,issuing_body,source_url,verification_status) VALUES ('CHED','ched_sample_curriculum','T3-HTTP-REF','Synthetic CHED sample','CHED','https://example.invalid/sample','draft')");
$log=tempnam(sys_get_temp_dir(),'t3-http-');$sessions=$log.'-sessions';mkdir($sessions);
try {
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$address=stream_socket_get_name($socket,false);fclose($socket);
    $env=getenv();$env['SMS_SESSION_COOKIE_SECURE']='0';
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.$sessions,'-d','error_log='.$log,'-S',$address,'-t',dirname(__DIR__)],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);
    if(!is_resource($server))throw new RuntimeException('Loopback HTTP server failed');fclose($pipes[0]);
    for($n=0;$n<60;$n++){$ready=@stream_socket_client('tcp://'.$address,$errno,$errstr,.1);if($ready){fclose($ready);break;}usleep(100000);}
    $editor=new FoundationHttpClient('http://'.$address);$reader=new FoundationHttpClient('http://'.$address);$guest=new FoundationHttpClient('http://'.$address);$clients=[$editor,$reader,$guest];
    foreach([[$editor,'task3_editor'],[$reader,'task3_reader']] as [$client,$username]){$client->request('auth/signin.php');task3HttpStatus($client->request('shared/auth_actions.php',['action'=>'login','username'=>$username,'password'=>$password],'POST'),200,'synthetic login');$client->request('dashboard/account.php');}
    task3HttpStatus($guest->api('version_reference','workspace',['curriculum_version_id'=>$version]),401,'guest workspace');
    $input=['curriculum_version_id'=>$version,'compliance_reference_id'=>$source,'reference_purpose'=>'school_curriculum'];
    $before=protectionSnapshot($db);
    task3HttpStatus($reader->api('version_reference','attach',$input,'POST'),403,'read-only user cannot attach');
    task3HttpStatus($editor->api('version_reference','attach',$input,'POST',''),403,'missing CSRF prevents attachment');
    task3HttpStatus($editor->api('version_reference','attach',$input),405,'GET cannot attach');
    task3HttpStatus($editor->api('version_reference','attach',['curriculum_version_id'=>99999999,'compliance_reference_id'=>$source,'reference_purpose'=>'school_curriculum'],'POST'),404,'forged missing version ID rejected');
    task3HttpStatus($editor->api('version_reference','attach',['curriculum_version_id'=>$version,'compliance_reference_id'=>99999999,'reference_purpose'=>'school_curriculum'],'POST'),404,'forged missing source ID rejected');
    task3HttpCheck($before===protectionSnapshot($db),'unauthorized and CSRF failures leave records unchanged');
    task3HttpStatus($editor->api('version_reference','attach',$input,'POST'),201,'explicitly authorized attachment');
    task3HttpStatus($reader->api('compliance','reference_evidence',['id'=>$source,'verification_subject'=>'document_identity','result'=>'not_assessed','rationale'=>'test'],'POST'),403,'read-only user cannot verify');
    task3HttpStatus($editor->api('compliance','reference_evidence',['id'=>$source,'verification_subject'=>'document_identity','result'=>'not_assessed','rationale'=>'Synthetic HTTP assessment'],'POST'),201,'explicitly authorized evidence assessment');
    task3HttpStatus($editor->api('compliance','reference_evidence',['id'=>$source,'verification_subject'=>'document_identity','result'=>'confirmed','rationale'=>'Missing locator'],'POST'),422,'confirmed identity requires evidence locator or digest');
    task3HttpStatus($reader->api('version_reference','classify_reference',['curriculum_version_id'=>$version,'compliance_reference_id'=>$source,'classification_basis'=>'ched_sample','confirm_reference_protection'=>'1'],'POST'),403,'read-only user cannot classify');
    task3HttpStatus($editor->api('version_reference','classify_reference',['curriculum_version_id'=>$version,'compliance_reference_id'=>$source,'classification_basis'=>'ched_sample'],'POST'),422,'classification requires explicit consequence confirmation');
    task3HttpStatus($editor->api('version_reference','classify_reference',['curriculum_version_id'=>$version,'compliance_reference_id'=>$source,'classification_basis'=>'ched_sample','confirm_reference_protection'=>'1'],'POST'),200,'explicitly authorized reference classification');
    task3HttpCheck($db->query("SELECT version_role FROM curriculum_versions WHERE id=$version")->fetch_row()[0]==='reference','HTTP classification stored correct lifecycle role');
    task3HttpCheck($db->query("SELECT reference_code_snapshot FROM curriculum_version_references WHERE curriculum_version_id=$version")->fetch_row()[0]==='T3-HTTP-REF','HTTP attachment stores source snapshot');
    task3HttpCheck((int)$db->query("SELECT COUNT(*) FROM curriculum_version_subject_snapshots s JOIN curriculum_version_subjects p ON p.id=s.curriculum_version_subject_id WHERE p.curriculum_version_id=$version AND s.snapshot_complete=1")->fetch_row()[0]===1,'HTTP classification captures complete snapshot');
    task3HttpStatus($editor->api('version','rename',['id'=>$version,'version_label'=>'Forged ordinary role mutation'],'POST'),409,'normal version mutation remains protected');
    task3HttpStatus($editor->api('compliance','reference_evidence',['id'=>$source,'verification_subject'=>'institutional_adoption','result'=>'confirmed','evidence_locator'=>'fake','rationale'=>'unsupported'],'POST'),422,'unsupported adoption assessment is rejected');
    task3HttpStatus($reader->api('version_reference','workspace',['curriculum_version_id'=>$version]),200,'read-only workspace access');
    task3HttpCheck($db->query("SELECT COUNT(*) FROM curriculum_version_review_events WHERE curriculum_version_id=$version")->fetch_row()[0]==0,'classification creates no invented approval event');

    // F1 end-to-end: edit through the authorized HTTP action and keep the old event historical.
    $staleVersion=task3HttpInsert($db,"INSERT INTO curriculum_versions(curriculum_id,version_label) VALUES ($curriculum,'Stale evidence HTTP version')");
    $db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES ($staleVersion,$subject,'2nd Year','1st Semester')");
    $staleSource=task3HttpInsert($db,"INSERT INTO compliance_references(reference_type,source_kind,reference_code,title,issuing_body,source_url,verification_status) VALUES ('CHED','ched_sample_curriculum','T3-HTTP-STALE','Original sample title','CHED','https://example.invalid/original','draft')");
    task3HttpStatus($editor->api('version_reference','attach',['curriculum_version_id'=>$staleVersion,'compliance_reference_id'=>$staleSource,'reference_purpose'=>'school_curriculum'],'POST'),201,'attach stale-evidence HTTP fixture');
    task3HttpStatus($editor->api('compliance','reference_evidence',['id'=>$staleSource,'verification_subject'=>'document_identity','result'=>'confirmed','evidence_locator'=>'https://example.invalid/original','rationale'=>'Synthetic original identity evidence'],'POST'),201,'record original confirmed identity evidence');
    $originalHttpEvent=$db->query("SELECT id,result,title_snapshot,source_url_snapshot,created_at FROM compliance_reference_evidence_events WHERE compliance_reference_id=$staleSource AND verification_subject='document_identity' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    task3HttpStatus($editor->api('compliance','reference_update',['id'=>$staleSource,'reference_type'=>'CHED','source_kind'=>'ched_sample_curriculum','reference_code'=>'T3-HTTP-STALE','title'=>'Corrected sample title','issuing_body'=>'CHED','source_url'=>'https://example.invalid/corrected','effective_date'=>'','verification_status'=>'draft','notes'=>'Synthetic fixture'],'POST'),200,'authorized source edit clears current verified status');
    $staleWorkspaceResponse=$reader->api('version_reference','workspace',['curriculum_version_id'=>$staleVersion]);
    task3HttpStatus($staleWorkspaceResponse,200,'read-only user reads stale assessment state');
    $staleWorkspaceData=$staleWorkspaceResponse['json']['workspace']['references'][0];
    task3HttpCheck($staleWorkspaceData['current_assessments']['document_identity']['status']==='stale_for_current_metadata'
        && $staleWorkspaceData['current_assessments']['document_identity']['historical_result']==='confirmed', 'workspace never represents old confirmation as current');
    task3HttpCheck($staleWorkspaceData['attachment_matches_current_source']===false
        && $staleWorkspaceData['reference_title_snapshot']==='Original sample title','workspace separates changed current source from attachment-time snapshot');
    task3HttpCheck($db->query("SELECT id,result,title_snapshot,source_url_snapshot,created_at FROM compliance_reference_evidence_events WHERE id={$originalHttpEvent['id']}")->fetch_assoc()===$originalHttpEvent,'authorized metadata edit preserves original evidence event');
    task3HttpStatus($editor->api('compliance','reference_evidence',['id'=>$staleSource,'verification_subject'=>'document_identity','result'=>'confirmed','evidence_locator'=>'https://example.invalid/corrected','rationale'=>'Synthetic current identity evidence'],'POST'),201,'record matching current identity assessment');
    $currentWorkspaceResponse=$reader->api('version_reference','workspace',['curriculum_version_id'=>$staleVersion]);
    task3HttpStatus($currentWorkspaceResponse,200,'read current assessment state');
    $currentEvents=$currentWorkspaceResponse['json']['workspace']['references'][0]['evidence_events'];
    task3HttpCheck($currentWorkspaceResponse['json']['workspace']['references'][0]['current_assessments']['document_identity']['status']==='confirmed'
        && $currentEvents[0]['current_assessment_result']==='confirmed' && $currentEvents[1]['current_assessment_result']==='stale_for_current_metadata', 'new matching assessment is current while prior event remains historical');

    $builder=$editor->request('modules/curriculum/pages/curricula.php',['program_id'=>$program,'curriculum_id'=>$curriculum,'version_id'=>$version]);
    task3HttpStatus($builder,200,'existing Curriculum Builder References & Baseline panel');
    task3HttpCheck(str_contains($builder['body'],'References &amp; Baseline') && str_contains($builder['body'],'Institutional adoption/approval not verified'),'Builder shows provenance and honest adoption label');
    $builderScript=file_get_contents(__DIR__.'/../modules/curriculum/assets/curriculum-builder.js');
    task3HttpCheck(str_contains($builderScript,'current_assessments') && str_contains($builderScript,'STALE — previous recorded result')
        && str_contains($builderScript,'Attachment-time snapshot:'),'Builder distinguishes stale current assessments from immutable attachment-time evidence');
    $dom=new DOMDocument();@$dom->loadHTML($builder['body']);$ids=[];foreach((new DOMXPath($dom))->query('//*[@id]') as $node)$ids[]=$node->getAttribute('id');
    task3HttpCheck(count($ids)===count(array_unique($ids)),'Builder panel adds no duplicate DOM IDs');
    $readerBuilder=$reader->request('modules/curriculum/pages/curricula.php',['program_id'=>$program,'curriculum_id'=>$curriculum,'version_id'=>$version]);
    task3HttpStatus($readerBuilder,200,'read-only Builder access');
    task3HttpCheck(!str_contains($readerBuilder['body'],'id="classifyReferenceForm"') && !str_contains($readerBuilder['body'],'id="attachVersionReferenceForm"'),'read-only Builder contains no unauthorized mutation forms');
    $referencesPage=$editor->request('modules/curriculum/pages/compliance_references.php');
    task3HttpCheck($referencesPage['status']===200 && str_contains($referencesPage['body'],'complianceEvidenceForm'),'verification permission exposes evidence-based form');
    $readerReferencesPage=$reader->request('modules/curriculum/pages/compliance_references.php');
    task3HttpCheck($readerReferencesPage['status']===200 && !str_contains($readerReferencesPage['body'],'id="complianceEvidenceForm"'),'read-only source registry hides verification form');
    echo json_encode(['passed'=>true,'checks'=>$checks,'suite'=>'Task 3 reference baseline HTTP'], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    foreach($clients as $client)$client->close();if(is_resource($server)){proc_terminate($server);proc_close($server);}foreach(glob($sessions.'/*') as $file)unlink($file);rmdir($sessions);unlink($log);
}
