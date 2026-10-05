(function () {
  'use strict';

  const script = document.currentScript;
  if (!script) return;

  const timeoutSeconds = Math.max(1, Number(script.dataset.timeoutSeconds) || 180);
  const timeoutMs = timeoutSeconds * 1000;
  const lastServerActivity = (Number(script.dataset.lastActivity) || 0) * 1000;
  const userId = Number(script.dataset.userId) || 0;
  const activityKey = 'csms:last-activity:' + userId;
  const csrfToken = script.dataset.csrfToken || '';
  const logoutUrl = script.dataset.logoutUrl || '';
  const signinUrl = script.dataset.signinUrl || '../auth/signin.php?reason=session_timeout';
  let storedActivity = 0;
  try { storedActivity = Number(localStorage.getItem(activityKey)) || 0; } catch (error) { /* storage may be disabled */ }

  let lastActivity = Math.max(lastServerActivity, storedActivity, Date.now());
  let lastPersist = 0;
  let lastHeartbeat = Date.now();
  let timer = 0;
  let expiring = false;
  let redirected = false;

  function persistActivity(now) {
    if (now - lastPersist < 1000) return;
    lastPersist = now;
    try { localStorage.setItem(activityKey, String(now)); } catch (error) { /* storage may be disabled */ }
  }

  function redirectToSignIn() {
    if (redirected) return;
    redirected = true;
    expiring = true;
    clearTimeout(timer);
    window.location.replace(signinUrl);
  }

  function sendActivityHeartbeat(now) {
    if (!logoutUrl || !csrfToken || now - lastHeartbeat < 30000) return;
    lastHeartbeat = now;
    const body = new URLSearchParams({ action: 'activity', _csrf_token: csrfToken });
    fetch(logoutUrl, { method: 'POST', body, credentials: 'same-origin', keepalive: true })
      .then(function (response) {
        if (response.status === 401) redirectToSignIn();
      })
      .catch(function () { /* a later authenticated request will enforce expiry */ });
  }

  function recordActivity() {
    if (expiring) return;
    const now = Date.now();
    lastActivity = now;
    persistActivity(now);
    sendActivityHeartbeat(now);
    scheduleTimeout();
  }

  function checkTimeout() {
    if (expiring) return;
    let sharedActivity = 0;
    try { sharedActivity = Number(localStorage.getItem(activityKey)) || 0; } catch (error) { /* storage may be disabled */ }
    if (sharedActivity > lastActivity) lastActivity = sharedActivity;

    const remaining = timeoutMs - (Date.now() - lastActivity);
    if (remaining > 0) {
      scheduleTimeout(remaining);
      return;
    }

    expiring = true;
    clearTimeout(timer);
    const body = new URLSearchParams({ action: 'logout', _csrf_token: csrfToken });
    fetch(logoutUrl, { method: 'POST', body, credentials: 'same-origin', keepalive: true })
      .catch(function () { /* server-side idle validation still expires the session */ })
      .finally(redirectToSignIn);
    // Do not keep a backgrounded page signed in if the network request stalls.
    window.setTimeout(redirectToSignIn, 750);
  }

  function scheduleTimeout(delay) {
    clearTimeout(timer);
    const remaining = delay === undefined ? timeoutMs - (Date.now() - lastActivity) : delay;
    timer = window.setTimeout(checkTimeout, Math.max(1, remaining));
  }

  ['pointerdown', 'pointermove', 'keydown', 'touchstart', 'scroll', 'click', 'input'].forEach(function (eventName) {
    document.addEventListener(eventName, recordActivity, { passive: true });
  });
  window.addEventListener('storage', function (event) {
    if (event.key !== activityKey) return;
    const sharedActivity = Number(event.newValue) || 0;
    if (sharedActivity > lastActivity) {
      lastActivity = sharedActivity;
      scheduleTimeout();
    }
  });
  window.addEventListener('focus', checkTimeout);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) checkTimeout();
  });
  window.addEventListener('pageshow', checkTimeout);

  scheduleTimeout();
}());
