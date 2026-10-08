<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../shared/database_config.php';
require_once __DIR__.'/../modules/curriculum/services/CurriculumVersionService.php';
$config=csms_database_config();
if($config['host']!=='127.0.0.1'||$config['port']===3306||!preg_match('/^csms_docker_test_task2_[a-z0-9_]+$/D',$config['database'])||getenv('SMS_TEST_CONFIRM_DATABASE')!==$config['database'])throw new RuntimeException('Unsafe worker target');
$conn=csms_database_connect($config);$id=(int)$argv[2];
try{
    if($argv[1]==='rename'){
        (new CurriculumVersionService($conn))->rename($id,['version_label'=>'Concurrent Forbidden'],1);
    }elseif($argv[1]==='baseline'){
        $conn->begin_transaction();
        (new CurriculumVersionEditPolicy($conn))->validateBaseline($id,(int)$argv[3]);
        // Test-only synthetic assignment: never exposed through application HTTP.
        $stmt=$conn->prepare("UPDATE curriculum_versions SET version_role='proposed',review_status='draft',baseline_version_id=? WHERE id=?");
        $baseline=(int)$argv[3];$stmt->bind_param('ii',$baseline,$id);$stmt->execute();$conn->commit();
    }else throw new RuntimeException('Unknown worker operation');
    echo 'ACCEPTED';exit(1);
}catch(DomainConflictException $e){$conn->rollback();echo 'CONFLICT';}
