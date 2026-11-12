<?php
// Old address of the Subject Equivalency Tool (it had two tabbed views). It now redirects to the one page, keeping the query string.
$query = http_build_query($_GET);
header('Location: subject_equivalency.php' . ($query !== '' ? '?' . $query : ''), true, 302);
exit;
