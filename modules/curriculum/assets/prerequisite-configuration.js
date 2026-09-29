(function () {
    'use strict';

    const workspace = document.getElementById('prerequisiteWorkspace');
    if (!workspace || !window.CurriculumUI) return;

    const ui = window.CurriculumUI;
    const endpoints = {
        programs: workspace.dataset.programApi,
        curricula: workspace.dataset.curriculumApi,
        versions: workspace.dataset.versionApi,
        requirements: workspace.dataset.requirementApi
    };
    const canManage = workspace.dataset.canManageRequirements === '1';
    const columnCount = canManage ? 9 : 8;
    const state = {
        page: 1,
        request: 0,
        targetRequest: 0,
        requiredRequest: 0,
        placementTotal: 0,
        relationships: new Map(),
        targetOptions: [],
        requiredOptions: [],
        pendingRemoval: null
    };

    const programFilter = document.getElementById('requirementProgramFilter');
    const curriculumFilter = document.getElementById('requirementCurriculumFilter');
    const versionFilter = document.getElementById('requirementVersionFilter');
    const subjectSearch = document.getElementById('requirementSubjectSearch');
    const typeFilter = document.getElementById('requirementTypeFilter');
    const perPage = document.getElementById('requirementPerPage');
    const rows = document.getElementById('requirementRows');
    const pagination = document.getElementById('requirementPagination');
    const addButton = document.getElementById('addRequirementButton');
    const addForm = document.getElementById('addRequirementForm');
    const targetSelect = document.getElementById('targetSubjectId');
    const requiredSelect = document.getElementById('requiredSubjectId');
    const removeElement = document.getElementById('removeRequirementModal');
    const addModal = ui.createModal(document.getElementById('addRequirementModal'));
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

    function contextParts() {
        return [selectedText(programFilter), selectedText(curriculumFilter), selectedText(versionFilter)]
            .filter(Boolean);
    }

    function updateContextState() {
        const hasVersion = Number(versionFilter.value) > 0;
        if (addButton) addButton.disabled = !hasVersion || state.placementTotal < 2;

        const parts = contextParts();
        let message = parts.length
            ? 'Current context: ' + parts.join(' → ')
            : 'Showing all configured subject requirements.';
        if (hasVersion && state.placementTotal < 2) {
            message += ' At least two subjects must be mapped to this curriculum version before a relationship can be configured.';
        }
        document.getElementById('requirementContextMessage').textContent = message;

        const help = document.getElementById('requirementHelpText');
        if (hasVersion && state.placementTotal < 2) {
            help.textContent = 'At least two subjects must be mapped to this curriculum version before a relationship can be configured.';
            help.classList.add('requirement-unavailable-note');
        } else {
            help.textContent = hasVersion
                ? 'Prerequisite and corequisite records configure curriculum relationships only; they do not enforce enrollment eligibility.'
                : 'Select a curriculum version before adding a relationship. These records do not enforce enrollment eligibility.';
            help.classList.remove('requirement-unavailable-note');
        }
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
                    return optionMarkup(program.id, program.program_code + ' — ' + program.program_name, null);
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
        state.placementTotal = 0;
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
        state.placementTotal = 0;
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

    function placementContext(yearLevel, semester) {
        const year = yearLevel || 'Not specified';
        const term = semester || 'Not specified';
        return '<span class="requirement-placement-context"><strong>' + ui.escapeHtml(year) +
            '</strong><span>Semester / Term: ' + ui.escapeHtml(term) + '</span></span>';
    }

    function subjectCell(code, title) {
        return '<span class="requirement-subject"><span class="record-code">' + ui.escapeHtml(code) +
            '</span><span class="record-name">' + ui.escapeHtml(title) + '</span></span>';
    }

    function relationshipRow(relationship) {
        const isPrerequisite = relationship.requirement_type === 'prerequisite';
        const actions = canManage
            ? '<td><div class="requirement-action-group"><button type="button" class="remove-action-button" data-remove-requirement="' +
                Number(relationship.id) + '"><i class="fa-solid fa-link-slash"></i> Remove</button></div></td>'
            : '';
        return '<tr>' +
            '<td>' + subjectCell(relationship.subject_code, relationship.subject_title) + '</td>' +
            '<td><span class="requirement-badge ' +
                (isPrerequisite ? 'requirement-prerequisite' : 'requirement-corequisite') + '">' +
                (isPrerequisite ? 'Prerequisite' : 'Corequisite') + '</span></td>' +
            '<td>' + subjectCell(relationship.required_subject_code, relationship.required_subject_title) + '</td>' +
            '<td>' + placementContext(relationship.subject_year_level, relationship.subject_semester) + '</td>' +
            '<td>' + placementContext(relationship.required_year_level, relationship.required_semester) + '</td>' +
            '<td><span class="mapping-context-stack"><strong>' + ui.escapeHtml(relationship.program_name) +
                '</strong><span>' + ui.escapeHtml(relationship.program_code) + '</span></span></td>' +
            '<td><span class="mapping-context-stack"><strong>' + ui.escapeHtml(relationship.curriculum_name) +
                '</strong><span>' + ui.escapeHtml(relationship.curriculum_code) + '</span></span></td>' +
            '<td><span class="record-code">' + ui.escapeHtml(relationship.version_label) + '</span></td>' +
            actions + '</tr>';
    }

    async function loadRelationships(page) {
        const sequence = ++state.request;
        state.page = Number(page) || 1;
        tableState('Loading subject requirements…', false);
        try {
            const payload = await ui.fetchJson(endpoints.requirements, {
                action: 'list',
                page: state.page,
                per_page: perPage.value,
                search: subjectSearch.value.trim(),
                academic_program_id: programFilter.value,
                curriculum_id: curriculumFilter.value,
                curriculum_version_id: versionFilter.value,
                requirement_type: typeFilter.value
            });
            if (sequence !== state.request) return;
            state.page = Number(payload.pagination.page);
            state.relationships.clear();
            payload.relationships.forEach(function (relationship) {
                state.relationships.set(Number(relationship.id), relationship);
            });
            ui.setCount(document.getElementById('requirementCount'), Number(payload.pagination.total_records));
            if (!payload.relationships.length) {
                let message = 'No prerequisite or corequisite relationships found.';
                if (versionFilter.value) {
                    message = 'This curriculum version has no configured subject requirements matching the selected filters.';
                } else if (subjectSearch.value.trim()) {
                    message = 'No target subjects match the selected filters.';
                }
                tableState(message, false);
            } else {
                rows.innerHTML = payload.relationships.map(relationshipRow).join('');
            }
            ui.renderPagination(pagination, payload.pagination, loadRelationships);
            updateContextState();
        } catch (error) {
            if (sequence !== state.request) return;
            state.relationships.clear();
            ui.setCount(document.getElementById('requirementCount'), 0);
            pagination.innerHTML = '';
            tableState(error.message || 'Subject requirements could not be loaded.', true);
        }
    }

    function placementLabel(placement) {
        const context = [placement.year_level, placement.semester].filter(Boolean).join(' / ');
        return placement.subject_code + ' — ' + placement.subject_title + (context ? ' • ' + context : '');
    }

    function renderPlacementSelect(select, options, selectedValue, excludedId) {
        if (!select) return;
        const available = options.filter(function (placement) {
            return Number(placement.subject_id) !== Number(excludedId || 0);
        });
        select.innerHTML = '<option value="">Select a mapped subject</option>' +
            available.map(function (placement) {
                return optionMarkup(placement.subject_id, placementLabel(placement), selectedValue);
            }).join('');
        select.disabled = available.length === 0;
        if (selectedValue && !available.some(function (placement) {
            return Number(placement.subject_id) === Number(selectedValue);
        })) {
            select.value = '';
        }
    }

    async function fetchPlacementOptions(searchValue) {
        return ui.fetchJson(endpoints.requirements, {
            action: 'placement_options',
            curriculum_version_id: versionFilter.value,
            page: 1,
            per_page: 50,
            search: String(searchValue || '').trim()
        });
    }

    async function refreshPlacementAvailability() {
        state.placementTotal = 0;
        if (!versionFilter.value) {
            updateContextState();
            return;
        }
        try {
            const payload = await fetchPlacementOptions('');
            state.placementTotal = Number(payload.pagination.total_records);
        } catch (error) {
            ui.showToast(error.message || 'Mapped subjects could not be loaded.', 'error');
        }
        updateContextState();
    }

    async function loadInitialModalOptions() {
        const payload = await fetchPlacementOptions('');
        state.targetOptions = payload.placements;
        state.requiredOptions = payload.placements.slice();
        renderPlacementSelect(targetSelect, state.targetOptions, null, null);
        renderPlacementSelect(requiredSelect, state.requiredOptions, null, null);
    }

    async function loadTargetOptions(searchValue) {
        const sequence = ++state.targetRequest;
        targetSelect.disabled = true;
        try {
            const payload = await fetchPlacementOptions(searchValue);
            if (sequence !== state.targetRequest) return;
            const selected = targetSelect.value;
            state.targetOptions = payload.placements;
            renderPlacementSelect(targetSelect, state.targetOptions, selected, null);
        } catch (error) {
            if (sequence !== state.targetRequest) return;
            ui.showFormError(addForm, error.message || 'Target subjects could not be loaded.');
        }
    }

    async function loadRequiredOptions(searchValue) {
        const sequence = ++state.requiredRequest;
        requiredSelect.disabled = true;
        try {
            const payload = await fetchPlacementOptions(searchValue);
            if (sequence !== state.requiredRequest) return;
            const selected = requiredSelect.value;
            state.requiredOptions = payload.placements;
            renderPlacementSelect(requiredSelect, state.requiredOptions, selected, targetSelect.value);
        } catch (error) {
            if (sequence !== state.requiredRequest) return;
            ui.showFormError(addForm, error.message || 'Required subjects could not be loaded.');
        }
    }

    async function openAddRequirement() {
        if (!canManage || !versionFilter.value || state.placementTotal < 2 || !addForm || !addModal) return;
        addForm.reset();
        ui.clearFormError(addForm);
        document.getElementById('requirementVersionId').value = versionFilter.value;
        document.getElementById('addRequirementContext').textContent = contextParts().join(' → ');
        addModal.open();
        targetSelect.innerHTML = '<option value="">Loading mapped subjects…</option>';
        requiredSelect.innerHTML = '<option value="">Loading mapped subjects…</option>';
        try {
            await loadInitialModalOptions();
        } catch (error) {
            ui.showFormError(addForm, error.message || 'Mapped subjects could not be loaded.');
        }
        document.getElementById('targetSubjectSearch').focus();
    }

    if (addForm) {
        addForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!addForm.reportValidity()) return;
            ui.clearFormError(addForm);
            const formData = new FormData(addForm);
            formData.set('action', 'create');
            ui.setFormSubmitting(addForm, true, 'Adding…');
            try {
                const payload = await ui.postForm(endpoints.requirements, formData);
                addModal.close();
                addForm.reset();
                ui.showToast(payload.message, 'success');
                await loadRelationships(1);
            } catch (error) {
                ui.showFormError(addForm, error.message || 'The subject requirement could not be added.');
            } finally {
                ui.setFormSubmitting(addForm, false);
            }
        });
    }

    document.getElementById('confirmRemoveRequirement')?.addEventListener('click', async function () {
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
            const payload = await ui.postForm(endpoints.requirements, formData);
            removeModal.close();
            state.pendingRemoval = null;
            ui.showToast(payload.message, 'updated');
            await loadRelationships(state.page);
        } catch (error) {
            ui.showFormError(removeElement, error.message || 'The subject requirement could not be removed.');
        } finally {
            button.disabled = false;
            button.innerHTML = originalLabel;
        }
    });

    programFilter.addEventListener('change', async function () {
        await loadCurricula();
        await refreshPlacementAvailability();
        await loadRelationships(1);
    });
    curriculumFilter.addEventListener('change', async function () {
        await loadVersions();
        await refreshPlacementAvailability();
        await loadRelationships(1);
    });
    versionFilter.addEventListener('change', async function () {
        await refreshPlacementAvailability();
        await loadRelationships(1);
    });
    typeFilter.addEventListener('change', function () { loadRelationships(1); });
    perPage.addEventListener('change', function () { loadRelationships(1); });
    subjectSearch.addEventListener('input', ui.debounce(function () { loadRelationships(1); }, 300));

    document.getElementById('clearRequirementFilters').addEventListener('click', async function () {
        programFilter.value = '';
        subjectSearch.value = '';
        typeFilter.value = '';
        await loadCurricula();
        await refreshPlacementAvailability();
        await loadRelationships(1);
    });

    addButton?.addEventListener('click', openAddRequirement);
    document.getElementById('targetSubjectSearch')?.addEventListener(
        'input',
        ui.debounce(function () {
            ui.clearFormError(addForm);
            loadTargetOptions(this.value);
        }, 300)
    );
    document.getElementById('requiredSubjectSearch')?.addEventListener(
        'input',
        ui.debounce(function () {
            ui.clearFormError(addForm);
            loadRequiredOptions(this.value);
        }, 300)
    );
    targetSelect?.addEventListener('change', function () {
        renderPlacementSelect(requiredSelect, state.requiredOptions, requiredSelect.value, targetSelect.value);
    });

    rows.addEventListener('click', function (event) {
        const removeButton = event.target.closest('[data-remove-requirement]');
        if (!removeButton || !canManage || !removeModal) return;
        const relationship = state.relationships.get(Number(removeButton.dataset.removeRequirement));
        if (!relationship) return;
        state.pendingRemoval = { id: relationship.id };
        ui.clearFormError(removeElement);
        const label = relationship.requirement_type === 'prerequisite' ? 'Prerequisite' : 'Corequisite';
        document.getElementById('removeRequirementMessage').textContent =
            'Remove ' + relationship.subject_code + ' — ' + label + ' → ' +
            relationship.required_subject_code + '? Subject Master and curriculum placement records will remain.';
        removeModal.open();
        document.getElementById('confirmRemoveRequirement').focus();
    });

    async function initialize() {
        await loadPrograms();
        await loadCurricula();
        await loadRelationships(1);
    }

    initialize();
}());
