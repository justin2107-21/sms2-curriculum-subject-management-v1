<?php
// Append-only audit service.
//
// Action names use uppercase capability-style constants such as
// AUTH_LOGIN_SUCCESS, ACCOUNT_PROFILE_UPDATE, and STUDENT_CREATE. Curriculum
// and administration actions use the same convention. RBAC_PERMISSION_CHANGE
// and RBAC_ROLE_ASSIGNMENT record guarded administration and local role setup.
//
// audit_log() is best-effort for events such as login failure and logout. It
// never interrupts the primary workflow, but records technical failures in the
// PHP error log. Critical database mutations use audit_log_required() inside
// the same transaction so the mutation and its audit evidence commit or roll
// back together.

function audit_connection(): mysqli
{
    global $conn;

    if (!isset($conn) || !($conn instanceof mysqli)) {
        require_once __DIR__ . '/db.php';
    }

    return $conn;
}

function audit_sanitize_metadata(array $metadata): array
{
    $safe = [];

    foreach ($metadata as $key => $value) {
        $keyText = (string)$key;
        if (preg_match('/password|passphrase|csrf|session|jwt|token|secret|api[_-]?key|authorization|credential/i', $keyText)) {
            continue;
        }

        if (is_array($value)) {
            $safe[$key] = audit_sanitize_metadata($value);
        } elseif (is_scalar($value) || $value === null) {
            $safe[$key] = is_string($value) ? substr($value, 0, 1000) : $value;
        }
    }

    return $safe;
}

function audit_write(
    string $action,
    string $entityType,
    ?int $entityId,
    string $description,
    array $metadata = []
): void {
    $action = trim($action);
    $entityType = trim($entityType);
    $description = trim($description);

    if (!preg_match('/^[A-Z][A-Z0-9_]{2,99}$/', $action)) {
        throw new InvalidArgumentException('Invalid audit action name.');
    }
    if (!preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $entityType)) {
        throw new InvalidArgumentException('Invalid audit entity type.');
    }
    if ($description === '') {
        throw new InvalidArgumentException('Audit description is required.');
    }

    $userId = function_exists('current_user_id') ? current_user_id() : null;
    $ipAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $safeMetadata = audit_sanitize_metadata($metadata);
    $metadataJson = $safeMetadata === []
        ? null
        : json_encode($safeMetadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $description = substr($description, 0, 255);
    $ipAddress = $ipAddress === '' ? null : substr($ipAddress, 0, 45);
    $userAgent = $userAgent === '' ? null : substr($userAgent, 0, 512);

    $db = audit_connection();
    $stmt = $db->prepare(
        'INSERT INTO audit_logs
            (user_id, action, entity_type, entity_id, description, ip_address, user_agent, metadata_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'ississss',
        $userId,
        $action,
        $entityType,
        $entityId,
        $description,
        $ipAddress,
        $userAgent,
        $metadataJson
    );
    $stmt->execute();
    $stmt->close();
}

function audit_log(
    string $action,
    string $entityType,
    ?int $entityId,
    string $description,
    array $metadata = []
): void {
    try {
        audit_write($action, $entityType, $entityId, $description, $metadata);
    } catch (Throwable $error) {
        log_application_error('Audit logging failed', $error);
    }
}

function audit_log_required(
    string $action,
    string $entityType,
    ?int $entityId,
    string $description,
    array $metadata = []
): void {
    audit_write($action, $entityType, $entityId, $description, $metadata);
}
