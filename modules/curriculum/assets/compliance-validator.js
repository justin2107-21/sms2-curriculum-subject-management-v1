(function () {
    'use strict';

    const workspace = document.getElementById('complianceWorkspace');
    const ui = window.CurriculumUI;
    if (!workspace || !ui) return;

    const endpoint = workspace.dataset.complianceApi;
    const activeView = workspace.dataset.complianceView;
    const canRun = workspace.dataset.canRun === '1';
    const canManage = workspace.dataset.canManage === '1';
    const canVerifyReferences = workspace.dataset.canVerifyReferences === '1';
    const ruleColumns = Number(workspace.dataset.ruleColumns || 7);
    const referenceColumns = Number(workspace.dataset.referenceColumns || 6);
    const state = {
        rulePage: 1,
        referencePage: 1,
        historyPage: 1,
        ruleRequest: 0,
        referenceRequest: 0,
        historyRequest: 0,
        rules: new Map(),
        references: new Map(),
        lockedRule: null,
    };

    let cimfRunId = '';
    window.CSMSPageCIMFContext = () => ({academic_program_id:program?.value || '',curriculum_id:curriculum?.value || '',curriculum_version_id:version?.value || '',run_id:cimfRunId,rule_id:document.getElementById('complianceRuleId')?.value || '',reference_id:document.getElementById('complianceReferenceId')?.value || ''});
    const program = document.getElementById('complianceProgram');
    const curriculum = document.getElementById('complianceCurriculum');
    const version = document.getElementById('complianceVersion');
    const runButton = document.getElementById('runComplianceValidation');
    const ruleRows = document.getElementById('complianceRuleRows');
    const referenceRows = document.getElementById('complianceReferenceRows');
    const historyRows = document.getElementById('complianceHistoryRows');
    const ruleForm = document.getElementById('complianceRuleForm');
    const referenceForm = document.getElementById('complianceReferenceForm');
    const evidenceForm = document.getElementById('complianceEvidenceForm');
    const ruleModal = ui.createModal(document.getElementById('complianceRuleModal'));
    const referenceModal = ui.createModal(document.getElementById('complianceReferenceModal'));
    const evidenceModal = ui.createModal(document.getElementById('complianceEvidenceModal'));
    const runModal = ui.createModal(document.getElementById('complianceRunModal'));
    const referenceAutocomplete = ui.createAutocomplete({
        input: document.getElementById('complianceRuleReferenceInput'),
        valueInput: document.getElementById('complianceRuleReference'),
        list: document.getElementById('complianceRuleReferenceOptions'),
        helpText: 'Search registered compliance references.',
        selectedText: 'Compliance reference selected.',
        loadingText: 'Searching compliance references…',
        noResultsText: 'No matching compliance references found.',
        search: async function (query) {
            const payload = await ui.fetchJson(endpoint, {
                action: 'reference_list', page: 1, per_page: 20, search: query
            });
            return payload.references;
        },
        getId: reference => reference.id,
        format: reference => ({
            label: '[' + reference.reference_type + '] ' +
                (reference.reference_code || reference.title || 'Reference') + ' — ' + reference.issuing_body,
            code: '[' + reference.reference_type + '] ' + (reference.reference_code || 'Reference'),
            title: reference.title || reference.issuing_body,
            meta: reference.issuing_body
        }),
        onError: error => ui.showFormError(ruleForm, error.message || 'Compliance references could not be loaded.')
    });
    const scopeInput = document.getElementById('complianceRuleScopeInput');
    const scopeAutocomplete = ui.createAutocomplete({
        input: scopeInput,
        valueInput: document.getElementById('complianceRuleScope'),
        list: document.getElementById('complianceRuleScopeOptions'),
        helpText: 'Search the selected scope type.',
        selectedText: 'Scope record selected.',
        loadingText: 'Searching scope records…',
        noResultsText: 'No matching scope records found.',
        search: async function (query) {
            const isProgram = document.getElementById('complianceRuleScopeType').value === 'program';
            const payload = await ui.fetchJson(endpoint, {
                action: isProgram ? 'program_options' : 'curriculum_options',
                page: 1, per_page: 20, search: query
            });
            return isProgram ? payload.programs : payload.curricula;
        },
        getId: record => record.id,
        format: function (record) {
            const isProgram = document.getElementById('complianceRuleScopeType').value === 'program';
            const code = isProgram ? record.program_code : record.curriculum_code;
            const title = isProgram ? record.program_name : record.curriculum_name;
            return { label: code + ' — ' + title, code: code, title: title };
        },
        onError: error => ui.showFormError(ruleForm, error.message || 'Scope records could not be loaded.')
    });
    const complianceSubjectInput = document.getElementById('complianceRuleSubjectInput');
    const complianceSubjectAutocomplete = ui.createAutocomplete({
        input: complianceSubjectInput,
        valueInput: document.getElementById('complianceRuleSubject'),
        list: document.getElementById('complianceRuleSubjectOptions'),
        helpText: 'Search for a Subject Master record.',
        selectedText: 'Configured subject selected.',
        loadingText: 'Searching subjects…',
        noResultsText: 'No matching subjects found.',
        errorText: 'Subject Master records could not be loaded.',
        search: async function (query) {
            const payload = await ui.fetchJson(endpoint, {
                action: 'subject_options', page: 1, per_page: 20, search: query
            });
            return payload.subjects;
        },
        getId: subject => subject.id,
        format: subject => ({ label: subject.subject_code + ' — ' + subject.subject_title,
            code: subject.subject_code, title: subject.subject_title })
    });

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
        if (runButton) runButton.disabled = true;
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
        if (runButton) runButton.disabled = true;
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
        cimfRunId = ''; window.CSMSCIMF?.updateContext();
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
        if (!canRun) return;
        if (!version.value) return;
        if (runButton) runButton.disabled = true;
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
            if (payload.run) { cimfRunId = payload.run.id; window.CSMSCIMF?.updateContext(); }
            ui.showToast(payload.message, payload.run ? 'success' : 'warning');
            if (payload.run && historyRows) loadHistory(1);
        } catch (error) {
            ui.showToast(error.message, 'error');
        } finally {
            if (runButton) runButton.disabled = !canRun || !version.value;
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
        const requestId = ++state.ruleRequest;
        state.rulePage = Number(page) || 1;
        tableState(ruleRows, ruleColumns, 'Loading compliance rules...', false);
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'rule_list', page: state.rulePage, per_page: document.getElementById('rulePerPage').value,
                search: document.getElementById('ruleSearch').value.trim(),
                rule_type: document.getElementById('ruleTypeFilter').value,
                technical_status: document.getElementById('ruleStatusFilter').value
            });
            if (requestId !== state.ruleRequest) return;
            state.rulePage = Number(payload.pagination.page);
            state.rules.clear();
            payload.rules.forEach(function (item) { state.rules.set(Number(item.id), item); });
            ruleRows.innerHTML = payload.rules.length ? payload.rules.map(ruleRow).join('') : '<tr><td colspan="' + ruleColumns + '" class="empty-cell">No compliance rules found.</td></tr>';
            ui.renderPagination(document.getElementById('rulePagination'), payload.pagination, loadRules);
        } catch (error) {
            if (requestId !== state.ruleRequest) return;
            tableState(ruleRows, ruleColumns, error.message, true);
            ui.showToast(error.message, 'error');
        }
    }

    function referenceRow(reference) {
        const source = reference.source_url
            ? '<a class="compliance-reference-link" href="' + ui.escapeHtml(reference.source_url) + '" target="_blank" rel="noopener noreferrer">Open source <i class="fa-solid fa-arrow-up-right-from-square"></i></a>' : '<span class="compliance-subtext">No URL stored</span>';
        const actions = (canManage || canVerifyReferences) ? '<td>' + (canManage ? '<button type="button" class="row-action-button" data-edit-compliance-reference="' + Number(reference.id) + '"><i class="fa-solid fa-pen"></i> Edit</button> ' : '') + (canVerifyReferences ? '<button type="button" class="row-action-button" data-assess-compliance-reference="' + Number(reference.id) + '"><i class="fa-solid fa-clipboard-check"></i> Assess</button>' : '') + '</td>' : '';
        const assessments = 'Identity: ' + (reference.document_identity_assessment || 'not assessed') + ' · Origin: ' + (reference.declared_origin_assessment || 'not assessed') + ' · Applicability: ' + (reference.applicability_assessment || 'not assessed');
        return '<tr><td><span class="compliance-name">' + ui.escapeHtml(reference.reference_code || reference.title || 'Reference') + '</span><span class="compliance-subtext">' + ui.escapeHtml(reference.title || 'No title') + (reference.effective_date ? ' / ' + ui.escapeHtml(reference.effective_date) : '') + '</span></td>' +
            '<td>' + badge(reference.reference_type, reference.reference_type === 'DEPED' ? 'DepEd' : reference.reference_type) + '<span class="compliance-subtext">' + ui.escapeHtml(reference.source_kind || 'unknown') + '</span></td><td>' + ui.escapeHtml(reference.issuing_body) + '</td>' +
            '<td>' + badge(reference.verification_status, 'Internal status: ' + reference.verification_status) + '<span class="compliance-subtext">' + ui.escapeHtml(assessments) + '</span></td><td>' + source + '</td><td>' + Number(reference.rule_count || 0) + '</td>' + actions + '</tr>';
    }

    async function loadReferences(page) {
        const requestId = ++state.referenceRequest;
        state.referencePage = Number(page) || 1;
        tableState(referenceRows, referenceColumns, 'Loading compliance references...', false);
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'reference_list', page: state.referencePage, per_page: document.getElementById('referencePerPage').value,
                search: document.getElementById('referenceSearch').value.trim(),
                reference_type: document.getElementById('referenceTypeFilter').value,
                verification_status: document.getElementById('referenceStatusFilter').value
            });
            if (requestId !== state.referenceRequest) return;
            state.referencePage = Number(payload.pagination.page);
            state.references.clear();
            payload.references.forEach(function (item) { state.references.set(Number(item.id), item); });
            referenceRows.innerHTML = payload.references.length ? payload.references.map(referenceRow).join('') : '<tr><td colspan="' + referenceColumns + '" class="empty-cell">No compliance references found.</td></tr>';
            ui.renderPagination(document.getElementById('referencePagination'), payload.pagination, loadReferences);
        } catch (error) {
            if (requestId !== state.referenceRequest) return;
            tableState(referenceRows, referenceColumns, error.message, true);
            ui.showToast(error.message, 'error');
        }
    }

    function historyRow(run) {
        const runner = [run.run_by_first_name, run.run_by_last_name].filter(Boolean).join(' ') || run.run_by_username || 'Unknown user';
        return '<tr data-record-view="' + Number(run.id) + '" tabindex="0" aria-label="View validation run on ' + ui.escapeHtml(formatDateTime(run.created_at)) + '"><td>' + ui.escapeHtml(formatDateTime(run.created_at)) + '</td><td><span class="compliance-name">' + ui.escapeHtml(run.program_code) + '</span><span class="compliance-subtext">' + ui.escapeHtml(run.program_name) + '</span></td>' +
            '<td><span class="compliance-name">' + ui.escapeHtml(run.curriculum_code) + '</span><span class="compliance-subtext">' + ui.escapeHtml(run.curriculum_name) + '</span></td><td>' + ui.escapeHtml(run.version_label) + '</td>' +
            '<td>' + Number(run.rules_evaluated) + '</td><td>' + Number(run.passed_count) + '</td><td>' + Number(run.failed_count) + '</td><td>' + Number(run.not_evaluated_count) + '</td><td>' + ui.escapeHtml(runner) + '</td>' +
            '<td><button type="button" class="row-action-button" data-view-compliance-run="' + Number(run.id) + '"><i class="fa-solid fa-eye"></i> View</button></td></tr>';
    }

    async function loadHistory(page) {
        const requestId = ++state.historyRequest;
        state.historyPage = Number(page) || 1;
        tableState(historyRows, 10, 'Loading validation history...', false);
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'history_list', page: state.historyPage, per_page: document.getElementById('historyPerPage').value,
                search: document.getElementById('historySearch').value.trim()
            });
            if (requestId !== state.historyRequest) return;
            state.historyPage = Number(payload.pagination.page);
            historyRows.innerHTML = payload.runs.length ? payload.runs.map(historyRow).join('') : '<tr><td colspan="10" class="empty-cell">No validation runs found.</td></tr>';
            ui.renderPagination(document.getElementById('historyPagination'), payload.pagination, loadHistory);
        } catch (error) {
            if (requestId !== state.historyRequest) return;
            tableState(historyRows, 10, error.message, true);
            ui.showToast(error.message, 'error');
        }
    }

    async function viewRun(id) {
        try {
            const payload = await ui.fetchJson(endpoint, { action: 'run_get', id: id });
            const run = payload.run;
            cimfRunId = run.id; window.CSMSCIMF?.updateContext();
            document.getElementById('complianceRunModalContext').textContent = run.program_code + ' / ' + run.curriculum_code + ' / ' + run.version_label + ' / ' + formatDateTime(run.created_at);
            renderSummary({ rules_evaluated: run.rules_evaluated, passed: run.passed_count, failed: run.failed_count, not_evaluated: run.not_evaluated_count }, document.getElementById('historyRunSummary'));
            document.getElementById('historyRunResultRows').innerHTML = run.results.length ? run.results.map(resultRow).join('') : '<tr><td colspan="6" class="empty-cell">No stored results.</td></tr>';
            runModal.open();
        } catch (error) {
            ui.showToast(error.message, 'error');
        }
    }

    function fillScopeOptions(rule) {
        const scopeType = document.getElementById('complianceRuleScopeType').value;
        scopeAutocomplete?.clear();
        scopeInput.disabled = false;
        scopeInput.placeholder = scopeType === 'program'
            ? 'Search academic program code or name'
            : 'Search curriculum code or name';
        if (!rule) return;
        const record = scopeType === 'program'
            ? { id: rule.academic_program_id, program_code: rule.program_code, program_name: rule.program_name }
            : { id: rule.curriculum_id, curriculum_code: rule.curriculum_code, curriculum_name: rule.curriculum_name };
        if (record.id) scopeAutocomplete?.setSelected(record);
    }

    function updateRuleFields() {
        const type = document.getElementById('complianceRuleType').value;
        const numeric = ['MIN_TOTAL_UNITS', 'MAX_TOTAL_UNITS', 'MIN_SUBJECT_UNITS', 'MAX_SUBJECT_UNITS'].includes(type);
        const subject = ['REQUIRED_SUBJECT', 'MIN_SUBJECT_UNITS', 'MAX_SUBJECT_UNITS'].includes(type);
        document.getElementById('complianceNumericField').hidden = !numeric;
        document.getElementById('complianceRuleNumeric').required = numeric;
        document.getElementById('complianceSubjectField').hidden = !subject;
        complianceSubjectInput.required = subject && !state.lockedRule;
        document.getElementById('complianceRuleSubject').required = false;
    }

    function setSemanticLock(locked) {
        ['complianceRuleReference', 'complianceRuleScopeType', 'complianceRuleScope', 'complianceRuleType', 'complianceRuleNumeric', 'complianceRuleSubject', 'complianceRuleSubjectInput'].forEach(function (id) {
            const field = document.getElementById(id);
            if (field) field.disabled = locked;
        });
        [document.getElementById('complianceRuleReferenceInput'), scopeInput, complianceSubjectInput]
            .forEach(function (input) {
                if (input) input.disabled = locked;
            });
        document.querySelectorAll('#complianceRuleModal [data-autocomplete-clear]').forEach(function (button) {
            button.disabled = locked;
        });
        document.getElementById('complianceRuleHistoryNotice').hidden = !locked;
    }

    async function openRuleForm(rule) {
        if (!canManage || !ruleForm || !ruleModal) return;
        ruleForm.reset();
        referenceAutocomplete?.clear();
        scopeAutocomplete?.clear();
        complianceSubjectAutocomplete?.clear();
        ui.clearFormError(ruleForm);
        setSemanticLock(false);
        state.lockedRule = rule && Number(rule.has_history) ? rule : null;
        try {
            document.getElementById('complianceRuleId').value = rule ? rule.id : '';
            document.getElementById('complianceRuleName').value = rule ? rule.rule_name : '';
            document.getElementById('complianceRuleStatus').value = rule ? rule.technical_status : 'active';
            document.getElementById('complianceRuleScopeType').value = rule && rule.curriculum_id ? 'curriculum' : 'program';
            fillScopeOptions(rule);
            if (rule?.compliance_reference_id) {
                referenceAutocomplete?.setSelected({
                    id: rule.compliance_reference_id,
                    reference_type: rule.reference_type,
                    reference_code: rule.reference_code,
                    title: rule.reference_title,
                    issuing_body: rule.issuing_body
                });
            }
            document.getElementById('complianceRuleType').value = rule ? rule.rule_type : 'MIN_TOTAL_UNITS';
            document.getElementById('complianceRuleNumeric').value = rule?.numeric_value ?? '';
            if (rule?.subject_id) {
                complianceSubjectAutocomplete?.setSelected({
                    id: rule.subject_id,
                    subject_code: rule.subject_code || '',
                    subject_title: rule.subject_title || ''
                });
            }
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
        document.getElementById('complianceReferenceSourceKind').value = reference ? (reference.source_kind || 'unknown') : 'unknown';
        document.getElementById('complianceReferenceSupersedes').value = reference?.supersedes_reference_id ?? '';
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

    async function openEvidenceForm(referenceId) {
        if (!canVerifyReferences || !evidenceForm || !evidenceModal) return;
        try {
            const payload = await ui.fetchJson(endpoint, {action: 'reference_get', id: referenceId});
            const reference = payload.reference;
            evidenceForm.reset();
            ui.clearFormError(evidenceForm);
            document.getElementById('complianceEvidenceReferenceId').value = reference.id;
            document.getElementById('complianceEvidenceReferenceLabel').textContent =
                (reference.reference_code || reference.title || 'Reference') + ' — ' + (reference.title || '') +
                ' · ' + (reference.source_kind || 'unknown') + ' · adoption/approval not verified';
            const history = document.getElementById('complianceEvidenceHistory');
            history.textContent = reference.evidence_events?.length
                ? reference.evidence_events.map(item => item.created_at + ' · ' + item.verification_subject.replaceAll('_',' ') + ': ' + item.result + ' · assessed record: ' + (item.reference_code_snapshot || item.title_snapshot || 'unnamed source') + ' / ' + (item.issuing_body_snapshot || 'issuer unknown') + ' · ' + (item.rationale || '') + (item.evidence_locator ? ' · evidence: ' + item.evidence_locator : '')).join('\n')
                : 'No evidence assessments recorded. Identity, origin, and applicability are not assessed.';
            evidenceModal.open();
        } catch (error) { ui.showToast(error.message, 'error'); }
    }

    async function submitEvidence(event) {
        event.preventDefault();
        if (!evidenceForm.reportValidity()) return;
        ui.clearFormError(evidenceForm);
        const data = new FormData(evidenceForm);
        data.set('action', 'reference_evidence');
        ui.setFormSubmitting(evidenceForm, true, 'Recording...');
        try {
            const payload = await ui.postForm(endpoint, data);
            evidenceModal.close();
            ui.showToast(payload.message, 'success');
            await loadReferences(state.referencePage);
        } catch (error) { ui.showFormError(evidenceForm, error.message); }
        finally { ui.setFormSubmitting(evidenceForm, false); }
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
        const ruleType = document.getElementById('complianceRuleType').value;
        const subjectRequired = ['REQUIRED_SUBJECT', 'MIN_SUBJECT_UNITS', 'MAX_SUBJECT_UNITS'].includes(ruleType);
        if (!state.lockedRule && !referenceAutocomplete?.hasSelection()) {
            ui.showFormError(ruleForm, 'Please select a valid compliance reference.');
            document.getElementById('complianceRuleReferenceInput').focus();
            return;
        }
        if (!state.lockedRule && !scopeAutocomplete?.hasSelection()) {
            ui.showFormError(ruleForm, 'Please select a valid scope record.');
            scopeInput?.focus();
            return;
        }
        if (subjectRequired && !state.lockedRule && !complianceSubjectAutocomplete?.hasSelection()) {
            ui.showFormError(ruleForm, 'Please select a valid subject.');
            complianceSubjectInput?.focus();
            return;
        }
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
    version?.addEventListener('change', function () { if (runButton) runButton.disabled = !canRun || !version.value; clearValidation(version.value ? 'Ready to validate ' + validationContextLabel() + '.' : 'Select a curriculum version to run configured checks.'); });
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
        const assess = event.target.closest('[data-assess-compliance-reference]');
        if (assess) openEvidenceForm(Number(assess.dataset.assessComplianceReference));
    });
    historyRows?.addEventListener('click', function (event) {
        const button = event.target.closest('[data-view-compliance-run]');
        if (button) {
            event.stopPropagation();
            viewRun(Number(button.dataset.viewComplianceRun));
            return;
        }
        const row = event.target.closest('tr[data-record-view]');
        if (row) viewRun(Number(row.dataset.recordView));
    });
    historyRows?.addEventListener('keydown', function (event) {
        const row = event.target.closest('tr[data-record-view]');
        if (row && event.target === row && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            viewRun(Number(row.dataset.recordView));
        }
    });

    if (canManage) {
        document.getElementById('newComplianceRule')?.addEventListener('click', function () { openRuleForm(null); });
        document.getElementById('newComplianceReference')?.addEventListener('click', function () { openReferenceForm(null); });
        document.getElementById('complianceRuleScopeType')?.addEventListener('change', function () { fillScopeOptions(null); });
        document.getElementById('complianceRuleType')?.addEventListener('change', updateRuleFields);
        referenceForm?.addEventListener('submit', submitReference);
        ruleForm?.addEventListener('submit', submitRule);
    }
    if (canVerifyReferences) evidenceForm?.addEventListener('submit', submitEvidence);

    if (activeView === 'validate') loadPrograms();
    if (activeView === 'rules') loadRules(1);
    if (activeView === 'references') loadReferences(1);
    if (activeView === 'history') loadHistory(1);
}());
