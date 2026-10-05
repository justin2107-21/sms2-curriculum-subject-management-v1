<?php
// Legacy modal endpoint retired. All assistance uses the route-bound global endpoint.
require_once __DIR__ . '/support.php';
require_http_method(['POST']);
require_valid_csrf_token();
json_response(false, 'Open the global CIMF Assistant to review this module.', ['status' => 'LEGACY_INTERFACE_RETIRED'], 410);
