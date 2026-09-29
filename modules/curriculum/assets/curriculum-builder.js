(function () {
    'use strict';

    const workspace = document.getElementById('curriculumWorkspace');
    if (!workspace || !window.CurriculumUI) return;

    const ui = window.CurriculumUI;
    const workspacePage = workspace.dataset.workspacePage;
    const endpoints = {
        programs: workspace.dataset.programApi,
        subjects: workspace.dataset.subjectApi,
        curricula: workspace.dataset.curriculumApi,
        versions: workspace.dataset.versionApi,
        assignments: workspace.dataset.assignmentApi
    };
    const permissions = {
        viewPrograms: workspace.dataset.canViewPrograms === '1',
        createPrograms: workspace.dataset.canCreatePrograms === '1',
        updatePrograms: workspace.dataset.canUpdatePrograms === '1',
        createCurricula: workspace.dataset.canCreateCurricula === '1',
        updateCurricula: workspace.dataset.canUpdateCurricula === '1',
        createVersions: workspace.dataset.canCreateVersions === '1',
        assignSubjects: workspace.dataset.canAssignSubjects === '1',
        removeAssignments: workspace.dataset.canRemoveAssignments === '1'
    };
    const columns = {
        programs: permissions.updatePrograms ? 5 : 4,
        curricula: 6,
        versions: 5,
        placements: permissions.removeAssignments ? 7 : 6
    };
    const state = {
        selectedCurriculumId: null,
        selectedCurriculum: null,
        selectedVersionId: null,
        selectedVersionLabel: '',
        programPage: 1,
        curriculumPage: 1,
        versionPage: 1,
        placementPage: 1,
        hasPrograms: false,
        pendingRemoval: null,
        programRequest: 0,
        curriculumRequest: 0,
        detailRequest: 0,
        versionRequest: 0,
        placementRequest: 0,
        programOptionRequest: 0,
        subjectOptionRequest: 0
    };

    const programRows = document.getElementById('programRows');
    const curriculumRows = document.getElementById('curriculumRows');
    const versionRows = document.getElementById('versionRows');
    const placementRows = document.getElementById('placementRows');

    const programForm = document.getElementById('programForm');
    const curriculumForm = document.getElementById('curriculumForm');
    const versionForm = document.getElementById('versionForm');
    const assignmentForm = document.getElementById('assignmentForm');
    const programModal = ui.createModal(document.getElementById('programModal'));
    const curriculumModal = ui.createModal(document.getElementById('curriculumModal'));
    const versionModal = ui.createModal(document.getElementById('versionModal'));
    const assignmentModal = ui.createModal(document.getElementById('assignmentModal'));
    const removalElement = document.getElementById('removeAssignmentModal');
    const removalModal = ui.createModal(removalElement);
    let programFormMode = 'create';
    let curriculumFormMode = 'create';
    const curriculumProgramCombobox = document.getElementById('curriculumProgramCombobox');
    const curriculumProgramInput = document.getElementById('curriculumProgramInput');
    const curriculumProgramId = document.getElementById('curriculumProgramId');
    const curriculumProgramOptions = document.getElementById('curriculumProgramOptions');
    const programComboboxState = {
        programs: [],
        activeIndex: -1,
        selectedId: null,
        selectedLabel: ''
    };

    function tableState(element, columnCount, message, isError) {
        if (!element) return;
        element.innerHTML = '<tr class="state-row"><td colspan="' + columnCount + '" class="state-cell' +
            (isError ? ' error-state' : '') + '">' + ui.escapeHtml(message) + '</td></tr>';
    }

    function setButtonEnabled(id, enabled) {
        const button = document.getElementById(id);
        if (button) button.disabled = !enabled;
    }

    function programRow(program) {
        const actions = permissions.updatePrograms
            ? '<td class="row-actions"><button type="button" class="row-action-button" data-edit-program="' +
                Number(program.id) + '"><i class="fa-solid fa-pen"></i> Edit</button></td>'
            : '';
        return '<tr>' +
            '<td><span class="record-code">' + ui.escapeHtml(program.program_code) + '</span></td>' +
            '<td><span class="record-name">' + ui.escapeHtml(program.program_name) + '</span></td>' +
            '<td>' + ui.escapeHtml(program.program_type || '—') + '</td>' +
            '<td>' + ui.statusBadge(program.status) + '</td>' + actions +
            '</tr>';
    }

    async function loadPrograms(page) {
        if (!permissions.viewPrograms || !programRows) return;
        const sequence = ++state.programRequest;
        state.programPage = Number(page) || 1;
        tableState(programRows, columns.programs, 'Loading academic programs…', false);
        try {
            const payload = await ui.fetchJson(endpoints.programs, {
                action: 'list', page: state.programPage, per_page: 10,
                search: document.getElementById('programSearch').value.trim(),
                status: document.getElementById('programStatus').value
            });
            if (sequence !== state.programRequest) return;
            state.programPage = Number(payload.pagination.page);
            ui.setCount(document.getElementById('programCount'), Number(payload.pagination.total_records));
            if (!payload.programs.length) {
                const message = permissions.createPrograms
                    ? 'No academic programs found. Use Add New to create the first matching record.'
                    : 'No academic programs found.';
                tableState(programRows, columns.programs, message, false);
            } else {
                programRows.innerHTML = payload.programs.map(programRow).join('');
            }
            ui.renderPagination(document.getElementById('programPagination'), payload.pagination, loadPrograms);
        } catch (error) {
            if (sequence !== state.programRequest) return;
            ui.setCount(document.getElementById('programCount'), 0);
            document.getElementById('programPagination').innerHTML = '';
            tableState(programRows, columns.programs, error.message || 'Academic programs could not be loaded.', true);
        }
    }

    function curriculumRow(curriculum) {
        const selected = Number(curriculum.id) === state.selectedCurriculumId;
        const edit = permissions.updateCurricula
            ? '<button type="button" class="row-action-button" data-edit-curriculum="' + Number(curriculum.id) +
                '"><i class="fa-solid fa-pen"></i> Edit</button>'
            : '';
        return '<tr>' +
            '<td><span class="record-code">' + ui.escapeHtml(curriculum.curriculum_code) + '</span></td>' +
            '<td><span class="record-name">' + ui.escapeHtml(curriculum.curriculum_name) + '</span></td>' +
            '<td><span class="record-name">' + ui.escapeHtml(curriculum.program_name) + '</span>' +
                '<br><span class="subtle-text">' + ui.escapeHtml(curriculum.program_code) + '</span></td>' +
            '<td>' + ui.statusBadge(curriculum.status) + '</td>' +
            '<td>' + ui.escapeHtml(ui.formatDate(curriculum.updated_at)) + '</td>' +
            '<td class="row-actions"><button type="button" class="view-button' + (selected ? ' selected' : '') +
                '" data-view-curriculum="' + Number(curriculum.id) + '"><i class="fa-solid fa-eye"></i> View</button>' +
                edit + '</td></tr>';
    }

    async function loadCurricula(page) {
        const sequence = ++state.curriculumRequest;
        state.curriculumPage = Number(page) || 1;
        tableState(curriculumRows, columns.curricula, 'Loading curricula…', false);
        try {
            const payload = await ui.fetchJson(endpoints.curricula, {
                action: 'list',
                page: state.curriculumPage,
                per_page: document.getElementById('curriculumPerPage').value,
                search: document.getElementById('curriculumSearch').value.trim(),
                status: document.getElementById('curriculumStatus').value
            });
            if (sequence !== state.curriculumRequest) return;
            state.curriculumPage = Number(payload.pagination.page);
            ui.setCount(document.getElementById('curriculumCount'), Number(payload.pagination.total_records));
            if (!payload.curricula.length) {
                const message = permissions.createCurricula
                    ? 'No curricula found. Create an academic program first, then use Add New Curriculum.'
                    : 'No curricula found.';
                tableState(curriculumRows, columns.curricula, message, false);
            } else {
                curriculumRows.innerHTML = payload.curricula.map(curriculumRow).join('');
            }
            ui.renderPagination(document.getElementById('curriculumPagination'), payload.pagination, loadCurricula);
        } catch (error) {
            if (sequence !== state.curriculumRequest) return;
            ui.setCount(document.getElementById('curriculumCount'), 0);
            document.getElementById('curriculumPagination').innerHTML = '';
            tableState(curriculumRows, columns.curricula, error.message || 'Curricula could not be loaded.', true);
        }
    }

    function programDisplayLabel(program) {
        return String(program.program_code || '') + ' — ' + String(program.program_name || '');
    }

    function closeProgramSuggestions(cancelPending) {
        if (!curriculumProgramInput || !curriculumProgramOptions) return;
        if (cancelPending) state.programOptionRequest += 1;
        curriculumProgramOptions.hidden = true;
        curriculumProgramInput.setAttribute('aria-expanded', 'false');
        curriculumProgramInput.removeAttribute('aria-activedescendant');
        programComboboxState.activeIndex = -1;
    }

    function clearProgramSuggestions(cancelPending) {
        if (cancelPending) state.programOptionRequest += 1;
        programComboboxState.programs = [];
        if (curriculumProgramOptions) curriculumProgramOptions.innerHTML = '';
        closeProgramSuggestions(false);
    }

    function openProgramSuggestions() {
        if (!curriculumProgramInput || !curriculumProgramOptions) return;
        curriculumProgramOptions.hidden = false;
        curriculumProgramInput.setAttribute('aria-expanded', 'true');
    }

    function clearProgramSelection() {
        programComboboxState.selectedId = null;
        programComboboxState.selectedLabel = '';
        if (curriculumProgramId) curriculumProgramId.value = '';
        if (curriculumProgramInput) curriculumProgramInput.setCustomValidity('');
    }

    function selectProgram(program) {
        if (!program || !curriculumProgramInput || !curriculumProgramId) return;
        const label = programDisplayLabel(program);
        programComboboxState.selectedId = Number(program.id);
        programComboboxState.selectedLabel = label;
        curriculumProgramId.value = String(programComboboxState.selectedId);
        curriculumProgramInput.value = label;
        curriculumProgramInput.setCustomValidity('');
        const message = document.getElementById('programRequirementMessage');
        if (message) message.textContent = 'Selected from the current Academic Program master records.';
        closeProgramSuggestions(true);
    }

    function renderProgramSearchState(message, stateClass) {
        if (!curriculumProgramOptions) return;
        programComboboxState.programs = [];
        programComboboxState.activeIndex = -1;
        curriculumProgramOptions.innerHTML = '<div class="program-combobox-state ' +
            ui.escapeHtml(stateClass || '') + '" role="status">' + ui.escapeHtml(message) + '</div>';
        openProgramSuggestions();
    }

    function renderProgramSuggestions(programs) {
        if (!curriculumProgramOptions) return;
        programComboboxState.programs = programs;
        programComboboxState.activeIndex = -1;
        curriculumProgramOptions.innerHTML = programs.map(function (program, index) {
            const type = program.program_type
                ? '<span class="program-option-type">' + ui.escapeHtml(program.program_type) + '</span>'
                : '';
            return '<button type="button" class="program-combobox-option" role="option" ' +
                'id="curriculumProgramOption' + index + '" data-program-option="' + index +
                '" aria-selected="false" tabindex="-1">' +
                '<span class="program-option-code">' + ui.escapeHtml(program.program_code) + '</span>' +
                '<span class="program-option-name">' + ui.escapeHtml(program.program_name) + '</span>' +
                type + '</button>';
        }).join('');
        openProgramSuggestions();
    }

    function setActiveProgramOption(index) {
        const count = programComboboxState.programs.length;
        if (!count || !curriculumProgramInput || !curriculumProgramOptions) return;
        const normalized = ((index % count) + count) % count;
        programComboboxState.activeIndex = normalized;
        curriculumProgramOptions.querySelectorAll('[role="option"]').forEach(function (option, optionIndex) {
            const selected = optionIndex === normalized;
            option.setAttribute('aria-selected', selected ? 'true' : 'false');
            option.classList.toggle('active', selected);
            if (selected) option.scrollIntoView({ block: 'nearest' });
        });
        curriculumProgramInput.setAttribute('aria-activedescendant', 'curriculumProgramOption' + normalized);
    }

    async function searchProgramOptions(searchValue) {
        if (!curriculumProgramInput || !curriculumProgramOptions) return 0;
        const query = String(searchValue || '').trim();
        if (query.length < 1) {
            clearProgramSuggestions(true);
            return 0;
        }
        const sequence = ++state.programOptionRequest;
        renderProgramSearchState('Searching programs…', 'loading');
        try {
            const payload = await ui.fetchJson(endpoints.programs, {
                action: 'list', page: 1, per_page: 20, search: query, status: ''
            });
            if (sequence !== state.programOptionRequest) return 0;
            const programs = Array.isArray(payload.programs) ? payload.programs : [];
            if (!programs.length) {
                renderProgramSearchState(
                    query ? 'No matching academic programs found.' : 'No academic programs are available.',
                    'empty'
                );
            } else {
                renderProgramSuggestions(programs);
            }
            return Number(payload.pagination.total_records);
        } catch (error) {
            if (sequence !== state.programOptionRequest) return 0;
            renderProgramSearchState('Academic programs could not be loaded.', 'error');
            return 0;
        }
    }

    async function refreshProgramAvailability() {
        if (!permissions.createCurricula || !permissions.viewPrograms) return;
        try {
            const payload = await ui.fetchJson(endpoints.programs, {
                action: 'list', page: 1, per_page: 10, search: '', status: ''
            });
            state.hasPrograms = Number(payload.pagination.total_records) > 0;
            setButtonEnabled('newCurriculumButton', state.hasPrograms);
        } catch (error) {
            state.hasPrograms = false;
            setButtonEnabled('newCurriculumButton', false);
        }
    }

    function showDetailLoading() {
        document.getElementById('curriculumDetailEmpty').hidden = true;
        document.getElementById('curriculumDetailContent').hidden = false;
        document.getElementById('detailCurriculumName').textContent = 'Loading curriculum…';
        document.getElementById('detailCurriculumCode').textContent = '';
        document.getElementById('detailCurriculumStatus').innerHTML = '';
        ['detailProgramName', 'detailProgramCode', 'detailProgramType', 'detailUpdatedAt'].forEach(function (id) {
            document.getElementById(id).textContent = '—';
        });
        tableState(versionRows, columns.versions, 'Loading curriculum versions…', false);
        tableState(placementRows, columns.placements, 'Select a curriculum version to view assigned subjects.', false);
        document.getElementById('versionPagination').innerHTML = '';
        document.getElementById('placementPagination').innerHTML = '';
        ui.setCount(document.getElementById('versionCount'), 0);
        ui.setCount(document.getElementById('placementCount'), 0);
        setButtonEnabled('newVersionButton', false);
        setButtonEnabled('assignSubjectButton', false);
    }

    async function selectCurriculum(id) {
        state.selectedCurriculumId = Number(id);
        state.selectedCurriculum = null;
        state.selectedVersionId = null;
        state.selectedVersionLabel = '';
        showDetailLoading();
        document.querySelectorAll('[data-view-curriculum]').forEach(function (button) {
            button.classList.toggle('selected', Number(button.dataset.viewCurriculum) === state.selectedCurriculumId);
        });
        const sequence = ++state.detailRequest;
        try {
            const payload = await ui.fetchJson(endpoints.curricula, {
                action: 'get', id: state.selectedCurriculumId
            });
            if (sequence !== state.detailRequest) return;
            const curriculum = payload.curriculum;
            state.selectedCurriculum = curriculum;
            document.getElementById('detailCurriculumName').textContent = curriculum.curriculum_name;
            document.getElementById('detailCurriculumCode').textContent = curriculum.curriculum_code;
            document.getElementById('detailCurriculumStatus').innerHTML = ui.statusBadge(curriculum.status);
            document.getElementById('detailProgramName').textContent = curriculum.program_name;
            document.getElementById('detailProgramCode').textContent = curriculum.program_code;
            document.getElementById('detailProgramType').textContent = curriculum.program_type || 'Not specified';
            document.getElementById('detailUpdatedAt').textContent = ui.formatDate(curriculum.updated_at);
            document.getElementById('placementHeading').textContent = 'Subject Placements';
            setButtonEnabled('newVersionButton', permissions.createVersions);
            await loadVersions(1);
        } catch (error) {
            if (sequence !== state.detailRequest) return;
            state.selectedCurriculum = null;
            document.getElementById('detailCurriculumName').textContent = 'Curriculum unavailable';
            document.getElementById('detailCurriculumCode').textContent = error.message || 'The record could not be loaded.';
            tableState(versionRows, columns.versions, 'Curriculum versions could not be loaded.', true);
        }
    }

    async function loadVersions(page, selectVersionId) {
        if (!state.selectedCurriculumId) return;
        const curriculumId = state.selectedCurriculumId;
        const sequence = ++state.versionRequest;
        state.versionPage = Number(page) || 1;
        tableState(versionRows, columns.versions, 'Loading curriculum versions…', false);
        try {
            const payload = await ui.fetchJson(endpoints.versions, {
                action: 'list', curriculum_id: curriculumId, page: state.versionPage, per_page: 10
            });
            if (sequence !== state.versionRequest || curriculumId !== state.selectedCurriculumId) return;
            state.versionPage = Number(payload.pagination.page);
            ui.setCount(document.getElementById('versionCount'), Number(payload.pagination.total_records));
            if (!payload.versions.length) {
                const message = permissions.createVersions
                    ? 'No curriculum versions found. Use New Version to create the first system record.'
                    : 'No curriculum versions found.';
                tableState(versionRows, columns.versions, message, false);
            } else {
                versionRows.innerHTML = payload.versions.map(function (version) {
                    const selected = Number(version.id) === state.selectedVersionId;
                    return '<tr>' +
                        '<td><span class="record-code">' + ui.escapeHtml(version.version_label) + '</span></td>' +
                        '<td>' + ui.escapeHtml(version.effective_academic_year || '—') + '</td>' +
                        '<td><span class="subtle-text">' + ui.escapeHtml(version.description || 'No description') + '</span></td>' +
                        '<td>' + ui.statusBadge(version.status) + '</td>' +
                        '<td><button type="button" class="view-button' + (selected ? ' selected' : '') +
                            '" data-version-id="' + Number(version.id) + '" data-version-label="' +
                            ui.escapeHtml(version.version_label) + '"><i class="fa-solid fa-list"></i> Subjects</button></td></tr>';
                }).join('');
            }
            ui.renderPagination(document.getElementById('versionPagination'), payload.pagination, loadVersions);
            if (selectVersionId) {
                const created = payload.versions.find(function (version) {
                    return Number(version.id) === Number(selectVersionId);
                });
                if (created) await selectVersion(created.id, created.version_label, false);
            }
        } catch (error) {
            if (sequence !== state.versionRequest) return;
            ui.setCount(document.getElementById('versionCount'), 0);
            document.getElementById('versionPagination').innerHTML = '';
            tableState(versionRows, columns.versions, error.message || 'Curriculum versions could not be loaded.', true);
        }
    }

    async function selectVersion(id, label, shouldScroll) {
        state.selectedVersionId = Number(id);
        state.selectedVersionLabel = String(label || '');
        document.querySelectorAll('[data-version-id]').forEach(function (button) {
            button.classList.toggle('selected', Number(button.dataset.versionId) === state.selectedVersionId);
        });
        document.getElementById('placementHeading').textContent = 'Subject Placements — ' + state.selectedVersionLabel;
        setButtonEnabled('assignSubjectButton', permissions.assignSubjects);
        await loadPlacements(1);
        if (shouldScroll !== false) {
            document.getElementById('placementSection').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    async function loadPlacements(page) {
        if (!state.selectedVersionId) return;
        const versionId = state.selectedVersionId;
        const sequence = ++state.placementRequest;
        state.placementPage = Number(page) || 1;
        tableState(placementRows, columns.placements, 'Loading assigned subjects…', false);
        try {
            const payload = await ui.fetchJson(endpoints.assignments, {
                action: 'list', curriculum_version_id: versionId, page: state.placementPage, per_page: 20
            });
            if (sequence !== state.placementRequest || versionId !== state.selectedVersionId) return;
            state.placementPage = Number(payload.pagination.page);
            ui.setCount(document.getElementById('placementCount'), Number(payload.pagination.total_records));
            if (!payload.assignments.length) {
                const message = permissions.assignSubjects
                    ? 'No subjects are assigned to this curriculum version. Use Assign Subject to add a placement.'
                    : 'No subjects are assigned to this curriculum version.';
                tableState(placementRows, columns.placements, message, false);
            } else {
                placementRows.innerHTML = payload.assignments.map(function (assignment) {
                    const placementClass = Number(assignment.is_required) === 1
                        ? 'placement-required' : 'placement-optional';
                    const placementLabel = Number(assignment.is_required) === 1 ? 'Required' : 'Optional';
                    const remove = permissions.removeAssignments
                        ? '<td class="row-actions"><button type="button" class="row-action-button destructive-action" ' +
                            'data-remove-assignment="' + Number(assignment.id) + '" data-subject-label="' +
                            ui.escapeHtml(assignment.subject_code + ' — ' + assignment.subject_title) +
                            '"><i class="fa-solid fa-link-slash"></i> Remove</button></td>'
                        : '';
                    return '<tr>' +
                        '<td>' + ui.escapeHtml(assignment.sequence_order ?? '—') + '</td>' +
                        '<td><span class="record-code">' + ui.escapeHtml(assignment.subject_code) + '</span>' +
                            '<br><span class="record-name">' + ui.escapeHtml(assignment.subject_title) + '</span></td>' +
                        '<td>' + ui.escapeHtml(ui.formatUnits(assignment.units)) + '</td>' +
                        '<td>' + ui.escapeHtml(assignment.year_level || '—') + '</td>' +
                        '<td>' + ui.escapeHtml(assignment.semester || '—') + '</td>' +
                        '<td><span class="placement-indicator ' + placementClass + '">' + placementLabel + '</span></td>' +
                        remove + '</tr>';
                }).join('');
            }
            ui.renderPagination(document.getElementById('placementPagination'), payload.pagination, loadPlacements);
        } catch (error) {
            if (sequence !== state.placementRequest) return;
            ui.setCount(document.getElementById('placementCount'), 0);
            document.getElementById('placementPagination').innerHTML = '';
            tableState(placementRows, columns.placements, error.message || 'Subject placements could not be loaded.', true);
        }
    }

    function resetProgramForm() {
        if (!programForm) return;
        programForm.reset();
        document.getElementById('programId').value = '';
        document.getElementById('programFormStatus').value = 'active';
        ui.clearFormError(programForm);
    }

    function openCreateProgram() {
        if (!permissions.createPrograms || !programForm || !programModal) return;
        programFormMode = 'create';
        resetProgramForm();
        document.getElementById('programModalTitle').textContent = 'New Academic Program';
        document.getElementById('programSubmitButton').textContent = 'Create Program';
        programModal.open();
        document.getElementById('programCode').focus();
    }

    async function openEditProgram(id) {
        if (!permissions.updatePrograms || !programForm || !programModal) return;
        programFormMode = 'update';
        resetProgramForm();
        document.getElementById('programModalTitle').textContent = 'Edit Academic Program';
        document.getElementById('programSubmitButton').textContent = 'Save Changes';
        programModal.open();
        ui.setFormSubmitting(programForm, true, 'Loading…');
        try {
            const payload = await ui.fetchJson(endpoints.programs, { action: 'get', id: id });
            const program = payload.program;
            document.getElementById('programId').value = program.id;
            document.getElementById('programCode').value = program.program_code || '';
            document.getElementById('programName').value = program.program_name || '';
            document.getElementById('programType').value = program.program_type || '';
            document.getElementById('programFormStatus').value = program.status || 'active';
            document.getElementById('programCode').focus();
        } catch (error) {
            ui.showFormError(programForm, error.message || 'The academic program could not be loaded.');
        } finally {
            ui.setFormSubmitting(programForm, false);
        }
    }

    function resetCurriculumForm() {
        if (!curriculumForm) return;
        curriculumForm.reset();
        document.getElementById('curriculumId').value = '';
        document.getElementById('curriculumFormStatus').value = 'active';
        if (curriculumProgramInput) curriculumProgramInput.value = '';
        clearProgramSelection();
        clearProgramSuggestions(true);
        const message = document.getElementById('programRequirementMessage');
        if (message) message.textContent = 'Select an existing academic program from the suggestions.';
        ui.clearFormError(curriculumForm);
    }

    async function openCreateCurriculum() {
        if (!permissions.createCurricula || !curriculumForm || !curriculumModal) return;
        if (!state.hasPrograms) {
            ui.showToast('Create an academic program before creating a curriculum.', 'warning');
            return;
        }
        curriculumFormMode = 'create';
        resetCurriculumForm();
        document.getElementById('curriculumModalTitle').textContent = 'New Curriculum';
        document.getElementById('curriculumSubmitButton').textContent = 'Create Curriculum';
        curriculumModal.open();
        document.getElementById('curriculumCode').focus();
    }

    async function openEditCurriculum(id) {
        if (!permissions.updateCurricula || !curriculumForm || !curriculumModal) return;
        curriculumFormMode = 'update';
        resetCurriculumForm();
        document.getElementById('curriculumModalTitle').textContent = 'Edit Curriculum';
        document.getElementById('curriculumSubmitButton').textContent = 'Save Changes';
        curriculumModal.open();
        ui.setFormSubmitting(curriculumForm, true, 'Loading…');
        try {
            const payload = await ui.fetchJson(endpoints.curricula, { action: 'get', id: id });
            const curriculum = payload.curriculum;
            document.getElementById('curriculumId').value = curriculum.id;
            document.getElementById('curriculumCode').value = curriculum.curriculum_code || '';
            document.getElementById('curriculumName').value = curriculum.curriculum_name || '';
            document.getElementById('curriculumFormStatus').value = curriculum.status || 'active';
            selectProgram({
                id: curriculum.academic_program_id,
                program_code: curriculum.program_code,
                program_name: curriculum.program_name,
                program_type: curriculum.program_type
            });
            document.getElementById('curriculumCode').focus();
        } catch (error) {
            ui.showFormError(curriculumForm, error.message || 'The curriculum could not be loaded.');
        } finally {
            ui.setFormSubmitting(curriculumForm, false);
        }
    }

    function openCreateVersion() {
        if (!permissions.createVersions || !state.selectedCurriculum || !versionForm || !versionModal) return;
        versionForm.reset();
        ui.clearFormError(versionForm);
        document.getElementById('versionCurriculumId').value = state.selectedCurriculumId;
        document.getElementById('versionStatus').value = 'draft';
        document.getElementById('versionCurriculumContext').textContent =
            state.selectedCurriculum.curriculum_code + ' — ' + state.selectedCurriculum.curriculum_name;
        versionModal.open();
        document.getElementById('versionLabel').focus();
    }

    async function loadSubjectOptions(searchValue) {
        const select = document.getElementById('assignmentSubjectId');
        if (!select) return 0;
        const sequence = ++state.subjectOptionRequest;
        const submit = assignmentForm?.querySelector('[type="submit"]');
        select.disabled = true;
        if (submit) submit.disabled = true;
        select.innerHTML = '<option value="">Loading subjects…</option>';
        try {
            const payload = await ui.fetchJson(endpoints.subjects, {
                action: 'list', page: 1, per_page: 50, search: String(searchValue || '').trim(), status: ''
            });
            if (sequence !== state.subjectOptionRequest) return 0;
            select.innerHTML = '<option value="">Select a subject</option>' +
                payload.subjects.map(function (subject) {
                    return '<option value="' + Number(subject.id) + '">' +
                        ui.escapeHtml(subject.subject_code + ' — ' + subject.subject_title) + '</option>';
                }).join('');
            select.disabled = payload.subjects.length === 0;
            if (submit) submit.disabled = payload.subjects.length === 0;
            return Number(payload.pagination.total_records);
        } catch (error) {
            if (sequence !== state.subjectOptionRequest) return 0;
            select.innerHTML = '<option value="">Subjects unavailable</option>';
            ui.showFormError(assignmentForm, error.message || 'Subject master records could not be loaded.');
            return 0;
        }
    }

    async function openAssignment() {
        if (!permissions.assignSubjects || !state.selectedVersionId || !assignmentForm || !assignmentModal) return;
        assignmentForm.reset();
        ui.clearFormError(assignmentForm);
        document.getElementById('assignmentVersionId').value = state.selectedVersionId;
        document.getElementById('assignmentSubjectSearch').value = '';
        document.getElementById('assignmentVersionContext').textContent =
            (state.selectedCurriculum?.curriculum_code || '') + ' — ' + state.selectedVersionLabel;
        assignmentModal.open();
        const total = await loadSubjectOptions('');
        if (!total) {
            ui.showFormError(assignmentForm, 'No Subject Master records are available. Create a subject first.');
        }
        document.getElementById('assignmentSubjectSearch').focus();
    }

    if (programForm) {
        programForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!programForm.reportValidity()) return;
            ui.clearFormError(programForm);
            const formData = new FormData(programForm);
            formData.set('action', programFormMode);
            ui.setFormSubmitting(programForm, true, programFormMode === 'create' ? 'Creating…' : 'Saving…');
            try {
                const payload = await ui.postForm(endpoints.programs, formData);
                programModal.close();
                resetProgramForm();
                ui.showToast(payload.message, programFormMode === 'create' ? 'success' : 'updated');
                await loadPrograms(state.programPage);
            } catch (error) {
                ui.showFormError(programForm, error.message || 'The academic program could not be saved.');
            } finally {
                ui.setFormSubmitting(programForm, false);
            }
        });
    }

    if (curriculumForm) {
        curriculumForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!curriculumProgramId?.value || !programComboboxState.selectedId) {
                const message = 'Please select an existing academic program.';
                curriculumProgramInput?.setCustomValidity(message);
                curriculumProgramInput?.reportValidity();
                ui.showFormError(curriculumForm, message);
                curriculumProgramInput?.focus();
                return;
            }
            curriculumProgramInput.setCustomValidity('');
            if (!curriculumForm.reportValidity()) return;
            ui.clearFormError(curriculumForm);
            const formData = new FormData(curriculumForm);
            formData.set('action', curriculumFormMode);
            ui.setFormSubmitting(curriculumForm, true, curriculumFormMode === 'create' ? 'Creating…' : 'Saving…');
            try {
                const payload = await ui.postForm(endpoints.curricula, formData);
                const savedId = Number(payload.curriculum.id);
                curriculumModal.close();
                resetCurriculumForm();
                ui.showToast(payload.message, curriculumFormMode === 'create' ? 'success' : 'updated');
                await loadCurricula(state.curriculumPage);
                await selectCurriculum(savedId);
            } catch (error) {
                ui.showFormError(curriculumForm, error.message || 'The curriculum could not be saved.');
            } finally {
                ui.setFormSubmitting(curriculumForm, false);
            }
        });
    }

    if (versionForm) {
        versionForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!versionForm.reportValidity()) return;
            ui.clearFormError(versionForm);
            const formData = new FormData(versionForm);
            formData.set('action', 'create');
            ui.setFormSubmitting(versionForm, true, 'Creating…');
            try {
                const payload = await ui.postForm(endpoints.versions, formData);
                versionModal.close();
                versionForm.reset();
                ui.showToast(payload.message, 'success');
                await loadVersions(1, payload.version.id);
            } catch (error) {
                ui.showFormError(versionForm, error.message || 'The curriculum version could not be created.');
            } finally {
                ui.setFormSubmitting(versionForm, false);
            }
        });
    }

    if (assignmentForm) {
        assignmentForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!assignmentForm.reportValidity()) return;
            ui.clearFormError(assignmentForm);
            const formData = new FormData(assignmentForm);
            formData.set('action', 'assign');
            ui.setFormSubmitting(assignmentForm, true, 'Assigning…');
            try {
                const payload = await ui.postForm(endpoints.assignments, formData);
                assignmentModal.close();
                assignmentForm.reset();
                ui.showToast(payload.message, 'success');
                await loadPlacements(1);
            } catch (error) {
                ui.showFormError(assignmentForm, error.message || 'The subject could not be assigned.');
            } finally {
                ui.setFormSubmitting(assignmentForm, false);
            }
        });
    }

    document.getElementById('confirmRemoveAssignment')?.addEventListener('click', async function () {
        if (!state.pendingRemoval || !removalElement) return;
        ui.clearFormError(removalElement);
        const button = this;
        button.disabled = true;
        const original = button.innerHTML;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Removing…';
        const formData = new FormData();
        formData.set('action', 'remove');
        formData.set('id', state.pendingRemoval.id);
        try {
            const payload = await ui.postForm(endpoints.assignments, formData);
            removalModal.close();
            state.pendingRemoval = null;
            ui.showToast(payload.message, 'updated');
            await loadPlacements(state.placementPage);
        } catch (error) {
            ui.showFormError(removalElement, error.message || 'The subject placement could not be removed.');
        } finally {
            button.disabled = false;
            button.innerHTML = original;
        }
    });

    document.getElementById('newProgramButton')?.addEventListener('click', openCreateProgram);
    document.getElementById('newCurriculumButton')?.addEventListener('click', openCreateCurriculum);
    document.getElementById('newVersionButton')?.addEventListener('click', openCreateVersion);
    document.getElementById('assignSubjectButton')?.addEventListener('click', openAssignment);

    if (curriculumProgramInput && curriculumProgramOptions && curriculumProgramCombobox) {
        const debouncedProgramSearch = ui.debounce(function () {
            searchProgramOptions(curriculumProgramInput.value);
        }, 300);

        curriculumProgramInput.addEventListener('input', function () {
            if (curriculumProgramInput.value !== programComboboxState.selectedLabel) {
                clearProgramSelection();
            }
            ui.clearFormError(curriculumForm);
            if (curriculumProgramInput.value.trim().length < 1) {
                clearProgramSuggestions(true);
                return;
            }
            debouncedProgramSearch();
        });
        curriculumProgramInput.addEventListener('keydown', async function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const query = curriculumProgramInput.value.trim();
                if (query.length < 1 || (programComboboxState.selectedId &&
                    curriculumProgramInput.value === programComboboxState.selectedLabel)) {
                    clearProgramSuggestions(true);
                    return;
                }
                if (curriculumProgramOptions.hidden || !programComboboxState.programs.length) {
                    await searchProgramOptions(query);
                }
                if (!programComboboxState.programs.length) return;
                const direction = event.key === 'ArrowDown' ? 1 : -1;
                const start = programComboboxState.activeIndex === -1
                    ? (direction === 1 ? 0 : programComboboxState.programs.length - 1)
                    : programComboboxState.activeIndex + direction;
                setActiveProgramOption(start);
            } else if (event.key === 'Enter' && !curriculumProgramOptions.hidden &&
                       programComboboxState.activeIndex >= 0) {
                event.preventDefault();
                selectProgram(programComboboxState.programs[programComboboxState.activeIndex]);
            } else if (event.key === 'Escape' && !curriculumProgramOptions.hidden) {
                event.preventDefault();
                event.stopPropagation();
                closeProgramSuggestions(true);
            } else if (event.key === 'Tab') {
                closeProgramSuggestions(true);
            }
        });
        curriculumProgramOptions.addEventListener('mousedown', function (event) {
            if (event.target.closest('[data-program-option]')) event.preventDefault();
        });
        curriculumProgramOptions.addEventListener('click', function (event) {
            const option = event.target.closest('[data-program-option]');
            if (!option) return;
            const program = programComboboxState.programs[Number(option.dataset.programOption)];
            selectProgram(program);
            curriculumProgramInput.focus();
        });
        document.addEventListener('click', function (event) {
            if (!curriculumProgramCombobox.contains(event.target)) closeProgramSuggestions(true);
        });
        document.getElementById('curriculumModal')?.querySelectorAll('[data-modal-close]').forEach(function (button) {
            button.addEventListener('click', function () { closeProgramSuggestions(true); });
        });
    }
    document.getElementById('assignmentSubjectSearch')?.addEventListener('input', ui.debounce(function () {
        ui.clearFormError(assignmentForm);
        loadSubjectOptions(document.getElementById('assignmentSubjectSearch').value);
    }, 300));

    if (workspacePage === 'programs') {
        document.getElementById('programSearchForm').addEventListener('submit', function (event) {
            event.preventDefault(); loadPrograms(1);
        });
        document.getElementById('programSearch').addEventListener('input', ui.debounce(function () { loadPrograms(1); }, 300));
        document.getElementById('programStatus').addEventListener('change', function () { loadPrograms(1); });
        programRows.addEventListener('click', function (event) {
            const button = event.target.closest('[data-edit-program]');
            if (button) openEditProgram(button.dataset.editProgram);
        });
        loadPrograms(1);
        return;
    }

    if (workspacePage !== 'curricula') return;

    document.getElementById('curriculumSearchForm').addEventListener('submit', function (event) {
        event.preventDefault(); loadCurricula(1);
    });
    document.getElementById('curriculumSearch').addEventListener('input', ui.debounce(function () { loadCurricula(1); }, 300));
    document.getElementById('curriculumStatus').addEventListener('change', function () { loadCurricula(1); });
    document.getElementById('curriculumPerPage').addEventListener('change', function () { loadCurricula(1); });

    curriculumRows.addEventListener('click', function (event) {
        const viewButton = event.target.closest('[data-view-curriculum]');
        const editButton = event.target.closest('[data-edit-curriculum]');
        if (viewButton) selectCurriculum(viewButton.dataset.viewCurriculum);
        if (editButton) openEditCurriculum(editButton.dataset.editCurriculum);
    });
    versionRows.addEventListener('click', function (event) {
        const button = event.target.closest('[data-version-id]');
        if (button) selectVersion(button.dataset.versionId, button.dataset.versionLabel);
    });
    placementRows.addEventListener('click', function (event) {
        const button = event.target.closest('[data-remove-assignment]');
        if (!button || !permissions.removeAssignments || !removalModal) return;
        state.pendingRemoval = {
            id: button.dataset.removeAssignment,
            label: button.dataset.subjectLabel
        };
        ui.clearFormError(removalElement);
        document.getElementById('removeAssignmentMessage').textContent =
            'Remove ' + state.pendingRemoval.label + ' from this curriculum version? The Subject Master record will remain.';
        removalModal.open();
        document.getElementById('confirmRemoveAssignment').focus();
    });

    loadCurricula(1).then(function () {
        const initialCurriculumId = Number(workspace.dataset.initialCurriculumId);
        if (Number.isInteger(initialCurriculumId) && initialCurriculumId > 0) {
            selectCurriculum(initialCurriculumId);
        }
    });
    refreshProgramAvailability();
}());
