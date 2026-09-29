(function () {
    'use strict';

    const hamburger = document.getElementById('hamburgerBtn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    function updateSidebarState() {
        if (!hamburger || !sidebar) return;
        const expanded = !sidebar.classList.contains('collapsed');
        hamburger.setAttribute('aria-expanded', String(expanded));
        if (overlay) {
            overlay.classList.toggle('active', window.innerWidth <= 900 && expanded);
        }
    }

    if (sidebar && window.innerWidth <= 900) {
        sidebar.classList.add('collapsed');
    }
    if (hamburger && sidebar) {
        updateSidebarState();
        hamburger.addEventListener('click', function () {
            sidebar.classList.toggle('collapsed');
            updateSidebarState();
        });
    }
    if (overlay && sidebar) {
        overlay.addEventListener('click', function () {
            sidebar.classList.add('collapsed');
            updateSidebarState();
        });
    }
    window.addEventListener('resize', updateSidebarState);

    document.querySelectorAll('.dropdown-trigger').forEach(function (button) {
        button.addEventListener('click', function () {
            const menu = document.getElementById(button.dataset.target);
            if (!menu) return;
            const wasOpen = menu.classList.contains('open');
            document.querySelectorAll('.dropdown-menu.open').forEach(function (item) {
                item.classList.remove('open');
            });
            document.querySelectorAll('.sidebar-item.open').forEach(function (item) {
                item.classList.remove('open');
            });
            if (!wasOpen) {
                menu.classList.add('open');
                button.classList.add('open');
            }
        });
    });

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    class CurriculumRequestError extends Error {
        constructor(message, status) {
            super(message);
            this.name = 'CurriculumRequestError';
            this.status = status;
        }
    }

    async function responsePayload(response) {
        try {
            return await response.json();
        } catch (error) {
            throw new CurriculumRequestError('The server returned an unreadable response.', response.status);
        }
    }

    async function fetchJson(endpoint, parameters) {
        const url = new URL(endpoint, window.location.href);
        Object.entries(parameters || {}).forEach(function ([key, value]) {
            if (value !== null && value !== undefined && value !== '') {
                url.searchParams.set(key, String(value));
            }
        });
        const response = await fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        });
        const payload = await responsePayload(response);
        if (!response.ok || !payload.success) {
            throw new CurriculumRequestError(
                payload.message || 'The request could not be completed.',
                response.status
            );
        }
        return payload;
    }

    async function postForm(endpoint, formData) {
        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const payloadData = formData instanceof FormData ? formData : new FormData();
        payloadData.set('_csrf_token', token);
        const response = await fetch(endpoint, {
            method: 'POST',
            body: payloadData,
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        });
        const payload = await responsePayload(response);
        if (!response.ok || !payload.success) {
            throw new CurriculumRequestError(
                payload.message || 'The request could not be completed.',
                response.status
            );
        }
        return payload;
    }

    function showToast(message, type) {
        const labels = {
            success: 'Success',
            updated: 'Updated',
            warning: 'Attention',
            error: 'Unable to Complete'
        };
        const safeType = Object.prototype.hasOwnProperty.call(labels, type) ? type : 'success';
        let toast = document.querySelector('.toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.className = 'toast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            toast.innerHTML = '<div class="toast-label"><span class="toast-dot"></span>' +
                '<span class="toast-title"></span></div><div class="toast-msg"></div>';
            document.body.appendChild(toast);
        }
        toast.querySelector('.toast-title').textContent = labels[safeType];
        toast.querySelector('.toast-msg').textContent = message;
        toast.className = 'toast ' + safeType;
        void toast.offsetWidth;
        toast.classList.add('show');
        clearTimeout(toast._hideTimer);
        toast._hideTimer = setTimeout(function () { toast.classList.remove('show'); }, 3800);
    }

    function showFormError(form, message) {
        const errorBox = form?.querySelector('.form-error');
        if (!errorBox) return;
        errorBox.textContent = message;
        errorBox.hidden = false;
        errorBox.setAttribute('tabindex', '-1');
        errorBox.focus();
    }

    function clearFormError(form) {
        const errorBox = form?.querySelector('.form-error');
        if (!errorBox) return;
        errorBox.textContent = '';
        errorBox.hidden = true;
    }

    function setFormSubmitting(form, submitting, busyLabel) {
        const button = form?.querySelector('[type="submit"]');
        if (!button) return;
        if (submitting) button.dataset.defaultLabel = button.innerHTML;
        button.disabled = submitting;
        button.innerHTML = submitting
            ? '<i class="fa-solid fa-spinner fa-spin"></i> ' + escapeHtml(busyLabel || 'Saving…')
            : (button.dataset.defaultLabel || button.innerHTML);
    }

    function createModal(element) {
        if (!element) return null;
        let previousFocus = null;

        function focusableElements() {
            return Array.from(element.querySelectorAll(
                'button:not([disabled]), input:not([disabled]), select:not([disabled]), ' +
                'textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )).filter(function (item) { return !item.hidden; });
        }

        function open() {
            previousFocus = document.activeElement;
            element.hidden = false;
            document.body.classList.add('curriculum-modal-open');
            requestAnimationFrame(function () {
                focusableElements()[0]?.focus();
            });
        }

        function close() {
            element.hidden = true;
            if (!document.querySelector('.curriculum-modal:not([hidden])')) {
                document.body.classList.remove('curriculum-modal-open');
            }
            previousFocus?.focus();
        }

        element.querySelectorAll('[data-modal-close]').forEach(function (button) {
            button.addEventListener('click', close);
        });
        element.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                close();
                return;
            }
            if (event.key !== 'Tab') return;
            const focusable = focusableElements();
            if (!focusable.length) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });

        return { open, close };
    }

    function statusBadge(status) {
        const normalized = String(status || '').toLowerCase();
        const safeClass = ['active', 'inactive', 'draft', 'archived'].includes(normalized)
            ? normalized
            : 'inactive';
        return '<span class="status-badge status-' + safeClass + '">' + escapeHtml(status || 'Unknown') + '</span>';
    }

    function formatDate(value) {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return String(value);
        return new Intl.DateTimeFormat(undefined, {
            year: 'numeric', month: 'short', day: 'numeric'
        }).format(date);
    }

    function formatUnits(value) {
        if (value === null || value === undefined || value === '') return '—';
        const number = Number(value);
        return Number.isFinite(number) ? number.toFixed(2).replace(/\.00$/, '') : '—';
    }

    function setCount(element, count) {
        if (element) element.textContent = count + (count === 1 ? ' record' : ' records');
    }

    function renderPagination(container, pagination, onPage) {
        if (!container || !pagination) return;
        const page = Number(pagination.page);
        const totalPages = Number(pagination.total_pages);
        const total = Number(pagination.total_records);
        const perPage = Number(pagination.per_page);
        const first = total === 0 ? 0 : ((page - 1) * perPage) + 1;
        const last = Math.min(page * perPage, total);

        container.innerHTML = '';
        const summary = document.createElement('span');
        summary.className = 'pagination-summary';
        summary.textContent = total === 0 ? 'No records' : 'Showing ' + first + '–' + last + ' of ' + total;

        const actions = document.createElement('div');
        actions.className = 'pagination-actions';
        const previous = document.createElement('button');
        previous.type = 'button';
        previous.className = 'page-button';
        previous.textContent = 'Previous';
        previous.disabled = page <= 1;
        previous.addEventListener('click', function () { onPage(page - 1); });

        const current = document.createElement('button');
        current.type = 'button';
        current.className = 'page-button current';
        current.textContent = page + ' / ' + totalPages;
        current.disabled = true;

        const next = document.createElement('button');
        next.type = 'button';
        next.className = 'page-button';
        next.textContent = 'Next';
        next.disabled = page >= totalPages;
        next.addEventListener('click', function () { onPage(page + 1); });

        actions.append(previous, current, next);
        container.append(summary, actions);
    }

    function debounce(callback, delay) {
        let timer;
        return function () {
            clearTimeout(timer);
            timer = setTimeout(callback, delay);
        };
    }

    window.CurriculumUI = {
        escapeHtml,
        fetchJson,
        postForm,
        showToast,
        showFormError,
        clearFormError,
        setFormSubmitting,
        createModal,
        statusBadge,
        formatDate,
        formatUnits,
        setCount,
        renderPagination,
        debounce
    };
}());
