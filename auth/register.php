<?php
// Public self-registration is unavailable in the administrative CSMS.
// Controlled CLI administrator provisioning remains separate and unchanged.
http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
echo 'Not found.';
exit;
