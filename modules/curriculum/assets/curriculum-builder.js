(function () {
    'use strict';

    const workspace = document.getElementById('curriculumWorkspace');
    if (!workspace || !window.CurriculumUI) return;

    const ui = window.CurriculumUI;
    const workspacePage = workspace.dataset.workspacePage;
    const curriculaView = workspace.dataset.curriculaView || 'program-list';
    const programCategory = workspace.dataset.programCategory || '';
    const initialProgramId = Number(workspace.dataset.initialProgramId) || 0;
    const initialCurriculumId = Number(workspace.dataset.initialCurriculumId) || 0;
    const initialVersionId = Number(workspace.dataset.initialVersionId) || 0;
    const endpoints = {
        programs: workspace.dataset.programApi,
        subjects: workspace.dataset.subjectApi,
        curricula: workspace.dataset.curriculumApi,
        versions: workspace.dataset.versionApi,
        assignments: workspace.dataset.assignmentApi
        ,versionReferences: workspace.dataset.versionReferenceApi
        ,compliance: workspace.dataset.complianceApi
    };
    const permissions = {
        viewPrograms: workspace.dataset.canViewPrograms === '1',
        viewCurricula: workspace.dataset.canViewCurricula === '1',
        createPrograms: workspace.dataset.canCreatePrograms === '1',
        updatePrograms: workspace.dataset.canUpdatePrograms === '1',
        createCurricula: workspace.dataset.canCreateCurricula === '1',
        updateCurricula: workspace.dataset.canUpdateCurricula === '1',
        createVersions: workspace.dataset.canCreateVersions === '1',
        assignSubjects: workspace.dataset.canAssignSubjects === '1',
        removeAssignments: workspace.dataset.canRemoveAssignments === '1'
        ,attachReferences: workspace.dataset.canAttachReferences === '1'
        ,classifyReference: workspace.dataset.canClassifyReference === '1'
        ,cloneReference: workspace.dataset.canCloneReference === '1'
    };
    const columns = {
        programs: permissions.updatePrograms ? 6 : 5,
        curricula: 7,
        versions: 1,
        placements: 1
    };
    const state = {
        selectedCurriculumId: null,
        selectedCurriculum: null,
        selectedVersionId: null,
        selectedVersionLabel: '',
        selectedVersionStatus: 'draft',
        programPage: 1,
        curriculumPage: 1,
        versionPage: 1,
        placementPage: 1,
        hasPrograms: false,
        pendingRemoval: null,
        programRequest: 0,
        curriculumRequest: 0,
        detailRequest: 0,
        programDetailsRequest: 0,
        versionRequest: 0,
        placementRequest: 0,
        placements: [],
        selectedYear: '',
        viewingProgram: null,
        pageProgram: null,
    };

    window.CSMSPageCIMFContext = () => ({academic_program_id:state.selectedCurriculum?.academic_program_id || state.pageProgram?.id || state.viewingProgram?.id || '',curriculum_id:state.selectedCurriculumId || '',curriculum_version_id:state.selectedVersionId || ''});
    const programRows = document.getElementById('programRows');
    const curriculumProgramRows = document.getElementById('curriculumProgramRows');
    const programCurriculaCards = document.getElementById('programCurriculaCards');
    const curriculumRows = document.getElementById('curriculumRows');
    const versionRows = document.getElementById('versionRows');
    const placementRows = document.getElementById('placementRows');

    const programForm = document.getElementById('programForm');
    const curriculumForm = document.getElementById('curriculumForm');
    const versionForm = document.getElementById('versionForm');
    const versionRenameForm = document.getElementById('versionRenameForm');
    const assignmentForm = document.getElementById('assignmentForm');
    const programModal = ui.createModal(document.getElementById('programModal'));
    const programDetailsModal = ui.createModal(document.getElementById('programDetailsModal'));
    const programArchiveModal = ui.createModal(document.getElementById('programArchiveModal'));
    const curriculumModal = ui.createModal(document.getElementById('curriculumModal'));
    const curriculumDetailModal = ui.createModal(document.getElementById('curriculumDetail'));
    const curriculumArchiveModal = ui.createModal(document.getElementById('curriculumArchiveModal'));
    const versionWorkspace = document.getElementById('versionWorkspace');
    const versionModal = ui.createModal(document.getElementById('versionModal'));
    const versionRenameModal = ui.createModal(document.getElementById('versionRenameModal'));
    const assignmentModal = ui.createModal(document.getElementById('assignmentModal'));
    const removalElement = document.getElementById('removeAssignmentModal');
    const removalModal = ui.createModal(removalElement);
    let programFormMode = 'create';
    let curriculumFormMode = 'create';
    const curriculumProgramCombobox = document.getElementById('curriculumProgramCombobox');
    const curriculumProgramInput = document.getElementById('curriculumProgramInput');
    const curriculumProgramId = document.getElementById('curriculumProgramId');
    const curriculumProgramOptions = document.getElementById('curriculumProgramOptions');
    const curriculumProgramAutocomplete = ui.createAutocomplete({
        root: curriculumProgramCombobox,
        input: curriculumProgramInput,
        valueInput: curriculumProgramId,
        list: curriculumProgramOptions,
        status: document.getElementById('programRequirementMessage'),
        helpText: 'Select an existing academic program from the suggestions.',
        selectedText: 'Selected from the current Academic Program master records.',
        loadingText: 'Searching programs…',
        noResultsText: 'No matching academic programs found.',
        errorText: 'Academic programs could not be loaded.',
        search: async function (query) {
            const payload = await ui.fetchJson(endpoints.programs, {
                action: 'list', page: 1, per_page: 20, search: query, status: ''
            });
            return payload.programs;
        },
        getId: program => program.id,
        format: program => ({
            label: program.program_code + ' — ' + program.program_name,
            code: program.program_code,
            title: program.program_name,
            meta: program.program_type || ''
        })
    });
    const assignmentSubjectInput = document.getElementById('assignmentSubjectInput');
    const assignmentSubjectAutocomplete = ui.createAutocomplete({
        input: assignmentSubjectInput,
        valueInput: document.getElementById('assignmentSubjectId'),
        list: document.getElementById('assignmentSubjectOptions'),
        status: document.getElementById('assignmentSubjectHint'),
        helpText: 'Type a subject code or title, then select a match.',
        selectedText: 'Subject selected. You can change it by editing the field.',
        loadingText: 'Searching subjects…',
        noResultsText: 'No matching subjects found.',
        errorText: 'Subject Master records could not be loaded.',
        search: async function (query) {
            const payload = await ui.fetchJson(endpoints.subjects, {
                action: 'list', page: 1, per_page: 20, search: query, status: ''
            });
            return payload.subjects;
        },
        getId: subject => subject.id,
        format: subject => ({
            label: subject.subject_code + ' — ' + subject.subject_title,
            code: subject.subject_code,
            title: subject.subject_title
        }),
        onError: error => ui.showFormError(assignmentForm, error.message || 'Subject Master records could not be loaded.')
    });
    const contextProgramAutocomplete = ui.createAutocomplete({
        input: document.getElementById('curriculumContextProgramInput'),
        valueInput: document.getElementById('curriculumContextProgramId'),
        list: document.getElementById('curriculumContextProgramOptions'),
        helpText: 'All academic programs', selectedText: 'Program context selected.',
        loadingText: 'Searching programs…', noResultsText: 'No matching programs found.',
        search: async function (query) {
            const payload = await ui.fetchJson(endpoints.programs, {
                action: 'list', page: 1, per_page: 20, search: query, status: ''
            });
            return payload.programs;
        },
        getId: item => item.id,
        format: item => ({ label: item.program_code + ' — ' + item.program_name,
            code: item.program_code, title: item.program_name, meta: item.program_type || '' }),
        onSelect: function () { resetCurriculumContext(); loadCurricula(1); },
        onClear: function () { resetCurriculumContext(); loadCurricula(1); }
    });
    const contextCurriculumAutocomplete = ui.createAutocomplete({
        input: document.getElementById('curriculumContextCurriculumInput'),
        valueInput: document.getElementById('curriculumContextCurriculumId'),
        list: document.getElementById('curriculumContextCurriculumOptions'),
        helpText: 'Select a curriculum', selectedText: 'Curriculum context selected.',
        loadingText: 'Searching curricula…', noResultsText: 'No matching curricula found.',
        search: async function (query) {
            const payload = await ui.fetchJson(endpoints.curricula, {
                action: 'list', page: 1, per_page: 20, search: query, status: '',
                academic_program_id: contextProgramAutocomplete?.getSelectedId() || ''
            });
            return payload.curricula;
        },
        getId: item => item.id,
        format: item => ({ label: item.curriculum_code + ' — ' + item.curriculum_name,
            code: item.curriculum_code, title: item.curriculum_name }),
        onSelect: function (item, id) { setCurriculumContext(id); },
        onClear: function () { clearSelectedCurriculumContext(); }
    });
    const contextVersionInput = document.getElementById('curriculumContextVersionInput') || { disabled: true, placeholder: '' };
    const contextVersionAutocomplete = ui.createAutocomplete({
        input: contextVersionInput,
        valueInput: document.getElementById('curriculumContextVersionId'),
        list: document.getElementById('curriculumContextVersionOptions'),
        helpText: 'Select a curriculum first', selectedText: 'Version context selected.',
        loadingText: 'Searching versions…', noResultsText: 'No matching versions found.',
        search: async function (query) {
            const curriculumId = contextCurriculumAutocomplete?.getSelectedId();
            if (!curriculumId) return [];
            const payload = await ui.fetchJson(endpoints.versions, {
                action: 'list', curriculum_id: curriculumId,
                page: 1, per_page: 20, search: query
            });
            return payload.versions;
        },
        getId: item => item.id,
        format: item => ({ label: item.version_label +
            (item.effective_academic_year ? ' — ' + item.effective_academic_year : ''),
            code: item.version_label, title: item.effective_academic_year || item.status }),
        onSelect: function (item) { setVersionContext(item.id, item.version_label); },
        onClear: function () { clearSelectedVersionContext(); }
    });

    function tableState(element, columnCount, message, isError) {
        if (!element) return;
        if (element === placementRows || element.tagName !== 'TBODY') {
            element.innerHTML = '<p class="state-cell' + (isError ? ' error-state' : '') + '">' + ui.escapeHtml(message) + '</p>';
            return;
        }
        element.innerHTML = '<tr class="state-row"><td colspan="' + columnCount + '" class="state-cell' +
            (isError ? ' error-state' : '') + '">' + ui.escapeHtml(message) + '</td></tr>';
    }

    function renderCompactPagination(container, pagination, onPage, noun) {
        ui.renderPagination(container, pagination, onPage);
        if (!container || Number(pagination?.total_pages) > 1) return;
        const summary = container.querySelector('.pagination-summary');
        const actions = container.querySelector('.pagination-actions');
        if (summary) {
            const total = Number(pagination.total_records) || 0;
            summary.textContent = total ? total + ' ' + noun : 'No ' + noun + ' found';
        }
        if (actions) actions.hidden = true;
    }

    function setButtonEnabled(id, enabled) {
        const button = document.getElementById(id);
        if (button) button.disabled = !enabled;
    }

    function clearSelectedVersionContext() {
        state.selectedVersionId = null;
        state.selectedVersionLabel = '';
        state.selectedVersionStatus = 'draft';
        if (versionWorkspace) versionWorkspace.hidden = true;
        document.querySelectorAll('[data-version-id]').forEach(function (button) {
            button.classList.remove('selected');
        });
        setButtonEnabled('assignSubjectButton', false);
    }

    function clearSelectedCurriculumContext() {
        state.selectedCurriculumId = null;
        state.selectedCurriculum = null;
        contextVersionAutocomplete?.clear();
        curriculumDetailModal?.close();
        document.querySelectorAll('[data-view-curriculum]').forEach(function (button) {
            button.classList.remove('selected');
        });
        clearSelectedVersionContext();
    }

    function resetCurriculumContext() {
        state.selectedCurriculumId = null;
        state.selectedCurriculum = null;
        contextCurriculumAutocomplete?.clear();
        clearSelectedCurriculumContext();
    }

    function setCurriculumContext(id, curriculum) {
        const curriculumId = Number(id);
        if (!curriculumId) return clearSelectedCurriculumContext();
        state.selectedCurriculumId = curriculumId;
        state.selectedCurriculum = curriculum || null;
        contextVersionAutocomplete?.clear();
        contextVersionInput.disabled = false;
        contextVersionInput.placeholder = 'Search version label or year';
        curriculumDetailModal?.close();
        clearSelectedVersionContext();
        if (curriculum) {
            contextProgramAutocomplete?.setSelected({
                id: curriculum.academic_program_id, program_code: curriculum.program_code,
                program_name: curriculum.program_name, program_type: curriculum.program_type
            }, false);
            contextCurriculumAutocomplete?.setSelected({
                id: curriculum.id, curriculum_code: curriculum.curriculum_code,
                curriculum_name: curriculum.curriculum_name
            }, false);
        }
    }

    function setVersionContext(id, label, status) {
        const versionId = Number(id);
        if (!versionId) return clearSelectedVersionContext();
        state.selectedVersionId = versionId;
        state.selectedVersionLabel = String(label || '');
        state.selectedVersionStatus = String(status || 'draft');
        contextVersionAutocomplete?.setSelected({
            id: versionId, version_label: state.selectedVersionLabel, status: ''
        }, false);
        contextVersionInput.disabled = false;
        if (versionWorkspace) versionWorkspace.hidden = true;
        setButtonEnabled('assignSubjectButton', false);
    }

    async function openProgramDetails(id) {
        if (!permissions.viewPrograms || !programDetailsModal) return;
        const sequence = ++state.programDetailsRequest;
        state.viewingProgramId = Number(id);
        state.viewingProgram = null;
        programDetailsModal.open();
        document.getElementById('programDetailsName').textContent = 'Loading program...';
        document.getElementById('programDetailsCode').textContent = '';
        document.getElementById('programDetailsType').textContent = '—';
        document.getElementById('programDetailsStatusBadge').innerHTML = '';
        if (permissions.updatePrograms) setButtonEnabled('programDetailsArchive', false);
        if (permissions.viewCurricula) {
            ['programCurriculaTotal', 'programVersionsTotal', 'programPlacementsTotal']
                .forEach(id => { document.getElementById(id).textContent = 'Loading...'; });
        }
        try {
            const payload = await ui.fetchJson(endpoints.programs, { action: 'get', id: id });
            if (sequence !== state.programDetailsRequest) return;
            const program = payload.program;
            state.viewingProgram = program;
            document.getElementById('programDetailsName').textContent = program.program_name || '—';
            document.getElementById('programDetailsCode').textContent = program.program_code || '';
            document.getElementById('programDetailsType').textContent = programDegreeLevel(program);
            document.getElementById('programDetailsStatusBadge').innerHTML = ui.statusBadge(program.status);
            const curriculaLink = document.getElementById('programSeeCurricula');
            if (curriculaLink) curriculaLink.href = 'curricula.php?program_id=' + Number(program.id);
            if (permissions.updatePrograms) setButtonEnabled('programDetailsArchive', String(program.status).toLowerCase() !== 'inactive');
            if (permissions.viewCurricula) {
                try {
                    await loadProgramCurriculumSummary(program, sequence);
                } catch (summaryError) {
                    if (sequence !== state.programDetailsRequest) return;
                    ['programCurriculaTotal', 'programVersionsTotal', 'programPlacementsTotal']
                        .forEach(function (fieldId) { document.getElementById(fieldId).textContent = 'Unavailable'; });
                }
            }
        } catch (error) {
            if (sequence !== state.programDetailsRequest) return;
            document.getElementById('programDetailsName').textContent = 'Program unavailable';
            document.getElementById('programDetailsCode').textContent = error.message || 'The record could not be loaded.';
        }
    }

    async function loadProgramCurriculumSummary(program, request) {
        const curriculaPayload = await ui.fetchJson(endpoints.curricula, {
            action: 'list', page: 1, per_page: 50, search: '', status: '',
            academic_program_id: program.id
        });
        if (request !== state.programDetailsRequest) return;
        const curricula = curriculaPayload.curricula || [];
        let versionCount = 0;
        let placementCount = 0;

        for (const curriculum of curricula) {
            const versionsPayload = await ui.fetchJson(endpoints.versions, {
                action: 'list', curriculum_id: curriculum.id, page: 1, per_page: 50
            });
            if (request !== state.programDetailsRequest) return;
            for (const version of versionsPayload.versions || []) {
                const assignments = await window.CurriculumPlacementView.load(
                    page => ui.fetchJson(endpoints.assignments, {
                        action: 'list', curriculum_version_id: version.id, page: page, per_page: 50
                    }),
                    () => request === state.programDetailsRequest
                );
                if (request !== state.programDetailsRequest) return;
                if (assignments === null) return;
                versionCount += 1;
                placementCount += assignments.length;
            }
        }
        if (request !== state.programDetailsRequest) return;
        document.getElementById('programCurriculaTotal').textContent = String(curricula.length);
        document.getElementById('programVersionsTotal').textContent = String(versionCount);
        document.getElementById('programPlacementsTotal').textContent = String(placementCount);
    }

    function openProgramArchiveConfirmation() {
        if (!permissions.updatePrograms || !state.viewingProgram || !programArchiveModal) return;
        document.getElementById('programArchiveMessage').textContent =
            'Are you sure you want to archive ' + (state.viewingProgram.program_code || 'this academic program') + '?';
        programArchiveModal.open();
    }

    async function openProgramArchiveForId(id) {
        if (!permissions.updatePrograms || !programArchiveModal) return;
        try {
            const payload = await ui.fetchJson(endpoints.programs, { action: 'get', id: id });
            state.viewingProgram = payload.program;
            openProgramArchiveConfirmation();
        } catch (error) {
            ui.showToast(error.message || 'The academic program could not be loaded.', 'error');
        }
    }

    async function archiveViewingProgram() {
        const program = state.viewingProgram;
        if (!permissions.updatePrograms || !program) return;
        const button = document.getElementById('confirmProgramArchive');
        const original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Archiving...';
        const formData = new FormData();
        formData.set('action', 'archive');
        formData.set('id', program.id);
        try {
            const payload = await ui.postForm(endpoints.programs, formData);
            programArchiveModal?.close();
            ui.showToast('Academic program archived.', 'success');
            if (workspacePage === 'curricula') {
                if (curriculaView === 'program-list') await loadCurriculaPrograms(state.programPage);
                else if (curriculaView === 'program-curricula') await loadProgramCurricula();
                else await loadCurriculumWorkspace();
            } else {
                await loadPrograms(state.programPage);
                await openProgramDetails(payload.program.id);
            }
        } catch (error) {
            ui.showToast(error.message || 'The academic program could not be archived.', 'error');
        } finally {
            button.disabled = false;
            button.innerHTML = original;
        }
    }

    function programDegreeLevel(program) {
        return program.program_type || 'Not specified';
    }

    function syncProgramDegreeLevel() {
        const level = document.getElementById('programTypeLevel');
        const other = document.getElementById('programTypeOther');
        const storedValue = document.getElementById('programType');
        other.hidden = level.value !== 'Other';
        storedValue.value = level.value === 'Other' ? (other.value.trim() || 'Other') : level.value;
    }

    function setProgramDegreeLevel(program) {
        const level = document.getElementById('programTypeLevel');
        const other = document.getElementById('programTypeOther');
        const options = ["Bachelor's Degree", 'Associate Degree', 'Diploma / Certificate', 'Senior High School', 'Other'];
        const stored = String(program.program_type || '');
        const value = stored;
        other.value = '';
        if (options.includes(value)) {
            level.value = value;
        } else if (value) {
            level.value = 'Other';
            other.value = value;
        } else {
            level.value = '';
        }
        syncProgramDegreeLevel();
    }

    async function loadProgramCategoryCounts() {
        const collegeCount = document.getElementById('collegeProgramCount');
        const shsCount = document.getElementById('shsProgramCount');
        if (!collegeCount || !shsCount) return;
        const categories = [
            { category: 'college', element: collegeCount, noun: total => total === 1 ? 'program' : 'programs' },
            { category: 'shs', element: shsCount, noun: total => total === 1 ? 'strand' : 'strands' }
        ];
        await Promise.all(categories.map(async item => {
            try {
                const payload = await ui.fetchJson(endpoints.programs, {
                    action: 'list', page: 1, per_page: 10, search: '', status: '', program_category: item.category
                });
                const total = Number(payload.pagination.total_records) || 0;
                item.element.textContent = total + ' ' + item.noun(total);
                item.element.hidden = false;
            } catch (error) {
                item.element.hidden = true;
            }
        }));
    }

    function programRow(program, category) {
        const actions = permissions.updatePrograms
            ? '<td class="row-actions"><div class="table-actions program-row-actions"><button type="button" class="row-action-button" data-edit-program="' +
                Number(program.id) + '"><i class="fa-solid fa-pen"></i> Edit</button> ' +
                '<button type="button" class="remove-action-button" data-archive-program="' + Number(program.id) + '"><i class="fa-solid fa-box-archive"></i> Archive</button></div></td>'
            : '';
        const degreeLevelCell = category === 'shs' ? '' : '<td>' + ui.escapeHtml(programDegreeLevel(program)) + '</td>';
        return '<tr data-record-view="' + Number(program.id) + '" tabindex="0" aria-label="View academic program ' +
            ui.escapeHtml(program.program_code) + '">' +
            '<td><span class="record-code">' + ui.escapeHtml(program.program_code) + '</span></td>' +
            '<td><span class="record-name">' + ui.escapeHtml(program.program_name) + '</span></td>' +
            degreeLevelCell +
            '<td>' + (permissions.viewCurricula ? (Number(program.curriculum_count) || 0) : '—') + '</td>' +
            '<td>' + ui.statusBadge(program.status) + '</td>' + actions +
            '</tr>';
    }

    async function loadPrograms(page) {
        if (!permissions.viewPrograms || !programRows) return;
        const programColumns = (programCategory === 'shs' ? 4 : 5) + (permissions.updatePrograms ? 1 : 0);
        const sequence = ++state.programRequest;
        state.programPage = Number(page) || 1;
        tableState(programRows, programColumns, 'Loading academic programs…', false);
        try {
            const payload = await ui.fetchJson(endpoints.programs, {
                action: 'list', page: state.programPage, per_page: 10,
                search: document.getElementById('programSearch').value.trim(),
                status: document.getElementById('programStatus').value,
                program_type: document.getElementById('programDegreeLevelFilter')?.value || '',
                program_category: programCategory
            });
            if (sequence !== state.programRequest) return;
            state.programPage = Number(payload.pagination.page);
            ui.setCount(document.getElementById('programCount'), Number(payload.pagination.total_records));
            if (!payload.programs.length) {
                if (programCategory === 'shs') {
                    const createAction = permissions.createPrograms
                        ? '<br><button type="button" class="secondary-action-button shs-empty-create" id="shsEmptyNewProgram"><i class="fa-solid fa-plus"></i> New Program</button>'
                        : '';
                    programRows.innerHTML = '<tr class="state-row"><td colspan="' + programColumns + '" class="state-cell">No Senior High School strands found.' + createAction + '</td></tr>';
                    document.getElementById('shsEmptyNewProgram')?.addEventListener('click', openCreateProgram);
                } else {
                    const message = permissions.createPrograms
                        ? 'No academic programs found. Use New Program to create the first matching record.'
                        : 'No academic programs found.';
                    tableState(programRows, programColumns, message, false);
                }
            } else {
                programRows.innerHTML = payload.programs.map(program => programRow(program, programCategory)).join('');
            }
            renderCompactPagination(document.getElementById('programPagination'), payload.pagination, loadPrograms, 'programs');
        } catch (error) {
            if (sequence !== state.programRequest) return;
            ui.setCount(document.getElementById('programCount'), 0);
            document.getElementById('programPagination').innerHTML = '';
            tableState(programRows, programColumns, error.message || 'Academic programs could not be loaded.', true);
        }
    }

    function curriculumProgramRow(program) {
        const actions = permissions.updatePrograms
            ? '<td class="row-actions"><button type="button" class="row-action-button" data-edit-group-program="' + Number(program.id) + '"><i class="fa-solid fa-pen"></i> Edit</button> ' +
                '<button type="button" class="remove-action-button" data-archive-group-program="' + Number(program.id) + '"><i class="fa-solid fa-box-archive"></i> Archive</button></td>'
            : '';
        return '<tr data-program-view="' + Number(program.id) + '" tabindex="0" role="link" aria-label="Open curricula for ' +
            ui.escapeHtml(program.program_name + ' ' + program.program_code) + '">' +
            '<td><span class="record-code">' + ui.escapeHtml(program.program_code) + '</span></td>' +
            '<td><span class="record-name">' + ui.escapeHtml(program.program_name) + '</span></td>' +
            '<td>' + (Number(program.curriculum_count) || 0) + '</td>' +
            '<td>' + ui.statusBadge(program.status) + '</td>' + actions + '</tr>';
    }

    async function loadCurriculaPrograms(page) {
        if (!curriculumProgramRows) return;
        const sequence = ++state.programRequest;
        state.programPage = Number(page) || 1;
        const columnCount = permissions.updatePrograms ? 5 : 4;
        tableState(curriculumProgramRows, columnCount, 'Loading programs...', false);
        try {
            const payload = await ui.fetchJson(endpoints.programs, {
                action: 'list', page: state.programPage, per_page: 20,
                search: document.getElementById('curriculumProgramSearch').value.trim(),
                status: document.getElementById('curriculumProgramStatus').value,
                program_category: document.getElementById('curriculumAcademicLevel').value
            });
            if (sequence !== state.programRequest) return;
            state.programPage = Number(payload.pagination.page);
            ui.setCount(document.getElementById('curriculumProgramCount'), Number(payload.pagination.total_records));
            if (!payload.programs.length) {
                tableState(curriculumProgramRows, columnCount, 'No academic programs or strands found.', false);
            } else {
                curriculumProgramRows.innerHTML = payload.programs.map(curriculumProgramRow).join('');
            }
            renderCompactPagination(document.getElementById('curriculumProgramPagination'), payload.pagination, loadCurriculaPrograms, 'programs');
        } catch (error) {
            if (sequence !== state.programRequest) return;
            ui.setCount(document.getElementById('curriculumProgramCount'), 0);
            tableState(curriculumProgramRows, columnCount, error.message || 'Academic programs could not be loaded.', true);
        }
    }

    function programCurriculumCard(curriculum) {
        const href = 'curricula.php?program_id=' + Number(curriculum.academic_program_id) + '&curriculum_id=' + Number(curriculum.id);
        const versionCount = Number(curriculum.version_count) || 0;
        const subjectCount = Number(curriculum.subject_count) || 0;
        return '<a class="subject-program-card curriculum-record-card" href="' + href + '" aria-label="Open curriculum ' +
            ui.escapeHtml(curriculum.curriculum_code + ' ' + curriculum.curriculum_name) + '">' +
            '<span class="subject-program-icon" aria-hidden="true"><i class="fa-solid fa-book-open"></i></span>' +
            '<strong>' + ui.escapeHtml(curriculum.curriculum_code) + '</strong>' +
            '<span class="subject-program-name">' + ui.escapeHtml(curriculum.curriculum_name) + '</span>' +
            '<span class="curriculum-record-kind">Curriculum</span>' +
            '<span class="curriculum-record-status">' + ui.statusBadge(curriculum.status) + '</span>' +
            '<span class="subject-program-meta"><small>' + versionCount + ' ' + (versionCount === 1 ? 'Version' : 'Versions') +
            '</small><small>' + subjectCount + ' ' + (subjectCount === 1 ? 'Subject' : 'Subjects') + '</small></span></a>';
    }

    async function loadProgramCurricula() {
        if (!programCurriculaCards || !initialProgramId) return;
        programCurriculaCards.innerHTML = '<p class="state-cell">Loading curricula...</p>';
        try {
            const payload = await ui.fetchJson(endpoints.programs, { action: 'get', id: initialProgramId });
            state.pageProgram = payload.program;
            state.hasPrograms = true;
            setButtonEnabled('newCurriculumButton', permissions.createCurricula);
            document.getElementById('selectedProgramCode').textContent = payload.program.program_code || 'Program';
            document.getElementById('selectedProgramName').textContent = payload.program.program_name || '';
            const curricula = [];
            let page = 1;
            let totalPages = 1;
            let totalRecords = 0;
            do {
                const list = await ui.fetchJson(endpoints.curricula, {
                    action: 'list', page: page, per_page: 50, search: '', status: '', academic_program_id: initialProgramId
                });
                curricula.push(...(list.curricula || []));
                totalRecords = Number(list.pagination?.total_records) || curricula.length;
                totalPages = Number(list.pagination?.total_pages) || 1;
                page += 1;
            } while (page <= totalPages);
            const count = totalRecords;
            document.getElementById('selectedProgramCurriculumCount').textContent = count + (count === 1 ? ' Curriculum' : ' Curricula');
            if (!curricula.length) {
                programCurriculaCards.innerHTML = '<div class="curriculum-empty-state"><p>No curricula created yet.</p>' +
                    (permissions.createCurricula ? '<button type="button" class="primary-action-button" id="emptyNewCurriculum"><i class="fa-solid fa-plus"></i> New Curriculum</button>' : '') + '</div>';
                document.getElementById('emptyNewCurriculum')?.addEventListener('click', openCreateCurriculum);
                return;
            }
            programCurriculaCards.innerHTML = curricula.map(programCurriculumCard).join('');
        } catch (error) {
            setButtonEnabled('newCurriculumButton', false);
            programCurriculaCards.innerHTML = '<p class="state-cell error-state">' + ui.escapeHtml(error.message || 'Program curricula could not be loaded.') + '</p>';
        }
    }

    async function loadCurriculumWorkspace() {
        if (!initialCurriculumId) return;
        const panel = document.getElementById('curriculumWorkspacePanel');
        const sequence = ++state.detailRequest;
        try {
            const payload = await ui.fetchJson(endpoints.curricula, { action: 'get', id: initialCurriculumId });
            if (sequence !== state.detailRequest) return;
            const curriculum = payload.curriculum;
            state.selectedCurriculumId = Number(curriculum.id);
            state.selectedCurriculum = curriculum;
            setButtonEnabled('newVersionButton', permissions.createVersions);
            document.getElementById('workspaceCurriculumName').textContent = curriculum.curriculum_name || 'Curriculum';
            document.getElementById('curriculumHeaderCode').textContent = curriculum.curriculum_code || '';
            document.getElementById('workspaceCurriculumStatus').innerHTML = ui.statusBadge(curriculum.status);
            const archiveButton = document.getElementById('archiveCurriculumButton');
            if (archiveButton) archiveButton.hidden = curriculum.status !== 'active';
            document.getElementById('backToProgramCurricula').href = 'curricula.php?program_id=' + Number(curriculum.academic_program_id);
            document.getElementById('backToCurricula').href = 'curricula.php?program_id=' + Number(curriculum.academic_program_id) + '&curriculum_id=' + Number(curriculum.id);
            document.getElementById('backProgramCurriculaLabel').textContent = curriculum.program_code || 'Program Curricula';
            document.getElementById('versionBackCurriculumLabel').textContent = curriculum.curriculum_code || 'Curriculum';
            await loadVersions(1, initialVersionId || null, Boolean(initialVersionId));
            if (initialVersionId && Number(state.selectedVersionId) !== initialVersionId) {
                try {
                    const versionPayload = await ui.fetchJson(endpoints.versions, { action: 'get', id: initialVersionId });
                    const version = versionPayload.version;
                    if (Number(version.curriculum_id) === Number(curriculum.id)) {
                        await openVersionSubjects(version.id, version.version_label, version.status);
                    }
                } catch (error) {
                    ui.showToast(error.message || 'The requested curriculum version could not be opened.', 'warning');
                }
            }
        } catch (error) {
            if (sequence === state.detailRequest && panel) {
                panel.innerHTML = '<p class="state-cell error-state">' + ui.escapeHtml(error.message || 'The curriculum could not be loaded.') + '</p>';
            }
        }
    }

    function curriculumRow(curriculum) {
        const selected = Number(curriculum.id) === state.selectedCurriculumId;
        const edit = permissions.updateCurricula
            ? '<button type="button" class="row-action-button" data-edit-curriculum="' + Number(curriculum.id) +
                '"><i class="fa-solid fa-pen"></i> Edit</button>'
            : '';
        return '<tr data-record-view="' + Number(curriculum.id) + '" tabindex="0" aria-label="View ' +
            ui.escapeHtml(curriculum.curriculum_code + ' ' + curriculum.curriculum_name) + '">' +
            '<td><span class="record-code">' + ui.escapeHtml(curriculum.curriculum_code) + '</span></td>' +
            '<td><span class="record-name">' + ui.escapeHtml(curriculum.curriculum_name) + '</span></td>' +
            '<td><span class="record-name">' + ui.escapeHtml(curriculum.program_name) + '</span>' +
                '<br><span class="subtle-text">' + ui.escapeHtml(curriculum.program_code) + '</span></td>' +
            '<td>' + (Number(curriculum.version_count) || 0) + '</td>' +
            '<td>' + (Number(curriculum.subject_count) || 0) + '</td>' +
            '<td>' + ui.statusBadge(curriculum.status) + '</td>' +
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
                per_page: 20,
                search: document.getElementById('curriculumSearch').value.trim(),
                status: document.getElementById('curriculumStatus').value,
                academic_program_id: document.getElementById('curriculumProgramFilter').value,
            });
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
            renderCompactPagination(document.getElementById('curriculumPagination'), payload.pagination, loadCurricula, 'curricula');
            return payload.curricula;
        } catch (error) {
            if (sequence !== state.curriculumRequest) return;
            ui.setCount(document.getElementById('curriculumCount'), 0);
            document.getElementById('curriculumPagination').innerHTML = '';
            tableState(curriculumRows, columns.curricula, error.message || 'Curricula could not be loaded.', true);
            return [];
        }
    }

    function selectProgram(program) {
        if (!program || !curriculumProgramInput || !curriculumProgramId) return;
        curriculumProgramAutocomplete?.setSelected(program);
        const message = document.getElementById('programRequirementMessage');
        if (message) message.textContent = 'Selected from the current Academic Program master records.';
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
        document.getElementById('curriculumDetailContent').hidden = false;
        document.getElementById('detailCurriculumName').textContent = 'Loading curriculum…';
        document.getElementById('detailCurriculumCode').textContent = '';
        document.getElementById('detailCurriculumStatus').innerHTML = '';
        document.getElementById('detailProgramCode').textContent = '';
        tableState(versionRows, columns.versions, 'Loading curriculum versions…', false);
        tableState(placementRows, columns.placements, 'Select a curriculum version to view assigned subjects.', false);
        document.getElementById('versionPagination').innerHTML = '';
        document.getElementById('placementPagination').innerHTML = '';
        ui.setCount(document.getElementById('versionCount'), 0);
        setButtonEnabled('newVersionButton', false);
        setButtonEnabled('assignSubjectButton', false);
    }

    async function restoreCurriculumContext(id) {
        try {
            const payload = await ui.fetchJson(endpoints.curricula, { action: 'get', id: id });
            const curriculum = payload.curriculum;
            setCurriculumContext(curriculum.id, curriculum);
            await loadCurricula(1);
            const versions = await ui.fetchJson(endpoints.versions, {
                action: 'list', curriculum_id: curriculum.id, page: 1, per_page: 50
            });
            const version = versions.versions.find(function (item) {
                return String(item.status).toLowerCase() === 'active';
            }) || versions.versions[0];
            if (version) setVersionContext(version.id, version.version_label);
        } catch (error) {
            ui.showToast(error.message || 'Curriculum context could not be restored.', 'warning');
        }
    }

    async function openCurriculumDetails(id) {
        state.selectedCurriculumId = Number(id);
        state.selectedCurriculum = null;
        state.selectedVersionId = null;
        state.selectedVersionLabel = '';
        showDetailLoading();
        curriculumDetailModal?.open();
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
            document.getElementById('detailProgramCode').textContent = curriculum.program_code;
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

    async function loadVersions(page, selectVersionId, openSelectedVersion) {
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
                const summaries = await Promise.all(payload.versions.map(async function (version) {
                    try {
                        const assignments = await window.CurriculumPlacementView.load(
                            pageNumber => ui.fetchJson(endpoints.assignments, {
                                action: 'list', curriculum_version_id: version.id,
                                page: pageNumber, per_page: 50
                            }),
                            () => sequence === state.versionRequest && curriculumId === state.selectedCurriculumId
                        );
                        if (assignments === null) return null;
                        return {
                            count: assignments.length,
                            cents: assignments.reduce(function (total, assignment) {
                                const units = Number(assignment.units);
                                return total + (Number.isFinite(units) ? Math.round(units * 100) : 0);
                            }, 0)
                        };
                    } catch (error) {
                        return null;
                    }
                }));
                if (sequence !== state.versionRequest || curriculumId !== state.selectedCurriculumId) return;
                versionRows.innerHTML = payload.versions.map(function (version, index) {
                    const summary = summaries[index];
                    const summaryText = summary
                        ? summary.count + ' Subjects · ' + ui.formatUnits(summary.cents / 100) + ' Units'
                        : 'Summary unavailable';
                    return '<article class="version-card"><div><strong class="record-code">' + ui.escapeHtml(version.version_label) +
                        '</strong><p class="version-card-summary">' + ui.escapeHtml(summaryText) + '</p></div><div class="version-card-actions">' +
                        ui.statusBadge(version.status) + (permissions.updateCurricula
                            ? '<button type="button" class="view-button" data-rename-version-id="' + Number(version.id) +
                                '" data-version-label="' + ui.escapeHtml(version.version_label) + '"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit</button>'
                            : '') + '<a class="view-button" data-version-id="' +
                        Number(version.id) + '" data-version-label="' + ui.escapeHtml(version.version_label) +
                        '" data-version-status="' + ui.escapeHtml(version.status) + '" href="curricula.php?program_id=' +
                        Number(state.selectedCurriculum.academic_program_id) + '&amp;curriculum_id=' + Number(curriculumId) +
                        '&amp;version_id=' + Number(version.id) + '">Open Version</a></div></article>';
                }).join('');
            }
            ui.renderPagination(document.getElementById('versionPagination'), payload.pagination, loadVersions);
            if (selectVersionId) {
                const created = payload.versions.find(function (version) {
                    return Number(version.id) === Number(selectVersionId);
                });
                if (created) {
                    setVersionContext(created.id, created.version_label, created.status);
                    if (openSelectedVersion) await openVersionSubjects(created.id, created.version_label, created.status);
                }
            }
        } catch (error) {
            if (sequence !== state.versionRequest) return;
            ui.setCount(document.getElementById('versionCount'), 0);
            document.getElementById('versionPagination').innerHTML = '';
            tableState(versionRows, columns.versions, error.message || 'Curriculum versions could not be loaded.', true);
        }
    }

    async function openVersionSubjects(id, label, status) {
        const cloneDetails = document.getElementById('cloneReferenceDetails');
        if (cloneDetails) cloneDetails.hidden = true;
        const button = versionRows?.querySelector('[data-version-id="' + Number(id) + '"]');
        setVersionContext(id, label, button?.dataset.versionStatus || status || 'draft');
        curriculumDetailModal?.close();
        document.querySelector('.curriculum-title-bar').hidden = true;
        document.querySelector('.workspace-grid').hidden = true;
        versionWorkspace.hidden = false;
        setButtonEnabled('assignSubjectButton', true);
        document.getElementById('workspaceVersionTitle').textContent = state.selectedVersionLabel;
        document.getElementById('workspaceVersionStatus').innerHTML = ui.statusBadge(state.selectedVersionStatus);
        document.getElementById('workspaceProgramCode').textContent = state.selectedCurriculum?.program_code || '';
        document.getElementById('workspaceCurriculumCode').textContent = state.selectedCurriculum?.curriculum_code || '';
        document.getElementById('workspaceBreadcrumbVersion').textContent = state.selectedVersionLabel;
        const programId = Number(state.selectedCurriculum?.academic_program_id) || 0;
        const curriculumId = Number(state.selectedCurriculum?.id) || 0;
        document.getElementById('workspaceProgramCode').href = 'curricula.php?program_id=' + programId;
        document.getElementById('workspaceCurriculumCode').href = 'curricula.php?program_id=' + programId + '&curriculum_id=' + curriculumId;
        const exportLink = document.getElementById('versionExportLink');
        if (exportLink) exportLink.href = 'curriculum_export.php';
        await loadReferenceWorkspace(id);
        await loadPlacements();
    }

    function sourceKindLabel(kind) {
        return ({regulatory_document:'Regulatory document', ched_sample_curriculum:'CHED sample curriculum',
            institutional_curriculum:'Institution-provided curriculum', institutional_policy:'Institutional policy',
            supporting_document:'Supporting document', other_document:'Other documented source', unknown:'Unknown source kind'})[kind] || 'Unknown source kind';
    }

    function currentAssessmentLabel(assessment) {
        if (!assessment) return 'not assessed';
        if (assessment.status === 'stale_for_current_metadata') {
            return 'STALE — previous recorded result: ' + String(assessment.historical_result || 'unknown');
        }
        return String(assessment.status || 'not assessed').replaceAll('_', ' ');
    }

    async function loadReferenceWorkspace(versionId) {
        const formAttach = document.getElementById('attachVersionReferenceForm');
        const formClassify = document.getElementById('classifyReferenceForm');
        try {
            const payload = await ui.fetchJson(endpoints.versionReferences, {
                action:'workspace', curriculum_version_id:versionId
            });
            if (Number(state.selectedVersionId) !== Number(versionId)) return;
            const record = payload.workspace;
            const comparisonLink = document.getElementById('compareBaselineLink');
            if (comparisonLink) {
                comparisonLink.hidden = record.version_role !== 'proposed' || !record.baseline_version_id;
                comparisonLink.href = 'curriculum_history_view.php?curriculum_id=' + Number(record.curriculum_id) + '&version_b_id=' + Number(record.id);
            }
            document.getElementById('referenceProgram').textContent = record.program_code + ' — ' + record.program_name;
            document.getElementById('referenceCurriculumVersion').textContent = record.curriculum_code + ' — ' + record.curriculum_name + ' / ' + record.version_label;
            document.getElementById('referenceVersionRole').textContent = record.version_role;
            document.getElementById('referenceReviewStatus').textContent = record.review_status + ' (workflow state; not proof of adoption)';
            document.getElementById('referenceBaseline').textContent = record.baseline_version_id
                ? record.baseline_version_label + ' (' + record.baseline_version_role + ')' : 'None';
            const summary = record.snapshot_summary;
            document.getElementById('referenceSnapshotStatus').textContent = summary.placements > 0 && summary.complete_snapshots === summary.placements
                ? summary.complete_snapshots + '/' + summary.placements + ' complete; captured ' + (summary.last_capture || 'date unavailable')
                : summary.complete_snapshots + '/' + summary.placements + ' complete; Historical subject details unavailable';
            const primary = record.references.find(item => item.reference_purpose === 'school_curriculum');
            const originResult = currentAssessmentLabel(primary?.current_assessments?.declared_origin);
            document.getElementById('referenceBasis').textContent = primary
                ? sourceKindLabel(primary.source_kind_snapshot) + (primary.source_kind_snapshot === 'institutional_curriculum' ? ' · declared-origin assessment: ' + originResult : '')
                : 'Unknown / no curriculum source attached';
            document.getElementById('referenceVersionContext').textContent = 'Technical status: ' + record.status + ' · Review status is a workflow state only.' +
                (record.references_inherited_from_version_id ? ' Documentary provenance inherited through baseline #' + record.references_inherited_from_version_id + '; no new verification implied.' : '');
            document.getElementById('referenceProtectionBadge').innerHTML = record.editable
                ? ui.statusBadge('editable') : ui.statusBadge('protected');
            const body = document.getElementById('versionReferenceRows');
            body.innerHTML = record.references.length ? record.references.map(function (item) {
                const sourceName = item.reference_code_snapshot || item.reference_title_snapshot || ('Source #' + item.compliance_reference_id);
                const title = item.reference_title_snapshot || 'Title unavailable at attachment';
                const locator = item.reference_url_snapshot
                    ? '<a href="' + ui.escapeHtml(item.reference_url_snapshot) + '" target="_blank" rel="noopener noreferrer">Open attached locator</a>'
                    : 'No locator captured';
                const currentLocator = item.current_source_url
                    ? '<a href="' + ui.escapeHtml(item.current_source_url) + '" target="_blank" rel="noopener noreferrer">Open current source locator</a>'
                    : 'Current source has no locator';
                const assess = ['document_identity','declared_origin','applicability'].map(key =>
                    key.replaceAll('_',' ') + ': ' + currentAssessmentLabel(item.current_assessments?.[key])).join(' · ');
                const attachmentWarning = item.attachment_matches_current_source
                    ? '' : ' · CURRENT SOURCE CHANGED SINCE ATTACHMENT; ATTACHMENT SNAPSHOT RETAINED';
                const currentWarning = item.verification_status === 'inactive' ? ' · CURRENT SOURCE INACTIVE/WITHDRAWN' : '';
                const currentSource = [item.current_reference_code || item.current_title || 'Current source', item.current_issuing_body || 'Issuer unavailable'].join(' · ');
                return '<tr><td><strong>' + ui.escapeHtml(sourceName) + '</strong><span class="compliance-subtext">Attachment-time snapshot: ' + ui.escapeHtml(title) + ' · ' + ui.escapeHtml(item.issuing_body_snapshot || 'Issuer unavailable at attachment') + '<br>' + locator + '<br>Current source: ' + ui.escapeHtml(currentSource) + ' · ' + currentLocator + '</span></td>' +
                    '<td>' + ui.escapeHtml(item.reference_purpose) + '</td><td>' + ui.escapeHtml(sourceKindLabel(item.source_kind_snapshot)) + '<span class="compliance-subtext">Current source assessments: ' + ui.escapeHtml(assess) + attachmentWarning + currentWarning + '</span></td>' +
                    '<td>' + ui.escapeHtml(item.linked_by_username || 'Unknown user') + '<span class="compliance-subtext">' + ui.escapeHtml(item.linked_at) + '</span></td></tr>';
            }).join('') : '<tr><td colspan="4" class="empty-cell">No documentary references are attached.</td></tr>';
            document.querySelectorAll('#attachVersionReferenceForm [name="curriculum_version_id"],#classifyReferenceForm [name="curriculum_version_id"]').forEach(el => { el.value = record.id; });
            const eligible = record.version_role === 'unclassified' && record.review_status === 'not_started' && !record.baseline_version_id;
            const attachDetails = formAttach?.closest('details');
            const classifyDetails = document.getElementById('classifyReferenceDetails');
            if (attachDetails) attachDetails.hidden = !eligible;
            if (classifyDetails) classifyDetails.hidden = !eligible;
            const choices = record.references.filter(item => item.reference_purpose === 'school_curriculum');
            if (formClassify) {
                const select = formClassify.querySelector('[name="compliance_reference_id"]');
                select.innerHTML = choices.length ? choices.map(item => '<option value="' + Number(item.compliance_reference_id) + '">' + ui.escapeHtml((item.reference_code_snapshot || item.reference_title_snapshot || 'Source') + ' · ' + sourceKindLabel(item.source_kind_snapshot)) + '</option>').join('') : '<option value="">Attach a curriculum source first</option>';
                select.disabled = !choices.length;
                if (choices.length) select.value = choices[0].compliance_reference_id;
            }
            if (formAttach) await populateReferenceChoices(formAttach.querySelector('[name="compliance_reference_id"]'));
            await loadCloneScope(record);
        } catch (error) {
            document.getElementById('referenceVersionContext').textContent = error.message || 'Reference details could not be loaded.';
            ui.showToast(error.message, 'error');
        }
    }

    async function loadCloneScope(record) {
        const details = document.getElementById('cloneReferenceDetails');
        const form = document.getElementById('cloneReferenceForm');
        if (!details || !form || !permissions.cloneReference || Number(state.selectedVersionId) !== Number(record.id)) return;
        const availability = document.getElementById('cloneReferenceAvailability');
        if (availability) {
            availability.hidden = record.version_role === 'reference';
            availability.textContent = 'This version is not a protected reference. Open an eligible protected reference to create a curriculum revision.';
        }
        details.hidden = record.version_role !== 'reference';
        form.querySelector('button[type="submit"]').disabled = true;
        if (details.hidden) return;
        form.reset(); ui.clearFormError(form);
        form.elements.source_version_id.value = record.id;
        form.elements.curriculum_id.value = record.curriculum_id;
        const scope = document.getElementById('cloneReferenceScope');
        scope.textContent = 'Checking protected reference and copy scope...';
        try {
            const result = await ui.fetchJson(endpoints.versions, {action:'clone_preview', source_version_id:record.id, curriculum_id:record.curriculum_id});
            if (Number(state.selectedVersionId) !== Number(record.id)) return;
            const sources = record.references.filter(item => item.reference_purpose === 'school_curriculum').map(item =>
                sourceKindLabel(item.source_kind_snapshot) + ': ' + (item.reference_title_snapshot || 'Title unavailable') +
                ' [' + (item.reference_code_snapshot || 'Code unavailable') + ']' +
                ' / Document location: ' + (item.reference_url_snapshot || item.evidence_events?.find(event => event.verification_subject === 'document_identity' && Number(event.matches_current_source) === 1)?.evidence_locator || 'Location unavailable') +
                ' / Recorded effective date: ' + (item.effective_date_snapshot || 'Not recorded'));
            scope.textContent = record.program_code + ' — ' + record.program_name + ' / ' + record.version_label + '. ' + sources.join('; ') + '. Copy: ' +
                Object.entries(result.preview.counts).map(([key,value]) => value + ' ' + key).join(', ') + '. ' + result.preview.notice;
            form.querySelector('button[type="submit"]').disabled = false;
        } catch (error) {
            if (Number(state.selectedVersionId) === Number(record.id)) scope.textContent = 'Cannot create a revision: ' + error.message;
        }
    }

    document.getElementById('cloneReferenceForm')?.addEventListener('submit', async function (event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        ui.clearFormError(form);
        if (Number(form.elements.source_version_id.value) !== Number(state.selectedVersionId)) {
            ui.showFormError(form,'Reference selection changed. Reload the selected reference before creating a revision.');
            return;
        }
        const data = new FormData(form); data.set('action','clone_reference');
        ui.setFormSubmitting(form,true,'Creating revision...');
        try {
            const payload = await ui.postForm(endpoints.versions,data);
            ui.showToast(payload.message,'success');
            await loadVersions(1,payload.version.id,false);
            await openVersionSubjects(payload.version.id,payload.version.version_label,payload.version.status);
        } catch (error) { ui.showFormError(form,error.message || 'Revision creation failed.'); }
        finally { ui.setFormSubmitting(form,false); }
    });

    async function populateReferenceChoices(select) {
        if (!select) return;
        try {
            const payload = await ui.fetchJson(endpoints.compliance, {action:'reference_list',page:1,per_page:50,search:''});
            const available = payload.references.filter(item => item.verification_status !== 'inactive');
            select.innerHTML = available.length ? '<option value="">Select a source</option>' + available.map(item =>
                '<option value="' + Number(item.id) + '">' + ui.escapeHtml((item.reference_code || item.title || 'Reference') + ' — ' + sourceKindLabel(item.source_kind) + ' — ' + item.issuing_body) + '</option>').join('')
                : '<option value="">No available source records</option>';
        } catch (error) { select.innerHTML = '<option value="">Source records unavailable</option>'; }
    }

    document.getElementById('attachVersionReferenceForm')?.addEventListener('submit', async function (event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        const data = new FormData(form); data.set('action','attach');
        ui.setFormSubmitting(form,true,'Attaching...');
        try {
            const payload = await ui.postForm(endpoints.versionReferences,data);
            form.reset(); ui.showToast(payload.message,'success');
            await loadReferenceWorkspace(state.selectedVersionId);
        } catch(error) { ui.showFormError(form,error.message); }
        finally { ui.setFormSubmitting(form,false); }
    });
    document.getElementById('classifyReferenceForm')?.addEventListener('submit', async function (event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        const data = new FormData(form); data.set('action','classify_reference');
        ui.setFormSubmitting(form,true,'Capturing and protecting...');
        try {
            const payload = await ui.postForm(endpoints.versionReferences,data);
            ui.showToast(payload.message,'success');
            await loadReferenceWorkspace(state.selectedVersionId);
            await loadVersions(state.versionPage,state.selectedVersionId,false);
        } catch(error) { ui.showFormError(form,error.message); }
        finally { ui.setFormSubmitting(form,false); }
    });

    async function loadCurriculumProgramFilter() {
        const filter = document.getElementById('curriculumProgramFilter');
        if (!filter) return;
        const initialProgramId = Number(workspace.dataset.initialProgramId) || 0;
        try {
            const payload = await ui.fetchJson(endpoints.programs, { action: 'list', page: 1, per_page: 50, search: '', status: '' });
            const programs = payload.programs || [];
            if (initialProgramId && !programs.some(program => Number(program.id) === initialProgramId)) {
                try {
                    const selected = await ui.fetchJson(endpoints.programs, { action: 'get', id: initialProgramId });
                    programs.push(selected.program);
                } catch (error) { }
            }
            filter.innerHTML = '<option value="">All programs</option>' + programs.map(program =>
                '<option value="' + Number(program.id) + '">' + ui.escapeHtml(program.program_code) + '</option>').join('');
            if (initialProgramId) filter.value = String(initialProgramId);
        } catch (error) {
            filter.innerHTML = '<option value="">All programs</option>' + (initialProgramId
                ? '<option value="' + initialProgramId + '">Program ' + initialProgramId + '</option>' : '');
            if (initialProgramId) filter.value = String(initialProgramId);
        }
    }

    function renderWorkspaceYear() {
        const isPreoral2026 = state.selectedCurriculum?.curriculum_code === 'BSIT-DEMO-2026' &&
            state.selectedVersionLabel === 'PREORAL-2026';
        const displayPlacements = isPreoral2026
            ? state.placements.map(row => /^3rd\s*yr$/i.test(String(row.year_level || '').trim())
                ? { ...row, year_level: '3rd Year' }
                : row)
            : state.placements;
        const grouped = window.CurriculumPlacementView.group(displayPlacements);
        const tabs = document.getElementById('yearTabs');
        const years = Array.from(grouped.years.keys());
        if (!years.includes(state.selectedYear)) state.selectedYear = years[0] || '';
        tabs.innerHTML = years.map((year, index) => '<button type="button" role="tab" id="year-tab-' + index +
            '" aria-selected="' + String(year === state.selectedYear) + '" aria-controls="year-panel" class="year-tab' +
            (year === state.selectedYear ? ' is-active' : '') + '" data-year-value="' + ui.escapeHtml(year) + '">' +
            ui.escapeHtml(year || 'Year not specified') + '</button>').join('');
        const yearGroups = grouped.years.get(state.selectedYear);
        if (!yearGroups) {
            placementRows.innerHTML = '<p class="state-cell">No placements are stored for this year.</p>';
            return;
        }
        placementRows.innerHTML = '<div id="year-panel" role="tabpanel" aria-labelledby="year-tab-' + years.indexOf(state.selectedYear) + '">' +
            Array.from(yearGroups.entries()).map(([semesterName, semester]) => '<section class="semester-panel"><header class="semester-panel-header"><strong>' +
                ui.escapeHtml(semesterName || 'Semester not specified') + '</strong><span>' + semester.rows.length + ' Subjects · ' +
                ui.escapeHtml(ui.formatUnits(semester.cents / 100)) + ' Units</span></header><div class="table-scroll"><table class="domain-table placement-table"><thead><tr><th>Code</th><th>Subject</th><th>Units</th><th>Placement</th>' +
                '</tr></thead><tbody>' + semester.rows.map(row => '<tr><td><span class="record-code">' +
                    ui.escapeHtml(row.subject_code) + '</span></td><td><span class="record-name">' + ui.escapeHtml(row.subject_title) + '</span></td><td>' +
                    ui.escapeHtml(ui.formatUnits(row.units)) + '</td><td><span class="placement-indicator ' + (Number(row.is_required) === 1 ? 'placement-required' : 'placement-optional') + '">' +
                    (Number(row.is_required) === 1 ? 'Required' : 'Optional') + '</span></td>' +
                    '</tr>').join('') + '</tbody></table></div></section>').join('') + '</div>';
    }

    async function loadPlacements() {
        if (!state.selectedVersionId) return;
        const versionId = state.selectedVersionId;
        const sequence = ++state.placementRequest;
        state.placementPage = 1;
        tableState(placementRows, columns.placements, 'Loading assigned subjects…', false);
        try {
            const assignments = await window.CurriculumPlacementView.load(
                page => ui.fetchJson(endpoints.assignments, {
                    action: 'list', curriculum_version_id: versionId, page, per_page: 50
                }),
                () => sequence === state.placementRequest && versionId === state.selectedVersionId
            );
            if (assignments === null) return;
            state.placements = assignments;
            if (!assignments.length) {
                const message = permissions.assignSubjects
                    ? 'No subjects are assigned to this curriculum version. Use Manage Subject Mapping to manage placements.'
                    : 'No subjects are assigned to this curriculum version.';
                tableState(placementRows, columns.placements, message, false);
            } else {
                renderWorkspaceYear();
            }
        } catch (error) {
            if (sequence !== state.placementRequest || versionId !== state.selectedVersionId) return;
            state.placements = [];
            tableState(placementRows, columns.placements, error.message || 'Subject placements could not be loaded.', true);
        }
    }

    function resetProgramForm() {
        if (!programForm) return;
        programForm.reset();
        document.getElementById('programId').value = '';
        document.getElementById('programFormStatus').value = 'active';
        syncProgramDegreeLevel();
        ui.clearFormError(programForm);
    }

    function openCreateProgram() {
        if (!permissions.createPrograms || !programForm || !programModal) return;
        programFormMode = 'create';
        resetProgramForm();
        if (programCategory === 'shs') {
            document.getElementById('programTypeLevel').value = 'Senior High School';
            syncProgramDegreeLevel();
        }
        document.getElementById('programModalTitle').textContent = 'New Program';
        document.getElementById('programSubmitButton').textContent = 'Save Program';
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
            setProgramDegreeLevel(program);
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
        curriculumProgramAutocomplete?.clear();
        document.getElementById('curriculumProgramPicker').hidden = false;
        document.getElementById('curriculumProgramFixedContext').hidden = true;
        const message = document.getElementById('programRequirementMessage');
        if (message) message.textContent = 'Select an existing academic program from the suggestions.';
        ui.clearFormError(curriculumForm);
    }

    async function openCreateCurriculum() {
        if (!permissions.createCurricula || !curriculumForm || !curriculumModal) return;
        if (!state.hasPrograms && !state.pageProgram) {
            ui.showToast('Create an academic program before creating a curriculum.', 'warning');
            return;
        }
        curriculumFormMode = 'create';
        resetCurriculumForm();
        const picker = document.getElementById('curriculumProgramPicker');
        const fixedContext = document.getElementById('curriculumProgramFixedContext');
        if (state.pageProgram) {
            selectProgram(state.pageProgram);
            picker.hidden = true;
            fixedContext.hidden = false;
            document.getElementById('curriculumProgramFixedLabel').textContent =
                state.pageProgram.program_code + ' — ' + state.pageProgram.program_name;
        } else {
            picker.hidden = false;
            fixedContext.hidden = true;
        }
        document.getElementById('curriculumModalTitle').textContent = 'New Curriculum';
        document.getElementById('curriculumSubmitButton').textContent = 'Save Curriculum';
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

    async function openAssignment() {
        if (!permissions.assignSubjects || !state.selectedVersionId || !assignmentForm || !assignmentModal) return;
        assignmentForm.reset();
        ui.clearFormError(assignmentForm);
        document.getElementById('assignmentVersionId').value = state.selectedVersionId;
        const levelLabel = document.getElementById('assignmentLevelLabel');
        const levelSelect = document.getElementById('assignmentLevel');
        const isShs = /senior high|\bshs\b|strand/i.test(String(state.selectedCurriculum?.program_type || ''));
        if (levelLabel && levelSelect) {
            levelLabel.innerHTML = (isShs ? 'Grade Level' : 'Year Level') + ' <strong>*</strong>';
            levelSelect.innerHTML = (isShs
                ? ['Grade 11', 'Grade 12']
                : ['1st Year', '2nd Year', '3rd Year', '4th Year'])
                .map(value => '<option value="' + value + '">' + value + '</option>').join('');
        }
        assignmentSubjectAutocomplete?.clear();
        document.getElementById('assignmentVersionContext').textContent =
            (state.selectedCurriculum?.curriculum_code || '') + ' — ' + state.selectedVersionLabel;
        assignmentModal.open();
        assignmentSubjectInput?.focus();
    }

    if (programForm) {
        programForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!document.getElementById('programTypeLevel').value) {
                ui.showFormError(programForm, 'Please select a degree level.');
                document.getElementById('programTypeLevel').focus();
                return;
            }
            syncProgramDegreeLevel();
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
                if (programRows) await loadPrograms(state.programPage);
                else if (workspacePage === 'curricula') await loadCurriculaPrograms(state.programPage);
                else await loadProgramCategoryCounts();
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
            if (!curriculumProgramAutocomplete?.hasSelection()) {
                const message = 'Please select a valid academic program.';
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
                curriculumModal.close();
                resetCurriculumForm();
                ui.showToast(payload.message, curriculumFormMode === 'create' ? 'success' : 'updated');
                if (workspacePage === 'curricula' && curriculaView === 'program-curricula') await loadProgramCurricula();
                else if (workspacePage === 'curricula' && curriculaView === 'curriculum-workspace') await loadCurriculumWorkspace();
                else if (workspacePage === 'programs') await loadCurricula(state.curriculumPage);
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

    if (versionRenameForm) {
        versionRenameForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!versionRenameForm.reportValidity()) return;
            ui.clearFormError(versionRenameForm);
            const formData = new FormData(versionRenameForm);
            formData.set('action', 'rename');
            ui.setFormSubmitting(versionRenameForm, true, 'Saving…');
            try {
                const payload = await ui.postForm(endpoints.versions, formData);
                versionRenameModal.close();
                ui.showToast(payload.message, 'updated');
                await loadVersions(state.versionPage);
            } catch (error) {
                ui.showFormError(versionRenameForm, error.message || 'The curriculum version could not be renamed.');
            } finally {
                ui.setFormSubmitting(versionRenameForm, false);
            }
        });
    }

    if (assignmentForm) {
        assignmentForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!assignmentSubjectAutocomplete?.hasSelection()) {
                ui.showFormError(assignmentForm, 'Please select a valid subject.');
                assignmentSubjectInput?.focus();
                return;
            }
            if (!assignmentForm.reportValidity()) return;
            ui.clearFormError(assignmentForm);
            const formData = new FormData(assignmentForm);
            formData.set('action', 'assign');
            ui.setFormSubmitting(assignmentForm, true, 'Assigning…');
            try {
                const payload = await ui.postForm(endpoints.assignments, formData);
                assignmentModal.close();
                assignmentForm.reset();
                assignmentSubjectAutocomplete?.clear();
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
    document.getElementById('programDetailsEdit')?.addEventListener('click', function () {
        if (!permissions.updatePrograms || !state.viewingProgramId) return;
        programDetailsModal?.close();
        openEditProgram(state.viewingProgramId);
    });
    document.getElementById('programDetailsArchive')?.addEventListener('click', openProgramArchiveConfirmation);
    document.getElementById('confirmProgramArchive')?.addEventListener('click', archiveViewingProgram);
    document.getElementById('programTypeLevel')?.addEventListener('change', syncProgramDegreeLevel);
    document.getElementById('programTypeOther')?.addEventListener('input', syncProgramDegreeLevel);
    document.getElementById('newCurriculumButton')?.addEventListener('click', openCreateCurriculum);
    document.getElementById('newVersionButton')?.addEventListener('click', openCreateVersion);
    versionRows?.addEventListener('click', async function (event) {
        const button = event.target.closest?.('[data-rename-version-id]');
        if (!button || !permissions.updateCurricula) return;
        versionRenameForm.elements.id.value = button.dataset.renameVersionId;
        versionRenameForm.elements.version_label.value = button.dataset.versionLabel || '';
        ui.clearFormError(versionRenameForm);
        versionRenameModal.open();
    });
    document.getElementById('assignSubjectButton')?.addEventListener('click', function () {
        if (!state.selectedVersionId || !state.selectedCurriculum) return;
        window.location.href = 'subject_mapping.php?' + new URLSearchParams({
            program_id: String(state.selectedCurriculum.academic_program_id),
            curriculum_id: String(state.selectedCurriculum.id),
            version_id: String(state.selectedVersionId)
        });
    });
    document.getElementById('detailEditCurriculumButton')?.addEventListener('click', function () {
        if (!permissions.updateCurricula || !state.selectedCurriculumId) return;
        curriculumDetailModal?.close();
        openEditCurriculum(state.selectedCurriculumId);
    });

    curriculumProgramInput?.addEventListener('input', function () { ui.clearFormError(curriculumForm); });
    assignmentSubjectInput?.addEventListener('input', function () { ui.clearFormError(assignmentForm); });

    if (workspacePage === 'programs') {
        if (!programRows) {
            loadProgramCategoryCounts();
            return;
        }
        document.getElementById('programSearchForm').addEventListener('submit', function (event) {
            event.preventDefault(); loadPrograms(1);
        });
        document.getElementById('programSearch').addEventListener('input', ui.debounce(function () { loadPrograms(1); }, 300));
        document.getElementById('programStatus').addEventListener('change', function () { loadPrograms(1); });
        document.getElementById('programDegreeLevelFilter')?.addEventListener('change', function () { loadPrograms(1); });
        programRows.addEventListener('click', function (event) {
            const action = event.target.closest('button, a, input, select');
            if (action) {
                event.stopPropagation();
                const editButton = action.closest('[data-edit-program]');
                if (editButton) openEditProgram(editButton.dataset.editProgram);
                const archiveButton = action.closest('[data-archive-program]');
                if (archiveButton) openProgramArchiveForId(archiveButton.dataset.archiveProgram);
                return;
            }
            const row = event.target.closest('tr[data-record-view]');
            if (row) openProgramDetails(row.dataset.recordView);
        });
        programRows.addEventListener('keydown', function (event) {
            const row = event.target.closest('tr[data-record-view]');
            if (row && event.target === row && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                openProgramDetails(row.dataset.recordView);
            }
        });
        loadPrograms(1);
        return;
    }

    if (workspacePage !== 'curricula') return;

    if (curriculaView === 'program-list') {
        const form = document.getElementById('curriculumProgramSearchForm');
        form.addEventListener('submit', function (event) { event.preventDefault(); loadCurriculaPrograms(1); });
        document.getElementById('curriculumProgramSearch').addEventListener('input', ui.debounce(function () { loadCurriculaPrograms(1); }, 300));
        document.getElementById('curriculumAcademicLevel').addEventListener('change', function () { loadCurriculaPrograms(1); });
        document.getElementById('curriculumProgramStatus').addEventListener('change', function () { loadCurriculaPrograms(1); });
        curriculumProgramRows.addEventListener('click', function (event) {
            const editButton = event.target.closest('[data-edit-group-program]');
            const archiveButton = event.target.closest('[data-archive-group-program]');
            if (editButton) {
                event.stopPropagation();
                openEditProgram(editButton.dataset.editGroupProgram);
                return;
            }
            if (archiveButton) {
                event.stopPropagation();
                openProgramArchiveForId(archiveButton.dataset.archiveGroupProgram);
                return;
            }
            const row = event.target.closest('tr[data-program-view]');
            if (row) window.location.href = 'curricula.php?program_id=' + Number(row.dataset.programView);
        });
        curriculumProgramRows.addEventListener('keydown', function (event) {
            const row = event.target.closest('tr[data-program-view]');
            if (row && event.target === row && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                window.location.href = 'curricula.php?program_id=' + Number(row.dataset.programView);
            }
        });
        loadCurriculaPrograms(1);
        return;
    }

    if (curriculaView === 'program-curricula') {
        loadProgramCurricula();
        return;
    }

    if (curriculaView !== 'curriculum-workspace') return;
    document.getElementById('yearTabs')?.addEventListener('click', function (event) {
        const tab = event.target.closest('[data-year-value]');
        if (!tab || !document.getElementById('yearTabs').contains(tab)) return;
        state.selectedYear = tab.dataset.yearValue;
        renderWorkspaceYear();
    });
    document.getElementById('editCurriculumButton')?.addEventListener('click', function () {
        if (permissions.updateCurricula && state.selectedCurriculumId) openEditCurriculum(state.selectedCurriculumId);
    });
    document.getElementById('archiveCurriculumButton')?.addEventListener('click', function () {
        if (!permissions.updateCurricula || state.selectedCurriculum?.status !== 'active') return;
        document.getElementById('curriculumArchiveName').textContent = state.selectedCurriculum.curriculum_code;
        curriculumArchiveModal?.open();
    });
    document.getElementById('confirmCurriculumArchive')?.addEventListener('click', async function () {
        if (!state.selectedCurriculumId || !curriculumArchiveModal) return;
        this.disabled = true;
        const data = new FormData(); data.set('action', 'archive'); data.set('id', String(state.selectedCurriculumId));
        try {
            await ui.postForm(endpoints.curricula, data);
            curriculumArchiveModal.close();
            ui.showToast('Curriculum archived.', 'success');
            window.location.href = 'curricula.php?program_id=' + Number(state.selectedCurriculum.academic_program_id);
        } catch (error) { ui.showFormError(document.getElementById('curriculumArchiveModal'), error.message); }
        finally { this.disabled = false; }
    });
    loadCurriculumWorkspace();
    refreshProgramAvailability();
}());
