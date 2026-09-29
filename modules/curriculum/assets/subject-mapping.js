(function () {
    'use strict';

    const workspace = document.getElementById('subjectMappingWorkspace');
    if (!workspace || !window.CurriculumUI) return;

    const ui = window.CurriculumUI;
    const endpoints = {
        programs: workspace.dataset.programApi,
        curricula: workspace.dataset.curriculumApi,
        versions: workspace.dataset.versionApi,
        subjects: workspace.dataset.subjectApi,
        assignments: workspace.dataset.assignmentApi
    };
    const permissions = {
        viewPrograms: workspace.dataset.canViewPrograms === '1',
        viewSubjects: workspace.dataset.canViewSubjects === '1',
        manage: workspace.dataset.canManageMappings === '1',
        add: workspace.dataset.canAddMappings === '1'
    };
    const columnCount = permissions.manage ? 10 : 9;
    const state = {
        page: 1,
        request: 0,
        subjectRequest: 0,
        mappings: new Map(),
        pendingRemoval: null
    };

    const programFilter = document.getElementById('mappingProgramFilter');
    const curriculumFilter = document.getElementById('mappingCurriculumFilter');
    const versionFilter = document.getElementById('mappingVersionFilter');
    const yearFilter = document.getElementById('mappingYearFilter');
    const semesterFilter = document.getElementById('mappingSemesterFilter');
    const subjectSearch = document.getElementById('mappingSubjectSearch');
    const perPage = document.getElementById('mappingPerPage');
    const rows = document.getElementById('mappingRows');
    const pagination = document.getElementById('mappingPagination');
    const addForm = document.getElementById('addMappingForm');
    const editForm = document.getElementById('editMappingForm');
    const removeElement = document.getElementById('removeMappingModal');
    const addModal = ui.createModal(document.getElementById('addMappingModal'));
    const editModal = ui.createModal(document.getElementById('editMappingModal'));
    const removeModal = ui.createModal(removeElement);

    function tableState(message, isError) {
        rows.innerHTML = '<tr class="state-row"><td colspan="' + columnCount + '" class="state-cell' +
            (isError ? ' error-state' : '') + '">' + ui.escapeHtml(message) + '</td></tr>';
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

    function updateContextState() {
        const addButton = document.getElementById('addMappingButton');
        const hasVersion = Number(versionFilter.value) > 0;
        if (addButton) addButton.disabled = !hasVersion;
        const parts = [selectedText(programFilter), selectedText(curriculumFilter), selectedText(versionFilter)]
            .filter(Boolean);
        document.getElementById('mappingContextMessage').textContent = parts.length
            ? 'Current context: ' + parts.join(' → ')
            : 'Showing all available subject placements.';
    }

    async function loadPrograms() {
        if (!permissions.viewPrograms) return;
        programFilter.disabled = true;
        programFilter.innerHTML = '<option value="">Loading programs…</option>';
        try {
            const payload = await ui.fetchJson(endpoints.programs, {
                action: 'list', page: 1, per_page: 50, search: '', status: ''
            });
            programFilter.innerHTML = '<option value="">All available programs</option>' +
                payload.programs.map(function (program) {
                    return optionMarkup(
                        program.id,
                        program.program_code + ' — ' + program.program_name,
                        null
                    );
                }).join('');
            programFilter.disabled = false;
        } catch (error) {
            programFilter.innerHTML = '<option value="">Programs unavailable</option>';
            ui.showToast(error.message || 'Academic programs could not be loaded.', 'error');
        }
    }

    async function loadCurricula(selectedId) {
        curriculumFilter.disabled = true;
        curriculumFilter.innerHTML = '<option value="">Loading curricula…</option>';
        versionFilter.disabled = true;
        versionFilter.innerHTML = '<option value="">Select a curriculum first</option>';
        try {
            const payload = await ui.fetchJson(endpoints.curricula, {
                action: 'list',
                page: 1,
                per_page: 50,
                search: '',
                status: '',
                academic_program_id: programFilter.value
            });
            curriculumFilter.innerHTML = '<option value="">All available curricula</option>' +
                payload.curricula.map(function (curriculum) {
                    return optionMarkup(
                        curriculum.id,
                        curriculum.curriculum_code + ' — ' + curriculum.curriculum_name,
                        selectedId
                    );
                }).join('');
            curriculumFilter.disabled = payload.curricula.length === 0;
        } catch (error) {
            curriculumFilter.innerHTML = '<option value="">Curricula unavailable</option>';
            ui.showToast(error.message || 'Curricula could not be loaded.', 'error');
        }
        updateContextState();
    }

    async function loadVersions(selectedId) {
        const curriculumId = Number(curriculumFilter.value);
        versionFilter.disabled = true;
        if (!curriculumId) {
            versionFilter.innerHTML = '<option value="">Select a curriculum first</option>';
            updateContextState();
            return;
        }
        versionFilter.innerHTML = '<option value="">Loading versions…</option>';
        try {
            const payload = await ui.fetchJson(endpoints.versions, {
                action: 'list', curriculum_id: curriculumId, page: 1, per_page: 50
            });
            versionFilter.innerHTML = '<option value="">All curriculum versions</option>' +
                payload.versions.map(function (version) {
                    const year = version.effective_academic_year
                        ? ' — ' + version.effective_academic_year
                        : '';
                    return optionMarkup(version.id, version.version_label + year, selectedId);
                }).join('');
            versionFilter.disabled = payload.versions.length === 0;
        } catch (error) {
            versionFilter.innerHTML = '<option value="">Versions unavailable</option>';
            ui.showToast(error.message || 'Curriculum versions could not be loaded.', 'error');
        }
        updateContextState();
    }

    function updateStoredValueSelect(select, values, emptyLabel) {
        const selected = select.value;
        select.innerHTML = '<option value="">' + ui.escapeHtml(emptyLabel) + '</option>' +
            values.map(function (value) {
                return '<option value="' + ui.escapeHtml(value) + '"' +
                    (value === selected ? ' selected' : '') + '>' + ui.escapeHtml(value) + '</option>';
            }).join('');
        if (selected && !values.includes(selected)) select.value = '';
    }

    function mappingRow(mapping) {
        const required = Number(mapping.is_required) === 1;
        const actions = permissions.manage
            ? '<td><div class="mapping-action-group">' +
                '<button type="button" class="row-action-button" data-edit-mapping="' + Number(mapping.id) +
                '"><i class="fa-solid fa-pen"></i> Edit</button>' +
                '<button type="button" class="remove-action-button" data-remove-mapping="' + Number(mapping.id) +
                '"><i class="fa-solid fa-link-slash"></i> Remove</button></div></td>'
            : '';
        return '<tr>' +
            '<td><span class="record-code">' + ui.escapeHtml(mapping.subject_code) + '</span></td>' +
            '<td><span class="record-name">' + ui.escapeHtml(mapping.subject_title) + '</span></td>' +
            '<td><span class="mapping-context-stack"><strong>' + ui.escapeHtml(mapping.program_name) +
                '</strong><span>' + ui.escapeHtml(mapping.program_code) + '</span></span></td>' +
            '<td><span class="mapping-context-stack"><strong>' + ui.escapeHtml(mapping.curriculum_name) +
                '</strong><span>' + ui.escapeHtml(mapping.curriculum_code) + '</span></span></td>' +
            '<td><span class="record-code">' + ui.escapeHtml(mapping.version_label) + '</span></td>' +
            '<td>' + ui.escapeHtml(mapping.year_level || '—') + '</td>' +
            '<td>' + ui.escapeHtml(mapping.semester || '—') + '</td>' +
            '<td>' + ui.escapeHtml(mapping.sequence_order ?? '—') + '</td>' +
            '<td><span class="placement-indicator ' +
                (required ? 'placement-required' : 'placement-optional') + '">' +
                (required ? 'Required' : 'Optional') + '</span></td>' +
            actions + '</tr>';
    }

    async function loadMappings(page) {
        const sequence = ++state.request;
        state.page = Number(page) || 1;
        tableState('Loading subject mappings…', false);
        try {
            const payload = await ui.fetchJson(endpoints.assignments, {
                action: 'mapping_list',
                page: state.page,
                per_page: perPage.value,
                search: subjectSearch.value.trim(),
                academic_program_id: programFilter.value,
                curriculum_id: curriculumFilter.value,
                curriculum_version_id: versionFilter.value,
                year_level: yearFilter.value,
                semester: semesterFilter.value
            });
            if (sequence !== state.request) return;
            state.page = Number(payload.pagination.page);
            state.mappings.clear();
            payload.mappings.forEach(function (mapping) {
                state.mappings.set(Number(mapping.id), mapping);
            });
            ui.setCount(document.getElementById('mappingCount'), Number(payload.pagination.total_records));
            updateStoredValueSelect(yearFilter, payload.filter_values.year_levels, 'All stored values');
            updateStoredValueSelect(semesterFilter, payload.filter_values.semesters, 'All stored values');
            if (!payload.mappings.length) {
                let message = 'No subject mappings found.';
                if (versionFilter.value && permissions.add) {
                    message = 'This curriculum version does not have any matching subject mappings yet. Use Add Subject Mapping to create one.';
                } else if (subjectSearch.value.trim()) {
                    message = 'No subjects match the selected filters.';
                }
                tableState(message, false);
            } else {
                rows.innerHTML = payload.mappings.map(mappingRow).join('');
            }
            ui.renderPagination(pagination, payload.pagination, loadMappings);
            updateContextState();
        } catch (error) {
            if (sequence !== state.request) return;
            state.mappings.clear();
            ui.setCount(document.getElementById('mappingCount'), 0);
            pagination.innerHTML = '';
            tableState(error.message || 'Subject mappings could not be loaded.', true);
        }
    }

    async function loadSubjectOptions(searchValue) {
        const select = document.getElementById('mappingSubjectId');
        if (!select) return 0;
        const sequence = ++state.subjectRequest;
        const submit = addForm.querySelector('[type="submit"]');
        select.disabled = true;
        submit.disabled = true;
        select.innerHTML = '<option value="">Loading subjects…</option>';
        try {
            const payload = await ui.fetchJson(endpoints.subjects, {
                action: 'list', page: 1, per_page: 50, search: String(searchValue || '').trim(), status: ''
            });
            if (sequence !== state.subjectRequest) return 0;
            select.innerHTML = '<option value="">Select a Subject Master record</option>' +
                payload.subjects.map(function (subject) {
                    return optionMarkup(
                        subject.id,
                        subject.subject_code + ' — ' + subject.subject_title,
                        null
                    );
                }).join('');
            select.disabled = payload.subjects.length === 0;
            submit.disabled = payload.subjects.length === 0;
            return Number(payload.pagination.total_records);
        } catch (error) {
            if (sequence !== state.subjectRequest) return 0;
            select.innerHTML = '<option value="">Subjects unavailable</option>';
            ui.showFormError(addForm, error.message || 'Subject Master records could not be loaded.');
            return 0;
        }
    }

    async function openAddMapping() {
        if (!permissions.add || !versionFilter.value || !addForm || !addModal) return;
        addForm.reset();
        ui.clearFormError(addForm);
        document.getElementById('addMappingVersionId').value = versionFilter.value;
        document.getElementById('mappingSubjectSelectorSearch').value = '';
        document.getElementById('addMappingContext').textContent =
            [selectedText(programFilter), selectedText(curriculumFilter), selectedText(versionFilter)]
                .filter(Boolean).join(' → ');
        addModal.open();
        const total = await loadSubjectOptions('');
        if (!total) ui.showFormError(addForm, 'No Subject Master records are available.');
        document.getElementById('mappingSubjectSelectorSearch').focus();
    }

    function openEditMapping(id) {
        const mapping = state.mappings.get(Number(id));
        if (!mapping || !permissions.manage || !editForm || !editModal) return;
        editForm.reset();
        ui.clearFormError(editForm);
        document.getElementById('editMappingId').value = mapping.id;
        document.getElementById('editMappingContext').textContent =
            mapping.program_code + ' → ' + mapping.curriculum_code + ' → ' + mapping.version_label;
        document.getElementById('editMappingSubject').textContent =
            mapping.subject_code + ' — ' + mapping.subject_title;
        document.getElementById('editMappingVersion').textContent =
            mapping.curriculum_code + ' — ' + mapping.version_label;
        document.getElementById('editMappingYear').value = mapping.year_level || '';
        document.getElementById('editMappingSemester').value = mapping.semester || '';
        document.getElementById('editMappingSequence').value = mapping.sequence_order ?? '';
        document.getElementById('editMappingRequired').value = Number(mapping.is_required) === 1 ? '1' : '0';
        editModal.open();
        document.getElementById('editMappingYear').focus();
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
                const payload = await ui.postForm(endpoints.assignments, formData);
                addModal.close();
                addForm.reset();
                ui.showToast(payload.message, 'success');
                await loadMappings(1);
            } catch (error) {
                ui.showFormError(addForm, error.message || 'The subject mapping could not be added.');
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
                const payload = await ui.postForm(endpoints.assignments, formData);
                editModal.close();
                ui.showToast(payload.message, 'updated');
                await loadMappings(state.page);
            } catch (error) {
                ui.showFormError(editForm, error.message || 'The subject mapping could not be updated.');
            } finally {
                ui.setFormSubmitting(editForm, false);
            }
        });
    }

    document.getElementById('confirmRemoveMapping')?.addEventListener('click', async function () {
        if (!state.pendingRemoval || !removeElement) return;
        ui.clearFormError(removeElement);
        const button = this;
        const originalLabel = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Removing…';
        const formData = new FormData();
        formData.set('action', 'remove');
        formData.set('id', state.pendingRemoval.id);
        try {
            const payload = await ui.postForm(endpoints.assignments, formData);
            removeModal.close();
            state.pendingRemoval = null;
            ui.showToast(payload.message, 'updated');
            await loadMappings(state.page);
        } catch (error) {
            ui.showFormError(removeElement, error.message || 'The subject mapping could not be removed.');
        } finally {
            button.disabled = false;
            button.innerHTML = originalLabel;
        }
    });

    programFilter.addEventListener('change', async function () {
        await loadCurricula();
        await loadMappings(1);
    });
    curriculumFilter.addEventListener('change', async function () {
        await loadVersions();
        await loadMappings(1);
    });
    versionFilter.addEventListener('change', function () { loadMappings(1); });
    yearFilter.addEventListener('change', function () { loadMappings(1); });
    semesterFilter.addEventListener('change', function () { loadMappings(1); });
    perPage.addEventListener('change', function () { loadMappings(1); });
    subjectSearch.addEventListener('input', ui.debounce(function () { loadMappings(1); }, 300));

    document.getElementById('clearMappingFilters').addEventListener('click', async function () {
        programFilter.value = '';
        subjectSearch.value = '';
        yearFilter.value = '';
        semesterFilter.value = '';
        await loadCurricula();
        await loadMappings(1);
    });

    document.getElementById('addMappingButton')?.addEventListener('click', openAddMapping);
    document.getElementById('mappingSubjectSelectorSearch')?.addEventListener(
        'input',
        ui.debounce(function () {
            ui.clearFormError(addForm);
            loadSubjectOptions(document.getElementById('mappingSubjectSelectorSearch').value);
        }, 300)
    );

    rows.addEventListener('click', function (event) {
        const editButton = event.target.closest('[data-edit-mapping]');
        const removeButton = event.target.closest('[data-remove-mapping]');
        if (editButton) openEditMapping(editButton.dataset.editMapping);
        if (removeButton && permissions.manage && removeModal) {
            const mapping = state.mappings.get(Number(removeButton.dataset.removeMapping));
            if (!mapping) return;
            state.pendingRemoval = { id: mapping.id };
            ui.clearFormError(removeElement);
            document.getElementById('removeMappingMessage').textContent =
                'Remove ' + mapping.subject_code + ' — ' + mapping.subject_title +
                ' from ' + mapping.curriculum_code + ' / ' + mapping.version_label +
                '? Only this placement will be deleted.';
            removeModal.open();
            document.getElementById('confirmRemoveMapping').focus();
        }
    });

    async function initialize() {
        await loadPrograms();
        await loadCurricula();
        await loadMappings(1);
    }

    initialize();
}());
