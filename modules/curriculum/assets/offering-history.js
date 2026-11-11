(function () {
    'use strict';
    // Subject Offering History: one page under the shared Program / Curriculum / Version context bar.
    // GET-only. Everything shown is derived from stored curriculum placements; nothing here writes.
    const ui = window.CurriculumUI, root = document.getElementById('offeringHistoryWorkspace');
    if (!ui || !root) return;
    const $ = id => document.getElementById(id), esc = ui.escapeHtml, api = root.dataset.historyApi;
    const state = { context: null, rows: new Map(), page: 1, request: 0, timer: null, panelLocked: false };
    const details = ui.createModal($('historyDetailsModal'));

    // ---- Shared context bar (same engine and lookups as Assignment and Electives; the selection is shared through js/curriculum-context.js) ----
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
    const curriculumId = () => cascade.get(1).getSelectedId() || '';
    const versionId = () => cascade.get(2).getSelectedId() || '';

    const PANELS = { loading: 'historyLoading', noPrograms: 'historyNoPrograms', empty: 'historyEmpty', panel: 'historyPanel' };
    function showPanel(name) { Object.entries(PANELS).forEach(([key, id]) => { $(id).hidden = key !== name; }); }
    const statusChip = status => '<span class="csms-chip csms-chip--' + esc(status) + '">' + esc(ui.statusLabel ? ui.statusLabel(status) : String(status).charAt(0).toUpperCase() + String(status).slice(1)) + '</span>';
    const termText = r => [r.year_level, r.semester].filter(Boolean).join(' · ') || '—';

    // ---- Loading: the bar changes in bursts (program, curriculum, version), so wait for it to settle ----
    function scheduleLoad() { clearTimeout(state.timer); state.timer = setTimeout(() => { state.page = 1; loadContext(); }, 250); }
    async function loadContext() {
        const ticket = ++state.request, version = versionId();
        if (!version) { state.context = null; state.rows.clear(); showPanel('empty'); return; }
        try {
            const record = (await ui.fetchJson(api, { action: 'context', program_id: programId(), curriculum_id: curriculumId(), version_id: version })).record;
            if (ticket !== state.request) return;
            state.context = record; renderStrip(); showPanel('panel');
            await loadRows(ticket);
        } catch (error) {
            if (ticket !== state.request) return;
            state.context = null; showPanel('empty');
            ui.showToast(error.message || 'The selected version could not be loaded.', 'error');
        }
    }
    function renderStrip() {
        const c = state.context; if (!c) { $('historyVersionStrip').textContent = ''; return; }
        $('historyVersionStrip').innerHTML = statusChip(c.version_status) + ' ' + esc(c.curriculum_code + ' · ' + c.version_label) + (c.effective_academic_year ? ' · Effective ' + esc(c.effective_academic_year) : '');
    }
    async function loadRows(ticket = state.request) {
        if (!state.context) return;
        const payload = await ui.fetchJson(api, { action: 'list', curriculum_version_id: state.context.version_id, search: $('historySearch').value.trim(), page: state.page, per_page: $('historyPerPage').value });
        if (ticket !== state.request) return;
        state.rows.clear();
        ui.setCount($('historyCount'), Number(payload.pagination.total_records));
        $('historyRows').innerHTML = payload.history.map((row, i) => { state.rows.set(String(i), row); return rowHtml(row, i); }).join('');
        $('historyTable').closest('.csms-page-table').hidden = !payload.history.length;
        $('historyNoMatch').hidden = payload.history.length > 0;
        ui.renderPagination($('historyPagination'), payload.pagination, page => { state.page = page; loadRows().catch(e => ui.showToast(e.message, 'error')); });
    }
    function rowHtml(row, i) {
        const code = row.current_subject_code, title = row.current_subject_title;
        return '<tr class="csms-row-click" tabindex="0" data-row="' + i + '" aria-haspopup="dialog" aria-label="View history details for ' + esc(code || 'subject') + '">' +
            '<td class="csms-ctable__code">' + esc(code || '—') + '</td><td>' + esc(title || '') + '</td><td>' + esc(termText(row)) + '</td><td>' + statusChip(row.version_status) + '</td></tr>';
    }

    // ---- Details dialog ----
    async function showDetails(i) {
        const row = state.rows.get(String(i)); if (!row) return;
        try {
            const { record: r } = await ui.fetchJson(api, { action: 'details', placement_id: row.placement_id });
            $('historyDetailsContext').textContent = (r.current_subject_code || '—') + ' · ' + (r.current_subject_title || '');
            const entries = [['Curriculum', r.curriculum_code === r.curriculum_name ? r.curriculum_code : r.curriculum_code + ' — ' + r.curriculum_name], ['Curriculum Version', r.version_label], ['Year Level', r.year_level], ['Semester / Term', r.semester], ['Placement Type', Number(r.is_required) === 1 ? 'Required' : 'Optional'], ['Stored Date', ui.formatDate(r.placement_created_at)]];
            $('historyDetailsBody').innerHTML = entries.filter(([, v]) => v).map(([k, v]) => '<dt>' + esc(k) + '</dt><dd>' + esc(v) + '</dd>').join('');
            $('historyMappingLink').href = 'subject_mapping.php?version_id=' + Number(r.curriculum_version_id) + '&subject_id=' + Number(r.subject_id);
            details.open();
        } catch (error) { ui.showToast(error.message || 'History details could not be loaded.', 'error'); }
    }
    $('historyRows').addEventListener('click', e => { const tr = e.target.closest('tr[data-row]'); if (tr) showDetails(tr.dataset.row); });
    $('historyRows').addEventListener('keydown', e => { if (['Enter', ' '].includes(e.key) && e.target.matches?.('tr[data-row]')) { e.preventDefault(); e.target.click(); } });
    $('historySearch').addEventListener('input', ui.debounce(() => { state.page = 1; loadRows().catch(e => ui.showToast(e.message, 'error')); }, 300));
    $('historyPerPage').addEventListener('change', () => { state.page = 1; loadRows().catch(e => ui.showToast(e.message, 'error')); });

    // ---- Start: the shared context script restores the saved or default selection and drives the bar; we follow it ----
    async function start() {
        showPanel('loading');
        try {
            if (!(await ui.fetchJson(api, { action: 'context_options', level: 'program', search: '' })).options.length) { state.panelLocked = true; showPanel('noPrograms'); return; }
        } catch (error) { ui.showToast(error.message || 'Programs could not be loaded.', 'error'); }
        // Nothing saved and no version the user may open: stop showing "loading".
        setTimeout(() => { if (!state.panelLocked && !versionId() && !state.context) showPanel('empty'); }, 4000);
    }
    start();
}());
