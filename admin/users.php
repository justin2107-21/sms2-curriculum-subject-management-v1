<?php
require_once __DIR__ . '/../shared/bootstrap.php';
require_permission('rbac.manage');
require_once __DIR__ . '/SystemAdminRepository.php';

$repository = new SystemAdminRepository(authorization_connection());
$totalUsers = $repository->userCount();
$users = $repository->users(100);
$styleVersion = (string)filemtime(__DIR__ . '/../css/system-admin.css');
$typographyVersion = (string)filemtime(__DIR__ . '/../css/csms-typography.css');
$scriptVersion = (string)filemtime(__DIR__ . '/../js/dashboard.js');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Users - BCP Curriculum &amp; Subject Management</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="../css/system-admin.css?v=<?= $styleVersion ?>"/>
  <link rel="stylesheet" href="../css/csms-typography.css?v=<?= $typographyVersion ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../';
$ACTIVE_NAV = 'users';
require __DIR__ . '/../shared/sidebar.php';
?>
<div class="main">
  <?php require __DIR__ . '/../shared/topbar.php'; ?>
  <main class="content system-admin-content">
    <div class="page-title-bar">
      <div>
        <h1 class="page-title"><i class="fa-solid fa-users"></i> Users</h1>
        <p class="system-admin-subtitle">Read-only view of user accounts and authoritative RBAC role assignments.</p>
      </div>
    </div>
    <section class="system-admin-card" aria-label="User accounts">
      <p class="system-admin-summary">
        <?= number_format($totalUsers) ?> account<?= $totalUsers === 1 ? '' : 's' ?>.
        This page does not create, edit, or delete users.
        <?= $totalUsers > count($users) ? 'Showing the first 100 accounts.' : '' ?>
      </p>
      <?php if ($users !== []): ?>
      <div class="system-admin-table-wrap">
        <table class="system-admin-table">
          <thead><tr><th>Username</th><th>Name</th><th>Email</th><th>RBAC Roles</th><th>Created</th></tr></thead>
          <tbody>
          <?php foreach ($users as $user): ?>
            <tr>
              <td><span class="system-admin-code"><?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?></span></td>
              <td class="system-admin-name"><?= htmlspecialchars(trim((string)$user['first_name'] . ' ' . (string)$user['last_name']), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= $user['role_names'] === null ? '<span class="system-admin-muted">No RBAC role</span>' : htmlspecialchars((string)$user['role_names'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$user['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <p class="system-admin-empty">No user accounts found.</p>
      <?php endif; ?>
    </section>
  </main>
  <footer class="footer">eLearning Commons &copy; 2026</footer>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js?v=<?= $scriptVersion ?>"></script>
</body>
</html>
