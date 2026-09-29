(function () {
    'use strict';

    const workspace = document.getElementById('electivesWorkspace');
    if (!workspace || !window.CurriculumUI) return;

    const ui = window.CurriculumUI;
    const endpoints = {
        programs: workspace.dataset.programApi,
        curricula: workspace.dataset.curriculumApi,
        versions: workspace.dataset.versionApi,
        groups: workspace.dataset.groupApi,
        memberships: workspace.dataset.membershipApi
    };
    const canManage = workspace.dataset.canManageElectives === '1';
    const groupColumnCount = canManage ? 7 : 6;
    const membershipColumnCount = canManage ? 6 : 5;
    const state = {
        groupPage: 1,
        membershipPage: 1,
        groupRequest: 0,
        membershipRequest: 0,
        optionRequest: 0,
        groups: new Map(),
        memberships: new Map(),
        selectedGroup: null,
        pendingGroupRemoval: null,
        pendingMembershipRemoval: null
    };

    const programFilter = document.getElementById('electiveProgramFilter');
    const curriculumFilter = document.getElementById('electiveCurriculumFilter');
    const versionFilter = document.getElementById('electiveVersionFilter');
    const groupSearch = document.getElementById('electiveGroupSearch');
    const groupPerPage = document.getElementById('electiveGroupPerPage');
    const groupRows = document.getElementById('electiveGroupRows');
    const groupPagination = document.getElementById('electiveGroupPagination');
    const membershipSearch = document.getElementById('electiveSubjectSearch');
    const membershipPerPage = document.getElementById('electiveMembershipPerPage');
    const membershipRows = document.getElementById('electiveMembershipRows');
    const membershipPagination = document.getElementById('electiveMembershipPagination');
    const createGroupButton = document.getElementById('createElectiveGroupButton');
    const addSubjectButton = document.getElementById('addElectiveSubjectButton');
    const createGroupForm = document.getElementById('createElectiveGroupForm');
    const editGroupForm = document.getElementById('editElectiveGroupForm');
    const addSubjectForm = document.getElementById('addElectiveSubjectForm');
    const removeGroupElement = document.getElementById('removeElectiveGroupModal');
    const removeSubjectElement = document.getElementById('removeElectiveSubjectModal');
    const createGroupModal = ui.createModal(document.getElementById('createElectiveGroupModal'));
    const editGroupModal = ui.createModal(document.getElementById('editElectiveGroupModal'));
    const removeGroupModal = ui.createModal(removeGroupElement);
    const addSubjectModal = ui.createModal(document.getElementById('addElectiveSubjectModal'));
    const removeSubjectModal = ui.createModal(removeSubjectElement);

    function groupTableState(message, isError) {
        groupRows.innerHTML = '<tr class="state-row"><td colspan="' + groupColumnCount +
            '" class="state-cell' + (isError ? ' error-state' : '') + '">' +
            ui.escapeHtml(message) + '</td></tr>';
    }

    function membershipTableState(message, isError) {
        membershipRows.innerHTML = '<tr class="state-row"><td colspan="' + membershipColumnCount +
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

    function contextParts() {
        return [selectedText(programFilter), selectedText(curriculumFilter), selectedText(versionFilter)]
            .filter(Boolean);
    }

    function updateContextState() {
        const hasVersion = Number(versionFilter.value) > 0;
        if (createGroupButton) createGroupButton.disabled = !hasVersion;
        document.getElementById('electiveContextMessage').textContent = hasVersion
            ? 'Current context: ' + contextParts().join(' → ')
            : 'Select a curriculum version to manage elective groups.';
    }

    async function loadPrograms() {
        programFilter.disabled = true;
        programFilter.innerHTML = '<option value="">Loading programs…</option>';
        try {
            const payload = await ui.fetchJson(endpoints.programs, {
                action: 'list', page: 1, per_page: 50, search: '', status: ''
            });
            programFilter.innerHTML = '<option value="">All available programs</option>' +
                payload.programs.map(function (program) {
                    return optionMarkup(program.id, program.program_code + ' — ' + program.program_name);
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
            curriculumFilter.innerHTML = '<option value="">Select a curriculum</option>' +
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
            versionFilter.innerHTML = '<option value="">Select a curriculum version</option>' +
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

    function clearSelectedGroup() {
        state.selectedGroup = null;
        state.memberships.clear();
        membershipSearch.value = '';
        setMembershipControlsEnabled(false);
        document.getElementById('selectedElectiveGroupMessage').textContent =
            'Select an elective group to view its subjects.';
        ui.setCount(document.getElementById('electiveMembershipCount'), 0);
        membershipPagination.innerHTML = '';
        membershipTableState('Select an elective group to view its subjects.', false);
        groupRows.querySelectorAll('tr.is-selected').forEach(function (row) {
            row.classList.remove('is-selected');
        });
    }

    function setMembershipControlsEnabled(enabled) {
        membershipSearch.disabled = !enabled;
        membershipSearch.placeholder = enabled
            ? 'Subject code or title'
            : 'Select an elective group first';
        membershipPerPage.disabled = !enabled;
        if (addSubjectButton) addSubjectButton.disabled = !enabled;
    }

    function groupRow(group) {
        const count = Number(group.subject_count);
        const selected = state.selectedGroup && Number(state.selectedGroup.id) === Number(group.id);
        const actions = canManage
            ? '<td><div class="elective-group-actions">' +
                '<button type="button" class="row-action-button" data-edit-elective-group="' + Number(group.id) +
                '"><i class="fa-solid fa-pen"></i> Edit</button>' +
                '<button type="button" class="remove-action-button" data-remove-elective-group="' + Number(group.id) +
                '"' + (count > 0 ? ' disabled title="Remove all subjects before removing this group."' : '') +
                '><i class="fa-solid fa-trash"></i> Remove</button></div></td>'
            : '';
        return '<tr data-group-id="' + Number(group.id) + '"' + (selected ? ' class="is-selected"' : '') + '>' +
            '<td><span class="elective-group-name">' + ui.escapeHtml(group.group_name) + '</span></td>' +
            '<td><span class="elective-description">' + ui.escapeHtml(group.description || '—') + '</span></td>' +
            '<td><span class="elective-subject-count"><i class="fa-solid fa-book"></i> ' + count + '</span></td>' +
            '<td><span class="elective-context-stack"><strong>' + ui.escapeHtml(group.curriculum_name) +
                '</strong><span>' + ui.escapeHtml(group.curriculum_code) + '</span></span></td>' +
            '<td><span class="record-code">' + ui.escapeHtml(group.version_label) + '</span></td>' +
            '<td>' + ui.escapeHtml(ui.formatDate(group.updated_at)) + '</td>' +
            actions + '</tr>';
    }

    async function loadGroups(page, preserveGroupId) {
        const versionId = Number(versionFilter.value);
        state.groupPage = Number(page) || 1;
        if (!versionId) {
            state.groups.clear();
            ui.setCount(document.getElementById('electiveGroupCount'), 0);
            groupPagination.innerHTML = '';
            groupTableState('Select a curriculum version to manage elective groups.', false);
            clearSelectedGroup();
            updateContextState();
            return;
        }

        const sequence = ++state.groupRequest;
        groupTableState('Loading elective groups…', false);
        try {
            const payload = await ui.fetchJson(endpoints.groups, {
                action: 'list',
                curriculum_version_id: versionId,
                page: state.groupPage,
                per_page: groupPerPage.value,
                search: groupSearch.value.trim()
            });
            if (sequence !== state.groupRequest) return;
            state.groupPage = Number(payload.pagination.page);
            state.groups.clear();
            payload.groups.forEach(function (group) {
                state.groups.set(Number(group.id), group);
            });
            const keepId = Number(preserveGroupId || state.selectedGroup?.id || 0);
            if (keepId && state.groups.has(keepId)) {
                state.selectedGroup = state.groups.get(keepId);
            } else if (state.selectedGroup) {
                clearSelectedGroup();
            }
            ui.setCount(document.getElementById('electiveGroupCount'), Number(payload.pagination.total_records));
            if (!payload.groups.length) {
                groupTableState(
                    groupSearch.value.trim()
                        ? 'No elective groups match this search.'
                        : 'No elective groups are configured for this curriculum version.',
                    false
                );
            } else {
                groupRows.innerHTML = payload.groups.map(groupRow).join('');
            }
            ui.renderPagination(groupPagination, payload.pagination, loadGroups);
            updateContextState();
        } catch (error) {
            if (sequence !== state.groupRequest) return;
            state.groups.clear();
            ui.setCount(document.getElementById('electiveGroupCount'), 0);
            groupPagination.innerHTML = '';
            groupTableState(error.message || 'Elective groups could not be loaded.', true);
            clearSelectedGroup();
        }
    }

    function membershipRow(membership) {
        const actions = canManage
            ? '<td><button type="button" class="remove-action-button" data-remove-elective-subject="' +
                Number(membership.id) + '"><i class="fa-solid fa-link-slash"></i> Remove</button></td>'
            : '';
        return '<tr>' +
            '<td><span class="record-code">' + ui.escapeHtml(membership.subject_code) + '</span></td>' +
            '<td><span class="record-name">' + ui.escapeHtml(membership.subject_title) + '</span></td>' +
            '<td>' + ui.escapeHtml(membership.year_level || '—') + '</td>' +
            '<td>' + ui.escapeHtml(membership.semester || '—') + '</td>' +
            '<td>' + ui.escapeHtml(ui.formatUnits(membership.units)) + '</td>' +
            actions + '</tr>';
    }

    async function loadMemberships(page) {
        if (!state.selectedGroup) {
            clearSelectedGroup();
            return;
        }
        const sequence = ++state.membershipRequest;
        state.membershipPage = Number(page) || 1;
        membershipTableState('Loading elective group subjects…', false);
        try {
            const payload = await ui.fetchJson(endpoints.memberships, {
                action: 'list',
                elective_group_id: state.selectedGroup.id,
                page: state.membershipPage,
                per_page: membershipPerPage.value,
                search: membershipSearch.value.trim()
            });
            if (sequence !== state.membershipRequest) return;
            state.membershipPage = Number(payload.pagination.page);
            state.selectedGroup = payload.group;
            state.memberships.clear();
            payload.memberships.forEach(function (membership) {
                state.memberships.set(Number(membership.id), membership);
            });
            setMembershipControlsEnabled(true);
            document.getElementById('selectedElectiveGroupMessage').textContent =
                state.selectedGroup.group_name + ' • ' +
                state.selectedGroup.curriculum_code + ' / ' + state.selectedGroup.version_label;
            ui.setCount(document.getElementById('electiveMembershipCount'), Number(payload.pagination.total_records));
            if (!payload.memberships.length) {
                membershipTableState(
                    membershipSearch.value.trim()
                        ? 'No subjects match this search within the selected elective group.'
                        : 'No subjects have been added to this elective group.',
                    false
                );
            } else {
                membershipRows.innerHTML = payload.memberships.map(membershipRow).join('');
            }
            ui.renderPagination(membershipPagination, payload.pagination, loadMemberships);
        } catch (error) {
            if (sequence !== state.membershipRequest) return;
            state.memberships.clear();
            ui.setCount(document.getElementById('electiveMembershipCount'), 0);
            membershipPagination.innerHTML = '';
            membershipTableState(error.message || 'Elective group subjects could not be loaded.', true);
        }
    }

    function selectGroup(id) {
        const group = state.groups.get(Number(id));
        if (!group) return;
        state.selectedGroup = group;
        groupRows.querySelectorAll('tr[data-group-id]').forEach(function (row) {
            row.classList.toggle('is-selected', Number(row.dataset.groupId) === Number(group.id));
        });
        membershipSearch.value = '';
        setMembershipControlsEnabled(true);
        loadMemberships(1);
    }

    function openCreateGroup() {
        if (!canManage || !versionFilter.value || !createGroupForm || !createGroupModal) return;
        createGroupForm.reset();
        ui.clearFormError(createGroupForm);
        document.getElementById('createElectiveGroupVersionId').value = versionFilter.value;
        document.getElementById('createElectiveGroupContext').textContent = contextParts().join(' → ');
        createGroupModal.open();
        createGroupForm.elements.group_name.focus();
    }

    function openEditGroup(id) {
        const group = state.groups.get(Number(id));
        if (!canManage || !group || !editGroupForm || !editGroupModal) return;
        editGroupForm.reset();
        ui.clearFormError(editGroupForm);
        document.getElementById('editElectiveGroupId').value = group.id;
        document.getElementById('editElectiveGroupName').value = group.group_name;
        document.getElementById('editElectiveGroupDescription').value = group.description || '';
        document.getElementById('editElectiveGroupContext').textContent =
            group.curriculum_code + ' → ' + group.version_label;
        editGroupModal.open();
        document.getElementById('editElectiveGroupName').focus();
    }

    function placementLabel(placement) {
        const context = [placement.year_level, placement.semester].filter(Boolean).join(' / ');
        return placement.subject_code + ' — ' + placement.subject_title +
            (context ? ' • ' + context : '');
    }

    async function loadPlacementOptions(searchValue) {
        const select = document.getElementById('addElectiveSubjectId');
        const submit = addSubjectForm.querySelector('[type="submit"]');
        const sequence = ++state.optionRequest;
        select.disabled = true;
        submit.disabled = true;
        select.innerHTML = '<option value="">Loading mapped subjects…</option>';
        try {
            const payload = await ui.fetchJson(endpoints.memberships, {
                action: 'placement_options',
                elective_group_id: state.selectedGroup.id,
                page: 1,
                per_page: 50,
                search: String(searchValue || '').trim()
            });
            if (sequence !== state.optionRequest) return;
            select.innerHTML = '<option value="">Select a curriculum-mapped subject</option>' +
                payload.placements.map(function (placement) {
                    return optionMarkup(placement.subject_id, placementLabel(placement));
                }).join('');
            select.disabled = payload.placements.length === 0;
            submit.disabled = payload.placements.length === 0;
            if (!payload.placements.length) {
                ui.showFormError(
                    addSubjectForm,
                    searchValue
                        ? 'No available mapped subjects match this search.'
                        : 'All mapped subjects are already included in this elective group, or no subjects are mapped.'
                );
            }
        } catch (error) {
            if (sequence !== state.optionRequest) return;
            select.innerHTML = '<option value="">Mapped subjects unavailable</option>';
            ui.showFormError(addSubjectForm, error.message || 'Mapped subjects could not be loaded.');
        }
    }

    async function openAddSubject() {
        if (!canManage || !state.selectedGroup || !addSubjectForm || !addSubjectModal) return;
        addSubjectForm.reset();
        ui.clearFormError(addSubjectForm);
        document.getElementById('addElectiveSubjectGroupId').value = state.selectedGroup.id;
        document.getElementById('addElectiveSubjectContext').textContent =
            state.selectedGroup.group_name + ' • ' +
            state.selectedGroup.curriculum_code + ' / ' + state.selectedGroup.version_label;
        addSubjectModal.open();
        await loadPlacementOptions('');
        document.getElementById('electiveSubjectSelectorSearch').focus();
    }

    if (createGroupForm) {
        createGroupForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!createGroupForm.reportValidity()) return;
            ui.clearFormError(createGroupForm);
            const formData = new FormData(createGroupForm);
            formData.set('action', 'create');
            ui.setFormSubmitting(createGroupForm, true, 'Creating…');
            try {
                const payload = await ui.postForm(endpoints.groups, formData);
                createGroupModal.close();
                createGroupForm.reset();
                ui.showToast(payload.message, 'success');
                await loadGroups(1, payload.group.id);
                if (state.groups.has(Number(payload.group.id))) selectGroup(payload.group.id);
            } catch (error) {
                ui.showFormError(createGroupForm, error.message || 'The elective group could not be created.');
            } finally {
                ui.setFormSubmitting(createGroupForm, false);
            }
        });
    }

    if (editGroupForm) {
        editGroupForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!editGroupForm.reportValidity()) return;
            ui.clearFormError(editGroupForm);
            const formData = new FormData(editGroupForm);
            formData.set('action', 'update');
            ui.setFormSubmitting(editGroupForm, true, 'Saving…');
            try {
                const payload = await ui.postForm(endpoints.groups, formData);
                editGroupModal.close();
                ui.showToast(payload.message, 'updated');
                await loadGroups(state.groupPage, payload.group.id);
                if (state.groups.has(Number(payload.group.id))) selectGroup(payload.group.id);
            } catch (error) {
                ui.showFormError(editGroupForm, error.message || 'The elective group could not be updated.');
            } finally {
                ui.setFormSubmitting(editGroupForm, false);
            }
        });
    }

    if (addSubjectForm) {
        addSubjectForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!addSubjectForm.reportValidity()) return;
            ui.clearFormError(addSubjectForm);
            const formData = new FormData(addSubjectForm);
            formData.set('action', 'add');
            ui.setFormSubmitting(addSubjectForm, true, 'Adding…');
            try {
                const payload = await ui.postForm(endpoints.memberships, formData);
                addSubjectModal.close();
                addSubjectForm.reset();
                ui.showToast(payload.message, 'success');
                const groupId = state.selectedGroup.id;
                await loadGroups(state.groupPage, groupId);
                await loadMemberships(1);
            } catch (error) {
                ui.showFormError(addSubjectForm, error.message || 'The subject could not be added.');
            } finally {
                ui.setFormSubmitting(addSubjectForm, false);
            }
        });
    }

    document.getElementById('confirmRemoveElectiveGroup')?.addEventListener('click', async function () {
        if (!state.pendingGroupRemoval) return;
        ui.clearFormError(removeGroupElement);
        const button = this;
        const original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Removing…';
        const formData = new FormData();
        formData.set('action', 'remove');
        formData.set('id', state.pendingGroupRemoval.id);
        try {
            const payload = await ui.postForm(endpoints.groups, formData);
            removeGroupModal.close();
            state.pendingGroupRemoval = null;
            clearSelectedGroup();
            ui.showToast(payload.message, 'updated');
            await loadGroups(state.groupPage);
        } catch (error) {
            ui.showFormError(removeGroupElement, error.message || 'The elective group could not be removed.');
        } finally {
            button.disabled = false;
            button.innerHTML = original;
        }
    });

    document.getElementById('confirmRemoveElectiveSubject')?.addEventListener('click', async function () {
        if (!state.pendingMembershipRemoval) return;
        ui.clearFormError(removeSubjectElement);
        const button = this;
        const original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Removing…';
        const formData = new FormData();
        formData.set('action', 'remove');
        formData.set('id', state.pendingMembershipRemoval.id);
        try {
            const payload = await ui.postForm(endpoints.memberships, formData);
            removeSubjectModal.close();
            state.pendingMembershipRemoval = null;
            ui.showToast(payload.message, 'updated');
            const groupId = state.selectedGroup.id;
            await loadGroups(state.groupPage, groupId);
            await loadMemberships(state.membershipPage);
        } catch (error) {
            ui.showFormError(removeSubjectElement, error.message || 'The subject could not be removed.');
        } finally {
            button.disabled = false;
            button.innerHTML = original;
        }
    });

    programFilter.addEventListener('change', async function () {
        await loadCurricula();
        clearSelectedGroup();
        await loadGroups(1);
    });
    curriculumFilter.addEventListener('change', async function () {
        await loadVersions();
        clearSelectedGroup();
        await loadGroups(1);
    });
    versionFilter.addEventListener('change', async function () {
        clearSelectedGroup();
        await loadGroups(1);
    });
    groupPerPage.addEventListener('change', function () { loadGroups(1); });
    groupSearch.addEventListener('input', ui.debounce(function () { loadGroups(1); }, 300));
    membershipPerPage.addEventListener('change', function () { loadMemberships(1); });
    membershipSearch.addEventListener('input', ui.debounce(function () { loadMemberships(1); }, 300));

    document.getElementById('clearElectiveFilters').addEventListener('click', async function () {
        programFilter.value = '';
        groupSearch.value = '';
        await loadCurricula();
        clearSelectedGroup();
        await loadGroups(1);
    });

    createGroupButton?.addEventListener('click', openCreateGroup);
    addSubjectButton?.addEventListener('click', openAddSubject);
    document.getElementById('electiveSubjectSelectorSearch')?.addEventListener(
        'input',
        ui.debounce(function () {
            ui.clearFormError(addSubjectForm);
            loadPlacementOptions(this.value);
        }, 300)
    );

    groupRows.addEventListener('click', function (event) {
        const editButton = event.target.closest('[data-edit-elective-group]');
        const removeButton = event.target.closest('[data-remove-elective-group]');
        const row = event.target.closest('[data-group-id]');
        if (editButton) {
            openEditGroup(editButton.dataset.editElectiveGroup);
            return;
        }
        if (removeButton) {
            if (removeButton.disabled || !canManage || !removeGroupModal) return;
            const group = state.groups.get(Number(removeButton.dataset.removeElectiveGroup));
            if (!group) return;
            state.pendingGroupRemoval = { id: group.id };
            ui.clearFormError(removeGroupElement);
            document.getElementById('removeElectiveGroupMessage').textContent =
                'Remove the empty elective group “' + group.group_name +
                '”? Curriculum, subject, placement, and prerequisite records will remain.';
            removeGroupModal.open();
            document.getElementById('confirmRemoveElectiveGroup').focus();
            return;
        }
        if (row) selectGroup(row.dataset.groupId);
    });

    membershipRows.addEventListener('click', function (event) {
        const removeButton = event.target.closest('[data-remove-elective-subject]');
        if (!removeButton || !canManage || !removeSubjectModal) return;
        const membership = state.memberships.get(Number(removeButton.dataset.removeElectiveSubject));
        if (!membership) return;
        state.pendingMembershipRemoval = { id: membership.id };
        ui.clearFormError(removeSubjectElement);
        document.getElementById('removeElectiveSubjectMessage').textContent =
            'Remove ' + membership.subject_code + ' — ' + membership.subject_title +
            ' from “' + state.selectedGroup.group_name +
            '”? Only this elective-group membership will be deleted.';
        removeSubjectModal.open();
        document.getElementById('confirmRemoveElectiveSubject').focus();
    });

    async function initialize() {
        await loadPrograms();
        await loadCurricula();
        await loadGroups(1);
    }

    initialize();
}());
