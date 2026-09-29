<?php

require_once __DIR__ . '/../../../shared/bootstrap.php';
require_authenticated_user(true);
require_once __DIR__ . '/../../../shared/db.php';
require_once __DIR__ . '/../validators/DomainExceptions.php';

function curriculum_http_action(): string
{
    $source = request_method() === 'POST' ? $_POST : $_GET;
    return is_string($source['action'] ?? null) ? trim($source['action']) : '';
}

function curriculum_require_read(string $permission): void
{
    require_http_method(['GET']);
    require_permission($permission, true);
}

function curriculum_require_mutation(string $permission): void
{
    require_http_method(['POST']);
    require_valid_csrf_token();
    require_permission($permission, true);
}

function curriculum_actor_id(): int
{
    $userId = current_user_id();
    if ($userId === null) {
        json_response(false, 'Authentication required.', [], 401);
    }
    return $userId;
}

function curriculum_handle_error(Throwable $error): void
{
    if ($error instanceof DomainValidationException) {
        json_response(false, $error->getMessage(), [], 422);
    }
    if ($error instanceof DomainConflictException) {
        json_response(false, $error->getMessage(), [], 409);
    }
    if ($error instanceof DomainNotFoundException) {
        json_response(false, $error->getMessage(), [], 404);
    }

    log_application_error('Curriculum domain request failed', $error);
    json_response(false, 'Unable to complete the curriculum request at this time.', [], 500);
}
