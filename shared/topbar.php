<?php
// Shared application topbar. Authentication and permission checks remain the
// responsibility of the page that includes this presentation component.
$topbarAppRoot = $APP_ROOT ?? '../';
$topbarAvatarInitial = strtoupper(substr((string)($_SESSION['first_name'] ?? 'U'), 0, 1));
$topbarClockPath = __DIR__ . '/../js/topbar-clock.js';
$topbarClockVersion = is_file($topbarClockPath) ? (string)filemtime($topbarClockPath) : '1';
$topbarSessionTimeoutPath = __DIR__ . '/../js/session-timeout.js';
$topbarSessionTimeoutVersion = is_file($topbarSessionTimeoutPath) ? (string)filemtime($topbarSessionTimeoutPath) : '1';
$topbarCurriculumContextPath = __DIR__ . '/../js/curriculum-context.js';
$topbarCurriculumContextVersion = is_file($topbarCurriculumContextPath) ? (string)filemtime($topbarCurriculumContextPath) : '1';
require_once __DIR__ . '/auth.php';
$topbarIdleTimeoutSeconds = CSMS_IDLE_TIMEOUT_SECONDS;
$topbarLastActivity = (int)($_SESSION['_last_activity_at'] ?? time());
$topbarUserId = (int)($_SESSION['user_id'] ?? 0);
$topbarTokensPath = __DIR__ . '/../css/csms-tokens.css';
$topbarTokensVersion = is_file($topbarTokensPath) ? (string)filemtime($topbarTokensPath) : '1';
?>
<link rel="stylesheet" href="<?= htmlspecialchars($topbarAppRoot . 'css/csms-tokens.css?v=' . $topbarTokensVersion, ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars($topbarAppRoot . 'css/session-warning.css?v=' . (is_file(__DIR__ . '/../css/session-warning.css') ? filemtime(__DIR__ . '/../css/session-warning.css') : '1'), ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars($topbarAppRoot . 'css/csms-fonts.css?v=' . (is_file(__DIR__ . '/../css/csms-fonts.css') ? filemtime(__DIR__ . '/../css/csms-fonts.css') : '1'), ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars($topbarAppRoot . 'css/csms-components.css?v=' . (is_file(__DIR__ . '/../css/csms-components.css') ? filemtime(__DIR__ . '/../css/csms-components.css') : '1'), ENT_QUOTES, 'UTF-8') ?>">
<?php if (getenv('CSMS_DISPOSABLE_PREVIEW') === '1'): ?><p class="synthetic-preview-notice" role="status">Disposable synthetic preview — not BCP academic data. Institutional adoption/approval not verified.</p><?php endif; ?>
<header class="topbar">
  <button class="hamburger" id="hamburgerBtn" type="button" aria-label="Toggle sidebar" aria-controls="sidebar">
    <i class="fa-solid fa-bars" aria-hidden="true"></i>
  </button>
  <span class="topbar-spacer"></span>
  <div class="topbar-right">
    <div class="local-time-display" aria-label="Current local time">
      <i class="fa-solid fa-clock" aria-hidden="true"></i>
      <time id="localTime">--:--:-- --</time>
    </div>
    <a href="<?= htmlspecialchars($topbarAppRoot . 'dashboard/account.php', ENT_QUOTES, 'UTF-8') ?>"
       class="avatar" title="Account Settings" aria-label="Open account settings">
      <?= htmlspecialchars($topbarAvatarInitial, ENT_QUOTES, 'UTF-8') ?>
    </a>
  </div>
</header>
<?php require __DIR__ . '/cimf_panel.php'; ?>
<script src="<?= htmlspecialchars($topbarAppRoot . 'js/topbar-clock.js?v=' . $topbarClockVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars($topbarAppRoot . 'js/session-timeout.js?v=' . $topbarSessionTimeoutVersion, ENT_QUOTES, 'UTF-8') ?>"
        data-timeout-seconds="<?= $topbarIdleTimeoutSeconds ?>"
        data-warning-seconds="<?= CSMS_IDLE_WARNING_SECONDS ?>"
        data-last-activity="<?= $topbarLastActivity ?>"
        data-user-id="<?= $topbarUserId ?>"
        data-logout-url="<?= htmlspecialchars($topbarAppRoot . 'shared/auth_actions.php', ENT_QUOTES, 'UTF-8') ?>"
        data-signin-url="<?= htmlspecialchars($topbarAppRoot . 'auth/signin.php?reason=session_timeout', ENT_QUOTES, 'UTF-8') ?>"
        data-csrf-token="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars($topbarAppRoot . 'js/csms-live-states.js?v=' . (is_file(__DIR__ . '/../js/csms-live-states.js') ? filemtime(__DIR__ . '/../js/csms-live-states.js') : '1'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars($topbarAppRoot . 'js/csms-context-bar.js?v=' . (is_file(__DIR__ . '/../js/csms-context-bar.js') ? filemtime(__DIR__ . '/../js/csms-context-bar.js') : '1'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars($topbarAppRoot . 'js/curriculum-context.js?v=' . $topbarCurriculumContextVersion, ENT_QUOTES, 'UTF-8') ?>" data-user-id="<?= $topbarUserId ?>"></script>
