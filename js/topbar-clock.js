(function (root) {
  'use strict';

  function formatLocalTime(value) {
    const date = value instanceof Date ? value : new Date(value);
    const hours24 = date.getHours();
    const hours12 = hours24 % 12 || 12;
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const seconds = String(date.getSeconds()).padStart(2, '0');
    const period = hours24 >= 12 ? 'PM' : 'AM';

    return String(hours12).padStart(2, '0') + ':' + minutes + ':' + seconds + ' ' + period;
  }

  root.Sms2LocalTime = { formatLocalTime: formatLocalTime };

  if (typeof document === 'undefined' || root.__sms2TopbarClockStarted) {
    return;
  }

  const timeElement = document.getElementById('localTime');
  if (!timeElement) {
    return;
  }

  root.__sms2TopbarClockStarted = true;
  let clockInterval = null;

  function updateLocalTime() {
    const now = new Date();
    timeElement.textContent = formatLocalTime(now);
    timeElement.dateTime = now.toISOString();
  }

  updateLocalTime();
  clockInterval = root.setInterval(updateLocalTime, 1000);

  root.addEventListener('beforeunload', function () {
    if (clockInterval !== null) {
      root.clearInterval(clockInterval);
      clockInterval = null;
    }
    root.__sms2TopbarClockStarted = false;
  }, { once: true });
})(typeof window !== 'undefined' ? window : globalThis);
