<?php
require_once __DIR__ . '/../shared/bootstrap.php';
require_once __DIR__ . '/../shared/EmailOtp.php';

if (is_authenticated()) {
    header('Location: ../dashboard/dashboard.php');
    exit;
}

// A refresh on the code screen keeps it: the password step was already passed and a code was sent (no logged-in session yet).
$otpPending = $_SESSION['otp_pending'] ?? null;
if (is_array($otpPending) && time() - (int)($otpPending['started_at'] ?? 0) > EmailOtpService::PENDING_SECONDS) {
    unset($_SESSION['otp_pending']);
    $otpPending = null;
}
$otpActive = is_array($otpPending);
$otpExpiresIn = $otpActive ? max(0, (int)$otpPending['expires_at'] - time()) : 0;
$otpResendIn = $otpActive ? max(0, (int)$otpPending['resend_at'] - time()) : 0;
$otpHint = $otpActive ? (string)$otpPending['masked'] : '';
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
  <link rel="stylesheet" href="../css/signin.css?v=<?= (int)filemtime(__DIR__ . '/../css/signin.css') ?>" />
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <script src="../js/page-loader.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body class="csms-signin">
<main class="signin-shell">
  <section class="signin-brand" aria-label="About CSMS">
    <div class="signin-brand__top">
      <img src="../images/BCP_LOGO.png" alt="BCP Logo" class="signin-brand__logo"/>
      <p class="signin-brand__school">Bestlink College of the Philippines</p>
    </div>
    <div class="signin-brand__body">
      <h1>Curriculum &amp; Subject Management System</h1>
      <p class="signin-brand__sub">Student Management System 2</p>
      <p>Organize, manage, and maintain curriculum structures and subject information through one centralized academic workspace.</p>
    </div>
  </section>

  <section class="signin-panel">
    <div class="signin-card">
      <img class="signin-card__logo" src="../images/BCP_LOGO.png" alt="" />

      <div class="auth-error" id="authError" role="alert" hidden>
        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
        <span id="authErrorText"></span>
      </div>

      <!-- Step 1: username and password -->
      <div id="stepPassword"<?= $otpActive ? ' hidden' : '' ?>>
        <h2>Sign in</h2>
        <p class="signin-card__lead">Use your CSMS username and password.</p>
        <form id="signinForm" novalidate>
          <div class="form-group">
            <label for="username">Username <span class="required-mark" aria-hidden="true">*</span></label>
            <input type="text" id="username" name="username" autocomplete="username"
                   autocapitalize="none" spellcheck="false" required aria-required="true" />
          </div>

          <div class="form-group">
            <label for="password">Password <span class="required-mark" aria-hidden="true">*</span></label>
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

      <!-- Step 2: six-digit code from the email (only when Email OTP is on) -->
      <div id="stepOtp"<?= $otpActive ? '' : ' hidden' ?>
           data-expires-in="<?= (int)$otpExpiresIn ?>" data-resend-in="<?= (int)$otpResendIn ?>">
        <h2>Check your email</h2>
        <p class="signin-card__lead">We sent a 6-digit code to <strong id="otpEmailHint"><?= htmlspecialchars($otpHint, ENT_QUOTES, 'UTF-8') ?></strong>. Enter it below to finish signing in.</p>
        <form id="otpForm" novalidate>
          <div class="form-group">
            <label for="otpCode">Verification code</label>
            <input type="text" id="otpCode" name="code" class="otp-input" inputmode="numeric" pattern="[0-9]{6}"
                   maxlength="6" autocomplete="one-time-code" spellcheck="false" aria-describedby="otpTimer" placeholder="000000" />
          </div>
          <p class="otp-timer" id="otpTimer" role="timer"><span id="otpLive">Code expires in <strong id="otpCountdown">5:00</strong></span><span id="otpGone" hidden>This code has expired. Request a new code.</span></p>
          <button type="submit" class="btn-signin" id="btnOtp">
            Verify and sign in
            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
          </button>
        </form>
        <div class="otp-actions">
          <button type="button" class="otp-link" id="btnResend" disabled>Resend code</button>
          <button type="button" class="otp-link" id="btnBack"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to sign in</button>
        </div>
        <p class="otp-help">Did not get it? Check your spam folder. A new code makes the old one stop working.</p>
      </div>
    </div>
  </section>
</main>

