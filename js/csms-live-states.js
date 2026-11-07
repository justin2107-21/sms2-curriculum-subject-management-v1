(function () {
  'use strict';
  // UI_Audit ST2: error messages are alerts, success / empty / loading messages are status updates, so screen
  // readers announce them. Attribute-only: nothing here changes how a message looks or when it is shown.
  // It covers the many places that build these messages (tables, panels, forms) in one spot; the shared helpers in
  // curriculum-common.js (toast, form error, autocomplete rows) also set the role themselves before inserting text.
  //
  // Table cells (td/th/tr) get aria-live instead of a role: a role on a cell would remove it from the table.
  // Existing role / aria-live attributes are never overwritten. Dashboard chart cards are not included on purpose
  // (the dashboard announces updates through its own status line).

  const ALERTS = '.form-error, .error-state, .is-error, .dashboard-error, .record-autocomplete-state.error';
  const STATUSES = '.state-cell, .mapping-state, .pr-empty, .empty-cell, .export-empty-state, ' +
    '.equivalency-empty-context, .grade-weighting-empty, .mapping-review-success, .record-autocomplete-state';
  const ALL = ALERTS + ', ' + STATUSES;

  function mark(el) {
    if (el.hasAttribute('role') || el.hasAttribute('aria-live')) return;
    const alert = el.matches(ALERTS);
    if (/^(TD|TH|TR)$/.test(el.tagName)) {
      el.setAttribute('aria-live', alert ? 'assertive' : 'polite');
      el.setAttribute('aria-atomic', 'true');
    } else {
      el.setAttribute('role', alert ? 'alert' : 'status');
    }
  }

  function scan(node) {
    if (!node || node.nodeType !== 1) return;
    if (node.matches(ALL)) mark(node);
    node.querySelectorAll(ALL).forEach(mark);
  }

  function start() {
    scan(document.body);
    new MutationObserver(function (records) {
      records.forEach(function (record) { record.addedNodes.forEach(scan); });
    }).observe(document.body, { childList: true, subtree: true });
  }

  if (document.body) start();
  else document.addEventListener('DOMContentLoaded', start);
}());
