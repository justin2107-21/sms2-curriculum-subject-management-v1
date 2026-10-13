<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/curriculum-revision-fixture.php';
require_once __DIR__.'/../modules/curriculum/services/CurriculumCloAssessmentService.php';
$placement=(int)($argv[1]??0);$actor=(int)($argv[2]??0);
require_once __DIR__.'/../shared/db.php';
$db=$conn;$database=(string)$db->query('SELECT DATABASE()')->fetch_row()[0];
if(!preg_match('/^csms_docker_test_task2_[a-z0-9_]+$/D',$database)||getenv('SMS_TEST_CONFIRM_DATABASE')!==$database
    ||getenv('DB_HOST')!=='127.0.0.1'||getenv('APP_ENV')!=='development'||(int)getenv('DB_PORT')===3306) throw new RuntimeException('Unsafe CLO concurrency test database.');
echo 'READY '.$db->thread_id.PHP_EOL;flush();
try {
    (new CurriculumCloAssessmentService($db))->createClo($placement,'RACE-CLO','Must not be written after review starts.',99,$actor);
    echo 'WRITTEN'.PHP_EOL;exit(1);
} catch (DomainConflictException $error) {
    echo 'REJECTED'.PHP_EOL;exit(0);
} catch (Throwable $error) {
    fwrite(STDERR,get_class($error).': '.$error->getMessage().PHP_EOL);exit(2);
}
