(function () {
    'use strict';

    const workspace = document.getElementById('complianceWorkspace');
    const ui = window.CurriculumUI;
    if (!workspace || !ui) return;

    const endpoint = workspace.dataset.complianceApi;
    const activeView = workspace.dataset.complianceView;
    const canManage = workspace.dataset.canManage === '1';
    const ruleColumns = Number(workspace.dataset.ruleColumns || 7);
    const referenceColumns = Number(workspace.dataset.referenceColumns || 6);
    const state = {
        rulePage: 1,
        referencePage: 1,
        historyPage: 1,
        rules: new Map(),
        references: new Map(),
        lockedRule: null,
    };

    const program = document.getElementById('complianceProgram');
    const curriculum = document.getElementById('complianceCurriculum');
    const version = document.getElementById('complianceVersion');
    const runButton = document.getElementById('runComplianceValidation');
    const ruleRows = document.getElementById('complianceRuleRows');
    const referenceRows = document.getElementById('complianceReferenceRows');
    const historyRows = document.getElementById('complianceHistoryRows');
    const ruleForm = document.getElementById('complianceRuleForm');
    const referenceForm = document.getElementById('complianceReferenceForm');
    const ruleModal = ui.createModal(document.getElementById('complianceRuleModal'));
    const referenceModal = ui.createModal(document.getElementById('complianceReferenceModal'));
    const runModal = ui.createModal(document.getElementById('complianceRunModal'));

    function optionMarkup(value, label) {
        return '<option value="' + ui.escapeHtml(value) + '">' + ui.escapeHtml(label) + '</option>';
    }

    function resetSelect(select, label, disabled) {
        if (!select) return;
        select.innerHTML = optionMarkup('', label);
        select.disabled = disabled;
    }

    function selectedLabel(select) {
        return select?.value ? (select.options[select.selectedIndex]?.textContent.trim() || '') : '';
    }

    function tableState(element, columns, message, isError) {
        if (!element) return;
        element.innerHTML = '<tr><td colspan="' + columns + '" class="empty-cell' +
            (isError ? ' error-cell' : '') + '">' + ui.escapeHtml(message) + '</td></tr>';
    }

    function badge(value, label) {
        const normalized = String(value || '').toLowerCase().replaceAll('_', '-');
        const icons = {
            pass: 'fa-circle-check', fail: 'fa-circle-xmark', 'not-evaluated': 'fa-circle-minus',
            verified: 'fa-circle-check', draft: 'fa-pen-ruler', inactive: 'fa-circle-pause',
            recorded: 'fa-clock-rotate-left',
            ched: 'fa-building-columns', deped: 'fa-school', institutional: 'fa-building', other: 'fa-book'
        };
        return '<span class="compliance-badge ' + ui.escapeHtml(normalized) + '"><i class="fa-solid ' +
            (icons[normalized] || 'fa-circle') + '"></i> ' + ui.escapeHtml(label || value) + '</span>';
    }

    async function loadPrograms() {
        resetSelect(program, 'Loading programs...', true);
        try {
            const payload = await ui.fetchJson(endpoint, { action: 'program_options', page: 1, per_page: 50 });
            program.innerHTML = optionMarkup('', 'Select an academic program') + payload.programs.map(function (item) {
                return optionMarkup(item.id, item.program_code + ' - ' + item.program_name);
            }).join('');
            program.disabled = payload.programs.length === 0;
        } catch (error) {
            resetSelect(program, 'Programs unavailable', true);
            ui.showToast(error.message, 'error');
        }
    }

    async function loadCurricula() {
        resetSelect(curriculum, program.value ? 'Loading curricula...' : 'Select a program first', true);
        resetSelect(version, 'Select a curriculum first', true);
        runButton.disabled = true;
        clearValidation('Select a curriculum version to run configured checks.');
        if (!program.value) return;
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'curriculum_options', academic_program_id: program.value, page: 1, per_page: 50
            });
            curriculum.innerHTML = optionMarkup('', 'Select a curriculum') + payload.curricula.map(function (item) {
                return optionMarkup(item.id, item.curriculum_code + ' - ' + item.curriculum_name);
            }).join('');
            curriculum.disabled = payload.curricula.length === 0;
        } catch (error) {
            resetSelect(curriculum, 'Curricula unavailable', true);
            ui.showToast(error.message, 'error');
        }
    }

    async function loadVersions() {
        resetSelect(version, curriculum.value ? 'Loading versions...' : 'Select a curriculum first', true);
        runButton.disabled = true;
        clearValidation('Select a curriculum version to run configured checks.');
        if (!curriculum.value) return;
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'version_options', curriculum_id: curriculum.value, page: 1, per_page: 50
            });
            version.innerHTML = optionMarkup('', 'Select a curriculum version') + payload.versions.map(function (item) {
                const year = item.effective_academic_year ? ' / ' + item.effective_academic_year : '';
                return optionMarkup(item.id, item.version_label + year);
            }).join('');
            version.disabled = payload.versions.length === 0;
        } catch (error) {
            resetSelect(version, 'Versions unavailable', true);
            ui.showToast(error.message, 'error');
        }
    }

    function validationContextLabel() {
        return [selectedLabel(program), selectedLabel(curriculum), selectedLabel(version)].filter(Boolean).join(' / ');
    }

    function clearValidation(message) {
        document.getElementById('complianceSummary').hidden = true;
        document.getElementById('validationContextMessage').textContent = message;
        tableState(document.getElementById('complianceResultRows'), 6, 'No validation has been run in this workspace.', false);
    }

    function resultRow(result) {
        const source = result.reference_url_snapshot
            ? '<a class="compliance-reference-link" href="' + ui.escapeHtml(result.reference_url_snapshot) + '" target="_blank" rel="noopener noreferrer">' + ui.escapeHtml(result.reference_identifier_snapshot) + ' <i class="fa-solid fa-arrow-up-right-from-square"></i></a>'
            : '<span class="compliance-subtext">' + ui.escapeHtml(result.reference_identifier_snapshot) + '</span>';
        return '<tr><td><span class="compliance-name">' + ui.escapeHtml(result.rule_name_snapshot) + '</span><span class="compliance-subtext">' + ui.escapeHtml(ruleTypeLabel(result.rule_type_snapshot)) + '</span></td>' +
            '<td>' + source + '</td><td>' + ui.escapeHtml(result.requirement_snapshot) + '</td>' +
            '<td>' + ui.escapeHtml(result.actual_value_snapshot) + '</td><td>' + badge(result.result_status, resultLabel(result.result_status)) + '</td>' +
            '<td><span class="compliance-subtext">' + ui.escapeHtml(result.explanation) + '</span></td></tr>';
    }

    function renderSummary(summary, container) {
        const values = [
            ['Rules Evaluated', summary.rules_evaluated], ['Pass', summary.passed],
            ['Fail', summary.failed], ['Not Evaluated', summary.not_evaluated]
        ];
        if (container) {
            container.innerHTML = values.map(function (item, index) {
                return '<div class="' + (index === 1 ? 'is-pass' : index === 2 ? 'is-fail' : index === 3 ? 'is-neutral' : '') + '"><span>' + item[0] + '</span><strong>' + Number(item[1] || 0) + '</strong></div>';
            }).join('');
            return;
        }
        document.getElementById('summaryEvaluated').textContent = summary.rules_evaluated;
        document.getElementById('summaryPassed').textContent = summary.passed;
        document.getElementById('summaryFailed').textContent = summary.failed;
        document.getElementById('summaryNotEvaluated').textContent = summary.not_evaluated;
        document.getElementById('complianceSummary').hidden = false;
    }

    async function runValidation() {
        if (!version.value) return;
        runButton.disabled = true;
        runButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Running...';
        const data = new FormData();
        data.set('action', 'validation_run');
        data.set('curriculum_version_id', version.value);
        try {
            const payload = await ui.postForm(endpoint, data);
            renderSummary(payload.summary);
            document.getElementById('validationContextMessage').textContent = validationContextLabel();
            document.getElementById('complianceResultRows').innerHTML = payload.results.length
                ? payload.results.map(resultRow).join('')
                : '<tr><td colspan="6" class="empty-cell">' + ui.escapeHtml(payload.message) + '</td></tr>';
            ui.showToast(payload.message, payload.run ? 'success' : 'warning');
            if (payload.run && historyRows) loadHistory(1);
        } catch (error) {
            ui.showToast(error.message, 'error');
        } finally {
            runButton.disabled = !version.value;
            runButton.innerHTML = '<i class="fa-solid fa-play"></i> Run Validation';
        }
    }

    function ruleTypeLabel(type) {
        return ({
            MIN_TOTAL_UNITS: 'Minimum total units', MAX_TOTAL_UNITS: 'Maximum total units',
            REQUIRED_SUBJECT: 'Required subject', MIN_SUBJECT_UNITS: 'Minimum subject units',
            MAX_SUBJECT_UNITS: 'Maximum subject units'
        })[type] || type;
    }

    function resultLabel(status) {
        return status === 'NOT_EVALUATED' ? 'Not Evaluated' : status === 'PASS' ? 'Pass' : 'Fail';
    }

    function formatDateTime(value) {
        if (!value) return '-';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return String(value);
        return new Intl.DateTimeFormat(undefined, {
            year: 'numeric', month: 'short', day: 'numeric',
            hour: 'numeric', minute: '2-digit'
        }).format(date);
    }

    function ruleParameter(rule) {
        const subject = rule.subject_code
            ? '<span class="compliance-name">' + ui.escapeHtml(rule.subject_code) + '</span><span class="compliance-subtext">' + ui.escapeHtml(rule.subject_title) + '</span>' : '';
        const numeric = rule.numeric_value !== null
            ? '<span class="compliance-subtext">Threshold: ' + ui.escapeHtml(ui.formatUnits(rule.numeric_value)) + '</span>' : '';
        return subject + numeric || '<span class="compliance-subtext">No configured parameter</span>';
    }

    function ruleRow(rule) {
        const scope = rule.academic_program_id
            ? rule.program_code + ' - ' + rule.program_name
            : rule.curriculum_code + ' - ' + rule.curriculum_name;
        const actions = canManage ? '<td><div class="compliance-row-actions"><button type="button" class="row-action-button" data-edit-compliance-rule="' + Number(rule.id) + '"><i class="fa-solid fa-pen"></i> Edit</button></div></td>' : '';
        return '<tr><td><span class="compliance-name">' + ui.escapeHtml(rule.rule_name) + '</span><span class="compliance-subtext">' + ui.escapeHtml(rule.description || 'No description') + '</span></td>' +
            '<td><span class="compliance-name">' + ui.escapeHtml(rule.reference_code || rule.reference_title || 'Reference') + '</span><span class="compliance-subtext">' + ui.escapeHtml(rule.issuing_body) + ' / ' + badge(rule.verification_status, rule.verification_status) + '</span></td>' +
            '<td>' + ui.escapeHtml(ruleTypeLabel(rule.rule_type)) + '</td><td><span class="compliance-subtext">' + ui.escapeHtml(scope) + '</span></td>' +
            '<td>' + ruleParameter(rule) + '</td><td>' + ui.statusBadge(rule.technical_status) + '</td><td>' + (Number(rule.has_history) ? badge('recorded', 'Recorded') : badge('inactive', 'Not yet used')) + '</td>' + actions + '</tr>';
    }

    async function loadRules(page) {
        state.rulePage = Number(page) || 1;
        tableState(ruleRows, ruleColumns, 'Loading compliance rules...', false);
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'rule_list', page: state.rulePage, per_page: document.getElementById('rulePerPage').value,
                search: document.getElementById('ruleSearch').value.trim(),
                rule_type: document.getElementById('ruleTypeFilter').value,
                technical_status: document.getElementById('ruleStatusFilter').value
            });
            state.rulePage = Number(payload.pagination.page);
            state.rules.clear();
            payload.rules.forEach(function (item) { state.rules.set(Number(item.id), item); });
            ruleRows.innerHTML = payload.rules.length ? payload.rules.map(ruleRow).join('') : '<tr><td colspan="' + ruleColumns + '" class="empty-cell">No compliance rules found.</td></tr>';
            ui.renderPagination(document.getElementById('rulePagination'), payload.pagination, loadRules);
        } catch (error) {
            tableState(ruleRows, ruleColumns, error.message, true);
            ui.showToast(error.message, 'error');
        }
    }

    function referenceRow(reference) {
        const source = reference.source_url
            ? '<a class="compliance-reference-link" href="' + ui.escapeHtml(reference.source_url) + '" target="_blank" rel="noopener noreferrer">Open source <i class="fa-solid fa-arrow-up-right-from-square"></i></a>' : '<span class="compliance-subtext">No URL stored</span>';
        const actions = canManage ? '<td><button type="button" class="row-action-button" data-edit-compliance-reference="' + Number(reference.id) + '"><i class="fa-solid fa-pen"></i> Edit</button></td>' : '';
        return '<tr><td><span class="compliance-name">' + ui.escapeHtml(reference.reference_code || reference.title || 'Reference') + '</span><span class="compliance-subtext">' + ui.escapeHtml(reference.title || 'No title') + (reference.effective_date ? ' / ' + ui.escapeHtml(reference.effective_date) : '') + '</span></td>' +
            '<td>' + badge(reference.reference_type, reference.reference_type === 'DEPED' ? 'DepEd' : reference.reference_type) + '</td><td>' + ui.escapeHtml(reference.issuing_body) + '</td>' +
            '<td>' + badge(reference.verification_status, reference.verification_status) + '</td><td>' + source + '</td><td>' + Number(reference.rule_count || 0) + '</td>' + actions + '</tr>';
    }

    async function loadReferences(page) {
        state.referencePage = Number(page) || 1;
        tableState(referenceRows, referenceColumns, 'Loading compliance references...', false);
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'reference_list', page: state.referencePage, per_page: document.getElementById('referencePerPage').value,
                search: document.getElementById('referenceSearch').value.trim(),
                reference_type: document.getElementById('referenceTypeFilter').value,
                verification_status: document.getElementById('referenceStatusFilter').value
            });
            state.referencePage = Number(payload.pagination.page);
            state.references.clear();
            payload.references.forEach(function (item) { state.references.set(Number(item.id), item); });
            referenceRows.innerHTML = payload.references.length ? payload.references.map(referenceRow).join('') : '<tr><td colspan="' + referenceColumns + '" class="empty-cell">No compliance references found.</td></tr>';
            ui.renderPagination(document.getElementById('referencePagination'), payload.pagination, loadReferences);
        } catch (error) {
            tableState(referenceRows, referenceColumns, error.message, true);
            ui.showToast(error.message, 'error');
        }
    }

    function historyRow(run) {
        const runner = [run.run_by_first_name, run.run_by_last_name].filter(Boolean).join(' ') || run.run_by_username || 'Unknown user';
        return '<tr><td>' + ui.escapeHtml(formatDateTime(run.created_at)) + '</td><td><span class="compliance-name">' + ui.escapeHtml(run.program_code) + '</span><span class="compliance-subtext">' + ui.escapeHtml(run.program_name) + '</span></td>' +
            '<td><span class="compliance-name">' + ui.escapeHtml(run.curriculum_code) + '</span><span class="compliance-subtext">' + ui.escapeHtml(run.curriculum_name) + '</span></td><td>' + ui.escapeHtml(run.version_label) + '</td>' +
            '<td>' + Number(run.rules_evaluated) + '</td><td>' + Number(run.passed_count) + '</td><td>' + Number(run.failed_count) + '</td><td>' + Number(run.not_evaluated_count) + '</td><td>' + ui.escapeHtml(runner) + '</td>' +
            '<td><button type="button" class="row-action-button" data-view-compliance-run="' + Number(run.id) + '"><i class="fa-solid fa-eye"></i> View</button></td></tr>';
    }

    async function loadHistory(page) {
        state.historyPage = Number(page) || 1;
        tableState(historyRows, 10, 'Loading validation history...', false);
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'history_list', page: state.historyPage, per_page: document.getElementById('historyPerPage').value,
                search: document.getElementById('historySearch').value.trim()
            });
            state.historyPage = Number(payload.pagination.page);
            historyRows.innerHTML = payload.runs.length ? payload.runs.map(historyRow).join('') : '<tr><td colspan="10" class="empty-cell">No validation runs found.</td></tr>';
            ui.renderPagination(document.getElementById('historyPagination'), payload.pagination, loadHistory);
        } catch (error) {
            tableState(historyRows, 10, error.message, true);
            ui.showToast(error.message, 'error');
        }
    }

    async function viewRun(id) {
        try {
            const payload = await ui.fetchJson(endpoint, { action: 'run_get', id: id });
            const run = payload.run;
            document.getElementById('complianceRunModalContext').textContent = run.program_code + ' / ' + run.curriculum_code + ' / ' + run.version_label + ' / ' + formatDateTime(run.created_at);
            renderSummary({ rules_evaluated: run.rules_evaluated, passed: run.passed_count, failed: run.failed_count, not_evaluated: run.not_evaluated_count }, document.getElementById('historyRunSummary'));
            document.getElementById('historyRunResultRows').innerHTML = run.results.length ? run.results.map(resultRow).join('') : '<tr><td colspan="6" class="empty-cell">No stored results.</td></tr>';
            runModal.open();
        } catch (error) {
            ui.showToast(error.message, 'error');
        }
    }

    async function optionData(action, extra) {
        return ui.fetchJson(endpoint, Object.assign({ action: action, page: 1, per_page: 50 }, extra || {}));
    }

    async function fillRuleOptions() {
        const referenceSelect = document.getElementById('complianceRuleReference');
        const subjectSelect = document.getElementById('complianceRuleSubject');
        const [referencePayload, subjectPayload] = await Promise.all([
            optionData('reference_list'), optionData('subject_options')
        ]);
        referenceSelect.innerHTML = optionMarkup('', 'Select a compliance reference') + referencePayload.references.map(function (item) {
            return optionMarkup(item.id, '[' + item.reference_type + '] ' + (item.reference_code || item.title || 'Reference') + ' - ' + item.issuing_body);
        }).join('');
        subjectSelect.innerHTML = optionMarkup('', 'Select a Subject Master record') + subjectPayload.subjects.map(function (item) {
            return optionMarkup(item.id, item.subject_code + ' - ' + item.subject_title + ' (' + ui.formatUnits(item.units) + ' units)');
        }).join('');
    }

    async function fillScopeOptions(selected) {
        const scopeType = document.getElementById('complianceRuleScopeType').value;
        const scope = document.getElementById('complianceRuleScope');
        resetSelect(scope, 'Loading scope records...', true);
        try {
            const payload = await optionData(scopeType === 'program' ? 'program_options' : 'curriculum_options');
            const records = scopeType === 'program' ? payload.programs : payload.curricula;
            scope.innerHTML = optionMarkup('', scopeType === 'program' ? 'Select an academic program' : 'Select a curriculum') + records.map(function (item) {
                return optionMarkup(item.id, scopeType === 'program' ? item.program_code + ' - ' + item.program_name : item.curriculum_code + ' - ' + item.curriculum_name);
            }).join('');
            scope.disabled = false;
            if (selected) scope.value = String(selected);
        } catch (error) {
            resetSelect(scope, 'Scope records unavailable', true);
            ui.showToast(error.message, 'error');
        }
    }

    function updateRuleFields() {
        const type = document.getElementById('complianceRuleType').value;
        const numeric = ['MIN_TOTAL_UNITS', 'MAX_TOTAL_UNITS', 'MIN_SUBJECT_UNITS', 'MAX_SUBJECT_UNITS'].includes(type);
        const subject = ['REQUIRED_SUBJECT', 'MIN_SUBJECT_UNITS', 'MAX_SUBJECT_UNITS'].includes(type);
        document.getElementById('complianceNumericField').hidden = !numeric;
        document.getElementById('complianceRuleNumeric').required = numeric;
        document.getElementById('complianceSubjectField').hidden = !subject;
        document.getElementById('complianceRuleSubject').required = subject;
    }

    function setSemanticLock(locked) {
        ['complianceRuleReference', 'complianceRuleScopeType', 'complianceRuleScope', 'complianceRuleType', 'complianceRuleNumeric', 'complianceRuleSubject'].forEach(function (id) {
            const field = document.getElementById(id);
            if (field) field.disabled = locked;
        });
        document.getElementById('complianceRuleHistoryNotice').hidden = !locked;
    }

    async function openRuleForm(rule) {
        if (!canManage || !ruleForm || !ruleModal) return;
        ruleForm.reset();
        ui.clearFormError(ruleForm);
        setSemanticLock(false);
        state.lockedRule = rule && Number(rule.has_history) ? rule : null;
        try {
            await fillRuleOptions();
            document.getElementById('complianceRuleId').value = rule ? rule.id : '';
            document.getElementById('complianceRuleName').value = rule ? rule.rule_name : '';
            document.getElementById('complianceRuleReference').value = rule ? rule.compliance_reference_id : '';
            document.getElementById('complianceRuleStatus').value = rule ? rule.technical_status : 'active';
            document.getElementById('complianceRuleScopeType').value = rule && rule.curriculum_id ? 'curriculum' : 'program';
            await fillScopeOptions(rule ? (rule.curriculum_id || rule.academic_program_id) : null);
            document.getElementById('complianceRuleType').value = rule ? rule.rule_type : 'MIN_TOTAL_UNITS';
            document.getElementById('complianceRuleNumeric').value = rule?.numeric_value ?? '';
            document.getElementById('complianceRuleSubject').value = rule?.subject_id ?? '';
            document.getElementById('complianceRuleDescription').value = rule?.description ?? '';
            updateRuleFields();
            setSemanticLock(Boolean(state.lockedRule));
            document.getElementById('complianceRuleModalTitle').textContent = rule ? 'Edit Compliance Rule' : 'New Compliance Rule';
            document.getElementById('saveComplianceRule').textContent = rule ? 'Save Rule' : 'Create Rule';
            ruleModal.open();
        } catch (error) {
            ui.showToast(error.message, 'error');
        }
    }

    function openReferenceForm(reference) {
        if (!canManage || !referenceForm || !referenceModal) return;
        referenceForm.reset();
        ui.clearFormError(referenceForm);
        document.getElementById('complianceReferenceId').value = reference ? reference.id : '';
        document.getElementById('complianceReferenceType').value = reference ? reference.reference_type : 'CHED';
        document.getElementById('complianceReferenceCode').value = reference?.reference_code ?? '';
        document.getElementById('complianceReferenceTitle').value = reference?.title ?? '';
        document.getElementById('complianceReferenceIssuer').value = reference?.issuing_body ?? '';
        document.getElementById('complianceReferenceDate').value = reference?.effective_date ?? '';
        document.getElementById('complianceReferenceUrl').value = reference?.source_url ?? '';
        document.getElementById('complianceReferenceStatus').value = reference ? reference.verification_status : 'draft';
        document.getElementById('complianceReferenceNotes').value = reference?.notes ?? '';
        document.getElementById('complianceReferenceModalTitle').textContent = reference ? 'Edit Compliance Reference' : 'New Compliance Reference';
        document.getElementById('saveComplianceReference').textContent = reference ? 'Save Reference' : 'Create Reference';
        referenceModal.open();
    }

    async function submitReference(event) {
        event.preventDefault();
        if (!referenceForm.reportValidity()) return;
        ui.clearFormError(referenceForm);
        const data = new FormData(referenceForm);
        const edit = Boolean(data.get('id'));
        data.set('action', edit ? 'reference_update' : 'reference_create');
        ui.setFormSubmitting(referenceForm, true, edit ? 'Saving...' : 'Creating...');
        try {
            const payload = await ui.postForm(endpoint, data);
            referenceModal.close();
            ui.showToast(payload.message, edit ? 'updated' : 'success');
            const refreshes = [];
            if (referenceRows) refreshes.push(loadReferences(state.referencePage));
            if (ruleRows) refreshes.push(loadRules(state.rulePage));
            await Promise.all(refreshes);
        } catch (error) {
            ui.showFormError(referenceForm, error.message);
        } finally {
            ui.setFormSubmitting(referenceForm, false);
        }
    }

    async function submitRule(event) {
        event.preventDefault();
        if (!ruleForm.reportValidity()) return;
        ui.clearFormError(ruleForm);
        const data = new FormData(ruleForm);
        const edit = Boolean(document.getElementById('complianceRuleId').value);
        data.set('id', document.getElementById('complianceRuleId').value);
        if (state.lockedRule) {
            const locked = state.lockedRule;
            data.set('compliance_reference_id', locked.compliance_reference_id);
            data.set('scope_type', locked.curriculum_id ? 'curriculum' : 'program');
            data.set('scope_id', locked.curriculum_id || locked.academic_program_id);
            data.set('rule_type', locked.rule_type);
            data.set('numeric_value', locked.numeric_value ?? '');
            data.set('subject_id', locked.subject_id ?? '');
        }
        data.set('action', edit ? 'rule_update' : 'rule_create');
        ui.setFormSubmitting(ruleForm, true, edit ? 'Saving...' : 'Creating...');
        try {
            const payload = await ui.postForm(endpoint, data);
            ruleModal.close();
            ui.showToast(payload.message, edit ? 'updated' : 'success');
            await loadRules(state.rulePage);
        } catch (error) {
            ui.showFormError(ruleForm, error.message);
        } finally {
            ui.setFormSubmitting(ruleForm, false);
        }
    }

    program?.addEventListener('change', loadCurricula);
    curriculum?.addEventListener('change', loadVersions);
    version?.addEventListener('change', function () { runButton.disabled = !version.value; clearValidation(version.value ? 'Ready to validate ' + validationContextLabel() + '.' : 'Select a curriculum version to run configured checks.'); });
    runButton?.addEventListener('click', runValidation);

    const debouncedRules = ui.debounce(function () { loadRules(1); }, 300);
    const debouncedReferences = ui.debounce(function () { loadReferences(1); }, 300);
    const debouncedHistory = ui.debounce(function () { loadHistory(1); }, 300);
    document.getElementById('ruleSearch')?.addEventListener('input', debouncedRules);
    document.getElementById('ruleTypeFilter')?.addEventListener('change', function () { loadRules(1); });
    document.getElementById('ruleStatusFilter')?.addEventListener('change', function () { loadRules(1); });
    document.getElementById('rulePerPage')?.addEventListener('change', function () { loadRules(1); });
    document.getElementById('referenceSearch')?.addEventListener('input', debouncedReferences);
    document.getElementById('referenceTypeFilter')?.addEventListener('change', function () { loadReferences(1); });
    document.getElementById('referenceStatusFilter')?.addEventListener('change', function () { loadReferences(1); });
    document.getElementById('referencePerPage')?.addEventListener('change', function () { loadReferences(1); });
    document.getElementById('historySearch')?.addEventListener('input', debouncedHistory);
    document.getElementById('historyPerPage')?.addEventListener('change', function () { loadHistory(1); });

    ruleRows?.addEventListener('click', function (event) {
        const button = event.target.closest('[data-edit-compliance-rule]');
        if (button) openRuleForm(state.rules.get(Number(button.dataset.editComplianceRule)));
    });
    referenceRows?.addEventListener('click', function (event) {
        const button = event.target.closest('[data-edit-compliance-reference]');
        if (button) openReferenceForm(state.references.get(Number(button.dataset.editComplianceReference)));
    });
    historyRows?.addEventListener('click', function (event) {
        const button = event.target.closest('[data-view-compliance-run]');
        if (button) viewRun(Number(button.dataset.viewComplianceRun));
    });

    if (canManage) {
        document.getElementById('newComplianceRule')?.addEventListener('click', function () { openRuleForm(null); });
        document.getElementById('newComplianceReference')?.addEventListener('click', function () { openReferenceForm(null); });
        document.getElementById('complianceRuleScopeType')?.addEventListener('change', function () { fillScopeOptions(null); });
        document.getElementById('complianceRuleType')?.addEventListener('change', updateRuleFields);
        referenceForm?.addEventListener('submit', submitReference);
        ruleForm?.addEventListener('submit', submitRule);
    }

    if (activeView === 'validate') loadPrograms();
    if (activeView === 'rules') loadRules(1);
    if (activeView === 'references') loadReferences(1);
    if (activeView === 'history') loadHistory(1);
}());
