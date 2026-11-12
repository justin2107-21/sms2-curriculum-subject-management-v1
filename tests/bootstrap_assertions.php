<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../shared/db.php';
require_once __DIR__.'/FoundationHttpClient.php';
$schema=$conn->query('SELECT DATABASE()')->fetch_row()[0];
if (!preg_match('/^csms_docker_test_[a-z0-9]+_bootstrap$/D',$schema) || getenv('SMS_TEST_CONFIRM_DATABASE')!==$schema) {
    throw new RuntimeException('Confirmed bootstrap test database required.');
}
function bootCheck(bool $ok,string $label): void { if (!$ok) {throw new RuntimeException('FAIL: '.$label);} echo 'PASS: '.$label.PHP_EOL; }
$mode=$argv[1]??'';
if ($mode==='empty-login') {
    $client=new FoundationHttpClient('http://127.0.0.1:'.(getenv('PORT')?:'8080'));
    $client->request('auth/signin.php');
    $response=$client->request('shared/auth_actions.php',['action'=>'login','username'=>'bootstrap-not-created','password'=>bin2hex(random_bytes(24))],'POST');
    bootCheck($response['status']===500 && !preg_match('/SQLSTATE|mysqli_sql_exception|Stack trace|<b>Warning/i',$response['body']),'Empty schema reproduces safe login HTTP 500');
    $client->close();$mode='empty';
}
if ($mode==='empty') {
    bootCheck($conn->query('SHOW TABLES')->num_rows===0,'No flags/disabled/wrong confirmation leaves database empty');
    exit;
}
if ($mode==='missing-role') { $conn->query("UPDATE roles SET name='bootstrap_missing_admin' WHERE name='admin'");exit; }
if ($mode==='restore-role') { $conn->query("UPDATE roles SET name='admin' WHERE name='bootstrap_missing_admin'");exit; }
if ($mode==='block-role') { $conn->query("CREATE TRIGGER bootstrap_block_role BEFORE INSERT ON user_roles FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Intentional test role failure'");exit; }
if ($mode==='unblock-role') { $conn->query('DROP TRIGGER bootstrap_block_role');exit; }
if ($mode==='block-audit') { $conn->query("CREATE TRIGGER bootstrap_block_audit BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Intentional test audit failure'");exit; }
if ($mode==='unblock-audit') { $conn->query('DROP TRIGGER bootstrap_block_audit');exit; }
if ($mode==='nonadmin-collision') { $conn->begin_transaction();$conn->query('DELETE FROM user_roles');$conn->query("UPDATE users SET role='student'");$conn->commit();exit; }
if ($mode==='restore-admin') { $conn->begin_transaction();$conn->query("UPDATE users SET role='admin'");$conn->query("INSERT INTO user_roles(user_id,role_id) SELECT users.id,roles.id FROM users JOIN roles ON roles.name='admin'");$conn->commit();exit; }
if ($mode==='schema') {
    bootCheck($conn->query('SHOW TABLES')->num_rows===23,'Bootstrap creates expected 23 tables');
    bootCheck((int)$conn->query("SELECT COUNT(*) FROM schema_migrations WHERE status='applied'")->fetch_row()[0]===16,'All 15 migrations plus baseline journaled');
    bootCheck((int)$conn->query('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()')->fetch_row()[0]===41,'All 41 FKs present');
    foreach (['users','user_roles','audit_logs','students','academic_programs','subjects','curricula'] as $table) {
        bootCheck((int)$conn->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0]===0,'Schema/failed provisioning has no rows: '.$table);
    }
    exit;
}
if (!in_array($mode,['admin','smoke'],true)) {throw new RuntimeException('Unknown bootstrap test mode');}
$workerEnvironment=file_get_contents('/proc/1/environ');
foreach (['CSMS_ADMIN_PASSWORD=','CSMS_BOOTSTRAP_ADMIN=','CSMS_RUN_MIGRATIONS='] as $key) {
    bootCheck(strpos($workerEnvironment,$key)===false,'Bootstrap secret/gate absent from Apache environment');
}
$user=$conn->query('SELECT * FROM users')->fetch_all(MYSQLI_ASSOC);
bootCheck(count($user)===1,'Exactly one bootstrap user');$user=$user[0];
bootCheck($user['username']===getenv('SMS_TEST_ADMIN_USER') && $user['role']==='admin','Requested identity and legacy admin role');
bootCheck(password_verify(getenv('SMS_TEST_ADMIN_PASSWORD'),$user['password_hash']) && $user['password_hash']!==getenv('SMS_TEST_ADMIN_PASSWORD'),'Original password verifies against secure hash, including after retry');
bootCheck((int)$conn->query('SELECT COUNT(*) FROM user_roles')->fetch_row()[0]===1,'Exactly one role mapping');
bootCheck((int)$conn->query("SELECT COUNT(*) FROM user_roles JOIN roles ON roles.id=user_roles.role_id WHERE roles.name='admin'")->fetch_row()[0]===1,'Authoritative administrator RBAC mapping');
$events=$conn->query("SELECT * FROM audit_logs WHERE action='ADMIN_BOOTSTRAP_CREATE'")->fetch_all(MYSQLI_ASSOC);
bootCheck(count($events)===1 && $events[0]['user_id']===null && (int)$events[0]['entity_id']===(int)$user['id'],'One atomic CLI bootstrap audit with no impersonated actor');
bootCheck(strpos(json_encode($events),getenv('SMS_TEST_ADMIN_PASSWORD'))===false && strpos(json_encode($events),$user['password_hash'])===false,'Audit excludes password and hash');
foreach (['students','academic_programs','subjects','curricula','curriculum_versions','curriculum_version_subjects','curriculum_subject_requirements','curriculum_elective_groups','subject_equivalencies','compliance_rules','compliance_references'] as $table) {
    bootCheck((int)$conn->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0]===0,'No sample/domain rows: '.$table);
}
if ($mode==='admin') {exit;}
$client=new FoundationHttpClient('http://127.0.0.1:'.(getenv('PORT')?:'8080'));
function bootHttp(array $response,int $status,string $label): void {
    bootCheck($response['status']===$status,$label);
    bootCheck(!preg_match('/SQLSTATE|mysqli_sql_exception|Fatal error|Stack trace|<b>Warning/i',$response['body']),$label.' safe response');
}
bootHttp($client->request('auth/signin.php'),200,'Bootstrap login form');
bootCheck((bool)preg_grep('/HttpOnly/i',$client->headers) && (bool)preg_grep('/SameSite=Lax/i',$client->headers),'Session cookie safeguards');
bootHttp($client->request('shared/auth_actions.php',['action'=>'login','username'=>getenv('SMS_TEST_ADMIN_USER'),'password'=>bin2hex(random_bytes(24))],'POST'),401,'Incorrect password normally rejected');
bootHttp($client->request('shared/auth_actions.php',['action'=>'login','username'=>'nonexistent-'.bin2hex(random_bytes(8)),'password'=>bin2hex(random_bytes(24))],'POST'),401,'Unknown username normally rejected');
bootHttp($client->request('shared/auth_actions.php',['action'=>'login','username'=>getenv('SMS_TEST_ADMIN_USER'),'password'=>getenv('SMS_TEST_ADMIN_PASSWORD')],'POST'),200,'Provisioned administrator login');
foreach (['dashboard/dashboard.php','dashboard/account.php','admin/users.php','admin/permissions.php','admin/audit_logs.php'] as $page) {bootHttp($client->request($page),200,$page);}
foreach (['academic_programs','curricula','subjects','subject_mapping','prerequisite_configuration','electives_manager','program_centric_assignment','subject_centric_assignment','subject_history_view','curriculum_history_view','subject_equivalency','grade_weighting_setup','compliance_validator','curriculum_export'] as $page) {
    bootHttp($client->request('modules/curriculum/pages/'.$page.'.php'),200,'Bootstrap module '.$page);
}
foreach (['database/startup.php','database/provision_admin.php','database/deploy.php','shared/setup.php','.env','.git/config','database/migrations/013_mariadb_subject_fk_compatibility.sql'] as $path) {
    bootCheck(in_array($client->request($path)['status'],[403,404],true),'Bootstrap sensitive path denied: '.$path);
}
bootHttp($client->request('shared/auth_actions.php',['action'=>'logout'],'POST'),200,'Bootstrap logout');
bootHttp($client->request('dashboard/dashboard.php'),302,'Bootstrap session invalidated');
$client->close();
echo "PASS: Production-bootstrap login/module smoke complete; no domain records created.\n";
