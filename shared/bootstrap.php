<?php
// Central bootstrap for every browser page and session-based endpoint.

require_once __DIR__ . '/http.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(function (Throwable $error): void {
    log_application_error('Unhandled application error', $error);

    if (!headers_sent()) {
        http_response_code(500);
    }

    if (request_expects_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'An unexpected server error occurred.']);
        return;
    }

    echo 'An unexpected server error occurred.';
});

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    $secureCookieOverride = getenv('SMS_SESSION_COOKIE_SECURE');
    $secureCookie = $secureCookieOverride === '1'
        ? true
        : ($secureCookieOverride === '0' ? false : request_is_https());

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        // HTTPS receives Secure cookies; local XAMPP HTTP remains usable.
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/audit.php';
