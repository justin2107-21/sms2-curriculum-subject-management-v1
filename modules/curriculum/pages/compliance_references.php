<?php
// Old address of one of the four Compliance Validator screens. It now redirects (302) to the one page with the right view, keeping the query string.
$query = $_GET;
unset($query['view'], $query['tab']);
$query = ['view' => 'sources'] + $query;
header('Location: compliance_validator.php?' . http_build_query($query), true, 302);
exit;
