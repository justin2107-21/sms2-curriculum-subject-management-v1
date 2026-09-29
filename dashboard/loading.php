<?php
require_once __DIR__ . '/../shared/bootstrap.php';
require_permission('dashboard.view');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../shared/favicon.php'; ?>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Loading – BCP Student Portal</title>
  <link rel="stylesheet" href="../css/loading.css" />
</head>
<body>
  <img class="bcp-logo" src="../images/BCP_LOGO.png" alt="Bestlink College of the Philippines Logo" />
  <p class="welcome">Magandang Buhay BCPian!</p>
  <p class="wait">
    Please wait
    <span class="dots"><span></span><span></span><span></span></span>
  </p>
  <script>
    setTimeout(function () {
      window.location.href = 'dashboard.php';
    }, 2500);
  </script>
</body>
</html>
