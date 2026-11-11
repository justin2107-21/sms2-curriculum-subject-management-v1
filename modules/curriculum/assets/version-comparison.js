(function () {
    'use strict';
    // Compare Curriculum Versions. The version in the shared context bar is the REVISED version; its recorded original is
    // resolved by the server. Rendering is the existing CurriculumHistoryComparisonUI; no comparison logic lives here. GET-only.
    const ui = window.CurriculumUI, root = document.getElementById('versionComparisonWorkspace'), render = window.CurriculumHistoryComparisonUI;
    if (!ui || !root || !render) return;
    const $ = id => document.getElementById(id), esc = ui.escapeHtml, api = root.dataset.historyApi;
    const state = { rows: new Map(), data: null, request: 0, timer: null, panelLocked: false, highlight: new URLSearchParams(location.search).get('subject_id') || '' };
    const changeModal = ui.createModal($('comparisonDetailsModal'));

    // ---- Shared context bar (same engine and lookups as the other modules) ----
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

    const PANELS = { loading: 'compareLoading', noPrograms: 'compareNoPrograms', noVersion: 'compareNoVersion', noOriginal: 'compareNoOriginal', error: 'compareError', result: 'compareResult' };
    function showPanel(name, message) {
        Object.entries(PANELS).forEach(([key, id]) => { $(id).hidden = key !== name; });
        if (name === 'error') $('compareErrorText').textContent = message || '';
    }

    // ---- Loading ----
    function scheduleLoad() { clearTimeout(state.timer); state.timer = setTimeout(() => loadContext(1), 250); }
    async function loadContext(page) {
        const ticket = ++state.request, version = versionId();
        state.rows.clear(); state.data = null;
        if (!version) { showPanel('noVersion'); return; }
        try {
            const context = (await ui.fetchJson(api, { action: 'context', program_id: programId(), curriculum_id: curriculumId(), version_id: version })).record;
            if (ticket !== state.request) return;
            if (context.version_role !== 'proposed' || !context.baseline_version_id) { showPanel('noOriginal'); return; }
            await loadComparison(page, ticket);
        } catch (error) {
            if (ticket !== state.request) return;
            showPanel('error', error.message || 'The versions could not be compared.');
        }
    }
    async function loadComparison(page, ticket = state.request) {
        // The original is not sent: the endpoint resolves it from the revision's recorded baseline.
        const data = await ui.fetchJson(api, { action: 'compare_revision', curriculum_id: curriculumId(), version_b_id: versionId(), page, per_page: 20 });
        if (ticket !== state.request) return;
        state.data = data; state.rows.clear();
        showPanel('result');
        $('compareOriginal').innerHTML = 'Compared with: <strong>' + esc(data.curriculum.curriculum_code + ' / ' + data.version_a.version_label) + '</strong> (recorded original)';
        renderChips(data.summary);
        $('comparisonRows').innerHTML = data.comparison.map((record, i) => { state.rows.set(String(i), record); return render.row(record, i); }).join('');
        markHighlight();
        $('comparisonPagination').hidden = Number(data.pagination.total_pages) <= 1;
        ui.renderPagination($('comparisonPagination'), data.pagination, p => loadComparison(p).catch(e => showPanel('error', e.message)));
        $('comparisonEvidence').innerHTML = render.evidence(data); $('comparisonEvidence').hidden = false;
        $('comparisonStructures').innerHTML = render.structures(data); $('comparisonStructures').hidden = false;
    }
    function renderChips(s) {
        const items = [['added', 'Added'], ['removed', 'Removed'], ['moved', 'Moved'], ['changed', 'Changed'], ['academic_differences', 'Details changed'], ['unresolved', 'Unresolved'], ['unchanged', 'Unchanged']]
            .filter(([key]) => s[key] !== undefined && (Number(s[key]) > 0 || key === 'added' || key === 'removed' || key === 'moved'));
        $('compareChips').innerHTML = items.map(([key, label]) => '<span class="csms-chip csms-chip--' + (key === 'added' ? 'active' : key === 'removed' ? 'archived' : key === 'unchanged' ? 'draft' : key === 'unresolved' ? 'under_review' : 'type') + '">' + esc(label) + ': ' + Number(s[key]) + '</span>').join(' ');
    }
    // When opened from a subject dialog, mark that subject's row (and bring it into view on its page).
    function markHighlight() {
        if (!state.highlight) return;
        const rows = $('comparisonRows').querySelectorAll('tr[data-change-details]');
        rows.forEach(tr => {
            const record = state.rows.get(tr.dataset.changeDetails);
            if (record && String(record.subject_id) === state.highlight) { tr.setAttribute('aria-current', 'true'); tr.scrollIntoView({ block: 'nearest' }); }
        });
    }

    // ---- Row details ----
    function showChangeDetails(index) {
        const record = state.rows.get(String(index)); if (!record || !state.data) return;
        const label = record.before?.details_available ? record.before : record.after;
        $('comparisonDetailsContext').textContent = label?.subject_code ? label.subject_code + ' · ' + (label.subject_title || '') : 'Subject #' + record.subject_id;
        $('comparisonDetailsVersions').innerHTML = '<article><h3>Original</h3>' + render.values(record.before) + '</article><article><h3>Revised</h3>' + render.values(record.after) + '</article>';
        $('comparisonDetailsChange').textContent = record.categories.join(' / ');
        $('comparisonDetailsExplanation').textContent = (record.warning || '') + (record.revision_clone_snapshot ? ' ' + record.revision_clone_snapshot.notice : '');
        changeModal.open();
    }
    $('comparisonRows').addEventListener('click', e => { const tr = e.target.closest('tr[data-change-details]'); if (tr) showChangeDetails(tr.dataset.changeDetails); });
    $('comparisonRows').addEventListener('keydown', e => { if (['Enter', ' '].includes(e.key) && e.target.matches?.('tr[data-change-details]')) { e.preventDefault(); e.target.click(); } });

    // ---- Start: the shared context script restores the address / saved / default selection and drives the bar ----
    async function start() {
        showPanel('loading');
        try {
            if (!(await ui.fetchJson(api, { action: 'context_options', level: 'program', search: '' })).options.length) { state.panelLocked = true; showPanel('noPrograms'); return; }
        } catch (error) { ui.showToast(error.message || 'Programs could not be loaded.', 'error'); }
        setTimeout(() => { if (!state.panelLocked && !versionId() && !state.data) showPanel('noVersion'); }, 4000);
    }
    start();
}());
