<?php
// Authentication helpers for the existing PHP-session browser application.

const CSMS_IDLE_TIMEOUT_SECONDS = 180;

function expire_authenticated_session_if_idle(): bool
{
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    $lastActivity = filter_var($_SESSION['_last_activity_at'] ?? null, FILTER_VALIDATE_INT);
    if ($lastActivity === false || $lastActivity === null || $lastActivity <= 0) {
        // Do not grant an untracked session a fresh timeout window. Sessions
        // created before idle tracking must authenticate again once.
        destroy_authenticated_session();
        return true;
    }

    if (time() - $lastActivity >= CSMS_IDLE_TIMEOUT_SECONDS) {
        destroy_authenticated_session();
        return true;
    }

    $_SESSION['_last_activity_at'] = time();
    return false;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    if (expire_authenticated_session_if_idle()) {
        return null;
    }

    // Account activation is authoritative on every request, including existing sessions.
    static $accountState = [];
    $id = (int)$_SESSION['user_id'];
    if (!array_key_exists($id, $accountState)) {
        $db = authorization_connection();
        $stmt = $db->prepare('SELECT is_active,must_change_password FROM users WHERE id=?');
        $stmt->bind_param('i', $id); $stmt->execute();
        $accountState[$id] = $stmt->get_result()->fetch_assoc(); $stmt->close();
    }
    if (!$accountState[$id] || !(int)$accountState[$id]['is_active']) {
        destroy_authenticated_session(); return null;
    }

    // Keep role data presented to the application aligned with the normalized
    // assignment, even for sessions created before this RBAC correction.
    $assignments = get_user_role_assignments($id);
    $roleSlugs = array_column($assignments, 'slug');
    $primaryRole = $assignments[0] ?? null;
    $_SESSION['role_id'] = $primaryRole['id'] ?? null;
    $_SESSION['role_slug'] = $primaryRole['slug'] ?? '';
    $_SESSION['role_slugs'] = $roleSlugs;
    $_SESSION['role'] = $primaryRole['display_name'] ?? '';

    return [
        'id' => (int)$_SESSION['user_id'],
        'must_change_password' => (bool)$accountState[$id]['must_change_password'],
        'username' => (string)($_SESSION['username'] ?? ''),
        'email' => (string)($_SESSION['email'] ?? ''),
        'first_name' => (string)($_SESSION['first_name'] ?? ''),
        'last_name' => (string)($_SESSION['last_name'] ?? ''),
        // Convenience/display data comes from user_roles -> roles. All access
        // decisions continue to resolve live permissions from MySQL.
        'role' => (string)($_SESSION['role'] ?? ''),
        'role_id' => (int)($_SESSION['role_id'] ?? 0),
        'role_slug' => (string)($_SESSION['role_slug'] ?? ''),
        'roles' => $roleSlugs,
    ];
}

function is_authenticated(): bool
{
    return current_user() !== null;
}

function current_user_id(): ?int
{
    $user = current_user();
    return $user === null ? null : $user['id'];
}

function require_authenticated_user(bool $jsonResponse = false): array
{
    $user = current_user();

    if ($user !== null) {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($user['must_change_password'] && PHP_SAPI !== 'cli'
            && !str_ends_with($script, '/dashboard/account.php')
            && !str_ends_with($script, '/shared/auth_actions.php')) {
            if ($jsonResponse) json_response(false, 'Change your temporary password in Account Settings before continuing.', [], 403);
            $root = preg_replace('~/(?:modules|admin|dashboard)/.*$~', '', $script);
            header('Location: '.$root.'/dashboard/account.php'); exit;
        }
        return $user;
    }

    if ($jsonResponse) {
        json_response(false, 'Authentication required.', [], 401);
    }

    header('Location: ../auth/signin.php');
    exit;
}

function destroy_authenticated_session(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => (bool)$params['secure'],
            'httponly' => (bool)$params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
