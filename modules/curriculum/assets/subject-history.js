(function () {
    'use strict';
    const root = document.getElementById('subjectHistoryWorkspace'), ui = window.CurriculumUI;
    if (!root || !ui) return;
    const $ = id => document.getElementById(id), esc = ui.escapeHtml, api = root.dataset.historyApi;
    const subjectView = Boolean($('subjectHistoryPanel'));
    const state = { historyPage: 1, historyRequest: 0, historyRows: new Map(), compareRows: new Map(), comparisonRequest: 0, comparison: null };
    const detailsModal = ui.createModal($('historyDetailsModal'));
    const changeModal = ui.createModal($('comparisonDetailsModal'));
    const subjectSearch = $('historySubjectSearch'), subjectId = $('historySubjectId');
    const versionSearch = $('historyVersionSearch'), versionId = $('historyVersion');
    const versionAInput = $('compareVersionASearch'), versionBInput = $('compareVersionBSearch');
    const compareProgramInput = $('compareProgramSearch'), compareCurriculumInput = $('compareCurriculumSearch');
    let historyVersionPicker, versionAPicker, versionBPicker;
    let compareCurriculumPicker;

    window.CSMSPageCIMFContext = () => subjectView ? {subject_id:subjectId.value,academic_program_id:$('historyProgram').value,curriculum_id:$('historyCurriculum').value,curriculum_version_id:versionId.value,year_level:$('historyYear').value,semester:$('historySemester').value,version_status:$('historyVersionStatus').value,page:state.historyPage} : {academic_program_id:$('compareProgram').value,curriculum_id:$('compareCurriculum').value,version_a_id:$('compareVersionA').value,version_b_id:$('compareVersionB').value,page:state.comparison?.pagination.page || 1};

    function option(value, label) { return '<option value="' + esc(value) + '">' + esc(label) + '</option>'; }
    function replaceOptions(select, label, items, key, text) {
        const previous = select.value;
        select.innerHTML = option('', label) + items.map(item => option(item[key], text(item))).join('');
        if (Array.from(select.options).some(item => item.value === previous)) select.value = previous;
    }
    function setState(rows, columns, message, error) {
        rows.innerHTML = '<tr><td colspan="' + columns + '" class="empty-cell' + (error ? ' error-cell' : '') + '">' + esc(message) + '</td></tr>';
    }
    function formatSubject(item) { return { label: item.subject_code + ' — ' + item.subject_title, code: item.subject_code, title: item.subject_title }; }
    const subjectPicker = ui.createAutocomplete({
        input: subjectSearch, valueInput: subjectId, list: $('historySubjectOptions'), minimumLength: 1, maxSuggestions: 3,
        helpText: 'Search by subject code or title.', selectedText: 'Subject selected.', loadingText: 'Searching subjects…',
        noResultsText: 'No matching subjects found.', errorText: 'Subjects could not be loaded.',
        search: async query => (await ui.fetchJson(api, { action: 'subject_options', search: query, page: 1, per_page: 20 })).subjects,
        getId: item => item.id, format: formatSubject, onSelect: () => loadHistory(1), onClear: () => clearHistory()
    });
    const compareProgramPicker = ui.createAutocomplete({
        input: compareProgramInput, valueInput: $('compareProgram'), list: $('compareProgramOptions'), minimumLength: 1, maxSuggestions: 3,
        helpText: 'Choose a program or strand first.', selectedText: 'Program / Strand selected.', loadingText: 'Searching programs…',
        noResultsText: 'No matching programs found.',
        search: async query => (await ui.fetchJson(api, { action: 'program_options', search: query, page: 1, per_page: 20 })).programs,
        getId: item => item.id, format: item => ({ label: item.program_code + ' — ' + item.program_name, code: item.program_code, title: item.program_name }),
        onSelect: () => { compareCurriculumPicker.clear(false); compareCurriculumInput.disabled = false; compareCurriculumInput.placeholder = 'Search curriculum'; clearCompareVersions(); },
        onClear: () => { compareCurriculumPicker.clear(false); compareCurriculumInput.disabled = true; compareCurriculumInput.placeholder = 'Select a program first'; clearCompareVersions(); }
    });
    compareCurriculumPicker = ui.createAutocomplete({
        input: compareCurriculumInput, valueInput: $('compareCurriculum'), list: $('compareCurriculumOptions'), minimumLength: 1, maxSuggestions: 3,
        helpText: 'Choose a curriculum under the selected program.', selectedText: 'Curriculum selected.', loadingText: 'Searching curricula…',
        noResultsText: 'No matching curricula found.',
        search: async query => {
            if (!$('compareProgram').value) return [];
            return (await ui.fetchJson(api, { action: 'curriculum_options', academic_program_id: $('compareProgram').value, search: query, page: 1, per_page: 20 })).curricula;
        },
        getId: item => item.id, format: item => ({ label: item.curriculum_code + ' — ' + item.curriculum_name, code: item.curriculum_code, title: item.curriculum_name }),
        onSelect: () => clearCompareVersions(), onClear: () => clearCompareVersions()
    });

    function versionPicker(input, hidden, list, curriculumSelect, otherPicker) {
        return ui.createAutocomplete({
            input, valueInput: hidden, list, minimumLength: 1, maxSuggestions: 3,
            helpText: 'Choose a version in the selected curriculum.', selectedText: 'Curriculum version selected.',
            loadingText: 'Searching versions…', noResultsText: 'No matching versions found.',
            search: async query => {
                if (!curriculumSelect.value) return [];
                const data = await ui.fetchJson(api, { action: 'version_options', curriculum_id: curriculumSelect.value, search: query, page: 1, per_page: 20 });
                return data.versions;
            },
            getId: item => item.id,
            format: item => ({ label: item.version_label + (item.effective_academic_year ? ' — ' + item.effective_academic_year : ''), code: item.version_label, title: item.effective_academic_year || item.status }),
            onSelect: () => {
                const duplicate = Boolean(otherPicker && hidden.value === otherPicker()?.getSelectedId());
                if (duplicate) {
                    otherPicker().clear(false);
                    $('compareValidation').textContent = 'Choose two different curriculum versions.';
                    $('compareValidation').hidden = false;
                } else if (!subjectView) $('compareValidation').hidden = true;
                if (subjectView) loadHistory(1); else updateCompareAvailability();
            },
            onClear: () => { if (subjectView) loadHistory(1); else updateCompareAvailability(); }
        });
    }
    historyVersionPicker = versionPicker(versionSearch, versionId, $('historyVersionOptions'), $('historyCurriculum'));
    versionAPicker = versionPicker(versionAInput, $('compareVersionA'), $('compareVersionAOptions'), $('compareCurriculum'), () => versionBPicker);
    versionBPicker = versionPicker(versionBInput, $('compareVersionB'), $('compareVersionBOptions'), $('compareCurriculum'), () => versionAPicker);

    function historyQuery(page) {
        return { action: 'list', page, per_page: 20, subject_id: subjectId.value, academic_program_id: $('historyProgram').value,
            curriculum_id: $('historyCurriculum').value, curriculum_version_id: versionId.value, year_level: $('historyYear').value,
            semester: $('historySemester').value, version_status: $('historyVersionStatus').value };
    }
    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : (many || one + 's')); }
    function updateHistorySummary(summary) {
        $('historySummary').textContent = [plural(summary.stored_placements || 0, 'stored placement'), plural(summary.programs || 0, 'program'),
            plural(summary.curricula || 0, 'curriculum', 'curricula'), plural(summary.curriculum_versions || 0, 'curriculum version')].join(' · ');
    }
    function historyRow(row, index) {
        state.historyRows.set(String(index), row);
        const historicalSubject = Number(row.snapshot_complete) === 1
            ? '<strong>' + esc(row.subject_code) + '</strong><span>' + esc(row.subject_title) + '</span><small>Captured snapshot · ' + esc(row.snapshot_captured_at || 'capture date unavailable') + '</small>'
            : '<strong>Historical subject details unavailable</strong><span>Current Subject Master: ' + esc(row.current_subject_code || '') + ' · ' + esc(row.current_subject_title || '') + '</span>';
        return '<tr><td><span class="history-subject-context">' + historicalSubject + '</span></td>' +
            '<td><span class="history-code">' + esc(row.program_code) + '</span></td>' +
            '<td><span class="history-curriculum-context"><strong>' + esc(row.curriculum_code) + '</strong><span>' + esc(row.version_label) + '</span></span></td>' +
            '<td>' + esc([row.year_level, row.semester].filter(Boolean).join(' · ') || '—') + '</td><td>' + ui.statusBadge(row.version_status) + '</td>' +
            '<td><button type="button" class="row-action-button history-link" data-history-details="' + index + '">View Details</button></td></tr>';
    }
    async function loadHistory(page) {
        if (!subjectId.value) { clearHistory(); return; }
        const request = ++state.historyRequest;
        $('historyCimfButton').disabled = true;
        setState($('subjectHistoryRows'), 6, 'Loading stored subject history…');
        try {
            const payload = await ui.fetchJson(api, historyQuery(page));
            if (request !== state.historyRequest) return;
            state.historyPage = Number(payload.pagination.page); state.historyRows.clear();
            ui.setCount($('subjectHistoryCount'), Number(payload.pagination.total_records)); updateHistorySummary(payload.summary);
            updateFilterOptions(payload.filter_values);
            if (payload.history.length) $('subjectHistoryRows').innerHTML = payload.history.map(historyRow).join('');
            else setState($('subjectHistoryRows'), 6, ( $('historyProgram').value || $('historyCurriculum').value || versionId.value || $('historyYear').value || $('historySemester').value || $('historyVersionStatus').value)
                ? 'No stored history matches the selected filters.' : 'No stored history found for this subject.');
            ui.renderPagination($('subjectHistoryPagination'), payload.pagination, loadHistory);
            $('historyCimfButton').disabled = payload.history.length === 0;
        } catch (error) { if (request === state.historyRequest) setState($('subjectHistoryRows'), 6, error.message || 'Stored subject history could not be loaded.', true); }
    }
    function clearHistory() {
        state.historyRequest++;
        state.historyRows.clear(); $('historySummary').textContent = subjectId.value ? 'No stored history found for this subject.' : 'Search for a subject to view its stored history.';
        ui.setCount($('subjectHistoryCount'), 0); $('subjectHistoryPagination').innerHTML = '';
        setState($('subjectHistoryRows'), 6, subjectId.value ? 'No stored history found for this subject.' : 'Search for a subject to view its stored history.');
        $('historyCimfButton').disabled = true;
    }
    function updateFilterOptions(values) {
        const set = (select, key, empty) => {
            const old = select.value, vals = values[key] || [];
            select.innerHTML = option('', empty) + vals.map(v => option(v, v)).join('');
            if (vals.includes(old)) select.value = old;
        };
        set($('historyYear'), 'year_levels', 'All Year Levels'); set($('historySemester'), 'semesters', 'All Semesters / Terms');
        set($('historyVersionStatus'), 'version_statuses', 'All Version Status');
    }
    async function loadPrograms() {
        const data = await ui.fetchJson(api, { action: 'program_options', page: 1, per_page: 50 });
        const label = p => p.program_code + ' — ' + p.program_name;
        if ($('historyProgram')) replaceOptions($('historyProgram'), 'All Programs', data.programs, 'id', label);
        if (subjectView) await loadCurricula($('historyProgram'), $('historyCurriculum'), false);
    }
    async function loadCurricula(program, curriculum, required) {
        curriculum.disabled = true; curriculum.innerHTML = option('', required ? 'Select a program first' : 'All Curricula');
        const data = await ui.fetchJson(api, { action: 'curriculum_options', ...(program.value ? { academic_program_id: program.value } : {}), page: 1, per_page: 50 });
        replaceOptions(curriculum, required ? 'Select a Curriculum' : 'All Curricula', data.curricula, 'id', c => c.curriculum_code + ' — ' + c.curriculum_name);
        curriculum.disabled = false;
    }
    function disableHistoryVersion() {
        historyVersionPicker.clear(false); versionSearch.disabled = !$('historyCurriculum').value;
        versionSearch.placeholder = $('historyCurriculum').value ? 'Search version label or academic year' : 'Select a curriculum first';
    }
    function clearCompareVersions() {
        versionAPicker.clear(false); versionBPicker.clear(false);
        const enabled = Boolean($('compareCurriculum').value);
        versionAInput.disabled = !enabled; versionBInput.disabled = !enabled;
        versionAInput.placeholder = enabled ? 'Search Version A' : 'Select a curriculum first';
        versionBInput.placeholder = enabled ? 'Search Version B' : 'Select a curriculum first';
        $('compareValidation').hidden = true; updateCompareAvailability();
    }
    function updateCompareAvailability() {
        const valid = Boolean($('compareCurriculum').value && $('compareVersionA').value && $('compareVersionB').value && $('compareVersionA').value !== $('compareVersionB').value);
        if (!valid) clearComparisonResult('Select a program, curriculum, and two different versions to compare their stored placements.');
        else loadComparison(1).catch(error => clearComparisonResult(error.message || 'Versions could not be compared.'));
    }
    function placement(record, prefix) {
        if (!record[prefix + '_placement_id']) return 'Not present';
        return [record[prefix + '_year_level'], record[prefix + '_semester']].filter(Boolean).join(' · ') || 'Placement not specified';
    }
    function changeName(type) { return ({ added: 'Added', removed: 'Removed', moved: 'Moved', placement_type_changed: 'Placement Type Changed', unchanged: 'Unchanged' })[type] || type; }
    function comparisonRow(record, i) {
        state.compareRows.set(String(i), record);
        const historicalSubject = record.subject_code
            ? '<strong>' + esc(record.subject_code) + '</strong><span>' + esc(record.subject_title) + '</span><small>Snapshot-backed details where captured</small>'
            : '<strong>Historical subject details unavailable</strong><span>No complete snapshot exists for either version.</span>';
        return '<tr><td><span class="history-subject-context">' + historicalSubject + '</span></td>' +
            '<td><span class="history-change-badge history-change-' + esc(record.change_type) + '">' + esc(changeName(record.change_type)) + '</span></td>' +
            '<td>' + esc(placement(record, 'version_a')) + '</td><td>' + esc(placement(record, 'version_b')) + '</td>' +
            '<td><button type="button" class="row-action-button" data-change-details="' + i + '">View Details</button></td></tr>';
    }
    function clearComparisonResult(message) {
        state.comparisonRequest++;
        state.comparison = null; state.compareRows.clear(); ui.setCount($('comparisonCount'), 0);
        $('comparisonPagination').innerHTML = ''; $('comparisonPagination').hidden = true; $('comparisonSummary').textContent = message;
        $('comparisonEmpty').textContent = message; $('comparisonEmpty').hidden = false; $('comparisonTableWrap').hidden = true;
        $('historyCimfButton').disabled = true;
    }
    async function loadComparison(page) {
        const request = ++state.comparisonRequest;
        const data = await ui.fetchJson(api, { action: 'compare_versions', curriculum_id: $('compareCurriculum').value,
            version_a_id: $('compareVersionA').value, version_b_id: $('compareVersionB').value, page, per_page: 20 });
        if (request !== state.comparisonRequest) return;
        state.comparison = data; state.compareRows.clear(); ui.setCount($('comparisonCount'), Number(data.pagination.total_records));
        const s = data.summary, changes = Number(s.added) + Number(s.removed) + Number(s.moved) + Number(s.placement_type_changed);
        const unitsA = s.version_a_units === null ? 'unavailable without complete snapshots' : ui.formatUnits(s.version_a_units) + ' units';
        const unitsB = s.version_b_units === null ? 'unavailable without complete snapshots' : ui.formatUnits(s.version_b_units) + ' units';
        $('comparisonSummary').textContent = 'Version A: ' + s.version_a_subjects + ' subjects · ' + unitsA + '   |   Version B: ' + s.version_b_subjects + ' subjects · ' + unitsB + '   |   Changes: ' + s.added + ' Added · ' + s.removed + ' Removed · ' + s.moved + ' Moved' + (s.placement_type_changed ? ' · ' + s.placement_type_changed + ' Placement Type Changed' : '');
        $('historyCimfButton').disabled = false;
        if (!data.comparison.length || changes === 0) {
            $('comparisonEmpty').textContent = 'No subject placement differences were found between the selected stored versions.';
            $('comparisonEmpty').hidden = false; $('comparisonTableWrap').hidden = true; $('comparisonPagination').hidden = true;
        } else {
            $('comparisonEmpty').hidden = true; $('comparisonTableWrap').hidden = false;
            $('comparisonRows').innerHTML = data.comparison.map(comparisonRow).join('');
            $('comparisonPagination').hidden = Number(data.pagination.total_pages) <= 1;
        }
        ui.renderPagination($('comparisonPagination'), data.pagination, loadComparison);
    }
    function explainChange(record) {
        const a = placement(record, 'version_a'), b = placement(record, 'version_b');
        if (record.change_type === 'removed') return 'The subject exists in Version A but is not present in Version B.';
        if (record.change_type === 'added') return 'The subject is not present in Version A and appears in Version B.';
        if (record.change_type === 'moved') return 'The stored placement changed from ' + a + ' to ' + b + '.';
        if (record.change_type === 'placement_type_changed') return 'The stored placement type changed from ' + (Number(record.version_a_is_required) ? 'Required' : 'Optional') + ' to ' + (Number(record.version_b_is_required) ? 'Required' : 'Optional') + '.';
        return 'The stored placement is the same in both selected versions.';
    }
    async function showHistoryDetails(index) {
        const row = state.historyRows.get(String(index)); if (!row) return;
        try {
            const { record: r } = await ui.fetchJson(api, { action: 'details', placement_id: row.placement_id });
            const subjectLabel = Number(r.snapshot_complete) === 1 ? r.subject_code + ' · ' + r.subject_title : 'Historical subject details unavailable';
            $('historyDetailsContext').textContent = subjectLabel;
            const entries = [['Historical subject', subjectLabel], ['Current Subject Master (present-day context)', (r.current_subject_code || '—') + ' — ' + (r.current_subject_title || '—')], ['Snapshot captured', Number(r.snapshot_complete) === 1 ? ui.formatDate(r.snapshot_captured_at) + ' · ' + (r.capture_method || '') : 'Unavailable'], ['Program / Strand', r.program_code + ' — ' + r.program_name], ['Curriculum', r.curriculum_code + ' — ' + r.curriculum_name], ['Curriculum Version', r.version_label + ' · ' + r.version_status], ['Year Level', r.year_level || '—'], ['Semester / Term', r.semester || '—'], ['Sequence', r.sequence_order ?? '—'], ['Placement Type', Number(r.is_required) === 1 ? 'Required' : 'Optional'], ['Stored Date', ui.formatDate(r.placement_created_at)], ['Captured units', Number(r.snapshot_complete) === 1 ? ui.formatUnits(r.units) : 'Unavailable'], ['Lecture units', Number(r.snapshot_complete) === 1 ? ui.formatUnits(r.lecture_units) : 'Unavailable'], ['Laboratory units', Number(r.snapshot_complete) === 1 ? ui.formatUnits(r.laboratory_units) : 'Unavailable'], ['Description', Number(r.snapshot_complete) === 1 ? (r.description || '—') : 'Unavailable'], ['Last Updated Date', ui.formatDate(r.placement_updated_at)], ['Program Type', r.program_type || '—']];
            $('historyDetailsBody').innerHTML = entries.map(([k,v]) => '<div><dt class="eyebrow">' + esc(k) + '</dt><dd>' + esc(v) + '</dd></div>').join('');
            $('historyMappingLink').href = 'subject_mapping.php?curriculum_version_id=' + Number(r.curriculum_version_id) + '&subject_id=' + Number(r.subject_id);
            detailsModal.open();
        } catch (error) { ui.showToast(error.message || 'History details could not be loaded.', 'error'); }
    }
    function showChangeDetails(index) {
        const r = state.compareRows.get(String(index)); if (!r || !state.comparison) return;
        $('comparisonDetailsContext').textContent = r.subject_code ? r.subject_code + ' · ' + r.subject_title : 'Historical subject details unavailable';
        const a = placement(r, 'version_a'), b = placement(r, 'version_b');
        const attrs = (prefix, present) => !present ? 'Not present' : (Number(r[prefix + '_snapshot_complete']) === 1
            ? esc(r[prefix + '_subject_code']) + ' — ' + esc(r[prefix + '_subject_title']) + '<br>' + ui.formatUnits(r[prefix + '_units']) + ' units · captured ' + esc(r[prefix + '_snapshot_captured_at'] || 'date unavailable')
            : 'Historical subject details unavailable');
        $('comparisonDetailsVersions').innerHTML = '<article><span class="eyebrow">VERSION A</span><strong>' + esc(state.comparison.version_a.version_label) + '</strong><p>Record state: ' + esc(state.comparison.version_a.status) + '<br>' + esc(a) + (r.version_a_placement_id ? '<br>' + (Number(r.version_a_is_required) ? 'Required' : 'Optional') + '<br>' + attrs('version_a', true) : '') + '</p></article><article><span class="eyebrow">VERSION B</span><strong>' + esc(state.comparison.version_b.version_label) + '</strong><p>Record state: ' + esc(state.comparison.version_b.status) + '<br>' + esc(b) + (r.version_b_placement_id ? '<br>' + (Number(r.version_b_is_required) ? 'Required' : 'Optional') + '<br>' + attrs('version_b', true) : '') + '</p></article>';
        $('comparisonDetailsChange').innerHTML = '<span class="history-change-badge history-change-' + esc(r.change_type) + '">' + esc(changeName(r.change_type)) + '</span>';
        $('comparisonDetailsExplanation').textContent = explainChange(r); changeModal.open();
    }

    if (subjectView) {
        $('moreHistoryFilters').addEventListener('click', function () {
            const open = this.getAttribute('aria-expanded') !== 'true'; this.setAttribute('aria-expanded', String(open)); $('historyMoreFilters').hidden = !open;
            this.innerHTML = 'More Filters <i class="fa-solid fa-chevron-' + (open ? 'up' : 'down') + '"></i>';
        });
        $('historyProgram').addEventListener('change', async () => { await loadCurricula($('historyProgram'), $('historyCurriculum'), false); disableHistoryVersion(); loadHistory(1); });
        $('historyCurriculum').addEventListener('change', () => { disableHistoryVersion(); loadHistory(1); });
        [$('historyYear'), $('historySemester'), $('historyVersionStatus')].forEach(select => select.addEventListener('change', () => loadHistory(1)));
    }
    $('clearSubjectHistoryFilters')?.addEventListener('click', async () => { subjectPicker.clear(false); $('historyProgram').value = ''; await loadCurricula($('historyProgram'), $('historyCurriculum'), false); $('historyYear').value = ''; $('historySemester').value = ''; $('historyVersionStatus').value = ''; $('historyMoreFilters').hidden = true; $('moreHistoryFilters').setAttribute('aria-expanded','false'); historyVersionPicker.clear(false); clearHistory(); });
    $('subjectHistoryRows')?.addEventListener('click', e => { const b = e.target.closest('[data-history-details]'); if (b) showHistoryDetails(b.dataset.historyDetails); });
    $('comparisonRows')?.addEventListener('click', e => { const b = e.target.closest('[data-change-details]'); if (b) showChangeDetails(b.dataset.changeDetails); });
    $('historyCimfButton').addEventListener('click', () => window.CSMSCIMF?.open(subjectView ? 'summarize' : 'explain'));
    async function initialize() {
        if ($('historyCimfButton')) $('historyCimfButton').disabled = true;
        try { await loadPrograms(); } catch (error) { if (subjectView) $('historySummary').textContent = error.message; }
        if (subjectView) clearHistory(); else clearComparisonResult('Select a program, curriculum, and two different versions to compare their stored placements.');
    }
    initialize();
}());
