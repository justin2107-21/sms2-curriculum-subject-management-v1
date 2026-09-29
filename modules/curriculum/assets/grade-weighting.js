(function () {
    'use strict';

    const workspace = document.getElementById('gradeWeightingWorkspace');
    const ui = window.CurriculumUI;
    if (!workspace || !ui) return;

    const endpoint = workspace.dataset.weightingApi;
    const canManage = workspace.dataset.canManage === '1';
    const columnCount = Number(workspace.dataset.componentColumnCount || 4);
    const state = {
        placement: null,
        scheme: null,
        components: new Map(),
        componentPage: 1,
        contextRequest: 0,
        componentRequest: 0,
        removeComponent: null,
    };

    const program = document.getElementById('weightingProgram');
    const curriculum = document.getElementById('weightingCurriculum');
    const version = document.getElementById('weightingVersion');
    const placement = document.getElementById('weightingPlacement');
    const rows = document.getElementById('weightingComponentRows');
    const pagination = document.getElementById('weightingComponentPagination');
    const search = document.getElementById('weightingComponentSearch');
    const perPage = document.getElementById('weightingComponentPerPage');
    const createSchemeButton = document.getElementById('createWeightingScheme');
    const editSchemeButton = document.getElementById('editWeightingScheme');
    const removeSchemeButton = document.getElementById('removeWeightingScheme');
    const addComponentButton = document.getElementById('addWeightingComponent');

    const schemeForm = document.getElementById('weightingSchemeForm');
    const componentForm = document.getElementById('weightingComponentForm');
    const schemeModal = ui.createModal(document.getElementById('weightingSchemeModal'));
    const componentModal = ui.createModal(document.getElementById('weightingComponentModal'));
    const removeComponentModal = ui.createModal(document.getElementById('removeWeightingComponentModal'));
    const removeSchemeModal = ui.createModal(document.getElementById('removeWeightingSchemeModal'));

    function optionMarkup(value, label) {
        return '<option value="' + ui.escapeHtml(value) + '">' + ui.escapeHtml(label) + '</option>';
    }

    function resetSelect(select, label, disabled) {
        select.innerHTML = optionMarkup('', label);
        select.disabled = disabled;
    }

    function selectedLabel(select) {
        return select.value ? (select.options[select.selectedIndex]?.textContent.trim() || '') : '';
    }

    function contextLabel() {
        return [selectedLabel(program), selectedLabel(curriculum), selectedLabel(version), selectedLabel(placement)]
            .filter(Boolean).join(' / ');
    }

    function componentTableState(message, isError) {
        rows.innerHTML = '<tr><td colspan="' + columnCount + '" class="empty-cell' +
            (isError ? ' error-cell' : '') + '">' + ui.escapeHtml(message) + '</td></tr>';
    }

    async function loadPrograms() {
        resetSelect(program, 'Loading programs...', true);
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'program_options', page: 1, per_page: 50
            });
            program.innerHTML = optionMarkup('', 'Select an academic program') +
                payload.programs.map(function (item) {
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
        resetSelect(placement, 'Select a version first', true);
        clearSchemeState('Select a curriculum version and subject to configure grade weighting.');
        if (!program.value) return;
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'curriculum_options', academic_program_id: program.value,
                page: 1, per_page: 50
            });
            curriculum.innerHTML = optionMarkup('', 'Select a curriculum') +
                payload.curricula.map(function (item) {
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
        resetSelect(placement, 'Select a version first', true);
        clearSchemeState('Select a curriculum version and subject to configure grade weighting.');
        if (!curriculum.value) return;
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'version_options', curriculum_id: curriculum.value,
                page: 1, per_page: 50
            });
            version.innerHTML = optionMarkup('', 'Select a curriculum version') +
                payload.versions.map(function (item) {
                    const year = item.effective_academic_year ? ' / ' + item.effective_academic_year : '';
                    return optionMarkup(item.id, item.version_label + year);
                }).join('');
            version.disabled = payload.versions.length === 0;
        } catch (error) {
            resetSelect(version, 'Versions unavailable', true);
            ui.showToast(error.message, 'error');
        }
    }

    async function loadPlacements() {
        resetSelect(placement, version.value ? 'Loading mapped subjects...' : 'Select a version first', true);
        clearSchemeState('Select a curriculum version and subject to configure grade weighting.');
        if (!version.value) return;
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'placement_options', curriculum_version_id: version.value,
                page: 1, per_page: 50
            });
            placement.innerHTML = optionMarkup('', 'Select a mapped subject') +
                payload.placements.map(function (item) {
                    const storedContext = [item.year_level, item.semester].filter(Boolean).join(' / ');
                    return optionMarkup(item.id, item.subject_code + ' - ' + item.subject_title +
                        (storedContext ? ' (' + storedContext + ')' : ''));
                }).join('');
            placement.disabled = payload.placements.length === 0;
        } catch (error) {
            resetSelect(placement, 'Mapped subjects unavailable', true);
            ui.showToast(error.message, 'error');
        }
    }

    function clearSchemeState(message) {
        state.placement = null;
        state.scheme = null;
        state.components.clear();
        document.getElementById('weightingContextMessage').textContent = message;
        document.getElementById('weightingSchemeEmpty').hidden = false;
        document.getElementById('weightingSchemeDetails').hidden = true;
        if (createSchemeButton) createSchemeButton.disabled = !placement.value;
        if (editSchemeButton) editSchemeButton.hidden = true;
        if (removeSchemeButton) removeSchemeButton.hidden = true;
        if (addComponentButton) addComponentButton.disabled = true;
        search.disabled = true;
        perPage.disabled = true;
        pagination.innerHTML = '';
        componentTableState('Select a configured subject placement to view components.', false);
    }

    function renderScheme() {
        const scheme = state.scheme;
        document.getElementById('weightingContextMessage').textContent = contextLabel();
        document.getElementById('weightingSchemeEmpty').hidden = Boolean(scheme);
        document.getElementById('weightingSchemeDetails').hidden = !scheme;
        if (createSchemeButton) createSchemeButton.disabled = !placement.value || Boolean(scheme);
        if (editSchemeButton) editSchemeButton.hidden = !scheme;
        if (removeSchemeButton) {
            removeSchemeButton.hidden = !scheme;
            removeSchemeButton.disabled = Boolean(scheme && Number(scheme.component_count) > 0);
            removeSchemeButton.title = scheme && Number(scheme.component_count) > 0
                ? 'Remove all components before removing this scheme.' : '';
        }
        if (addComponentButton) addComponentButton.disabled = !scheme;
        search.disabled = !scheme;
        perPage.disabled = !scheme;
        if (!scheme) {
            componentTableState('No grade weighting scheme is configured for this subject placement.', false);
            pagination.innerHTML = '';
            return;
        }
        document.getElementById('weightingSchemeDescription').textContent =
            scheme.description || 'No description provided.';
        document.getElementById('weightingComponentTotal').textContent = scheme.component_count;
        document.getElementById('weightingCurrentTotal').textContent = scheme.total_weight + '%';
        document.getElementById('weightingRemainingTotal').textContent = scheme.remaining_weight + '%';
        const status = document.getElementById('weightingCompletionStatus');
        const complete = scheme.completion_status === 'complete';
        status.className = 'weighting-status-badge ' + (complete ? 'is-complete' : 'is-incomplete');
        status.innerHTML = '<i class="fa-solid ' + (complete ? 'fa-circle-check' : 'fa-circle-half-stroke') +
            '"></i> ' + (complete ? 'Complete' : 'Incomplete');
    }

    async function loadScheme() {
        const placementId = Number(placement.value);
        const requestId = ++state.contextRequest;
        clearSchemeState(placementId ? 'Loading grade weighting configuration...' :
            'Select a curriculum version and subject to configure grade weighting.');
        if (!placementId) return;
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'scheme_get', curriculum_version_subject_id: placementId
            });
            if (requestId !== state.contextRequest) return;
            state.placement = payload.placement;
            state.scheme = payload.scheme;
            renderScheme();
            if (state.scheme) loadComponents(1);
        } catch (error) {
            if (requestId !== state.contextRequest) return;
            clearSchemeState(error.message);
            ui.showToast(error.message, 'error');
        }
    }

    function componentRow(component) {
        const actions = canManage
            ? '<td><div class="weighting-component-actions">' +
                '<button type="button" class="row-action-button" data-edit-weighting-component="' +
                Number(component.id) + '"><i class="fa-solid fa-pen"></i> Edit</button>' +
                '<button type="button" class="remove-action-button" data-remove-weighting-component="' +
                Number(component.id) + '"><i class="fa-solid fa-trash"></i> Remove</button>' +
                '</div></td>' : '';
        return '<tr><td><span class="weighting-component-name">' +
            ui.escapeHtml(component.component_name) + '</span></td>' +
            '<td><span class="weighting-component-value">' +
            ui.escapeHtml(component.weight_percentage) + '%</span></td>' +
            '<td>' + ui.escapeHtml(component.sequence_order ?? '-') + '</td>' +
            '<td>' + ui.escapeHtml(ui.formatDate(component.updated_at)) + '</td>' + actions + '</tr>';
    }

    async function loadComponents(page) {
        if (!state.scheme) {
            renderScheme();
            return;
        }
        const requestId = ++state.componentRequest;
        state.componentPage = Number(page) || 1;
        componentTableState('Loading weighting components...', false);
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'component_list', scheme_id: state.scheme.id,
                page: state.componentPage, per_page: perPage.value,
                search: search.value.trim()
            });
            if (requestId !== state.componentRequest) return;
            state.scheme = payload.scheme;
            state.componentPage = Number(payload.pagination.page);
            state.components.clear();
            payload.components.forEach(function (component) {
                state.components.set(Number(component.id), component);
            });
            renderScheme();
            if (!payload.components.length) {
                componentTableState(search.value.trim()
                    ? 'No weighting components match this search.'
                    : 'No weighting components have been added.', false);
            } else {
                rows.innerHTML = payload.components.map(componentRow).join('');
            }
            ui.renderPagination(pagination, payload.pagination, loadComponents);
        } catch (error) {
            if (requestId !== state.componentRequest) return;
            componentTableState(error.message, true);
            ui.showToast(error.message, 'error');
        }
    }

    function openSchemeForm(editing) {
        if (!canManage || !schemeForm || !schemeModal || !placement.value) return;
        schemeForm.reset();
        ui.clearFormError(schemeForm);
        const isEdit = Boolean(editing && state.scheme);
        document.getElementById('weightingSchemeId').value = isEdit ? state.scheme.id : '';
        document.getElementById('weightingSchemePlacementId').value = placement.value;
        document.getElementById('weightingSchemeFormDescription').value =
            isEdit ? (state.scheme.description || '') : '';
        document.getElementById('weightingSchemeModalTitle').textContent =
            isEdit ? 'Edit Weighting Scheme' : 'Create Weighting Scheme';
        document.getElementById('weightingSchemeModalContext').textContent = contextLabel();
        document.getElementById('saveWeightingScheme').textContent =
            isEdit ? 'Save Description' : 'Create Scheme';
        schemeModal.open();
        document.getElementById('weightingSchemeFormDescription').focus();
    }

    function openComponentForm(component) {
        if (!canManage || !componentForm || !componentModal || !state.scheme) return;
        componentForm.reset();
        ui.clearFormError(componentForm);
        const isEdit = Boolean(component);
        document.getElementById('weightingComponentId').value = isEdit ? component.id : '';
        document.getElementById('weightingComponentSchemeId').value = state.scheme.id;
        document.getElementById('weightingComponentName').value = isEdit ? component.component_name : '';
        document.getElementById('weightingComponentWeight').value = isEdit ? component.weight_percentage : '';
        document.getElementById('weightingComponentSequence').value =
            isEdit && component.sequence_order !== null ? component.sequence_order : '';
        document.getElementById('weightingComponentModalTitle').textContent =
            isEdit ? 'Edit Weighting Component' : 'Add Weighting Component';
        document.getElementById('weightingComponentModalContext').textContent =
            state.scheme.subject_code + ' - ' + state.scheme.subject_title;
        document.getElementById('saveWeightingComponent').textContent =
            isEdit ? 'Save Component' : 'Add Component';
        updateProjectedTotal();
        componentModal.open();
        document.getElementById('weightingComponentName').focus();
    }

    function updateProjectedTotal() {
        if (!state.scheme || !componentForm) return;
        const editingId = Number(document.getElementById('weightingComponentId').value || 0);
        const existing = editingId ? state.components.get(editingId) : null;
        const current = Number(state.scheme.total_weight || 0);
        const proposed = Number(document.getElementById('weightingComponentWeight').value || 0);
        const projected = current - Number(existing?.weight_percentage || 0) + proposed;
        const help = document.getElementById('weightingProjectedTotal');
        help.textContent = 'Current total: ' + current.toFixed(2) + '%. Projected total: ' +
            Math.max(0, projected).toFixed(2) + '%.';
        help.classList.toggle('form-help-error', projected > 100);
    }

    async function submitScheme(event) {
        event.preventDefault();
        ui.clearFormError(schemeForm);
        const data = new FormData(schemeForm);
        const isEdit = Boolean(data.get('id'));
        data.set('action', isEdit ? 'scheme_update' : 'scheme_create');
        ui.setFormSubmitting(schemeForm, true, isEdit ? 'Saving...' : 'Creating...');
        try {
            const payload = await ui.postForm(endpoint, data);
            state.scheme = payload.scheme;
            schemeModal.close();
            ui.showToast(payload.message, isEdit ? 'updated' : 'success');
            renderScheme();
            await loadComponents(1);
        } catch (error) {
            ui.showFormError(schemeForm, error.message);
        } finally {
            ui.setFormSubmitting(schemeForm, false);
        }
    }

    async function submitComponent(event) {
        event.preventDefault();
        if (!componentForm.reportValidity()) return;
        ui.clearFormError(componentForm);
        const data = new FormData(componentForm);
        const isEdit = Boolean(data.get('id'));
        data.set('action', isEdit ? 'component_update' : 'component_create');
        ui.setFormSubmitting(componentForm, true, isEdit ? 'Saving...' : 'Adding...');
        try {
            const payload = await ui.postForm(endpoint, data);
            state.scheme = payload.scheme;
            componentModal.close();
            ui.showToast(payload.message, isEdit ? 'updated' : 'success');
            await loadComponents(state.componentPage);
        } catch (error) {
            ui.showFormError(componentForm, error.message);
        } finally {
            ui.setFormSubmitting(componentForm, false);
        }
    }

    function requestComponentRemoval(component) {
        if (!canManage || !component || !removeComponentModal) return;
        state.removeComponent = component;
        document.getElementById('removeWeightingComponentMessage').textContent =
            'Remove "' + component.component_name + '" (' + component.weight_percentage + '%) from this scheme?';
        removeComponentModal.open();
    }

    async function confirmComponentRemoval() {
        if (!state.removeComponent) return;
        const button = document.getElementById('confirmRemoveWeightingComponent');
        button.disabled = true;
        const data = new FormData();
        data.set('action', 'component_remove');
        data.set('id', state.removeComponent.id);
        try {
            const payload = await ui.postForm(endpoint, data);
            state.scheme = payload.scheme;
            state.removeComponent = null;
            removeComponentModal.close();
            ui.showToast(payload.message, 'success');
            await loadComponents(state.componentPage);
        } catch (error) {
            ui.showToast(error.message, 'error');
        } finally {
            button.disabled = false;
        }
    }

    async function confirmSchemeRemoval() {
        if (!state.scheme) return;
        const button = document.getElementById('confirmRemoveWeightingScheme');
        button.disabled = true;
        const data = new FormData();
        data.set('action', 'scheme_remove');
        data.set('id', state.scheme.id);
        try {
            const payload = await ui.postForm(endpoint, data);
            removeSchemeModal.close();
            ui.showToast(payload.message, 'success');
            await loadScheme();
        } catch (error) {
            ui.showToast(error.message, 'error');
        } finally {
            button.disabled = false;
        }
    }

    program.addEventListener('change', loadCurricula);
    curriculum.addEventListener('change', loadVersions);
    version.addEventListener('change', loadPlacements);
    placement.addEventListener('change', loadScheme);
    document.getElementById('clearWeightingContext').addEventListener('click', function () {
        program.value = '';
        resetSelect(curriculum, 'Select a program first', true);
        resetSelect(version, 'Select a curriculum first', true);
        resetSelect(placement, 'Select a version first', true);
        clearSchemeState('Select a curriculum version and subject to configure grade weighting.');
    });

    createSchemeButton?.addEventListener('click', function () { openSchemeForm(false); });
    editSchemeButton?.addEventListener('click', function () { openSchemeForm(true); });
    removeSchemeButton?.addEventListener('click', function () {
        if (state.scheme && Number(state.scheme.component_count) === 0) removeSchemeModal.open();
    });
    addComponentButton?.addEventListener('click', function () { openComponentForm(null); });
    schemeForm?.addEventListener('submit', submitScheme);
    componentForm?.addEventListener('submit', submitComponent);
    document.getElementById('weightingComponentWeight')?.addEventListener('input', updateProjectedTotal);
    document.getElementById('confirmRemoveWeightingComponent')?.addEventListener('click', confirmComponentRemoval);
    document.getElementById('confirmRemoveWeightingScheme')?.addEventListener('click', confirmSchemeRemoval);

    rows.addEventListener('click', function (event) {
        const editButton = event.target.closest('[data-edit-weighting-component]');
        if (editButton) {
            const component = state.components.get(Number(editButton.dataset.editWeightingComponent));
            if (component) openComponentForm(component);
            return;
        }
        const removeButton = event.target.closest('[data-remove-weighting-component]');
        if (removeButton) {
            const component = state.components.get(Number(removeButton.dataset.removeWeightingComponent));
            if (component) requestComponentRemoval(component);
        }
    });

    search.addEventListener('input', ui.debounce(function () { loadComponents(1); }, 300));
    perPage.addEventListener('change', function () { loadComponents(1); });

    clearSchemeState('Select a curriculum version and subject to configure grade weighting.');
    loadPrograms();
}());
