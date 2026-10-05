(function () {
    'use strict';

    const workspace = document.getElementById('curriculumExportWorkspace');
    const ui = window.CurriculumUI;
    if (!workspace || !ui) return;

    const endpoint = workspace.dataset.exportApi;
    const printPage = workspace.dataset.printPage;
    const ids = ['exportProgram', 'exportCurriculum', 'exportVersion'];
    const inputIds = ['exportProgramSearch', 'exportCurriculumSearch', 'exportVersionSearch'];
    const listIds = ['exportProgramOptions', 'exportCurriculumOptions', 'exportVersionOptions'];
    const values = ids.map(id => document.getElementById(id));
    const inputs = inputIds.map(id => document.getElementById(id));
    const selections = [null, null, null];
    const previewButton = document.getElementById('generateCurriculumPreview');
    const csvButton = document.getElementById('downloadCurriculumCsv');
    const printButton = document.getElementById('printCurriculumExport');
    const preview = document.getElementById('curriculumExportPreview');
    const status = document.getElementById('exportActionStatus');
    const yearSelect = document.getElementById('exportYear');
    const semesterSelect = document.getElementById('exportSemester');
    const panels = Array.from(document.querySelectorAll('.export-tab-panel'));
    let dataset = null;
    let requestId = 0;
    let scopeRequestId = 0;

    function params() {
        return {
            academic_program_id: values[0].value,
            curriculum_id: values[1].value,
            curriculum_version_id: values[2].value,
            year_level: yearSelect.value,
            semester: semesterSelect.value
        };
    }

    function ready() { return values.every(input => Boolean(input.value)); }
    function setActionsEnabled(enabled) {
        previewButton.disabled = !enabled;
        const hasSubjects = enabled && dataset && Number(dataset.summary.total_subjects) > 0;
        csvButton.disabled = !hasSubjects;
        printButton.disabled = !hasSubjects;
    }

    function escape(value) {
        return value === null || value === undefined || value === '' ? '&mdash;' : ui.escapeHtml(String(value));
    }

    function displayUnits(value) {
        const number = Number(value);
        return Number.isFinite(number) ? ui.escapeHtml(number.toFixed(2).replace(/\.00$/, '')) : '&mdash;';
    }

    function clearPreview(message) {
        dataset = null;
        document.getElementById('exportPreviewTitle').textContent = 'Curriculum Structure';
        document.getElementById('exportPreviewSummary').textContent = '';
        preview.innerHTML = '<div class="export-empty-state"><p>' + ui.escapeHtml(message) + '</p></div>';
    }

    function scopeLabel() {
        const year = yearSelect.selectedOptions[0]?.textContent;
        const semester = semesterSelect.selectedOptions[0]?.textContent;
        if (!yearSelect.value && !semesterSelect.value) return 'Entire Curriculum';
        if (!yearSelect.value) return 'All Year Levels · ' + semester;
        return year + ' · ' + (semesterSelect.value ? semester : 'All Semesters / Terms');
    }

    function updateScopeSummary() {
        const label = scopeLabel();
        document.getElementById('exportScopeSummary').textContent = label;
        document.querySelectorAll('[data-scope-label]').forEach(node => { node.textContent = label; });
    }

    function setSelectOptions(select, values, allLabel) {
        select.replaceChildren(new Option(allLabel, ''));
        values.forEach(option => select.add(new Option(option.label, option.value)));
    }

    async function loadScopeOptions(resetYear) {
        if (!ready()) return;
        ++requestId;
        setActionsEnabled(false);
        clearPreview('Updating the available export scope...');
        const currentRequest = ++scopeRequestId;
        const priorYear = resetYear ? '' : yearSelect.value;
        const priorSemester = resetYear ? '' : semesterSelect.value;
        yearSelect.disabled = true;
        semesterSelect.disabled = true;
        try {
            const yearData = await ui.fetchJson(endpoint, Object.assign({ action: 'scope_options' }, params(), { year_level: '' }));
            if (currentRequest !== scopeRequestId) return;
            setSelectOptions(yearSelect, yearData.year_levels || [], 'All Year Levels');
            if (priorYear && Array.from(yearSelect.options).some(option => option.value === priorYear)) yearSelect.value = priorYear;
            const termData = await ui.fetchJson(endpoint, Object.assign({ action: 'scope_options' }, params(), { year_level: yearSelect.value }));
            if (currentRequest !== scopeRequestId) return;
            setSelectOptions(semesterSelect, termData.semesters || [], 'All Semesters / Terms');
            if (priorSemester && Array.from(semesterSelect.options).some(option => option.value === priorSemester)) semesterSelect.value = priorSemester;
            yearSelect.disabled = false;
            semesterSelect.disabled = false;
            updateScopeSummary();
            await generatePreview();
        } catch (error) {
            if (currentRequest !== scopeRequestId) return;
            yearSelect.disabled = false;
            semesterSelect.disabled = false;
            ui.showToast(error.message, 'error');
        }
    }

    function activateTab(name) {
        document.querySelectorAll('[data-export-tab]').forEach(function (tab) {
            const active = tab.dataset.exportTab === name;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        panels.forEach(panel => { panel.hidden = panel.id !== 'exportPanel' + name.charAt(0).toUpperCase() + name.slice(1); });
    }

    function renderReport(data) {
        const context = data.context;
        const count = Number(data.summary.total_subjects || 0);
        document.getElementById('exportPreviewTitle').textContent = context.curriculum_name;
        document.getElementById('exportPreviewSummary').textContent = context.version_label + ' · ' + count + ' Subjects · ' + displayUnits(data.summary.total_units).replace(/&mdash;/g, '0') + ' Units · ' + data.scope.label;
        if (!count) {
            preview.innerHTML = '<div class="export-empty-state"><p>No mapped subjects were found for the selected export scope.</p></div>';
            return;
        }

        const yearMarkup = data.years.map(function (year) {
            const semesterMarkup = year.semesters.map(function (semester) {
                const rows = semester.subjects.map(function (subject) {
                    return '<tr><td><strong>' + escape(subject.subject_code) + '</strong></td><td>' + escape(subject.subject_title) + '</td><td class="number-cell">' + displayUnits(subject.units) + '</td><td>' + escape(subject.placement_type) + '</td></tr>';
                }).join('');
                return '<section class="export-semester"><h4>' + escape(semester.semester) + '</h4><div class="export-table-scroll"><table class="export-report-table"><thead><tr><th>Subject Code</th><th>Subject Title</th><th>Units</th><th>Type / Placement</th></tr></thead><tbody>' + rows + '</tbody></table></div><p class="export-subtotal">Semester Units: <strong>' + displayUnits(semester.total_units) + '</strong></p></section>';
            }).join('');
            return '<section class="export-year"><h3>' + escape(year.year_level) + '</h3>' + semesterMarkup + '<p class="export-year-total">Year Total Units: <strong>' + displayUnits(year.total_units) + '</strong></p></section>';
        }).join('');

        preview.innerHTML = '<article class="export-report"><header class="export-document-header"><p>' + escape(context.program_name).toUpperCase() + '</p><h2>Curriculum Structure</h2><div>' + escape(context.curriculum_code) + ' · ' + escape(context.version_label) + '</div><h3>' + escape(context.curriculum_name) + '</h3><p class="export-print-summary">Export scope: ' + escape(data.scope.label) + '</p></header>' + yearMarkup + '<footer class="export-document-footer"><strong>Total Subjects: ' + count + '</strong><strong>Total Units: ' + displayUnits(data.summary.total_units) + '</strong><p>' + escape(data.disclaimer) + '</p><small>Generated from CSMS · ' + escape(data.generated_at) + '</small></footer></article>';
    }

    async function generatePreview() {
        if (!ready()) return;
        const currentRequest = ++requestId;
        setActionsEnabled(false);
        clearPreview('Loading stored curriculum data...');
        status.textContent = 'Loading curriculum preview from stored records…';
        try {
            const payload = await ui.fetchJson(endpoint, Object.assign({ action: 'preview' }, params()));
            if (currentRequest !== requestId) return;
            dataset = payload;
            renderReport(dataset);
            status.textContent = 'Preview generated from current stored curriculum data.';
        } catch (error) {
            if (currentRequest !== requestId) return;
            clearPreview('The curriculum preview could not be generated.');
            ui.showToast(error.message, 'error');
            status.textContent = 'The preview could not be generated.';
        } finally {
            if (currentRequest === requestId) setActionsEnabled(ready());
        }
    }

    function filenameFromHeader(header) {
        const encoded = /filename\*=UTF-8''([^;]+)/i.exec(header || '');
        if (encoded) return decodeURIComponent(encoded[1]).replace(/[^A-Za-z0-9._-]/g, '_');
        const match = /filename="?([^";]+)"?/i.exec(header || '');
        return match ? match[1].replace(/[^A-Za-z0-9._-]/g, '_') : 'curriculum_export.csv';
    }

    async function downloadCsv() {
        if (!ready() || !dataset || Number(dataset.summary.total_subjects) === 0) return;
        csvButton.disabled = true;
        status.textContent = 'Generating CSV from stored curriculum data…';
        const form = new FormData();
        form.set('action', 'download_csv');
        Object.entries(params()).forEach(([key, value]) => form.set(key, value));
        form.set('_csrf_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
        try {
            const response = await fetch(endpoint, { method: 'POST', body: form, credentials: 'same-origin' });
            if (!response.ok) {
                let message = 'The CSV could not be generated.';
                try { message = (await response.json()).message || message; } catch (error) { /* keep generic message */ }
                throw new Error(message);
            }
            const blob = await response.blob();
            if (blob.size === 0 || !/^text\/csv\b/i.test(response.headers.get('Content-Type') || '')) throw new Error('The server returned an invalid CSV response.');
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filenameFromHeader(response.headers.get('Content-Disposition'));
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
            status.textContent = 'CSV generated from current stored curriculum data.';
            ui.showToast('Curriculum CSV downloaded.', 'success');
        } catch (error) {
            ui.showToast(error.message, 'error');
            status.textContent = 'The CSV could not be generated.';
        } finally { setActionsEnabled(ready()); }
    }

    function openPrintView() {
        if (!ready() || !dataset || Number(dataset.summary.total_subjects) === 0) return;
        const url = new URL(printPage, window.location.href);
        Object.entries(params()).forEach(([key, value]) => url.searchParams.set(key, value));
        const printWindow = window.open(url.toString(), '_blank');
        if (!printWindow) ui.showToast('Allow pop-ups to open the print view.', 'warning');
    }

    const cascade = ui.createCascadingAutocomplete({
        levels: [
            {
                input: inputs[0], valueInput: values[0], list: document.getElementById(listIds[0]),
                helpText: 'Choose a program or strand.', selectedText: 'Program selected.', loadingText: 'Searching programs…', noResultsText: 'No matching programs found.', placeholder: 'Search code or program name', minimumLength: 0, showSuggestionsOnFocus: true,
                search: async query => (await ui.fetchJson(endpoint, { action: 'program_options', search: query, page: 1, per_page: 20 })).programs,
                getId: item => item.id, format: item => ({ label: item.program_code + ' — ' + item.program_name, code: item.program_code, title: item.program_name, meta: item.program_type || '' })
            },
            {
                input: inputs[1], valueInput: values[1], list: document.getElementById(listIds[1]),
                helpText: 'Choose a curriculum for this program.', selectedText: 'Curriculum selected.', loadingText: 'Searching curricula…', noResultsText: 'No curricula found for this program.', placeholder: 'Search curriculum code or name', disabledPlaceholder: 'Select a program first', minimumLength: 0, showSuggestionsOnEnable: true,
                search: async query => (await ui.fetchJson(endpoint, { action: 'curriculum_options', academic_program_id: values[0].value, search: query, page: 1, per_page: 20 })).curricula,
                getId: item => item.id, format: item => ({ label: item.curriculum_code + ' — ' + item.curriculum_name, code: item.curriculum_code, title: item.curriculum_name })
            },
            {
                input: inputs[2], valueInput: values[2], list: document.getElementById(listIds[2]),
                helpText: 'Choose a curriculum version.', selectedText: 'Curriculum version selected.', loadingText: 'Searching versions…', noResultsText: 'No curriculum versions found.', placeholder: 'Search version label or academic year', disabledPlaceholder: 'Select a curriculum first', minimumLength: 0, showSuggestionsOnEnable: true,
                search: async query => (await ui.fetchJson(endpoint, { action: 'version_options', curriculum_id: values[1].value, search: query, page: 1, per_page: 20 })).versions,
                getId: item => item.id, format: item => ({ label: item.version_label + (item.effective_academic_year ? ' — ' + item.effective_academic_year : ''), code: item.version_label, title: item.effective_academic_year || '' })
            }
        ],
        onChange: function (index, item, id, action) {
            selections[index] = action === 'select' ? item : null;
            for (let child = index + 1; child < selections.length; child++) selections[child] = null;
            ++requestId;
            setActionsEnabled(false);
            clearPreview('Select a Program / Strand, Curriculum, and Curriculum Version to preview an export.');
            status.textContent = 'Select a curriculum version to preview its stored data.';
            if (index < 2 || action !== 'select') {
                ++scopeRequestId;
                yearSelect.value = '';
                semesterSelect.value = '';
                setSelectOptions(yearSelect, [], 'All Year Levels');
                setSelectOptions(semesterSelect, [], 'All Semesters / Terms');
                yearSelect.disabled = true;
                semesterSelect.disabled = true;
                updateScopeSummary();
            }
            if (index === 2 && action === 'select') loadScopeOptions(true);
        }
    });

    document.querySelectorAll('[data-export-tab]').forEach(tab => tab.addEventListener('click', () => activateTab(tab.dataset.exportTab)));
    yearSelect.addEventListener('change', function () {
        semesterSelect.value = '';
        updateScopeSummary();
        loadScopeOptions(false);
    });
    semesterSelect.addEventListener('change', function () {
        updateScopeSummary();
        generatePreview();
    });
    previewButton.addEventListener('click', generatePreview);
    csvButton.addEventListener('click', downloadCsv);
    printButton.addEventListener('click', openPrintView);
    inputs.forEach(input => input.addEventListener('input', function () {
        ++requestId;
        dataset = null;
        setActionsEnabled(false);
        clearPreview('The selected context changed. Complete the selection again to generate the current curriculum.');
    }));
    updateScopeSummary();
    setActionsEnabled(false);
}());
