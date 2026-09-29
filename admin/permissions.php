<?php
require_once __DIR__ . '/../shared/bootstrap.php';
require_permission('rbac.manage');
require_once __DIR__ . '/SystemAdminRepository.php';

$repository = new SystemAdminRepository(authorization_connection());
$totalPermissions = $repository->permissionCount();
$permissions = $repository->permissions();
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
  <title>Permissions - BCP Curriculum &amp; Subject Management</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="../css/system-admin.css?v=<?= $styleVersion ?>"/>
  <link rel="stylesheet" href="../css/csms-typography.css?v=<?= $typographyVersion ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../';
$ACTIVE_NAV = 'permissions';
require __DIR__ . '/../shared/sidebar.php';
?>
<div class="main">
  <?php require __DIR__ . '/../shared/topbar.php'; ?>
  <main class="content system-admin-content">
    <div class="page-title-bar">
      <div>
        <h1 class="page-title"><i class="fa-solid fa-user-shield"></i> Permissions</h1>
        <p class="system-admin-subtitle">Read-only view of database-backed permissions and their temporary role assignments.</p>
      </div>
    </div>
    <section class="system-admin-card" aria-label="RBAC permissions">
      <p class="system-admin-summary">
        <?= number_format($totalPermissions) ?> permission<?= $totalPermissions === 1 ? '' : 's' ?>.
        Authorization remains authoritative in MySQL; this page does not change mappings.
      </p>
      <?php if ($permissions !== []): ?>
      <div class="system-admin-table-wrap">
        <table class="system-admin-table">
          <thead><tr><th>Permission</th><th>Module</th><th>Description</th><th>Assigned Roles</th></tr></thead>
          <tbody>
          <?php foreach ($permissions as $permission): ?>
            <tr>
              <td><span class="system-admin-code"><?= htmlspecialchars((string)$permission['name'], ENT_QUOTES, 'UTF-8') ?></span></td>
              <td><?= htmlspecialchars((string)$permission['module'], ENT_QUOTES, 'UTF-8') ?></td>
              <td class="system-admin-description"><?= htmlspecialchars((string)$permission['description'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= $permission['role_names'] === null ? '<span class="system-admin-muted">Unassigned</span>' : htmlspecialchars((string)$permission['role_names'], ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <p class="system-admin-empty">No permissions found.</p>
      <?php endif; ?>
    </section>
  </main>
  <footer class="footer">eLearning Commons &copy; 2026</footer>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js?v=<?= $scriptVersion ?>"></script>
</body>
</html>
