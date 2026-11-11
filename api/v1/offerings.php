<?php
// REST endpoint for offerings reported by other subsystems. Contract: docs/ui-reference/Offering_API_Plan.md.
// Machine authentication only (Authorization: Bearer <key>). No session, no cookies, no CSRF: this route never loads shared/bootstrap.php.
require_once __DIR__ . '/../../shared/api_bootstrap.php';
require_once __DIR__ . '/../../modules/curriculum/services/OfferingApiService.php';

const API_MAX_BODY_BYTES = 262144;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) api_error('method_not_allowed', 'Use GET or POST.', 405, ['Allow' => 'GET, POST']);
if (getenv('APP_ENV') === 'production' && !api_request_is_https()) api_error('https_required', 'HTTPS is required.', 403);

try {
    $db = api_connection();
    $clients = new ApiClientService($db);
    $client = $clients->authenticate(api_authorization_header());
    $limit = max(1, min(1000, (int)(getenv('API_RATE_LIMIT_PER_MIN') ?: 60)));
    $clients->enforceRateLimit((int)$client['id'], $limit);
    $service = new OfferingApiService($db);

    if ($method === 'GET') {
        if (!in_array($client['scope'], ['read', 'read_write'], true)) api_error('forbidden', 'This API key cannot read offerings.', 403);
        api_respond(200, $service->listOwn($client, $_GET));
    }

    if (!in_array($client['scope'], ['write', 'read_write'], true)) api_error('forbidden', 'This API key cannot report offerings.', 403);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > API_MAX_BODY_BYTES) api_error('payload_too_large', 'The request body is too large (256 KB maximum).', 413);
    $body = (string)file_get_contents('php://input', false, null, 0, API_MAX_BODY_BYTES + 1);
    if (strlen($body) > API_MAX_BODY_BYTES) api_error('payload_too_large', 'The request body is too large (256 KB maximum).', 413);
    try {
        $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        api_error('bad_request', 'The body is not valid JSON.', 400);
    }
    if (!is_array($data) || !isset($data['offerings']) || !is_array($data['offerings']) || !api_is_list($data["offerings"]) || $data['offerings'] === []) {
        api_error('invalid_request', 'Send {"offerings": [ ... ]} with at least one item.', 422);
    }
    if (array_diff(array_keys($data), ['offerings']) !== []) api_error('invalid_request', 'Only the "offerings" key is accepted.', 422);
    if (count($data['offerings']) > OfferingApiService::MAX_ITEMS) api_error('too_many_items', 'At most ' . OfferingApiService::MAX_ITEMS . ' offerings per request.', 413);
    api_respond(200, $service->ingest($client, $data['offerings'], api_request_id()));
} catch (ApiException $error) {
    api_error($error->errorCode, $error->getMessage(), $error->httpStatus, $error->headers);
}
