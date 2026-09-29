<?php
// Authentication helpers for the existing PHP-session browser application.

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    return [
        'id' => (int)$_SESSION['user_id'],
        'username' => (string)($_SESSION['username'] ?? ''),
        'email' => (string)($_SESSION['email'] ?? ''),
        'first_name' => (string)($_SESSION['first_name'] ?? ''),
        'last_name' => (string)($_SESSION['last_name'] ?? ''),
        // Temporary display/compatibility value only. Authorization helpers
        // deliberately ignore it and query normalized RBAC mappings.
        'role' => (string)($_SESSION['role'] ?? 'student'),
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

    session_destroy();
}
