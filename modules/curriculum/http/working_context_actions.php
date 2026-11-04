<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/WorkingContextService.php';

$action = curriculum_http_action();
$service = new WorkingContextService($conn, static fn(int $userId, string $permission): bool => user_can($userId, $permission));

try {
    switch ($action) {
        case 'read':
            require_http_method(['GET']);
            json_response(true, 'Working context loaded.', $service->resolve(curriculum_actor_id(), $_GET));

        case 'save':
            require_http_method(['POST']);
            require_valid_csrf_token();
            json_response(true, 'Working context saved.', $service->save(curriculum_actor_id(), $_POST));

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
