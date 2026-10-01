(function () {
    'use strict';

    const workspace = document.getElementById('subjectWorkspace');
    if (!workspace || !window.CurriculumUI) return;

    const ui = window.CurriculumUI;
    const api = workspace.dataset.subjectApi;
    const canCreate = workspace.dataset.canCreateSubjects === '1';
    const canUpdate = workspace.dataset.canUpdateSubjects === '1';
    const columnCount = canUpdate ? 7 : 6;
    const rows = document.getElementById('subjectRows');
    const count = document.getElementById('subjectCount');
    const pagination = document.getElementById('subjectPagination');
    const search = document.getElementById('subjectSearch');
    const status = document.getElementById('subjectStatus');
    const perPage = document.getElementById('subjectPerPage');
    const versionFilter = document.getElementById('subjectVersionFilter');
    const yearFilter = document.getElementById('subjectYearFilter');
    const semesterFilter = document.getElementById('subjectSemesterFilter');
    let contextRequest = 0;
    let contextPage = 0;
    const form = document.getElementById('subjectForm');
    const modal = ui.createModal(document.getElementById('subjectModal'));
    let requestSequence = 0;
    let currentPage = 1;
    let formMode = 'create';

    function stateRow(message, isError) {
        rows.innerHTML = '<tr class="state-row"><td colspan="' + columnCount + '" class="state-cell' +
            (isError ? ' error-state' : '') + '">' + ui.escapeHtml(message) + '</td></tr>';
    }

    function subjectRow(subject) {
        const action = canUpdate
            ? '<td class="row-actions"><button type="button" class="row-action-button" data-edit-subject="' +
                Number(subject.id) + '"><i class="fa-solid fa-pen"></i> Edit</button></td>'
            : '';
        return '<tr>' +
            '<td><span class="record-code">' + ui.escapeHtml(subject.subject_code) + '</span></td>' +
            '<td><span class="record-name">' + ui.escapeHtml(subject.subject_title) + '</span></td>' +
            '<td>' + ui.escapeHtml(ui.formatUnits(subject.units)) + '</td>' +
            '<td>' + ui.escapeHtml(ui.formatUnits(subject.lecture_units)) + '</td>' +
            '<td>' + ui.escapeHtml(ui.formatUnits(subject.laboratory_units)) + '</td>' +
            '<td>' + ui.statusBadge(subject.status) + '</td>' + action +
            '</tr>';
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
                per_page: 20, curriculum_version_id: versionFilter.value
            });
            if (request !== contextRequest) return;
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
            });
            document.getElementById('subjectContextNote').textContent = versionFilter.value
                ? 'Placement context: ' + versionFilter.selectedOptions[0].textContent + '. Subject records remain unique.'
                : 'Filters use curriculum placements. Each Subject Master record appears only once.';
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
        ui.clearFormError(form);
    }

    function openCreateSubject() {
        if (!canCreate || !form || !modal) return;
        formMode = 'create';
        resetSubjectForm();
        document.getElementById('subjectModalTitle').textContent = 'New Subject';
        document.getElementById('subjectSubmitButton').textContent = 'Create Subject';
        modal.open();
        document.getElementById('subjectCode').focus();
    }

    async function openEditSubject(id) {
        if (!canUpdate || !form || !modal) return;
        formMode = 'update';
        resetSubjectForm();
        document.getElementById('subjectModalTitle').textContent = 'Edit Subject';
        document.getElementById('subjectSubmitButton').textContent = 'Save Changes';
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
            document.getElementById('subjectLectureUnits').value = subject.lecture_units ?? '';
            document.getElementById('subjectLaboratoryUnits').value = subject.laboratory_units ?? '';
            document.getElementById('subjectFormStatus').value = subject.status || 'active';
            document.getElementById('subjectCode').focus();
        } catch (error) {
            ui.showFormError(form, error.message || 'The subject could not be loaded.');
        } finally {
            ui.setFormSubmitting(form, false);
        }
    }

    if (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!form.reportValidity()) return;
            ui.clearFormError(form);
            const formData = new FormData(form);
            formData.set('action', formMode);
            ui.setFormSubmitting(form, true, formMode === 'create' ? 'Creating…' : 'Saving…');
            try {
                const payload = await ui.postForm(api, formData);
                modal.close();
                resetSubjectForm();
                ui.showToast(payload.message, formMode === 'create' ? 'success' : 'updated');
                await loadSubjects(currentPage);
            } catch (error) {
                ui.showFormError(form, error.message || 'The subject could not be saved.');
            } finally {
                ui.setFormSubmitting(form, false);
            }
        });
    }

    document.getElementById('newSubjectButton')?.addEventListener('click', openCreateSubject);
    rows.addEventListener('click', function (event) {
        const button = event.target.closest('[data-edit-subject]');
        if (button) openEditSubject(button.dataset.editSubject);
    });

    document.getElementById('subjectSearchForm').addEventListener('submit', function (event) {
        event.preventDefault();
        loadSubjects(1);
    });
    search.addEventListener('input', ui.debounce(function () { loadSubjects(1); }, 300));
    status.addEventListener('change', function () { loadSubjects(1); });
    perPage.addEventListener('change', function () { loadSubjects(1); });

    if (versionFilter) {
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
