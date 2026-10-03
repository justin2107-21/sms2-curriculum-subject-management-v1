<?php
// Intentionally CLI-only. Opt-in tests mutate only uniquely named test fixtures.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/FoundationHttpClient.php';
require_once __DIR__ . '/../shared/db.php';

$base = rtrim(getenv('SMS_TEST_BASE_URL') ?: '', '/');
$password = getenv('SMS_TEST_ADMIN_PASSWORD') ?: '';
$schema = $conn->query('SELECT DATABASE()')->fetch_row()[0];
if (getenv('SMS_TEST_CONFIRM_DATABASE') !== $schema || $password === ''
    || !in_array(parse_url($base, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
    fwrite(STDERR, "Set SMS_TEST_BASE_URL (loopback), SMS_TEST_ADMIN_PASSWORD and SMS_TEST_CONFIRM_DATABASE to opt in.\n");
    exit(1);
}
$prefix = 'DEV-REGTEST-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
$admin = new FoundationHttpClient($base);
$guest = new FoundationHttpClient($base);
$student = new FoundationHttpClient($base);
$owned = [];
$checks = 0;
$beforeCounts = [];
foreach ($conn->query('SHOW TABLES')->fetch_all(MYSQLI_NUM) as [$table]) {
    $beforeCounts[$table] = (int)$conn->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0];
}
$auditStart = (int)$conn->query('SELECT COALESCE(MAX(id),0) FROM audit_logs')->fetch_row()[0];
function check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    $checks++;
    echo 'PASS: ' . $label . PHP_EOL;
}
function expect(array $response, int $status, string $label): array
{
    check($response['status'] === $status, $label . ' HTTP ' . $status . ' (received ' . $response['status'] . ')');
    check(!preg_match('/SQLSTATE|mysqli_sql_exception|Fatal error|<b>Warning|Stack trace|constraint fails/i', $response['body']), $label . ' safe output');
    return $response['json'] ?? [];
}
function auditCount(): int
{
    global $conn;
    return (int)$conn->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0];
}
function mutate(string $endpoint, string $action, array $data, int $status, ?string $event = null): array
{
    global $admin, $conn;
    $before = auditCount();
    $last = (int)$conn->query('SELECT COALESCE(MAX(id),0) FROM audit_logs')->fetch_row()[0];
    $response = $admin->api($endpoint, $action, $data, 'POST');
    // Capture inserted IDs before assertions, so even a failed audit assertion cleans up.
    $created = [
        'program/create'=>['academic_programs','program'], 'subject/create'=>['subjects','subject'],
        'curriculum/create'=>['curricula','curriculum'],'version/create'=>['curriculum_versions','version'],
        'assignment/assign'=>['curriculum_version_subjects','assignment'],
        'program_assignment/assign'=>['curriculum_version_subjects','assignment'],
        'requirement/create'=>['curriculum_subject_requirements','relationship'],
        'elective_group/create'=>['curriculum_elective_groups','group'],
        'elective_membership/add'=>['curriculum_elective_group_subjects','membership'],
        'equivalency/create'=>['subject_equivalencies','equivalency'],
        'grade_weighting/scheme_create'=>['grade_weighting_schemes','scheme'],
        'grade_weighting/component_create'=>['grade_weighting_components','component'],
        'compliance/reference_create'=>['compliance_references','reference'],
        'compliance/rule_create'=>['compliance_rules','rule'],
        'compliance/validation_run'=>['compliance_validation_runs','run'],
    ];
    if ($response['status'] === 201 && isset($created["$endpoint/$action"])) {
        [$table,$key] = $created["$endpoint/$action"];
        if (isset($response['json'][$key]['id'])) {
            $row = own($table,$response['json'][$key]);
            if ($table === 'curriculum_elective_groups') {
                foreach ($conn->query('SELECT id FROM curriculum_elective_group_subjects WHERE elective_group_id='.(int)$row['id'])->fetch_all(MYSQLI_ASSOC) as $member) {
                    own('curriculum_elective_group_subjects',$member);
                }
            }
            if ($table === 'compliance_validation_runs') {
                foreach ($conn->query('SELECT id FROM compliance_validation_results WHERE validation_run_id='.(int)$row['id'])->fetch_all(MYSQLI_ASSOC) as $result) {
                    own('compliance_validation_results',$result);
                }
            }
        }
    }
    $json = expect($response, $status, "$endpoint/$action");
    $membershipEvents = $event && $endpoint === 'elective_group' && $action === 'create' ? count($data['subject_ids']) : 0;
    check(auditCount() === $before + ($event ? 1 : 0) + $membershipEvents, "$endpoint/$action exact audit count");
    if ($event) {
        $row = $conn->query("SELECT action FROM audit_logs WHERE id > $last ORDER BY id DESC")->fetch_assoc();
        check(($row['action'] ?? '') === $event, "$endpoint/$action audit type");
    }
    return $json;
}
function own(string $table, array $row): array
{
    global $owned;
    $owned[$table][] = (int)$row['id'];
    return $row;
}
function readApi(string $endpoint, string $action, array $data = [], int $status = 200): array
{
    global $admin;
    $before = auditCount();
    $json = expect($admin->api($endpoint, $action, $data), $status, "$endpoint/$action read");
    check(auditCount() === $before, "$endpoint/$action no read audit");
    return $json;
}

