<?php
// Container-only script, outside DocumentRoot. No database readiness claim.
$port = getenv('PORT') ?: '8080';
if (!preg_match('/^[1-9][0-9]{0,4}$/D', $port) || (int)$port > 65535) {
    exit(1);
}
$context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
$body = @file_get_contents('http://127.0.0.1:' . $port . '/health.php', false, $context);
exit($body === '{"status":"ok"}' && preg_match('/^HTTP\/\S+ 200\b/', $http_response_header[0] ?? '') ? 0 : 1);
