(function () {
    'use strict';
    // Academic Strand/Course Assignment: one program-first page under the shared Program / Curriculum / Version context bar.
    // Assign / Unassign are PROGRAM-level. The selected curriculum version only scopes the Placed column and the Placed counts.
    const ui = window.CurriculumUI, root = document.getElementById('programAssignmentWorkspace');
    if (!ui || !root) return;
    const $ = id => document.getElementById(id), esc = ui.escapeHtml;
    const api = root.dataset.assignmentApi, builderPage = root.dataset.builderPage, manage = root.dataset.canManage === '1';
    const FILTERS = ['assigned', 'placed', 'placed_not_assigned', 'assigned_not_placed'];
    const state = { context: null, records: [], page: 1, pages: 1, total: 0, filter: '', selected: [], pendingRemove: null, request: 0, timer: null, panelLocked: false };
    const dialogs = { reconcile: manage ? ui.createModal($('reconcileModal')) : null, assign: manage ? ui.createModal($('assignModal')) : null, remove: manage ? ui.createModal($('removeModal')) : null, details: ui.createModal($('detailsModal')) };

    const inactiveTag = r => r.status && r.status !== 'active' ? ' · Inactive' : '';
    const programLabel = r => `${r.program_code} — ${r.program_name}`;
    const subjectLabel = r => `${r.subject_code} — ${r.subject_title}`;
    const termText = p => [p.year_level, p.semester].filter(Boolean).join(' · ') || 'Term not specified';

    // ---- Shared context bar (same engine and lookups as Electives Manager; the selection is shared through js/curriculum-context.js) ----
    const cascade = ui.createCascadingAutocomplete({ levels: ['program', 'curriculum', 'version'].map((key, index) => ({
        input: $(key + 'Search'), valueInput: $(key + 'Id'), list: $(key + 'Options'), minimumLength: 0, showSuggestionsOnFocus: true, showSuggestionsOnEnable: true,
        placeholder: index === 0 ? 'Search program code or name' : 'Search ' + key, disabledPlaceholder: index === 1 ? 'Choose program first' : 'Choose curriculum first',
        search: async query => (await ui.fetchJson(api, { action: 'context_options', level: key, ...ctx(), search: query })).options,
        format: item => ({ label: [item.code, item.title].filter(Boolean).join(' — '), code: item.code, title: item.title || '' })
    })), onChange: () => scheduleLoad() });
    function ctx() {
        const out = {};
        [['program_id', 0], ['curriculum_id', 1]].forEach(([key, i]) => { const id = cascade?.get(i)?.getSelectedId?.(); if (id) out[key] = id; });
        return out;
    }
    const programId = () => cascade.get(0).getSelectedId() || '';
    const versionId = () => cascade.get(2).getSelectedId() || '';

    // ---- Why an action is blocked: shown beside the button and as a toast when the action is still attempted ----
    const assignBlockReason = () => {
        if (!manage) return 'You do not have permission to assign.';
        if (!state.context) return 'Select a program first.';
        if (state.context.status !== 'active') return 'Inactive programs cannot receive new assignments.';
        return '';
    };
    function syncAssignState() {
        const button = $('assignButton'), info = $('assignReason');
        if (!button) return;
        const reason = assignBlockReason();
        button.disabled = reason !== '';
        if (info) { info.hidden = reason === ''; $('assignReasonTip').textContent = reason; }
    }

    let assignPicker = null;
    if (manage) {
        assignPicker = ui.createAutocomplete({ input: $('assignSearch'), valueInput: $('assignId'), list: $('assignOptions'), minimumLength: 0, maxSuggestions: 3, showSuggestionsOnFocus: true,
            // available=1: the server returns only active subjects that are not yet assigned to this program.
            search: async q => (await ui.fetchJson(api, { action: 'search', type: 'subject', search: q, context_type: 'program', context_id: programId(), available: '1' })).records.filter(r => !state.selected.some(x => Number(x.id) === Number(r.id))),
            getId: r => r.id, format: r => ({ label: subjectLabel(r), code: r.subject_code, title: r.subject_title, meta: `${r.units} units` }),
            onSelect: r => { if (!state.selected.some(x => Number(x.id) === Number(r.id))) state.selected.push(r); renderSelected(); assignPicker.clear(false); $('assignSearch').focus(); },
            onClear: () => {}, errorText: 'Matching subjects could not be loaded.' });
    }

    // Only the filter lives in the address here; program_id, curriculum_id and version_id are kept by the shared context script.
    function syncUrl() {
        const url = new URL(window.location.href);
        if (state.filter) url.searchParams.set('filter', state.filter); else url.searchParams.delete('filter');
        window.history.replaceState(window.history.state, '', url);
    }

    // ---- Rendering ----
    const chip = (kind, text) => `<span class="csms-chip csms-chip--${kind}">${esc(text)}</span>`;
    function showPanel(panel) { // 'loading' | 'empty' | 'noPrograms' | 'none' | 'list'
        $('assignLoading').hidden = panel !== 'loading';
        $('assignEmpty').hidden = panel !== 'empty';
        $('assignNoPrograms').hidden = panel !== 'noPrograms';
        $('assignNoSubjects').hidden = panel !== 'none';
        $('assignmentList').hidden = panel !== 'list';
    }
    const hasVersion = () => Boolean(state.context && state.context.version_id);
    function renderCoverage() {
        const r = state.context, box = $('assignmentCoverage');
        box.hidden = !r;
        if (!r) return;
        $('coverageType').textContent = r.program_type || '—';
        const status = $('coverageStatus');
        status.className = 'csms-chip csms-chip--' + (r.status === 'active' ? 'active' : 'inactive');
        status.textContent = r.status === 'active' ? 'Active' : 'Inactive';
        const placed = hasVersion();
        $('coverageScope').textContent = placed ? 'Placed counts use the selected curriculum version.' : 'No curriculum version selected: Placed counts are not shown.';
        $('countAssigned').textContent = r.assigned_count;
        $('countPlaced').textContent = placed ? r.placed_count : '—';
        $('countPna').textContent = placed ? r.placed_not_assigned : '—';
        $('countAnp').textContent = placed ? r.assigned_not_placed : '—';
        syncCountButtons(); syncReconcileState();
    }
    // "Assign placed subjects": the label carries how many active subjects it would assign; a disabled button says why.
    const reconcileBlockReason = () => {
        const r = state.context;
        if (!manage) return 'You do not have permission to assign.';
        if (!r) return 'Select a program first.';
        if (!r.version_id) return 'Select a curriculum version to see the subjects placed in it.';
        if (r.status !== 'active') return 'Inactive programs cannot receive new assignments.';
        if (r.assignable_count === 0) return r.placed_not_assigned > 0 ? 'The placed subjects that are not assigned are all inactive, and inactive subjects are never assigned.' : 'Every subject placed in this version is already assigned.';
        return '';
    };
    function syncReconcileState() {
        const button = $('reconcileButton'); if (!button) return;
        const reason = reconcileBlockReason(), n = state.context?.version_id ? state.context.assignable_count : 0;
        button.textContent = n ? `Assign ${n} placed subject${n === 1 ? '' : 's'}` : 'Assign placed subjects';
        button.disabled = reason !== '';
        $('reconcileReason').hidden = reason === ''; $('reconcileReasonTip').textContent = reason;
    }
    function syncCountButtons() {
        root.querySelectorAll('.assignment-count').forEach(b => { const on = b.dataset.filter === state.filter; b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
        $('coverageFilter').value = state.filter;
    }
    // Year and semester in the selected version (a subject is placed once per version).
    const placedIn = r => r.placements.length ? esc(termText(r.placements[0])) : '';
    function renderRows() {
        const rows = state.records;
        $('assignmentRows').innerHTML = rows.map(r => `<tr class="csms-row-click" tabindex="0" data-row="${r.subject_id}" aria-haspopup="dialog">`
            + `<td class="csms-ctable__code">${esc(r.subject_code)}</td><td>${esc(r.subject_title)}${r.subject_status !== 'active' ? ' ' + chip('inactive', 'Inactive') : ''}</td>`
            + `<td class="csms-ctable__num">${esc(r.units)}</td><td>${r.assigned ? chip('active', 'Assigned') : chip('warning', 'Not assigned')}</td><td>${placedIn(r)}</td>`
            + `<td class="csms-ctable__action"><button type="button" class="csms-rowmenu" data-menu="${r.subject_id}" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ${esc(r.subject_code)}"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button></td></tr>`).join('');
        $('assignmentRows').closest('.table-scroll').hidden = rows.length === 0;
        $('assignNoMatch').hidden = rows.length !== 0;
        $('assignmentCount').textContent = `${state.total} record${state.total === 1 ? '' : 's'}`;
        $('assignmentPagination').innerHTML = state.pages > 1 ? `<button type="button" class="button-muted" data-page="${state.page - 1}" ${state.page <= 1 ? 'disabled' : ''}>Previous</button><span>Page ${state.page} of ${state.pages}</span><button type="button" class="button-muted" data-page="${state.page + 1}" ${state.page >= state.pages ? 'disabled' : ''}>Next</button>` : '';
        $('assignmentPagination').hidden = state.pages <= 1;
    }
    const hasSubjects = () => state.context && (state.context.assigned_count + state.context.placed_not_assigned) > 0;

    // ---- Loading: the context bar changes in bursts (program, curriculum, version), so wait for it to settle ----
    function scheduleLoad() {
        clearTimeout(state.timer);
        state.timer = setTimeout(() => { state.page = 1; loadContext(); }, 250);
    }
    async function loadContext() {
        const ticket = ++state.request, id = programId(), version = versionId();
        if (!id) {
            state.context = null; state.records = []; renderCoverage(); syncAssignState(); $('cimfReviewButton').disabled = true; showPanel('empty'); return;
        }
        try {
            const record = (await ui.fetchJson(api, { action: 'context', type: 'program', id, ...(version ? { version_id: version } : {}) })).record;
            if (ticket !== state.request) return;
            state.context = record; renderCoverage(); syncAssignState(); $('cimfReviewButton').disabled = false;
            await loadRows(ticket);
        } catch (error) {
            if (ticket !== state.request) return;
            state.context = null; renderCoverage(); syncAssignState(); showPanel('empty');
            ui.showToast(error.message || 'The selected program could not be loaded.', 'error');
        }
    }
    async function loadRows(ticket = state.request) {
        if (!state.context) return;
        if (!hasSubjects()) { showPanel('none'); return; }
        showPanel('list');
        try {
            const payload = await ui.fetchJson(api, { action: 'list', type: 'program', id: programId(), ...(versionId() ? { version_id: versionId() } : {}), filter: state.filter, search: $('assignmentSearch').value.trim(), page: state.page, per_page: $('perPage').value });
            if (ticket !== state.request) return;
            state.records = payload.assignments; state.page = payload.pagination.page; state.pages = payload.pagination.total_pages; state.total = payload.pagination.total_records;
            renderRows();
        } catch (error) { if (ticket === state.request) ui.showToast(error.message || 'Subjects could not be loaded.', 'error'); }
    }
    async function refresh() { await loadContext(); }
    function setFilter(value) {
        state.filter = FILTERS.includes(value) ? value : '';
        state.page = 1; syncCountButtons(); syncUrl(); loadRows();
    }

    // ---- Assign (dialog, bulk) ----
    function openAssign() {
        const blocked = assignBlockReason();
        if (blocked) { ui.showToast(blocked, 'error'); return; }
        state.selected = []; renderSelected(); ui.clearFormError($('assignForm'));
        $('assignModalContext').textContent = programLabel(state.context);
        $('assignSearch').value = ''; $('assignId').value = ''; dialogs.assign.open(); $('assignSearch').focus();
    }
    function renderSelected() {
        $('selectedTitle').textContent = `Selected (${state.selected.length})`;
        $('selectedRecords').innerHTML = state.selected.map((r, i) => `<li><span><strong>${esc(r.subject_code)}</strong> — ${esc(r.subject_title)}</span><button type="button" class="row-action-button" data-remove-selected="${i}">Remove</button></li>`).join('') || '<li class="subtle-text">No subjects selected.</li>';
        $('assignForm').querySelector('[type="submit"]').disabled = state.selected.length === 0;
    }
    async function postAssign(ids) {
        const data = new FormData();
        data.set('action', 'assign'); data.set('view', 'program'); data.set('context_id', programId()); data.set('record_ids', JSON.stringify(ids));
        return ui.postForm(api, data);
    }
    // Reason an Assign is unavailable for one row (shown in the menu, and as a toast if it is still attempted).
    const rowAssignBlock = row => assignBlockReason() || (row.subject_status !== 'active' ? 'Inactive subjects cannot be newly assigned.' : '');
    async function assignOne(row) {
        const blocked = rowAssignBlock(row);
        if (blocked) { ui.showToast(blocked, 'error'); return; }
        try { const result = await postAssign([row.subject_id]); ui.showToast(result.message, 'success'); await refresh(); }
        catch (error) { ui.showToast(error.message || 'The subject could not be assigned.', 'error'); }
    }

    // ---- Assign all placed: preview, then one server action (single transaction, idempotent, program-level only) ----
    const reconcileIds = () => ({ program_id: programId(), curriculum_id: cascade.get(1).getSelectedId(), version_id: versionId() });
    const reviewUi = manage && window.CsmsReview ? window.CsmsReview.create($('reconcileChecks'), { esc, onAction: () => {} }) : null;
    let preview = null;
    async function openReconcile() {
        const blocked = reconcileBlockReason();
        if (blocked) { ui.showToast(blocked, 'error'); return; }
        try {
            preview = (await ui.fetchJson(api, { action: 'reconcile_preview', ...reconcileIds() })).preview;
        } catch (error) { ui.showToast(error.message || 'The preview could not be loaded.', 'error'); return; }
        ui.clearFormError($('reconcileModal'));
        $('reconcileContext').textContent = `${programLabel(state.context)} · ${cascade.get(2).getSelectedLabel() || 'selected version'}`;
        const columns = [{ header: 'Code', code: true, cell: r => esc(r.subject_code) }, { header: 'Title', cell: r => esc(r.subject_title) }, { header: 'Units', cell: r => esc(r.units) }, { header: 'Year / Semester', cell: r => esc([r.year_level, r.semester].filter(Boolean).join(' · ') || '—') }];
        reviewUi.reset();
        reviewUi.render([{ title: 'Placed in the selected version, not yet assigned', note: '', checks: [
            { key: 'reconcile-assign', label: 'Will be assigned', hint: 'Active subjects only.', rows: preview.assignable, columns },
            { key: 'reconcile-skip', label: 'Skipped, inactive', hint: 'Inactive subjects are never assigned.', severity: 'warning', rows: preview.skipped_inactive, columns } ] }]);
        // Open the lists so the whole preview is visible without extra clicks.
        $('reconcileChecks').querySelectorAll('[data-review-issue]').forEach(head => { if (!head.closest('.csms-check').classList.contains('is-open')) head.click(); });
        const n = preview.assignable.length;
        $('confirmReconcile').textContent = n ? `Assign ${n} subject${n === 1 ? '' : 's'}` : 'Nothing to assign';
        $('confirmReconcile').disabled = n === 0 || preview.program_status !== 'active';
        dialogs.reconcile.open();
    }
    async function confirmReconcile() {
        const button = $('confirmReconcile'); button.disabled = true;
        const data = new FormData(); data.set('action', 'reconcile'); Object.entries(reconcileIds()).forEach(([k, v]) => data.set(k, v));
        try { const result = await ui.postForm(api, data); dialogs.reconcile.close(); ui.showToast(result.message, 'success'); await refresh(); }
        catch (error) { ui.showFormError($('reconcileModal'), error.message || 'The subjects could not be assigned. Nothing was changed.'); button.disabled = false; }
    }

    // ---- Unassign: the server decides and names the curricula and versions that block it ----
    function openRemove(row) {
        if (!manage) { ui.showToast('You do not have permission to unassign subjects.', 'error'); return; }
        if (!row.assignment_id) { ui.showToast('This subject is not assigned to the program.', 'error'); return; }
        state.pendingRemove = row.assignment_id; ui.clearFormError($('removeModal'));
        $('removeMessage').textContent = `Unassign ${subjectLabel(row)} from ${programLabel(state.context)}?`;
        dialogs.remove.open();
    }

    // ---- Where used: every program and every curriculum version, whatever is selected in the bar ----
    function usageMarkup(u) {
        const assigned = u.assigned.length ? u.assigned.map(a => `<li><strong>${esc(a.program_code)}</strong> — ${esc(a.program_name)}${a.program_status !== 'active' ? ' ' + chip('inactive', 'Inactive') : ''}</li>`).join('') : '<li class="subtle-text">Not assigned to any program or strand.</li>';
        const placed = u.placements.length ? u.placements.map(p => `<li><strong>${esc(p.program_code)}</strong> · ${esc(p.curriculum_code)} / ${esc(p.version_label)}<br><span class="subtle-text">${esc(termText(p))}</span></li>`).join('') : '<li class="subtle-text">Not placed in any curriculum.</li>';
        return `<dl class="csms-detail-list"><dt>Subject</dt><dd class="csms-ctable__code">${esc(u.subject.subject_code)}</dd><dt>Title</dt><dd>${esc(u.subject.subject_title)}</dd><dt>Units</dt><dd>${esc(u.subject.units)}</dd><dt>Status</dt><dd>${chip(u.subject.status === 'active' ? 'active' : 'inactive', u.subject.status === 'active' ? 'Active' : 'Inactive')}</dd></dl>`
            + `<h3>Assigned to programs / strands</h3><ul class="placement-list">${assigned}</ul><h3>Placed in curricula</h3><ul class="placement-list">${placed}</ul>`;
    }
    async function openUsage(subjectId) {
        try {
            const p = await ui.fetchJson(api, { action: 'usage', subject_id: subjectId });
            $('detailsBody').innerHTML = usageMarkup(p.usage); dialogs.details.open();
        } catch (error) { ui.showToast(error.message || 'Where used could not be loaded.', 'error'); }
    }
    function openBuilder() {
        const q = new URLSearchParams(ctx());
        if (versionId()) q.set('version_id', versionId());
        window.location.assign(`${builderPage}?${q}`);
    }
    function showCimfReview(assignmentId) {
        if (!state.context) { ui.showToast('Select a program first.', 'error'); return; }
        if (!window.CSMSCIMF) { ui.showToast('CIMF review is not available on this page.', 'error'); return; }
        window.CSMSCIMF.open('review', assignmentId ? { assignment_id: assignmentId } : {});
    }
    window.CSMSPageCIMFContext = () => ({ view: 'program', academic_program_id: programId() });

    // ---- Row menu (shared js/csms-menu.js) and events ----
    const rowById = id => state.records.find(r => Number(r.subject_id) === Number(id));
    const rowMenu = window.CsmsMenu ? window.CsmsMenu.create($('assignmentRowMenu'), { esc, onSelect: (action, data) => {
        const row = rowById(data.subject); if (!row) { ui.showToast('That subject is no longer in the list. Reload the page.', 'error'); return; }
        if (action === 'assign') assignOne(row); else if (action === 'unassign') openRemove(row);
        else if (action === 'usage') openUsage(row.subject_id); else if (action === 'builder') openBuilder();
        else if (action === 'review') showCimfReview(row.assignment_id);
    } }) : null;
    function menuItems(row) {
        const data = { subject: row.subject_id }, items = [];
        if (manage) {
            if (row.assigned) items.push({ action: 'unassign', label: 'Unassign', danger: true, data });
            else { const blocked = rowAssignBlock(row); items.push({ action: 'assign', label: 'Assign', data, ...(blocked ? { disabled: true, hint: blocked } : {}) }); }
        }
        items.push({ action: 'usage', label: 'Where used', data }, { action: 'builder', label: 'Open in Curriculum Builder', data });
        if (row.assigned) items.push({ action: 'review', label: 'CIMF review', data });
        return items;
    }
    $('assignmentRows').addEventListener('click', e => {
        const m = e.target.closest('[data-menu]');
        if (m) { const row = rowById(m.dataset.menu); if (row) rowMenu?.open(m, menuItems(row)); return; }
        const tr = e.target.closest('[data-row]'); if (tr) openUsage(tr.dataset.row);
    });
    $('assignmentRows').addEventListener('keydown', e => { if (e.target.matches?.('[data-row]') && ['Enter', ' '].includes(e.key)) { e.preventDefault(); openUsage(e.target.dataset.row); } });
    $('assignmentPagination').addEventListener('click', e => { const b = e.target.closest('[data-page]'); if (b && !b.disabled) { state.page = Number(b.dataset.page); loadRows(); } });
    root.querySelector('.assignment-coverage__counts').addEventListener('click', e => { const b = e.target.closest('[data-filter]'); if (b) setFilter(state.filter === b.dataset.filter ? '' : b.dataset.filter); });
    $('coverageFilter').addEventListener('change', () => setFilter($('coverageFilter').value));
    $('perPage').addEventListener('change', () => { state.page = 1; loadRows(); });
    $('assignmentSearch').addEventListener('input', ui.debounce(() => { state.page = 1; loadRows(); }, 250));
    $('cimfReviewButton').addEventListener('click', () => showCimfReview());
    if (manage) {
        $('assignButton').addEventListener('click', openAssign);
        $('reconcileButton').addEventListener('click', openReconcile);
        $('confirmReconcile').addEventListener('click', confirmReconcile);
        $('selectedRecords').addEventListener('click', e => { const b = e.target.closest('[data-remove-selected]'); if (b) { state.selected.splice(Number(b.dataset.removeSelected), 1); renderSelected(); } });
        $('assignForm').addEventListener('submit', async e => {
            e.preventDefault(); if (!state.selected.length) return;
            ui.setFormSubmitting(e.currentTarget, true, 'Assigning…');
            try { const result = await postAssign(state.selected.map(r => Number(r.id))); dialogs.assign.close(); ui.showToast(result.message, 'success'); await refresh(); }
            catch (error) { ui.showFormError(e.currentTarget, error.message || 'Assignments could not be saved.'); }
            finally { ui.setFormSubmitting(e.currentTarget, false); }
        });
        $('confirmRemove').addEventListener('click', async function () {
            if (!state.pendingRemove) return;
            const data = new FormData(); data.set('action', 'remove'); data.set('id', state.pendingRemove); this.disabled = true;
            try { const p = await ui.postForm(api, data); dialogs.remove.close(); ui.showToast(p.message, 'updated'); state.pendingRemove = null; await refresh(); }
            catch (error) { ui.showFormError($('removeModal'), error.message || 'The subject could not be unassigned.'); }
            finally { this.disabled = false; }
        });
    }

    // ---- Start: the shared context script restores the saved or default selection and drives the bar; we follow it ----
    async function start() {
        showPanel('loading'); renderCoverage(); syncAssignState();
        const wanted = new URLSearchParams(window.location.search).get('filter') || '';
        if (FILTERS.includes(wanted)) state.filter = wanted;
        syncCountButtons();
        try {
            if (!(await ui.fetchJson(api, { action: 'context_options', level: 'program', search: '' })).options.length) { state.panelLocked = true; showPanel('noPrograms'); return; }
        } catch (error) { ui.showToast(error.message || 'Programs could not be loaded.', 'error'); }
        // Nothing saved and nothing to default to (no version the user may open): leave the bar to the user.
        setTimeout(() => { if (!state.panelLocked && !programId() && !state.context) showPanel('empty'); }, 4000);
    }
    start();
}());
