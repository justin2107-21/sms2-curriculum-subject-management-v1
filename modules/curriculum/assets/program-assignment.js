(function () {
    'use strict';
    // Academic Strand/Course Assignment: one program-first page. Pick a Program / Strand, see every subject that is
    // assigned to it or placed in one of its curricula, and assign or unassign from the table.
    const ui = window.CurriculumUI, root = document.getElementById('programAssignmentWorkspace');
    if (!ui || !root) return;
    const $ = id => document.getElementById(id), esc = ui.escapeHtml;
    const api = root.dataset.assignmentApi, builderPage = root.dataset.builderPage, manage = root.dataset.canManage === '1';
    const FILTERS = ['assigned', 'placed', 'placed_not_assigned', 'assigned_not_placed'];
    const state = { context: null, records: [], page: 1, pages: 1, total: 0, filter: '', selected: [], pendingRemove: null };
    const dialogs = { assign: manage ? ui.createModal($('assignModal')) : null, remove: manage ? ui.createModal($('removeModal')) : null, details: ui.createModal($('detailsModal')) };
    window.CSMSPageCIMFContext = () => ({ view: 'program', academic_program_id: $('contextId').value });

    const inactiveTag = r => r.status && r.status !== 'active' ? ' · Inactive' : '';
    const programLabel = r => `${r.program_code} — ${r.program_name}`;
    const subjectLabel = r => `${r.subject_code} — ${r.subject_title}`;
    const termText = p => [p.year_level, p.semester].filter(Boolean).join(' · ') || 'Term not specified';

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

    // ---- Program picker (selection is kept in the address: ?program_id=) ----
    const contextPicker = ui.createAutocomplete({ input: $('contextSearch'), valueInput: $('contextId'), list: $('contextOptions'), minimumLength: 0, maxSuggestions: 3, showSuggestionsOnFocus: true,
        search: async q => (await ui.fetchJson(api, { action: 'search', type: 'program', search: q })).records, getId: r => r.id,
        format: r => ({ label: programLabel(r), code: r.program_code, title: r.program_name, meta: (r.program_type || '') + inactiveTag(r) }),
        onSelect: record => { state.filter = ''; $('assignmentSearch').value = ''; syncCountButtons(); loadContext(record.id); }, onClear: clearContext, errorText: 'Programs could not be searched.' });
    let assignPicker = null;
    if (manage) {
        assignPicker = ui.createAutocomplete({ input: $('assignSearch'), valueInput: $('assignId'), list: $('assignOptions'), minimumLength: 0, maxSuggestions: 3, showSuggestionsOnFocus: true,
            // available=1: the server returns only active subjects that are not yet assigned to this program.
            search: async q => (await ui.fetchJson(api, { action: 'search', type: 'subject', search: q, context_type: 'program', context_id: $('contextId').value, available: '1' })).records.filter(r => !state.selected.some(x => Number(x.id) === Number(r.id))),
            getId: r => r.id, format: r => ({ label: subjectLabel(r), code: r.subject_code, title: r.subject_title, meta: `${r.units} units` }),
            onSelect: r => { if (!state.selected.some(x => Number(x.id) === Number(r.id))) state.selected.push(r); renderSelected(); assignPicker.clear(false); $('assignSearch').focus(); },
            onClear: () => {}, errorText: 'Matching subjects could not be loaded.' });
    }

    function syncUrl() {
        const url = new URL(window.location.href), id = $('contextId').value;
        if (id) url.searchParams.set('program_id', id); else url.searchParams.delete('program_id');
        if (state.filter) url.searchParams.set('filter', state.filter); else url.searchParams.delete('filter');
        window.history.replaceState(null, '', url);
    }

    // ---- Rendering ----
    const chip = (kind, text) => `<span class="csms-chip csms-chip--${kind}">${esc(text)}</span>`;
    function showPanel(panel) { // 'none' | 'empty' | 'list'
        $('assignEmpty').hidden = panel !== 'empty';
        $('assignNoSubjects').hidden = panel !== 'none';
        $('assignmentList').hidden = panel !== 'list';
    }
    function renderCoverage() {
        const r = state.context, box = $('assignmentCoverage');
        box.hidden = !r;
        if (!r) return;
        $('coverageCode').textContent = r.program_code;
        $('coverageName').textContent = r.program_name;
        $('coverageType').textContent = r.program_type || '—';
        const status = $('coverageStatus');
        status.className = 'csms-chip csms-chip--' + (r.status === 'active' ? 'active' : 'inactive');
        status.textContent = r.status === 'active' ? 'Active' : 'Inactive';
        $('countAssigned').textContent = r.assigned_count; $('countPlaced').textContent = r.placed_count;
        $('countPna').textContent = r.placed_not_assigned; $('countAnp').textContent = r.assigned_not_placed;
        syncCountButtons();
    }
    function syncCountButtons() {
        root.querySelectorAll('.assignment-count').forEach(b => { const on = b.dataset.filter === state.filter; b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
        $('coverageFilter').value = state.filter;
    }
    function placedIn(r) {
        if (!r.placements.length) return '';
        const first = r.placements[0], more = r.placements.length - 1;
        return `${esc(first.curriculum_code)} · ${esc(termText(first))}` + (more ? ` <span class="csms-ctable__muted">+${more}</span>` : '');
    }
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

    // ---- Loading ----
    async function loadContext(id) {
        state.page = 1; state.context = null; state.records = [];
        renderCoverage(); syncAssignState(); $('cimfReviewButton').disabled = true; syncUrl();
        try {
            state.context = (await ui.fetchJson(api, { action: 'context', type: 'program', id })).record;
            renderCoverage(); syncAssignState(); $('cimfReviewButton').disabled = false;
            await loadRows();
        } catch (error) {
            state.context = null; renderCoverage(); syncAssignState(); showPanel('empty');
            ui.showToast(error.message || 'The selected program could not be loaded.', 'error');
        }
    }
    function clearContext() {
        state.context = null; state.records = []; state.page = 1; state.filter = '';
        $('assignmentSearch').value = ''; renderCoverage(); syncAssignState(); $('cimfReviewButton').disabled = true; showPanel('empty'); syncUrl();
    }
    async function loadRows() {
        if (!state.context) return;
        if (!hasSubjects()) { showPanel('none'); return; }
        showPanel('list');
        try {
            const payload = await ui.fetchJson(api, { action: 'list', type: 'program', id: $('contextId').value, filter: state.filter, search: $('assignmentSearch').value.trim(), page: state.page, per_page: $('perPage').value });
            state.records = payload.assignments; state.page = payload.pagination.page; state.pages = payload.pagination.total_pages; state.total = payload.pagination.total_records;
            renderRows();
        } catch (error) { ui.showToast(error.message || 'Subjects could not be loaded.', 'error'); }
    }
    async function refresh() {
        if (!state.context) return;
        state.context = (await ui.fetchJson(api, { action: 'context', type: 'program', id: $('contextId').value })).record;
        renderCoverage(); syncAssignState(); await loadRows();
    }
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
        data.set('action', 'assign'); data.set('view', 'program'); data.set('context_id', $('contextId').value); data.set('record_ids', JSON.stringify(ids));
        return ui.postForm(api, data);
    }
    async function assignOne(row) {
        const blocked = assignBlockReason() || (row.subject_status !== 'active' ? 'Inactive subjects cannot be newly assigned.' : '');
        if (blocked) { ui.showToast(blocked, 'error'); return; }
        try { const result = await postAssign([row.subject_id]); ui.showToast(result.message, 'success'); await refresh(); }
        catch (error) { ui.showToast(error.message || 'The subject could not be assigned.', 'error'); }
    }

    // ---- Unassign: the server refuses while the subject is placed; the page says so before asking ----
    function openRemove(row) {
        if (!manage) { ui.showToast('You do not have permission to unassign subjects.', 'error'); return; }
        if (!row.assignment_id) { ui.showToast('This subject is not assigned to the program.', 'error'); return; }
        if (row.placed) { ui.showToast(`${row.subject_code} is placed in a curriculum of this program, so it cannot be unassigned. Remove it from the curriculum plan in the Curriculum Builder first.`, 'error'); return; }
        state.pendingRemove = row.assignment_id; ui.clearFormError($('removeModal'));
        $('removeMessage').textContent = `Unassign ${subjectLabel(row)} from ${programLabel(state.context)}?`;
        dialogs.remove.open();
    }

    // ---- Where used (replaces the old subject-centric tab) ----
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
    function openBuilder(row) {
        const first = row.placements[0], q = new URLSearchParams({ program_id: $('contextId').value });
        if (first) { q.set('curriculum_id', first.curriculum_id); q.set('version_id', first.version_id); }
        window.location.assign(`${builderPage}?${q}`);
    }
    function showCimfReview(assignmentId) {
        if (!state.context) { ui.showToast('Select a program first.', 'error'); return; }
        if (!window.CSMSCIMF) { ui.showToast('CIMF review is not available on this page.', 'error'); return; }
        window.CSMSCIMF.open('review', assignmentId ? { assignment_id: assignmentId } : {});
    }

    // ---- Row menu and events ----
    const rowById = id => state.records.find(r => Number(r.subject_id) === Number(id));
    const rowMenu = window.CsmsMenu ? window.CsmsMenu.create($('assignmentRowMenu'), { esc, onSelect: (action, data) => {
        const row = rowById(data.subject); if (!row) { ui.showToast('That subject is no longer in the list. Reload the page.', 'error'); return; }
        if (action === 'assign') assignOne(row); else if (action === 'unassign') openRemove(row);
        else if (action === 'usage') openUsage(row.subject_id); else if (action === 'builder') openBuilder(row);
        else if (action === 'review') showCimfReview(row.assignment_id);
    } }) : null;
    function menuItems(row) {
        const data = { subject: row.subject_id }, items = [];
        if (manage) items.push(row.assigned ? { action: 'unassign', label: 'Unassign', danger: true, data } : { action: 'assign', label: 'Assign', data });
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

    // ---- Start: restore the program and filter from the address ----
    async function start() {
        showPanel('empty'); renderCoverage(); syncAssignState();
        const params = new URLSearchParams(window.location.search), id = params.get('program_id');
        if (FILTERS.includes(params.get('filter') || '')) state.filter = params.get('filter');
        syncCountButtons();
        if (!/^[1-9][0-9]*$/.test(id || '')) return;
        try {
            const record = (await ui.fetchJson(api, { action: 'context', type: 'program', id })).record;
            contextPicker.setSelected(record, false);
            await loadContext(record.id);
        } catch (error) { state.filter = ''; syncUrl(); ui.showToast(error.message || 'The program in the address could not be loaded.', 'error'); }
    }
    start();
}());
