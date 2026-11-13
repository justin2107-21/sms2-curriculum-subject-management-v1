<?php
require_once __DIR__ . '/../shared/bootstrap.php';
require_once __DIR__ . '/../shared/EmailOtp.php';

if (is_authenticated()) {
    header('Location: ../dashboard/dashboard.php');
    exit;
}

// Email OTP only: after the password step a code was sent and there is no logged-in session yet; a refresh keeps the code step.
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
  <link rel="stylesheet" href="../css/signin-otp.css?v=<?= (int)filemtime(__DIR__ . '/../css/signin-otp.css') ?>" />
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

      <h2 id="signinTitle"<?= $otpActive ? ' hidden' : '' ?>>Sign In to CSMS</h2>

      <div class="auth-error" id="authError" role="alert" hidden>
        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
        <span id="authErrorText"></span>
      </div>

      <form id="signinForm" style="width:100%"<?= $otpActive ? ' hidden' : '' ?> novalidate>
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

      <p class="authorized-users-note" id="signinNote"<?= $otpActive ? ' hidden' : '' ?>>For authorized users only.</p>

      <!-- Email OTP step (only shown when OTP_ENABLED=1 and the password was right) -->
      <div id="stepOtp"<?= $otpActive ? '' : ' hidden' ?> data-expires-in="<?= (int)$otpExpiresIn ?>" data-resend-in="<?= (int)$otpResendIn ?>">
        <h2 id="otpTitle">Check your email</h2>
        <p class="otp-lead">We sent a 6-digit code to <strong id="otpEmailHint"><?= htmlspecialchars($otpHint, ENT_QUOTES, 'UTF-8') ?></strong>. Enter it below to finish signing in.</p>
        <form id="otpForm" novalidate>
          <div class="form-group">
            <label id="otpLabel" for="otpDigit1">Verification code</label>
            <div class="otp-boxes" role="group" aria-labelledby="otpLabel">
              <input type="text" id="otpDigit1" class="otp-box" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" autocapitalize="none" spellcheck="false" aria-label="Digit 1 of 6" />
              <input type="text" id="otpDigit2" class="otp-box" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off" spellcheck="false" aria-label="Digit 2 of 6" />
              <input type="text" id="otpDigit3" class="otp-box" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off" spellcheck="false" aria-label="Digit 3 of 6" />
              <input type="text" id="otpDigit4" class="otp-box" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off" spellcheck="false" aria-label="Digit 4 of 6" />
              <input type="text" id="otpDigit5" class="otp-box" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off" spellcheck="false" aria-label="Digit 5 of 6" />
              <input type="text" id="otpDigit6" class="otp-box" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off" spellcheck="false" aria-label="Digit 6 of 6" />
            </div>
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
        if (data.success && data.otp_required) {
            passwordInput.value = '';
            resetButton();
            window.csmsOtp.show(data);
        } else if (data.success) {
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

<script>
// Email OTP step: six digit boxes, 5-minute countdown, resend with a 60-second cooldown. Nothing here touches the password step.
(function () {
    'use strict';
    const API = '../shared/auth_actions.php';
    const $ = id => document.getElementById(id);
    const errorBox = $('authError');
    const stepOtp = $('stepOtp');
    const parts = ['signinTitle', 'signinForm', 'signinNote'].map($);
    const boxes = Array.from(stepOtp.querySelectorAll('.otp-box'));
    const resend = $('btnResend'), verify = $('btnOtp');
    let expiresAt = 0, resendAt = 0, ticker = 0, busy = false;

    const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
    const showError = message => { $('authErrorText').textContent = message; errorBox.hidden = false; boxes.forEach(b => b.setAttribute('aria-invalid', 'true')); };
    const clearError = () => { errorBox.hidden = true; boxes.forEach(b => b.removeAttribute('aria-invalid')); };
    const codeValue = () => boxes.map(b => b.value).join('');
    const clock = seconds => Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
    async function post(action, fields) {
        const fd = new FormData();
        fd.set('action', action);
        Object.entries(fields || {}).forEach(([k, v]) => fd.set(k, v));
        fd.set('_csrf_token', csrf());
        const response = await fetch(API, { method: 'POST', body: fd });
        try { return await response.json(); } catch { return { success: false, message: 'Request failed. Please try again.' }; }
    }

    // Digit boxes
    function spread(digits, from) {
        for (let k = 0; k < digits.length && from + k < boxes.length; k += 1) boxes[from + k].value = digits[k];
        boxes[Math.min(from + digits.length, boxes.length - 1)].focus();
    }
    function clearBoxes() { boxes.forEach(b => { b.value = ''; }); }
    boxes.forEach((box, i) => {
        box.addEventListener('input', () => {
            const digits = box.value.replace(/\D/g, '');
            clearError();
            if (digits.length > 1) { box.value = ''; spread(digits.length >= 6 ? digits.slice(0, 6) : digits, digits.length >= 6 ? 0 : i); return; }
            box.value = digits;
            if (digits && i < boxes.length - 1) boxes[i + 1].focus();
        });
        box.addEventListener('keydown', event => {
            if (event.key === 'Backspace' && !box.value && i > 0) { event.preventDefault(); boxes[i - 1].value = ''; boxes[i - 1].focus(); }
            else if (event.key === 'ArrowLeft' && i > 0) { event.preventDefault(); boxes[i - 1].focus(); }
            else if (event.key === 'ArrowRight' && i < boxes.length - 1) { event.preventDefault(); boxes[i + 1].focus(); }
        });
        box.addEventListener('paste', event => {
            const digits = ((event.clipboardData || window.clipboardData).getData('text') || '').replace(/\D/g, '');
            if (digits.length < 2) return;
            event.preventDefault(); clearError();
            if (digits.length >= 6) { clearBoxes(); spread(digits.slice(0, 6), 0); } else spread(digits, i);
        });
        box.addEventListener('focus', () => box.select());
    });

    // Timers
    function tick() {
        const now = Date.now();
        const left = Math.max(0, Math.ceil((expiresAt - now) / 1000)), wait = Math.max(0, Math.ceil((resendAt - now) / 1000));
        $('otpCountdown').textContent = clock(left);
        $('otpTimer').classList.toggle('is-expired', left === 0);
        $('otpLive').hidden = left === 0; $('otpGone').hidden = left !== 0;
        resend.disabled = wait > 0 || busy;
        resend.textContent = wait > 0 ? 'Resend code in ' + wait + 's' : 'Resend code';
        verify.disabled = left === 0 || busy;
    }
    function startTimers(expiresIn, resendIn) {
        expiresAt = Date.now() + expiresIn * 1000; resendAt = Date.now() + resendIn * 1000;
        clearInterval(ticker); ticker = setInterval(tick, 500); tick();
    }
    function show(data) {
        clearError();
        $('otpEmailHint').textContent = data.email_hint || 'your email';
        parts.forEach(p => { p.hidden = true; });
        stepOtp.hidden = false;
        clearBoxes();
        startTimers(data.expires_in, data.resend_in);
        boxes[0].focus();
    }
    function showPassword() {
        clearInterval(ticker);
        stepOtp.hidden = true;
        parts.forEach(p => { p.hidden = false; });
        clearBoxes(); clearError();
        $('username').focus();
    }
    window.csmsOtp = { show };

    $('otpForm').addEventListener('submit', async event => {
        event.preventDefault();
        clearError();
        const code = codeValue();
        if (!/^[0-9]{6}$/.test(code)) { showError('Enter all 6 digits of the code from the email.'); (boxes.find(b => !b.value) || boxes[0]).focus(); return; }
        busy = true; verify.setAttribute('aria-busy', 'true'); tick();
        try {
            const data = await post('otp_verify', { code });
            if (data.success) { window.location.href = data.redirect || '../dashboard/loading.php'; return; }
            if (data.restart) { showPassword(); showError(data.message); return; }
            showError(data.message);
            if (typeof data.expires_in === 'number') { expiresAt = Date.now() + data.expires_in * 1000; resendAt = Date.now() + (data.resend_in || 0) * 1000; }
            if (data.expired || data.locked) expiresAt = Date.now();
            clearBoxes(); boxes[0].focus();
        } catch { showError('Request failed. Please try again.'); }
        busy = false; verify.removeAttribute('aria-busy'); tick();
    });
    resend.addEventListener('click', async () => {
        clearError(); busy = true; tick();
        try {
            const data = await post('otp_resend', {});
            if (data.success && data.otp_required) { busy = false; show(data); return; }
            if (data.restart) { busy = false; showPassword(); showError(data.message); return; }
            showError(data.message);
            if (typeof data.retry_after === 'number') resendAt = Date.now() + data.retry_after * 1000;
        } catch { showError('Request failed. Please try again.'); }
        busy = false; tick();
    });
    $('btnBack').addEventListener('click', async () => {
        try { await post('otp_cancel', {}); } catch { /* the server also drops the pending step after 15 minutes */ }
        showPassword();
    });

    <?php if ($otpActive): ?>
    startTimers(Number(stepOtp.dataset.expiresIn), Number(stepOtp.dataset.resendIn));
    <?php endif; ?>
})();
</script>
</body>
</html>
