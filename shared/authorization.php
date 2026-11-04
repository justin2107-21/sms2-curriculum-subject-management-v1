<?php
// Database-backed, request-scoped RBAC authorization.
//
// PHP sessions establish who is authenticated. These helpers query normalized
// MySQL RBAC mappings to determine what that user may do. The legacy
// $_SESSION['role'] and users.role values are not authorization sources.

function authorization_connection(): mysqli
{
    global $conn;

    if (!isset($conn) || !($conn instanceof mysqli)) {
        require_once __DIR__ . '/db.php';
    }

    return $conn;
}

function get_user_role_assignments(int $userId): array
{
    static $roleCache = [];

    if ($userId <= 0) {
        return [];
    }
    if (array_key_exists($userId, $roleCache)) {
        return $roleCache[$userId];
    }

    $db = authorization_connection();
    $stmt = $db->prepare(
        'SELECT roles.id, roles.name, roles.display_name
         FROM roles
         INNER JOIN user_roles ON user_roles.role_id = roles.id
         WHERE user_roles.user_id = ? AND roles.is_active = 1
         ORDER BY roles.name'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $roles = [];

    while ($row = $result->fetch_assoc()) {
        $roles[] = [
            'id' => (int)$row['id'],
            'slug' => (string)$row['name'],
            'display_name' => (string)$row['display_name'],
        ];
    }
    $stmt->close();

    $roleCache[$userId] = $roles;
    return $roles;
}

function get_user_roles(int $userId): array
{
    return array_column(get_user_role_assignments($userId), 'slug');
}

function get_user_permissions(int $userId): array
{
    static $permissionCache = [];

    if ($userId <= 0) {
        return [];
    }
    if (array_key_exists($userId, $permissionCache)) {
        return $permissionCache[$userId];
    }

    $db = authorization_connection();
    $stmt = $db->prepare(
        'SELECT DISTINCT permissions.name
         FROM permissions
         INNER JOIN role_permissions ON role_permissions.permission_id = permissions.id
         INNER JOIN user_roles ON user_roles.role_id = role_permissions.role_id
         INNER JOIN roles ON roles.id = user_roles.role_id AND roles.is_active = 1
         WHERE user_roles.user_id = ?
         ORDER BY permissions.name'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $permissions = [];

    while ($row = $result->fetch_assoc()) {
        $permissions[] = $row['name'];
    }
    $stmt->close();

    $permissionCache[$userId] = $permissions;
    return $permissions;
}

function user_can(int $userId, string $permission): bool
{
    if ($userId <= 0 || $permission === '') {
        return false;
    }

    return in_array($permission, get_user_permissions($userId), true);
}

function require_permission(string $permission, bool $jsonResponse = false): void
{
    $user = require_authenticated_user($jsonResponse);

    if (user_can($user['id'], $permission)) {
        return;
    }

    if ($jsonResponse) {
        json_response(false, 'You are not authorized to perform this action.', [], 403);
    }

    http_response_code(403);
    require __DIR__ . '/access_denied.php';
    exit;
}
