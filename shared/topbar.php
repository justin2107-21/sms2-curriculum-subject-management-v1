<?php
// Shared application topbar. Authentication and permission checks remain the
// responsibility of the page that includes this presentation component.
$topbarAppRoot = $APP_ROOT ?? '../';
$topbarAvatarInitial = strtoupper(substr((string)($_SESSION['first_name'] ?? 'U'), 0, 1));
$topbarClockPath = __DIR__ . '/../js/topbar-clock.js';
$topbarClockVersion = is_file($topbarClockPath) ? (string)filemtime($topbarClockPath) : '1';
?>
<header class="topbar">
  <button class="hamburger" id="hamburgerBtn" type="button" aria-label="Toggle sidebar" aria-controls="sidebar">
    <i class="fa-solid fa-bars" aria-hidden="true"></i>
  </button>
  <span class="topbar-spacer"></span>
  <div class="topbar-right">
    <div class="local-time-display" aria-label="Current local time">
      <i class="fa-regular fa-clock" aria-hidden="true"></i>
      <time id="localTime">--:--:-- --</time>
    </div>
    <a href="<?= htmlspecialchars($topbarAppRoot . 'dashboard/account.php', ENT_QUOTES, 'UTF-8') ?>"
       class="avatar" title="Account Settings" aria-label="Open account settings">
      <?= htmlspecialchars($topbarAvatarInitial, ENT_QUOTES, 'UTF-8') ?>
    </a>
  </div>
</header>
<script src="<?= htmlspecialchars($topbarAppRoot . 'js/topbar-clock.js?v=' . $topbarClockVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
