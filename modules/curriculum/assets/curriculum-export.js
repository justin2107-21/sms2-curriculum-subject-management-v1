(function () {
    'use strict';

    const workspace = document.getElementById('curriculumExportWorkspace');
    if (!workspace || !window.CurriculumUI) return;

    const ui = window.CurriculumUI;
    const endpoint = workspace.dataset.exportApi;
    const printPage = workspace.dataset.printPage;
    const program = document.getElementById('exportProgram');
    const curriculum = document.getElementById('exportCurriculum');
    const version = document.getElementById('exportVersion');
    const previewButton = document.getElementById('previewCurriculumExport');
    const printButton = document.getElementById('printCurriculumExport');
    const csvButton = document.getElementById('downloadCurriculumCsv');
    const preview = document.getElementById('curriculumExportPreview');
    const status = document.getElementById('exportActionStatus');
    const optionInputs = {
        include_requirements: document.getElementById('includeRequirements'),
        include_electives: document.getElementById('includeElectives'),
        include_grade_weighting: document.getElementById('includeGradeWeighting'),
        include_compliance: document.getElementById('includeCompliance')
    };

    function optionMarkup(value, label) {
        return '<option value="' + ui.escapeHtml(value) + '">' + ui.escapeHtml(label) + '</option>';
    }

    function resetSelect(select, label, disabled) {
        select.innerHTML = optionMarkup('', label);
        select.disabled = disabled;
    }

    function contextParameters() {
        return {
            academic_program_id: program.value,
            curriculum_id: curriculum.value,
            curriculum_version_id: version.value
        };
    }

    function exportParameters() {
        const parameters = contextParameters();
        Object.entries(optionInputs).forEach(function ([name, input]) {
            parameters[name] = input.checked ? '1' : '0';
        });
        return parameters;
    }

    function setActionsEnabled(enabled) {
        previewButton.disabled = !enabled;
        printButton.disabled = !enabled;
        csvButton.disabled = !enabled;
        status.textContent = enabled
            ? 'Ready to preview or generate a generic report.'
            : 'Select a curriculum version to begin.';
    }

    function value(value) {
        return value === null || value === undefined || value === '' ? '&mdash;' : ui.escapeHtml(value);
    }

    function units(value) {
        if (value === null || value === undefined || value === '') return '&mdash;';
        const number = Number(value);
        return Number.isFinite(number) ? ui.escapeHtml(number.toFixed(2).replace(/\.00$/, '')) : '&mdash;';
    }

    function contextItems(context) {
        const items = [
            ['Academic Program', context.program_code + ' — ' + context.program_name],
            ['Program Type', context.program_type],
            ['Curriculum', context.curriculum_code + ' — ' + context.curriculum_name],
            ['Curriculum Version', context.version_label],
            ['Effective Academic Year', context.effective_academic_year],
            ['Technical Version Status', context.version_status]
        ];
        return items.map(function (item) {
            return '<div class="export-context-item"><span>' + ui.escapeHtml(item[0]) + '</span><strong>' + value(item[1]) + '</strong></div>';
        }).join('');
    }

    function groupedSubjects(subjects) {
        const groups = new Map();
        subjects.forEach(function (subject) {
            const year = subject.year_level || 'Not specified';
            const term = subject.semester || 'Not specified';
            const key = year + '\u0000' + term;
            if (!groups.has(key)) groups.set(key, { year, term, subjects: [] });
            groups.get(key).subjects.push(subject);
        });
        return Array.from(groups.values());
    }

    function subjectRows(subjects) {
        return subjects.map(function (subject) {
            return '<tr><td><strong>' + value(subject.subject_code) + '</strong></td>' +
                '<td>' + value(subject.subject_title) + '</td>' +
                '<td class="number-cell">' + units(subject.units) + '</td>' +
                '<td class="number-cell">' + units(subject.lecture_units) + '</td>' +
                '<td class="number-cell">' + units(subject.laboratory_units) + '</td>' +
                '<td class="number-cell">' + value(subject.sequence_order) + '</td>' +
                '<td>' + ((Number(subject.is_required) === 1) ? 'Required Placement' : 'Optional Placement') + '</td></tr>';
        }).join('');
    }

    function subjectsSection(subjects) {
        const groups = groupedSubjects(subjects);
        let body = '';
        if (!groups.length) {
            body = '<div class="export-table-scroll"><table class="export-report-table"><tbody><tr><td class="empty-report-cell">No subjects are mapped to this curriculum version.</td></tr></tbody></table></div>';
        } else {
            body = groups.map(function (group) {
                return '<h3>Year Level: ' + ui.escapeHtml(group.year) + ' &nbsp;•&nbsp; Semester / Term: ' + ui.escapeHtml(group.term) + '</h3>' +
                    '<div class="export-table-scroll"><table class="export-report-table"><thead><tr><th>Subject Code</th><th>Subject Title</th><th>Total Units</th><th>Lecture</th><th>Laboratory</th><th>Sequence</th><th>Placement</th></tr></thead><tbody>' +
                    subjectRows(group.subjects) + '</tbody></table></div>';
            }).join('');
        }
        return '<section class="export-report-section"><h2>Curriculum Subjects</h2><p class="export-section-note">Year-level and semester/term groups use the stored text values and lexical record order.</p>' + body + '</section>';
    }

    function requirementsSection(items) {
        const rows = items.length ? items.map(function (item) {
            return '<tr><td>' + value(item.subject_code) + '</td><td>' + value(item.subject_title) + '</td><td>' +
                ui.escapeHtml(item.requirement_type === 'corequisite' ? 'Corequisite' : 'Prerequisite') + '</td><td>' +
                value(item.required_subject_code) + '</td><td>' + value(item.required_subject_title) + '</td></tr>';
        }).join('') : '<tr><td colspan="5" class="empty-report-cell">No prerequisite or corequisite relationships are configured.</td></tr>';
        return '<section class="export-report-section"><h2>Prerequisite &amp; Corequisite Relationships</h2><div class="export-table-scroll"><table class="export-report-table"><thead><tr><th>Target Subject</th><th>Target Title</th><th>Relationship</th><th>Required Subject</th><th>Required Title</th></tr></thead><tbody>' + rows + '</tbody></table></div></section>';
    }

    function electivesSection(groups) {
        const rows = [];
        groups.forEach(function (group) {
            if (!group.subjects.length) {
                rows.push('<tr><td>' + value(group.group_name) + '</td><td>' + value(group.description) + '</td><td>&mdash;</td><td>&mdash;</td></tr>');
            }
            group.subjects.forEach(function (subject) {
                rows.push('<tr><td>' + value(group.group_name) + '</td><td>' + value(group.description) + '</td><td>' + value(subject.subject_code) + '</td><td>' + value(subject.subject_title) + '</td></tr>');
            });
        });
        const body = rows.length ? rows.join('') : '<tr><td colspan="4" class="empty-report-cell">No elective groups are configured for this curriculum version.</td></tr>';
        return '<section class="export-report-section"><h2>Elective Groups</h2><p class="export-section-note">This section reports stored elective-group membership only.</p><div class="export-table-scroll"><table class="export-report-table"><thead><tr><th>Group</th><th>Description</th><th>Subject Code</th><th>Subject Title</th></tr></thead><tbody>' + body + '</tbody></table></div></section>';
    }

    function weightingSection(schemes) {
        const rows = [];
        schemes.forEach(function (scheme) {
            if (!scheme.components.length) {
                rows.push('<tr><td>' + value(scheme.subject_code) + '</td><td>' + value(scheme.subject_title) + '</td><td>&mdash;</td><td>&mdash;</td><td class="number-cell">' + units(scheme.total_weight) + '%</td><td>' + ui.escapeHtml(scheme.completion_status) + '</td></tr>');
            }
            scheme.components.forEach(function (component) {
                rows.push('<tr><td>' + value(scheme.subject_code) + '</td><td>' + value(scheme.subject_title) + '</td><td>' + value(component.component_name) + '</td><td class="number-cell">' + units(component.weight_percentage) + '%</td><td class="number-cell">' + units(scheme.total_weight) + '%</td><td>' + ui.escapeHtml(scheme.completion_status) + '</td></tr>');
            });
        });
        const body = rows.length ? rows.join('') : '<tr><td colspan="6" class="empty-report-cell">No grade-weighting configurations are stored for this curriculum version.</td></tr>';
        return '<section class="export-report-section"><h2>Grade Weighting Configuration</h2><div class="export-table-scroll"><table class="export-report-table"><thead><tr><th>Subject Code</th><th>Subject Title</th><th>Component</th><th>Weight</th><th>Total</th><th>Technical Status</th></tr></thead><tbody>' + body + '</tbody></table></div></section>';
    }

    function complianceSection(run) {
        if (!run) {
            return '<section class="export-report-section"><h2>Latest Stored Compliance Validation Summary</h2><p class="export-section-note">No stored validation run is available for this curriculum version.</p></section>';
        }
        return '<section class="export-report-section"><h2>Latest Stored Compliance Validation Summary</h2><p class="export-section-note">This is stored internal decision-support output and is not an official regulatory determination.</p><div class="export-table-scroll"><table class="export-report-table"><thead><tr><th>Run Date</th><th>Rules Evaluated</th><th>Passed</th><th>Failed</th><th>Not Evaluated</th><th>Run By</th></tr></thead><tbody><tr><td>' + value(run.created_at) + '</td><td class="number-cell">' + value(run.rules_evaluated) + '</td><td class="number-cell">' + value(run.passed_count) + '</td><td class="number-cell">' + value(run.failed_count) + '</td><td class="number-cell">' + value(run.not_evaluated_count) + '</td><td>' + value(run.run_by_name) + '</td></tr></tbody></table></div></section>';
    }

    function renderReport(data) {
        const context = data.context;
        let optional = '';
        if (data.options.requirements) optional += requirementsSection(data.requirements);
        if (data.options.electives) optional += electivesSection(data.elective_groups);
        if (data.options.grade_weighting) optional += weightingSection(data.grade_weighting);
        if (data.options.compliance) optional += complianceSection(data.latest_compliance_run);
        preview.innerHTML = '<article class="export-report">' +
            '<header class="export-report-header"><p class="export-report-kicker">Generic system report</p><h1 class="export-report-title">' + value(context.curriculum_name) + '</h1><p class="export-report-subtitle">Curriculum &amp; Subject Management</p></header>' +
            '<div class="export-context-summary">' + contextItems(context) + '</div>' +
            '<div class="export-unit-summary"><div><span>Subjects</span><strong>' + value(data.summary.total_subjects) + '</strong></div><div><span>Total Units</span><strong>' + units(data.summary.total_units) + '</strong></div><div><span>Lecture Units</span><strong>' + units(data.summary.total_lecture_units) + '</strong></div><div><span>Laboratory Units</span><strong>' + units(data.summary.total_laboratory_units) + '</strong></div></div>' +
            subjectsSection(data.subjects) + optional +
            '<p class="export-report-notice">' + ui.escapeHtml(data.disclaimer) + '</p><p class="export-generated-at">Generated ' + value(data.generated_at) + '</p></article>';
    }

    async function loadPrograms() {
        resetSelect(program, 'Loading programs...', true);
        try {
            const payload = await ui.fetchJson(endpoint, { action: 'program_options', page: 1, per_page: 50 });
            program.innerHTML = optionMarkup('', 'Select an academic program') + payload.programs.map(function (item) {
                return optionMarkup(item.id, item.program_code + ' — ' + item.program_name);
            }).join('');
            program.disabled = payload.programs.length === 0;
            if (!payload.programs.length) status.textContent = 'No academic programs are available.';
        } catch (error) {
            resetSelect(program, 'Programs unavailable', true);
            ui.showToast(error.message, 'error');
        }
    }

    async function loadCurricula() {
        resetSelect(curriculum, program.value ? 'Loading curricula...' : 'Select a program first', true);
        resetSelect(version, 'Select a curriculum first', true);
        setActionsEnabled(false);
        if (!program.value) return;
        try {
            const payload = await ui.fetchJson(endpoint, { action: 'curriculum_options', academic_program_id: program.value, page: 1, per_page: 50 });
            curriculum.innerHTML = optionMarkup('', 'Select a curriculum') + payload.curricula.map(function (item) {
                return optionMarkup(item.id, item.curriculum_code + ' — ' + item.curriculum_name);
            }).join('');
            curriculum.disabled = payload.curricula.length === 0;
            if (!payload.curricula.length) status.textContent = 'No curricula are available for the selected program.';
        } catch (error) {
            resetSelect(curriculum, 'Curricula unavailable', true);
            ui.showToast(error.message, 'error');
        }
    }

    async function loadVersions() {
        resetSelect(version, curriculum.value ? 'Loading versions...' : 'Select a curriculum first', true);
        setActionsEnabled(false);
        if (!curriculum.value) return;
        try {
            const payload = await ui.fetchJson(endpoint, { action: 'version_options', curriculum_id: curriculum.value, page: 1, per_page: 50 });
            version.innerHTML = optionMarkup('', 'Select a curriculum version') + payload.versions.map(function (item) {
                const year = item.effective_academic_year ? ' / ' + item.effective_academic_year : '';
                return optionMarkup(item.id, item.version_label + year);
            }).join('');
            version.disabled = payload.versions.length === 0;
            if (!payload.versions.length) status.textContent = 'No curriculum versions are available.';
        } catch (error) {
            resetSelect(version, 'Versions unavailable', true);
            ui.showToast(error.message, 'error');
        }
    }

    async function previewReport() {
        previewButton.disabled = true;
        status.textContent = 'Loading report preview…';
        try {
            const payload = await ui.fetchJson(endpoint, Object.assign({ action: 'preview' }, exportParameters()));
            renderReport(payload);
            status.textContent = 'Preview generated from current stored data.';
        } catch (error) {
            ui.showToast(error.message, 'error');
            status.textContent = 'The preview could not be generated.';
        } finally {
            previewButton.disabled = !version.value;
        }
    }

    function filenameFromHeader(header) {
        const match = /filename="?([^";]+)"?/i.exec(header || '');
        return match ? match[1].replace(/[^A-Za-z0-9._-]/g, '_') : 'curriculum_export.csv';
    }

    async function downloadCsv() {
        csvButton.disabled = true;
        status.textContent = 'Generating CSV…';
        const form = new FormData();
        Object.entries(Object.assign({ action: 'download_csv' }, exportParameters())).forEach(function ([key, item]) {
            form.set(key, item);
        });
        form.set('_csrf_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
        try {
            const response = await fetch(endpoint, { method: 'POST', body: form, credentials: 'same-origin' });
            if (!response.ok) {
                let message = 'The CSV could not be generated.';
                try { message = (await response.json()).message || message; } catch (error) { /* generic fallback */ }
                throw new Error(message);
            }
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filenameFromHeader(response.headers.get('Content-Disposition'));
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
            status.textContent = 'CSV generated from current stored data.';
            ui.showToast('Curriculum CSV generated.', 'success');
        } catch (error) {
            status.textContent = 'The CSV could not be generated.';
            ui.showToast(error.message, 'error');
        } finally {
            csvButton.disabled = !version.value;
        }
    }

    function openPrintView() {
        const url = new URL(printPage, window.location.href);
        Object.entries(exportParameters()).forEach(function ([key, item]) { url.searchParams.set(key, item); });
        const printWindow = window.open(url.toString(), '_blank', 'noopener');
        if (!printWindow) ui.showToast('Allow pop-ups to open the print view.', 'warning');
    }

    program.addEventListener('change', loadCurricula);
    curriculum.addEventListener('change', loadVersions);
    version.addEventListener('change', function () { setActionsEnabled(Boolean(version.value)); });
    Object.values(optionInputs).forEach(function (input) {
        input.addEventListener('change', function () {
            if (version.value) status.textContent = 'Report options changed. Preview again to refresh the report.';
        });
    });
    previewButton.addEventListener('click', previewReport);
    csvButton.addEventListener('click', downloadCsv);
    printButton.addEventListener('click', openPrintView);

    setActionsEnabled(false);
    loadPrograms();
}());
