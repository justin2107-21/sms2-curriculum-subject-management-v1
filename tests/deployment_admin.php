<?php
// Disposable HTTP regression actor only; not a production provisioning workflow.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/db.php';
$database = $conn->query('SELECT DATABASE()')->fetch_row()[0];
if (!preg_match('/^(csms_docker_test_|sms_db_foundation_test_deploy_)[a-z0-9_]+$/D', $database)
    || getenv('SMS_TEST_CONFIRM_DATABASE') !== $database
    || (int)$conn->query('SELECT COUNT(*) FROM users')->fetch_row()[0] !== 0
    || strlen(getenv('SMS_TEST_ADMIN_PASSWORD') ?: '') < 24) {
    fwrite(STDERR, "Refusing non-disposable, unconfirmed, or non-empty account setup.\n"); exit(1);
}
$hash = password_hash(getenv('SMS_TEST_ADMIN_PASSWORD'), PASSWORD_DEFAULT);
$conn->begin_transaction();
try {
    $stmt = $conn->prepare("INSERT INTO users(username,email,first_name,last_name,password_hash,role) VALUES ('admin','test-admin@example.invalid','DISPOSABLE','TEST',?,'admin')");
    $stmt->bind_param('s',$hash); $stmt->execute(); $stmt->close();
    $id = (int)$conn->insert_id;
    $stmt = $conn->prepare("INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE name='super_admin'");
    $stmt->bind_param('i',$id); $stmt->execute();
    if ($stmt->affected_rows !== 1) { throw new RuntimeException('Test role unavailable.'); }
    $stmt->close(); $conn->commit();
    echo "Disposable test administrator provisioned without outputting credentials.\n";
} catch (Throwable $error) { $conn->rollback(); throw $error; }
