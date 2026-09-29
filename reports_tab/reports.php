<?php
// ============================================================
//  OVERVIEW.PHP  (app/home_tab/)
//  Home > Overview page — uses the shared sidebar.
// ============================================================
require_once __DIR__ . '/../shared/bootstrap.php';
require_permission('dashboard.view');
require_once __DIR__ . '/../shared/db.php';

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = ucfirst(htmlspecialchars($_SESSION['role'] ?? 'student'));
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

// Quick stats
$total = $active = 0;
$canViewStudents = user_can((int)current_user_id(), 'student.view');
if ($canViewStudents) {
    $r = $conn->query("SELECT COUNT(*) AS c FROM students"); if ($r) $total  = (int)$r->fetch_assoc()['c'];
    $r = $conn->query("SELECT COUNT(*) AS c FROM students WHERE status='Active'"); if ($r) $active = (int)$r->fetch_assoc()['c'];
}
$inactive = $total - $active;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Overview – BCP Student Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
</head>
<body>

<?php
// Include shared sidebar — set $APP_ROOT so paths inside resolve correctly
$APP_ROOT   = '../';
$ACTIVE_NAV = 'reports'; // change this depening on the name of your dashboard tab
require_once __DIR__ . '/../shared/sidebar.php';
?>

<div class="main">

  <!-- Topbar -->
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">
      <i class="fa-solid fa-bars"></i>
    </button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap">
        <input type="text" placeholder="Search..."/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <a href="../dashboard/account.php" class="avatar" id="avatarBtn" title="Account Settings">
        <?= $sess_initial ?>
      </a>
    </div>
  </div>

  <!-- Content -->
  <div class="content">

    <!-- Page title -->
    <div class="page-title-bar">
      <h2 class="page-title">
        <i class="fa-solid fa-house"></i>
        Reports
      </h2>
    </div>

    <!-- Welcome + stats row -->
    <div class="info-row">

      <!-- Welcome card -->
      <div class="info-card">
        <div class="card-label">
          <i class="fa-solid fa-user-graduate"></i>
          Welcome back
        </div>
        <div class="card-name"><?= $sess_first . ' ' . $sess_last ?></div>
        <div class="card-detail">
          Role: <?= $sess_role ?><br>
          You are now viewing the Home Overview.
        </div>
      </div>

      <!-- Total students -->
      <?php if ($canViewStudents): ?>
      <div class="info-card">
        <div class="card-label">
          <i class="fa-solid fa-users"></i>
          Total Students
        </div>
        <div class="card-amount"><?= $total ?></div>
        <div class="card-detail">Registered in the system</div>
      </div>
      <?php endif; ?>

      <!-- Active / Inactive -->
      <?php if ($canViewStudents): ?>
      <div class="info-card">
        <div class="card-label">
          <i class="fa-solid fa-circle-dot"></i>
          Status Breakdown
        </div>
        <div class="card-detail" style="margin-top:6px;">
          <span class="badge-active">Active: <?= $active ?></span>
          &nbsp;
          <span class="badge-inactive">Inactive: <?= $inactive ?></span>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- end info-row -->

    <!-- Quick links card -->
    <div class="form-card">
      <h3>Quick Links</h3>
      <div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:4px;">
        <a href="../dashboard/dashboard.php"
           style="display:inline-flex; align-items:center; gap:8px; background:#eff6ff;
                  border-radius:8px; padding:12px 20px; text-decoration:none;
                  color:#2563eb; font-size:0.85rem; font-weight:600;">
          <i class="fa-solid fa-gauge"></i> Dashboard
        </a>
        <a href="../dashboard/account.php"
           style="display:inline-flex; align-items:center; gap:8px; background:#f5f3ff;
                  border-radius:8px; padding:12px 20px; text-decoration:none;
                  color:#7c3aed; font-size:0.85rem; font-weight:600;">
          <i class="fa-solid fa-user-gear"></i> Account Settings
        </a>
      </div>
    </div>

  </div><!-- end content -->

  <div class="footer">eLearning Commons &copy; 2026</div>

</div><!-- end main -->

<!-- Sidebar tap-outside overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
</body>
</html>
<?php $conn->close(); ?>
