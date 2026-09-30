<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/FoundationHttpClient.php';
$port = getenv('PORT') ?: '8080';
if (!preg_match('/^[1-9][0-9]{0,4}$/D',$port)) { throw new RuntimeException('Invalid test port.'); }
$client = new FoundationHttpClient('http://127.0.0.1:'.$port);
function dockerCheck(bool $ok,string $label): void {
    if (!$ok) { throw new RuntimeException('FAIL: '.$label); }
    echo 'PASS: '.$label.PHP_EOL;
}
$health=$client->request('health.php');
dockerCheck($health['status']===200 && $health['body']==='{"status":"ok"}','Minimal liveness HTTP 200 on injected port');
$root=$client->request('');
dockerCheck($root['status']===302 && (bool)preg_grep('/^Location: auth\/signin\.php$/i',$client->headers),'Root sign-in redirect');
$signin=$client->request('auth/signin.php');
dockerCheck($signin['status']===200 && $client->csrf!=='','Login and CSRF render');
$secure=(bool)preg_grep('/^Set-Cookie:.*;\s*secure(?:;|$)/i',$client->headers);
dockerCheck($secure===(getenv('SMS_SESSION_COOKIE_SECURE')==='1'),'Session Secure flag matches trusted runtime setting');
if (getenv('SMS_DOCKER_EXPECT_DB_FAILURE')==='1') {
    $response=$client->request('shared/auth_actions.php',['action'=>'login','username'=>'nonexistent-test-user','password'=>bin2hex(random_bytes(24))],'POST');
    dockerCheck($response['status']===500 && ($response['json']['message']??'')==='An unexpected server error occurred.','Database failure returns generic JSON');
    foreach (['DB_HOST','DB_USER','DB_USERNAME','DB_PASS','DB_PASSWORD','DB_NAME','DB_DATABASE'] as $name) {
        $value=getenv($name);
        dockerCheck(!$value || strpos($response['body'],$value)===false,'Connection value not exposed');
    }
} else {
    require_once __DIR__.'/../shared/db.php';
    dockerCheck($conn->query('SELECT DATABASE()')->fetch_row()[0]===getenv('SMS_TEST_CONFIRM_DATABASE'),'Configured database and aliases connect');
}
foreach(['css/auth.css','js/page-loader.js','images/BCP_LOGO.png'] as $asset) {
    $response=$client->request($asset); dockerCheck($response['status']===200 && $response['body']!=='','Static asset '.$asset);
}
foreach(['.git/config','.github/workflows/php-lint.yml','.env','database/','database/deploy.php','database/seeds/development_seed.sql','database/migrations/','students.sql','sms_db_full_backup_test.sql','docs/hostforge-deployment.md','tests/foundation.php','docker/apache-vhost.conf','shared/setup.php','Dockerfile'] as $path) {
    dockerCheck(in_array($client->request($path)['status'],[403,404],true),'Sensitive URL denied: '.$path);
}
foreach (['database/migrations/005_create_curriculum_subject_requirements.sql','database/migrations/013_mariadb_subject_fk_compatibility.sql'] as $path) {
    dockerCheck(in_array($client->request($path)['status'],[403,404],true),'Migration SQL denied: '.$path);
}
$client->close();
