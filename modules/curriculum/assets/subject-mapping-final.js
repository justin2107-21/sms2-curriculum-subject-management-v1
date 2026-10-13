(function () {
    'use strict';

    const root = document.getElementById('subjectMappingWorkspace');
    if (!root || !window.CurriculumUI) return;
    const ui = window.CurriculumUI;
    const model = window.SubjectMappingData;
    const api = {
        programs: root.dataset.programApi,
        curricula: root.dataset.curriculumApi,
        versions: root.dataset.versionApi,
        subjects: root.dataset.subjectApi,
        requirements: root.dataset.requirementApi,
        mappings: root.dataset.assignmentApi,
        outcomes: root.dataset.outcomeApi,
        clos: root.dataset.cloApi
    };
    const permission = {
        prerequisites: root.dataset.canViewPrerequisites === '1',
        programs: root.dataset.canViewPrograms === '1',
        subjects: root.dataset.canViewSubjects === '1',
        manage: root.dataset.canManageMappings === '1',
        add: root.dataset.canAddMappings === '1',
        manageOutcomes: root.dataset.canManageOutcomes === '1',
        manageClos: root.dataset.canManageClos === '1',
        managePlans: root.dataset.canManagePlans === '1'
    };
    const state = {
        program: null, curriculum: null, version: null, loaded: false, year: '', requirements: [], relationshipRequest: null, relationshipsLoaded: false, requirementError: '', detailRequest: 0,
        mappings: new Map(), allMappings: [], pendingRemoval: null,
        selectedMapping: null, currentTab: 'map', mapRequest: 0,
        reviewLoaded: false, yearValues: [], semesterValues: [], outcomeData: null, outcomeRequest: 0
    };
    window.CSMSPageCIMFContext = () => ({academic_program_id:state.program?.id || '',curriculum_id:state.curriculum?.id || '',curriculum_version_id:state.version?.id || '',subject_id:state.selectedMapping?.subject_id || '',year_level:state.year || ''});
    const el = id => document.getElementById(id);
    const programInput = el('mappingProgramInput');
    const curriculumInput = el('mappingCurriculumInput');
    const versionInput = el('mappingVersionInput');
    const programIdInput = el('mappingProgramFilter');
    const curriculumIdInput = el('mappingCurriculumFilter');
    const versionIdInput = el('mappingVersionFilter');
    const mapTarget = el('mappingMap');
    const connectionModal = ui.createModal(el('mappingConnectionModal'));
    const detailsModal = ui.createModal(el('mappingDetailsModal'));
    const addModal = ui.createModal(el('addMappingModal'));
    const editModal = ui.createModal(el('editMappingModal'));
    const removeModal = ui.createModal(el('removeMappingModal'));
    const addForm = el('addMappingForm');
    const editForm = el('editMappingForm');
    const addSubjectInput = el('mappingSubjectSelectorSearch');
    const editYear = el('editMappingYear');
    const editSemester = el('editMappingSemester');
    let curriculumAutocomplete;
    let versionAutocomplete;
    let traceRequest = 0;
    let contextReset = false;
    let selectedTraceSubject = null;
    let selectedReviewIssue = null;
    let reviewIssues = null;

    function esc(value) { return ui.escapeHtml(value == null ? '' : String(value)); }
    function arr(value) { return Array.isArray(value) ? value : []; }
    function isBlank(value) { return value == null || String(value).trim() === ''; }
    function sortLabel(a, b) { return String(a || '').localeCompare(String(b || ''), undefined, { numeric: true, sensitivity: 'base' }); }
    function contextLabels() {
        return [
            programAutocomplete?.getSelectedLabel(),
            curriculumAutocomplete?.getSelectedLabel(),
            versionAutocomplete?.getSelectedLabel()
        ].filter(Boolean);
    }
    function contextUrl(page, mapping) {
        return page + '?' + new URLSearchParams({
            program_id: String(mapping?.academic_program_id || state.program?.id || ''),
            curriculum_id: String(mapping?.curriculum_id || state.curriculum?.id || ''),
            version_id: String(mapping?.curriculum_version_id || state.version?.id || ''),
            subject_id: String(mapping?.subject_id || '')
        });
    }
    function isShs() { return /shs|senior\s*high/i.test(state.program?.program_type || ''); }
    function traceScope() {
        return { academic_program_id: programIdInput.value, curriculum_id: curriculumIdInput.value, curriculum_version_id: versionIdInput.value };
    }
    function contextualTrace() { return Object.values(traceScope()).some(Boolean); }
    function traceEmpty() {
        return versionIdInput.value ? 'Select a subject from this curriculum version to view its placement.' : contextualTrace() ? 'Select a subject from the selected context to view its placements.' : 'Select a subject to view its curriculum placements.';
    }
    function resetTrace() {
        traceRequest++;
        selectedTraceSubject = null;
        traceAutocomplete?.clear(false);
        el('mappingTraceHelper').textContent = contextualTrace() ? 'Search subjects within the selected curriculum context.' : 'Search a Subject Master record to view all curriculum placements.';
        showPanelState(el('mappingTraceResults'), permission.subjects ? traceEmpty() : 'Subject Trace requires Subject Master view permission.');
    }
    function yearChoices() { return [...new Set([...(isShs() ? ['Grade 11', 'Grade 12'] : ['1st Year', '2nd Year', '3rd Year', '4th Year']), ...state.allMappings.map(row => row.year_level).filter(value => !isBlank(value))])].sort(sortLabel); }
    function updateContextMessage() {
        const complete = Boolean(state.program && state.curriculum && state.version && versionIdInput.value);
        root.classList.toggle('has-mapping-context', complete);
        const add = el('addMappingButton');
        if (add) { add.hidden = state.currentTab !== 'map'; add.disabled = !permission.add || !complete || !state.loaded; }
        el('mappingReviewScope').textContent = complete ? [state.program.program_code, state.curriculum.curriculum_code, state.version.version_label].join(' / ') : 'Select a version';
        if (el('mappingPrerequisiteLink')) el('mappingPrerequisiteLink').href = contextUrl('prerequisite_configuration.php');
    }

    function setSelectOptions(select, values, placeholder, currentValue) {
        if (!select) return;
        const unique = [...new Set(arr(values).filter(value => !isBlank(value)))];
        if (currentValue && !unique.includes(currentValue)) unique.push(currentValue);
        unique.sort(sortLabel);
        select.replaceChildren(new Option(placeholder, ''));
        unique.forEach(value => select.add(new Option(value, value)));
        if (currentValue && unique.includes(currentValue)) select.value = currentValue;
    }
    function setPlacementOptions(prefix, year = '', semester = '') {
        setSelectOptions(el(prefix + 'Year'), state.yearValues, 'Select year / grade level', year);
        setSelectOptions(el(prefix + 'Semester'), ['1st Semester', '2nd Semester', ...state.semesterValues], 'Select semester', semester);
    }
    function showPanelState(target, message, error) {
        if (!target) return;
        target.innerHTML = '<p class="mapping-state' + (error ? ' is-error' : '') + '">' + esc(message) + '</p>';
    }

    const mappingContextCascade = ui.createCascadingAutocomplete({
        levels: [
            {
                input: programInput, valueInput: programIdInput, list: el('mappingProgramOptions'),
                helpText: 'Search stored academic programs.', selectedText: 'Program selected.',
                noResultsText: 'No matching programs found.', placeholder: 'Search program code or name',
                search: async query => (await ui.fetchJson(api.programs, { action: 'list', page: 1, per_page: 50, search: query, status: '' })).programs,
                getId: item => item.id,
                format: item => ({ label: item.program_code + ' — ' + item.program_name, code: item.program_code, title: item.program_name })
            },
            {
                input: curriculumInput, valueInput: curriculumIdInput, list: el('mappingCurriculumOptions'),
                helpText: 'Select a program first.', selectedText: 'Curriculum selected.',
                noResultsText: 'No matching curricula found.', placeholder: 'Search curriculum code or name',
                disabledPlaceholder: 'Choose program first', minimumLength: 0, showSuggestionsOnEnable: true, showSuggestionsOnFocus: true, focusOnEnable: true,
                search: async query => programIdInput.value ? (await ui.fetchJson(api.curricula, {
                    action: 'list', page: 1, per_page: 50, search: query, status: '', academic_program_id: programIdInput.value
                })).curricula : [],
                getId: item => item.id,
                format: item => ({ label: item.curriculum_code + ' — ' + item.curriculum_name, code: item.curriculum_code, title: item.curriculum_name })
            },
            {
                input: versionInput, valueInput: versionIdInput, list: el('mappingVersionOptions'),
                helpText: 'Select a curriculum first.', selectedText: 'Version selected.',
                noResultsText: 'No matching versions found.', placeholder: 'Search version label',
                disabledPlaceholder: 'Choose curriculum first', minimumLength: 0, showSuggestionsOnEnable: true, showSuggestionsOnFocus: true, focusOnEnable: true,
                search: async query => curriculumIdInput.value ? (await ui.fetchJson(api.versions, {
                    action: 'list', curriculum_id: curriculumIdInput.value, page: 1, per_page: 50, search: query
                })).versions : [],
                getId: item => item.id,
                format: item => ({ label: item.version_label, code: item.version_label, title: item.effective_academic_year || '' })
            }
        ],
        onChange: function (index, item, id, action) {
            if (index === 0) { state.program = action === 'select' ? item : null; state.curriculum = null; state.version = null; }
            if (index === 1) { state.curriculum = action === 'select' ? item : null; state.version = null; }
            if (index === 2) state.version = action === 'select' ? item : null;
            state.year = ''; selectedReviewIssue = null;
            ['mappingSubjectSearch', 'mappingYearFilter', 'mappingSemesterFilter', 'mappingPlacementFilter'].forEach(key => { el(key).value = ''; });
            if (contextReset) return;
            resetTrace();
            if (index === 0) onProgramChange();
            else if (index === 1) onCurriculumChange();
            else { updateContextMessage(); loadMap(); }
        }
    });
    const programAutocomplete = mappingContextCascade?.get(0);
    curriculumAutocomplete = mappingContextCascade?.get(1);
    versionAutocomplete = mappingContextCascade?.get(2);
    const addSubjectAutocomplete = ui.createAutocomplete({
        input: addSubjectInput, valueInput: el('mappingSubjectId'), list: el('mappingSubjectOptions'), status: el('mappingSubjectHint'),
        helpText: 'Select an existing Subject Master record.', selectedText: 'Subject selected.',
        noResultsText: 'Subject not found in Subject Master.',
        search: async query => (await ui.fetchJson(api.mappings, { action: 'placement_subject_options', ...traceScope(), page: 1, per_page: 50, search: query, status: '' })).subjects,
        getId: subject => subject.id,
        format: subject => ({ label: subject.subject_code + ' — ' + subject.subject_title, code: subject.subject_code, title: subject.subject_title })
    });
    const traceOptions = {
        input: el('mappingTraceSearch'), valueInput: el('mappingTraceSubjectId'), list: el('mappingTraceOptions'),
        helpText: 'Search the Subject Master.', selectedText: 'Subject selected.', noResultsText: 'Subject not found in Subject Master.',
        search: async query => {
            traceOptions.noResultsText = contextualTrace() ? 'No matching subject is placed in the selected context.' : 'Subject not found in Subject Master.';
            return (await ui.fetchJson(api.subjects, { action: 'list', ...traceScope(), page: 1, per_page: 50, search: query, status: '' })).subjects;
        },
        getId: item => item.id,
        format: item => ({ label: item.subject_code + ' — ' + item.subject_title, code: item.subject_code, title: item.subject_title }),
        onSelect: item => { selectedTraceSubject = item; loadTrace(item); },
        onClear: () => { selectedTraceSubject = null; traceRequest += 1; showPanelState(el('mappingTraceResults'), traceEmpty()); }
    };
    const traceAutocomplete = ui.createAutocomplete(traceOptions);

    function resetMapContext() {
        const prior = contextReset;
        contextReset = true;
        curriculumAutocomplete?.clear();
        versionAutocomplete?.clear();
        curriculumInput.disabled = !programIdInput.value;
        curriculumInput.placeholder = programIdInput.value ? 'Search curriculum' : 'Choose program first';
        versionInput.disabled = true;
        versionInput.placeholder = 'Choose curriculum first';
        contextReset = prior;
        updateContextMessage();
    }
    function onProgramChange() {
        resetMapContext();
        updateContextMessage();
        loadMap();
    }
    function onCurriculumChange() {
        const prior = contextReset;
        contextReset = true;
        versionAutocomplete?.clear();
        versionInput.disabled = !curriculumIdInput.value;
        versionInput.placeholder = curriculumIdInput.value ? 'Search version' : 'Choose curriculum first';
        contextReset = prior;
        updateContextMessage();
        loadMap();
    }

    async function fetchAll(endpoint, key, parameters) {
        const records = [];
        let page = 1;
        let pages = 1;
        let expected = null;
        do {
            const payload = await ui.fetchJson(endpoint, { ...(parameters || {}), page, per_page: 50 });
            const pagination = payload.pagination || {};
            if (expected === null) expected = Number(pagination.total_records) || 0;
            if (Number(pagination.total_records) !== expected || Number(pagination.page) !== page) {
                throw new Error('Records changed while loading. Please retry the review.');
            }
            records.push(...arr(payload[key]));
            pages = Number(pagination.total_pages) || 1;
            page += 1;
        } while (page <= pages);
        if (records.length !== expected || new Set(records.map(row => Number(row.id))).size !== expected) throw new Error('The complete record set could not be loaded. Please retry.');
        return records;
    }

    async function loadMap() {
        const request = ++state.mapRequest;
        const version = versionIdInput.value;
        if (state.currentTab === 'outcomes') loadOutcomes();
        state.loaded = false; state.reviewLoaded = false; state.allMappings = []; state.requirements = []; state.relationshipRequest = null; state.relationshipsLoaded = false; state.requirementError = '';
        connectionModal?.close(); renderConnections();
        state.mappings.clear(); state.detailRequest++; traceRequest++;
        detailsModal?.close(); addModal?.close(); editModal?.close(); removeModal?.close();
        state.pendingRemoval = null; state.selectedMapping = null;
        updateContextMessage();
        el('mappingCount').textContent = version ? 'Loading...' : 'Select a version';
        el('mappingYearTabs').replaceChildren();
        setSelectOptions(el('mappingYearFilter'), [], isShs() ? 'All Grades' : 'All Years', '');
        ['mappingMap', 'mappingReviewCards', 'mappingReviewResults'].forEach(id => showPanelState(el(id), version ? 'Loading selected curriculum version...' : 'Select a program, curriculum, and version to view placements.'));
        if (selectedTraceSubject) showPanelState(el('mappingTraceResults'), version ? 'Refreshing subject usage...' : 'Select a curriculum version to view subject usage.');
        if (!version) {
            if (selectedTraceSubject) loadTrace(selectedTraceSubject);
            return;
        }
        try {
            const mappings = await fetchAll(api.mappings, 'mappings', { action: 'mapping_list', curriculum_version_id: version });
            if (request !== state.mapRequest) return;
            state.mappings = new Map(mappings.map(item => [Number(item.id), item])); state.allMappings = mappings; state.loaded = true;
            state.yearValues = yearChoices();
            setSelectOptions(el('mappingYearFilter'), state.yearValues, isShs() ? 'All Grades' : 'All Years', state.year);
            state.semesterValues = [...new Set(mappings.map(item => item.semester).filter(value => !isBlank(value)))].sort(sortLabel);
            setSelectOptions(el('mappingSemesterFilter'), state.semesterValues, 'All Semesters', el('mappingSemesterFilter').value);
            updateContextMessage(); renderFilteredMap(); loadReview(true);
            if (selectedTraceSubject) loadTrace(selectedTraceSubject);
            if (permission.prerequisites) {
                try {
                    state.relationshipRequest = fetchAll(api.requirements, 'relationships', { action: 'list', curriculum_version_id: version, requirement_type: 'prerequisite' });
                    const requirements = await state.relationshipRequest;
                    if (request !== state.mapRequest) return;
                    state.requirements = requirements;
                } catch (error) { if (request !== state.mapRequest) return; state.requirementError = error.message; }
            }
            state.relationshipsLoaded = true;
            renderConnections();
        } catch (error) {
            if (request !== state.mapRequest) return;
            state.loaded = false; updateContextMessage();
            ['mappingMap', 'mappingReviewCards', 'mappingReviewResults'].forEach(id => showPanelState(el(id), error.message || 'Selected context could not be loaded.', true));
        }
    }

    async function loadOutcomes() {
        const target = el('outcomeMatrix');
        if (!target) return;
        const versionId = versionIdInput.value;
        const request = ++state.outcomeRequest;
        if (!versionId) { state.outcomeData = null; showPanelState(target, 'Select a BSIT curriculum version to view recorded outcomes.'); return; }
        showPanelState(target, 'Loading source outcomes and recorded mappings...');
        try {
            const data = await ui.fetchJson(api.outcomes, { action: 'workspace', curriculum_version_id: versionId });
            if (request !== state.outcomeRequest) return;
            state.outcomeData = data;
            renderOutcomes();
        } catch (error) {
            if (request === state.outcomeRequest) showPanelState(target, error.message || 'Outcome mappings could not be loaded.', true);
        }
    }

    function renderOutcomes() {
        const data = state.outcomeData, target = el('outcomeMatrix');
        if (!data || !target) return;
        const context = data.context, outcomes = arr(data.outcomes), placements = arr(data.placements), mappings = arr(data.mappings);
        el('outcomeVersionScope').textContent = [context.program_code, context.curriculum_name, context.version_label].join(' / ');
        const placementSelect = el('cloPlacementSelect');
        const previousPlacement = placementSelect.value;
        placementSelect.innerHTML = '<option value="">Select a placement</option>' + placements.map(row => '<option value="' + Number(row.placement_id) + '">' + esc(row.subject_code + ' · ' + row.subject_title + ' · ' + (row.year_level || 'Year not set') + ' / ' + (row.semester || 'Term not set')) + '</option>').join('');
        if (placements.some(row => String(row.placement_id) === previousPlacement)) placementSelect.value = previousPlacement;
        if (placementSelect.value) loadCloWorkspace();
        else showPanelState(el('cloAssessmentContent'), 'Select a curriculum version and subject placement.');
        if (!outcomes.length) { showPanelState(target, 'The source-traceable IT01–IT13 catalog has not been loaded in this environment.'); return; }
        const canEdit = permission.manageOutcomes && Boolean(context.editable);
        const labels = { not_assessed: 'Not assessed', incomplete: 'Incomplete', mapped: 'Mapped', unmapped: 'Unmapped' };
        target.innerHTML = outcomes.map(outcome => {
            const links = mappings.filter(row => Number(row.outcome_id) === Number(outcome.id));
            const options = placements.map(row => '<option value="' + Number(row.placement_id) + '">' + esc(row.subject_code + ' · ' + row.subject_title + ' · ' + (row.year_level || 'Year not set') + ' / ' + (row.semester || 'Term not set')) + '</option>').join('');
            const linkHtml = links.length ? links.map(link => '<li><strong>' + esc(link.subject_code) + ' · ' + esc(link.subject_title) + '</strong><span>' + esc((link.year_level || 'Year not set') + ' / ' + (link.semester || 'Term not set')) + '</span><p>' + esc(link.rationale) + (link.mapping_origin === 'inherited' ? '<small>Inherited from reference mapping #' + Number(link.inherited_from_mapping_id) + '; not independently reassessed.</small>' : '') + '</p>' + (canEdit ? '<button type="button" class="button-muted" data-outcome-remove="' + Number(link.id) + '">Remove link</button>' : '') + '</li>').join('') : '<li class="outcome-no-links">No subject links recorded.</li>';
            const actions = canEdit ? '<form class="outcome-add-form" data-outcome-add="' + Number(outcome.id) + '"><label>Subject placement<select name="placement_id" required><option value="">Choose placement</option>' + options + '</select></label><label>Academic rationale<textarea name="rationale" maxlength="3000" required></textarea></label><button class="button-primary" type="submit">Record mapping</button></form><form class="outcome-assess-form" data-outcome-assess="' + Number(outcome.id) + '"><label>Coverage note<textarea name="rationale" maxlength="3000"></textarea></label><button class="button-muted" name="assessment_state" value="incomplete">Mark incomplete</button><button class="button-muted" name="assessment_state" value="complete">Mark complete</button></form>' : '';
            const citation = outcome.document_code + ' · ' + outcome.document_title + ' · ' + outcome.source_section + ' · printed p. ' + outcome.printed_page + ' (PDF p. ' + outcome.pdf_page_index + ')';
            return '<article class="outcome-card"><header><div><span class="record-code">' + esc(outcome.outcome_code) + '</span><span class="outcome-status outcome-status-' + esc(outcome.coverage_status) + '">' + esc(labels[outcome.coverage_status]) + '</span><p>' + esc(outcome.outcome_text) + '</p><small>' + esc(citation) + '</small>' + (Number(outcome.source_order) === 1 ? '<small>Source PDF SHA-256: ' + esc(outcome.source_sha256) + '</small>' : '') + '</div><strong>' + Number(outcome.mapping_count) + ' recorded subject link' + (Number(outcome.mapping_count) === 1 ? '' : 's') + '</strong></header><ul class="outcome-link-list">' + linkHtml + '</ul>' + actions + '</article>';
        }).join('');
    }

    async function loadCloWorkspace() {
        const placementId = el('cloPlacementSelect')?.value, target = el('cloAssessmentContent');
        if (!target) return;
        if (!placementId) { showPanelState(target, 'Select a subject placement to view its course learning outcomes.'); return; }
        showPanelState(target, 'Loading placement-owned CLO and planned assessment records...');
        try {
            const data = await ui.fetchJson(api.clos, {action:'workspace',placement_id:placementId});
            if (String(el('cloPlacementSelect').value) !== String(placementId)) return;
            const clos = arr(data.clos), alignments = arr(data.alignments), plans = arr(data.plans), parents = arr(data.parent_mappings);
            const editable = Boolean(data.editable), canClo = editable && permission.manageClos, canPlan = editable && permission.managePlans;
            const parentOptions = parents.map(p => '<option value="' + Number(p.mapping_id) + '">' + esc(p.outcome_code + ' · ' + p.outcome_text) + '</option>').join('');
            target.innerHTML = '<p><strong>' + esc(data.placement.subject_code + ' · ' + data.placement.subject_title) + '</strong> — ' + esc(data.placement.version_label) + (editable ? ' · Editable draft' : ' · Read only') + '</p><p class="curriculum-notice">' + esc(data.disclaimer) + '</p>' +
                (canClo ? '<form class="clo-create-form"><input type="hidden" name="placement_id" value="' + Number(placementId) + '"><label>CLO code<input name="clo_code" maxlength="40" required></label><label>Sequence<input name="sequence_order" type="number" min="1" max="65535" required></label><label>Course learning outcome<textarea name="clo_statement" maxlength="12000" required></textarea></label><button class="button-primary">Record CLO</button></form>' : '') +
                (clos.length ? clos.map(clo => {
                    const links = alignments.filter(a => Number(a.clo_id) === Number(clo.id));
                    const ownPlans = plans.filter(p => Number(p.clo_id) === Number(clo.id));
                    const activeClo = clo.record_state === 'active';
                    return '<article class="outcome-card"><header><div><span class="record-code">' + esc(clo.clo_code) + '</span><span class="outcome-status">' + esc(clo.record_state === 'withdrawn' ? 'Withdrawn · history retained' : (clo.review_state === 'awaiting_review' ? 'Awaiting academic review' : (clo.origin === 'inherited' ? 'Inherited · review required' : 'User authored'))) + '</span><p>' + esc(clo.clo_statement) + '</p></div><span>Sequence ' + Number(clo.sequence_order) + '</span>' + (canClo && activeClo ? '<button type="button" class="button-muted" data-clo-action="clo_withdraw" data-id="' + Number(clo.id) + '">Withdraw CLO</button>' : '') + '</header>' +
                        '<h4>Graduate outcome alignments</h4>' + (links.length ? '<ul>' + links.map(a => '<li><strong>' + esc(a.outcome_code) + '</strong> ' + esc(a.outcome_text) + '<p>' + esc(a.rationale) + '</p>' + (a.review_state === 'awaiting_review' ? '<small>Awaiting academic review</small>' : '') + (canClo ? '<button type="button" class="button-muted" data-clo-action="alignment_withdraw" data-id="' + Number(a.id) + '" data-clo="' + Number(clo.id) + '">Withdraw alignment</button>' : '') + '</li>').join('') + '</ul>' : '<p>No graduate outcome alignment recorded.</p>') +
                        (canClo && parentOptions ? '<form class="clo-alignment-form"><input type="hidden" name="clo_id" value="' + Number(clo.id) + '"><label>Existing graduate outcome mapping<select name="parent_mapping_id" required><option value="">Choose mapped outcome</option>' + parentOptions + '</select></label><label>Academic rationale<textarea name="rationale" maxlength="3000" required></textarea></label><button class="button-muted">Align CLO</button></form>' : (canClo ? '<p class="mapping-state">Record a subject-to-graduate-outcome mapping above before aligning a CLO.</p>' : '')) +
                        '<h4>Planned assessment</h4>' + (ownPlans.length ? ownPlans.map(p => '<p><strong>' + esc(p.plan_status === 'planned' ? 'Planned' : 'Incomplete plan') + '</strong> · ' + esc(p.assessment_method || 'Method not recorded') + '<br>' + esc(p.performance_indicator || 'Performance indicator not recorded') + '<br>' + esc(p.planned_task || p.evidence_description || 'Task/evidence not recorded') + (p.review_state === 'awaiting_review' ? '<br>Awaiting academic review' : '') + (canPlan ? ' <button type="button" class="button-muted" data-clo-action="plan_withdraw" data-id="' + Number(p.id) + '" data-clo="' + Number(clo.id) + '">Withdraw plan</button>' : '') + '</p>').join('') : '<p>No assessment plan recorded.</p>') +
                        (canPlan ? '<form class="clo-plan-form"><input type="hidden" name="clo_id" value="' + Number(clo.id) + '"><label>Assessment method<input name="assessment_method" maxlength="180"></label><label>Planned task<textarea name="planned_task" maxlength="12000"></textarea></label><label>Evidence description<textarea name="evidence_description" maxlength="12000"></textarea></label><label>Performance indicator / criterion<textarea name="performance_indicator" maxlength="6000"></textarea></label><label>Rubric / standard (optional)<textarea name="rubric_or_standard" maxlength="12000"></textarea></label><button class="button-muted">Record planned assessment</button></form>' : '') +
                        '</article>';
                }).join('') : '<p class="mapping-state">No course learning outcomes recorded for this placement.</p>');
        } catch (error) { showPanelState(target, error.message || 'CLO workspace could not be loaded.', true); }
    }

    el('cloPlacementSelect')?.addEventListener('change', loadCloWorkspace);
    el('cloAssessmentContent')?.addEventListener('submit', async event => {
        const form = event.target.closest('.clo-create-form,.clo-edit-form,.clo-alignment-form,.clo-alignment-edit-form,.clo-plan-form,.clo-plan-edit-form'); if (!form) return;
        event.preventDefault(); const body = new FormData(form); body.set('placement_id',el('cloPlacementSelect').value);
        body.set('action',form.matches('.clo-create-form')?'clo_create':form.matches('.clo-edit-form')?'clo_update':form.matches('.clo-alignment-form')?'alignment_add':form.matches('.clo-alignment-edit-form')?'alignment_update':form.matches('.clo-plan-edit-form')?'plan_update':'plan_add');
        try { const result=await ui.postForm(api.clos,body); ui.showToast(result.message || 'Academic planning record saved.','success'); await loadCloWorkspace(); }
        catch(error) { ui.showToast(error.message || 'Record could not be saved.','error'); }
    });
    el('cloAssessmentContent')?.addEventListener('click', async event => {
        const button=event.target.closest('[data-clo-action]'); if (!button) return;
        if (!window.confirm('Withdraw this record? Its historical record will be retained.')) return;
        const body=new FormData(); body.set('placement_id',el('cloPlacementSelect').value); body.set('action',button.dataset.cloAction); body.set('clo_id',button.dataset.clo || button.dataset.id); if (button.dataset.cloAction==='alignment_withdraw') body.set('alignment_id',button.dataset.id); if (button.dataset.cloAction==='plan_withdraw') body.set('plan_id',button.dataset.id);
        try { const result=await ui.postForm(api.clos,body); ui.showToast(result.message || 'Record withdrawn.','success'); await loadCloWorkspace(); }
        catch(error) { ui.showToast(error.message || 'Record could not be withdrawn.','error'); }
    });

    el('mappingPanelOutcomes')?.addEventListener('submit', async event => {
        const form = event.target.closest('[data-outcome-add], [data-outcome-assess]');
        if (!form) return;
        event.preventDefault();
        const data = new FormData(form);
        data.set('curriculum_version_id', String(versionIdInput.value));
        if (form.matches('[data-outcome-add]')) {
            data.set('action','add'); data.set('outcome_id',form.dataset.outcomeAdd);
        } else {
            data.set('action','assess'); data.set('outcome_id',form.dataset.outcomeAssess);
            data.set('assessment_state',event.submitter?.value || 'incomplete');
        }
        try { const result=await ui.postForm(api.outcomes,data); ui.showToast(result.message || 'Outcome record saved.','success'); await loadOutcomes(); }
        catch(error) { ui.showToast(error.message || 'Outcome record could not be saved.','error'); }
    });
    el('mappingPanelOutcomes')?.addEventListener('click', async event => {
        const button=event.target.closest('[data-outcome-remove]'); if (!button) return;
        const data=new FormData(); data.set('action','remove'); data.set('curriculum_version_id',String(versionIdInput.value)); data.set('mapping_id',button.dataset.outcomeRemove);
        try { const result=await ui.postForm(api.outcomes,data); ui.showToast(result.message || 'Mapping removed.','success'); await loadOutcomes(); }
        catch(error) { ui.showToast(error.message || 'Mapping could not be removed.','error'); }
    });
    function renderFilteredMap() {
        if (!state.loaded) return;
        el('mappingYearFilter').value = state.year;
        el('mappingYearTabs').innerHTML = ['', ...yearChoices()].map(year => '<button type="button" data-map-year="' + esc(year) + '" aria-pressed="' + (year === state.year) + '" class="' + (year === state.year ? 'is-active' : '') + '">' + esc(year || (isShs() ? 'All Grades' : 'All Years')) + '</button>').join('');
        const query = el('mappingSubjectSearch').value.trim().toLowerCase();
        const semester = el('mappingSemesterFilter').value, placement = el('mappingPlacementFilter').value;
        const rows = ui.searchRecords(state.allMappings,query,row=>[row.subject_code,row.subject_title]).filter(row => (!state.year || row.year_level === state.year) && (!semester || row.semester === semester) && (!placement || String(row.is_required) === placement));
        el('mappingCount').textContent = rows.length + ' placements'; renderMap(rows);
    }

    function renderMap(mappings) {
        const query = el('mappingSubjectSearch').value;
        if (!mappings.length) {
            showPanelState(mapTarget, el('mappingSubjectSearch').value.trim() || state.year || el('mappingSemesterFilter').value || el('mappingPlacementFilter').value
                ? 'No subject placements match these filters.' : 'This curriculum version has no subject placements yet.');
            return;
        }
        const years = new Map();
        mappings.forEach(mapping => {
            const year = isBlank(mapping.year_level) ? 'Missing Year Level' : String(mapping.year_level);
            const semester = isBlank(mapping.semester) ? 'Missing Semester' : String(mapping.semester);
            if (!years.has(year)) years.set(year, new Map([['1st Semester', []], ['2nd Semester', []]]));
            const semesters = years.get(year);
            if (!semesters.has(semester)) semesters.set(semester, []);
            semesters.get(semester).push(mapping);
        });
        mapTarget.innerHTML = [...years.entries()].sort((a, b) => sortLabel(a[0], b[0])).map(([year, semesters]) => {
            const yearMappings = [...semesters.values()].flat();
            const yearUnits = model.unitCents(yearMappings) / 100;
            const semesterPanels = [...semesters.entries()].sort((a, b) => sortLabel(a[0], b[0])).map(([semester, items]) => {
                items = ui.searchRecords(items,query,row=>[row.subject_code,row.subject_title]);
                const units = model.unitCents(items) / 100;
                return '<section class="mapping-semester-panel"><header><div><h3>' + esc(semester) + '</h3><span>' + items.length + ' Subjects \u00b7 ' + ui.formatUnits(units) + ' Units</span></div></header><div class="mapping-subject-list">' +
                    items.map(mappingCard).join('') + '</div></section>';
            }).join('');
            return '<section class="mapping-year-section"><header class="mapping-year-header"><div><h2>' + esc(year) + '</h2><span>' + yearMappings.length + ' Subjects</span></div><span>' + ui.formatUnits(yearUnits) + ' Units</span></header><div class="mapping-semester-grid">' + semesterPanels + '</div></section>';
        }).join('');
    }

    function mappingCard(mapping) {
        const required = Number(mapping.is_required) === 1;
        const label = String(mapping.is_required) === '1' ? 'Required' : String(mapping.is_required) === '0' ? 'Optional' : 'Not set';
        return '<button type="button" class="mapping-subject-card" data-open-mapping="' + Number(mapping.id) + '" aria-label="View placement for ' + esc(mapping.subject_code + ' ' + mapping.subject_title) + '">' +
            '<span class="mapping-card-main"><strong' + (String(mapping.subject_code).length > 9 ? ' class="mapping-code-long"' : '') + '>' + esc(mapping.subject_code).replaceAll('/', '/<wbr>') + '</strong><span>' + esc(mapping.subject_title) + '</span></span>' +
            '<span class="mapping-card-meta"><strong>' + esc(ui.formatUnits(mapping.units)) + ' Units</strong><span class="placement-indicator ' + (required ? 'placement-required' : 'placement-optional') + '">' + label + '</span></span></button>';
    }

    async function openMappingDetails(mapping, fromTrace = false) {
        if (!mapping || !detailsModal) return;
        const request = ++state.detailRequest;
        state.selectedMapping = mapping; state.mappings.set(Number(mapping.id), mapping);
        if (el('mappingDetailsRemove')) el('mappingDetailsRemove').hidden = fromTrace;
        el('mappingDetailsCode').textContent = mapping.subject_code;
        el('mappingDetailsTitle').textContent = mapping.subject_title;
        el('mappingDetailsProgram').textContent = mapping.program_code;
        el('mappingDetailsCurriculum').textContent = mapping.curriculum_code;
        el('mappingDetailsVersion').textContent = mapping.version_label;
        el('mappingDetailsYear').textContent = mapping.year_level || 'Not set';
        el('mappingDetailsSemester').textContent = mapping.semester || 'Not set';
        el('mappingDetailsPlacement').textContent = String(mapping.is_required) === '1' ? 'Required' : String(mapping.is_required) === '0' ? 'Optional' : 'Not set';
        el('mappingDetailsPlacement').className = 'placement-indicator ' + (String(mapping.is_required) === '1' ? 'placement-required' : 'placement-optional');
        el('mappingDetailsUnits').textContent = ui.formatUnits(mapping.units) + ' Units';
        if (el('mappingDetailsSubjectLink')) el('mappingDetailsSubjectLink').href = 'subjects.php?subject_id=' + Number(mapping.subject_id);
        el('mappingDetailsVersionLink').href = contextUrl('curriculum_builder.php', mapping);
        if (el('mappingDetailsPrerequisiteLink')) el('mappingDetailsPrerequisiteLink').href = contextUrl('prerequisite_configuration.php', mapping);
        el('mappingDetailsRequires').textContent = permission.prerequisites ? 'Loading...' : 'Requires prerequisite view permission.';
        detailsModal.open();
        if (!permission.prerequisites) return;
        try {
            const relationships = await (Number(mapping.curriculum_version_id) === Number(state.version?.id) && state.relationshipRequest ? state.relationshipRequest : fetchAll(api.requirements, 'relationships', { action: 'list', curriculum_version_id: mapping.curriculum_version_id, requirement_type: 'prerequisite' }));
            if (request !== state.detailRequest) return;
            const requires = relationships.filter(row => row.requirement_type === 'prerequisite' && Number(row.subject_id) === Number(mapping.subject_id));
            el('mappingDetailsRequires').textContent = requires.length ? requires.map(row => row.required_subject_code + ' — ' + row.required_subject_title).join('; ') : 'No prerequisite rules configured.';
        } catch (error) { if (request === state.detailRequest) el('mappingDetailsRequires').textContent = 'Stored prerequisites could not be loaded.'; }
    }

    function prepareEdit(mapping) {
        if (!mapping || !permission.manage || !editForm || !editModal) return;
        editForm.reset();
        ui.clearFormError(editForm);
        el('editMappingId').value = mapping.id;
        el('editMappingContext').textContent = [mapping.program_code, mapping.curriculum_code, mapping.version_label].join(' / ');
        el('editMappingSubject').textContent = mapping.subject_code + ' — ' + mapping.subject_title;
        el('editMappingVersion').textContent = mapping.curriculum_code + ' — ' + mapping.version_label;
        const editYears = /grade\s/i.test(mapping.year_level || '') ? ['Grade 11', 'Grade 12'] : ['1st Year', '2nd Year', '3rd Year', '4th Year'];
        setSelectOptions(editYear, [...editYears, ...state.yearValues], 'Select year / grade level', mapping.year_level || '');
        setSelectOptions(editSemester, ['1st Semester', '2nd Semester', ...state.semesterValues], 'Select semester', mapping.semester || '');
        el('editMappingSequence').value = mapping.sequence_order == null ? '' : mapping.sequence_order;
        el('editMappingRequired').value = isBlank(mapping.is_required) ? '' : String(mapping.is_required);
        state.detailRequest++;
        detailsModal?.close();
        editModal.open();
        previewPlacement();
        editYear.focus();
    }
    let sequenceRequest = 0, sequenceValid = false;
    async function previewPlacement() {
        const request = ++sequenceRequest, panel = el('mappingSequenceResult');
        sequenceValid = false;
        const save = editForm.querySelector('[type="submit"]');
        save.disabled = true;
        panel.textContent = 'Checking prerequisite sequence...';
        try {
            const data = await ui.fetchJson(api.mappings, { action: 'preview_placement', ...Object.fromEntries(new FormData(editForm)) });
            if (request !== sequenceRequest) return;
            const result = data.validation;
            sequenceValid = result.valid;
            panel.className = result.valid ? 'mapping-fixed-context' : 'form-error';
            panel.innerHTML = result.valid ? (result.relationships.length ? '<strong>Dependency affected</strong><p>The selected placement remains earlier than every dependent subject and later than every prerequisite.</p>' : 'No prerequisite conflicts detected.') : '<strong>PREREQUISITE CONFLICT</strong>' + result.conflicts.map(c => '<p>' + esc(c.message) + '</p><div><strong>' + esc(c.prerequisite_code) + '</strong><p>' + (Number(c.prerequisite_subject_id) === Number(state.mappings.get(Number(el('editMappingId').value))?.subject_id) ? 'Selected: ' : 'Current: ') + esc(c.prerequisite_year + ' · ' + c.prerequisite_semester) + '</p><p>↓ required before</p><strong>' + esc(c.target_code + ' — ' + c.target_title) + '</strong><p>' + (Number(c.target_subject_id) === Number(state.mappings.get(Number(el('editMappingId').value))?.subject_id) ? 'Selected: ' : 'Current: ') + esc(c.target_year + ' · ' + c.target_semester) + '</p><strong>Invalid sequence</strong></div>').join('');
            save.disabled = !sequenceValid;
        } catch (error) { if (request === sequenceRequest) panel.textContent = error.message || 'Sequence cannot be verified.'; }
    }
    editYear?.addEventListener('change', previewPlacement);
    editSemester?.addEventListener('change', previewPlacement);
    function requestRemoval(mapping) {
        if (!mapping || !permission.manage || !removeModal) return;
        state.pendingRemoval = mapping;
        ui.clearFormError(el('removeMappingModal'));
        el('removeMappingMessage').innerHTML = '<p>Are you sure you want to remove:</p><strong>' + esc(mapping.subject_code + ' \u2014 ' + mapping.subject_title) + '</strong><p>from:</p><strong>' + esc(mapping.version_label) + '</strong><p>' + esc((mapping.year_level || 'Not set') + ' \u00b7 ' + (mapping.semester || 'Not set')) + '</p>';
        state.detailRequest++;
        detailsModal?.close();
        removeModal.open();
        el('confirmRemoveMapping').focus();
    }

    async function openAddMapping() {
        if (!permission.add || state.currentTab !== 'map' || !state.loaded || !state.program || !state.curriculum || !state.version || !versionIdInput.value || !addForm || !addModal) return;
        addForm.reset();
        ui.clearFormError(addForm);
        el('addMappingVersionId').value = versionIdInput.value;
        el('addMappingProgramId').value = programIdInput.value;
        el('addMappingCurriculumId').value = curriculumIdInput.value;
        el('addMappingContext').textContent = contextLabels().join(' / ');
        addSubjectAutocomplete?.clear();
        setPlacementOptions('addMapping', state.year);
        addModal.open();
        addSubjectInput.focus();
    }

    async function loadTrace(subject) {
        const request = ++traceRequest, target = el('mappingTraceResults');
        if (!permission.subjects) { showPanelState(target, 'Subject Trace requires Subject Master view permission.'); return; }
        showPanelState(target, 'Loading stored subject usage...');
        try {
            const usage = await fetchAll(api.mappings, 'mappings', { action: 'mapping_list', ...traceScope(), subject_id: subject.id });
            if (request !== traceRequest) return;
            usage.forEach(row => state.mappings.set(Number(row.id), row));
            const versions = new Set(usage.map(row => Number(row.curriculum_version_id))).size;
            const programs = new Set(usage.map(row => Number(row.academic_program_id))).size;
            const cards = usage.map((row, index) => '<article class="mapping-usage-card' + (String(row.curriculum_version_id) === versionIdInput.value ? ' is-current' : '') + '"><header><h4>Usage ' + (index + 1) + '</h4>' + (String(row.curriculum_version_id) === versionIdInput.value ? '<span>Selected context</span>' : '') + '</header><dl>' +
                [['Program', row.program_code], ['Curriculum', row.curriculum_code], ['Version', row.version_label], ['Year / Grade Level', row.year_level || 'Not set'], ['Semester / Term', row.semester || 'Not set'], ['Placement', String(row.is_required) === '1' ? 'Required' : String(row.is_required) === '0' ? 'Optional' : 'Not set'], ['Units', ui.formatUnits(row.units)]].map(([label, value]) => '<div><dt>' + label + '</dt><dd>' + esc(value) + '</dd></div>').join('') + '</dl><button type="button" class="button-muted" data-open-trace-mapping="' + Number(row.id) + '">Open Placement</button></article>').join('');
            if (contextualTrace() && !usage.length) {
                selectedTraceSubject = null; traceAutocomplete.clear(false);
                showPanelState(target, 'No matching subject is placed in the selected context.'); return;
            }
            const heading = versionIdInput.value ? 'Placement in Selected Curriculum Version' : contextualTrace() ? 'Placements in Selected Context' : 'Usage Across All Contexts';
            target.innerHTML = '<article class="mapping-trace-subject"><div><h2>' + esc(subject.subject_code) + '</h2><strong>' + esc(subject.subject_title) + '</strong><p>Used in ' + versions + ' Curriculum ' + (versions === 1 ? 'Version' : 'Versions') + ' \u00b7 ' + programs + ' ' + (programs === 1 ? 'Program' : 'Programs') + '</p></div><span class="mapping-unit-chip">' + esc(ui.formatUnits(subject.units)) + ' Units</span></article><section class="mapping-trace-usage"><header><h3>' + heading + '</h3><span>' + usage.length + ' placements</span></header>' + (cards || '<p class="mapping-state">This subject is not placed in any curriculum version.</p>') + '</section>';
        } catch (error) { if (request === traceRequest) showPanelState(target, error.message || 'Subject usage could not be loaded.', true); }
    }
    const reviewLabels = {
        placement: ['Missing / Invalid Placement', 'Indicator must be Required or Optional'],
        duplicates: ['Duplicate Placements', 'Same subject in the same version, year/grade and semester'],
        missingYear: ['Missing Year / Grade', 'Placements without a year or grade label'],
        missingSemester: ['Missing Semester', 'Placements without a semester label']
    };
    function loadReview() {
        if (!state.loaded) return;
        reviewIssues = model.review(state.allMappings); state.reviewLoaded = true; renderReviewCards();
    }
    function renderReviewCards() {
        el('mappingReviewCards').innerHTML = Object.entries(reviewLabels).map(([key, [label, hint]]) => '<button type="button" class="mapping-review-card' + (selectedReviewIssue === key ? ' is-active' : '') + '" data-review-issue="' + key + '" aria-pressed="' + (selectedReviewIssue === key) + '"><span>' + label + '</span><strong>' + reviewIssues[key].length + '</strong><small>' + hint + '</small></button>').join('');
        if (selectedReviewIssue) { showReviewIssue(selectedReviewIssue); return; }
        const total = Object.values(reviewIssues).reduce((sum, rows) => sum + rows.length, 0);
        el('mappingReviewResults').innerHTML = total ? '<p class="mapping-state">Select an issue card to inspect affected mappings. Checks apply only to the selected version.</p>' : '<div class="mapping-state mapping-review-success"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><div><strong>No structural mapping issues detected.</strong><p>All reviewed placements contain the required mapping information.</p><small>Checks cover stored labels and indicators. Academic correctness or compliance is not evaluated.</small>' + (!state.allMappings.length ? '<p>This version currently has no mapped subjects.</p>' : '') + '</div></div>';
    }
    function showReviewIssue(key) {
        if (!reviewIssues || !reviewLabels[key]) return;
        const items = reviewIssues[key], title = reviewLabels[key][0];
        if (!items.length) { showPanelState(el('mappingReviewResults'), 'No ' + title.toLowerCase() + ' detected in this version.'); return; }
        el('mappingReviewResults').innerHTML = '<section class="mapping-issue-list"><h3>' + title + '<span>' + items.length + ' affected mappings</span></h3>' + items.map(row => '<div class="mapping-issue-row"><strong>' + esc(row.subject_code) + '</strong><span>' + esc(row.subject_title) + '<small>' + esc((row.year_level || 'No year / grade') + ' \u00b7 ' + (row.semester || 'No semester')) + '</small></span>' + (permission.manage ? '<button type="button" class="button-muted" data-edit-review-mapping="' + Number(row.id) + '">Edit Placement</button>' : '<button type="button" class="button-muted" data-open-review-mapping="' + Number(row.id) + '">View Details</button>') + '</div>').join('') + '</section>';
    }

    function setTab(name) {
        state.currentTab = name;
        updateContextMessage();
        [['map', 'mappingTabMap', 'mappingPanelMap'], ['trace', 'mappingTabTrace', 'mappingPanelTrace'], ['review', 'mappingTabReview', 'mappingPanelReview'], ['outcomes','mappingTabOutcomes','mappingPanelOutcomes']].forEach(([key, tabId, panelId]) => {
            const active = key === name;
            el(tabId).classList.toggle('is-active', active);
            el(tabId).setAttribute('aria-selected', String(active));
            el(tabId).tabIndex = active ? 0 : -1;
            el(panelId).hidden = !active;
        });
        if (name === 'review') loadReview(false);
        if (name === 'outcomes') loadOutcomes();
    }

    async function initializeContext() {
        updateContextMessage(); await loadMap();
        if (!permission.programs) { showPanelState(mapTarget, 'Program context is not available with your current permissions.'); return; }
        const params = new URLSearchParams(window.location.search);
        if (!params.get('version_id')) return;
        const request = state.mapRequest;
        try {
            const version = (await ui.fetchJson(api.versions, { action: 'get', id: params.get('version_id') })).version;
            const curriculum = (await ui.fetchJson(api.curricula, { action: 'get', id: version.curriculum_id })).curriculum;
            const program = (await ui.fetchJson(api.programs, { action: 'get', id: curriculum.academic_program_id })).program;
            if (request !== state.mapRequest) return;
            if ((params.get('program_id') && Number(params.get('program_id')) !== Number(program.id)) || (params.get('curriculum_id') && Number(params.get('curriculum_id')) !== Number(curriculum.id))) throw new Error('The linked mapping context is inconsistent. Please choose a context.');
            state.program = program; state.curriculum = curriculum; state.version = version;
            programAutocomplete.setSelected(program, false); curriculumAutocomplete.setSelected(curriculum, false); versionAutocomplete.setSelected(version, false);
            resetTrace();
            updateContextMessage(); await loadMap();
            if (params.get('placement_id')) openMappingDetails(state.mappings.get(Number(params.get('placement_id'))));
            else if (params.get('subject_id')) {
                const placement = state.allMappings.find(p => Number(p.subject_id) === Number(params.get('subject_id')));
                if (placement) { if (permission.manage) prepareEdit(placement); else openMappingDetails(placement); }
            }
            if (params.get('outcomes') === '1') setTab('outcomes');
        } catch (error) { if (request === state.mapRequest) showPanelState(mapTarget, error.message || 'Linked curriculum context could not be loaded.', true); }
    }

    el('mappingWorkspaceTabs')?.addEventListener('click', event => {
        const tab = event.target.closest('[role="tab"]');
        if (!tab) return;
        setTab(tab.id === 'mappingTabMap' ? 'map' : tab.id === 'mappingTabTrace' ? 'trace' : tab.id === 'mappingTabOutcomes' ? 'outcomes' : 'review');
    });
    el('mappingWorkspaceTabs')?.addEventListener('keydown', event => {
        const tabs = [...el('mappingWorkspaceTabs').querySelectorAll('[role="tab"]')];
        const current = tabs.indexOf(event.target.closest('[role="tab"]'));
        if (current < 0) return;
        let next = current;
        if (event.key === 'ArrowRight') next = (current + 1) % tabs.length;
        else if (event.key === 'ArrowLeft') next = (current + tabs.length - 1) % tabs.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = tabs.length - 1;
        else return;
        event.preventDefault();
        const tab = tabs[next];
        setTab(tab.id === 'mappingTabMap' ? 'map' : tab.id === 'mappingTabTrace' ? 'trace' : tab.id === 'mappingTabOutcomes' ? 'outcomes' : 'review');
        tab.focus();
    });
    mapTarget?.addEventListener('click', event => {
        const card = event.target.closest('[data-open-mapping]');
        if (card) openMappingDetails(state.mappings.get(Number(card.dataset.openMapping)));
    });
    el('mappingTraceResults')?.addEventListener('click', event => {
        const row = event.target.closest('[data-open-trace-mapping]');
        if (row) openMappingDetails(state.mappings.get(Number(row.dataset.openTraceMapping)), true);
    });
    el('mappingReviewCards')?.addEventListener('click', event => {
        const card = event.target.closest('[data-review-issue]');
        if (card) { selectedReviewIssue = card.dataset.reviewIssue; renderReviewCards(); }
    });
    el('mappingReviewResults')?.addEventListener('click', event => {
        const edit = event.target.closest('[data-edit-review-mapping]');
        if (edit) prepareEdit(state.mappings.get(Number(edit.dataset.editReviewMapping)));
        const row = event.target.closest('[data-open-review-mapping]');
        if (row) openMappingDetails(state.mappings.get(Number(row.dataset.openReviewMapping)));
    });
    el('mappingDetailsEdit')?.addEventListener('click', () => prepareEdit(state.selectedMapping));
    el('mappingDetailsRemove')?.addEventListener('click', () => requestRemoval(state.selectedMapping));
    el('addMappingButton')?.addEventListener('click', openAddMapping);
    el('mappingSubjectSearch')?.addEventListener('input', ui.debounce(renderFilteredMap, 150));
    el('mappingYearTabs').addEventListener('click', event => { const button = event.target.closest('[data-map-year]'); if (button) { state.year = button.dataset.mapYear; renderFilteredMap(); } });
    el('mappingYearFilter').addEventListener('change', () => { state.year = el('mappingYearFilter').value; renderFilteredMap(); });
    el('mappingPlacementFilter').addEventListener('change', renderFilteredMap);
    el('mappingSemesterFilter')?.addEventListener('change', renderFilteredMap);

    addForm?.addEventListener('submit', async event => {
        event.preventDefault();
        if (state.currentTab !== 'map' || !state.program || !state.curriculum || !state.version || !state.loaded) return;
        if (!addSubjectAutocomplete?.hasSelection()) {
            ui.showFormError(addForm, 'Select an existing Subject Master record.');
            addSubjectInput.focus();
            return;
        }
        if (!addForm.reportValidity()) return;
        if (state.allMappings.some(row => Number(row.subject_id) === Number(el('mappingSubjectId').value))) { ui.showFormError(addForm, 'This subject is already placed in this curriculum version. Use Edit Placement to change its placement.'); return; }
        ui.clearFormError(addForm);
        const data = new FormData(addForm);
        data.set('action', 'map_subject');
        ui.setFormSubmitting(addForm, true, 'Saving…');
        try {
            const result = await ui.postForm(api.mappings, data);
            addModal.close();
            ui.showToast(result.message || 'Subject mapped.', 'success');
            await loadMap();
        } catch (error) {
            ui.showFormError(addForm, error.message || 'Subject mapping could not be saved.');
        } finally { ui.setFormSubmitting(addForm, false); }
    });
    editForm?.addEventListener('submit', async event => {
        event.preventDefault();
        if (!editForm.reportValidity()) return;
        if (!sequenceValid) return;
        ui.clearFormError(editForm);
        const data = new FormData(editForm);
        data.set('action', 'update_mapping');
        ui.setFormSubmitting(editForm, true, 'Saving…');
        try {
            const result = await ui.postForm(api.mappings, data);
            editModal.close();
            ui.showToast(result.message || 'Mapping updated.', 'success');
            await loadMap();
        } catch (error) {
            ui.showFormError(editForm, error.message || 'Mapping could not be updated.');
        } finally { ui.setFormSubmitting(editForm, false); if (!el('editMappingModal').hidden) previewPlacement(); }
    });
    el('confirmRemoveMapping')?.addEventListener('click', async function () {
        if (!state.pendingRemoval) return;
        ui.clearFormError(el('removeMappingModal'));
        const button = this;
        button.disabled = true;
        try {
            const data = new FormData();
            data.set('action', 'remove');
            data.set('id', String(state.pendingRemoval.id));
            const result = await ui.postForm(api.mappings, data);
            removeModal.close();
            state.pendingRemoval = null;
            ui.showToast(result.message || 'Mapping removed.', 'success');
            await loadMap();
        } catch (error) {
            ui.showFormError(el('removeMappingModal'), error.message || 'Mapping could not be removed.');
        } finally { button.disabled = false; }
    });

    function renderConnections() {
        const target = el('mappingConnections');
        target.hidden = !permission.prerequisites || !el('mappingShowConnections')?.checked;
        if (target.hidden) { target.replaceChildren(); return; }
        if (!state.relationshipsLoaded) { showPanelState(target, 'Loading stored prerequisites...'); return; }
        if (state.requirementError) { showPanelState(target, state.requirementError, true); return; }
        target.innerHTML = state.requirements.map((rule, index) => '<button type="button" class="mapping-connection" data-connection="' + index + '"><strong>' + esc(rule.required_subject_code + ' → ' + rule.subject_code) + '</strong><span>' + esc(rule.subject_code + ' requires ' + rule.required_subject_code) + '</span></button>').join('') || '<p class="mapping-state">No prerequisite configured.</p>';
    }
    el('mappingShowConnections')?.addEventListener('change', renderConnections);
    el('mappingConnections').addEventListener('click', event => {
        const button = event.target.closest('[data-connection]');
        const rule = button && state.requirements[Number(button.dataset.connection)];
        if (!rule || !permission.prerequisites) return;
        el('mappingConnectionTitle').textContent = rule.required_subject_code + ' → ' + rule.subject_code;
        el('mappingConnectionSubjects').textContent = rule.required_subject_title + ' → ' + rule.subject_title;
        el('mappingConnectionText').textContent = rule.subject_code + ' requires ' + rule.required_subject_code + '.';
        el('mappingConnectionManage').href = contextUrl('prerequisite_configuration.php', rule);
        connectionModal.open();
    });
    setTab('map');
    initializeContext();
}());
