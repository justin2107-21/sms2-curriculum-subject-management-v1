(function () {
    'use strict';

    const workspace = document.getElementById('curriculumExportWorkspace');
    const ui = window.CurriculumUI;
    if (!workspace || !ui) return;

    const endpoint = workspace.dataset.exportApi;
    const ids = ['exportProgram', 'exportCurriculum', 'exportVersion'];
    const inputIds = ['exportProgramSearch', 'exportCurriculumSearch', 'exportVersionSearch'];
    const listIds = ['exportProgramOptions', 'exportCurriculumOptions', 'exportVersionOptions'];
    const values = ids.map(id => document.getElementById(id));
    const inputs = inputIds.map(id => document.getElementById(id));
    const selections = [null, null, null];
    const csvButton = document.getElementById('downloadCurriculumCsv');
    const pdfButton = document.getElementById('downloadCurriculumPdf');
    const printButton = document.getElementById('printCurriculumExport');
    const preview = document.getElementById('curriculumExportPreview');
    const status = document.getElementById('exportActionStatus');
    const yearSelect = document.getElementById('exportYear');
    const semesterSelect = document.getElementById('exportSemester');
    const emptyBox = document.getElementById('exportEmpty');
    const panelBox = document.getElementById('exportPanel');
    // Same pattern as Offering History: an empty state until a version is chosen, then the panel.
    function showPanel(visible) { emptyBox.hidden = visible; panelBox.hidden = !visible; }
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
        const hasSubjects = enabled && dataset && Number(dataset.summary.total_subjects) > 0;
        csvButton.disabled = !hasSubjects;
        pdfButton.disabled = !hasSubjects;
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
        document.getElementById('exportPreviewSummary').textContent = '';
        preview.innerHTML = '<div class="csms-empty" role="status"><i class="fa-solid fa-spinner" aria-hidden="true"></i><strong>' + ui.escapeHtml(message) + '</strong></div>';
    }

    function setSelectOptions(select, values, allLabel) {
        select.replaceChildren(new Option(allLabel, ''));
        values.forEach(option => select.add(new Option(option.label, option.value)));
    }

    async function loadScopeOptions(resetYear) {
        if (!ready()) return;
        ++requestId;
        setActionsEnabled(false);
        clearPreview('Loading curriculum…');
        const currentRequest = ++scopeRequestId;
        const priorYear = resetYear ? '' : yearSelect.value;
        const priorSemester = resetYear ? '' : semesterSelect.value;
        yearSelect.disabled = true;
        semesterSelect.disabled = true;
        try {
            const yearData = await ui.fetchJson(endpoint, Object.assign({ action: 'scope_options' }, params(), { year_level: '' }));
            if (currentRequest !== scopeRequestId) return;
            setSelectOptions(yearSelect, yearData.year_levels || [], 'All year levels');
            if (priorYear && Array.from(yearSelect.options).some(option => option.value === priorYear)) yearSelect.value = priorYear;
            const termData = await ui.fetchJson(endpoint, Object.assign({ action: 'scope_options' }, params(), { year_level: yearSelect.value }));
            if (currentRequest !== scopeRequestId) return;
            setSelectOptions(semesterSelect, termData.semesters || [], 'All semesters / terms');
            if (priorSemester && Array.from(semesterSelect.options).some(option => option.value === priorSemester)) semesterSelect.value = priorSemester;
            yearSelect.disabled = false;
            semesterSelect.disabled = false;
            await generatePreview();
        } catch (error) {
            if (currentRequest !== scopeRequestId) return;
            yearSelect.disabled = false;
            semesterSelect.disabled = false;
            ui.showToast(error.message, 'error');
        }
    }

    function renderReport(data) {
        const context = data.context;
        const count = Number(data.summary.total_subjects || 0);
        document.getElementById('exportPreviewTitle').textContent = context.curriculum_name;
        document.getElementById('exportPreviewSummary').textContent = context.version_label + ' · ' + count + (count === 1 ? ' subject' : ' subjects') + ' · ' + displayUnits(data.summary.total_units).replace(/&mdash;/g, '0') + ' units · ' + data.scope.label;
        const graduateOutcomes = Array.isArray(data.graduate_outcomes) ? data.graduate_outcomes : [];
        const outcomeMarkup = graduateOutcomes.length ? '<section class="export-outcomes"><h2>CHED BSIT Graduate Outcome Mapping</h2><p>CHED Memorandum Order No. 25, series of 2015 · Section 6.3. Institutional adoption/approval not verified. Curriculum mapping does not establish student outcome attainment, regulatory compliance, or institutional approval.</p>' + graduateOutcomes.map(outcome => '<article><h3>' + escape(outcome.outcome_code) + ' · ' + escape(outcome.coverage_status.replace('_',' ')) + '</h3><p>' + escape(outcome.outcome_text) + '</p><small>' + escape(outcome.document_code + ' · ' + outcome.document_title + ' · ' + outcome.source_section + ' · printed p. ' + outcome.printed_page + ' / PDF p. ' + outcome.pdf_page_index + ' · SHA-256 ' + outcome.source_sha256) + '</small><ul>' + outcome.mappings.map(mapping => '<li><strong>' + escape(mapping.subject_code + ' · ' + mapping.subject_title) + '</strong> — ' + escape(mapping.rationale) + '</li>').join('') + (outcome.mappings.length ? '' : '<li>No subject links recorded.</li>') + '</ul></article>').join('') + '</section>' : '';
        const clos = Array.isArray(data.course_learning_outcomes) ? data.course_learning_outcomes : [];
        const cloMarkup = clos.length ? '<section class="export-outcomes"><h2>Course Learning Outcomes &amp; Planned Assessment</h2><p>These are placement-scoped user-authored planning records. They do not establish student attainment, regulatory compliance, or institutional approval.</p>' + clos.map(clo => '<article><h3>' + escape(clo.subject_code + ' · ' + clo.subject_title + ' · ' + clo.clo_code) + (clo.origin === 'inherited' ? ' · Inherited' : '') + '</h3><p>' + escape(clo.clo_statement) + '</p><small>' + escape(clo.record_state + ' · ' + clo.review_state + ' · sequence ' + clo.sequence_order) + '</small><h4>Graduate outcome alignment</h4>' + (clo.alignments.length ? '<ul>' + clo.alignments.map(a => '<li>' + escape(a.outcome_code + ' · ' + a.rationale + ' · ' + a.record_state + ' · ' + a.review_state) + '</li>').join('') + '</ul>' : '<p>No alignment recorded.</p>') + '<h4>Planned assessment</h4>' + (clo.assessment_plans.length ? clo.assessment_plans.map(p => '<p>' + escape([p.assessment_method,p.planned_task,p.evidence_description,p.performance_indicator,p.rubric_or_standard,p.record_state,p.review_state].filter(Boolean).join(' · ')) + '</p>').join('') : '<p>No assessment plan recorded.</p>') + '</article>').join('') + '</section>' : '';
        if (!count) {
            preview.innerHTML = '<div class="csms-empty" role="status"><i class="fa-solid fa-filter" aria-hidden="true"></i><strong>No subjects in this scope</strong><span>No mapped subjects were found for the selected export scope.</span></div>' + outcomeMarkup + cloMarkup + '<p>' + escape(data.disclaimer) + '</p>';
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

        preview.innerHTML = '<article class="export-report"><header class="export-document-header"><p>' + escape(context.program_name).toUpperCase() + '</p><h2>Curriculum Structure</h2><div>' + escape(context.curriculum_code) + ' · ' + escape(context.version_label) + '</div><h3>' + escape(context.curriculum_name) + '</h3><p class="export-print-summary">Export scope: ' + escape(data.scope.label) + '</p></header>' + yearMarkup + outcomeMarkup + cloMarkup + '<footer class="export-document-footer"><strong>Total Subjects: ' + count + '</strong><strong>Total Units: ' + displayUnits(data.summary.total_units) + '</strong><p>' + escape(data.disclaimer) + '</p><small>Generated from CSMS · ' + escape(data.generated_at) + '</small></footer></article>';
    }

    async function generatePreview() {
        if (!ready()) return;
        const currentRequest = ++requestId;
        setActionsEnabled(false);
        clearPreview('Loading curriculum…');
        setStatus('');
        try {
            const payload = await ui.fetchJson(endpoint, Object.assign({ action: 'preview' }, params()));
            if (currentRequest !== requestId) return;
            dataset = payload;
            renderReport(dataset);
        } catch (error) {
            if (currentRequest !== requestId) return;
            clearPreview('The curriculum preview could not be generated.');
            ui.showToast(error.message, 'error');
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

    // Password protection (AES-256 ZIP). The password lives only in these two inputs and in the one POST; it is cleared after every attempt.
    const protectBox = document.getElementById('exportProtect'), protectFields = document.getElementById('exportProtectFields');
    const passwordInput = document.getElementById('exportPassword'), confirmInput = document.getElementById('exportPasswordConfirm');
    const passwordError = document.getElementById('exportPasswordError');
    const MIN_PASSWORD = 10, MAX_PASSWORD = 128;
    function passwordProblem() {
        const pw = passwordInput.value, again = confirmInput.value;
        if (!pw) return 'Enter a password to protect the export.';
        if (!pw.trim()) return 'The password cannot be only spaces.';
        if (pw.length < MIN_PASSWORD) return 'The password must be at least ' + MIN_PASSWORD + ' characters.';
        if (pw.length > MAX_PASSWORD) return 'The password must be at most ' + MAX_PASSWORD + ' characters.';
        if (!again) return 'Confirm the password.';
        if (pw !== again) return 'The password and its confirmation do not match.';
        return '';
    }
    // Inline, visible message next to the buttons. Every refusal goes through here: a click is never a silent no-op.
    function showPasswordError(message) { passwordError.textContent = message; passwordError.hidden = !message; }
    function clearPasswords() {
        passwordInput.value = ''; confirmInput.value = '';
        document.querySelectorAll('[data-pw-toggle]').forEach(button => setReveal(button, false));
    }
    function setReveal(button, show) {
        const input = document.getElementById(button.dataset.pwToggle);
        if (input) input.type = show ? 'text' : 'password';
        button.setAttribute('aria-pressed', show ? 'true' : 'false');
        const icon = button.querySelector('i');
        if (icon) icon.className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
    }
    document.querySelectorAll('[data-pw-toggle]').forEach(button => button.addEventListener('click', () => setReveal(button, button.getAttribute('aria-pressed') !== 'true')));
    protectBox.addEventListener('change', () => {
        protectFields.hidden = !protectBox.checked;
        showPasswordError('');
        if (!protectBox.checked) clearPasswords(); else passwordInput.focus();
    });
    [passwordInput, confirmInput].forEach(input => input.addEventListener('input', () => showPasswordError('')));

    // Status line: resets on the next action and fades by itself, so "PDF downloaded." never goes stale.
    let statusTimer = null;
    function setStatus(text, fadeMs) {
        clearTimeout(statusTimer);
        status.textContent = text;
        if (text && fadeMs) statusTimer = setTimeout(() => { status.textContent = ''; }, fadeMs);
    }

    const FORMATS = {
        csv: { action: 'download_csv', label: 'CSV', type: /^text\/csv\b/i, button: csvButton },
        pdf: { action: 'download_pdf', label: 'PDF', type: /^application\/pdf\b/i, button: pdfButton }
    };
    async function download(kind) {
        const format = FORMATS[kind];
        setStatus('');
        if (!ready() || !dataset || Number(dataset.summary.total_subjects) === 0) {
            showPasswordError('Select a curriculum version with subjects before downloading.');
            return;
        }
        const protect = protectBox.checked;
        if (protect) {
            const problem = passwordProblem();
            showPasswordError(problem);
            if (problem) { (passwordInput.value.length < MIN_PASSWORD || passwordInput.value.length > MAX_PASSWORD ? passwordInput : confirmInput).focus(); return; }
        } else {
            showPasswordError('');
        }
        setActionsEnabled(false);
        setStatus(protect ? 'Generating password-protected ' + format.label + ' ZIP…' : 'Generating ' + format.label + '…');
        const form = new FormData();
        form.set('action', format.action);
        Object.entries(params()).forEach(([key, value]) => form.set(key, value));
        if (protect) { form.set('protect', '1'); form.set('export_password', passwordInput.value); form.set('export_password_confirm', confirmInput.value); }
        form.set('_csrf_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
        try {
            const response = await fetch(endpoint, { method: 'POST', body: form, credentials: 'same-origin' });
            if (!response.ok) {
                let message = 'The ' + format.label + ' could not be generated.';
                try { message = (await response.json()).message || message; } catch (error) { /* keep generic message */ }
                throw new Error(message);
            }
            const blob = await response.blob();
            if (blob.size === 0 || !(protect ? /^application\/zip\b/i : format.type).test(response.headers.get('Content-Type') || '')) throw new Error('The server returned an invalid ' + (protect ? 'protected ZIP' : format.label) + ' response.');
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filenameFromHeader(response.headers.get('Content-Disposition'));
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
            ui.showToast(protect ? 'Protected ' + format.label + ' ZIP downloaded.' : 'Curriculum ' + format.label + ' downloaded.', 'success');
            clearPasswords();
            showPasswordError('');
            setStatus(protect ? 'Protected ZIP downloaded. Keep the password safe: it cannot be recovered.' : format.label + ' downloaded.', 8000);
        } catch (error) {
            showPasswordError(error.message);
            ui.showToast(error.message, 'error');
            setStatus('');
        } finally { setActionsEnabled(ready()); }
    }

    // Prints the current selection from this page: the print stylesheet hides everything except the preview document.
    function printCurrent() {
        if (!ready() || !dataset || Number(dataset.summary.total_subjects) === 0) { showPasswordError('Select a curriculum version with subjects before printing.'); return; }
        showPasswordError('');
        window.print();
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
            dataset = null;
            setStatus('');
            showPasswordError('');
            if (index < 2 || action !== 'select') {
                ++scopeRequestId;
                yearSelect.value = '';
                semesterSelect.value = '';
                setSelectOptions(yearSelect, [], 'All year levels');
                setSelectOptions(semesterSelect, [], 'All semesters / terms');
                yearSelect.disabled = true;
                semesterSelect.disabled = true;
            }
            if (index === 2 && action === 'select') { showPanel(true); loadScopeOptions(true); } else showPanel(false);
        }
    });

    yearSelect.addEventListener('change', function () {
        semesterSelect.value = '';
        loadScopeOptions(false);
    });
    semesterSelect.addEventListener('change', generatePreview);
    csvButton.addEventListener('click', () => download('csv'));
    pdfButton.addEventListener('click', () => download('pdf'));
    printButton.addEventListener('click', printCurrent);
    inputs.forEach(input => input.addEventListener('input', function () {
        ++requestId;
        dataset = null;
        setActionsEnabled(false);
    }));
    showPanel(false);
    setActionsEnabled(false);
}());
