(function () {
  'use strict';

  const script = document.currentScript;
  if (!script) return;

  // The timeout comes only from the server (CSMS_IDLE_TIMEOUT_SECONDS via data-timeout-seconds).
  // Without it the client timer stays off; the server-side idle check still signs the user out.
  const timeoutSeconds = Number(script.dataset.timeoutSeconds);
  if (!(timeoutSeconds > 0)) return;
  const timeoutMs = timeoutSeconds * 1000;
  // Warning window (CSMS_IDLE_WARNING_SECONDS via data-warning-seconds): the modal opens at timeout − window.
  // Without it, or when it is not shorter than the timeout, there is no warning and the sign-out is silent.
  const warningSeconds = Math.max(0, Number(script.dataset.warningSeconds) || 0);
  const warnMs = warningSeconds > 0 && warningSeconds < timeoutSeconds ? timeoutMs - warningSeconds * 1000 : 0;
  const lastServerActivity = (Number(script.dataset.lastActivity) || 0) * 1000;
  const userId = Number(script.dataset.userId) || 0;
  const activityKey = 'csms:last-activity:' + userId;
  const csrfToken = script.dataset.csrfToken || '';
  const logoutUrl = script.dataset.logoutUrl || '';
  const signinUrl = script.dataset.signinUrl || '../auth/signin.php?reason=session_timeout';
  const HEARTBEAT_INTERVAL_MS = 30000;
  let storedActivity = 0;
  try { storedActivity = Number(localStorage.getItem(activityKey)) || 0; } catch (error) { /* storage may be disabled */ }

  let lastActivity = Math.max(lastServerActivity, storedActivity, Date.now());
  let lastPersist = 0;
  let lastHeartbeat = Date.now();
  let heartbeatTimer = 0;
  let timer = 0;
  let countdownTimer = 0;
  let expiring = false;
  let redirected = false;
  let warningOpen = false;
  let staying = false;
  let dialog = null;
  let previousFocus = null;

  function persistActivity(now) {
    if (now - lastPersist < 1000) return;
    lastPersist = now;
    try { localStorage.setItem(activityKey, String(now)); } catch (error) { /* storage may be disabled */ }
  }

  function syncSharedActivity() {
    let shared = 0;
    try { shared = Number(localStorage.getItem(activityKey)) || 0; } catch (error) { /* storage may be disabled */ }
    if (shared > lastActivity) lastActivity = shared;
  }

  function post(action) {
    const body = new URLSearchParams({ action: action, _csrf_token: csrfToken });
    return fetch(logoutUrl, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true });
  }

  function redirectToSignIn() {
    if (redirected) return;
    redirected = true;
    expiring = true;
    clearTimeout(timer);
    clearInterval(countdownTimer);
    window.location.replace(signinUrl);
  }

  // Keep-alive: POST action=activity to shared/auth_actions.php (CSRF-checked, authenticated, refreshes the
  // server's last-activity time, 401 once the session has expired). Throttled, with a trailing call so the
  // server's clock is never behind the last user activity this script has seen.
  function sendHeartbeat() {
    heartbeatTimer = 0;
    if (!logoutUrl || !csrfToken || expiring) return;
    lastHeartbeat = Date.now();
    post('activity')
      .then(function (response) {
        if (response.status === 401) redirectToSignIn();
      })
      .catch(function () { /* a later authenticated request will enforce expiry */ });
  }

  function requestHeartbeat(now) {
    if (!logoutUrl || !csrfToken || heartbeatTimer) return;
    const wait = HEARTBEAT_INTERVAL_MS - (now - lastHeartbeat);
    if (wait <= 0) sendHeartbeat();
    else heartbeatTimer = window.setTimeout(sendHeartbeat, wait);
  }

  function recordActivity() {
    // While the warning is open only its buttons count: a mouse twitch must not silently dismiss it.
    if (expiring || warningOpen) return;
    const now = Date.now();
    lastActivity = now;
    persistActivity(now);
    requestHeartbeat(now);
    schedule();
  }

  function schedule() {
    clearTimeout(timer);
    if (expiring || warningOpen) return;
    const idle = Date.now() - lastActivity;
    const target = warnMs && idle < warnMs ? warnMs : timeoutMs;
    timer = window.setTimeout(check, Math.max(1, target - idle));
  }

  function check() {
    if (expiring) return;
    syncSharedActivity();
    if (warningOpen) { updateCountdown(); return; }
    const idle = Date.now() - lastActivity;
    if (idle >= timeoutMs) { expire(); return; }
    if (warnMs && idle >= warnMs) { openWarning(); return; }
    schedule();
  }

  function expire() {
    if (expiring) return;
    expiring = true;
    clearTimeout(timer);
    clearInterval(countdownTimer);
    clearTimeout(heartbeatTimer);
    post('logout')
      .catch(function () { /* server-side idle validation still expires the session */ })
      .finally(redirectToSignIn);
    // Do not keep a backgrounded page signed in if the network request stalls.
    window.setTimeout(redirectToSignIn, 750);
  }

  // ---- Warning dialog -------------------------------------------------------------------------------------

  function buildDialog() {
    const el = document.createElement('dialog');
    el.className = 'csms-session-warning';
    el.id = 'csmsSessionWarning';
    el.setAttribute('role', 'alertdialog');
    el.setAttribute('aria-labelledby', 'csmsSessionWarningTitle');
    el.setAttribute('aria-describedby', 'csmsSessionWarningText');
    el.innerHTML =
      '<div class="csms-session-warning__body">' +
        '<h2 class="csms-session-warning__title" id="csmsSessionWarningTitle">Your session is about to end</h2>' +
        '<p class="csms-session-warning__text" id="csmsSessionWarningText">You’ll be signed out in ' +
          '<span class="csms-session-warning__count" id="csmsSessionWarningCount" aria-hidden="true"></span> ' +
          '<span id="csmsSessionWarningUnit" aria-hidden="true">seconds</span> due to inactivity.</p>' +
        '<p class="visually-hidden" id="csmsSessionWarningLive" role="status" aria-live="polite"></p>' +
        '<p class="csms-session-warning__error" id="csmsSessionWarningError" role="alert" hidden></p>' +
      '</div>' +
      '<div class="csms-session-warning__actions">' +
        '<button type="button" class="csms-session-warning__btn" id="csmsSessionWarningSignOut">Sign out</button>' +
        '<button type="button" class="csms-session-warning__btn csms-session-warning__btn--primary" id="csmsSessionWarningStay" autofocus>Stay signed in</button>' +
      '</div>';
    document.body.appendChild(el);
    el.querySelector('#csmsSessionWarningStay').addEventListener('click', staySignedIn);
    el.querySelector('#csmsSessionWarningSignOut').addEventListener('click', expire);
    // Escape is the safe choice: it keeps the session, it never signs the user out or silently hides the warning.
    el.addEventListener('cancel', function (event) { event.preventDefault(); staySignedIn(); });
    return el;
  }

  function setText(id, text) {
    const node = dialog.querySelector('#' + id);
    if (node && node.textContent !== text) node.textContent = text;
  }

  function renderCountdown(left) {
    setText('csmsSessionWarningCount', String(left));
    setText('csmsSessionWarningUnit', left === 1 ? 'second' : 'seconds');
    // Screen readers: announce at 30 and 10 seconds, not every tick (the dialog description covers the start).
    if (left === 30 || left === 10) setText('csmsSessionWarningLive', 'You’ll be signed out in ' + left + ' seconds.');
  }

  function openWarning() {
    if (warningOpen || expiring) return;
    if (!dialog) dialog = buildDialog();
    warningOpen = true;
    clearTimeout(timer);
    previousFocus = document.activeElement;
    setText('csmsSessionWarningLive', '');
    dialog.querySelector('#csmsSessionWarningError').hidden = true;
    dialog.querySelector('#csmsSessionWarningStay').disabled = false;
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
    dialog.querySelector('#csmsSessionWarningStay').focus();
    updateCountdown();
    clearInterval(countdownTimer);
    countdownTimer = window.setInterval(updateCountdown, 1000);
  }

  function closeWarning() {
    if (!warningOpen) return;
    warningOpen = false;
    clearInterval(countdownTimer);
    if (dialog.open) {
      if (typeof dialog.close === 'function') dialog.close();
      else dialog.removeAttribute('open');
    }
    if (previousFocus && document.contains(previousFocus) && typeof previousFocus.focus === 'function') previousFocus.focus();
    previousFocus = null;
  }

  function updateCountdown() {
    if (!warningOpen || expiring) return;
    syncSharedActivity(); // another tab may have been used, or kept the session alive
    const idle = Date.now() - lastActivity;
    if (idle < warnMs) { closeWarning(); schedule(); return; }
    const left = Math.ceil((timeoutMs - idle) / 1000);
    if (left <= 0) { expire(); return; }
    renderCountdown(left);
  }

  async function staySignedIn() {
    if (staying || expiring || !warningOpen) return;
    staying = true;
    const stay = dialog.querySelector('#csmsSessionWarningStay');
    const error = dialog.querySelector('#csmsSessionWarningError');
    stay.disabled = true;
    error.hidden = true;
    try {
      const response = await post('activity');
      if (response.status === 401) { redirectToSignIn(); return; } // already expired on the server
      if (!response.ok) throw new Error('Keep-alive failed with status ' + response.status);
      const now = Date.now();
      lastActivity = now;
      lastHeartbeat = now;
      lastPersist = 0;
      persistActivity(now);
      closeWarning();
      schedule();
    } catch (failure) {
      error.textContent = 'We could not extend your session. Check your connection and try again, or sign out.';
      error.hidden = false;
      stay.disabled = false;
      stay.focus();
    } finally {
      staying = false;
    }
  }

  ['pointerdown', 'pointermove', 'keydown', 'touchstart', 'scroll', 'click', 'input'].forEach(function (eventName) {
    document.addEventListener(eventName, recordActivity, { passive: true });
  });
  window.addEventListener('storage', function (event) {
    if (event.key !== activityKey) return;
    const sharedActivity = Number(event.newValue) || 0;
    if (sharedActivity > lastActivity) {
      lastActivity = sharedActivity;
      if (warningOpen) closeWarning();
      schedule();
    }
  });
  window.addEventListener('focus', check);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) check();
  });
  window.addEventListener('pageshow', check);

  schedule();
}());
