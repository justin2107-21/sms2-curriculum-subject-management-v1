<?php
// Legacy address kept for old bookmarks: ?view=curriculum goes to the comparison screen, anything else to Subject Offering History.
$legacyQuery = $_GET;
$legacyView = strtolower(trim((string)($legacyQuery['view'] ?? $legacyQuery['history_view'] ?? 'subject')));
unset($legacyQuery['view'], $legacyQuery['history_view']);
$legacyTarget = $legacyView === 'curriculum' ? 'curriculum_history_view.php' : 'subject_history_view.php';
$legacyQueryString = http_build_query($legacyQuery);
header('Location: ' . $legacyTarget . ($legacyQueryString !== '' ? '?' . $legacyQueryString : ''), true, 302);
exit;
