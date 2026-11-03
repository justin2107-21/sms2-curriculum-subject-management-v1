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
  <link rel="stylesheet" href="../css/csms-tokens.css?v=<?= (int)filemtime(__DIR__ . '/../css/csms-tokens.css') ?>" />
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

      <div class="auth-error" id="authError" role="alert" hidden>
        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
        <span id="authErrorText"></span>
      </div>

      <form id="signinForm" style="width:100%" novalidate>
        <div class="form-group">
          <label for="username">
            <i class="fa-solid fa-user" aria-hidden="true"></i>
            Username <span class="required-mark" aria-hidden="true">*</span>
          </label>
          <input type="text" id="username" name="username" autocomplete="username"
                 autocapitalize="none" spellcheck="false" required aria-required="true" />
        </div>

        <div class="form-group">
          <label for="password">
            <i class="fa-solid fa-lock" aria-hidden="true"></i>
            Password <span class="required-mark" aria-hidden="true">*</span>
          </label>
          <div class="password-field">
            <input type="password" id="password" name="password" autocomplete="current-password"
                   required aria-required="true" aria-describedby="capsLockHint" />
            <button type="button" class="password-toggle" id="passwordToggle"
                    aria-label="Show password" aria-pressed="false" aria-controls="password">
              <i class="fa-solid fa-eye" aria-hidden="true"></i>
            </button>
          </div>
          <p class="caps-lock-hint" id="capsLockHint" aria-live="polite" hidden>
            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Caps Lock is on.
          </p>
        </div>

        <button type="submit" class="btn-signin" id="btnSignin">
          Sign In
          <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
        </button>
      </form>

      <p class="authorized-users-note">For authorized users only.</p>
    </div>
  </div>
</div>

<script>
// Password visibility toggle and Caps Lock hint (presentation only).
(function () {
    const input  = document.getElementById('password');
    const toggle = document.getElementById('passwordToggle');
    const hint   = document.getElementById('capsLockHint');
    toggle.addEventListener('click', function () {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
        toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        toggle.querySelector('i').className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
        input.focus();
    });
    function capsLock(event) {
        if (typeof event.getModifierState === 'function') hint.hidden = !event.getModifierState('CapsLock');
    }
    input.addEventListener('keydown', capsLock);
    input.addEventListener('keyup', capsLock);
    input.addEventListener('blur', function () { hint.hidden = true; });
    ['username', 'password'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', function () { this.removeAttribute('aria-invalid'); });
    });
})();

document.getElementById('signinForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const usernameInput = document.getElementById('username');
    const passwordInput = document.getElementById('password');
    const username = usernameInput.value.trim();
    const password = passwordInput.value;
    const errorBox = document.getElementById('authError');
    const btn      = document.getElementById('btnSignin');
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    errorBox.hidden = true;
    [usernameInput, passwordInput].forEach(function (field) {
        field.removeAttribute('aria-invalid');
        if (field === passwordInput) field.setAttribute('aria-describedby', 'capsLockHint');
        else field.removeAttribute('aria-describedby');
    });
    if (!username || !password) {
        if (!username) markInvalid(usernameInput);
        if (!password) markInvalid(passwordInput);
        showError('Please enter your username and password.');
        (!username ? usernameInput : passwordInput).focus();
        return;
    }

    passwordInput.type = 'password';
    btn.disabled    = true;
    btn.setAttribute('aria-busy', 'true');
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
            resetButton();
        }
    } catch {
        showError('Request failed. Please try again.');
        resetButton();
    }

    function resetButton() {
        btn.disabled  = false;
        btn.removeAttribute('aria-busy');
        btn.innerHTML = 'Sign In <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>';
    }
    function markInvalid(field) {
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', field === passwordInput ? 'authError capsLockHint' : 'authError');
    }
    function showError(msg) {
        document.getElementById('authErrorText').textContent = msg;
        errorBox.hidden = false;
    }
});
</script>
</body>
</html>