<script>
(function () {
    'use strict';
    const API = '../shared/auth_actions.php';
    const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
    const $ = id => document.getElementById(id);
    const errorBox = $('authError');
    const stepPassword = $('stepPassword'), stepOtp = $('stepOtp');
    const REDIRECT = '../dashboard/loading.php';

    function showError(message) { $('authErrorText').textContent = message; errorBox.hidden = false; }
    function clearError() { errorBox.hidden = true; }
    async function post(action, fields) {
        const fd = new FormData();
        fd.set('action', action);
        Object.entries(fields || {}).forEach(([k, v]) => fd.set(k, v));
        fd.set('_csrf_token', csrf());
        const response = await fetch(API, { method: 'POST', body: fd });
        try { return await response.json(); } catch { return { success: false, message: 'Request failed. Please try again.' }; }
    }

    // ---- Step 1: password ------------------------------------------------------------------------------------------------------------
    const input = $('password'), toggle = $('passwordToggle'), hint = $('capsLockHint');
    toggle.addEventListener('click', function () {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
        toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        toggle.querySelector('i').className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
        input.focus();
    });
    function capsLock(event) { if (typeof event.getModifierState === 'function') hint.hidden = !event.getModifierState('CapsLock'); }
    input.addEventListener('keydown', capsLock);
    input.addEventListener('keyup', capsLock);
    input.addEventListener('blur', function () { hint.hidden = true; });
    ['username', 'password'].forEach(function (id) { $(id).addEventListener('input', function () { this.removeAttribute('aria-invalid'); }); });

    $('signinForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const usernameInput = $('username'), passwordInput = $('password'), btn = $('btnSignin');
        const username = usernameInput.value.trim(), password = passwordInput.value;
        clearError();
        [usernameInput, passwordInput].forEach(function (field) {
            field.removeAttribute('aria-invalid');
            if (field === passwordInput) field.setAttribute('aria-describedby', 'capsLockHint'); else field.removeAttribute('aria-describedby');
        });
        if (!username || !password) {
            if (!username) markInvalid(usernameInput);
            if (!password) markInvalid(passwordInput);
            showError('Please enter your username and password.');
            (!username ? usernameInput : passwordInput).focus();
            return;
        }
        passwordInput.type = 'password';
        btn.disabled = true; btn.setAttribute('aria-busy', 'true'); btn.textContent = 'Signing in…';
        try {
            const data = await post('login', { username, password });
            if (data.success && data.otp_required) { passwordInput.value = ''; showOtp(data); }
            else if (data.success) window.location.href = data.redirect || REDIRECT;
            else showError(data.message);
        } catch { showError('Request failed. Please try again.'); }
        btn.disabled = false; btn.removeAttribute('aria-busy');
        btn.innerHTML = 'Sign In <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>';

        function markInvalid(field) {
            field.setAttribute('aria-invalid', 'true');
            field.setAttribute('aria-describedby', field === passwordInput ? 'authError capsLockHint' : 'authError');
        }
    });

    // ---- Step 2: code from the email -------------------------------------------------------------------------------------------------
    let expiresAt = 0, resendAt = 0, ticker = 0;
    const code = $('otpCode'), resend = $('btnResend'), verify = $('btnOtp');
    const clock = seconds => Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');

    function tick() {
        const now = Date.now();
        const left = Math.max(0, Math.ceil((expiresAt - now) / 1000)), wait = Math.max(0, Math.ceil((resendAt - now) / 1000));
        $('otpCountdown').textContent = clock(left);
        $('otpTimer').classList.toggle('is-expired', left === 0);
        $('otpLive').hidden = left === 0; $('otpGone').hidden = left !== 0;
        resend.disabled = wait > 0;
        resend.textContent = wait > 0 ? 'Resend code in ' + wait + 's' : 'Resend code';
        verify.disabled = left === 0;
    }
    function startTimers(expiresIn, resendIn) {
        expiresAt = Date.now() + expiresIn * 1000; resendAt = Date.now() + resendIn * 1000;
        clearInterval(ticker); ticker = setInterval(tick, 500); tick();
    }
    function showOtp(data) {
        clearError();
        $('otpEmailHint').textContent = data.email_hint || 'your email';
        stepPassword.hidden = true; stepOtp.hidden = false;
        code.value = '';
        startTimers(data.expires_in, data.resend_in);
        code.focus();
    }
    function showPassword() {
        clearInterval(ticker);
        stepOtp.hidden = true; stepPassword.hidden = false;
        clearError(); $('username').focus();
    }
    code.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 6); clearError(); });

    $('otpForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        clearError();
        if (!/^[0-9]{6}$/.test(code.value)) { showError('Enter the 6-digit code from the email.'); code.focus(); return; }
        verify.disabled = true; verify.setAttribute('aria-busy', 'true');
        try {
            const data = await post('otp_verify', { code: code.value });
            if (data.success) { window.location.href = data.redirect || REDIRECT; return; }
            if (data.restart) { showPassword(); showError(data.message); return; }
            showError(data.message);
            if (typeof data.expires_in === 'number') { expiresAt = Date.now() + data.expires_in * 1000; resendAt = Date.now() + (data.resend_in || 0) * 1000; }
            if (data.expired || data.locked) expiresAt = Date.now();
            code.select();
        } catch { showError('Request failed. Please try again.'); }
        verify.removeAttribute('aria-busy'); tick();
    });
    resend.addEventListener('click', async function () {
        clearError(); resend.disabled = true;
        try {
            const data = await post('otp_resend', {});
            if (data.success && data.otp_required) { showOtp(data); return; }
            if (data.restart) { showPassword(); showError(data.message); return; }
            showError(data.message);
            if (typeof data.retry_after === 'number') resendAt = Date.now() + data.retry_after * 1000;
        } catch { showError('Request failed. Please try again.'); }
        tick();
    });
    $('btnBack').addEventListener('click', async function () {
        try { await post('otp_cancel', {}); } catch { /* the server also drops the pending step after 15 minutes */ }
        code.value = ''; showPassword();
    });

    <?php if ($otpActive): ?>
    startTimers(Number($('stepOtp').dataset.expiresIn), Number($('stepOtp').dataset.resendIn));
    <?php endif; ?>
})();
</script>
</body>
</html>
