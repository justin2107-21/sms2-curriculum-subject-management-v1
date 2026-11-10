(function () {
    'use strict';
    // Shared row menu: one floating menu element per page, opened from a "..." button in a table row.
    //   const menu = CsmsMenu.create(document.getElementById('rowMenu'), { esc, onSelect(action, data, opener) {} });
    //   menu.open(button, [{ action: 'edit', label: 'Edit', data: { id: 3 }, danger: false }]);
    // Keyboard: Arrow Up/Down and Home/End move between items, Escape closes and returns focus to the button, Tab closes.
    // Clicking outside, scrolling or resizing the window closes it. Items are real buttons (role="menuitem").
    function create(menu, options) {
        const esc = options.esc;
        let opener = null, openedAt = 0;
        const items = () => [...menu.querySelectorAll('button')];
        // position:fixed is measured from the viewport only when no ancestor has a transform, filter or contain. Moving the menu
        // to <body> makes that true on every page, so it always opens at its button.
        if (window.document?.body && menu.parentNode !== window.document.body) window.document.body.appendChild(menu);
        function close(returnFocus) {
            if (menu.hidden) return;
            menu.hidden = true;
            opener?.setAttribute?.('aria-expanded', 'false');
            if (returnFocus) opener?.focus();
            opener = null;
        }
        function open(button, list) {
            if (!menu.hidden && opener === button) { close(true); return; }
            opener?.setAttribute?.('aria-expanded', 'false');
            opener = button;
            menu.innerHTML = list.map(item => '<button type="button" role="menuitem"' + (item.danger ? ' class="is-danger"' : '') + (item.disabled ? ' aria-disabled="true"' : '') +
                ' data-menu-action="' + esc(item.action) + '"' + Object.entries(item.data || {}).map(([k, v]) => ' data-' + k + '="' + esc(v) + '"').join('') + '>' + esc(item.label) + (item.disabled && item.hint ? '<span class="csms-menu__hint">' + esc(item.hint) + '</span>' : '') + '</button>').join('');
            const box = button.getBoundingClientRect ? button.getBoundingClientRect() : { top: 0, bottom: 0, right: 0 };
            menu.hidden = false;
            const height = menu.offsetHeight || list.length * 38 + 8, viewport = window.innerHeight || 0;
            const below = Math.round(box.bottom + 4);
            menu.style.top = (viewport && below + height > viewport - 8 ? Math.max(8, Math.round(box.top - height - 4)) : below) + 'px';
            menu.style.left = Math.max(8, Math.min(Math.round(box.right - 168), (window.innerWidth || 0) - (menu.offsetWidth || 168) - 8)) + 'px';
            button.setAttribute?.('aria-expanded', 'true');
            openedAt = Date.now();
            menu.querySelector('button')?.focus({ preventScroll: true });
        }
        menu.addEventListener('click', event => {
            const item = event.target.closest('[data-menu-action]');
            if (!item) return;
            // A disabled item stays open and shows its reason; it never runs the action.
            if (item.getAttribute('aria-disabled') === 'true') return;
            const trigger = opener, data = { ...item.dataset };
            delete data.menuAction;
            close(false);
            options.onSelect(item.dataset.menuAction, data, trigger);
        });
        menu.addEventListener('keydown', event => {
            const list = items(), index = list.indexOf(event.target);
            if (event.key === 'Escape') { event.preventDefault(); close(true); }
            else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); list[(index + (event.key === 'ArrowDown' ? 1 : list.length - 1)) % list.length]?.focus(); }
            else if (event.key === 'Home' || event.key === 'End') { event.preventDefault(); list[event.key === 'Home' ? 0 : list.length - 1]?.focus(); }
            else if (event.key === 'Tab') close(false);
        });
        const outside = event => { if (!menu.hidden && !event.target.closest('#' + menu.id) && !event.target.closest('[aria-haspopup="menu"]')) close(false); };
        window.document?.addEventListener?.('click', outside);
        // The browser may scroll the page itself right after opening (focus, soft keyboard); only a later scroll closes the menu.
        window.addEventListener?.('scroll', () => { if (Date.now() - openedAt > 400) close(false); }, true);
        window.addEventListener?.('resize', () => close(false));
        return { open, close, isOpen: () => !menu.hidden, outside };
    }
    window.CsmsMenu = { create };
}());
