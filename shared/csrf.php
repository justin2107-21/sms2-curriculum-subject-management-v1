<?php
// Session-based CSRF protection for the existing browser UI.
// A future Bearer/JWT API must use its own authentication model and must not
// depend on these browser-session tokens.

const CSRF_SESSION_KEY = '_csrf_token';
const CSRF_FORM_FIELD = '_csrf_token';

function csrf_token(): string
{
    $token = $_SESSION[CSRF_SESSION_KEY] ?? '';

    if (!is_string($token) || strlen($token) !== 64) {
        $token = bin2hex(random_bytes(32));
        $_SESSION[CSRF_SESSION_KEY] = $token;
    }

    return $token;
}

function csrf_field(): string
{
    return '<input type="hidden" name="' . CSRF_FORM_FIELD . '" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function submitted_csrf_token(): string
{
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $formToken = $_POST[CSRF_FORM_FIELD] ?? '';
    $token = is_string($headerToken) && $headerToken !== '' ? $headerToken : $formToken;

    return is_string($token) ? $token : '';
}

function verify_csrf_token(?string $token = null): bool
{
    $sessionToken = $_SESSION[CSRF_SESSION_KEY] ?? '';
    $token = $token ?? submitted_csrf_token();

    return is_string($sessionToken)
        && strlen($sessionToken) === 64
        && $token !== ''
        && hash_equals($sessionToken, $token);
}

function require_valid_csrf_token(bool $jsonResponse = true): void
{
    if (verify_csrf_token()) {
        return;
    }

    if ($jsonResponse) {
        json_response(false, 'Invalid or expired security token. Please refresh the page and try again.', [], 403);
    }

    http_response_code(403);
    echo 'Invalid or expired security token. Please refresh the page and try again.';
    exit;
}

function rotate_csrf_token(): string
{
    unset($_SESSION[CSRF_SESSION_KEY]);
    return csrf_token();
}
