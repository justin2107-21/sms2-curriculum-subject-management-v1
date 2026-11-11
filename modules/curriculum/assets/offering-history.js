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
        const payload = await ui.fetchJson(api, { action: 'version_subjects', version_id: state.context.version_id, search: $('historySearch').value.trim(), page: state.page, per_page: $('historyPerPage').value });
        if (ticket !== state.request) return;
        state.rows.clear();
        ui.setCount($('historyCount'), Number(payload.pagination.total_records));
        $('historyRows').innerHTML = payload.subjects.map((row, i) => { state.rows.set(String(i), row); return rowHtml(row, i); }).join('');
        $('historyTable').closest('.csms-page-table').hidden = !payload.subjects.length;
        $('historyNoMatch').hidden = payload.subjects.length > 0;
        $('historyNoMatch').querySelector('strong').textContent = $('historySearch').value.trim() ? 'No subjects match' : 'No subjects in this version';
        $('historyNoMatch').querySelector('span').textContent = $('historySearch').value.trim() ? 'Change the search.' : 'This curriculum version has no subject placements yet.';
        ui.renderPagination($('historyPagination'), payload.pagination, page => { state.page = page; loadRows().catch(e => ui.showToast(e.message, 'error')); });
    }
    const noSnapshotChip = '<span class="csms-chip csms-chip--draft">No snapshot, current values shown</span>';
    const plural = (n, one) => n + ' ' + one + (Number(n) === 1 ? '' : 's');
    function rowHtml(row, i) {
        return '<tr class="csms-row-click" tabindex="0" data-row="' + i + '" aria-haspopup="dialog" aria-label="View version history of ' + esc(row.subject_code || 'subject') + '">' +
            '<td class="csms-ctable__code">' + esc(row.subject_code || '—') + '</td>' +
            '<td>' + esc(row.subject_title || '') + (Number(row.snapshot_complete) === 1 ? '' : ' ' + noSnapshotChip) + '</td>' +
            '<td class="csms-ctable__num">' + esc(ui.formatUnits(row.units)) + '</td>' +
            '<td>' + esc(termText(row)) + '</td><td>' + esc(plural(row.version_count, 'version')) + '</td>' +
            '<td>' + statusChip(state.context.version_status) + '</td></tr>';
    }

    // ---- Dialog: the subject's version timeline across the program ----
    // Current vs historical: Active = "Current", Archived = "Historical". Values are "captured" only from a complete snapshot.
    const standing = status => status === 'active' ? ' <span class="csms-chip csms-chip--type">Current</span>' : status === 'archived' ? ' <span class="csms-chip csms-chip--type">Historical</span>' : '';
    function timelineRow(t) {
        const captured = Number(t.snapshot_complete) === 1
            ? '<br><span class="subtle-text">Captured ' + esc(ui.formatDate(t.snapshot_captured_at)) + ': ' + esc([t.subject_code, t.subject_title].filter(Boolean).join(' · ')) + ' · ' + esc(plural(ui.formatUnits(t.units), 'unit')) + '</span>'
            : '<br>' + noSnapshotChip;
        return '<tr' + (t.is_selected ? ' aria-current="true"' : '') + '><td>' + esc(t.curriculum_code === t.curriculum_name || !t.curriculum_name ? t.curriculum_code : t.curriculum_code + ' — ' + t.curriculum_name) + '</td>' +
            '<td><strong>' + esc(t.version_label) + '</strong>' + (t.is_selected ? ' <span class="csms-chip csms-chip--type">Selected</span>' : '') +
            '<br><span class="subtle-text">' + (t.effective_academic_year ? 'Effective ' + esc(t.effective_academic_year) : 'Effectivity not recorded') + '</span>' + captured + '</td>' +
            '<td>' + statusChip(t.version_status) + standing(t.version_status) + '</td><td>' + esc(termText(t)) + '</td>' +
            '<td>' + (Number(t.is_required) === 1 ? 'Required' : 'Optional') + '</td><td>' + esc(ui.formatDate(t.placement_created_at)) + '</td></tr>';
    }
    async function showDetails(i) {
        const row = state.rows.get(String(i)); if (!row) return;
        try {
            const data = await ui.fetchJson(api, { action: 'timeline', placement_id: row.placement_id });
            const s = data.subject, p = data.program;
            $('historyDetailsContext').textContent = (s.current_code || '—') + ' · ' + (s.current_title || '');
            $('historyDetailsBody').innerHTML = '<dt>Subject Master (current)</dt><dd class="csms-ctable__code">' + esc((s.current_code || '—') + ' — ' + (s.current_title || '—') + (s.current_units != null ? ' · ' + plural(ui.formatUnits(s.current_units), 'unit') : '')) + '</dd>' +
                '<dt>Program / Strand</dt><dd>' + esc(p.code === p.name ? p.code : p.code + ' — ' + p.name) + '</dd>' +
                '<dt>Appears in</dt><dd>' + esc(plural(data.timeline.length, 'version')) + '</dd>';
            $('historyTimelineRows').innerHTML = data.timeline.map(timelineRow).join('');
            $('historyMappingLink').href = 'subject_mapping.php?version_id=' + Number(state.context.version_id) + '&subject_id=' + Number(row.subject_id);
            // Same comparison screen Curriculum Builder opens; it only works for a revised version with a recorded original.
            const c = state.context, link = $('historyCompareLink');
            link.hidden = !(c && c.version_role === 'proposed' && c.baseline_version_id);
            if (!link.hidden) link.href = 'curriculum_history_view.php?curriculum_id=' + Number(c.curriculum_id) + '&version_b_id=' + Number(c.version_id);
            details.open();
        } catch (error) { ui.showToast(error.message || 'Version history could not be loaded.', 'error'); }
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