$failed = false;
try {
    expect($guest->request('dashboard/dashboard.php'), 302, 'Unauthenticated page');
    expect($guest->api('program', 'list'), 401, 'Unauthenticated API');
    expect($admin->request('auth/signin.php'), 200, 'Sign in page');
    check($admin->csrf !== '', 'Login CSRF field');
    check((bool)preg_grep('/HttpOnly/i', $admin->headers) && (bool)preg_grep('/SameSite=Lax/i', $admin->headers), 'Session cookie attributes');
    expect($admin->request('shared/auth_actions.php', ['action' => 'login', 'username' => 'admin', 'password' => bin2hex(random_bytes(24))], 'POST'), 401, 'Invalid password');
    expect($admin->request('shared/auth_actions.php', ['action' => 'login', 'username' => getenv('SMS_TEST_ADMIN_USER') ?: 'admin', 'password' => $password], 'POST'), 200, 'Admin login');
    expect($admin->request('dashboard/dashboard.php'), 200, 'Admin dashboard');
    expect($admin->request('dashboard/account.php'), 200, 'Account / refreshed CSRF');
    $pages = ['academic_programs','curricula','subjects','subject_mapping','prerequisite_configuration','electives_manager',
        'program_centric_assignment','subject_centric_assignment','subject_history_view','curriculum_history_view',
        'subject_comparison','equivalency_records','grade_weighting_setup','validate_curriculum','compliance_rules',
        'compliance_references','validation_history','curriculum_export'];
    foreach ($pages as $page) {
        $response = $admin->request('modules/curriculum/pages/' . $page . '.php');
        expect($response, 200, 'Admin page ' . $page);
        check(strpos($response['body'], 'rel="icon"') !== false, $page . ' favicon');
        $dom = new DOMDocument(); @$dom->loadHTML($response['body']);
        $ids = [];
        foreach ((new DOMXPath($dom))->query('//*[@id]') as $element) { $ids[] = $element->getAttribute('id'); }
        check(count($ids) === count(array_unique($ids)), $page . ' unique DOM IDs');
        if ($page === 'subjects') {
            foreach (['subjectUnits'] as $id) {
                $field = (new DOMXPath($dom))->query('//*[@id="'.$id.'"]')->item(0);
                check($field !== null && $field->getAttribute('step') === '0.01'
                    && $field->getAttribute('min') === '0', $id . ' decimal step and nonnegative minimum');
            }
        }
    }
    foreach (['admin/users.php','admin/permissions.php','admin/audit_logs.php','admin/audit_logs.php?page=999999'] as $page) {
        expect($admin->request($page), 200, 'Admin system page ' . $page);
    }
    expect($student->request('auth/register.php'), 404, 'Public registration page disabled');
    expect($student->request('auth/register2.php'), 302, 'Legacy registration step redirects');
    expect($student->request('auth/signin.php'), 200, 'Guest sign-in CSRF');
    $temporaryPassword = bin2hex(random_bytes(18));
    $userCount = (int)$conn->query('SELECT COUNT(*) FROM users')->fetch_row()[0];
    $roleCount = (int)$conn->query('SELECT COUNT(*) FROM user_roles')->fetch_row()[0];
    $registrationAuditCount = auditCount();
    expect($student->request('shared/auth_actions.php', [
        'action'=>'register','username'=>$prefix,'email'=>$prefix.'@example.invalid',
        'first_name'=>'DEV-REGTEST','last_name'=>'DISPOSABLE','password'=>$temporaryPassword,
        'confirm_password'=>$temporaryPassword,'role'=>'admin',
    ], 'POST'), 403, 'Public registration rejected even with valid CSRF and forged role');
    expect($student->request('shared/auth_actions.php', ['action'=>'register'], 'POST', ''), 403, 'Registration missing CSRF');
    expect($student->request('shared/auth_actions.php', ['action'=>'register'], 'POST', 'invalid'), 403, 'Registration invalid CSRF');
    expect($student->request('shared/auth_actions.php', ['action'=>'register']), 405, 'GET registration rejected');
    expect($admin->request('shared/auth_actions.php', ['action'=>'register'], 'POST'), 403, 'Authenticated registration also disabled');
    check((int)$conn->query('SELECT COUNT(*) FROM users')->fetch_row()[0] === $userCount
        && (int)$conn->query('SELECT COUNT(*) FROM user_roles')->fetch_row()[0] === $roleCount
        && auditCount() === $registrationAuditCount, 'Rejected registration changes no users, role mappings or audits');

    // CLI-owned disposable fixture, not a replacement web provisioning route.
    $hash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
    $email = $prefix.'@example.invalid';
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO users(username,email,first_name,last_name,password_hash,role) VALUES (?,?,'DEV-REGTEST','DISPOSABLE',?,'student')");
        $stmt->bind_param('sss',$prefix,$email,$hash); $stmt->execute(); $fixtureId = (int)$conn->insert_id; $stmt->close();
        $stmt = $conn->prepare("INSERT INTO user_roles(user_id,role_id,assigned_by) SELECT ?,id,NULL FROM roles WHERE name='student'");
        $stmt->bind_param('i',$fixtureId); $stmt->execute();
        if ($stmt->affected_rows !== 1) { throw new RuntimeException('Temporary student role unavailable.'); }
        $stmt->close(); $conn->commit(); own('users',['id'=>$fixtureId]);
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
    $stmt = $conn->prepare('SELECT id,role FROM users WHERE username=?'); $stmt->bind_param('s',$prefix); $stmt->execute();
    $testUser = own('users', $stmt->get_result()->fetch_assoc()); $stmt->close();
    check($testUser['role'] === 'student', 'CLI fixture retains compatibility role');
    check((int)$conn->query('SELECT COUNT(*) FROM user_roles ur JOIN role_permissions rp ON ur.role_id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE ur.user_id=' . $testUser['id'] . " AND p.module NOT IN ('dashboard','account')")->fetch_row()[0] === 0, 'Unauthorized role has no domain permissions');
    expect($student->request('shared/auth_actions.php', ['action'=>'login','username'=>$prefix,'password'=>$temporaryPassword], 'POST'), 200, 'Student login');
    $studentDashboard = $student->request('dashboard/dashboard.php');
    expect($studentDashboard,200,'Student dashboard');
    check(strpos($studentDashboard['body'], 'sidebar-submenu-item') === false, 'Restricted navigation hidden');
    expect($student->request('dashboard/account.php'),200,'Student self-service settings');
    foreach ($pages as $page) { expect($student->request('modules/curriculum/pages/'.$page.'.php'),403,'Student page '.$page); }
    foreach (['users','permissions','audit_logs'] as $page) { expect($student->request('admin/'.$page.'.php'),403,'Student system '.$page); }

    $guards = [
        ['program','list','create'],['subject','list','create'],['curriculum','list','create'],['version','list','create'],
        ['assignment','mapping_list','assign'],['assignment','mapping_list','update'],['assignment','mapping_list','remove'],
        ['requirement','list','create'],['requirement','list','remove'],['elective_group','list','create'],
        ['elective_membership','list','add'],['elective_membership','list','remove'],
        ['program_assignment','list','assign'],['program_assignment','list','update'],['program_assignment','list','remove'],
        ['equivalency','list','create'],['equivalency','list','update'],
        ['grade_weighting','program_options','scheme_create'],['grade_weighting','program_options','component_update'],
        ['grade_weighting','program_options','component_remove'],['compliance','reference_list','reference_create'],
        ['compliance','rule_list','rule_update'],['compliance','history_list','validation_run'],['export','program_options','download_csv'],
    ];
    foreach ($guards as [$endpoint,$read,$write]) {
        $auditBefore = auditCount();
        expect($student->api($endpoint,$read),403,"Student $endpoint/$read");
        expect($student->api($endpoint,$write,[], 'POST'),403,"Student $endpoint/$write valid CSRF");
        expect($admin->api($endpoint,$write,[], 'POST',''),403,"Missing CSRF $endpoint/$write");
        expect($admin->api($endpoint,$write,[], 'POST','invalid'),403,"Invalid CSRF $endpoint/$write");
        expect($admin->api($endpoint,$write),405,"GET mutation $endpoint/$write");
        check(auditCount()===$auditBefore,"Rejected $endpoint requests no success audit");
    }
    expect($student->api('subject_history','list'),403,'Student history API');
    expect($guest->api('subject','contexts'),401,'Guest placement contexts');
    expect($student->api('subject','contexts'),403,'Student placement contexts');
    expect($admin->api('subject','contexts',[], 'POST'),405,'Placement context read-only method');
    expect($admin->api('subject_history','list',[], 'POST'),405,'History read-only API');
    expect($student->request('shared/student_actions.php',['action'=>'get']),403,'Legacy student CRUD restricted');
    $programs=[]; $subjects=[]; $curricula=[]; $versions=[]; $placements=[];
    for($i=0;$i<12;$i++) {
        $data=['program_code'=>$prefix.'-P'.$i,'program_name'=>$prefix.' Program '.$i,'program_type'=>'SYNTHETIC','status'=>'active'];
        $programs[]=own('academic_programs',mutate('program','create',$data,201,'PROGRAM_CREATE')['program']);
    }
    $p=$programs[0];
    mutate('program','create',['program_code'=>$p['program_code'],'program_name'=>'Duplicate'],409);
    mutate('program','update',['id'=>$p['id'],'program_code'=>$p['program_code'],'program_name'=>$prefix.' Edited Program','program_type'=>'SYNTHETIC','status'=>'inactive'],200,'PROGRAM_UPDATE');
    for($i=0;$i<12;$i++) {
        $data=['subject_code'=>$prefix.'-S'.$i,'subject_title'=>$prefix.' Subject '.$i,'units'=>'3.00','status'=>'active'];
        $subjects[]=own('subjects',mutate('subject','create',$data,201,'SUBJECT_CREATE')['subject']);
    }
    $s=$subjects[0];
    mutate('subject','create',['subject_code'=>$s['subject_code'],'subject_title'=>'Duplicate','units'=>'3'],409);
    mutate('subject','create',['subject_code'=>$prefix.'-NEG','subject_title'=>'Negative','units'=>'-1'],422);
    mutate('subject','update',['id'=>$s['id'],'subject_code'=>$s['subject_code'],'subject_title'=>$prefix.' Edited Subject','units'=>'3.25','lecture_units'=>'3','laboratory_units'=>'0.25','status'=>'inactive'],200,'SUBJECT_UPDATE');
    $decimalSubject = readApi('subject','get',['id'=>$s['id']])['subject'];
    check((string)$decimalSubject['units'] === '3.25' && (string)$decimalSubject['lecture_units'] === '3.00'
        && (string)$decimalSubject['laboratory_units'] === '0.25', 'Integer and fractional subject units persist unchanged');
    foreach([0,1] as $i) {
        $data=['curriculum_code'=>$prefix.'-C'.$i,'curriculum_name'=>$prefix.' Curriculum '.$i,'academic_program_id'=>$programs[$i]['id'],'status'=>'active'];
        $curricula[]=own('curricula',mutate('curriculum','create',$data,201,'CURRICULUM_CREATE')['curriculum']);
    }
    $c=$curricula[0];
    mutate('curriculum','create',['curriculum_code'=>$prefix.'-BAD','curriculum_name'=>'Bad parent','academic_program_id'=>2147483647],404);
    mutate('curriculum','update',['id'=>$c['id'],'curriculum_code'=>$c['curriculum_code'],'curriculum_name'=>$prefix.' Edited Curriculum','academic_program_id'=>$p['id'],'status'=>'inactive'],200,'CURRICULUM_UPDATE');
    foreach([0,0,1] as $i=>$ci) {
        $data=['curriculum_id'=>$curricula[$ci]['id'],'version_label'=>'DEV-V'.$i,'status'=>['draft','active','archived'][$i],'effective_academic_year'=>'DEV-YEAR'];
        $versions[]=own('curriculum_versions',mutate('version','create',$data,201,'CURRICULUM_VERSION_CREATE')['version']);
    }
    $v=$versions[0];
    $emptyPreview=readApi('export','preview',['academic_program_id'=>$programs[0]['id'],'curriculum_id'=>$curricula[0]['id'],'curriculum_version_id'=>$v['id']]);
    check($emptyPreview['summary']['total_lecture_units']===null && $emptyPreview['summary']['total_laboratory_units']===null,
        'Empty-version lecture/lab summary unavailable');
    mutate('version','create',['curriculum_id'=>$c['id'],'version_label'=>$v['version_label']],409);
    readApi('curriculum','get',['id'=>$c['id']]); readApi('version','get',['id'=>$v['id']]);
    for($i=0;$i<11;$i++) {
        $data=['curriculum_version_id'=>$v['id'],'subject_id'=>$subjects[$i]['id'],'year_level'=>'DEV-Year','semester'=>'DEV-Term','sequence_order'=>$i,'is_required'=>1];
        $placements[]=own('curriculum_version_subjects',mutate('assignment','assign',$data,201,'CURRICULUM_SUBJECT_ASSIGN')['assignment']);
    }
    $mapping=['curriculum_version_id'=>$v['id'],'subject_id'=>$s['id']];
    mutate('assignment','assign',$mapping,409);
    mutate('assignment','update',['id'=>$placements[0]['id'],'year_level'=>'DEV-Changed','semester'=>'DEV-Term','sequence_order'=>0,'is_required'=>0],200,'CURRICULUM_SUBJECT_UPDATE');
    foreach([0,1,11] as $i) {
        own('curriculum_version_subjects',mutate('assignment','assign',['curriculum_version_id'=>$versions[1]['id'],'subject_id'=>$subjects[$i]['id'],'year_level'=>'DEV-Year','semester'=>'DEV-Term','sequence_order'=>$i],201,'CURRICULUM_SUBJECT_ASSIGN')['assignment']);
    }
    $shared=own('curriculum_version_subjects',mutate('program_assignment','assign',['curriculum_version_id'=>$versions[2]['id'],'subject_id'=>$s['id']],201,'CURRICULUM_SUBJECT_ASSIGN')['assignment']);
    mutate('program_assignment','update',['id'=>$shared['id'],'year_level'=>'DEV-Shared','semester'=>'DEV-Term','sequence_order'=>0,'is_required'=>1],200,'CURRICULUM_SUBJECT_UPDATE');
    $contextSubjects=readApi('subject','list',['search'=>$prefix,'curriculum_version_id'=>$v['id'],'per_page'=>50]);
    check($contextSubjects['pagination']['total_records']===11,'Subject version filter');
    $contextSubjects=readApi('subject','list',['search'=>$prefix,'year_level'=>'DEV-Year','semester'=>'DEV-Term','per_page'=>50]);
    check($contextSubjects['pagination']['total_records']===12 && count(array_unique(array_column($contextSubjects['subjects'],'id')))===12,
        'Subject contexts do not duplicate shared master records');
    $contextSubjects=readApi('subject','list',['curriculum_version_id'=>$v['id'],'year_level'=>'DEV-Changed','semester'=>'DEV-Term']);
    check($contextSubjects['pagination']['total_records']===1,'Combined placement context uses same placement');
    check(readApi('subject','list',['year_level'=>"DEV-Year' OR 1=1 --"])['pagination']['total_records']===0,'Context text is parameterized');
    readApi('subject','list',['curriculum_version_id'=>-1],422);
    readApi('subject','list',['curriculum_version_id'=>2147483647],404);
    readApi('subject','list',['semester'=>['bad']],422);
    readApi('subject','contexts',['per_page'=>10000],422);
    $contextChoices=readApi('subject','contexts',['search'=>$prefix,'curriculum_version_id'=>$v['id']]);
    check($contextChoices['pagination']['total_records']===3 && in_array('DEV-Changed',$contextChoices['filter_values']['year_levels'],true),
        'Bounded version choices and stored placement labels');
    $singleContext=['academic_program_id'=>$programs[1]['id'],'curriculum_id'=>$curricula[1]['id'],'curriculum_version_id'=>$versions[2]['id']];
    $subjectUpdate=['id'=>$s['id'],'subject_code'=>$s['subject_code'],'subject_title'=>$prefix.' Edited Subject','units'=>'3.25','status'=>'inactive'];
    foreach([[null,null],['0','0'],['3','0.25']] as [$lecture,$lab]) {
        mutate('subject','update',$subjectUpdate+['lecture_units'=>$lecture,'laboratory_units'=>$lab],200,'SUBJECT_UPDATE');
        $summary=readApi('export','preview',$singleContext)['summary'];
        check($lecture===null ? $summary['total_lecture_units']===null : (float)$summary['total_lecture_units']===(float)$lecture,
            'Lecture summary distinguishes NULL, confirmed zero and numeric values');
        check($lab===null ? $summary['total_laboratory_units']===null : (float)$summary['total_laboratory_units']===(float)$lab,
            'Laboratory summary distinguishes NULL, confirmed zero and numeric values');
        if($lecture===null) {
            $print=expect($admin->request('modules/curriculum/pages/curriculum_print.php',$singleContext),200,'NULL-summary print view');
        }
    }
    // Stored, ordered placements are required by the existing sequence validator.
    foreach ([0=>'3rd Year',1=>'2nd Year',2=>'1st Year'] as $index=>$year) {
        mutate('assignment','update',['id'=>$placements[$index]['id'],'year_level'=>$year,'semester'=>'1st Semester','sequence_order'=>$index,'is_required'=>1],200,'CURRICULUM_SUBJECT_UPDATE');
    }
    $req=['curriculum_version_id'=>$v['id'],'subject_id'=>$subjects[0]['id'],'required_subject_id'=>$subjects[1]['id'],'requirement_type'=>'prerequisite'];
    $r1=own('curriculum_subject_requirements',mutate('requirement','create',$req,201,'SUBJECT_REQUIREMENT_CREATE')['relationship']);
    $r2=own('curriculum_subject_requirements',mutate('requirement','create',array_replace($req,['subject_id'=>$subjects[1]['id'],'required_subject_id'=>$subjects[2]['id']]),201,'SUBJECT_REQUIREMENT_CREATE')['relationship']);
    mutate('requirement','create',$req,409);
    mutate('requirement','create',array_replace($req,['required_subject_id'=>$subjects[0]['id']]),422);
    mutate('requirement','create',array_replace($req,['subject_id'=>$subjects[1]['id'],'required_subject_id'=>$subjects[0]['id']]),422);
    mutate('requirement','create',array_replace($req,['subject_id'=>$subjects[2]['id'],'required_subject_id'=>$subjects[0]['id']]),422);
    mutate('requirement','create',array_replace($req,['required_subject_id'=>$subjects[11]['id']]),422);
    $coreq=own('curriculum_subject_requirements',mutate('requirement','create',array_replace($req,['subject_id'=>$subjects[2]['id'],'required_subject_id'=>$subjects[0]['id'],'requirement_type'=>'corequisite']),201,'SUBJECT_REQUIREMENT_CREATE')['relationship']);
    mutate('assignment','remove',['id'=>$placements[0]['id']],409);
    mutate('requirement','remove',['id'=>$coreq['id']],200,'SUBJECT_REQUIREMENT_REMOVE');
    $groupData=['program_id'=>$p['id'],'curriculum_id'=>$c['id'],'curriculum_version_id'=>$v['id'],'group_name'=>$prefix.' Group','year_level'=>'DEV-Year','semester'=>'DEV-Term','required_selections'=>1,'subject_ids'=>[$subjects[3]['id']]];
    $group=own('curriculum_elective_groups',mutate('elective_group','create',$groupData,201,'ELECTIVE_GROUP_CREATE')['group']);
    mutate('elective_group','create',$groupData,409);
    mutate('elective_group','update',array_replace($groupData,['id'=>$group['id'],'group_name'=>$prefix.' Edited Group']),200,'ELECTIVE_GROUP_UPDATE');
    $memberData=['elective_group_id'=>$group['id'],'subject_id'=>$subjects[3]['id']];
    $member=own('curriculum_elective_group_subjects',$conn->query('SELECT * FROM curriculum_elective_group_subjects WHERE elective_group_id='.(int)$group['id'])->fetch_assoc());
    mutate('elective_membership','add',$memberData,422);
    mutate('elective_group','remove',['id'=>$group['id']],422);
    mutate('assignment','remove',['id'=>$placements[3]['id']],409);
    mutate('program_assignment','remove',['id'=>$placements[3]['id']],409);
    readApi('elective_membership','placement_options',['elective_group_id'=>$group['id'],'search'=>$subjects[4]['subject_code']]);
    $pair=['subject_a_id'=>$subjects[0]['id'],'subject_b_id'=>$subjects[1]['id']];
    $comparison=readApi('equivalency','compare',$pair);
    $differenceValues=array_column($comparison['differences'],'result');
    check(in_array('different',$differenceValues,true) && in_array('not_available',$differenceValues,true),'Equivalency objective comparison');
    readApi('equivalency','compare',['subject_a_id'=>$s['id'],'subject_b_id'=>$s['id']],422);
    $equiv=own('subject_equivalencies',mutate('equivalency','create',$pair+['decision_status'=>'pending'],201,'SUBJECT_EQUIVALENCY_CREATE')['equivalency']);
    mutate('equivalency','create',['subject_a_id'=>$subjects[1]['id'],'subject_b_id'=>$subjects[0]['id'],'decision_status'=>'pending'],409);
    mutate('equivalency','update',['id'=>$equiv['id'],'decision_status'=>'equivalent','remarks'=>''],422);
    foreach(['equivalent','not_equivalent','pending'] as $decision) {
        mutate('equivalency','update',['id'=>$equiv['id'],'decision_status'=>$decision,'remarks'=>'DEVELOPMENT SYNTHETIC REVIEW - NOT OFFICIAL'],200,'SUBJECT_EQUIVALENCY_UPDATE');
    }
    $schemeData=['curriculum_version_subject_id'=>$placements[4]['id'],'description'=>'DEVELOPMENT SYNTHETIC WEIGHTS - NOT OFFICIAL'];
    $scheme=own('grade_weighting_schemes',mutate('grade_weighting','scheme_create',$schemeData,201,'GRADE_WEIGHTING_SCHEME_CREATE')['scheme']);
    mutate('grade_weighting','scheme_update',['id'=>$scheme['id'],'description'=>'DEVELOPMENT UPDATED SYNTHETIC WEIGHTS'],200,'GRADE_WEIGHTING_SCHEME_UPDATE');
    mutate('assignment','remove',['id'=>$placements[4]['id']],409);
    $componentData=['scheme_id'=>$scheme['id'],'component_name'=>$prefix.' Component A','weight_percentage'=>'40.00'];
    $weightResult=mutate('grade_weighting','component_create',$componentData,201,'GRADE_WEIGHTING_COMPONENT_CREATE');
    $weightA=own('grade_weighting_components',$weightResult['component']);
    check($weightResult['scheme']['completion_status']==='incomplete','Weight below 100 incomplete');
    mutate('grade_weighting','component_create',$componentData,409);
    $weightResult=mutate('grade_weighting','component_create',array_replace($componentData,['component_name'=>$prefix.' Component B','weight_percentage'=>'60.00']),201,'GRADE_WEIGHTING_COMPONENT_CREATE');
    $weightB=own('grade_weighting_components',$weightResult['component']);
    check($weightResult['scheme']['completion_status']==='complete' && $weightResult['scheme']['total_weight']==='100.00','Weight exactly 100 complete');
    mutate('grade_weighting','component_update',['id'=>$weightB['id'],'component_name'=>$weightB['component_name'],'weight_percentage'=>'60.01'],422);
    mutate('grade_weighting','component_update',['id'=>$weightB['id'],'component_name'=>$weightB['component_name'],'weight_percentage'=>'59.99'],200,'GRADE_WEIGHTING_COMPONENT_UPDATE');
    mutate('grade_weighting','scheme_remove',['id'=>$scheme['id']],409);

    $referenceData=['reference_type'=>'OTHER','reference_code'=>$prefix.'-REF','title'=>'DEVELOPMENT SYNTHETIC COMPLIANCE REFERENCE',
        'issuing_body'=>'DEVELOPMENT TEST SOURCE','verification_status'=>'draft','notes'=>'NOT A REAL CHED, DEPED, OR BESTLINK POLICY.'];
    $reference=own('compliance_references',mutate('compliance','reference_create',$referenceData,201,'COMPLIANCE_REFERENCE_CREATE')['reference']);
    $rules=[]; $ruleInputs=[];
    foreach(['MIN_TOTAL_UNITS','MAX_TOTAL_UNITS','REQUIRED_SUBJECT','MIN_SUBJECT_UNITS','MAX_SUBJECT_UNITS'] as $type) {
        $data=['compliance_reference_id'=>$reference['id'],'rule_name'=>$prefix.' '.$type,'rule_type'=>$type,
            'scope_type'=>'curriculum','scope_id'=>$c['id'],'numeric_value'=>'1','subject_id'=>$subjects[$type==='MAX_SUBJECT_UNITS'?11:0]['id'],
            'technical_status'=>'active','description'=>'DEVELOPMENT SYNTHETIC CHECK - NOT OFFICIAL POLICY'];
        $ruleInputs[]=$data;
        $rules[]=own('compliance_rules',mutate('compliance','rule_create',$data,201,'COMPLIANCE_RULE_CREATE')['rule']);
    }
    mutate('compliance','rule_update',['id'=>$rules[0]['id']]+$ruleInputs[0],200,'COMPLIANCE_RULE_UPDATE');
    $run=mutate('compliance','validation_run',['curriculum_version_id'=>$v['id']],200);
    check($run['run']===null,'Draft references excluded');
    mutate('compliance','reference_update',['id'=>$reference['id']]+array_replace($referenceData,['verification_status'=>'verified']),200,'COMPLIANCE_REFERENCE_UPDATE');
    $run=mutate('compliance','validation_run',['curriculum_version_id'=>$v['id']],201,'COMPLIANCE_VALIDATION_RUN');
    check($run['summary']['passed']===3 && $run['summary']['failed']===1 && $run['summary']['not_evaluated']===1,'All five deterministic rule types PASS / FAIL / NOT_EVALUATED');
    $snapshot=readApi('compliance','run_get',['id'=>$run['run']['id']])['run'];
    mutate('compliance','rule_update',['id'=>$rules[0]['id']]+array_replace($ruleInputs[0],['numeric_value'=>'9']),409);
    mutate('compliance','reference_update',['id'=>$reference['id']]+array_replace($referenceData,['verification_status'=>'inactive']),200,'COMPLIANCE_REFERENCE_UPDATE');
    check(readApi('compliance','run_get',['id'=>$run['run']['id']])['run']===$snapshot,'History snapshot unchanged after source update');
    mutate('compliance','validation_run',['curriculum_version_id'=>$v['id']],200);
    check(readApi('version','get',['id'=>$v['id']])['version']['status']==='draft','Validation never approves curriculum');

    $comparisonArgs=['curriculum_id'=>$c['id'],'version_a_id'=>$v['id'],'version_b_id'=>$versions[1]['id']];
    $history=readApi('subject_history','compare_versions',$comparisonArgs);
    check($history['summary']['added']===1 && $history['summary']['removed']===9 && $history['summary']['changed']===2 && $history['summary']['unchanged']===0,'History Added / Removed / Changed / Unchanged');
    readApi('subject_history','compare_versions',array_replace($comparisonArgs,['version_b_id'=>$versions[2]['id']]),422);
    $sharedHistory=readApi('subject_history','list',['search'=>$s['subject_code']]);
    check($sharedHistory['summary']['programs']===2,'Shared subject across programs');
    $context=['academic_program_id'=>$p['id'],'curriculum_id'=>$c['id'],'curriculum_version_id'=>$v['id']];
    $exportArgs=$context+['include_requirements'=>1,'include_electives'=>1,'include_grade_weighting'=>1,'include_compliance'=>1];
    $export=readApi('export','preview',$exportArgs);
    check(count($export['subjects'])===11 && count($export['requirements'])===2 && count($export['elective_groups'])===1,'Export selected sections');
    check(strpos($export['disclaimer'],'does not by itself constitute official')!==false,'Export not official approval disclaimer');
    readApi('export','preview',[],422);
    mutate('export','download_csv',$exportArgs,200,'CURRICULUM_EXPORT_GENERATE');
    expect($admin->request('modules/curriculum/pages/curriculum_print.php',$exportArgs),200,'Print view HTML');
    expect($student->request('modules/curriculum/pages/curriculum_print.php',$exportArgs),403,'Print authorization');

    $lists=[
        ['program','list',[], 'programs'],['subject','list',[], 'subjects'],['curriculum','list',[], 'curricula'],
        ['version','list',['curriculum_id'=>$c['id']], 'versions'],
        ['assignment','mapping_list',[], 'mappings'],['requirement','list',[], 'requirements'],
        ['elective_group','list',['curriculum_version_id'=>$v['id']], 'groups'],
        ['elective_membership','list',['elective_group_id'=>$group['id']], 'memberships'],
        ['program_assignment','list',['view'=>'program'], 'assignments'],
        ['program_assignment','list',['view'=>'subject'], 'assignments'],['subject_history','list',[], 'history'],
        ['subject_history','compare_versions',$comparisonArgs,'comparison'],['equivalency','list',[], 'equivalencies'],
        ['grade_weighting','component_list',['scheme_id'=>$scheme['id']], 'components'],
        ['compliance','reference_list',[], 'references'],['compliance','rule_list',[], 'rules'],['compliance','history_list',[], 'runs'],
    ];
    foreach($lists as [$endpoint,$action,$args,$key]) {
        foreach([10,20,50] as $perPage) {
            $list=readApi($endpoint,$action,$args+['per_page'=>$perPage]);
            check($list['pagination']['per_page']===$perPage,"$endpoint allowed page size $perPage");
        }
        foreach([['page'=>0],['page'=>'bad'],['per_page'=>100000],['search'=>['invalid']]] as $bad) { readApi($endpoint,$action,$bad+$args,422); }
        $list=readApi($endpoint,$action,$args+['page'=>999999]);
        check($list['pagination']['page']<=$list['pagination']['total_pages'],"$endpoint high page clamps");
        readApi($endpoint,$action,$args+['search'=>$prefix]);
        readApi($endpoint,$action,$args+['search'=>"%_' OR 1=1 --"]);
    }
    foreach(['program'=>'programs','subject'=>'subjects'] as $endpoint=>$key) {
        $first=readApi($endpoint,'list',['search'=>$prefix,'per_page'=>10,'page'=>1]);
        $second=readApi($endpoint,'list',['search'=>$prefix,'per_page'=>10,'page'=>2]);
        check(count($first[$key])===10 && count($second[$key])===2 && !array_intersect(array_column($first[$key],'id'),array_column($second[$key],'id')),"$endpoint two distinct pages");
        $inactive=readApi($endpoint,'list',['search'=>$prefix,'status'=>'inactive']);
        check($inactive['pagination']['total_records']===1,"$endpoint status filter");
        check(readApi($endpoint,'list',['search'=>"%_' OR 1=1 --"])['pagination']['total_records']===0,"$endpoint literal wildcard escaping");
    }
    foreach(['assignment'=>'mapping_list','program_assignment'=>'list','subject_history'=>'list','requirement'=>'list'] as $endpoint=>$action) {
        readApi($endpoint,$action,$context+['search'=>$s['subject_code'],'view'=>'program']);
    }
    $filtered=readApi('assignment','mapping_list',$context+['year_level'=>'3rd Year','semester'=>'1st Semester']);
    check($filtered['pagination']['total_records']===1,'Year and term placement filters');
    readApi('requirement','list',['curriculum_version_id'=>$v['id'],'requirement_type'=>'prerequisite','subject_id'=>$s['id']]);
    readApi('equivalency','list',['search'=>$prefix,'decision_status'=>'pending']);

    mutate('grade_weighting','component_remove',['id'=>$weightA['id']],200,'GRADE_WEIGHTING_COMPONENT_REMOVE');
    mutate('grade_weighting','component_remove',['id'=>$weightB['id']],200,'GRADE_WEIGHTING_COMPONENT_REMOVE');
    mutate('grade_weighting','scheme_remove',['id'=>$scheme['id']],200,'GRADE_WEIGHTING_SCHEME_REMOVE');
    mutate('elective_membership','remove',['id'=>$member['id']],422);
    mutate('elective_group','archive',$groupData+['id'=>$group['id']],200,'ELECTIVE_GROUP_ARCHIVE');
    mutate('requirement','remove',['id'=>$r1['id']],200,'SUBJECT_REQUIREMENT_REMOVE');
    mutate('requirement','remove',['id'=>$r2['id']],200,'SUBJECT_REQUIREMENT_REMOVE');
    mutate('assignment','remove',['id'=>$placements[0]['id']],200,'CURRICULUM_SUBJECT_REMOVE');
    mutate('program_assignment','remove',['id'=>$shared['id']],200,'CURRICULUM_SUBJECT_REMOVE');
    check(readApi('subject','get',['id'=>$s['id']])['subject']['subject_code']===$s['subject_code'],'Removing placements preserves Subject Master');
    $sensitiveKeys=0;
    foreach($conn->query('SELECT metadata_json FROM audit_logs WHERE metadata_json IS NOT NULL')->fetch_all(MYSQLI_ASSOC) as $row) {
        $metadata=json_decode($row['metadata_json'],true);
        array_walk_recursive($metadata,function($value,$key) use (&$sensitiveKeys,$password,$temporaryPassword,$admin,$student) {
            if(preg_match('/password|passphrase|csrf|session|jwt|token|secret|api[_-]?key|authorization|credential/i',(string)$key)
                || (is_string($value) && in_array($value,[$password,$temporaryPassword,$admin->csrf,$student->csrf],true))) { $sensitiveKeys++; }
        });
    }
    check($sensitiveKeys===0,'Audit metadata contains no credential/token keys or tested secret values');
    expect($student->request('shared/auth_actions.php',['action'=>'logout'],'POST'),200,'Student logout');
    expect($student->api('program','list'),401,'Student session invalidated');
    expect($admin->request('shared/auth_actions.php',['action'=>'logout'],'POST'),200,'Admin logout');
    expect($admin->api('program','list'),401,'Old admin session cannot call API');
    expect($admin->request('dashboard/dashboard.php'),302,'Logged out dashboard redirect');
} catch (Throwable $error) {
    $failed = true;
    fwrite(STDERR, $error instanceof RuntimeException ? $error->getMessage() . PHP_EOL : 'FAIL: regression encountered a technical exception; inspect local logs.' . PHP_EOL);
} finally {
    // Logout before deleting the disposable user, preserving valid audit FKs.
    foreach ([$admin, $student] as $client) {
        if ($client->csrf !== '') { $client->request('shared/auth_actions.php', ['action' => 'logout'], 'POST'); }
    }
    // Exact IDs captured from this process only. FK checks remain enabled.
    $order = ['compliance_validation_results','compliance_validation_runs','compliance_rules','compliance_references',
        'grade_weighting_components','grade_weighting_schemes','subject_equivalencies',
        'curriculum_elective_group_subjects','curriculum_elective_groups','curriculum_subject_requirements',
        'curriculum_version_subjects','curriculum_versions','curricula','subjects','academic_programs','users'];
    try {
        $conn->begin_transaction();
        foreach ($order as $table) {
            foreach ($owned[$table] ?? [] as $id) {
                if ($table === 'users') { $conn->query('DELETE FROM user_roles WHERE user_id=' . (int)$id); }
                $stmt = $conn->prepare("DELETE FROM `$table` WHERE id=?");
                $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
            }
        }
        $conn->commit();
        foreach ($beforeCounts as $table => $count) {
            if ($table === 'audit_logs') { continue; }
            check((int)$conn->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0] === $count, 'Cleanup count ' . $table);
        }
    } catch (Throwable $error) {
        $conn->rollback();
        $failed = true;
        fwrite(STDERR, "CLEANUP FAILED. Preserve fixtures for inspection: $prefix\n");
        // IDs are not secrets and allow precise recovery; no broad prefix deletion.
        fwrite(STDERR, json_encode($owned) . PHP_EOL);
    }
    $admin->close(); $guest->close(); $student->close();
}
echo json_encode(['checks' => $checks, 'passed' => !$failed, 'fixture_prefix' => $prefix, 'audit_start' => $auditStart, 'audit_end' => (int)$conn->query('SELECT COALESCE(MAX(id),0) FROM audit_logs')->fetch_row()[0]]) . PHP_EOL;
exit($failed ? 1 : 0);
