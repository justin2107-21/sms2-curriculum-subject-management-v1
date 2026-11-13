<?php
require_once __DIR__ . '/../shared/bootstrap.php';
require_permission('user.manage');
require_once __DIR__ . '/AccountAdministration.php';
require_once __DIR__ . '/SystemAdminRepository.php';
require_once __DIR__ . '/../database/ProvisionalRoles.php';

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
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Users - BCP Curriculum &amp; Subject Management</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="../modules/curriculum/assets/curriculum.css?v=<?= (int)filemtime(__DIR__.'/../modules/curriculum/assets/curriculum.css') ?>"/>
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
        <h1 class="page-title"><i class="fa-solid fa-users" aria-hidden="true"></i> Users</h1>
        <p class="system-admin-subtitle">Manage prototype accounts and authoritative RBAC role assignments.</p>
      </div>
    </div>
    <div class="system-admin-summary"><button class="primary-action-button" type="button" id="adminNewUser"><i class="fa-solid fa-plus" aria-hidden="true"></i> Create User</button></div>
    <section class="system-admin-card" aria-label="User accounts">
      <p class="system-admin-summary">
        Legacy accounts are retained. Use Account Settings for your own profile.
        <?= $totalUsers > count($users) ? 'Showing the first 100 of ' . number_format($totalUsers) . ' accounts.' : '' ?>
      </p>
      <?php if ($users !== []): ?>
      <div class="csms-filter-row system-admin-toolbar" id="usersToolbar">
        <label class="csms-search"><span class="sr-only">Search username, name or email</span><input type="search" id="usersSearch" maxlength="100" placeholder="Search username, name or email" autocomplete="off"/></label>
        <select class="csms-select" id="usersRole" aria-label="Role"><option value="">All roles</option></select>
        <select class="csms-select" id="usersStatus" aria-label="Status"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
        <button type="button" class="csms-link" id="usersClear" hidden>Clear filters</button>
        <span class="csms-filter-row__summary" id="usersCount" role="status" aria-live="polite"></span>
      </div>
      <div class="system-admin-table-wrap">
        <table class="system-admin-table" id="usersTable">
          <thead><tr>
            <th scope="col" data-sort="username" aria-sort="none"><button type="button" class="system-admin-sort">Username<span class="system-admin-sort__icon" aria-hidden="true"></span></button></th>
            <th scope="col" data-sort="name" aria-sort="none"><button type="button" class="system-admin-sort">Name<span class="system-admin-sort__icon" aria-hidden="true"></span></button></th>
            <th scope="col" data-sort="email" aria-sort="none"><button type="button" class="system-admin-sort">Email<span class="system-admin-sort__icon" aria-hidden="true"></span></button></th>
            <th scope="col" data-sort="role" aria-sort="none"><button type="button" class="system-admin-sort">RBAC Roles<span class="system-admin-sort__icon" aria-hidden="true"></span></button></th>
            <th scope="col" data-sort="status" aria-sort="none"><button type="button" class="system-admin-sort">Status<span class="system-admin-sort__icon" aria-hidden="true"></span></button></th>
            <th scope="col" data-sort="created" aria-sort="none"><button type="button" class="system-admin-sort">Created<span class="system-admin-sort__icon" aria-hidden="true"></span></button></th>
            <th scope="col">Actions</th>
          </tr></thead>
          <tbody>
          <?php foreach ($users as $user): ?>
            <tr>
              <td><span class="system-admin-code"><?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?></span></td>
              <td class="system-admin-name"><?= htmlspecialchars(trim((string)$user['first_name'] . ' ' . (string)$user['last_name']), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= $user['role_names'] === null ? '<span class="system-admin-muted">No RBAC role</span>' : htmlspecialchars((string)$user['role_names'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= $user['is_active'] ? 'Active' : 'Inactive' ?></td>
              <td><?= htmlspecialchars((string)$user['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?php if((int)$user['id'] !== (int)current_user_id() && in_array($user['role_names'], array_column(ProvisionalRoles::definitions(), 'label'), true) && (new AccountAdministration(authorization_connection()))->manageableBy((int)current_user_id(), get_user_roles((int)$user['id']))): ?><button class="button-muted" type="button" data-admin-edit="<?= (int)$user['id'] ?>">Edit User</button><?php endif; ?></td></tr>
          <?php endforeach; ?>
            <tr id="usersNoMatch" hidden><td colspan="7"><div class="csms-empty" role="status"><i class="fa-solid fa-filter" aria-hidden="true"></i><strong>No accounts match these filters.</strong><button type="button" class="button-muted" id="usersNoMatchClear">Clear filters</button></div></td></tr>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <p class="system-admin-empty">No user accounts found.</p>
      <?php endif; ?>
    </section>
  </main>
  <footer class="footer">Bestlink College of the Philippines &middot; CSMS-SMS2 &copy; 2026</footer>
</div>
<?php require __DIR__.'/account_form.php'; ?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../modules/curriculum/assets/curriculum-common.js?v=<?= (int)filemtime(__DIR__.'/../modules/curriculum/assets/curriculum-common.js') ?>"></script>
<script src="../js/account-administration.js?v=<?= (int)filemtime(__DIR__.'/../js/account-administration.js') ?>"></script>
<script src="../js/users-list.js?v=<?= (int)filemtime(__DIR__.'/../js/users-list.js') ?>"></script>
</body>
</html>
