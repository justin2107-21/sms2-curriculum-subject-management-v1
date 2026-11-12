(function () {
    'use strict';

    // Grade Weighting Setup: one page under the shared Program / Curriculum / Version bar. The main view is a server-side paged table of the
    // version's subjects (filters, sort, paging and counts all come from the server); one dialog edits a subject's components or shows them
    // read-only. The server decides whether a version can be edited and checks every rule again on save (total exactly 100.00).
    // Nothing here is a default: weights are whatever the institution enters. CIMF only explains.
    const root = document.getElementById('gradeWeightingWorkspace');
    const ui = window.CurriculumUI;
    if (!root || !ui) return;

    const $ = id => document.getElementById(id);
    const esc = ui.escapeHtml;
    const api = root.dataset.weightingApi;
    const canManage = root.dataset.canManage === '1';
    const SEARCH_MIN = 2;
    const STATUS = {
        configured: ['Configured', 'csms-chip--approved'],
        incomplete: ['Incomplete', 'csms-chip--under_review'],
        not_configured: ['Not configured', 'csms-chip--draft']
    };
    const state = { context: null, rows: new Map(), request: 0, abort: null, timer: null, loaded: false, panelLocked: false,
        view: { search: '', status: '', year_level: '', semester: '', sort: 'term', direction: 'asc', page: 1, per_page: 20 },
        dialog: null };

    // ---------- shared context bar (same engine and lookups as Offering History and Electives) ----------
    const cascade = ui.createCascadingAutocomplete({ levels: ['program', 'curriculum', 'version'].map((key, index) => ({
        input: $(key + 'Search'), valueInput: $(key + 'Id'), list: $(key + 'Options'), minimumLength: 0, showSuggestionsOnFocus: true, showSuggestionsOnEnable: true,
        placeholder: index === 0 ? 'Search program code or name' : 'Search ' + key, disabledPlaceholder: index === 1 ? 'Choose program first' : 'Choose curriculum first',
        search: async query => (await ui.fetchJson(api, { action: 'context_options', level: key, ...ctxIds(), search: query })).options,
        format: item => ({ label: [item.code, item.title].filter(Boolean).join(' — '), code: item.code, title: item.title || '' })
    })), onChange: () => scheduleContext() });
    function ctxIds() {
        const out = {};
        [['program_id', 0], ['curriculum_id', 1]].forEach(([key, i]) => { const id = cascade?.get(i)?.getSelectedId?.(); if (id) out[key] = id; });
        return out;
    }
    const programId = () => cascade.get(0).getSelectedId() || '';
    const curriculumId = () => cascade.get(1).getSelectedId() || '';
    const versionId = () => cascade.get(2).getSelectedId() || '';

    const PANELS = { loading: 'weightingLoading', noPrograms: 'weightingNoPrograms', empty: 'weightingEmpty', panel: 'weightingPanel' };
    function showPanel(name) { Object.entries(PANELS).forEach(([key, id]) => { $(id).hidden = key !== name; }); }
    const plural = (n, one, many) => n + ' ' + (n === 1 ? one : many);
    const statusChip = status => { const [label, css] = STATUS[status] || STATUS.not_configured; return '<span class="csms-chip ' + css + '">' + esc(label) + '</span>'; };
    const termText = row => [row.year_level, row.semester].filter(Boolean).join(' · ') || '—';

    // ---------- list state in the URL (the shared context script owns program_id, curriculum_id and version_id) ----------
    const URL_KEYS = { q: 'search', status: 'status', year: 'year_level', semester: 'semester', sort: 'sort', dir: 'direction', page: 'page', per_page: 'per_page' };
    const DEFAULTS = { sort: 'term', direction: 'asc', page: 1, per_page: 20 };
    const SORTS = ['code', 'title', 'term', 'components', 'total', 'status'];
    function readUrl() {
        const params = new URLSearchParams(window.location.search), view = state.view;
        Object.entries(URL_KEYS).forEach(([key, field]) => { view[field] = params.has(key) ? params.get(key) : (DEFAULTS[field] ?? ''); });
        view.page = Math.max(1, parseInt(view.page, 10) || 1);
        view.per_page = [10, 20, 50, 100].includes(parseInt(view.per_page, 10)) ? parseInt(view.per_page, 10) : 20;
        if (!SORTS.includes(view.sort)) view.sort = 'term';
        if (!['asc', 'desc'].includes(view.direction)) view.direction = 'asc';
        if (!['', 'configured', 'incomplete', 'not_configured'].includes(view.status)) view.status = '';
    }
    function writeUrl(push) {
        const params = new URLSearchParams(window.location.search);
        Object.keys(URL_KEYS).forEach(key => params.delete(key));
        Object.entries(URL_KEYS).forEach(([key, field]) => {
            const value = state.view[field];
            if (value !== '' && value !== null && value !== undefined && String(value) !== String(DEFAULTS[field] ?? '')) params.set(key, String(value));
        });
        const query = params.toString(), url = window.location.pathname + (query ? '?' + query : '');
        if (url !== window.location.pathname + window.location.search) window.history[push ? 'pushState' : 'replaceState'](null, '', url);
    }
    function syncControls() {
        const view = state.view;
        $('weightingSearch').value = view.search;
        $('weightingStatus').value = view.status;
        $('weightingYear').value = view.year_level;
        $('weightingSemester').value = view.semester;
        $('weightingPerPage').value = String(view.per_page);
        document.querySelectorAll('.weighting-sort').forEach(button => {
            const active = button.dataset.sort === view.sort;
            button.closest('th').setAttribute('aria-sort', active ? (view.direction === 'asc' ? 'ascending' : 'descending') : 'none');
            button.innerHTML = esc(button.dataset.label) + (active ? ' <i class="fa-solid fa-arrow-' + (view.direction === 'asc' ? 'up' : 'down') + ' weighting-sort__icon" aria-hidden="true"></i>' : '');
        });
    }
    function activeFilters() {
        const view = state.view, list = [];
        if (view.search) list.push(['search', 'Search: ' + view.search]);
        if (view.status) list.push(['status', 'Status: ' + STATUS[view.status][0]]);
        if (view.year_level) list.push(['year_level', 'Year: ' + view.year_level]);
        if (view.semester) list.push(['semester', 'Semester: ' + view.semester]);
        return list;
    }
    function renderChips() {
        const filters = activeFilters(), chips = $('weightingChips');
        chips.hidden = !filters.length;
        chips.innerHTML = filters.map(([field, label]) => '<button type="button" class="csms-chip csms-chip--type weighting-chip" data-clear="' + esc(field) + '" aria-label="Remove filter ' + esc(label) + '">' + esc(label) + ' <i class="fa-solid fa-xmark" aria-hidden="true"></i></button>').join('') +
            (filters.length ? '<button type="button" class="csms-link" id="weightingClearAll">Clear all</button>' : '');
    }
    function clearAll() { Object.assign(state.view, { search: '', status: '', year_level: '', semester: '', page: 1 }); applyView(true); }

    // ---------- context and table ----------
    function scheduleContext() { clearTimeout(state.timer); state.timer = setTimeout(() => { Object.assign(state.view, { year_level: '', semester: '', page: 1 }); loadContext(); }, 250); }
    function fillSelect(select, values, label) {
        select.replaceChildren(new Option(label, ''));
        values.forEach(value => select.add(new Option(value, value)));
    }
    function renderNotice() {
        const context = state.context, notice = $('weightingVersionNotice'), lines = [];
        if (context && canManage && !context.editable) lines.push(context.reason_text);
        if (context && canManage && !context.editable_version_exists) lines.push('No editable version is available for your role.');
        notice.hidden = !lines.length;
        notice.innerHTML = lines.length ? '<i class="fa-solid fa-circle-info" aria-hidden="true"></i> ' + lines.map(esc).join(' ') : '';
    }
    async function loadContext() {
        const ticket = ++state.request, version = versionId();
        if (!version) { state.context = null; state.rows.clear(); showPanel('empty'); return; }
        try {
            const payload = await ui.fetchJson(api, { action: 'context', program_id: programId(), curriculum_id: curriculumId(), curriculum_version_id: version });
            if (ticket !== state.request) return;
            state.context = { ...payload.record, editable_version_exists: payload.editable_version_exists, coverage: payload.coverage };
            fillSelect($('weightingYear'), payload.years || [], 'All year levels');
            fillSelect($('weightingSemester'), payload.semesters || [], 'All semesters');
            showPanel('panel');
            renderNotice();
            applyView(false);
        } catch (error) {
            if (ticket !== state.request) return;
            state.context = null; showPanel('empty');
            ui.showToast(error.message || 'The selected version could not be loaded.', 'error');
        }
    }
    function showListState(which, message) {
        $('weightingNoMatch').hidden = which !== 'nomatch';
        $('weightingNoSubjects').hidden = which !== 'none';
        $('weightingError').hidden = which !== 'error';
        $('weightingTableWrap').hidden = which !== 'list';
        $('weightingPagination').hidden = which !== 'list';
        if (which === 'error') $('weightingErrorText').textContent = message || '';
    }
    function rowHtml(row) {
        const id = Number(row.id), menu = '<td class="csms-ctable__action"><button type="button" class="csms-rowmenu" data-menu="' + id + '" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ' + esc(row.subject_code) + '"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button></td>';
        return '<tr class="csms-row-click" tabindex="0" data-row="' + id + '" aria-haspopup="dialog" aria-label="Open the grade weighting of ' + esc(row.subject_code) + '">' +
            '<td class="csms-ctable__code">' + esc(row.subject_code) + '</td><td>' + esc(row.subject_title) + '</td><td>' + esc(termText(row)) + '</td>' +
            '<td class="csms-ctable__num">' + row.component_count + '</td><td class="csms-ctable__num">' + (row.component_count ? esc(row.total_weight) : '—') + '</td>' +
            '<td>' + statusChip(row.status) + '</td>' + menu + '</tr>';
    }
    async function loadRows() {
        const context = state.context;
        if (!context) return;
        const request = ++state.request;
        state.abort?.abort();
        const controller = state.abort = new AbortController();
        const view = state.view, url = new URL(api, window.location.href);
        const params = { action: 'overview', curriculum_version_id: context.version_id, page: view.page, per_page: view.per_page, search: view.search.length >= SEARCH_MIN ? view.search : '',
            status: view.status, year_level: view.year_level, semester: view.semester, sort: view.sort, direction: view.direction };
        Object.entries(params).forEach(([key, value]) => { if (value !== '' && value !== null && value !== undefined) url.searchParams.set(key, String(value)); });
        try {
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal });
            const payload = await response.json().catch(() => ({}));
            if (request !== state.request) return;
            if (!response.ok || !payload.success) throw new Error(payload.message || 'The request could not be completed.');
            state.loaded = true;
            state.rows.clear();
            const rows = payload.placements || [];
            rows.forEach(row => state.rows.set(Number(row.id), row));
            ui.setCount($('weightingCount'), Number(payload.pagination.total_records));
            if (Number(payload.pagination.page) !== view.page) { view.page = Number(payload.pagination.page); writeUrl(false); }
            if (!rows.length) { showListState(activeFilters().length ? 'nomatch' : 'none'); return; }
            $('weightingRows').innerHTML = rows.map(rowHtml).join('');
            ui.renderPagination($('weightingPagination'), payload.pagination, page => { view.page = page; applyView(true); });
            showListState('list');
        } catch (error) {
            if (error.name === 'AbortError' || request !== state.request) return;
            showListState('error', error.message);
        }
    }
    function applyView(push) { syncControls(); renderChips(); writeUrl(push); loadRows(); }
    function filtersChanged() {
        const view = state.view;
        view.search = $('weightingSearch').value.trim();
        view.status = $('weightingStatus').value;
        view.year_level = $('weightingYear').value;
        view.semester = $('weightingSemester').value;
        view.per_page = parseInt($('weightingPerPage').value, 10);
        view.page = 1;
        applyView(true);
    }

    // ---------- the editor dialog ----------
    const dialogEl = $('weightingDialog');
    const dialog = ui.createModal(dialogEl, { restoreFocus: () => $('weightingSearch') });
    const discardModal = ui.createModal($('discardWeightingModal'));
    const WEIGHT_PATTERN = /^\d{1,3}(?:\.\d{1,2})?$/;
    let rowSeq = 0;
    const hundredths = text => { const [whole, fraction = ''] = String(text).trim().split('.'); return Number(whole) * 100 + Number(fraction.padEnd(2, '0')); };
    const weightValid = text => WEIGHT_PATTERN.test(String(text).trim()) && hundredths(text) >= 1 && hundredths(text) <= 10000;
    const fixed = value => Math.floor(value / 100) + '.' + String(value % 100).padStart(2, '0');
    const normalizedWeight = text => weightValid(text) ? fixed(hundredths(text)) : String(text).trim();
    const draftKey = rows => JSON.stringify(rows.map(r => [r.id ? Number(r.id) : null, r.name.trim(), normalizedWeight(r.weight)]));
    function dlg() { return state.dialog; }
    const dirty = () => Boolean(dlg()) && dlg().editable && draftKey(dlg().rows) !== dlg().baseline;

    // Problems of the draft, in the order a person would fix them. Returns [] when it can be saved at 100.00.
    function problems() {
        const rows = dlg().rows, list = [], seen = new Set();
        if (!rows.length) list.push('Add at least one component.');
        rows.forEach((row, index) => {
            const name = row.name.trim(), n = index + 1;
            if (!name) list.push('Row ' + n + ': enter a component name.');
            else if (name.length > 150) list.push('Row ' + n + ': the name must be 150 characters or fewer.');
            else if (seen.has(name.toLowerCase())) list.push('Row ' + n + ': "' + name + '" is already used (names are not case sensitive).');
            seen.add(name.toLowerCase());
            if (!weightValid(row.weight)) list.push('Row ' + n + ': the weight must be greater than 0 and at most 100, with at most two decimals.');
        });
        return list;
    }
    function totalHundredths() { return dlg().rows.reduce((sum, row) => sum + (weightValid(row.weight) ? hundredths(row.weight) : 0), 0); }

    function updateTotals() {
        const d = dlg(), total = totalHundredths(), list = problems(), exact = total === 10000 && !list.length;
        const over = total > 10000;
        $('weightingTotalText').textContent = fixed(total) + '% total';
        const track = $('weightingProgress').parentElement;
        $('weightingProgress').style.width = Math.min(100, total / 100) + '%';
        $('weightingProgress').classList.toggle('is-over', over);
        track.setAttribute('aria-valuenow', String(Math.min(100, total / 100)));
        const chip = $('weightingTotalChip');
        chip.className = 'csms-chip ' + (exact ? 'csms-chip--approved' : over ? 'csms-chip--returned' : 'csms-chip--draft');
        chip.textContent = exact ? 'Complete' : over ? 'Over 100%' : 'Incomplete';
        const message = $('weightingTotalMessage');
        if (!d.editable) message.textContent = '';
        else if (over) message.textContent = fixed(total - 10000) + '% over the limit. Reduce a weight to save.';
        else if (total < 10000) message.textContent = fixed(10000 - total) + '% remaining. Weights must total exactly 100% to save.';
        else message.textContent = list.length ? list[0] : 'The weights total exactly 100%.';
        $('weightingUnsaved').hidden = !dirty();
        const save = $('weightingSave');
        if (save) save.disabled = !(d.editable && dirty() && exact);
        $('weightingCimf').disabled = !(window.CSMSCIMF && d.rows.some(row => row.name.trim() && weightValid(row.weight)));
        d.rows.forEach((row, index) => {
            const tr = document.querySelector('#weightingComponentRows tr[data-seq="' + row.seq + '"]');
            if (!tr) return;
            const name = tr.querySelector('input[data-field="name"]'), weight = tr.querySelector('input[data-field="weight"]');
            if (name) name.setAttribute('aria-invalid', String(Boolean(row.touchedName) && (!row.name.trim() || row.name.trim().length > 150 || d.rows.some((o, i) => i !== index && o.name.trim().toLowerCase() === row.name.trim().toLowerCase() && row.name.trim()))));
            if (weight) weight.setAttribute('aria-invalid', String(Boolean(row.touchedWeight) && !weightValid(row.weight)));
        });
    }
    function renderRows() {
        const d = dlg(), body = $('weightingComponentRows');
        if (!d.rows.length && !d.editable) { body.innerHTML = '<tr><td colspan="3" class="weighting-none">No components are configured for this subject.</td></tr>'; return; }
        body.innerHTML = d.rows.map((row, index) => d.editable
            ? '<tr data-seq="' + row.seq + '"><td><input type="text" class="weighting-input" data-field="name" maxlength="150" value="' + esc(row.name) + '" aria-label="Component name, row ' + (index + 1) + '" placeholder="Component name"/></td>' +
              '<td class="csms-ctable__num"><input type="text" inputmode="decimal" class="weighting-input weighting-input--weight" data-field="weight" value="' + esc(row.weight) + '" aria-label="Weight in percent, row ' + (index + 1) + '" placeholder="0.00"/></td>' +
              '<td class="csms-ctable__action"><button type="button" class="csms-rowmenu" data-remove="' + row.seq + '" aria-label="Remove component ' + (index + 1) + '"><i class="fa-solid fa-trash" aria-hidden="true"></i></button></td></tr>'
            : '<tr><td>' + esc(row.name) + '</td><td class="csms-ctable__num">' + esc(normalizedWeight(row.weight)) + '</td><td></td></tr>').join('');
    }
    function setDialogNotice(text) { const n = $('weightingDialogNotice'); n.hidden = !text; n.innerHTML = text ? '<i class="fa-solid fa-circle-info" aria-hidden="true"></i> ' + esc(text) : ''; }
    function showFormError(text) { const e = $('weightingFormError'); e.hidden = !text; e.textContent = text || ''; }

    async function openDialog(placementId, options = {}) {
        state.dialog = { placementId, loading: true, editable: false, rows: [], baseline: '[]', placement: null, scheme: null };
        $('weightingDialogTitle').textContent = 'Grade weighting';
        $('weightingDialogContext').textContent = '';
        $('weightingEditor').hidden = true;
        $('weightingDialogLoading').hidden = false;
        $('weightingRemoveConfirm').hidden = true;
        setDialogNotice('');
        showFormError('');
        ['weightingSave', 'weightingRemove'].forEach(id => { if ($(id)) $(id).hidden = true; });
        $('weightingCimf').disabled = true;
        $('weightingClose').textContent = 'Close';
        dialog.open();
        try {
            const payload = await ui.fetchJson(api, { action: 'scheme_get', curriculum_version_subject_id: placementId });
            if (!state.dialog || state.dialog.placementId !== placementId) return;
            const d = state.dialog, placement = payload.placement;
            d.loading = false;
            d.placement = placement;
            d.scheme = payload.scheme;
            d.editable = Boolean(payload.version?.editable);
            d.rows = (payload.components || []).map(c => ({ id: Number(c.id), seq: ++rowSeq, name: c.component_name, weight: fixed(hundredths(c.weight_percentage)) }));
            d.baseline = draftKey(d.rows);
            if (d.editable && !d.rows.length) d.rows.push({ id: null, seq: ++rowSeq, name: '', weight: '' });
            $('weightingDialogTitle').textContent = 'Grade weighting';
            $('weightingDialogContext').innerHTML = '<span class="csms-ctable__code">' + esc(placement.subject_code) + '</span> ' + esc(placement.subject_title) + ' · ' + esc(placement.version_label);
            $('weightingDialogLoading').hidden = true;
            $('weightingEditor').hidden = false;
            $('weightingAddComponent') && ($('weightingAddComponent').hidden = !d.editable);
            if (!d.editable) setDialogNotice(payload.version?.reason_text || 'This configuration is read-only.');
            if ($('weightingSave')) $('weightingSave').hidden = !d.editable;
            if ($('weightingRemove')) $('weightingRemove').hidden = !(d.editable && d.scheme);
            $('weightingClose').textContent = d.editable ? 'Cancel' : 'Close';
            renderRows();
            updateTotals();
            if (options.confirmRemove && d.editable && d.scheme) askRemove();
            else if (d.editable) dialogEl.querySelector('.weighting-input')?.focus();
        } catch (error) {
            dialog.close();
            state.dialog = null;
            ui.showToast(error.message || 'The configuration could not be loaded.', 'error');
        }
    }
    function addRow() {
        const d = dlg();
        if (!d || !d.editable) return;
        if (d.rows.length >= 100) { showFormError('A configuration can have at most 100 components.'); return; }
        const row = { id: null, seq: ++rowSeq, name: '', weight: '' };
        d.rows.push(row);
        renderRows(); updateTotals();
        $('weightingComponentRows').querySelector('tr[data-seq="' + row.seq + '"] input')?.focus();
    }

    // Closing: unsaved edits ask once (the same discard dialog as before), otherwise close at once.
    function requestClose() {
        if (dirty()) { discardModal.open(); return; }
        finishClose();
    }
    function finishClose() { state.dialog = null; document.body.classList.remove('weighting-cimf-beside'); dialog.close(); }
    $('weightingX').addEventListener('click', requestClose);
    $('weightingBackdrop').addEventListener('click', requestClose);
    $('weightingClose').addEventListener('click', requestClose);
    dialogEl.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !$('discardWeightingModal').hidden) return;
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); requestClose(); }
    }, true);
    $('confirmDiscardWeighting').addEventListener('click', () => { discardModal.close(); finishClose(); });

    function askRemove() {
        const d = dlg();
        $('weightingRemoveText').textContent = 'Remove all ' + plural(d.rows.filter(r => r.id).length || d.rows.length, 'component', 'components') + ' and the configuration of ' + d.placement.subject_code + '? The subject can then be removed from the version again. This cannot be undone.';
        $('weightingRemoveConfirm').hidden = false;
        $('weightingRemoveCancel').focus();
    }
    async function postAction(action, extra) {
        const d = dlg(), data = new FormData();
        data.set('action', action);
        data.set('curriculum_version_subject_id', d.placement.id);
        data.set('curriculum_version_id', d.placement.curriculum_version_id);
        Object.entries(extra || {}).forEach(([key, value]) => data.set(key, value));
        return ui.postForm(api, data);
    }
    async function save() {
        const d = dlg();
        if (!d || !d.editable || problems().length || totalHundredths() !== 10000 || !dirty()) return;
        showFormError('');
        $('weightingSave').disabled = true;
        try {
            const payload = await postAction('configuration_save', { components: JSON.stringify(d.rows.map((row, index) => ({ id: row.id || '', component_name: row.name.trim(), weight_percentage: normalizedWeight(row.weight), sequence_order: index + 1 }))) });
            ui.showToast(payload.message, 'success');
            finishClose();
            await refreshAfterChange();
        } catch (error) {
            showFormError(error.message);
            updateTotals();
        }
    }
    async function removeConfiguration() {
        const d = dlg();
        if (!d || !d.editable) return;
        $('weightingRemoveConfirmButton').disabled = true;
        try {
            const payload = await postAction('configuration_remove');
            ui.showToast(payload.message, 'success');
            finishClose();
            await refreshAfterChange();
        } catch (error) {
            $('weightingRemoveConfirm').hidden = true;
            showFormError(error.message);
        } finally { $('weightingRemoveConfirmButton').disabled = false; }
    }
    async function refreshAfterChange() {
        // coverage and the table both change; the context call is cheap and keeps the notice in step
        const context = state.context;
        if (context) {
            try { const payload = await ui.fetchJson(api, { action: 'context', program_id: context.program_id, curriculum_id: context.curriculum_id, curriculum_version_id: context.version_id }); state.context = { ...payload.record, editable_version_exists: payload.editable_version_exists, coverage: payload.coverage }; renderNotice(); } catch (error) { /* the table reload below reports problems */ }
        }
        await loadRows();
    }

    // ---------- CIMF: advice beside the dialog; never written into the fields ----------
    window.CSMSPageCIMFContext = () => {
        const d = dlg(), context = state.context;
        const out = { academic_program_id: context?.program_id || programId(), curriculum_id: context?.curriculum_id || curriculumId(), curriculum_version_id: context?.version_id || versionId(), curriculum_version_subject_id: d?.placement?.id || '' };
        // The unsaved draft goes along only when every row is valid, so CIMF explains what is on screen; otherwise it reads the stored configuration.
        if (d && d.rows.length && !problems().length) out.components = d.rows.map(row => ({ component_name: row.name.trim(), weight_percentage: normalizedWeight(row.weight) }));
        return out;
    };
    const cimfPanel = $('csmsCimfPanel');
    if (cimfPanel) new MutationObserver(() => { if (cimfPanel.hidden) document.body.classList.remove('weighting-cimf-beside'); }).observe(cimfPanel, { attributes: true, attributeFilter: ['hidden'] });
    $('weightingCimf').addEventListener('click', () => {
        if (!window.CSMSCIMF || !dlg()) return;
        document.body.classList.add('weighting-cimf-beside');
        window.CSMSCIMF.updateContext?.();
        window.CSMSCIMF.open('review');
    });

    // ---------- events ----------
    $('weightingComponentRows').addEventListener('input', event => {
        const input = event.target.closest('input[data-field]');
        if (!input || !dlg()) return;
        const row = dlg().rows.find(r => String(r.seq) === input.closest('tr').dataset.seq);
        if (!row) return;
        if (input.dataset.field === 'name') { row.name = input.value; row.touchedName = true; } else { row.weight = input.value; row.touchedWeight = true; }
        showFormError('');
        updateTotals();
    });
    $('weightingComponentRows').addEventListener('keydown', event => {
        if (event.key === 'Enter' && event.target.matches('input[data-field]')) { event.preventDefault(); if (event.target.dataset.field === 'weight') { addRowIfLast(event.target); } else event.target.closest('tr').querySelector('input[data-field="weight"]').focus(); }
    });
    function addRowIfLast(input) {
        const rows = dlg().rows, last = rows[rows.length - 1];
        if (String(last.seq) === input.closest('tr').dataset.seq && last.name.trim() && weightValid(last.weight) && totalHundredths() < 10000) addRow();
    }
    $('weightingComponentRows').addEventListener('click', event => {
        const button = event.target.closest('[data-remove]');
        if (!button || !dlg()) return;
        const d = dlg(), index = d.rows.findIndex(r => String(r.seq) === button.dataset.remove);
        if (index < 0) return;
        d.rows.splice(index, 1);
        if (!d.rows.length) d.rows.push({ id: null, seq: ++rowSeq, name: '', weight: '' });
        renderRows(); updateTotals();
        (document.querySelector('#weightingComponentRows input') || $('weightingAddComponent'))?.focus();
    });
    $('weightingAddComponent')?.addEventListener('click', addRow);
    $('weightingSave')?.addEventListener('click', save);
    $('weightingRemove')?.addEventListener('click', askRemove);
    $('weightingRemoveCancel').addEventListener('click', () => { $('weightingRemoveConfirm').hidden = true; $('weightingRemove')?.focus(); });
    $('weightingRemoveConfirmButton').addEventListener('click', removeConfiguration);

    const searchLater = ui.debounce(() => { if ($('weightingSearch').value.trim().length === 1) return; filtersChanged(); }, 300);
    $('weightingSearch').addEventListener('input', searchLater);
    ['weightingStatus', 'weightingYear', 'weightingSemester', 'weightingPerPage'].forEach(id => $(id).addEventListener('change', filtersChanged));
    $('weightingChips').addEventListener('click', event => {
        if (event.target.closest('#weightingClearAll')) { clearAll(); return; }
        const chip = event.target.closest('[data-clear]');
        if (!chip) return;
        state.view[chip.dataset.clear] = '';
        state.view.page = 1;
        applyView(true);
    });
    $('weightingClearFilters').addEventListener('click', clearAll);
    $('weightingRetry').addEventListener('click', () => loadRows());
    document.querySelectorAll('.weighting-sort').forEach(button => {
        button.dataset.label = button.textContent.trim();
        button.addEventListener('click', () => {
            const view = state.view, column = button.dataset.sort;
            view.direction = view.sort === column ? (view.direction === 'asc' ? 'desc' : 'asc') : (['components', 'total'].includes(column) ? 'desc' : 'asc');
            view.sort = column;
            view.page = 1;
            applyView(true);
        });
    });
    window.addEventListener('popstate', () => { readUrl(); if (state.context) applyView(false); });

    const rowMenu = window.CsmsMenu ? window.CsmsMenu.create($('weightingRowMenu'), {
        esc,
        onSelect(action, data) {
            const row = state.rows.get(Number(data.row));
            if (!row) return;
            if (action === 'open') openDialog(row.id);
            else if (action === 'remove') openDialog(row.id, { confirmRemove: true });
        }
    }) : null;
    function menuItems(row) {
        const editable = canManage && state.context?.editable;
        const items = [{ action: 'open', label: editable ? 'Edit weights' : 'View weights', data: { row: row.id } }];
        if (editable && row.component_count > 0) items.push({ action: 'remove', label: 'Remove configuration', danger: true, data: { row: row.id } });
        return items;
    }
    $('weightingRows').addEventListener('click', event => {
        const menuButton = event.target.closest('[data-menu]');
        if (menuButton) { const row = state.rows.get(Number(menuButton.dataset.menu)); if (row) rowMenu?.open(menuButton, menuItems(row)); return; }
        const tr = event.target.closest('[data-row]');
        if (tr) openDialog(Number(tr.dataset.row));
    });
    $('weightingRows').addEventListener('keydown', event => {
        if (event.target.matches?.('[data-row]') && ['Enter', ' '].includes(event.key)) { event.preventDefault(); openDialog(Number(event.target.dataset.row)); }
    });

    // ---------- start: the shared context script restores the saved or default selection and drives the bar; we follow it ----------
    async function start() {
        readUrl();
        showPanel('loading');
        try {
            if (!(await ui.fetchJson(api, { action: 'context_options', level: 'program', search: '' })).options.length) { state.panelLocked = true; showPanel('noPrograms'); return; }
        } catch (error) { ui.showToast(error.message || 'Programs could not be loaded.', 'error'); }
        setTimeout(() => { if (!state.panelLocked && !versionId() && !state.context) showPanel('empty'); }, 4000);
    }
    start();
}());
