<?php
// Authentication and account actions for the existing session-based UI.

require_once __DIR__ . '/bootstrap.php';

require_http_method(['POST']);
require_valid_csrf_token();

$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

// CSMS accounts are provisioned by authorized operators, never public signup.
// Keep this before database access; registration stays disabled during outages.
if ($action === 'register') {
    json_response(false, 'Public registration is disabled. Contact your system administrator.', [], 403);
}

function respond(bool $ok, string $message, array $extra = [], int $status = 200): void
{
    json_response($ok, $message, $extra, $status);
}
// Logout should remain available even if the database is temporarily down.
if ($action === 'logout') {
    // Ending the caller's session does not require an active-account database lookup.
    // Keep logout available during database outages while retaining CSRF and idle checks.
    if (empty($_SESSION['user_id']) || expire_authenticated_session_if_idle()) {
        respond(false, 'Authentication required.', [], 401);
    }
    $logoutUserId = (int)$_SESSION['user_id'];
    audit_log(
        'AUTH_LOGOUT',
        'user',
        $logoutUserId,
        'Authenticated user logged out.'
    );
    destroy_authenticated_session();
    respond(true, 'Logged out.');
}

if ($action === 'activity') {
    require_authenticated_user(true);
    respond(true, 'Session activity recorded.');
}

$databaseActions = ['login', 'update_profile', 'change_password', 'delete_account'];
if (!in_array($action, $databaseActions, true)) {
    respond(false, 'Unknown action.', [], 400);
}

require_once __DIR__ . '/db.php';

try {
    switch ($action) {
        case 'login': {
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');

            if (!$username || !$password) {
                audit_log(
                    'AUTH_LOGIN_FAILURE',
                    'user',
                    null,
                    'Login attempt failed.',
                    ['username' => $username, 'reason' => 'missing_credentials']
                );
                respond(false, 'Username and password are required.', [], 422);
            }

            $stmt = $conn->prepare(
                'SELECT id, username, email, first_name, last_name, password_hash, is_active, must_change_password
                 FROM users WHERE username = ? LIMIT 1'
            );
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$user || !password_verify($password, $user['password_hash']) || !(int)$user['is_active']) {
                audit_log(
                    'AUTH_LOGIN_FAILURE',
                    'user',
                    null,
                    'Login attempt failed.',
                    ['username' => $username, 'reason' => 'invalid_credentials']
                );
                respond(false, 'Invalid username or password.', [], 401);
            }

            $roleAssignments = get_user_role_assignments((int)$user['id']);
            $roleSlugs = array_column($roleAssignments, 'slug');
            $primaryRole = $roleAssignments[0] ?? null;

            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['role_id'] = $primaryRole['id'] ?? null;
            $_SESSION['role_slug'] = $primaryRole['slug'] ?? '';
            $_SESSION['role_slugs'] = $roleSlugs;
            // Retain the old display slot for legacy pages, but derive it from
            // the real normalized assignment, never users.role.
            $_SESSION['role'] = $primaryRole['display_name'] ?? '';
            $_SESSION['_last_activity_at'] = time();
            rotate_csrf_token();

            audit_log(
                'AUTH_LOGIN_SUCCESS',
                'user',
                (int)$user['id'],
                'User logged in successfully.'
            );

            respond(true, 'Login successful.', [
                'must_change_password'=>(bool)$user['must_change_password'],
                'redirect'=>$user['must_change_password'] ? '../dashboard/account.php' : '../dashboard/loading.php',
                'role_slug'=>$primaryRole['slug'] ?? null,
            ]);
        }

        case 'update_profile': {
            require_permission('account.update', true);
            $user = current_user();
            $firstName = trim((string)($_POST['first_name'] ?? ''));
            $lastName = trim((string)($_POST['last_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));

            if (!$firstName || !$lastName || !$email || !$username) {
                respond(false, 'All profile fields are required.', [], 422);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                respond(false, 'Invalid email address.', [], 422);
            }

            $userId = $user['id'];
            $stmt = $conn->prepare(
                'SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ? LIMIT 1'
            );
            $stmt->bind_param('ssi', $username, $email, $userId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                respond(false, 'Username or email is already used by another account.', [], 409);
            }
            $stmt->close();

            $stmt = $conn->prepare(
                'UPDATE users SET first_name = ?, last_name = ?, email = ?, username = ? WHERE id = ?'
            );
            $conn->begin_transaction();
            try {
                $stmt->bind_param('ssssi', $firstName, $lastName, $email, $username, $userId);
                $stmt->execute();
                $stmt->close();
                audit_log_required(
                    'ACCOUNT_PROFILE_UPDATE',
                    'user',
                    $userId,
                    'Authenticated user updated their profile.',
                    ['changed_fields' => ['first_name', 'last_name', 'email', 'username']]
                );
                $conn->commit();
            } catch (Throwable $error) {
                $conn->rollback();
                throw $error;
            }

            $_SESSION['first_name'] = $firstName;
            $_SESSION['last_name'] = $lastName;
            $_SESSION['email'] = $email;
            $_SESSION['username'] = $username;
            respond(true, 'Profile updated successfully.');
        }

        case 'change_password': {
            require_permission('account.update', true);
            $user = current_user();
            $current = (string)($_POST['current_password'] ?? '');
            $new = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');

            if (!$current || !$new || !$confirm) {
                respond(false, 'All password fields are required.', [], 422);
            }
            if (strlen($new) < 6) {
                respond(false, 'New password must be at least 6 characters.', [], 422);
            }
            if (strlen($new) > 72 || strpos($new, "\0") !== false || ($user['must_change_password'] && strlen($new) < 12)) {
                respond(false, 'Temporary passwords must be replaced with a password of 12–72 bytes.', [], 422);
            }
            if ($new === $current) respond(false, 'Choose a new password different from your temporary/current password.', [], 422);
            if ($new !== $confirm) {
                respond(false, 'New passwords do not match.', [], 422);
            }

            $userId = $user['id'];
            $stmt = $conn->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || !password_verify($current, $row['password_hash'])) {
                respond(false, 'Current password is incorrect.', [], 403);
            }

            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?');
            $conn->begin_transaction();
            try {
                $stmt->bind_param('si', $newHash, $userId);
                $stmt->execute();
                $stmt->close();
                audit_log_required(
                    'ACCOUNT_PASSWORD_CHANGE',
                    'user',
                    $userId,
                    'Authenticated user changed their password.'
                );
                $conn->commit();
            } catch (Throwable $error) {
                $conn->rollback();
                throw $error;
            }
            respond(true, 'Password changed successfully.');
        }

        case 'delete_account': {
            require_once __DIR__.'/../database/ProvisionalRoles.php';
            $accountUser = require_authenticated_user(true);
            if (array_intersect(get_user_roles((int)$accountUser['id']), array_keys(ProvisionalRoles::definitions()))) {
                respond(false, 'Prototype accounts are retained. Contact an administrator for account deactivation.', [], 403);
            }
            require_permission('account.update', true);
            $user = current_user();
            $userId = $user['id'];
            $stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
            $conn->begin_transaction();
            try {
                audit_log_required(
                    'ACCOUNT_DELETE',
                    'user',
                    $userId,
                    'Authenticated user deleted their account.',
                    ['username' => $user['username']]
                );
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $stmt->close();
                $conn->commit();
            } catch (Throwable $error) {
                $conn->rollback();
                throw $error;
            }
            destroy_authenticated_session();
            respond(true, 'Account deleted.');
        }
    }
} catch (Throwable $error) {
    log_application_error('Authentication action failed', $error);
    respond(false, 'Unable to complete the request at this time.', [], 500);
}
