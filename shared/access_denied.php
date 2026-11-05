<?php
// Reusable browser-safe 403 response. The authorization helper sets the
// response status before including this file.
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Access Denied – BCP Student Management System</title>
  <style>
    *{box-sizing:border-box}
    body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f7fb;color:#1f2937;font-family:"DM Sans", "DM Sans", "Segoe UI", sans-serif;padding:24px}
    .denied-card{width:min(460px,100%);background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:32px;text-align:center;box-shadow:0 12px 35px rgba(15,23,42,.08)}
    .denied-code{font-size:3rem;font-weight:800;color:#dc2626;margin:0}
    h1{font-size:1.35rem;margin:8px 0 10px}
    p{font-size:.92rem;line-height:1.6;color:#6b7280;margin:0 0 22px}
    a{display:inline-block;background:#2563eb;color:#fff;text-decoration:none;border-radius:8px;padding:10px 18px;font-weight:700;font-size:.86rem}
    a:hover{background:#1d4ed8}
  </style>
</head>
<body>
  <main class="denied-card">
    <p class="denied-code">403</p>
    <h1>Access denied</h1>
    <p>You are signed in, but your account does not have permission to access this resource.</p>
    <a href="../dashboard/dashboard.php">Return to Dashboard</a>
  </main>
</body>
</html>
