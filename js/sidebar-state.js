(function () {
  'use strict';

  const STORAGE_KEY = 'sms2_sidebar_scroll';
  const sidebar = document.getElementById('sidebar');
  const sidebarNav = document.querySelector('#sidebar .sidebar-nav');

  if (!sidebar || !sidebarNav) {
    return;
  }

  /* Initialize the responsive drawer before the page-specific toggle script.
     The ready marker lets CSS keep the unopened drawer off-canvas without
     changing the sidebar's internal width or causing label reflow. */
  if (window.matchMedia('(max-width: 900px)').matches) {
    sidebar.classList.add('collapsed');
  }
  sidebar.classList.add('sidebar-ready');

  sidebarNav.querySelectorAll('[data-sidebar-menu-group]').forEach(function (menuGroup) {
    const menuToggle = menuGroup.querySelector('[data-sidebar-menu-toggle]');
    const submenu = menuGroup.querySelector('[data-sidebar-menu-panel]');
    const storageKey = menuGroup.dataset.sidebarMenuStorageKey;

    if (!menuToggle || !submenu || !storageKey) return;

    function setMenuOpen(open, persist) {
      menuGroup.classList.toggle('is-open', open);
      submenu.classList.toggle('open', open);
      menuToggle.setAttribute('aria-expanded', String(open));
      submenu.setAttribute('aria-hidden', String(!open));
      submenu.toggleAttribute('inert', !open);
      submenu.querySelectorAll('a').forEach(function (link) {
        link.tabIndex = open ? 0 : -1;
      });

      if (persist) {
        try {
          sessionStorage.setItem(storageKey, open ? '1' : '0');
        } catch (error) {
          // Storage may be unavailable in privacy-restricted browser contexts.
        }
      }
    }

    const hasActiveChild = Boolean(submenu.querySelector('.sidebar-submenu-item.active'));
    let storedOpen = false;
    try {
      storedOpen = sessionStorage.getItem(storageKey) === '1';
    } catch (error) {
      storedOpen = false;
    }

    setMenuOpen(hasActiveChild || storedOpen, false);
    menuToggle.addEventListener('click', function () {
      setMenuOpen(!submenu.classList.contains('open'), true);
    });
  });

  function readStoredPosition() {
    try {
      const storedValue = sessionStorage.getItem(STORAGE_KEY);

      if (storedValue === null || storedValue.trim() === '') {
        return null;
      }

      const position = Number(storedValue);
      return Number.isFinite(position) && position >= 0 ? position : null;
    } catch (error) {
      return null;
    }
  }

  function storePosition() {
    try {
      sessionStorage.setItem(STORAGE_KEY, String(Math.round(sidebarNav.scrollTop)));
    } catch (error) {
      // Storage may be unavailable in privacy-restricted browser contexts.
    }
  }

  function keepActiveItemVisible() {
    const activeItem = sidebarNav.querySelector('.sidebar-item.active, .sidebar-submenu-item.active');

    if (!activeItem) {
      return;
    }

    const navBounds = sidebarNav.getBoundingClientRect();
    const activeBounds = activeItem.getBoundingClientRect();
    const edgePadding = 8;

    if (activeBounds.top < navBounds.top + edgePadding) {
      sidebarNav.scrollTop -= navBounds.top + edgePadding - activeBounds.top;
    } else if (activeBounds.bottom > navBounds.bottom - edgePadding) {
      sidebarNav.scrollTop += activeBounds.bottom - navBounds.bottom + edgePadding;
    }
  }

  const storedPosition = readStoredPosition();

  if (storedPosition !== null) {
    const maximumPosition = Math.max(0, sidebarNav.scrollHeight - sidebarNav.clientHeight);
    sidebarNav.scrollTop = Math.min(storedPosition, maximumPosition);
  }

  keepActiveItemVisible();
  storePosition();

  let animationFramePending = false;

  sidebarNav.addEventListener('scroll', function () {
    if (animationFramePending) {
      return;
    }

    animationFramePending = true;
    window.requestAnimationFrame(function () {
      animationFramePending = false;
      storePosition();
    });
  }, { passive: true });
})();
