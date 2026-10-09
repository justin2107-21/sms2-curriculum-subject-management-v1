<?php
// Shared by the CLI-only protection suites; never loaded by application routes.
function protectionDatabase(): mysqli
{
    global $conn;
    require_once __DIR__ . '/../shared/db.php';
    $name = (string)$conn->query('SELECT DATABASE()')->fetch_row()[0];
    if (PHP_SAPI !== 'cli' || !preg_match('/^csms_docker_test_task2_[a-z0-9_]+$/D', $name)
        || getenv('SMS_TEST_CONFIRM_DATABASE') !== $name
        || getenv('DB_HOST') !== '127.0.0.1' || getenv('APP_ENV') !== 'development'
        || (int)getenv('DB_PORT') === 3306) {
        throw new RuntimeException('Requires explicitly confirmed isolated Task 2 database on a dedicated port.');
    }
    if ((int)$conn->query('SELECT COUNT(*) FROM academic_programs')->fetch_row()[0] !== 0) {
        throw new RuntimeException('Protection fixture requires empty academic tables.');
    }
    return $conn;
}
function protectionInsert(mysqli $db, string $sql): int { $db->query($sql); return (int)$db->insert_id; }
function protectionSnapshot(mysqli $db): string
{
    $out=[];
    foreach ($db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM) as [$table]) {
        $rows=array_map('json_encode',$db->query("SELECT * FROM `$table`")->fetch_all(MYSQLI_ASSOC));
        sort($rows); $out[$table]=$rows;
    }
    ksort($out); return hash('sha256',json_encode($out,JSON_THROW_ON_ERROR));
}
function protectionFixtures(mysqli $db): array
{
    $password=bin2hex(random_bytes(24));$hash=$db->real_escape_string(password_hash($password,PASSWORD_DEFAULT));
    $actor=protectionInsert($db,"INSERT INTO users(username,email,first_name,last_name,password_hash) VALUES ('task2_admin','task2_admin@example.invalid','Synthetic','Admin','$hash')");
    $viewer=protectionInsert($db,"INSERT INTO users(username,email,first_name,last_name,password_hash) VALUES ('task2_viewer','task2_viewer@example.invalid','Synthetic','Viewer','$hash')");
    $db->query("INSERT INTO user_roles(user_id,role_id) SELECT $actor,id FROM roles WHERE name='admin'");
    $db->query("INSERT INTO user_roles(user_id,role_id) SELECT $viewer,id FROM roles WHERE name='student'");
    $fixtures=[];
    foreach (['legacy','draft','returned','reference','under_review','approved'] as $state) {
        $program=protectionInsert($db,"INSERT INTO academic_programs(program_code,program_name) VALUES ('T2-$state','Synthetic Program')");
        $curriculum=protectionInsert($db,"INSERT INTO curricula(curriculum_code,curriculum_name,academic_program_id) VALUES ('T2-$state','Synthetic Curriculum',$program)");
        $baseline=null;
        if(in_array($state,['draft','returned','under_review','approved'])) {
            $baseline=protectionInsert($db,"INSERT INTO curriculum_versions(curriculum_id,version_label,version_role) VALUES ($curriculum,'Baseline','reference')");
        }
        $role=$state==='legacy'?'unclassified':($state==='reference'?'reference':'proposed');
        $review=in_array($state,['legacy','reference'])?'not_started':$state;
        $baseSql=$baseline===null?'NULL':(string)$baseline;
        $version=protectionInsert($db,"INSERT INTO curriculum_versions(curriculum_id,version_label,version_role,review_status,baseline_version_id,status) VALUES ($curriculum,'Version','$role','$review',$baseSql,'active')");
        $subjects=[];$placements=[];
        for($i=0;$i<4;$i++){
            $subject=protectionInsert($db,"INSERT INTO subjects(subject_code,subject_title,units) VALUES ('T2-$state-$i','Synthetic Subject',3)");
            $subjects[]=$subject;
            if($i<3) $placements[]=protectionInsert($db,"INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES ($version,$subject,'1st Year','1st Semester')");
        }
        $group=protectionInsert($db,"INSERT INTO curriculum_elective_groups(curriculum_version_id,group_name,year_level,semester,required_selections) VALUES ($version,'Synthetic Group','1st Year','1st Semester',1)");
        $members=[];
        foreach(array_slice($subjects,0,2) as $subject)$members[]=protectionInsert($db,"INSERT INTO curriculum_elective_group_subjects(elective_group_id,curriculum_version_id,subject_id) VALUES ($group,$version,$subject)");
        $requirement=protectionInsert($db,"INSERT INTO curriculum_subject_requirements(curriculum_version_id,subject_id,required_subject_id,requirement_type) VALUES ($version,{$subjects[1]},{$subjects[0]},'corequisite')");
        $scheme=protectionInsert($db,"INSERT INTO grade_weighting_schemes(curriculum_version_subject_id,description) VALUES ({$placements[0]},'Synthetic')");
        $component=protectionInsert($db,"INSERT INTO grade_weighting_components(scheme_id,component_name,weight_percentage) VALUES ($scheme,'Existing',50)");
        $reference=protectionInsert($db,"INSERT INTO compliance_references(reference_type,title,issuing_body,verification_status) VALUES ('OTHER','Synthetic Reference','Synthetic','verified')");
        $db->query("INSERT INTO curriculum_version_references(curriculum_version_id,compliance_reference_id,reference_purpose,verification_status_snapshot) VALUES ($version,$reference,'other','verified')");
        $rule=protectionInsert($db,"INSERT INTO compliance_rules(compliance_reference_id,rule_name,rule_type,numeric_value,curriculum_id,technical_status) VALUES ($reference,'Synthetic Minimum','MIN_TOTAL_UNITS',1,$curriculum,'active')");
        $db->query("INSERT INTO curriculum_version_subject_snapshots(curriculum_version_subject_id,subject_code_snapshot,subject_title_snapshot,units_snapshot,capture_method) VALUES ({$placements[2]},'Synthetic Snapshot','Synthetic Snapshot',3,'mapping_capture')");
        $fixtures[$state]=compact('program','curriculum','version','baseline','subjects','placements','group','members','requirement','scheme','component','reference','rule');
    }
    return ['actor'=>$actor,'viewer'=>$viewer,'password'=>$password,'versions'=>$fixtures];
}
function protectionInputs(array $f): array
{
    return [
        'placement'=>['year_level'=>'1st Year','semester'=>'1st Semester','is_required'=>'1'],
        'group'=>['curriculum_version_id'=>$f['version'],'group_name'=>'Changed Group','year_level'=>'1st Year','semester'=>'1st Semester','required_selections'=>1,'required_subject_count'=>1,'subject_ids'=>array_slice($f['subjects'],0,2)],
        'context'=>['program_id'=>$f['program'],'curriculum_id'=>$f['curriculum'],'curriculum_version_id'=>$f['version']],
        'component'=>['component_name'=>'Changed','weight_percentage'=>'25','sequence_order'=>1],
        'configuration'=>['components'=>[['component_name'=>'All','weight_percentage'=>'100']]],
        'subject'=>['subject_code'=>'Changed-'.$f['version'],'subject_title'=>'Changed','units'=>'4'],
        'program'=>['program_code'=>'Changed-'.$f['version'],'program_name'=>'Changed'],
        'curriculum'=>['curriculum_code'=>'Changed-'.$f['version'],'curriculum_name'=>'Changed','academic_program_id'=>$f['program']],
        // Source verification now requires an append-only confirmed identity-evidence event.
        // This legacy mutation fixture only exercises editable metadata updates.
        'reference'=>['reference_type'=>'OTHER','title'=>'Changed','issuing_body'=>'Synthetic','verification_status'=>'draft'],
        'rule'=>['compliance_reference_id'=>$f['reference'],'rule_name'=>'Changed','rule_type'=>'MIN_TOTAL_UNITS','numeric_value'=>'2','scope_type'=>'curriculum','scope_id'=>$f['curriculum'],'technical_status'=>'active'],
    ];
}
