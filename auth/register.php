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
  <title>Register Account – BCP Student Management System</title>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <link rel="stylesheet" href="../css/auth.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<div class="card register-card">
  <!-- Left panel -->
  <div class="left">
    <div class="left-top">
      <img src="../images/BCP_LOGO.png" alt="BCP Logo" class="left-logo"/>
      <p class="left-school">Bestlink College of the Philippines</p>
    </div>
    <div class="left-body">
      <h1>Student Management System</h1>
      <p class="subtitle">Enrollment System</p>
      <p>
        <span>Enrollment</span> is the process of registering individuals into the
        system by collecting and verifying the required information.
        Once enrollment is complete, users can access the
        services and features available to them.
      </p>
    </div>
  </div>

  <!-- Right panel -->
  <div class="right">
    <img class="bcp-logo" src="../images/BCP_LOGO.png" alt="Bestlink College of the Philippines Logo" />

    <h2>Register Account</h2>

    <div class="auth-error"   id="authError"   style="display:none;"></div>
    <div class="auth-success" id="authSuccess" style="display:none;"></div>

    <form id="registerForm" style="width:100%">
      <div class="form-row">
        <div class="form-group">
          <label>
            <i class="fa-solid fa-user"></i>
            First Name
          </label>
          <input type="text" id="first_name" autocomplete="given-name" />
        </div>
        <div class="form-group">
          <label>
            <i class="fa-solid fa-user"></i>
            Last Name
          </label>
          <input type="text" id="last_name" autocomplete="family-name" />
        </div>
      </div>

      <div class="form-group">
        <label>
          <i class="fa-solid fa-envelope"></i>
          Email
        </label>
        <input type="email" id="email" autocomplete="email" />
      </div>

      <div class="form-group">
        <label>
          <i class="fa-solid fa-user"></i>
          Username
        </label>
        <input type="text" id="username" autocomplete="username" />
      </div>

      <div class="form-group">
        <label>
          <i class="fa-solid fa-lock"></i>
          Password
        </label>
        <input type="password" id="password" autocomplete="new-password" />
      </div>

      <div class="form-group">
        <label>
          <i class="fa-solid fa-lock"></i>
          Confirm Password
        </label>
        <input type="password" id="confirmPassword" autocomplete="new-password" />
      </div>

      <button type="submit" class="btn-done" id="btnRegister">
        Create Account
        <i class="fa-solid fa-arrow-right"></i>
      </button>
    </form>

    <p class="register-link">
      Already have an account? <a href="signin.php">Sign in here</a>
    </p>
  </div>
</div>

<script>
document.getElementById('registerForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const first_name = document.getElementById('first_name').value.trim();
    const last_name  = document.getElementById('last_name').value.trim();
    const email      = document.getElementById('email').value.trim();
    const username   = document.getElementById('username').value.trim();
    const password   = document.getElementById('password').value;
    const confirm    = document.getElementById('confirmPassword').value;
    const errorBox   = document.getElementById('authError');
    const successBox = document.getElementById('authSuccess');
    const btn        = document.getElementById('btnRegister');
    const csrfToken  = document.querySelector('meta[name="csrf-token"]').content;

    errorBox.style.display = successBox.style.display = 'none';

    if (!first_name || !last_name || !email || !username || !password)
        return showError('All fields are required.');
    if (password.length < 6)
        return showError('Password must be at least 6 characters.');
    if (password !== confirm)
        return showError('Passwords do not match.');

    btn.disabled    = true;
    btn.textContent = 'Creating account…';

    const fd = new FormData();
    fd.set('action',           'register');
    fd.set('first_name',       first_name);
    fd.set('last_name',        last_name);
    fd.set('email',            email);
    fd.set('username',         username);
    fd.set('password',         password);
    fd.set('confirm_password', confirm);
    fd.set('_csrf_token',       csrfToken);

    try {
        const data = await fetch('../shared/auth_actions.php', { method: 'POST', body: fd }).then(r => r.json());
        if (data.success) {
            successBox.textContent   = 'Account created! Redirecting to sign in…';
            successBox.style.display = 'block';
            setTimeout(() => { window.location.href = 'signin.php'; }, 1800);
        } else {
            showError(data.message);
            btn.disabled  = false;
            btn.innerHTML = 'Create Account <i class="fa-solid fa-arrow-right"></i>';
        }
    } catch {
        showError('Request failed. Please try again.');
        btn.disabled  = false;
        btn.innerHTML = 'Create Account <i class="fa-solid fa-arrow-right"></i>';
    }

    function showError(msg) {
        errorBox.textContent   = msg;
        errorBox.style.display = 'block';
    }
});
</script>
</body>
</html>
