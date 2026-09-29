(function () {
  'use strict';

  function initializeSidebar() {
    const sidebar = document.getElementById('sidebar');
    const hamburger = document.getElementById('hamburgerBtn');
    const overlay = document.getElementById('sidebarOverlay');

    if (!sidebar || !hamburger) {
      return;
    }

    function updateOverlay() {
      if (overlay) {
        overlay.classList.toggle(
          'active',
          window.innerWidth <= 900 && !sidebar.classList.contains('collapsed')
        );
      }
      hamburger.setAttribute('aria-expanded', String(!sidebar.classList.contains('collapsed')));
    }

    if (window.innerWidth <= 900) {
      sidebar.classList.add('collapsed');
    }
    updateOverlay();

    hamburger.addEventListener('click', function () {
      sidebar.classList.toggle('collapsed');
      updateOverlay();
    });

    if (overlay) {
      overlay.addEventListener('click', function () {
        sidebar.classList.add('collapsed');
        updateOverlay();
      });
    }

    window.addEventListener('resize', function () {
      if (window.innerWidth <= 900 && overlay && !sidebar.classList.contains('collapsed')) {
        overlay.classList.add('active');
      } else if (overlay) {
        overlay.classList.remove('active');
      }
    });
  }

  initializeSidebar();
})();
