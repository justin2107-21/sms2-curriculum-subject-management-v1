(function () {
    'use strict';

    const workspace = document.getElementById('subjectWorkspace');
    if (!workspace || !window.CurriculumUI) return;

    let cimfSelectedSubjectId = '';
    window.CSMSPageCIMFContext = () => ({subject_id:cimfSelectedSubjectId});
    const ui = window.CurriculumUI;
    const api = workspace.dataset.subjectApi;
    const assignmentApi = workspace.dataset.assignmentApi;
    const programApi = workspace.dataset.programApi;
    const curriculumApi = workspace.dataset.curriculumApi;
    const versionApi = workspace.dataset.versionApi;
    const canViewPlacementContext = workspace.dataset.canViewPlacementContext === '1';
    const canCreate = workspace.dataset.canCreateSubjects === '1'
        && workspace.dataset.canAssignCreatedSubject === '1';
    const canUpdate = workspace.dataset.canUpdateSubjects === '1';
    const canAssignCreatedSubject = workspace.dataset.canAssignCreatedSubject === '1';
    const columnCount = 7;
    const rows = document.getElementById('subjectRows');
    const count = document.getElementById('subjectCount');
    const pagination = document.getElementById('subjectPagination');
    const search = document.getElementById('subjectSearch');
    const status = document.getElementById('subjectStatus');
    const perPage = document.getElementById('subjectPerPage');
    const programFilter = document.getElementById('subjectProgramFilter');
    const curriculumFilter = document.getElementById('subjectCurriculumFilter');
    const versionFilter = document.getElementById('subjectVersionFilter');
    const yearFilter = document.getElementById('subjectYearFilter');
    const semesterFilter = document.getElementById('subjectSemesterFilter');
    const programCards = document.getElementById('subjectProgramCards');
    let contextRequest = 0;
    let contextPage = 0;
    let viewingSubjectId = null;
    const form = document.getElementById('subjectForm');
    const modal = ui.createModal(document.getElementById('subjectModal'));
    const detailsModal = ui.createModal(document.getElementById('subjectDetailsModal'));
    const assignmentSection = document.getElementById('subjectAssignmentSection');
    const assignmentCurriculumInput = document.getElementById('subjectAssignmentCurriculumInput');
    let assignmentContextRequest = 0;
    let assignmentCurriculumAutocomplete;
    let assignmentVersionAutocomplete;
    const assignmentVersionInput = document.getElementById('subjectAssignmentVersionInput');
    const assignmentProgramAutocomplete = ui.createAutocomplete({
        input: document.getElementById('subjectAssignmentProgramInput'),
        valueInput: document.getElementById('subjectAssignmentProgramId'),
        list: document.getElementById('subjectAssignmentProgramOptions'),
        helpText: 'Choose an existing academic program.',
        selectedText: 'Academic program selected.',
        loadingText: 'Searching academic programs…',
        noResultsText: 'No matching academic programs found.',
        search: async function (query) {
            const payload = await ui.fetchJson(programApi, {
                action: 'list', page: 1, per_page: 20, search: query, status: ''
            });
            return payload.programs;
        },
        getId: item => item.id,
        format: item => ({ label: item.program_code + ' — ' + item.program_name,
            code: item.program_code, title: item.program_name, meta: item.program_type || '' }),
        onSelect: function () { resetAssignmentCurricula(); },
        onClear: function () { resetAssignmentCurricula(); }
    });
    assignmentCurriculumAutocomplete = ui.createAutocomplete({
        input: document.getElementById('subjectAssignmentCurriculumInput'),
        valueInput: document.getElementById('subjectAssignmentCurriculumId'),
        list: document.getElementById('subjectAssignmentCurriculumOptions'),
        helpText: 'Choose a curriculum for the selected program.',
        selectedText: 'Curriculum selected.',
        loadingText: 'Searching curricula…',
        noResultsText: 'No curricula match this search for the selected program.',
        search: async function (query) {
            const programId = assignmentProgramAutocomplete?.getSelectedId();
            if (!programId) return [];
            const payload = await ui.fetchJson(curriculumApi, {
                action: 'list', page: 1, per_page: 20, search: query, status: '',
                academic_program_id: programId
            });
            return payload.curricula;
        },
        getId: item => item.id,
        format: item => ({ label: item.curriculum_code + ' — ' + item.curriculum_name,
            code: item.curriculum_code, title: item.curriculum_name }),
        onSelect: function () { resetAssignmentVersions(); },
        onClear: function () { resetAssignmentVersions(); }
    });
    assignmentVersionAutocomplete = ui.createAutocomplete({
        input: assignmentVersionInput,
        valueInput: document.getElementById('subjectAssignmentVersionId'),
        list: document.getElementById('subjectAssignmentVersionOptions'),
        helpText: 'Choose a version for the selected curriculum.',
        selectedText: 'Curriculum version selected.',
        loadingText: 'Searching curriculum versions…',
        noResultsText: 'No curriculum versions match this search.',
        search: async function (query) {
            const curriculumId = assignmentCurriculumAutocomplete?.getSelectedId();
            if (!curriculumId) return [];
            const payload = await ui.fetchJson(versionApi, {
                action: 'list', curriculum_id: curriculumId,
                page: 1, per_page: 20, search: query
            });
            return payload.versions;
        },
        getId: item => item.id,
        format: item => ({ label: item.version_label +
            (item.effective_academic_year ? ' — ' + item.effective_academic_year : ''),
            code: item.version_label, title: item.effective_academic_year || item.status }),
        onSelect: item => loadAssignmentPlacementValues(item.id),
        onClear: resetAssignmentPlacementValues
    });
    let requestSequence = 0;
    let detailsRequest = 0;
    let currentPage = 1;
    let formMode = 'create';
    let subjectPersistedAfterAssignmentFailure = false;
    let legacyLectureUnits = '';
    let legacyLaboratoryUnits = '';

    function setAssignmentOptions(select, values, placeholder, fallback) {
        if (!select) return;
        const options = values.length ? values : fallback;
        select.replaceChildren(new Option(placeholder, ''));
        options.forEach(function (value) { select.add(new Option(value, value)); });
        select.value = '';
    }

    function resetAssignmentPlacementValues() {
        setAssignmentOptions(document.getElementById('subjectAssignmentYear'), [], 'Select year level', []);
        setAssignmentOptions(document.getElementById('subjectAssignmentSemester'), [], 'Select semester', []);
    }

    async function loadAssignmentPlacementValues(versionId) {
        const request = ++assignmentContextRequest;
        resetAssignmentPlacementValues();
        try {
            const payload = await ui.fetchJson(assignmentApi, {
                action: 'mapping_list', page: 1, per_page: 1, search: '',
                academic_program_id: assignmentProgramAutocomplete?.getSelectedId() || '',
                curriculum_id: assignmentCurriculumAutocomplete?.getSelectedId() || '',
                curriculum_version_id: versionId, year_level: '', semester: ''
            });
            if (request !== assignmentContextRequest) return;
            setAssignmentOptions(document.getElementById('subjectAssignmentYear'),
                payload.filter_values.year_levels || [], 'Select year level',
                ['1st Year', '2nd Year', '3rd Year', '4th Year']);
            setAssignmentOptions(document.getElementById('subjectAssignmentSemester'),
                payload.filter_values.semesters || [], 'Select semester',
                ['1st Semester', '2nd Semester']);
        } catch (error) {
            if (request !== assignmentContextRequest) return;
            setAssignmentOptions(document.getElementById('subjectAssignmentYear'), [],
                'Select year level', ['1st Year', '2nd Year', '3rd Year', '4th Year']);
            setAssignmentOptions(document.getElementById('subjectAssignmentSemester'), [],
                'Select semester', ['1st Semester', '2nd Semester']);
            ui.showToast(error.message || 'Placement values could not be loaded.', 'warning');
        }
    }

    function resetAssignmentVersions() {
        if (!assignmentVersionInput) return;
        assignmentVersionAutocomplete?.clear();
        resetAssignmentPlacementValues();
        const enabled = Boolean(assignmentCurriculumAutocomplete?.hasSelection());
        assignmentVersionInput.disabled = !enabled;
        assignmentVersionInput.placeholder = enabled
            ? 'Search version label or academic year'
            : 'Select a curriculum first';
    }

    function resetAssignmentCurricula() {
        if (!assignmentCurriculumInput) return;
        assignmentCurriculumAutocomplete?.clear();
        assignmentCurriculumInput.disabled = !assignmentProgramAutocomplete?.hasSelection();
        assignmentCurriculumInput.placeholder =
            assignmentProgramAutocomplete?.hasSelection()
                ? 'Search curriculum code or name' : 'Select a program first';
        resetAssignmentVersions();
    }

    function updateAssignmentVisibility() {
        const visible = Boolean(canAssignCreatedSubject && formMode === 'create');
        if (assignmentSection) assignmentSection.hidden = !visible;
        const assignmentFields = document.getElementById('subjectAssignmentFields');
        if (assignmentFields) assignmentFields.disabled = !visible;
    }

    function setSubjectActionsBusy(busy) {
        const button = document.getElementById('subjectSubmitButton');
        if (button) button.disabled = busy || subjectPersistedAfterAssignmentFailure;
    }

    function resetAssignmentSelectors() {
        if (!assignmentVersionInput) return;
        assignmentProgramAutocomplete?.clear();
        assignmentCurriculumAutocomplete?.clear();
        assignmentVersionAutocomplete?.clear();
        assignmentCurriculumInput.disabled = true;
        assignmentCurriculumInput.placeholder = 'Select a program first';
        assignmentVersionInput.disabled = true;
        assignmentVersionInput.placeholder = 'Select a curriculum first';
        resetAssignmentPlacementValues();
    }

    function stateRow(message, isError) {
        rows.innerHTML = '<tr class="state-row"><td colspan="' + columnCount + '" class="state-cell' +
            (isError ? ' error-state' : '') + '">' + ui.escapeHtml(message) + '</td></tr>';
    }

    function subjectRow(subject) {
        const action = '<td class="row-actions subject-row-actions">' +
            '<button type="button" class="row-action-button" data-view-subject="' + Number(subject.id) + '"><i class="fa-solid fa-eye" aria-hidden="true"></i> View</button>' +
            (canUpdate ? '<button type="button" class="row-action-button" data-edit-subject="' + Number(subject.id) + '"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit</button>' : '') +
            '</td>';
        return '<tr data-record-view="' + Number(subject.id) + '" tabindex="0" aria-label="View ' +
            ui.escapeHtml(subject.subject_code + ' ' + subject.subject_title) + '">' +
            '<td><span class="record-code">' + ui.escapeHtml(subject.subject_code) + '</span></td>' +
            '<td><span class="record-name">' + ui.escapeHtml(subject.subject_title) + '</span></td>' +
            '<td>' + ui.escapeHtml(ui.formatUnits(subject.units)) + '</td>' +
            '<td>' + contextListCell(subject.program_context) + '</td>' +
            '<td>' + contextListCell(subject.placement_context) + '</td>' +
            '<td>' + ui.statusBadge(subject.status) + '</td>' + action +
            '</tr>';
    }

    function contextListCell(values) {
        if (!canViewPlacementContext || !Array.isArray(values) || values.length === 0) return '<span class="subtle-text">—</span>';
        return '<span class="subject-context-values">' + values.map(value => '<span>' + ui.escapeHtml(value) + '</span>').join('') + '</span>';
    }

    function renderProgramCards(programs) {
        if (!programCards) return;
        const selected = programFilter?.value || '';
        const cards = ['<button type="button" class="subject-program-card' + (!selected ? ' is-selected' : '') + '" data-program-card="" aria-pressed="' + (!selected) + '"><strong>View All</strong><span>All programs</span></button>'];
        programs.forEach(function (program) {
            const id = String(Number(program.id));
            const active = selected === id;
            cards.push('<button type="button" class="subject-program-card' + (active ? ' is-selected' : '') + '" data-program-card="' + id + '" aria-pressed="' + active + '"><strong>' +
                ui.escapeHtml(program.program_code) + '</strong><span>' + ui.escapeHtml(program.program_name) + '</span><small>' +
                ui.escapeHtml(String(Number(program.subject_count) || 0)) + ' subjects</small></button>');
        });
        programCards.innerHTML = cards.join('');
    }

    async function loadSubjects(page) {
        const sequence = ++requestSequence;
        currentPage = Number(page) || 1;
        stateRow('Loading subjects…', false);
        try {
            const payload = await ui.fetchJson(api, {
                action: 'list',
                page: currentPage,
                per_page: perPage.value,
                search: search.value.trim(),
                status: status.value,
                academic_program_id: programFilter?.value || '',
                curriculum_id: curriculumFilter?.value || '',
                curriculum_version_id: versionFilter?.value || '',
                year_level: yearFilter?.value || '',
                semester: semesterFilter?.value || ''
            });
            if (sequence !== requestSequence) return;
            currentPage = Number(payload.pagination.page);
            ui.setCount(count, Number(payload.pagination.total_records));
            if (!payload.subjects.length) {
                const message = canCreate
                    ? 'No subjects found. Use New Subject to create the first matching record.'
                    : 'No subjects found.';
                stateRow(message, false);
            } else {
                rows.innerHTML = payload.subjects.map(subjectRow).join('');
            }
            ui.renderPagination(pagination, payload.pagination, loadSubjects);
        } catch (error) {
            if (sequence !== requestSequence) return;
            ui.setCount(count, 0);
            pagination.innerHTML = '';
            stateRow(error.message || 'Subjects could not be loaded.', true);
        }
    }

    async function loadContexts(filtersOnly) {
        if (!versionFilter) return;
        const request = ++contextRequest;
        const more = document.getElementById('subjectMoreVersions');
        more.disabled = true;
        try {
            const payload = await ui.fetchJson(api, {
                action: 'contexts', page: filtersOnly ? 1 : contextPage + 1,
                per_page: 20, academic_program_id: programFilter.value,
                curriculum_id: curriculumFilter.value, curriculum_version_id: versionFilter.value
            });
            if (request !== contextRequest) return;
            const programSelection = programFilter.value;
            programFilter.replaceChildren(new Option('All programs', ''));
            payload.programs.forEach(program => programFilter.add(new Option(program.program_code + ' — ' + program.program_name, program.id)));
            if (Array.from(programFilter.options).some(option => option.value === programSelection)) programFilter.value = programSelection;
            const curriculumSelection = curriculumFilter.value;
            curriculumFilter.replaceChildren(new Option('All curricula', ''));
            payload.curricula.forEach(curriculum => curriculumFilter.add(new Option(curriculum.curriculum_code + ' — ' + curriculum.curriculum_name, curriculum.id)));
            if (Array.from(curriculumFilter.options).some(option => option.value === curriculumSelection)) curriculumFilter.value = curriculumSelection;
            curriculumFilter.disabled = payload.curricula.length === 0;
            renderProgramCards(payload.programs);
            if (!filtersOnly) {
                payload.versions.forEach(version => {
                    if (!Array.from(versionFilter.options).some(option => option.value === String(version.id))) {
                        versionFilter.add(new Option(version.curriculum_code + ' — ' + version.version_label, version.id));
                    }
                });
                contextPage = Number(payload.pagination.page);
                more.hidden = contextPage >= Number(payload.pagination.total_pages);
            }
            [[yearFilter, payload.filter_values.year_levels, 'All year levels'],
                [semesterFilter, payload.filter_values.semesters, 'All semesters']].forEach(([select, values, label]) => {
                const selected = select.value;
                select.replaceChildren(new Option(label, ''));
                values.forEach(value => select.add(new Option(value, value)));
                if (values.includes(selected)) select.value = selected;
                select.disabled = values.length === 0;
            });
            versionFilter.disabled = Number(payload.pagination.total_records) === 0;
            document.getElementById('subjectContextNote').textContent = 'Each Subject Master record appears once. Placement labels reflect stored curriculum assignments.';
        } catch (error) {
            if (request !== contextRequest) return;
            document.getElementById('subjectContextNote').textContent = error.message || 'Placement filters could not be loaded.';
        } finally {
            if (request === contextRequest) more.disabled = false;
        }
    }

    function resetSubjectForm() {
        if (!form) return;
        form.reset();
        form.querySelector('#subjectId').value = '';
        form.querySelector('#subjectFormStatus').value = 'active';
        subjectPersistedAfterAssignmentFailure = false;
        legacyLectureUnits = '';
        legacyLaboratoryUnits = '';
        resetAssignmentSelectors();
        updateAssignmentVisibility();
        ui.clearFormError(form);
    }

    function openCreateSubject() {
        if (!canCreate || !form || !modal) return;
        formMode = 'create';
        resetSubjectForm();
        document.getElementById('subjectModalTitle').textContent = 'New Subject';
        updateAssignmentVisibility();
        modal.open();
        document.getElementById('subjectCode').focus();
    }

    async function openEditSubject(id) {
        cimfSelectedSubjectId = id; window.CSMSCIMF?.updateContext();
        if (!canUpdate || !form || !modal) return;
        formMode = 'update';
        resetSubjectForm();
        document.getElementById('subjectModalTitle').textContent = 'Edit Subject';
        document.getElementById('subjectSubmitButton').textContent = 'Save Changes';
        updateAssignmentVisibility();
        modal.open();
        ui.setFormSubmitting(form, true, 'Loading…');
        try {
            const payload = await ui.fetchJson(api, { action: 'get', id: id });
            const subject = payload.subject;
            document.getElementById('subjectId').value = subject.id;
            document.getElementById('subjectCode').value = subject.subject_code || '';
            document.getElementById('subjectTitle').value = subject.subject_title || '';
            document.getElementById('subjectDescription').value = subject.description || '';
            document.getElementById('subjectUnits').value = subject.units ?? '';
            document.getElementById('subjectFormStatus').value = subject.status || 'active';
            legacyLectureUnits = subject.lecture_units ?? '';
            legacyLaboratoryUnits = subject.laboratory_units ?? '';
            document.getElementById('subjectCode').focus();
        } catch (error) {
            ui.showFormError(form, error.message || 'The subject could not be loaded.');
        } finally {
            ui.setFormSubmitting(form, false);
        }
    }

    async function openSubjectDetails(id) {
        cimfSelectedSubjectId = id; window.CSMSCIMF?.updateContext();
        if (!detailsModal) return;
        const request = ++detailsRequest;
        viewingSubjectId = Number(id);
        detailsModal.open();
        document.getElementById('subjectDetailsTitle').textContent = 'Loading subject…';
        document.getElementById('subjectDetailsCode').textContent = '';
        document.getElementById('subjectDetailsStatus').innerHTML = '';
        document.getElementById('subjectDetailsName').textContent = '—';
        document.getElementById('subjectDetailsUnits').textContent = '—';
        document.getElementById('subjectPlacementDetail').hidden = false;
        document.getElementById('subjectPlacementDetail').textContent = 'Loading curriculum usage…';
        document.getElementById('subjectUsageRows').hidden = true;
        document.getElementById('subjectUsageRows').replaceChildren();
        document.getElementById('subjectUsageCount').textContent = 'Loading';
        try {
            const payload = await ui.fetchJson(api, { action: 'get', id: id });
            if (request !== detailsRequest) return;
            const subject = payload.subject;
            document.getElementById('subjectDetailsTitle').textContent = 'Subject Details';
            document.getElementById('subjectDetailsCode').textContent = subject.subject_code || '';
            document.getElementById('subjectDetailsStatus').innerHTML = ui.statusBadge(subject.status);
            document.getElementById('subjectDetailsName').textContent = subject.subject_title || '—';
            document.getElementById('subjectDetailsUnits').textContent = ui.formatUnits(subject.units);
            try {
                await loadSubjectUsage(subject, request);
            } catch (usageError) {
                if (request !== detailsRequest) return;
                document.getElementById('subjectUsageCount').textContent = 'Unavailable';
                const usageMessage = document.getElementById('subjectPlacementDetail');
                usageMessage.hidden = false;
                usageMessage.textContent = usageError.message || 'Curriculum usage could not be loaded.';
            }
        } catch (error) {
            if (request !== detailsRequest) return;
            document.getElementById('subjectDetailsTitle').textContent = 'Subject unavailable';
            document.getElementById('subjectDetailsCode').textContent = error.message || 'The record could not be loaded.';
        }
    }

    async function loadSubjectUsage(subject, request) {
        const note = document.getElementById('subjectPlacementDetail');
        const list = document.getElementById('subjectUsageRows');
        const count = document.getElementById('subjectUsageCount');
        if (!canViewPlacementContext || !assignmentApi) {
            count.textContent = 'Unavailable';
            note.textContent = 'Curriculum usage is not available with your current permissions.';
            return;
        }

        const mappings = [];
        let page = 1;
        let totalPages = 1;
        let expectedTotal = null;
        const seen = new Set();
        do {
            const payload = await ui.fetchJson(assignmentApi, {
                action: 'mapping_list', page: page, per_page: 50,
                search: subject.subject_code, academic_program_id: '', curriculum_id: '',
                curriculum_version_id: '', year_level: '', semester: ''
            });
            if (request !== detailsRequest) return;
            if (expectedTotal === null) expectedTotal = Number(payload.pagination.total_records);
            if (Number(payload.pagination.page) !== page ||
                Number(payload.pagination.total_records) !== expectedTotal) {
                throw new Error('Placement usage changed while loading. Please open the subject again.');
            }
            totalPages = Number(payload.pagination.total_pages) || 1;
            payload.mappings.forEach(function (mapping) {
                if (Number(mapping.subject_id) !== Number(subject.id)) return;
                if (seen.has(String(mapping.id))) throw new Error('Duplicate placement usage returned by the server.');
                seen.add(String(mapping.id));
                mappings.push(mapping);
            });
            page += 1;
        } while (page <= totalPages);

        if (request !== detailsRequest) return;
        count.textContent = mappings.length + (mappings.length === 1 ? ' placement' : ' placements');
        if (!mappings.length) {
            note.textContent = 'No curriculum placements are stored for this subject.';
            return;
        }
        note.hidden = true;
        list.innerHTML = mappings.map(function (mapping) {
            const placement = Number(mapping.is_required) === 1 ? 'Required' : 'Optional';
            const location = [mapping.year_level, mapping.semester].filter(Boolean).join(' / ') || 'Placement not specified';
            const target = '../../../modules/curriculum/pages/curricula.php?curriculum_id=' + encodeURIComponent(mapping.curriculum_id);
            return '<li><div class="subject-usage-context"><a href="' + target + '">' +
                ui.escapeHtml(mapping.curriculum_code + ' — ' + mapping.curriculum_name) + '</a>' +
                '<span>' + ui.escapeHtml(mapping.version_label) + ' · ' + ui.escapeHtml(location) +
                ' · ' + placement + '</span></div><strong>' + ui.escapeHtml(ui.formatUnits(mapping.units)) + ' units</strong></li>';
        }).join('');
        list.hidden = false;
    }

    async function saveSubject() {
        if (!form || subjectPersistedAfterAssignmentFailure) return;
        const isCreate = formMode === 'create';

        if (isCreate) {
            if (!assignmentProgramAutocomplete?.hasSelection()) {
                ui.showFormError(form, 'Please select a valid academic program.');
                document.getElementById('subjectAssignmentProgramInput').focus();
                return;
            }
            if (!assignmentCurriculumAutocomplete?.hasSelection()) {
                ui.showFormError(form, 'Please select a valid curriculum.');
                assignmentCurriculumInput?.focus();
                return;
            }
            if (!assignmentVersionAutocomplete?.hasSelection()) {
                ui.showFormError(form, 'Please select a valid curriculum version.');
                assignmentVersionInput?.focus();
                return;
            }
        }
        if (!form.reportValidity()) return;

        ui.clearFormError(form);
        setSubjectActionsBusy(true);
        try {
            let payload;
            if (isCreate) {
                const subjectData = new FormData();
                ['subject_code', 'subject_title', 'description', 'units', 'status'].forEach(function (name) {
                    subjectData.set(name, form.elements.namedItem(name).value);
                });
                subjectData.set('action', 'create');
                payload = await ui.postForm(api, subjectData);
            } else {
                const subjectData = new FormData();
                ['subject_code', 'subject_title', 'description', 'units', 'status'].forEach(function (name) {
                    subjectData.set(name, form.elements.namedItem(name).value);
                });
                subjectData.set('id', form.querySelector('#subjectId').value);
                subjectData.set('lecture_units', legacyLectureUnits);
                subjectData.set('laboratory_units', legacyLaboratoryUnits);
                subjectData.set('action', 'update');
                payload = await ui.postForm(api, subjectData);
            }

            if (isCreate) {
                const assignmentData = new FormData();
                assignmentData.set('action', 'assign');
                assignmentData.set('subject_id', String(payload.subject.id));
                assignmentData.set('curriculum_version_id', assignmentVersionAutocomplete.getSelectedId());
                assignmentData.set('year_level', document.getElementById('subjectAssignmentYear').value.trim());
                assignmentData.set('semester', document.getElementById('subjectAssignmentSemester').value.trim());
                assignmentData.set('is_required', document.getElementById('subjectAssignmentRequired').value);
                try {
                    await ui.postForm(assignmentApi, assignmentData);
                } catch (assignmentError) {
                    subjectPersistedAfterAssignmentFailure = true;
                    setSubjectActionsBusy(false);
                    await loadSubjects(1);
                    const message = 'Subject was created, but curriculum assignment failed.';
                    ui.showFormError(form, message + ' ' + (assignmentError.message || 'Use Curriculum Builder to assign it later.'));
                    ui.showToast(message, 'error');
                    return;
                }
            }

            modal.close();
            resetSubjectForm();
            await loadSubjects(isCreate ? 1 : currentPage);
            ui.showToast(isCreate
                ? 'Subject created and assigned successfully.'
                : (payload.message || 'Subject saved.'), isCreate ? 'success' : 'updated');
        } catch (error) {
            ui.showFormError(form, error.message || 'The subject could not be saved.');
            ui.showToast(error.message || 'The subject could not be saved.', 'error');
        } finally {
            if (isCreate) updateAssignmentVisibility();
            setSubjectActionsBusy(false);
        }
    }

    document.getElementById('newSubjectButton')?.addEventListener('click', openCreateSubject);
    document.getElementById('subjectDetailsEdit')?.addEventListener('click', function () {
        if (!canUpdate || !viewingSubjectId) return;
        detailsModal?.close();
        openEditSubject(viewingSubjectId);
    });
    form?.addEventListener('submit', function (event) {
        event.preventDefault();
        saveSubject();
    });
    rows.addEventListener('click', function (event) {
        const viewButton = event.target.closest('[data-view-subject]');
        if (viewButton) {
            event.stopPropagation();
            openSubjectDetails(viewButton.dataset.viewSubject);
            return;
        }
        const button = event.target.closest('[data-edit-subject]');
        if (button) {
            event.stopPropagation();
            openEditSubject(button.dataset.editSubject);
            return;
        }
        const row = event.target.closest('tr[data-record-view]');
        if (row) openSubjectDetails(row.dataset.recordView);
    });
    rows.addEventListener('keydown', function (event) {
        const row = event.target.closest('tr[data-record-view]');
        if (row && event.target === row && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            openSubjectDetails(row.dataset.recordView);
        }
    });

    document.getElementById('subjectSearchForm').addEventListener('submit', function (event) {
        event.preventDefault();
        loadSubjects(1);
    });
    search.addEventListener('input', ui.debounce(function () { loadSubjects(1); }, 300));
    status.addEventListener('change', function () { loadSubjects(1); });
    perPage.addEventListener('change', function () { loadSubjects(1); });

    if (programFilter) {
        programFilter.addEventListener('change', function () {
            curriculumFilter.value = '';
            versionFilter.replaceChildren(new Option('All versions', ''));
            versionFilter.value = '';
            yearFilter.value = '';
            semesterFilter.value = '';
            contextPage = 0;
            loadContexts(false);
            loadSubjects(1);
        });
        curriculumFilter.addEventListener('change', function () {
            versionFilter.replaceChildren(new Option('All versions', ''));
            versionFilter.value = '';
            yearFilter.value = '';
            semesterFilter.value = '';
            contextPage = 0;
            loadContexts(false);
            loadSubjects(1);
        });
        programCards?.addEventListener('click', function (event) {
            const card = event.target.closest('[data-program-card]');
            if (!card) return;
            programFilter.value = card.dataset.programCard;
            programFilter.dispatchEvent(new Event('change', { bubbles: true }));
        });
        versionFilter.addEventListener('change', function () {
            yearFilter.value = '';
            semesterFilter.value = '';
            loadContexts(true);
            loadSubjects(1);
        });
        yearFilter.addEventListener('change', function () { loadSubjects(1); });
        semesterFilter.addEventListener('change', function () { loadSubjects(1); });
        document.getElementById('subjectMoreVersions').addEventListener('click', function () { loadContexts(false); });
        loadContexts(false);
    }
    loadSubjects(1);
}());
