<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('zend.exception_ignore_args','1');
require_once __DIR__.'/../tests/FoundationHttpClient.php';
$stateDir=(string)($argv[1]??'');$s=json_decode((string)file_get_contents($stateDir.'/state.json'),true,512,JSON_THROW_ON_ERROR);
if(($s['kind']??'')!=='CSMS_TASK9_DISPOSABLE_PREVIEW'||!preg_match('/^csms_docker_test_task7_run_9_[a-f0-9]{32}$/D',$s['database'])||(int)$s['dbPort']===3306||realpath($s['data'])!==realpath($stateDir.'/data'))throw new RuntimeException('Unsafe preview target.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db=new mysqli('127.0.0.1','root',$s['dbPassword'],null,(int)$s['dbPort']);$identity=$db->query('SELECT @@datadir data,@@port port,@@foreign_key_checks fk')->fetch_assoc();
if(strcasecmp((string)realpath($identity['data']),(string)realpath($s['data']))!==0||(int)$identity['port']!==(int)$s['dbPort']||(int)$identity['fk']!==1)throw new RuntimeException('Preview server identity mismatch.');
$db->select_db($s['database']);if($db->query('SELECT DATABASE()')->fetch_row()[0]!==$s['database'])throw new RuntimeException('Wrong preview database.');
$f=json_decode((string)file_get_contents($stateDir.'/fixture.json'),true,512,JSON_THROW_ON_ERROR);
$c=new FoundationHttpClient('http://127.0.0.1:'.$s['httpPort']);$c->request('auth/signin.php');
$login=$c->request('shared/auth_actions.php',['action'=>'login','username'=>'task9_preview','password'=>$s['loginPassword']],'POST');if($login['status']!==200)throw new RuntimeException('Preview synthetic login failed.');
$c->request('modules/curriculum/pages/subject_mapping.php');
$routes=[
    ['program','get',['id'=>$f['program_id']],'program'],
    ['program','summary',['id'=>$f['program_id']],'summary'],
    ['curriculum','list',['academic_program_id'=>$f['program_id'],'per_page'=>50],'curricula'],
    ['version','list',['curriculum_id'=>$f['curriculum_id'],'per_page'=>50],'versions'],
    ['assignment','mapping_list',['curriculum_version_id'=>$f['revision_version_id'],'per_page'=>50],'mappings'],
    ['outcome_mapping','workspace',['curriculum_version_id'=>$f['revision_version_id']],'outcomes'],
    ['clo_assessment','workspace',['placement_id'=>$f['revision_placement_id']],'clos'],
];
foreach($routes as [$endpoint,$action,$params,$key]){
    $r=$c->api($endpoint,$action,$params);
    $data=$r['json'][$key]??null;
    $count=is_array($data)&&array_values($data)===$data?count($data):null;
    echo json_encode(['endpoint'=>$endpoint.'_actions.php','action'=>$action,'parameters'=>$params,'status'=>$r['status'],'record_count'=>$count,'message'=>$r['json']['message']??'']).PHP_EOL;
    if($r['status']!==200)throw new RuntimeException('Preview workflow request failed.');
}
$r=$c->request('dashboard/analytics.php',['version_id'=>$f['revision_version_id']]);echo json_encode(['endpoint'=>'dashboard/analytics.php','status'=>$r['status'],'placements'=>$r['json']['metrics']['mapped_subjects']??null]).PHP_EOL;
if($r['status']!==200)throw new RuntimeException('Preview analytics failed.');
$pages=['dashboard/dashboard.php','modules/curriculum/pages/academic_programs.php','modules/curriculum/pages/curricula.php','modules/curriculum/pages/subject_mapping.php','modules/curriculum/pages/prerequisite_configuration.php','modules/curriculum/pages/electives_manager.php','modules/curriculum/pages/program_centric_assignment.php','modules/curriculum/pages/subject_history_view.php','modules/curriculum/pages/subject_comparison.php','modules/curriculum/pages/grade_weighting_setup.php','modules/curriculum/pages/validate_curriculum.php','modules/curriculum/pages/curriculum_export.php'];
foreach($pages as $page){$response=$c->request($page);echo json_encode(['page'=>$page,'status'=>$response['status']]).PHP_EOL;if($response['status']!==200)throw new RuntimeException('Preview page failed: '.$page);}
$c->close();echo 'PASS: authenticated Program -> Curriculum -> Version -> placements -> IT01-IT13 -> CLO/assessment HTTP flow.'.PHP_EOL;
