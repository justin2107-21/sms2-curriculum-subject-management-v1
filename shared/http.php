<?php
// Shared HTTP and error-response helpers.

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function request_is_https(): bool
{
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));

    return ($https !== '' && $https !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function request_expects_json(): bool
{
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $script = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));

    return str_contains($accept, 'application/json') || str_ends_with($script, '_actions.php');
}

function json_response(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function require_http_method(array $allowedMethods): void
{
    $allowed = array_values(array_unique(array_map('strtoupper', $allowedMethods)));

    if (!in_array(request_method(), $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        json_response(false, 'Method not allowed.', [], 405);
    }
}

function log_application_error(string $context, Throwable $error): void
{
    error_log(sprintf(
        '[SMS2] %s: %s in %s:%d',
        $context,
        $error->getMessage(),
        $error->getFile(),
        $error->getLine()
    ));
}
