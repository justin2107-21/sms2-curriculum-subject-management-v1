<?php

require_once __DIR__ . '/../../../shared/bootstrap.php';
require_authenticated_user(true);
require_once __DIR__ . '/../../../shared/db.php';
require_once __DIR__ . '/../validators/DomainExceptions.php';
require_once __DIR__ . '/../services/CurriculumReadScope.php';

function curriculum_http_action(): string
{
    $source = request_method() === 'POST' ? $_POST : $_GET;
    return is_string($source['action'] ?? null) ? trim($source['action']) : '';
}

function curriculum_require_read(string $permission): void
{
    require_http_method(['GET']);
    require_permission($permission, true);
    CurriculumReadScope::enforceInput(authorization_connection(), $_GET);
}

function curriculum_require_mutation(string $permission): void
{
    require_http_method(['POST']);
    require_valid_csrf_token();
    require_permission($permission, true);
    CurriculumReadScope::enforceInput(authorization_connection(), $_POST);
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
        if ($error instanceof AcademicSequenceException) {
            require_once __DIR__ . '/../../../shared/audit.php';
            audit_log('ACADEMIC_SEQUENCE_BLOCKED', 'curriculum_subject_requirement', null,
                'Academic sequence validation blocked a change.', ['curriculum_version_id' => $error->validation['curriculum_version_id'] ?? null, 'conflicts' => $error->validation['conflicts']]);
        }
        json_response(false, $error->getMessage(), $error instanceof AcademicSequenceException ? ['validation' => $error->validation] : [], 422);
    }
    if ($error instanceof DomainConflictException) {
        json_response(false, $error->getMessage(), [], 409);
    }
    if ($error instanceof DomainNotFoundException) {
        json_response(false, $error->getMessage(), [], 404);
    }

    if ($error instanceof mysqli_sql_exception && in_array((int)$error->getCode(), [1054,1146], true)) {
        log_application_error('Curriculum schema does not match application; verify migration compatibility', $error);
        json_response(false, 'This workspace database schema does not match the application. Ask the local administrator to verify its migration status before continuing. No automatic upgrade was performed.', ['error_code'=>'schema_mismatch'], 503);
    }
    log_application_error('Curriculum domain request failed', $error);
    json_response(false, 'The curriculum service could not complete this request. Retry it; if the problem continues, ask the administrator to check the application log.', ['error_code'=>'service_unavailable'], 500);
}
