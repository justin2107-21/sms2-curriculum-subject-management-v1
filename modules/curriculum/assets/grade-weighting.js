(function () {
    'use strict';

    const workspace = document.getElementById('gradeWeightingWorkspace');
    const ui = window.CurriculumUI;
    if (!workspace || !ui) return;

    const endpoint = workspace.dataset.weightingApi;
    const canManage = workspace.dataset.canManage === '1';
    const state = {
        placement: null,
        scheme: null,
        components: [],
        baseline: '[]',
        dirty: false,
        contextRequest: 0,
        selectedItems: [null, null, null, null],
        removeComponent: null,
        pendingContextChange: null
    };
    const ids = ['weightingProgram', 'weightingCurriculum', 'weightingVersion', 'weightingPlacement'];
    const searches = ['weightingProgramSearch', 'weightingCurriculumSearch', 'weightingVersionSearch', 'weightingPlacementSearch'];
    const lists = ['weightingProgramOptions', 'weightingCurriculumOptions', 'weightingVersionOptions', 'weightingPlacementOptions'];
    const hidden = ids.map(id => document.getElementById(id));
    const inputs = searches.map(id => document.getElementById(id));
    const enabledPlaceholders = ['Search code or program name', 'Search curriculum code or name', 'Search version label or academic year', 'Search subject code or title'];
    const disabledPlaceholders = ['', 'Select a program first', 'Select a curriculum first', 'Select a curriculum version first'];
    const rows = document.getElementById('weightingComponentRows');
    const tableWrap = document.getElementById('weightingTableWrap');
    const footer = document.getElementById('weightingFooter');
    const addButton = document.getElementById('addWeightingComponent');
    const saveButton = document.getElementById('saveWeightingChanges');
    const form = document.getElementById('weightingComponentForm');
    const componentModal = canManage ? ui.createModal(document.getElementById('weightingComponentModal')) : null;
    const removeModal = canManage ? ui.createModal(document.getElementById('removeWeightingComponentModal')) : null;
    const discardModal = ui.createModal(document.getElementById('discardWeightingModal'));
    const cimfButton = document.getElementById('reviewWeightingWithCimf');
    const totalMessage = document.getElementById('weightingTotalMessage');
    const componentsSection = document.getElementById('weightingComponentsSection');
    const selectionHint = document.getElementById('weightingSelectionHint');

    window.CSMSPageCIMFContext = () => ({academic_program_id:hidden[0].value, curriculum_id:hidden[1].value, curriculum_version_id:hidden[2].value, curriculum_version_subject_id:state.placement?.id || '', components:state.components.map(item => ({component_name:item.component_name,weight_percentage:Number(item.weight_percentage).toFixed(2)}))});

    function cents(value) {
        const number = Number(value);
        return Number.isFinite(number) ? Math.round(number * 100) : 0;
    }

    function totalCents(components) {
        return components.reduce((sum, component) => sum + cents(component.weight_percentage), 0);
    }

    function serializedDraft(components) {
        return JSON.stringify(components.map(item => ({
            id: item.id ? Number(item.id) : null,
            component_name: String(item.component_name || '').trim(),
            weight_percentage: (cents(item.weight_percentage) / 100).toFixed(2),
            sequence_order: Number(item.sequence_order || 0)
        })));
    }

    function markDirty() {
        state.dirty = serializedDraft(state.components) !== state.baseline;
        const indicator = document.getElementById('weightingUnsaved');
        if (indicator) indicator.hidden = !state.dirty;
    }

    function contextLabel() {
        return state.selectedItems.map(function (item, index) {
            if (!item) return '';
            if (index === 0) return item.program_code + ' — ' + item.program_name;
            if (index === 1) return item.curriculum_code;
            if (index === 2) return item.version_label;
            return item.subject_code + ' — ' + item.subject_title;
        }).filter(Boolean).join('  ›  ');
    }

    function restoreContext() {
        const cascade = window.weightingContextCascade;
        if (!cascade) return;
        state.selectedItems.forEach(function (item, index) {
            const selector = cascade.get(index);
            if (selector && item) selector.setSelected(item, false);
        });
        inputs.forEach(function (input, index) {
            const enabled = index === 0 || state.selectedItems.slice(0, index).every(Boolean);
            input.disabled = !enabled;
            input.placeholder = enabled ? enabledPlaceholders[index] : disabledPlaceholders[index];
        });
    }

    function applyContextChange(index, item, action) {
        const cascadeSelector = cascade?.get(index);
        if (action === 'select' && item) cascadeSelector?.setSelected(item, false);
        else cascadeSelector?.clear(false);
        for (let child = index + 1; child < 4; child++) {
            cascade?.get(child)?.clear(false);
            state.selectedItems[child] = null;
        }
        state.selectedItems[index] = action === 'select' ? item : null;
        inputs.forEach(function (input, level) {
            const enabled = level === 0 || state.selectedItems.slice(0, level).every(Boolean);
            input.disabled = !enabled;
            input.placeholder = enabled ? enabledPlaceholders[level] : disabledPlaceholders[level];
        });
        state.dirty = false;
        state.placement = null;
        state.scheme = null;
        state.components = [];
        state.baseline = '[]';
        renderComponents();
        if (index === 3) {
            document.getElementById('weightingContextMessage').textContent = 'Loading stored weighting configuration…';
            loadConfiguration();
        }
    }

    function updateProgress() {
        const total = totalCents(state.components);
        const hundred = 10000;
        const remaining = hundred - total;
        const exact = total === hundred;
        const percent = (total / 100).toFixed(2).replace(/\.00$/, '');
        const totalText = document.getElementById('weightingTotalText');
        const bar = document.getElementById('weightingProgress');
        const track = document.querySelector('.grade-weighting-progress-track');
        const badge = document.getElementById('weightingCompletionStatus');
        totalText.textContent = percent + '% total';
        bar.style.width = Math.max(0, Math.min(100, total / 100)) + '%';
        bar.classList.toggle('is-over-limit', total > hundred);
        if (track) track.setAttribute('aria-valuenow', String(Math.max(0, Math.min(100, total / 100))));
        badge.className = 'status-badge ' + (exact ? 'status-active' : total > hundred ? 'grade-weighting-invalid' : 'status-draft');
        badge.textContent = exact ? 'Valid configuration' : total > hundred ? 'Invalid configuration' : 'Incomplete configuration';
        if (exact) totalMessage.textContent = 'The component weights total exactly 100%.';
        else if (total > hundred) totalMessage.textContent = ((total - hundred) / 100).toFixed(2).replace(/\.00$/, '') + '% over limit. Reduce component weights before saving.';
        else totalMessage.textContent = ((remaining) / 100).toFixed(2).replace(/\.00$/, '') + '% remaining.';
        if (saveButton) saveButton.disabled = !canManage || !state.placement || !exact || !state.dirty;
        cimfButton.disabled = !state.placement || state.components.length === 0;
    }

    function renderComponents() {
        const hasPlacement = Boolean(state.placement);
        const hasRows = state.components.length > 0;
        componentsSection.hidden = !hasPlacement;
        selectionHint.hidden = Boolean(hidden[3].value);
        document.getElementById('weightingContextMessage').textContent = hasPlacement
            ? 'User-configured academic data for ' + state.placement.subject_code + ' in ' + state.placement.version_label + '.'
            : '';
        document.getElementById('weightingDemoNote').hidden = !(state.placement && Number(state.placement.is_demo || 0) === 1);
        document.getElementById('weightingSchemeEmpty').hidden = hasRows;
        document.getElementById('weightingSchemeEmpty').innerHTML = hasPlacement && !hasRows
            ? '<p>No grade weighting has been configured for this subject.</p>'
            : '';
        tableWrap.hidden = !hasRows;
        footer.hidden = !hasPlacement;
        addButton && (addButton.disabled = !hasPlacement);
        rows.innerHTML = hasRows ? state.components.map(function (component, index) {
            return '<tr><td><span class="weighting-component-name">' + ui.escapeHtml(component.component_name) + '</span></td>' +
                '<td><span class="weighting-component-value">' + ui.escapeHtml(Number(component.weight_percentage).toFixed(2).replace(/\.00$/, '')) + '%</span></td>' +
                (canManage ? '<td><div class="weighting-component-actions"><button type="button" class="row-action-button" data-edit-component="' + index + '">Edit</button><button type="button" class="remove-action-button" data-remove-component="' + index + '">Remove</button></div></td>' : '') +
                '</tr>';
        }).join('') : '';
        markDirty();
        updateProgress();
    }

    async function loadConfiguration() {
        const placementId = Number(hidden[3].value || 0);
        const request = ++state.contextRequest;
        state.placement = null;
        state.scheme = null;
        state.components = [];
        state.baseline = '[]';
        state.dirty = false;
        if (!placementId) { renderComponents(); return; }
        renderComponents();
        document.getElementById('weightingContextMessage').textContent = 'Loading stored weighting configuration…';
        try {
            const payload = await ui.fetchJson(endpoint, { action: 'scheme_get', curriculum_version_subject_id: placementId });
            if (request !== state.contextRequest) return;
            state.placement = payload.placement;
            state.scheme = payload.scheme;
            if (state.scheme) {
                const result = await ui.fetchJson(endpoint, { action: 'component_list', scheme_id: state.scheme.id, page: 1, per_page: 50 });
                if (request !== state.contextRequest) return;
                state.components = result.components.map(item => ({ ...item, weight_percentage: Number(item.weight_percentage) }));
            }
            state.baseline = serializedDraft(state.components);
            renderComponents();
        } catch (error) {
            if (request !== state.contextRequest) return;
            state.placement = null;
            state.components = [];
            renderComponents();
            ui.showToast(error.message, 'error');
        }
    }

    function updateModalAvailability() {
        const editing = Number(document.getElementById('weightingComponentIndex').value);
        const otherTotal = state.components.reduce((sum, item, index) => sum + (index === editing ? 0 : cents(item.weight_percentage)), 0);
        const entered = cents(document.getElementById('weightingComponentWeight').value);
        const projected = otherTotal + entered;
        const help = document.getElementById('weightingProjectedTotal');
        const currentLabel = editing >= 0 ? 'Other components total' : 'Current Total';
        help.textContent = currentLabel + ': ' + (otherTotal / 100).toFixed(2).replace(/\.00$/, '') + '% · Available Weight ' +
            (Math.max(0, 10000 - otherTotal) / 100).toFixed(2).replace(/\.00$/, '') + '%';
        help.classList.toggle('form-help-error', projected > 10000);
        document.getElementById('saveWeightingComponent').disabled = !document.getElementById('weightingComponentName').value.trim() || entered <= 0 || projected > 10000;
    }

    function openComponentForm(index) {
        if (!canManage || !state.placement || !componentModal) return;
        form.reset(); ui.clearFormError(form);
        const isEdit = Number.isInteger(index) && index >= 0;
        const component = isEdit ? state.components[index] : null;
        document.getElementById('weightingComponentIndex').value = isEdit ? String(index) : '-1';
        document.getElementById('weightingComponentName').value = component?.component_name || '';
        document.getElementById('weightingComponentWeight').value = component?.weight_percentage ?? '';
        document.getElementById('weightingComponentModalTitle').textContent = isEdit ? 'Edit Grade Component' : 'Add Grade Component';
        document.getElementById('weightingComponentSubject').textContent = state.placement.subject_code + ' — ' + state.placement.subject_title;
        updateModalAvailability(); componentModal.open(); document.getElementById('weightingComponentName').focus();
    }

    function submitComponent(event) {
        event.preventDefault();
        if (!form.reportValidity()) return;
        const index = Number(document.getElementById('weightingComponentIndex').value);
        const name = document.getElementById('weightingComponentName').value.trim();
        const weightText = document.getElementById('weightingComponentWeight').value.trim();
        const weight = Number(weightText);
        const duplicate = state.components.some((item, position) => position !== index && item.component_name.trim().toLocaleLowerCase() === name.toLocaleLowerCase());
        if (!name || name.length > 150) return ui.showFormError(form, 'Enter a component name up to 150 characters.');
        if (duplicate) return ui.showFormError(form, 'A component with this name already exists in this configuration.');
        if (!/^\d+(?:\.\d{1,2})?$/.test(weightText) || !Number.isFinite(weight) || weight <= 0 || weight > 100) return ui.showFormError(form, 'Weight must be greater than 0%, at most 100%, and use no more than two decimal places.');
        const other = state.components.reduce((sum, item, position) => sum + (position === index ? 0 : cents(item.weight_percentage)), 0);
        if (other + cents(weight) > 10000) return ui.showFormError(form, 'This component would exceed the 100% total. Maximum available weight is ' + ((10000 - other) / 100).toFixed(2).replace(/\.00$/, '') + '%.');
        const old = index >= 0 ? state.components[index] : null;
        const item = { id: old?.id || null, component_name: name, weight_percentage: weight.toFixed(2), sequence_order: old?.sequence_order ?? index + 1 };
        if (index >= 0) state.components[index] = item; else state.components.push(item);
        componentModal.close(); renderComponents();
    }

    function requestRemoval(index) {
        const component = state.components[index]; if (!component || !removeModal) return;
        state.removeComponent = index;
        document.getElementById('removeWeightingComponentMessage').textContent = 'Remove "' + component.component_name + '" from ' + state.placement.subject_code + '?';
        removeModal.open();
    }

    function removeComponent() {
        if (state.removeComponent === null) return;
        state.components.splice(state.removeComponent, 1);
        state.removeComponent = null; removeModal.close(); renderComponents();
    }

    async function saveConfiguration() {
        if (!saveButton || saveButton.disabled || totalCents(state.components) !== 10000) return;
        saveButton.disabled = true;
        const data = new FormData();
        data.set('action', 'configuration_save');
        data.set('curriculum_version_subject_id', hidden[3].value);
        data.set('curriculum_version_id', hidden[2].value);
        data.set('components', JSON.stringify(state.components.map((item, index) => ({
            id: item.id || '', component_name: item.component_name,
            weight_percentage: Number(item.weight_percentage).toFixed(2), sequence_order: index + 1
        }))));
        try {
            const payload = await ui.postForm(endpoint, data);
            state.scheme = payload.scheme;
            state.components = payload.components.map(item => ({ ...item, weight_percentage: Number(item.weight_percentage) }));
            state.baseline = serializedDraft(state.components);
            renderComponents(); ui.showToast(payload.message, 'success');
        } catch (error) {
            ui.showToast(error.message, 'error');
            updateProgress();
        }
    }

    function reviewWithCimf() { if (state.placement && state.components.length) window.CSMSCIMF?.open('review'); }

    const cascade = ui.createCascadingAutocomplete({
        levels: [
            {
                input: inputs[0], valueInput: hidden[0], list: document.getElementById(lists[0]),
                helpText: 'Choose a program or strand.', selectedText: 'Program selected.', loadingText: 'Searching programs…', noResultsText: 'No matching programs found.', placeholder: 'Search code or program name', minimumLength: 0, showSuggestionsOnFocus: true,
                search: async query => (await ui.fetchJson(endpoint, { action: 'program_options', search: query, page: 1, per_page: 20 })).programs,
                getId: item => item.id, format: item => ({ label: item.program_code + ' — ' + item.program_name, code: item.program_code, title: item.program_name, meta: item.program_type || '' })
            },
            {
                input: inputs[1], valueInput: hidden[1], list: document.getElementById(lists[1]),
                helpText: 'Choose a curriculum for the selected program.', selectedText: 'Curriculum selected.', loadingText: 'Searching curricula…', noResultsText: 'No curricula found for this program.', placeholder: 'Search curriculum code or name', disabledPlaceholder: 'Select a program first', minimumLength: 0, showSuggestionsOnEnable: true,
                search: async query => (await ui.fetchJson(endpoint, { action: 'curriculum_options', academic_program_id: hidden[0].value, search: query, page: 1, per_page: 20 })).curricula,
                getId: item => item.id, format: item => ({ label: item.curriculum_code + ' — ' + item.curriculum_name, code: item.curriculum_code, title: item.curriculum_name })
            },
            {
                input: inputs[2], valueInput: hidden[2], list: document.getElementById(lists[2]),
                helpText: 'Choose a version of the selected curriculum.', selectedText: 'Curriculum version selected.', loadingText: 'Searching versions…', noResultsText: 'No curriculum versions found.', placeholder: 'Search version label or academic year', disabledPlaceholder: 'Select a curriculum first', minimumLength: 0, showSuggestionsOnEnable: true,
                search: async query => (await ui.fetchJson(endpoint, { action: 'version_options', curriculum_id: hidden[1].value, search: query, page: 1, per_page: 20 })).versions,
                getId: item => item.id, format: item => ({ label: item.version_label + (item.effective_academic_year ? ' — ' + item.effective_academic_year : ''), code: item.version_label, title: item.effective_academic_year || '' })
            },
            {
                input: inputs[3], valueInput: hidden[3], list: document.getElementById(lists[3]),
                helpText: 'Choose a subject mapped to this curriculum version.', selectedText: 'Mapped subject selected.', loadingText: 'Searching mapped subjects…', noResultsText: 'No mapped subjects found in this version.', placeholder: 'Search subject code or title', disabledPlaceholder: 'Select a curriculum version first', minimumLength: 0, showSuggestionsOnEnable: true,
                search: async query => (await ui.fetchJson(endpoint, { action: 'placement_options', curriculum_version_id: hidden[2].value, search: query, page: 1, per_page: 20 })).placements,
                getId: item => item.id, format: item => ({ label: item.subject_code + ' — ' + item.subject_title, code: item.subject_code, title: item.subject_title, meta: [item.year_level, item.semester].filter(Boolean).join(' · ') })
            }
        ],
        onChange: function (index, item, id, action) {
            if (state.dirty) {
                state.pendingContextChange = { index, item, action };
                restoreContext();
                discardModal.open();
                return;
            }
            applyContextChange(index, item, action);
        }
    });
    window.weightingContextCascade = cascade;

    workspace.addEventListener('click', function (event) {
        const edit = event.target.closest('[data-edit-component]');
        if (edit) { openComponentForm(Number(edit.dataset.editComponent)); return; }
        const remove = event.target.closest('[data-remove-component]');
        if (remove) requestRemoval(Number(remove.dataset.removeComponent));
    });
    form?.addEventListener('submit', submitComponent);
    document.getElementById('weightingComponentWeight')?.addEventListener('input', updateModalAvailability);
    document.getElementById('weightingComponentName')?.addEventListener('input', updateModalAvailability);
    document.getElementById('confirmRemoveWeightingComponent')?.addEventListener('click', removeComponent);
    document.getElementById('confirmDiscardWeighting')?.addEventListener('click', function () {
        if (!state.pendingContextChange) return;
        const pending = state.pendingContextChange;
        state.pendingContextChange = null;
        discardModal.close();
        applyContextChange(pending.index, pending.item, pending.action);
    });
    addButton?.addEventListener('click', function () { openComponentForm(-1); });
    saveButton?.addEventListener('click', saveConfiguration);
    cimfButton?.addEventListener('click', reviewWithCimf);
    renderComponents();
}());
