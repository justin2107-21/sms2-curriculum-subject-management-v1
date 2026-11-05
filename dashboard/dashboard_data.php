<?php
// Read-only dashboard analytics endpoint (GET). Optional filters:
//   program_id  - one program/strand; omitted = all programs
//   version_id  - one version of that program (charts that need a single version)
// The existing analytics.php version contract is unchanged.
require_once __DIR__ . '/../modules/curriculum/http/support.php';
require_once __DIR__ . '/../modules/curriculum/validators/DomainValidator.php';
require_once __DIR__ . '/DashboardAnalyticsService.php';
require_http_method(['GET']);
require_permission('dashboard.view', true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$userId = curriculum_actor_id();
$db = authorization_connection();
try {
    $programId = isset($_GET['program_id']) && $_GET['program_id'] !== ''
        ? DomainValidator::positiveId($_GET['program_id'], 'Program or strand') : null;
    $versionId = isset($_GET['version_id']) && $_GET['version_id'] !== ''
        ? DomainValidator::positiveId($_GET['version_id'], 'Curriculum version') : null;
    if ($versionId !== null && $programId === null) {
        throw new DomainValidationException('Choose a program or strand before choosing a version.');
    }

    $permissions = DashboardAnalyticsService::PERMISSIONS;
    $can = [];
    foreach ($permissions as $permission) $can[$permission] = user_can($userId, $permission);

    $config = require __DIR__ . '/dashboard_config.php';
    $service = new DashboardAnalyticsService($db, $config);

    $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    $payload = $service->build($userId, $can, $programId, $versionId);
    $payload['programs'] = $can['program.view'] ? $service->programs() : [];
    $db->commit();

    echo json_encode(['success' => true] + $payload, JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    try { $db->rollback(); } catch (Throwable $ignored) {}
    curriculum_handle_error($error);
}
