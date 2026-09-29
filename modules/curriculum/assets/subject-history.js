(function () {
    'use strict';

    const workspace = document.getElementById('subjectHistoryWorkspace');
    const ui = window.CurriculumUI;
    if (!workspace || !ui) return;

    const endpoint = workspace.dataset.historyApi;
    const activeView = document.getElementById('subjectHistoryPanel') ? 'subject' : 'curriculum';
    const state = {
        historyPage: 1,
        historyRequest: 0,
        comparisonPage: 1,
        comparisonRequest: 0,
        comparisonLoaded: false,
    };

    const history = {
        search: document.getElementById('historySubjectSearch'),
        program: document.getElementById('historyProgram'),
        curriculum: document.getElementById('historyCurriculum'),
        version: document.getElementById('historyVersion'),
        year: document.getElementById('historyYear'),
        semester: document.getElementById('historySemester'),
        versionStatus: document.getElementById('historyVersionStatus'),
        perPage: document.getElementById('historyPerPage'),
        rows: document.getElementById('subjectHistoryRows'),
        pagination: document.getElementById('subjectHistoryPagination'),
        count: document.getElementById('subjectHistoryCount'),
        context: document.getElementById('subjectHistoryContext'),
    };

    const comparison = {
        program: document.getElementById('compareProgram'),
        curriculum: document.getElementById('compareCurriculum'),
        versionA: document.getElementById('compareVersionA'),
        versionB: document.getElementById('compareVersionB'),
        perPage: document.getElementById('comparePerPage'),
        button: document.getElementById('compareVersionsButton'),
        rows: document.getElementById('comparisonRows'),
        pagination: document.getElementById('comparisonPagination'),
        count: document.getElementById('comparisonCount'),
        context: document.getElementById('comparisonContext'),
    };

    function optionMarkup(value, label) {
        return '<option value="' + ui.escapeHtml(value) + '">' + ui.escapeHtml(label) + '</option>';
    }

    function selectLabel(select) {
        return select.value ? select.options[select.selectedIndex]?.textContent.trim() || '' : '';
    }

    function resetSelect(select, label, disabled) {
        select.innerHTML = optionMarkup('', label);
        select.disabled = disabled;
    }

    function setTableState(rows, columnCount, message, isError) {
        rows.innerHTML = '<tr><td colspan="' + columnCount + '" class="empty-cell' +
            (isError ? ' error-cell' : '') + '">' + ui.escapeHtml(message) + '</td></tr>';
    }

    function updateDistinctSelect(select, values, emptyLabel) {
        const selected = select.value;
        select.innerHTML = optionMarkup('', emptyLabel) + values.map(function (value) {
            return optionMarkup(value, value);
        }).join('');
        if (values.includes(selected)) select.value = selected;
    }

    async function loadPrograms() {
        const payload = await ui.fetchJson(endpoint, {
            action: 'program_options', page: 1, per_page: 50
        });
        const options = payload.programs.map(function (program) {
            return optionMarkup(program.id, program.program_code + ' — ' + program.program_name);
        }).join('');
        if (history.program) {
            history.program.innerHTML = optionMarkup('', 'All academic programs') + options;
        }
        if (comparison.program) {
            comparison.program.innerHTML = optionMarkup('', 'Select an academic program') + options;
        }
    }

    async function loadCurricula(scope) {
        const controls = scope === 'history' ? history : comparison;
        const curriculumLabel = scope === 'history'
            ? 'Select a program to narrow curricula'
            : 'Select an academic program first';
        resetSelect(controls.curriculum, curriculumLabel, true);
        if (scope === 'history') {
            resetSelect(history.version, 'Select a curriculum to narrow versions', true);
        } else {
            resetComparisonVersions('Select a curriculum first');
        }
        if (!controls.program.value) return;

        const payload = await ui.fetchJson(endpoint, {
            action: 'curriculum_options',
            academic_program_id: controls.program.value,
            page: 1,
            per_page: 50,
        });
        controls.curriculum.innerHTML = optionMarkup('', scope === 'history'
            ? 'All curricula in this program'
            : 'Select a curriculum') + payload.curricula.map(function (curriculum) {
            return optionMarkup(
                curriculum.id,
                curriculum.curriculum_code + ' — ' + curriculum.curriculum_name
            );
        }).join('');
        controls.curriculum.disabled = false;
    }

    function resetComparisonVersions(label) {
        resetSelect(comparison.versionA, label, true);
        resetSelect(comparison.versionB, label, true);
        updateCompareButton();
    }

    async function loadVersions(scope) {
        const curriculum = scope === 'history' ? history.curriculum : comparison.curriculum;
        if (scope === 'history') {
            resetSelect(history.version, 'Select a curriculum to narrow versions', true);
        } else {
            resetComparisonVersions('Select a curriculum first');
        }
        if (!curriculum.value) return;

        const payload = await ui.fetchJson(endpoint, {
            action: 'version_options',
            curriculum_id: curriculum.value,
            page: 1,
            per_page: 50,
        });
        const options = payload.versions.map(function (version) {
            const year = version.effective_academic_year
                ? ' / ' + version.effective_academic_year
                : '';
            return optionMarkup(
                version.id,
                version.version_label + year + ' (' + version.status + ')'
            );
        }).join('');
        if (scope === 'history') {
            history.version.innerHTML = optionMarkup('', 'All versions in this curriculum') + options;
            history.version.disabled = false;
        } else {
            comparison.versionA.innerHTML = optionMarkup('', 'Select Version A') + options;
            comparison.versionB.innerHTML = optionMarkup('', 'Select Version B') + options;
            comparison.versionA.disabled = false;
            comparison.versionB.disabled = false;
            updateCompareButton();
        }
    }

    function historyQuery(page) {
        return {
            action: 'list',
            page,
            per_page: history.perPage.value,
            search: history.search.value.trim(),
            academic_program_id: history.program.value,
            curriculum_id: history.curriculum.value,
            curriculum_version_id: history.version.value,
            year_level: history.year.value,
            semester: history.semester.value,
            version_status: history.versionStatus.value,
        };
    }

    function subjectContext(record) {
        return '<span class="history-subject-context"><strong class="history-code">' +
            ui.escapeHtml(record.subject_code) + '</strong><span>' +
            ui.escapeHtml(record.subject_title) + '</span></span>';
    }

    function programContext(record) {
        return '<span class="history-program-context"><strong>' +
            ui.escapeHtml(record.program_name) + '</strong><span class="history-code">' +
            ui.escapeHtml(record.program_code) + '</span></span>';
    }

    function curriculumContext(record) {
        return '<span class="history-curriculum-context"><strong>' +
            ui.escapeHtml(record.curriculum_name) + '</strong><span class="history-code">' +
            ui.escapeHtml(record.curriculum_code) + '</span></span>';
    }

    function versionContext(record) {
        return '<span class="history-version-context"><strong>' +
            ui.escapeHtml(record.version_label) + '</strong><span>' +
            ui.escapeHtml(record.effective_academic_year || 'Academic year not specified') +
            '</span></span>';
    }

    function historyRow(record) {
        return '<tr>' +
            '<td>' + subjectContext(record) + '</td>' +
            '<td>' + programContext(record) + '</td>' +
            '<td><span class="history-program-type">' +
                ui.escapeHtml(record.program_type || 'Not specified') + '</span></td>' +
            '<td>' + curriculumContext(record) + '</td>' +
            '<td>' + versionContext(record) + '</td>' +
            '<td>' + ui.statusBadge(record.version_status) + '</td>' +
            '<td>' + ui.escapeHtml(record.year_level || '—') + '</td>' +
            '<td>' + ui.escapeHtml(record.semester || '—') + '</td>' +
            '<td>' + ui.escapeHtml(record.sequence_order ?? '—') + '</td>' +
            '<td><span class="history-placement-badge">' +
                (Number(record.is_required) === 1 ? 'Required' : 'Optional') + '</span></td>' +
            '<td><span class="history-placement">Created: ' +
                ui.escapeHtml(ui.formatDate(record.version_created_at)) + '<br>Updated: ' +
                ui.escapeHtml(ui.formatDate(record.version_updated_at)) + '</span></td>' +
            '</tr>';
    }

    function updateHistorySummary(summary) {
        document.getElementById('historyStoredPlacements').textContent = summary.stored_placements || 0;
        document.getElementById('historyPrograms').textContent = summary.programs || 0;
        document.getElementById('historyCurricula').textContent = summary.curricula || 0;
        document.getElementById('historyVersions').textContent = summary.curriculum_versions || 0;
        const dateContext = document.getElementById('historyDateContext');
        if (summary.earliest_record || summary.latest_record) {
            dateContext.textContent = 'Earliest stored curriculum record: ' +
                ui.formatDate(summary.earliest_record) + ' · Latest stored curriculum record: ' +
                ui.formatDate(summary.latest_record) +
                '. These are database record dates, not official offering dates.';
        } else {
            dateContext.textContent = 'No stored curriculum-version dates are available for this result.';
        }
    }

    function historyContextParts() {
        const parts = [];
        if (history.search.value.trim()) parts.push('Subject: ' + history.search.value.trim());
        if (history.program.value) parts.push(selectLabel(history.program));
        if (history.curriculum.value) parts.push(selectLabel(history.curriculum));
        if (history.version.value) parts.push(selectLabel(history.version));
        return parts;
    }

    async function loadHistory(page) {
        const sequence = ++state.historyRequest;
        setTableState(history.rows, 11, 'Loading stored subject history…', false);
        try {
            const payload = await ui.fetchJson(endpoint, historyQuery(page));
            if (sequence !== state.historyRequest) return;
            state.historyPage = Number(payload.pagination.page);
            ui.setCount(history.count, Number(payload.pagination.total_records));
            updateDistinctSelect(history.year, payload.filter_values.year_levels, 'All stored values');
            updateDistinctSelect(history.semester, payload.filter_values.semesters, 'All stored values');
            updateDistinctSelect(
                history.versionStatus,
                payload.filter_values.version_statuses,
                'All stored states'
            );
            updateHistorySummary(payload.summary);
            if (payload.history.length) {
                history.rows.innerHTML = payload.history.map(historyRow).join('');
            } else {
                const message = history.search.value.trim()
                    ? 'No historical subject usage matches the selected filters.'
                    : 'No historical subject usage found.';
                setTableState(history.rows, 11, message, false);
            }
            ui.renderPagination(history.pagination, payload.pagination, loadHistory);
            const parts = historyContextParts();
            history.context.textContent = parts.length
                ? 'Current context: ' + parts.join(' → ')
                : 'Showing stored subject placements across all curriculum versions.';
        } catch (error) {
            if (sequence !== state.historyRequest) return;
            ui.setCount(history.count, 0);
            history.pagination.innerHTML = '';
            updateHistorySummary({});
            setTableState(
                history.rows,
                11,
                error.message || 'Stored subject history could not be loaded.',
                true
            );
        }
    }

    function updateCompareButton() {
        comparison.button.disabled = !comparison.curriculum.value ||
            !comparison.versionA.value || !comparison.versionB.value ||
            comparison.versionA.value === comparison.versionB.value;
    }

    function placementMarkup(record, prefix) {
        const placementId = record[prefix + '_placement_id'];
        if (!placementId) return '<span class="history-placement">Not present in this version</span>';
        return '<span class="history-placement">Year: ' +
            ui.escapeHtml(record[prefix + '_year_level'] || '—') + '<br>Semester / Term: ' +
            ui.escapeHtml(record[prefix + '_semester'] || '—') + '<br>Sequence: ' +
            ui.escapeHtml(record[prefix + '_sequence_order'] ?? '—') + '<br>Placement: ' +
            (Number(record[prefix + '_is_required']) === 1 ? 'Required' : 'Optional') + '</span>';
    }

    function changeLabel(type) {
        return {
            added: 'Added',
            removed: 'Removed',
            changed: 'Placement Changed',
            unchanged: 'Unchanged',
        }[type] || 'Unchanged';
    }

    function comparisonRow(record) {
        const type = String(record.change_type || 'unchanged');
        return '<tr><td>' + subjectContext(record) + '</td><td><span class="history-change-badge history-change-' +
            ui.escapeHtml(type) + '">' + ui.escapeHtml(changeLabel(type)) + '</span></td><td>' +
            placementMarkup(record, 'version_a') + '</td><td>' +
            placementMarkup(record, 'version_b') + '</td></tr>';
    }

    function updateComparisonSummary(summary) {
        document.getElementById('comparisonAdded').textContent = summary.added || 0;
        document.getElementById('comparisonRemoved').textContent = summary.removed || 0;
        document.getElementById('comparisonChanged').textContent = summary.changed || 0;
        document.getElementById('comparisonUnchanged').textContent = summary.unchanged || 0;
    }

    function clearComparisonResult(message) {
        state.comparisonLoaded = false;
        comparison.pagination.innerHTML = '';
        ui.setCount(comparison.count, 0);
        updateComparisonSummary({});
        setTableState(comparison.rows, 4, message, false);
        comparison.context.textContent = message;
        document.getElementById('versionAHeader').textContent = 'Version A Placement';
        document.getElementById('versionBHeader').textContent = 'Version B Placement';
    }

    async function loadComparison(page) {
        if (comparison.button.disabled && !state.comparisonLoaded) return;
        const sequence = ++state.comparisonRequest;
        setTableState(comparison.rows, 4, 'Comparing stored subject placements…', false);
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'compare_versions',
                curriculum_id: comparison.curriculum.value,
                version_a_id: comparison.versionA.value,
                version_b_id: comparison.versionB.value,
                page,
                per_page: comparison.perPage.value,
            });
            if (sequence !== state.comparisonRequest) return;
            state.comparisonLoaded = true;
            state.comparisonPage = Number(payload.pagination.page);
            ui.setCount(comparison.count, Number(payload.pagination.total_records));
            updateComparisonSummary(payload.summary);
            document.getElementById('versionAHeader').textContent =
                payload.version_a.version_label + ' Placement';
            document.getElementById('versionBHeader').textContent =
                payload.version_b.version_label + ' Placement';
            if (payload.comparison.length) {
                comparison.rows.innerHTML = payload.comparison.map(comparisonRow).join('');
            } else {
                setTableState(
                    comparison.rows,
                    4,
                    'Neither selected curriculum version has stored subject placements.',
                    false
                );
            }
            ui.renderPagination(comparison.pagination, payload.pagination, loadComparison);
            comparison.context.textContent = payload.curriculum.curriculum_code + ' — ' +
                payload.curriculum.curriculum_name + ': ' + payload.version_a.version_label +
                ' compared with ' + payload.version_b.version_label + '.';
        } catch (error) {
            if (sequence !== state.comparisonRequest) return;
            state.comparisonLoaded = false;
            ui.setCount(comparison.count, 0);
            comparison.pagination.innerHTML = '';
            updateComparisonSummary({});
            setTableState(
                comparison.rows,
                4,
                error.message || 'The curriculum versions could not be compared.',
                true
            );
        }
    }

    history.program?.addEventListener('change', async function () {
        await loadCurricula('history');
        await loadHistory(1);
    });
    history.curriculum?.addEventListener('change', async function () {
        await loadVersions('history');
        await loadHistory(1);
    });
    history.version?.addEventListener('change', function () { loadHistory(1); });
    history.year?.addEventListener('change', function () { loadHistory(1); });
    history.semester?.addEventListener('change', function () { loadHistory(1); });
    history.versionStatus?.addEventListener('change', function () { loadHistory(1); });
    history.perPage?.addEventListener('change', function () { loadHistory(1); });
    history.search?.addEventListener('input', ui.debounce(function () { loadHistory(1); }, 300));

    document.getElementById('clearSubjectHistoryFilters')?.addEventListener('click', async function () {
        history.search.value = '';
        history.program.value = '';
        resetSelect(history.curriculum, 'Select a program to narrow curricula', true);
        resetSelect(history.version, 'Select a curriculum to narrow versions', true);
        history.year.value = '';
        history.semester.value = '';
        history.versionStatus.value = '';
        history.perPage.value = '20';
        await loadHistory(1);
    });

    comparison.program?.addEventListener('change', async function () {
        await loadCurricula('comparison');
        clearComparisonResult('Select a curriculum and two versions to compare their subject placements.');
    });
    comparison.curriculum?.addEventListener('change', async function () {
        await loadVersions('comparison');
        clearComparisonResult('Select two curriculum versions to compare their subject placements.');
    });
    comparison.versionA?.addEventListener('change', function () {
        state.comparisonLoaded = false;
        updateCompareButton();
    });
    comparison.versionB?.addEventListener('change', function () {
        state.comparisonLoaded = false;
        updateCompareButton();
    });
    comparison.perPage?.addEventListener('change', function () {
        if (state.comparisonLoaded) loadComparison(1);
    });
    comparison.button?.addEventListener('click', function () { loadComparison(1); });

    document.getElementById('clearCurriculumHistoryFilters')?.addEventListener('click', function () {
        comparison.program.value = '';
        resetSelect(comparison.curriculum, 'Select an academic program first', true);
        resetComparisonVersions('Select a curriculum first');
        comparison.perPage.value = '20';
        clearComparisonResult('Select a program, curriculum, and two versions to compare their stored subject placements.');
    });

    async function initialize() {
        try {
            await loadPrograms();
        } catch (error) {
            if (history.program) history.program.innerHTML = optionMarkup('', 'Academic programs unavailable');
            if (comparison.program) comparison.program.innerHTML = optionMarkup('', 'Academic programs unavailable');
        }
        if (activeView === 'subject') {
            await loadHistory(1);
        } else {
            clearComparisonResult('Select a program, curriculum, and two versions to compare their stored subject placements.');
        }
    }

    initialize();
}());
