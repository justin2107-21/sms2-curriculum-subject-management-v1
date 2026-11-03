<?php
// DB-free: the idle timeout is 300 s on the server and reaches the client only through data-timeout-seconds.
require_once __DIR__ . '/../shared/auth.php';

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failures++;
};
$root = dirname(__DIR__);
$js = file_get_contents($root . '/js/session-timeout.js');
$topbar = file_get_contents($root . '/shared/topbar.php');
$layout = file_get_contents($root . '/shared/ui/layout.php');

$check(CSMS_IDLE_TIMEOUT_SECONDS === 300, 'server idle timeout is 300 seconds');
$check(str_contains($topbar, '$topbarIdleTimeoutSeconds = CSMS_IDLE_TIMEOUT_SECONDS;'), 'topbar reads the constant');
$check(str_contains($topbar, 'data-timeout-seconds="<?= $topbarIdleTimeoutSeconds ?>"'), 'topbar passes it to the client');
$check(str_contains($layout, '$timeout = CSMS_IDLE_TIMEOUT_SECONDS;'), 'bootstrap layout reads the constant');
$check(str_contains($js, 'Number(script.dataset.timeoutSeconds)'), 'client reads data-timeout-seconds');
foreach (['topbar' => $topbar, 'layout' => $layout, 'client' => $js] as $name => $source) {
    $check(preg_match('/\b(180|240|300)\b/', $source) === 0, "$name has no hard-coded timeout number");
}

// Warning (UI_Decisions D9): opens at timeout - 60 s, window comes from the same place as the timeout.
$check(CSMS_IDLE_WARNING_SECONDS === 60, 'warning window is 60 seconds');
$check(CSMS_IDLE_TIMEOUT_SECONDS - CSMS_IDLE_WARNING_SECONDS === 240, 'warning opens at 240 seconds idle');
$check(CSMS_IDLE_WARNING_SECONDS < CSMS_IDLE_TIMEOUT_SECONDS, 'warning window is shorter than the timeout');
$check(str_contains($topbar, 'data-warning-seconds="<?= CSMS_IDLE_WARNING_SECONDS ?>"'), 'topbar passes the warning window');
$check(str_contains($layout, 'data-warning-seconds="<?= (int)CSMS_IDLE_WARNING_SECONDS ?>"'), 'bootstrap layout passes the warning window');
$check(str_contains($js, 'script.dataset.warningSeconds') && str_contains($js, 'timeoutMs - warningSeconds * 1000'), 'client derives warning time as timeout minus window');
$check(preg_match('/\b60\b/', $js) === 0, 'client has no hard-coded 60');

// Keep-alive ("Stay signed in") reuses the existing endpoint: POST only, CSRF-checked before the action
// is read, authenticated (401 once expired).
$actions = file_get_contents($root . '/shared/auth_actions.php');
$check(str_contains($js, "post('activity')"), 'Stay signed in calls the activity keep-alive');
$csrfAt = strpos($actions, 'require_valid_csrf_token();');
$activityAt = strpos($actions, "\$action === 'activity'");
$check($csrfAt !== false && $activityAt !== false && $csrfAt < $activityAt, 'keep-alive endpoint is CSRF-checked before the action runs');
$check(str_contains($actions, "require_http_method(['POST']);"), 'keep-alive endpoint is POST only');
$check(preg_match("/action === 'activity'\\) \\{\\s*require_authenticated_user\\(true\\);/", $actions) === 1, 'keep-alive requires an authenticated user');

// Server-side enforcement does not depend on JavaScript.
$_SESSION = ['user_id' => 1, '_last_activity_at' => time() - 299];
$check(expire_authenticated_session_if_idle() === false, 'session active at 299 s idle');
$_SESSION = ['user_id' => 1, '_last_activity_at' => time() - 300];
// @: the CLI cannot clear the session cookie header once test output has started.
$check(@expire_authenticated_session_if_idle() === true, 'session expired at 300 s idle');

exit($failures === 0 ? 0 : 1);
