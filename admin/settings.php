<?php
require_once __DIR__ . '/../shared/bootstrap.php';
require_permission('settings.view');

$styleVersion = (string)filemtime(__DIR__ . '/../css/system-admin.css');
$typographyVersion = (string)filemtime(__DIR__ . '/../css/csms-typography.css');
$scriptVersion = (string)filemtime(__DIR__ . '/../js/dashboard.js');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>System Settings - BCP Curriculum &amp; Subject Management</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="../modules/curriculum/assets/curriculum.css?v=<?= (int)filemtime(__DIR__.'/../modules/curriculum/assets/curriculum.css') ?>"/>
  <link rel="stylesheet" href="../css/system-admin.css?v=<?= $styleVersion ?>"/>
  <link rel="stylesheet" href="../css/csms-typography.css?v=<?= $typographyVersion ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../';
$ACTIVE_NAV = 'system-settings';
require __DIR__ . '/../shared/sidebar.php';
?>
<div class="main">
  <?php require __DIR__ . '/../shared/topbar.php'; ?>
  <main class="content system-admin-content">
    <div class="page-title-bar"><div><h1 class="page-title">System Settings</h1><p class="system-admin-subtitle">Security administration and current technical configuration.</p></div></div>
    <section class="system-admin-card"><div class="system-admin-summary"><h2>Session Security</h2><p>Inactivity timeout: <?= CSMS_IDLE_TIMEOUT_SECONDS ?> seconds. Authentication, CSRF and database-backed permissions remain enforced.</p><p>Deployment configuration and provider credentials are managed by the local operator; secrets are never displayed here.</p></div><div class="system-admin-summary"><a class="secondary-action-button" href="users.php">Manage Accounts and Activation</a> <a class="secondary-action-button" href="permissions.php">Manage Role Permissions</a></div></section>
  </main>
  <footer class="footer">Bestlink College of the Philippines &middot; CSMS-SMS2 &copy; 2026</footer>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../modules/curriculum/assets/curriculum-common.js?v=<?= (int)filemtime(__DIR__.'/../modules/curriculum/assets/curriculum-common.js') ?>"></script>
</body>
</html>
