<?php
require_once __DIR__ . '/../shared/bootstrap.php';

if (is_authenticated()) {
    header('Location: ../dashboard/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../shared/favicon.php'; ?>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CSMS | Sign In</title>
  <link rel="stylesheet" href="../css/auth.css?v=<?= (int)filemtime(__DIR__ . '/../css/auth.css') ?>" />
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <script src="../js/page-loader.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body class="csms-signin">
<div class="outer">
  <div class="card">
    <!-- Left panel -->
    <div class="left">
      <div class="left-top">
        <img src="../images/BCP_LOGO.png" alt="BCP Logo" class="left-logo"/>
        <p class="left-school">Bestlink College of the Philippines</p>
      </div>
      <div class="left-body">
        <h1>Curriculum &amp; Subject Management System</h1>
        <p class="subtitle">Student Management System 2</p>
        <p>
          Organize, manage, and maintain curriculum structures and subject information through one centralized academic workspace.
        </p>
      </div>
    </div>

    <!-- Right panel -->
    <div class="right">
      <img class="bcp-logo" src="../images/BCP_LOGO.png" alt="Bestlink College of the Philippines Logo" />

      <h2>Sign In to CSMS</h2>

      <div class="auth-error" id="authError" style="display:none;"></div>

      <form id="signinForm" style="width:100%">
        <div class="form-group">
          <label for="username">
            <i class="fa-solid fa-user"></i>
            Username
          </label>
          <input type="text" id="username" autocomplete="username" />
        </div>

        <div class="form-group">
          <label for="password">
            <i class="fa-solid fa-lock"></i>
            Password
          </label>
          <input type="password" id="password" autocomplete="current-password" />
        </div>

        <button type="submit" class="btn-signin" id="btnSignin">
          Sign In
          <i class="fa-solid fa-arrow-right"></i>
        </button>
      </form>

      <p class="authorized-users-note">For authorized users only.</p>
    </div>
  </div>
</div>

<script>
document.getElementById('signinForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const username = document.getElementById('username').value.trim();
    const password = document.getElementById('password').value;
    const errorBox = document.getElementById('authError');
    const btn      = document.getElementById('btnSignin');
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    errorBox.style.display = 'none';
    if (!username || !password) return showError('Please enter your username and password.');

    btn.disabled    = true;
    btn.textContent = 'Signing in…';

    const fd = new FormData();
    fd.set('action', 'login');
    fd.set('username', username);
    fd.set('password', password);
    fd.set('_csrf_token', csrfToken);

    try {
        const data = await fetch('../shared/auth_actions.php', { method: 'POST', body: fd }).then(r => r.json());
        if (data.success) {
            window.location.href = data.redirect || '../dashboard/loading.php';
        } else {
            showError(data.message);
            btn.disabled  = false;
            btn.innerHTML = 'Sign In <i class="fa-solid fa-arrow-right"></i>';
        }
    } catch {
        showError('Request failed. Please try again.');
        btn.disabled  = false;
        btn.innerHTML = 'Sign In <i class="fa-solid fa-arrow-right"></i>';
    }

    function showError(msg) {
        errorBox.textContent   = msg;
        errorBox.style.display = 'block';
    }
});
</script>
</body>
</html>
