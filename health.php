<?php
// Liveness only: deliberately independent of database/session state.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(200);
echo '{"status":"ok"}';
