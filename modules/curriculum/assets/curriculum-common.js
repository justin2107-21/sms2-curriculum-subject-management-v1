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
        constructor(message, status, code) {
            super(message);
            this.name = 'CurriculumRequestError';
            this.status = status;
            this.code = code || '';
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
                response.status, payload.error_code
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
                response.status, payload.error_code
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

    function createModal(element, options) {
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
            element.setAttribute('aria-hidden', 'false');
            document.body.classList.add('curriculum-modal-open');
            requestAnimationFrame(function () {
                focusableElements()[0]?.focus();
            });
        }

        function close() {
            element.hidden = true;
            element.setAttribute('aria-hidden', 'true');
            if (!document.querySelector('.curriculum-modal:not([hidden])')) {
                document.body.classList.remove('curriculum-modal-open');
            }
            // Record lists may be refreshed while a View dialog is open.
            const focusTarget = previousFocus?.isConnected === false ? options?.restoreFocus?.() : previousFocus;
            focusTarget?.focus();
        }

        element.querySelectorAll('[data-modal-close]').forEach(function (button) {
            button.addEventListener('click', close);
        });
        element.setAttribute('aria-hidden', 'true');
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

    function createAutocomplete(options) {
        const input = options?.input;
        const valueInput = options?.valueInput;
        const list = options?.list;
        if (!input || !valueInput || !list || typeof options.search !== 'function') return null;

        const root = options.root || input.parentElement;
        const clearButton = root?.querySelector('[data-autocomplete-clear]');
        const status = options.status || null;
        const delay = Number.isFinite(options.delay) ? options.delay : 300;
        const minimumLength = Number.isFinite(options.minimumLength) ? options.minimumLength : 1;
        let timer = null;
        let requestSequence = 0;
        let items = [];
        let activeIndex = -1;
        let selectedLabel = '';
        let pendingDirection = 0;

        function setExpanded(expanded) {
            list.hidden = !expanded;
            input.setAttribute('aria-expanded', String(expanded));
            if (!expanded) input.removeAttribute('aria-activedescendant');
        }

        function showState(message, kind) {
            items = [];
            activeIndex = -1;
            input.removeAttribute('aria-activedescendant');
            list.replaceChildren();
            const row = document.createElement('div');
            row.className = 'record-autocomplete-state' + (kind ? ' ' + kind : '');
            row.setAttribute('role', 'status');
            row.textContent = message;
            list.appendChild(row);
            if (kind === 'error') {
                const retry = document.createElement('button');
                retry.type = 'button'; retry.className = 'record-autocomplete-option';
                retry.textContent = 'Retry search';
                retry.addEventListener('click', () => { if (!input.disabled) scheduleSearch(true); });
                list.appendChild(retry);
            }
            setExpanded(true);
        }

        function format(item) {
            const result = options.format ? options.format(item) : { label: String(item.label ?? '') };
            return typeof result === 'string' ? { label: result } : result;
        }

        function select(item, notify) {
            const id = String(options.getId ? options.getId(item) : item.id ?? '');
            if (!id) return;
            requestSequence += 1;
            clearTimeout(timer);
            const display = format(item);
            selectedLabel = String(display.label ?? '');
            valueInput.value = id;
            input.value = selectedLabel;
            input.classList.add('has-selection');
            input.setCustomValidity('');
            if (status) status.textContent = options.selectedText || 'Selected from matching records.';
            if (clearButton) clearButton.hidden = false;
            setExpanded(false);
            if (notify !== false && options.onSelect) options.onSelect(item, id);
            if (!input.closest('#csmsCimfPanel')) document.dispatchEvent(new CustomEvent('csms:context-changed'));
        }

        function clear(notify) {
            requestSequence += 1;
            clearTimeout(timer);
            selectedLabel = '';
            valueInput.value = '';
            input.value = '';
            input.classList.remove('has-selection');
            input.setCustomValidity('');
            if (clearButton) clearButton.hidden = true;
            list.replaceChildren();
            setExpanded(false);
            if (status) status.textContent = options.helpText || '';
            if (notify !== false && options.onClear) options.onClear();
            if (!input.closest('#csmsCimfPanel')) document.dispatchEvent(new CustomEvent('csms:context-changed'));
        }

        function setSelected(item, notify) {
            select(item, notify);
        }

        function close() {
            requestSequence += 1;
            clearTimeout(timer);
            setExpanded(false);
        }

        function activate(index) {
            if (!items.length) return;
            activeIndex = (index + items.length) % items.length;
            list.querySelectorAll('[role="option"]').forEach(function (option, optionIndex) {
                const active = optionIndex === activeIndex;
                option.setAttribute('aria-selected', active ? 'true' : 'false');
                option.classList.toggle('active', active);
                if (active) {
                    input.setAttribute('aria-activedescendant', option.id);
                    option.scrollIntoView({ block: 'nearest' });
                }
            });
        }

        function render(results) {
            items = Array.isArray(results) ? results : [];
            activeIndex = -1;
            list.replaceChildren();
            if (!items.length) {
                pendingDirection = 0;
                showState(options.noResultsText || 'No matching records found.', 'empty');
                return;
            }
            items.forEach(function (item, index) {
                const display = format(item);
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'record-autocomplete-option';
                button.id = list.id + '-option-' + index;
                button.setAttribute('role', 'option');
                button.setAttribute('aria-selected', 'false');
                button.dataset.optionIndex = String(index);
                if (display.code) {
                    const code = document.createElement('span');
                    code.className = 'record-autocomplete-code';
                    code.textContent = String(display.code);
                    button.appendChild(code);
                }
                const label = document.createElement('span');
                label.className = 'record-autocomplete-label';
                label.textContent = String(display.title || display.label || '');
                button.appendChild(label);
                if (display.meta) {
                    const meta = document.createElement('span');
                    meta.className = 'record-autocomplete-meta';
                    meta.textContent = String(display.meta);
                    button.appendChild(meta);
                }
                list.appendChild(button);
            });
            setExpanded(true);
            if (pendingDirection) {
                const direction = pendingDirection;
                pendingDirection = 0;
                activate(direction > 0 ? 0 : items.length - 1);
            }
        }

        async function search(query, sequence) {
            if (query.length < minimumLength) return;
            showState(options.loadingText || 'Searching…', 'loading');
            try {
                const results = await options.search(query);
                if (sequence !== requestSequence) return;
                const ranked = searchRecords(Array.isArray(results) ? results : [], query, options.searchFields || (item => {
                    if ('subject_code' in item) return [item.subject_code, item.subject_title];
                    if ('reference_code' in item) return [item.reference_code, item.title, item.issuing_body];
                    if ('version_label' in item) return [item.version_label, item.effective_academic_year];
                    if ('curriculum_code' in item) return [item.curriculum_code, item.curriculum_name];
                    if ('program_code' in item) return [item.program_code, item.program_name];
                    const display = format(item);
                    return display.code || display.title ? [display.code, display.title] : [display.label];
                }));
                const contextEntity = ranked[0] && !('subject_code' in ranked[0]) && ['program_code','curriculum_code','version_label'].some(key => key in ranked[0]);
                render(ranked.slice(0, options.maxSuggestions || (contextEntity ? 3 : 5)));
            } catch (error) {
                if (sequence !== requestSequence) return;
                showState(error.status === 403 ? 'You do not have permission to view these records.' :
                    (error.status === 401 ? 'Your session has ended. Sign in again.' :
                    (error.message || options.errorText || 'Matching records could not be loaded. Retry the search.')), 'error');
                if (options.onError) options.onError(error);
            }
        }

        function scheduleSearch(immediate) {
            clearTimeout(timer);
            const query = input.value.trim();
            const sequence = ++requestSequence;
            if (query.length < minimumLength) {
                pendingDirection = 0;
                items = [];
                list.replaceChildren();
                setExpanded(false);
                return;
            }
            if (immediate) {
                search(query, sequence);
            } else {
                showState(options.loadingText || 'Searching…', 'loading');
                timer = setTimeout(function () { search(query, sequence); }, delay);
            }
        }

        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', list.id);
        input.setAttribute('autocomplete', 'off');
        if (options.placeholder) input.placeholder = options.placeholder;
        list.setAttribute('role', 'listbox');
        if (clearButton) clearButton.hidden = true;

        input.addEventListener('input', function () {
            pendingDirection = 0;
            if (input.value !== selectedLabel) {
                const hadSelection = Boolean(valueInput.value || selectedLabel);
                selectedLabel = '';
                valueInput.value = '';
                input.classList.remove('has-selection');
                if (clearButton) clearButton.hidden = true;
                if (status) status.textContent = options.helpText || '';
                if (hadSelection && options.onClear) options.onClear();
            }
            input.setCustomValidity('');
            scheduleSearch(false);
        });
        input.addEventListener('focus', function () {
            if (options.showSuggestionsOnFocus && !input.disabled && !valueInput.value) scheduleSearch(true);
        });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                if (!items.length) {
                    pendingDirection = 1;
                    scheduleSearch(true);
                }
                else activate(activeIndex + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                if (!items.length) {
                    pendingDirection = -1;
                    scheduleSearch(true);
                }
                else activate(activeIndex <= 0 ? items.length - 1 : activeIndex - 1);
            } else if (event.key === 'Enter' && !list.hidden && activeIndex >= 0) {
                event.preventDefault();
                event.stopPropagation();
                select(items[activeIndex]);
            } else if (event.key === 'Escape' && !list.hidden) {
                event.preventDefault();
                event.stopPropagation();
                close();
            } else if (event.key === 'Tab') {
                close();
            }
        });
        list.addEventListener('mousedown', function (event) {
            if (event.target.closest('[data-option-index]')) event.preventDefault();
        });
        list.addEventListener('click', function (event) {
            const option = event.target.closest('[data-option-index]');
            if (option) select(items[Number(option.dataset.optionIndex)]);
        });
        clearButton?.addEventListener('click', function () {
            clear();
            input.focus();
        });
        document.addEventListener('click', function (event) {
            if (!root?.contains(event.target)) close();
        });

        return {
            clear,
            setSelected,
            hasSelection: function () {
                return Boolean(valueInput.value && selectedLabel && input.value === selectedLabel);
            },
            getSelectedId: function () { return valueInput.value; },
            getSelectedLabel: function () { return selectedLabel; },
            refresh: function () { scheduleSearch(true); },
            close
        };
    }

    function createCascadingAutocomplete(config) {
        const levels = Array.isArray(config?.levels) ? config.levels : [];
        // A cascade is one component: when a role omits its form, none of its
        // dependent inputs should be initialized or updated.
        if (levels.length < 2 || levels.some(level =>
            !level?.input || !level.valueInput || !level.list || typeof level.search !== 'function')) return null;
        const selectors = [];

        function syncAvailability(index) {
            levels.forEach(function (level, levelIndex) {
                if (levelIndex === 0) return;
                const enabled = selectors.slice(0, levelIndex).every(function (selector) {
                    return selector && selector.hasSelection();
                });
                level.input.disabled = !enabled;
                level.input.placeholder = enabled
                    ? (level.placeholder || '')
                    : (level.disabledPlaceholder || 'Select the previous item first');
            });
        }

        function clearFollowing(index) {
            for (let childIndex = index + 1; childIndex < selectors.length; childIndex += 1) {
                selectors[childIndex]?.clear(false);
            }
            syncAvailability(index);
        }

        function showNextSuggestions(index) {
            const nextIndex = index + 1;
            const nextLevel = levels[nextIndex];
            const currentSelector = selectors[index];
            const nextSelector = selectors[nextIndex];
            if (nextLevel?.showSuggestionsOnEnable && currentSelector && nextSelector && !nextSelector.hasSelection() && !nextLevel.input.disabled) {
                const parentId = currentSelector.getSelectedId();
                // Wait for the selection click to finish bubbling: the outside-click
                // listener would otherwise immediately dismiss the next dropdown.
                setTimeout(function () {
                    if (currentSelector.getSelectedId() !== parentId || nextLevel.input.disabled || nextSelector.hasSelection()) return;
                    const searchOnFocus = nextLevel.focusOnEnable && nextLevel.showSuggestionsOnFocus && document.activeElement !== nextLevel.input;
                    if (nextLevel.focusOnEnable) nextLevel.input.focus();
                    if (!searchOnFocus) nextSelector.refresh();
                }, 0);
            }
        }

        levels.forEach(function (level, index) {
            const selector = createAutocomplete({
                ...level,
                maxSuggestions: 3,
                onSelect: function (item, id) {
                    clearFollowing(index);
                    syncAvailability(index);
                    if (level.onSelect) level.onSelect(item, id);
                    if (config.onChange) config.onChange(index, item, id, 'select');
                    document.dispatchEvent(new CustomEvent('csms:curriculum-context-selection', { detail: { index, item, action: 'select' } }));
                    showNextSuggestions(index);
                },
                onClear: function () {
                    clearFollowing(index);
                    syncAvailability(index);
                    if (level.onClear) level.onClear();
                    if (config.onChange) config.onChange(index, null, '', 'clear');
                    document.dispatchEvent(new CustomEvent('csms:curriculum-context-selection', { detail: { index, item: null, action: 'clear' } }));
                }
            });
            selectors.push(selector);
        });

        syncAvailability(-1);
        document.dispatchEvent(new CustomEvent('csms:curriculum-context-cascade-ready', { detail: { levels, selectors, restoreSavedContext: config.restoreSavedContext !== false } }));
        return {
            get: function (index) { return selectors[index] || null; },
            setContext: function (items) {
                if (!Array.isArray(items) || items.length !== selectors.length) throw new Error('Incomplete curriculum context.');
                selectors.forEach((selector,index) => selector.setSelected(items[index],false));
                syncAvailability(-1);
                items.forEach((item,index) => document.dispatchEvent(new CustomEvent('csms:curriculum-context-selection',{detail:{index,item,action:'select'}})));
            },
            clear: function (index) {
                const start = Number.isInteger(index) ? index : 0;
                selectors[start]?.clear(false);
                clearFollowing(start);
                if (config.onChange) config.onChange(start, null, '', 'reset');
            },
            hasSelection: function () {
                return selectors.length === levels.length && selectors.every(function (selector) {
                    return selector && selector.hasSelection();
                });
            },
            selectors: selectors
        };
    }

    // Presentation labels and existing foundation palette; never an authorization decision.
    function statusLabel(status) {
        return ({not_started:'Not started', awaiting_review:'Awaiting academic review', under_review:'Under review',
            proposed:'Proposed revision', reference:'Reference', unclassified:'Unclassified', editable:'Editable',
            protected:'Protected', read_only:'Read only', not_assessed:'Not assessed', incomplete:'Incomplete',
            mapped:'Mapped', unmapped:'Unmapped', school_curriculum:'Curriculum source'})[status]
            || String(status || 'Unknown').replaceAll('_', ' ');
    }
    function statusBadge(status, label) {
        const normalized = String(status || '').toLowerCase();
        const positive = ['active','required','editable','approved','mapped','planned','complete'];
        const pending = ['draft','under_review','awaiting_review','incomplete','returned'];
        const css = normalized === 'reference' ? 'read-only-pill'
            : positive.includes(normalized) ? 'status-active'
            : pending.includes(normalized) ? 'status-draft'
            : normalized === 'archived' ? 'status-archived' : 'status-inactive';
        return '<span class="status-badge ' + css + '">' + escapeHtml(label || statusLabel(normalized)) + '</span>';
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

    function setCountState(element, state) {
        if (!element) return;
        element.textContent = state === 'loading' ? 'Loading...' : 'Not loaded';
        element.dataset.loadState = state;
        element.setAttribute('aria-live','polite');
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

    // New CSMS features must reuse established system components and visual patterns. Do not introduce independent styling when an equivalent system pattern exists.
    function normalizeSearch(value) { return String(value ?? '').trim().replace(/\s+/gu, ' ').toLowerCase(); }
    function searchRank(fields, query) {
        const q = normalizeSearch(query);
        if (!q) return 0;
        return Math.min(...fields.map(value => {
            const field = normalizeSearch(value);
            if (field === q) return 0;
            if (field.startsWith(q)) return 1;
            const wordStart = field.indexOf(q);
            if (wordStart >= 0 && (wordStart === 0 || !/[\p{L}\p{N}]/u.test(field[wordStart - 1]))) return 2;
            return field.includes(q) ? 3 : Infinity;
        }));
    }
    function searchRecords(records, query, fields) {
        const compare = (a, b) => a < b ? -1 : a > b ? 1 : 0;
        return records.map(item => ({ item, values: fields(item).map(normalizeSearch), rank: searchRank(fields(item), query) }))
            .filter(row => Number.isFinite(row.rank))
            .sort((a, b) => a.rank - b.rank || searchRank([a.values[0]], query) - searchRank([b.values[0]], query) || a.values.reduce((result, value, index) => result || compare(value, b.values[index]), 0) || compare(String(a.item.id ?? a.item.subject_id ?? ''), String(b.item.id ?? b.item.subject_id ?? '')))
            .map(row => row.item);
    }
    window.CurriculumUI = {
        normalizeSearch, searchRank, searchRecords, statusLabel,
        escapeHtml,
        fetchJson,
        postForm,
        showToast,
        showFormError,
        clearFormError,
        setFormSubmitting,
        createModal,
        createAutocomplete,
        createCascadingAutocomplete,
        statusBadge,
        formatDate,
        formatUnits,
        setCount, setCountState,
        renderPagination,
        debounce
    };
}());
