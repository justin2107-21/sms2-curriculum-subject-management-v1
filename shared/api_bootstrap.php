<?php
// Bootstrap for machine-to-machine API routes ONLY (api/v1/*). Deliberately does not start a session, read cookies or check CSRF:
// the bearer key is the only credential. Every response is JSON with a stable error format.
// Never include this from browser (session) pages.

require_once __DIR__ . '/database_config.php';

function api_request_id(): string
{
    static $id = null;
    return $id ??= bin2hex(random_bytes(16));
}

function api_respond(int $status, array $body, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    foreach ($headers as $name => $value) header($name . ': ' . $value);
    echo json_encode($body + ['request_id' => api_request_id()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(string $code, string $message, int $status, array $headers = []): void
{
    api_respond($status, ['error' => ['code' => $code, 'message' => $message]], $headers);
}

// Anything unexpected: generic message to the caller, detail only in the server log (never a stack trace or SQL).
set_exception_handler(static function (Throwable $e): void {
    error_log('[API] category=INTERNAL_' . get_class($e));
    if (!headers_sent()) api_error('server_error', 'The request could not be completed.', 500);
});
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set('display_errors', '0');

function api_connection(): mysqli
{
    static $conn = null;
    return $conn ??= csms_database_connect(csms_database_config());
}

function api_request_is_https(): bool
{
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    return ($https !== '' && $https !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function api_authorization_header(): ?string
{
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) if (!empty($_SERVER[$key])) return (string)$_SERVER[$key];
    if (function_exists('getallheaders')) foreach (getallheaders() as $name => $value) if (strcasecmp($name, 'Authorization') === 0) return (string)$value;
    return null;
}
