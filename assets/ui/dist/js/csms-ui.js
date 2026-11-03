(function () {
  'use strict';

  // Mobile sidebar toggle.
  var toggle = document.getElementById('csmsMenuToggle');
  var sidebar = document.getElementById('csmsSidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', function () {
      var open = sidebar.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
        sidebar.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.focus();
      }
    });
  }

  // Context bar: "Change" expands the collapsed one-line summary (D3).
  document.querySelectorAll('[data-context-bar]').forEach(function (bar) {
    var change = bar.querySelector('[data-context-change]');
    if (!change) return;
    change.addEventListener('click', function () {
      bar.classList.remove('is-collapsed');
      var first = bar.querySelector('select');
      if (first) first.focus();
    });
  });
})();
