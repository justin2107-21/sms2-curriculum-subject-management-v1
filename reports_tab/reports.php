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
$sess_role    = ucfirst(htmlspecialchars($_SESSION['role'] ?? ''));

// Students are kept for SMS2 integration and are not shown in CSMS.
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
  <link rel="stylesheet" href="../css/csms-typography.css?v=<?= (string)@filemtime(__DIR__ . '/../css/csms-typography.css') ?>"/>
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

  <?php require __DIR__ . '/../shared/topbar.php'; ?>
  <!-- Content -->
  <div class="content">

    <!-- Page title -->
    <div class="page-title-bar">
      <div>
        <h1 class="page-title"><i class="fa-solid fa-chart-column" aria-hidden="true"></i> Reports</h1>
        <p class="page-subtitle">Your account overview.</p>
      </div>
    </div>

    <!-- Welcome + stats row -->
    <div class="info-row">

      <!-- Welcome card -->
      <div class="info-card">
        <div class="card-label">
          <i class="fa-solid fa-user-graduate" aria-hidden="true"></i>
          Welcome back
        </div>
        <div class="card-name"><?= $sess_first . ' ' . $sess_last ?></div>
        <div class="card-detail">
          Role: <?= $sess_role ?><br>
          You are now viewing the Home Overview.
        </div>
      </div>

    </div><!-- end info-row -->

    <!-- Quick links card -->
    <div class="form-card">
      <h3>Quick Links</h3>
      <div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:4px;">
        <a href="../dashboard/dashboard.php"
           style="display:inline-flex; align-items:center; gap:8px; background:#eff6ff;
                  border-radius:8px; padding:12px 20px; text-decoration:none;
                  color:#2563eb; font-size:0.85rem; font-weight:600;">
          <i class="fa-solid fa-gauge" aria-hidden="true"></i> Dashboard
        </a>
        <a href="../dashboard/account.php"
           style="display:inline-flex; align-items:center; gap:8px; background:#f5f3ff;
                  border-radius:8px; padding:12px 20px; text-decoration:none;
                  color:#7c3aed; font-size:0.85rem; font-weight:600;">
          <i class="fa-solid fa-user-gear" aria-hidden="true"></i> Account Settings
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
