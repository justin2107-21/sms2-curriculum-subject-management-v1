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

$databaseActions = ['login', 'otp_verify', 'otp_resend', 'otp_cancel', 'update_profile', 'change_password', 'delete_account'];
if (!in_array($action, $databaseActions, true)) {
    respond(false, 'Unknown action.', [], 400);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/EmailOtp.php';
require_once __DIR__ . '/OtpMailer.php';

/** Finish sign-in: the only place that creates the logged-in session (password only, or password plus a correct email code). */
function complete_login(mysqli $conn, array $user): void
{
    $roleAssignments = get_user_role_assignments((int)$user['id']);
    $roleSlugs = array_column($roleAssignments, 'slug');
    $primaryRole = $roleAssignments[0] ?? null;

    session_regenerate_id(true);
    unset($_SESSION['otp_pending']);
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

/** Pending sign-in: the password was right, a code was sent, but there is no logged-in session yet. */
function otp_pending_payload(): array
{
    $pending = $_SESSION['otp_pending'] ?? [];
    return [
        'otp_required' => true,
        'email_hint' => (string)($pending['masked'] ?? ''),
        'expires_in' => max(0, (int)($pending['expires_at'] ?? 0) - time()),
        'resend_in' => max(0, (int)($pending['resend_at'] ?? 0) - time()),
        'code_length' => EmailOtpService::CODE_LENGTH,
    ];
}

function otp_wait_text(int $seconds): string
{
    return $seconds >= 120 ? ceil($seconds / 60) . ' minutes' : max(1, $seconds) . ' seconds';
}

/** Send a new code (first send after the password, or a resend) and answer the browser. Never logs the code. */
function otp_begin(mysqli $conn, array $user, bool $isResend): void
{
    $userId = (int)$user['id'];
    $service = new EmailOtpService($conn);
    $result = $service->issue($userId);
    if ($result['status'] === 'limit') {
        audit_log('AUTH_OTP_RESEND_BLOCKED', 'user', $userId, 'Sign-in code request blocked: too many codes in 15 minutes.', ['reason' => 'limit']);
        respond(false, 'Too many codes were requested. Please wait ' . otp_wait_text((int)$result['retry_after']) . ' and try again.', ['retry_after' => (int)$result['retry_after']], 429);
    }
    if ($result['status'] === 'cooldown') {
        audit_log('AUTH_OTP_RESEND_BLOCKED', 'user', $userId, 'Sign-in code request blocked: wait before asking again.', ['reason' => 'cooldown']);
        if (!$isResend && (int)$result['valid_until'] > time()) {
            // Signed in again within a minute: the code that was just emailed still works.
            $_SESSION['otp_pending'] = ['user_id' => $userId, 'masked' => EmailOtpService::maskEmail((string)$user['email']), 'started_at' => time(),
                'expires_at' => (int)$result['valid_until'], 'resend_at' => time() + (int)$result['retry_after']];
            respond(true, 'A sign-in code was sent a moment ago. Check your email.', otp_pending_payload());
        }
        respond(false, 'Please wait ' . otp_wait_text((int)$result['retry_after']) . ' before asking for another code.', ['retry_after' => (int)$result['retry_after']], 429);
    }
    $name = trim((string)$user['first_name'] . ' ' . (string)$user['last_name']);
    if (!OtpMailer::send((string)$user['email'], $name, $result['code'], (int)(EmailOtpService::VALID_SECONDS / 60))) {
        $service->discard((int)$result['challenge_id']);
        audit_log('AUTH_OTP_SEND_FAILED', 'user', $userId, 'Sign-in code could not be emailed; the user was not signed in.', ['resend' => $isResend]);
        respond(false, 'We could not send the verification email, so you were not signed in. Please try again later or contact an administrator.', [], 503);
    }
    audit_log('AUTH_OTP_SENT', 'user', $userId, 'Sign-in code sent by email.', ['resend' => $isResend]);
    $started = $isResend ? (int)($_SESSION['otp_pending']['started_at'] ?? time()) : time();
    $_SESSION['otp_pending'] = ['user_id' => $userId, 'masked' => EmailOtpService::maskEmail((string)$user['email']), 'started_at' => $started,
        'expires_at' => (int)$result['expires_at'], 'resend_at' => (int)$result['resend_at']];
    respond(true, $isResend ? 'A new code was sent.' : 'We sent a 6-digit code to your email.', otp_pending_payload());
}

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

            // A new password check always starts clean: drop any earlier pending code screen.
            unset($_SESSION['otp_pending']);
            if (EmailOtpService::requiredFor($user)) {
                // Password is right, but the session is NOT created yet. The code step finishes the sign-in.
                session_regenerate_id(true);
                otp_begin($conn, $user, false);
            }
            // OTP_ENABLED is off, or the account has no valid email: sign-in works as before.
            complete_login($conn, $user);
        }

        case 'otp_cancel': {
            unset($_SESSION['otp_pending']);
            respond(true, 'Back to sign in.');
        }

        case 'otp_resend':
        case 'otp_verify': {
            $pending = $_SESSION['otp_pending'] ?? null;
            if (!is_array($pending) || time() - (int)($pending['started_at'] ?? 0) > EmailOtpService::PENDING_SECONDS) {
                unset($_SESSION['otp_pending']);
                respond(false, 'Your sign-in session expired. Please sign in again.', ['restart' => true], 401);
            }
            $stmt = $conn->prepare(
                'SELECT id, username, email, first_name, last_name, is_active, must_change_password FROM users WHERE id = ? LIMIT 1'
            );
            $pendingId = (int)$pending['user_id'];
            $stmt->bind_param('i', $pendingId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$user || !(int)$user['is_active'] || !EmailOtpService::validEmail($user['email'])) {
                unset($_SESSION['otp_pending']);
                respond(false, 'Your sign-in session expired. Please sign in again.', ['restart' => true], 401);
            }
            if ($action === 'otp_resend') {
                otp_begin($conn, $user, true);
            }

            $result = (new EmailOtpService($conn))->verify((int)$user['id'], (string)($_POST['code'] ?? ''));
            $userId = (int)$user['id'];
            switch ($result['status']) {
                case 'ok':
                    audit_log('AUTH_OTP_VERIFIED', 'user', $userId, 'Sign-in code verified.');
                    complete_login($conn, $user);
                case 'invalid_format':
                    respond(false, 'Enter the 6-digit code from the email.', otp_pending_payload(), 422);
                case 'wrong':
                    audit_log('AUTH_OTP_FAILED', 'user', $userId, 'Sign-in code was wrong.', ['reason' => 'wrong_code', 'attempts_left' => (int)$result['attempts_left']]);
                    respond(false, 'That code is not correct. You have ' . (int)$result['attempts_left'] . ($result['attempts_left'] === 1 ? ' try' : ' tries') . ' left.', otp_pending_payload() + ['attempts_left' => (int)$result['attempts_left']], 401);
                case 'locked':
                    audit_log('AUTH_OTP_FAILED', 'user', $userId, 'Sign-in code locked after too many wrong entries.', ['reason' => 'attempts_exceeded']);
                    respond(false, 'Too many wrong codes. Request a new code and try again.', otp_pending_payload() + ['locked' => true], 429);
                case 'expired':
                    audit_log('AUTH_OTP_EXPIRED', 'user', $userId, 'Sign-in code expired before it was used.');
                    respond(false, 'This code has expired. Request a new code.', otp_pending_payload() + ['expired' => true], 410);
                default: // used or none
                    audit_log('AUTH_OTP_FAILED', 'user', $userId, 'Sign-in code was no longer valid.', ['reason' => 'not_valid']);
                    respond(false, 'This code can no longer be used. Request a new code.', otp_pending_payload() + ['expired' => true], 410);
            }
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
