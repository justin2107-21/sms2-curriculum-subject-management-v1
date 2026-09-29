(function () {
    'use strict';

    const workspace = document.getElementById('programAssignmentWorkspace');
    if (!workspace || !window.CurriculumUI) return;

    const ui = window.CurriculumUI;
    const endpoint = workspace.dataset.assignmentApi;
    const canManage = workspace.dataset.canManageAssignments === '1';
    const activeView = document.getElementById('programCentricPanel') ? 'program' : 'subject';
    const columnCounts = { program: canManage ? 12 : 11, subject: canManage ? 10 : 9 };
    const state = {
        activeView: activeView,
        optionRequest: 0,
        program: { page: 1, request: 0, records: new Map(), loaded: false },
        subject: { page: 1, request: 0, records: new Map(), loaded: false },
        pendingRemoval: null
    };

    const controls = {
        program: {
            program: document.getElementById('programAssignmentProgram'),
            curriculum: document.getElementById('programAssignmentCurriculum'),
            version: document.getElementById('programAssignmentVersion'),
            year: document.getElementById('programAssignmentYear'),
            semester: document.getElementById('programAssignmentSemester'),
            search: document.getElementById('programAssignmentSearch'),
            perPage: document.getElementById('programAssignmentPerPage'),
            rows: document.getElementById('programAssignmentRows'),
            pagination: document.getElementById('programAssignmentPagination'),
            count: document.getElementById('programAssignmentCount'),
            context: document.getElementById('programAssignmentContext')
        },
        subject: {
            program: document.getElementById('subjectUsageProgram'),
            curriculum: document.getElementById('subjectUsageCurriculum'),
            version: document.getElementById('subjectUsageVersion'),
            search: document.getElementById('subjectUsageSearch'),
            perPage: document.getElementById('subjectUsagePerPage'),
            rows: document.getElementById('subjectUsageRows'),
            pagination: document.getElementById('subjectUsagePagination'),
            count: document.getElementById('subjectUsageCount'),
            context: document.getElementById('subjectUsageContext')
        }
    };

    const addForm = document.getElementById('addProgramAssignmentForm');
    const editForm = document.getElementById('editProgramAssignmentForm');
    const removeElement = document.getElementById('removeProgramAssignmentModal');
    const addModal = ui.createModal(document.getElementById('addProgramAssignmentModal'));
    const editModal = ui.createModal(document.getElementById('editProgramAssignmentModal'));
    const removeModal = ui.createModal(removeElement);

    function tableState(view, message, isError) {
        const control = controls[view];
        control.rows.innerHTML = '<tr class="state-row"><td colspan="' + columnCounts[view] +
            '" class="state-cell' + (isError ? ' error-state' : '') + '">' +
            ui.escapeHtml(message) + '</td></tr>';
    }

    function selectedText(select) {
        const option = select?.selectedOptions?.[0];
        return option && option.value ? option.textContent.trim() : '';
    }

    function optionMarkup(value, label, selectedValue) {
        return '<option value="' + Number(value) + '"' +
            (Number(value) === Number(selectedValue) ? ' selected' : '') + '>' +
            ui.escapeHtml(label) + '</option>';
    }

    function resetCurriculumControls(view) {
        const control = controls[view];
        control.curriculum.disabled = true;
        control.curriculum.innerHTML = '<option value="">Select an academic program first</option>';
        control.version.disabled = true;
        control.version.innerHTML = '<option value="">Select a curriculum first</option>';
    }

    function updateProgramControlState() {
        const control = controls.program;
        if (!control.program) return;
        const hasProgram = Number(control.program.value) > 0;
        control.search.disabled = !hasProgram;
        control.search.placeholder = hasProgram
            ? 'Subject code or title'
            : 'Select an academic program first';
        control.year.disabled = !hasProgram;
        control.semester.disabled = !hasProgram;
        const addButton = document.getElementById('addProgramAssignmentButton');
        if (addButton) addButton.disabled = !(hasProgram && Number(control.version.value) > 0);
    }

    async function loadPrograms() {
        const programControls = [controls.program.program, controls.subject.program].filter(Boolean);
        programControls.forEach(function (select) {
            select.disabled = true;
            select.innerHTML = '<option value="">Loading academic programs…</option>';
        });
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'program_options', page: 1, per_page: 50, search: '', status: ''
            });
            const options = payload.programs.map(function (program) {
                const type = program.program_type ? ' • ' + program.program_type : '';
                return optionMarkup(program.id, program.program_code + ' — ' + program.program_name + type);
            }).join('');
            programControls.forEach(function (select) {
                select.innerHTML = '<option value="">All available academic programs</option>' + options;
                select.disabled = payload.programs.length === 0;
                if (!payload.programs.length) {
                    select.innerHTML = '<option value="">No academic programs found</option>';
                }
            });
        } catch (error) {
            programControls.forEach(function (select) {
                select.innerHTML = '<option value="">Academic programs unavailable</option>';
            });
            ui.showToast(error.message || 'Academic programs could not be loaded.', 'error');
        }
        updateProgramControlState();
    }

    async function loadCurricula(view, selectedId) {
        const control = controls[view];
        const programId = Number(control.program.value);
        control.curriculum.disabled = true;
        control.version.disabled = true;
        control.version.innerHTML = '<option value="">Select a curriculum first</option>';
        if (!programId) {
            control.curriculum.innerHTML = '<option value="">Select an academic program first</option>';
            if (view === 'program') updateProgramControlState();
            return;
        }

        control.curriculum.innerHTML = '<option value="">Loading curricula…</option>';
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'curriculum_options',
                academic_program_id: programId,
                page: 1, per_page: 50, search: '', status: ''
            });
            control.curriculum.innerHTML = '<option value="">All curricula for this program</option>' +
                payload.curricula.map(function (curriculum) {
                    return optionMarkup(
                        curriculum.id,
                        curriculum.curriculum_code + ' — ' + curriculum.curriculum_name,
                        selectedId
                    );
                }).join('');
            control.curriculum.disabled = payload.curricula.length === 0;
            if (!payload.curricula.length) {
                control.curriculum.innerHTML = '<option value="">No curricula are available for this program</option>';
            }
        } catch (error) {
            control.curriculum.innerHTML = '<option value="">Curricula unavailable</option>';
            ui.showToast(error.message || 'Curricula could not be loaded.', 'error');
        }
        if (view === 'program') updateProgramControlState();
    }

    async function loadVersions(view, selectedId) {
        const control = controls[view];
        const curriculumId = Number(control.curriculum.value);
        control.version.disabled = true;
        if (!curriculumId) {
            control.version.innerHTML = '<option value="">Select a curriculum first</option>';
            if (view === 'program') updateProgramControlState();
            return;
        }

        control.version.innerHTML = '<option value="">Loading curriculum versions…</option>';
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'version_options', curriculum_id: curriculumId,
                page: 1, per_page: 50, search: '', status: ''
            });
            control.version.innerHTML = '<option value="">All curriculum versions</option>' +
                payload.versions.map(function (version) {
                    const year = version.effective_academic_year ? ' — ' + version.effective_academic_year : '';
                    return optionMarkup(version.id, version.version_label + year, selectedId);
                }).join('');
            control.version.disabled = payload.versions.length === 0;
            if (!payload.versions.length) {
                control.version.innerHTML = '<option value="">No curriculum versions are available</option>';
            }
        } catch (error) {
            control.version.innerHTML = '<option value="">Curriculum versions unavailable</option>';
            ui.showToast(error.message || 'Curriculum versions could not be loaded.', 'error');
        }
        if (view === 'program') updateProgramControlState();
    }

    function updateStoredValues(select, values) {
        const selected = select.value;
        select.innerHTML = '<option value="">All stored values</option>' + values.map(function (value) {
            return '<option value="' + ui.escapeHtml(value) + '"' +
                (value === selected ? ' selected' : '') + '>' + ui.escapeHtml(value) + '</option>';
        }).join('');
        if (selected && !values.includes(selected)) select.value = '';
    }

    function contextParts(view) {
        const control = controls[view];
        return [selectedText(control.program), selectedText(control.curriculum), selectedText(control.version)]
            .filter(Boolean);
    }

    function usageBadge(record) {
        const count = Number(record.program_usage_count) || 0;
        return '<span class="assignment-usage-badge"><i class="fa-solid fa-building-columns"></i> ' +
            count + (count === 1 ? ' program' : ' programs') + '</span>';
    }

    function actionCell(record) {
        if (!canManage) return '';
        const hasRequirement = Number(record.has_requirement_dependencies) === 1;
        const hasElective = Number(record.has_elective_dependencies) === 1;
        const dependencyTitle = hasRequirement || hasElective
            ? 'Dependencies must be removed before this assignment can be removed.'
            : 'Remove this curriculum placement.';
        return '<td><div class="assignment-action-group">' +
            '<button type="button" class="row-action-button" data-edit-program-assignment="' + Number(record.id) +
            '"><i class="fa-solid fa-pen"></i> Edit</button>' +
            '<button type="button" class="remove-action-button' +
            (hasRequirement || hasElective ? ' has-dependency' : '') + '" data-remove-program-assignment="' + Number(record.id) +
            '" title="' + ui.escapeHtml(dependencyTitle) + '"><i class="fa-solid fa-link-slash"></i> Remove</button>' +
            '</div></td>';
    }

    function contextStack(primary, secondary) {
        return '<span class="assignment-program-context"><strong>' + ui.escapeHtml(primary) +
            '</strong><span>' + ui.escapeHtml(secondary) + '</span></span>';
    }

    function programRow(record) {
        const required = Number(record.is_required) === 1;
        return '<tr>' +
            '<td><span class="record-code">' + ui.escapeHtml(record.subject_code) + '</span></td>' +
            '<td><span class="record-name">' + ui.escapeHtml(record.subject_title) + '</span></td>' +
            '<td>' + contextStack(record.program_name, record.program_code) + '</td>' +
            '<td><span class="assignment-program-type">' + ui.escapeHtml(record.program_type || 'Not specified') + '</span></td>' +
            '<td>' + contextStack(record.curriculum_name, record.curriculum_code) + '</td>' +
            '<td><span class="record-code">' + ui.escapeHtml(record.version_label) + '</span></td>' +
            '<td>' + ui.escapeHtml(record.year_level || '—') + '</td>' +
            '<td>' + ui.escapeHtml(record.semester || '—') + '</td>' +
            '<td>' + ui.escapeHtml(record.sequence_order ?? '—') + '</td>' +
            '<td><span class="placement-indicator ' + (required ? 'placement-required' : 'placement-optional') + '">' +
                (required ? 'Required' : 'Optional') + '</span></td>' +
            '<td>' + usageBadge(record) + '</td>' + actionCell(record) + '</tr>';
    }

    function subjectRow(record) {
        return '<tr>' +
            '<td><span class="record-code">' + ui.escapeHtml(record.subject_code) + '</span></td>' +
            '<td><span class="record-name">' + ui.escapeHtml(record.subject_title) + '</span></td>' +
            '<td>' + contextStack(record.program_name, record.program_code) + '</td>' +
            '<td><span class="assignment-program-type">' + ui.escapeHtml(record.program_type || 'Not specified') + '</span></td>' +
            '<td>' + contextStack(record.curriculum_name, record.curriculum_code) + '</td>' +
            '<td><span class="record-code">' + ui.escapeHtml(record.version_label) + '</span></td>' +
            '<td>' + ui.escapeHtml(record.year_level || '—') + '</td>' +
            '<td>' + ui.escapeHtml(record.semester || '—') + '</td>' +
            '<td>' + usageBadge(record) + '</td>' + actionCell(record) + '</tr>';
    }

    function queryFor(view, page) {
        const control = controls[view];
        return {
            action: 'list', view: view, page: page, per_page: control.perPage.value,
            search: control.search.value.trim(),
            academic_program_id: control.program.value,
            curriculum_id: control.curriculum.value,
            curriculum_version_id: control.version.value,
            year_level: view === 'program' ? control.year.value : '',
            semester: view === 'program' ? control.semester.value : ''
        };
    }

    async function loadAssignments(view, page) {
        const control = controls[view];
        if (view === 'program' && !control.program.value) {
            state.program.records.clear();
            state.program.loaded = true;
            ui.setCount(control.count, 0);
            control.pagination.innerHTML = '';
            tableState('program', 'Select an academic program to view its assigned subjects.', false);
            control.context.textContent = 'Select an academic program to view its assigned subjects.';
            updateProgramControlState();
            return;
        }

        const viewState = state[view];
        const sequence = ++viewState.request;
        viewState.page = Number(page) || 1;
        tableState(view, view === 'program' ? 'Loading program subject assignments…' : 'Loading subject program usage…', false);
        try {
            const payload = await ui.fetchJson(endpoint, queryFor(view, viewState.page));
            if (sequence !== viewState.request) return;
            viewState.page = Number(payload.pagination.page);
            viewState.records.clear();
            payload.assignments.forEach(function (record) {
                viewState.records.set(Number(record.id), record);
            });
            viewState.loaded = true;
            ui.setCount(control.count, Number(payload.pagination.total_records));
            if (view === 'program') {
                updateStoredValues(control.year, payload.filter_values.year_levels);
                updateStoredValues(control.semester, payload.filter_values.semesters);
            }

            if (!payload.assignments.length) {
                let message = view === 'program'
                    ? 'No subjects are assigned to the selected academic-program context.'
                    : 'No program assignments found for this subject or filter selection.';
                if (control.search.value.trim()) {
                    message = 'No subject assignments match the selected filters.';
                }
                tableState(view, message, false);
            } else {
                control.rows.innerHTML = payload.assignments.map(view === 'program' ? programRow : subjectRow).join('');
            }
            ui.renderPagination(control.pagination, payload.pagination, function (requestedPage) {
                loadAssignments(view, requestedPage);
            });
            const parts = contextParts(view);
            control.context.textContent = parts.length
                ? 'Current context: ' + parts.join(' → ')
                : 'Showing bounded subject assignments across all academic programs.';
            if (view === 'program') updateProgramControlState();
        } catch (error) {
            if (sequence !== viewState.request) return;
            viewState.records.clear();
            ui.setCount(control.count, 0);
            control.pagination.innerHTML = '';
            tableState(view, error.message || 'Subject assignments could not be loaded.', true);
        }
    }

    function findRecord(id) {
        return state[state.activeView].records.get(Number(id)) ||
            state.program.records.get(Number(id)) || state.subject.records.get(Number(id));
    }

    async function loadSubjectOptions(searchValue) {
        const select = document.getElementById('programAssignmentSubjectId');
        const submit = addForm?.querySelector('[type="submit"]');
        if (!select || !submit) return;
        const sequence = ++state.optionRequest;
        select.disabled = true;
        submit.disabled = true;
        select.innerHTML = '<option value="">Loading available subjects…</option>';
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'subject_options',
                curriculum_version_id: controls.program.version.value,
                page: 1, per_page: 50, search: String(searchValue || '').trim()
            });
            if (sequence !== state.optionRequest) return;
            select.innerHTML = '<option value="">Select an available Subject Master record</option>' +
                payload.subjects.map(function (subject) {
                    return optionMarkup(subject.id, subject.subject_code + ' — ' + subject.subject_title);
                }).join('');
            select.disabled = payload.subjects.length === 0;
            submit.disabled = payload.subjects.length === 0;
            if (!payload.subjects.length) {
                ui.showFormError(
                    addForm,
                    searchValue
                        ? 'No available Subject Master records match this search.'
                        : 'All available Subject Master records are already assigned, or no subjects exist.'
                );
            }
        } catch (error) {
            if (sequence !== state.optionRequest) return;
            select.innerHTML = '<option value="">Subjects unavailable</option>';
            ui.showFormError(addForm, error.message || 'Subject Master records could not be loaded.');
        }
    }

    async function openAddAssignment() {
        if (!canManage || !controls.program.version.value || !addForm || !addModal) return;
        addForm.reset();
        ui.clearFormError(addForm);
        document.getElementById('addProgramAssignmentVersionId').value = controls.program.version.value;
        document.getElementById('addProgramAssignmentContext').textContent = contextParts('program').join(' → ');
        addModal.open();
        await loadSubjectOptions('');
        document.getElementById('programAssignmentSubjectSearch').focus();
    }

    function openEditAssignment(id) {
        const record = findRecord(id);
        if (!canManage || !record || !editForm || !editModal) return;
        editForm.reset();
        ui.clearFormError(editForm);
        document.getElementById('editProgramAssignmentId').value = record.id;
        document.getElementById('editProgramAssignmentContext').textContent =
            record.program_code + ' → ' + record.curriculum_code + ' → ' + record.version_label;
        document.getElementById('editProgramAssignmentSubject').textContent =
            record.subject_code + ' — ' + record.subject_title;
        document.getElementById('editProgramAssignmentVersion').textContent =
            record.curriculum_code + ' — ' + record.version_label;
        document.getElementById('editProgramAssignmentYear').value = record.year_level || '';
        document.getElementById('editProgramAssignmentSemester').value = record.semester || '';
        document.getElementById('editProgramAssignmentSequence').value = record.sequence_order ?? '';
        document.getElementById('editProgramAssignmentRequired').value = Number(record.is_required) === 1 ? '1' : '0';
        editModal.open();
        document.getElementById('editProgramAssignmentYear').focus();
    }

    function openRemoveAssignment(id) {
        const record = findRecord(id);
        if (!canManage || !record || !removeModal || !removeElement) return;
        state.pendingRemoval = { id: record.id };
        ui.clearFormError(removeElement);
        document.getElementById('removeProgramAssignmentMessage').textContent =
            'Remove ' + record.subject_code + ' — ' + record.subject_title + ' from ' +
            record.program_code + ' / ' + record.curriculum_code + ' / ' + record.version_label +
            '? Only this curriculum placement will be deleted.';
        const dependencyWarning = document.getElementById('programAssignmentDependencyWarning');
        const messages = [];
        if (Number(record.has_requirement_dependencies) === 1) {
            messages.push('This assignment is used by a prerequisite or corequisite relationship.');
        }
        if (Number(record.has_elective_dependencies) === 1) {
            messages.push('This assignment belongs to an elective group.');
        }
        dependencyWarning.hidden = messages.length === 0;
        dependencyWarning.textContent = messages.length
            ? messages.join(' ') + ' Removal will remain blocked until those relationships are removed.'
            : '';
        removeModal.open();
        document.getElementById('confirmRemoveProgramAssignment').focus();
    }

    async function refreshLoadedViews() {
        if (state.program.loaded) await loadAssignments('program', state.program.page);
        if (state.subject.loaded) await loadAssignments('subject', state.subject.page);
    }

    if (addForm) {
        addForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!addForm.reportValidity()) return;
            ui.clearFormError(addForm);
            const formData = new FormData(addForm);
            formData.set('action', 'assign');
            ui.setFormSubmitting(addForm, true, 'Adding…');
            try {
                const payload = await ui.postForm(endpoint, formData);
                addModal.close();
                addForm.reset();
                ui.showToast(payload.message, 'success');
                await refreshLoadedViews();
            } catch (error) {
                const message = (error.message || 'The subject assignment could not be added.')
                    .replace('already mapped to', 'already assigned to');
                ui.showFormError(addForm, message);
            } finally {
                ui.setFormSubmitting(addForm, false);
            }
        });
    }

    if (editForm) {
        editForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!editForm.reportValidity()) return;
            ui.clearFormError(editForm);
            const formData = new FormData(editForm);
            formData.set('action', 'update');
            ui.setFormSubmitting(editForm, true, 'Saving…');
            try {
                const payload = await ui.postForm(endpoint, formData);
                editModal.close();
                ui.showToast(payload.message, 'updated');
                await refreshLoadedViews();
            } catch (error) {
                ui.showFormError(editForm, error.message || 'The subject assignment could not be updated.');
            } finally {
                ui.setFormSubmitting(editForm, false);
            }
        });
    }

    document.getElementById('confirmRemoveProgramAssignment')?.addEventListener('click', async function () {
        if (!state.pendingRemoval || !removeElement) return;
        ui.clearFormError(removeElement);
        const button = this;
        const original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Removing…';
        const formData = new FormData();
        formData.set('action', 'remove');
        formData.set('id', state.pendingRemoval.id);
        try {
            const payload = await ui.postForm(endpoint, formData);
            removeModal.close();
            state.pendingRemoval = null;
            ui.showToast(payload.message, 'updated');
            await refreshLoadedViews();
        } catch (error) {
            ui.showFormError(removeElement, error.message || 'The subject assignment could not be removed.');
        } finally {
            button.disabled = false;
            button.innerHTML = original;
        }
    });

    function bindRowActions(view) {
        controls[view].rows?.addEventListener('click', function (event) {
            const edit = event.target.closest('[data-edit-program-assignment]');
            const remove = event.target.closest('[data-remove-program-assignment]');
            if (edit) openEditAssignment(edit.dataset.editProgramAssignment);
            if (remove) openRemoveAssignment(remove.dataset.removeProgramAssignment);
        });
    }

    controls.program.program?.addEventListener('change', async function () {
        controls.program.search.value = '';
        controls.program.year.value = '';
        controls.program.semester.value = '';
        resetCurriculumControls('program');
        await loadCurricula('program');
        updateProgramControlState();
        await loadAssignments('program', 1);
    });
    controls.program.curriculum?.addEventListener('change', async function () {
        await loadVersions('program');
        await loadAssignments('program', 1);
    });
    controls.program.version?.addEventListener('change', function () {
        updateProgramControlState();
        loadAssignments('program', 1);
    });
    controls.program.year?.addEventListener('change', function () { loadAssignments('program', 1); });
    controls.program.semester?.addEventListener('change', function () { loadAssignments('program', 1); });
    controls.program.perPage?.addEventListener('change', function () { loadAssignments('program', 1); });
    controls.program.search?.addEventListener('input', ui.debounce(function () { loadAssignments('program', 1); }, 300));

    controls.subject.program?.addEventListener('change', async function () {
        resetCurriculumControls('subject');
        await loadCurricula('subject');
        await loadAssignments('subject', 1);
    });
    controls.subject.curriculum?.addEventListener('change', async function () {
        await loadVersions('subject');
        await loadAssignments('subject', 1);
    });
    controls.subject.version?.addEventListener('change', function () { loadAssignments('subject', 1); });
    controls.subject.perPage?.addEventListener('change', function () { loadAssignments('subject', 1); });
    controls.subject.search?.addEventListener('input', ui.debounce(function () { loadAssignments('subject', 1); }, 300));

    document.getElementById('clearProgramAssignmentFilters')?.addEventListener('click', async function () {
        controls.program.program.value = '';
        controls.program.search.value = '';
        controls.program.year.value = '';
        controls.program.semester.value = '';
        resetCurriculumControls('program');
        updateProgramControlState();
        await loadAssignments('program', 1);
    });

    document.getElementById('clearSubjectUsageFilters')?.addEventListener('click', async function () {
        controls.subject.program.value = '';
        controls.subject.search.value = '';
        resetCurriculumControls('subject');
        await loadAssignments('subject', 1);
    });

    document.getElementById('addProgramAssignmentButton')?.addEventListener('click', openAddAssignment);
    document.getElementById('programAssignmentSubjectSearch')?.addEventListener(
        'input',
        ui.debounce(function () {
            ui.clearFormError(addForm);
            loadSubjectOptions(this.value);
        }, 300)
    );

    bindRowActions(activeView);

    async function initialize() {
        resetCurriculumControls(activeView);
        await loadPrograms();
        await loadAssignments(activeView, 1);
    }

    initialize();
}());
