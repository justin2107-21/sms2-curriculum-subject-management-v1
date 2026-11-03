<?php
// Bootstrap-profile layout shell: <html> head, sidebar, topbar, content wrapper.
// Usage in a (future) pilot page, after shared/bootstrap.php and the page's own
// require_permission() call:
//   $UI_PROFILE = 'bootstrap';
//   ui_layout_begin(['title' => '...', 'active_nav' => 'prerequisite-configuration', 'app_root' => '../../../']);
//   ... page content ...
//   ui_layout_end(['scripts' => ['../assets/page.js']]);
//
// Not included on purpose (decisions pending for the pilot):
//  * CIMF panel: shared/cimf_panel.php loads legacy stylesheets, which the Bootstrap
//    profile must not load. A Bootstrap presentation of the same panel (behaviour and
//    advisory-only rules unchanged) is a pilot task.
//  * Notifications: no notification feature exists yet, so no placeholder is rendered.
require_once __DIR__ . '/profile.php';
require_once __DIR__ . '/sidebar.php';

if (!function_exists('ui_topbar')) {
    function ui_topbar(string $appRoot): void
    {
        $initial = strtoupper(substr((string)($_SESSION['first_name'] ?? 'U'), 0, 1));
        ?>
<header class="csms-topbar">
  <button class="btn btn-outline-secondary btn-sm d-lg-none" id="csmsMenuToggle" type="button"
          aria-label="Toggle sidebar" aria-controls="csmsSidebar" aria-expanded="false">
    <i class="bi bi-list" aria-hidden="true"></i>
  </button>
  <span class="csms-topbar__spacer"></span>
  <div class="csms-topbar__clock" aria-label="Current local time">
    <i class="bi bi-clock" aria-hidden="true"></i> <time id="localTime">--:--:-- --</time>
  </div>
  <a class="csms-avatar" href="<?= ui_e($appRoot . 'dashboard/account.php') ?>"
     title="Account Settings" aria-label="Open account settings"><?= ui_e($initial) ?></a>
</header>
        <?php
    }
}

if (!function_exists('ui_session_scripts')) {
    // Re-uses the existing clock and idle-timeout scripts unchanged so the session
    // timeout behaviour is identical in both profiles.
    function ui_session_scripts(string $appRoot): void
    {
        $v = static function (string $f): string {
            $p = __DIR__ . '/../../js/' . $f;
            return is_file($p) ? (string)filemtime($p) : '1';
        };
        require_once __DIR__ . '/../auth.php';
        $timeout = CSMS_IDLE_TIMEOUT_SECONDS;
        ?>
<link rel="stylesheet" href="<?= ui_e($appRoot . 'css/session-warning.css?v=' . (is_file(__DIR__ . '/../../css/session-warning.css') ? filemtime(__DIR__ . '/../../css/session-warning.css') : '1')) ?>">
<script src="<?= ui_e($appRoot . 'js/topbar-clock.js?v=' . $v('topbar-clock.js')) ?>"></script>
<script src="<?= ui_e($appRoot . 'js/session-timeout.js?v=' . $v('session-timeout.js')) ?>"
        data-timeout-seconds="<?= (int)$timeout ?>"
        data-warning-seconds="<?= (int)CSMS_IDLE_WARNING_SECONDS ?>"
        data-last-activity="<?= (int)($_SESSION['_last_activity_at'] ?? time()) ?>"
        data-user-id="<?= (int)($_SESSION['user_id'] ?? 0) ?>"
        data-logout-url="<?= ui_e($appRoot . 'shared/auth_actions.php') ?>"
        data-signin-url="<?= ui_e($appRoot . 'auth/signin.php?reason=session_timeout') ?>"
        data-csrf-token="<?= ui_e(csrf_token()) ?>"></script>
        <?php
    }
}

if (!function_exists('ui_layout_begin')) {
    function ui_layout_begin(array $opts = []): void
    {
        $appRoot = $opts['app_root'] ?? '../';
        $title = $opts['title'] ?? 'CSMS';
        $active = $opts['active_nav'] ?? '';
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= ui_e($title) ?> | CSMS</title>
  <?php require __DIR__ . '/../favicon.php'; ?>
  <?= ui_head_assets($appRoot) ?>
</head>
<body>
<div class="csms-shell">
  <?php ui_sidebar($appRoot, $active); ?>
  <div class="csms-main">
    <?php ui_topbar($appRoot); ?>
    <main class="csms-content" id="main-content">
        <?php
    }
}

if (!function_exists('ui_layout_end')) {
    function ui_layout_end(array $opts = []): void
    {
        $appRoot = $opts['app_root'] ?? '../';
        ?>
    </main>
  </div>
</div>
        <?php
        echo ui_foot_assets($appRoot);
        ui_session_scripts($appRoot);
        foreach (($opts['scripts'] ?? []) as $src) {
            echo '<script src="' . ui_e($src) . '"></script>' . "\n";
        }
        echo "</body>\n</html>\n";
    }
}
