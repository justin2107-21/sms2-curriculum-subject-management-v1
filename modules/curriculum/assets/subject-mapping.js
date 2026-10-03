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
    const columnCount = permissions.manage ? 6 : 5;
    const state = {
        page: 1,
        request: 0,
        mappings: new Map(),
        pendingRemoval: null,
        viewingMapping: null,
        clearingFilters: false,
        resettingContext: false
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
    const detailsModal = ui.createModal(document.getElementById('mappingDetailsModal'));
    const programInput = document.getElementById('mappingProgramInput');
    const curriculumInput = document.getElementById('mappingCurriculumInput');
    const versionInput = document.getElementById('mappingVersionInput');
    let curriculumAutocomplete;
    let versionAutocomplete;
    const programAutocomplete = ui.createAutocomplete({
        input: programInput, valueInput: programFilter,
        list: document.getElementById('mappingProgramOptions'),
        helpText: 'All available programs', selectedText: 'Program context selected.',
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
        onSelect: function () { resetMappingContext(); if (!state.clearingFilters && !state.resettingContext) loadMappings(1); },
        onClear: function () { resetMappingContext(); if (!state.clearingFilters && !state.resettingContext) loadMappings(1); }
    });
    curriculumAutocomplete = ui.createAutocomplete({
        input: curriculumInput, valueInput: curriculumFilter,
        list: document.getElementById('mappingCurriculumOptions'),
        helpText: 'All available curricula', selectedText: 'Curriculum context selected.',
        loadingText: 'Searching curricula…', noResultsText: 'No matching curricula found.',
        search: async function (query) {
            const payload = await ui.fetchJson(endpoints.curricula, {
                action: 'list', page: 1, per_page: 20, search: query, status: '',
                academic_program_id: programFilter.value
            });
            return payload.curricula;
        },
        getId: item => item.id,
        format: item => ({ label: item.curriculum_code + ' — ' + item.curriculum_name,
            code: item.curriculum_code, title: item.curriculum_name }),
        onSelect: function () { resetMappingVersion(); if (!state.clearingFilters && !state.resettingContext) loadMappings(1); },
        onClear: function () { resetMappingVersion(); if (!state.clearingFilters && !state.resettingContext) loadMappings(1); }
    });
    versionAutocomplete = ui.createAutocomplete({
        input: versionInput, valueInput: versionFilter,
        list: document.getElementById('mappingVersionOptions'),
        helpText: 'Select a curriculum first', selectedText: 'Version context selected.',
        loadingText: 'Searching versions…', noResultsText: 'No matching versions found.',
        search: async function (query) {
            if (!curriculumFilter.value) return [];
            const payload = await ui.fetchJson(endpoints.versions, {
                action: 'list', curriculum_id: curriculumFilter.value,
                page: 1, per_page: 20, search: query
            });
            return payload.versions;
        },
        getId: item => item.id,
        format: item => ({ label: item.version_label +
            (item.effective_academic_year ? ' — ' + item.effective_academic_year : ''),
            code: item.version_label, title: item.effective_academic_year || item.status }),
        onSelect: function () { if (!state.clearingFilters && !state.resettingContext) loadMappings(1); },
        onClear: function () { if (!state.clearingFilters && !state.resettingContext) loadMappings(1); }
    });
    const subjectAutocomplete = ui.createAutocomplete({
        input: document.getElementById('mappingSubjectSelectorSearch'),
        valueInput: document.getElementById('mappingSubjectId'),
        list: document.getElementById('mappingSubjectOptions'),
        status: document.getElementById('mappingSubjectHint'),
        helpText: 'Type a code or title and select a Subject Master record.',
        selectedText: 'Subject selected.',
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
        format: subject => ({ label: subject.subject_code + ' — ' + subject.subject_title,
            code: subject.subject_code, title: subject.subject_title }),
        onError: error => ui.showFormError(addForm, error.message || 'Subject Master records could not be loaded.')
    });

    function tableState(message, isError) {
        rows.innerHTML = '<tr class="state-row"><td colspan="' + columnCount + '" class="state-cell' +
            (isError ? ' error-state' : '') + '">' + ui.escapeHtml(message) + '</td></tr>';
    }

    function updateContextState() {
        const addButton = document.getElementById('addMappingButton');
        const hasVersion = Number(versionFilter.value) > 0;
        if (addButton) addButton.disabled = !hasVersion;
        const parts = [programAutocomplete?.getSelectedLabel(), curriculumAutocomplete?.getSelectedLabel(), versionAutocomplete?.getSelectedLabel()]
            .filter(Boolean);
        document.getElementById('mappingContextMessage').textContent = parts.length
            ? 'Current context: ' + parts.join(' → ')
            : 'Showing all available subject placements.';
    }

    function resetMappingVersion() {
        const wasResetting = state.resettingContext;
        state.resettingContext = true;
        versionAutocomplete?.clear();
        versionInput.disabled = !curriculumFilter.value;
        versionInput.placeholder = curriculumFilter.value
            ? 'Search version label or year' : 'Select a curriculum first';
        state.resettingContext = wasResetting;
        updateContextState();
    }

    function resetMappingContext() {
        const wasResetting = state.resettingContext;
        state.resettingContext = true;
        curriculumAutocomplete?.clear();
        curriculumInput.placeholder = 'All available curricula';
        resetMappingVersion();
        state.resettingContext = wasResetting;
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
        return '<tr data-record-view="' + Number(mapping.id) + '" tabindex="0" aria-label="View mapping for ' +
            ui.escapeHtml(mapping.subject_code + ' ' + mapping.subject_title) + '">' +
            '<td><span class="mapping-subject-cell"><span class="record-code">' + ui.escapeHtml(mapping.subject_code) +
                '</span><span class="record-name">' + ui.escapeHtml(mapping.subject_title) + '</span></span></td>' +
            '<td>' + ui.escapeHtml(mapping.year_level || '—') + '</td>' +
            '<td>' + ui.escapeHtml(mapping.semester || '—') + '</td>' +
            '<td><span class="placement-indicator ' +
                (required ? 'placement-required' : 'placement-optional') + '">' +
                (required ? 'Required' : 'Optional') + '</span></td>' +
            '<td>' + ui.escapeHtml(ui.formatUnits(mapping.units)) + '</td>' +
            actions + '</tr>';
    }

    function openMappingDetails(id) {
        const mapping = state.mappings.get(Number(id));
        if (!mapping || !detailsModal) return;
        state.viewingMapping = mapping;
        document.getElementById('mappingDetailsTitle').textContent = mapping.subject_title || 'Mapping Details';
        document.getElementById('mappingDetailsCode').textContent = mapping.subject_code || '';
        document.getElementById('mappingDetailsSubject').textContent = mapping.subject_title || '—';
        document.getElementById('mappingDetailsCurriculum').textContent =
            mapping.curriculum_code + ' — ' + mapping.curriculum_name;
        document.getElementById('mappingDetailsVersion').textContent = mapping.version_label || '—';
        document.getElementById('mappingDetailsYear').textContent = mapping.year_level || '—';
        document.getElementById('mappingDetailsSemester').textContent = mapping.semester || '—';
        document.getElementById('mappingDetailsPlacement').textContent =
            Number(mapping.is_required) === 1 ? 'Required' : 'Optional';
        document.getElementById('mappingDetailsUnits').textContent = ui.formatUnits(mapping.units);
        detailsModal.open();
    }

    function requestMappingRemoval(mapping) {
        if (!mapping || !permissions.manage || !removeModal) return;
        state.pendingRemoval = { id: mapping.id };
        ui.clearFormError(removeElement);
        document.getElementById('removeMappingMessage').textContent =
            'Remove ' + mapping.subject_code + ' — ' + mapping.subject_title +
            ' from ' + mapping.curriculum_code + ' / ' + mapping.version_label +
            '? Only this placement will be deleted.';
        detailsModal?.close();
        removeModal.open();
        document.getElementById('confirmRemoveMapping').focus();
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

    async function openAddMapping() {
        if (!permissions.add || !versionFilter.value || !addForm || !addModal) return;
        addForm.reset();
        ui.clearFormError(addForm);
        document.getElementById('addMappingVersionId').value = versionFilter.value;
        subjectAutocomplete?.clear();
        document.getElementById('addMappingContext').textContent =
            [programAutocomplete?.getSelectedLabel(), curriculumAutocomplete?.getSelectedLabel(), versionAutocomplete?.getSelectedLabel()]
                .filter(Boolean).join(' → ');
        addModal.open();
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
        detailsModal?.close();
        editModal.open();
        document.getElementById('editMappingYear').focus();
    }

    if (addForm) {
        addForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!subjectAutocomplete?.hasSelection()) {
                ui.showFormError(addForm, 'Please select a valid subject.');
                document.getElementById('mappingSubjectSelectorSearch').focus();
                return;
            }
            if (!addForm.reportValidity()) return;
            ui.clearFormError(addForm);
            const formData = new FormData(addForm);
            formData.set('action', 'assign');
            ui.setFormSubmitting(addForm, true, 'Adding…');
            try {
                const payload = await ui.postForm(endpoints.assignments, formData);
                addModal.close();
                addForm.reset();
                subjectAutocomplete?.clear();
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

    yearFilter.addEventListener('change', function () { loadMappings(1); });
    semesterFilter.addEventListener('change', function () { loadMappings(1); });
    perPage.addEventListener('change', function () { loadMappings(1); });
    subjectSearch.addEventListener('input', ui.debounce(function () { loadMappings(1); }, 300));

    document.getElementById('clearMappingFilters').addEventListener('click', async function () {
        state.clearingFilters = true;
        programAutocomplete?.clear();
        curriculumAutocomplete?.clear();
        versionAutocomplete?.clear();
        subjectSearch.value = '';
        yearFilter.value = '';
        semesterFilter.value = '';
        versionInput.disabled = true;
        versionInput.placeholder = 'Select a curriculum first';
        state.clearingFilters = false;
        await loadMappings(1);
    });

    document.getElementById('addMappingButton')?.addEventListener('click', openAddMapping);
    document.getElementById('mappingSubjectSelectorSearch')?.addEventListener('input', function () {
        ui.clearFormError(addForm);
    });

    rows.addEventListener('click', function (event) {
        const editButton = event.target.closest('[data-edit-mapping]');
        const removeButton = event.target.closest('[data-remove-mapping]');
        if (editButton) {
            event.stopPropagation();
            openEditMapping(editButton.dataset.editMapping);
            return;
        }
        if (removeButton) {
            event.stopPropagation();
            requestMappingRemoval(state.mappings.get(Number(removeButton.dataset.removeMapping)));
            return;
        }
        const row = event.target.closest('tr[data-record-view]');
        if (row) openMappingDetails(row.dataset.recordView);
    });
    rows.addEventListener('keydown', function (event) {
        const row = event.target.closest('tr[data-record-view]');
        if (row && event.target === row && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            openMappingDetails(row.dataset.recordView);
        }
    });

    document.getElementById('mappingDetailsEdit')?.addEventListener('click', function () {
        if (state.viewingMapping && permissions.manage) openEditMapping(state.viewingMapping.id);
    });
    document.getElementById('mappingDetailsRemove')?.addEventListener('click', function () {
        if (state.viewingMapping && permissions.manage) requestMappingRemoval(state.viewingMapping);
    });

    async function initialize() {
        if (permissions.viewPrograms) {
            try {
                const programs = await ui.fetchJson(endpoints.programs, {
                    action: 'list', page: 1, per_page: 50, search: '', status: ''
                });
                const program = programs.programs.find(function (item) {
                    return String(item.status).toLowerCase() === 'active';
                }) || programs.programs[0];
                if (program) {
                    programAutocomplete?.setSelected(program, false);
                    const curricula = await ui.fetchJson(endpoints.curricula, {
                        action: 'list', page: 1, per_page: 50, search: '', status: '',
                        academic_program_id: program.id
                    });
                    const curriculum = curricula.curricula.find(function (item) {
                        return String(item.status).toLowerCase() === 'active';
                    }) || curricula.curricula[0];
                    if (curriculum) {
                        curriculumAutocomplete?.setSelected(curriculum, false);
                        versionInput.disabled = false;
                        versionInput.placeholder = 'Search version label or year';
                        const versions = await ui.fetchJson(endpoints.versions, {
                            action: 'list', curriculum_id: curriculum.id, page: 1, per_page: 50
                        });
                        const version = versions.versions.find(function (item) {
                            return String(item.status).toLowerCase() === 'active';
                        }) || versions.versions[0];
                        if (version) versionAutocomplete?.setSelected(version, false);
                    }
                }
            } catch (error) {
                ui.showToast(error.message || 'Current curriculum context could not be loaded.', 'warning');
            }
        }
        updateContextState();
        await loadMappings(1);
    }

    initialize();
}());
