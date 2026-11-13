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
        reviewLoaded: false, yearValues: [], semesterValues: [], outcomeData: null, outcomeRequest: 0, cloRequest: 0
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
    const detailsModal = ui.createModal(el('mappingDetailsModal'));
    const sourceModal = ui.createModal(el('sourceDetailsModal'));
    const outcomeModal = ui.createModal(el('outcomeViewModal'),{restoreFocus:()=>el('outcomeSearch')});
    const cloModal = ui.createModal(el('cloViewModal'),{restoreFocus:()=>el('cloPlacementSearch')});
    const cloCreateModal = ui.createModal(el('cloCreateModal'),{restoreFocus:()=>el('cloPlacementSearch')});
    const academicDialogs = [[sourceModal,'sourceDetailsModal'],[outcomeModal,'outcomeViewModal'],[cloModal,'cloViewModal'],[cloCreateModal,'cloCreateModal']];
    function closeAcademicDialogs(except) {
        academicDialogs.forEach(([dialog,id])=>{if(id!==except&&!el(id).hidden)dialog.close();});
    }
    let curriculumAutocomplete;
    let versionAutocomplete;
    let contextReset = false;
    let reviewIssues = null;
    let contextRequest = 0;
    let selectedOutcome = null;
    let outcomeFilter = 'all';
    let selectedClo = null;
    let cloData = null;
    const academicSubmissions = new WeakSet();
    function setAcademicSubmitting(form, busy) {
        if (busy) academicSubmissions.add(form); else academicSubmissions.delete(form);
        ui.setFormSubmitting(form,busy);
        form.querySelectorAll?.('button[type="submit"]').forEach(button=>{button.disabled=busy;});
    }

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
    function yearChoices() { return [...new Set([...(isShs() ? ['Grade 11', 'Grade 12'] : ['1st Year', '2nd Year', '3rd Year', '4th Year']), ...state.allMappings.map(row => row.year_level).filter(value => !isBlank(value))])].sort(sortLabel); }
    function updateContextMessage() {
        const complete = Boolean(state.program && state.curriculum && state.version && versionIdInput.value);
        root.classList.toggle('has-mapping-context', complete);
        const builderLink = el('mappingBuilderLink');
        if (builderLink) { builderLink.hidden = !complete; if (complete) builderLink.href = contextUrl('curriculum_builder.php').replace(/&subject_id=$/, ''); }
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
    function showPanelState(target, message, error) {
        if (!target) return;
        target.innerHTML = '<p class="mapping-state' + (error ? ' is-error' : '') + '">' + esc(message) + '</p>';
    }

    const mappingContextCascade = ui.createCascadingAutocomplete({
        restoreSavedContext: !new URLSearchParams(window.location.search).has('version_id'),
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
            state.year = ''; reviewUi.reset();
            ['mappingSubjectSearch', 'mappingYearFilter', 'mappingSemesterFilter', 'mappingStatusFilter'].forEach(key => { el(key).value = ''; });
            if (contextReset) return;
            contextRequest++;
            writeUrl();
            if (index === 0) onProgramChange();
            else if (index === 1) onCurriculumChange();
            else { updateContextMessage(); loadMap(); }
        }
    });
    const programAutocomplete = mappingContextCascade?.get(0);
    curriculumAutocomplete = mappingContextCascade?.get(1);
    versionAutocomplete = mappingContextCascade?.get(2);

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

    const COURSE_EMPTY = '<div class="csms-empty" role="status"><i class="fa-solid fa-list-check" aria-hidden="true"></i><strong>Select a subject to view or add its course learning outcomes</strong></div>';
    const showCourseEmpty = () => { el('cloAssessmentContent').innerHTML = COURSE_EMPTY; };

    async function loadMap() {
        const request = ++state.mapRequest;
        const version = versionIdInput.value;
        state.outcomeRequest++; state.cloRequest++; state.outcomeData=null; selectedOutcome=null; selectedClo=null; cloData=null;
        closeAcademicDialogs();
        el('viewSourceDetails').disabled=true;
        ['outcomeSources','outcomeDetail'].forEach(id => el(id).replaceChildren());
        cloPlacementAutocomplete.clear(false);
        el('cloPlacementSearch').disabled=true;
        showCourseEmpty();
        showPanelState(el('outcomeMatrix'), 'Select a BSIT curriculum version to view its recorded outcome mappings.');
        if (['outcomes', 'course'].includes(state.currentTab)) loadOutcomes();
        state.matrix = null; state.loaded = false; state.reviewLoaded = false; state.allMappings = []; state.requirements = []; state.relationshipRequest = null; state.relationshipsLoaded = false; state.requirementError = '';
        state.mappings.clear(); state.detailRequest++;
        detailsModal?.close();
        state.selectedMapping = null;
        updateContextMessage();
        el('mappingCount').textContent = version ? 'Loading...' : 'Select a version';
        setSelectOptions(el('mappingYearFilter'), [], isShs() ? 'All Grades' : 'All Years', '');
        ['mappingMap', 'mappingReviewCards', 'mappingReviewResults'].forEach(id => showPanelState(el(id), version ? 'Loading selected curriculum version...' : 'Select a program, curriculum, and version to view placements.'));
        if (!version) return;
        try {
            const mappings = await fetchAll(api.mappings, 'mappings', { action: 'mapping_list', curriculum_version_id: version });
            if (request !== state.mapRequest) return;
            state.mappings = new Map(mappings.map(item => [Number(item.id), item])); state.allMappings = mappings; state.loaded = true; loadMatrix(version, request);
            state.yearValues = yearChoices();
            setSelectOptions(el('mappingYearFilter'), state.yearValues, isShs() ? 'All Grades' : 'All Years', state.year);
            state.semesterValues = [...new Set(mappings.map(item => item.semester).filter(value => !isBlank(value)))].sort(sortLabel);
            setSelectOptions(el('mappingSemesterFilter'), state.semesterValues, 'All Semesters', el('mappingSemesterFilter').value);
            updateContextMessage(); renderFilteredMap(); loadReview(true);
        } catch (error) {
            if (request !== state.mapRequest) return;
            state.loaded = false; el('mappingCount').textContent='Not loaded'; updateContextMessage();
            ['mappingMap', 'mappingReviewCards', 'mappingReviewResults'].forEach(id => showPanelState(el(id), error.message || 'Selected context could not be loaded.', true));
        }
    }

    async function loadOutcomes() {
        const target = el('outcomeMatrix');
        if (!target) return;
        const versionId = versionIdInput.value;
        const request = ++state.outcomeRequest;
        if (!versionId) { state.outcomeData = null; showPanelState(target, 'Select a BSIT curriculum version to view recorded outcomes.'); return; }
        state.outcomeData=null; cloData=null;
        ['outcomeSources','outcomeDetail'].forEach(id=>el(id).replaceChildren());
        el('cloPlacementSearch').disabled=true;
        el('viewSourceDetails').disabled=true;
        if(!el('outcomeViewModal').hidden)showPanelState(el('outcomeDetail'),'Refreshing recorded outcome mappings...');
        if(!el('sourceDetailsModal').hidden)showPanelState(el('outcomeSources'),'Refreshing documentary source details...');
        if(!el('cloViewModal').hidden)showPanelState(el('cloDetail'),'Refreshing academic planning records...');
        state.cloRequest++;
        showPanelState(el('cloAssessmentContent'),'Loading selected version workspace...');
        showPanelState(target, 'Loading source outcomes and recorded mappings...');
        try {
            const data = await ui.fetchJson(api.outcomes, { action: 'workspace', curriculum_version_id: versionId });
            if (request !== state.outcomeRequest) return;
            state.outcomeData = data;
            el('cloPlacementSearch').disabled=false;
            el('viewSourceDetails').disabled=!arr(data.outcomes).length;
            renderOutcomes();
        } catch (error) {
            if (request === state.outcomeRequest) {
                showPanelState(target, error.message || 'Outcome mappings could not be loaded.', true);
                cloPlacementAutocomplete.clear(false);
                cloData=null; selectedOutcome=null; selectedClo=null; closeAcademicDialogs();
                showPanelState(el('cloAssessmentContent'),'Version workspace unavailable. Reselect an authorized version.',true);
            }
        }
    }

    // Subject picker of the Course outcomes tab: searchable, grouped by year and semester, starts empty.
    const cloPlacementAutocomplete = (function () {
        const input = el('cloPlacementSearch'), hidden = el('cloPlacementSelect'), box = el('cloPlacementOptions'), clearButton = input.parentElement.querySelector('[data-autocomplete-clear]');
        let shown = [], active = -1, filtering = false;
        const placements = () => arr(state.outcomeData?.placements);
        const label = row => row.subject_code + ' / ' + row.subject_title;
        const groupLabel = row => (row.year_level || 'Year not set') + ' \u00b7 ' + (row.semester || 'Term not set');
        function open(show) { box.hidden = !show; input.setAttribute('aria-expanded', String(show)); if (!show) { active = -1; input.removeAttribute('aria-activedescendant'); } }
        function render() {
            const terms = (filtering ? input.value : '').trim().toLowerCase().split(/\s+/).filter(Boolean);
            const rows = placements().filter(row => terms.every(t => [row.subject_code, row.subject_title, row.year_level, row.semester].join(' ').toLowerCase().includes(t)));
            const groups = new Map();
            rows.forEach(row => { const key = groupLabel(row); if (!groups.has(key)) groups.set(key, []); groups.get(key).push(row); });
            shown = [];
            box.innerHTML = rows.length ? [...groups].map(([key, list]) => '<div role="group" aria-label="' + esc(key) + '"><div class="record-autocomplete-group">' + esc(key) + '</div>' + list.map(row => { shown.push(row); return '<button type="button" role="option" class="record-autocomplete-option" id="cloOpt' + Number(row.placement_id) + '" data-placement="' + Number(row.placement_id) + '"><strong>' + esc(row.subject_code) + '</strong> ' + esc(row.subject_title) + '</button>'; }).join('') + '</div>').join('')
                : '<div class="record-autocomplete-state" role="status">No matching subject placements.</div>';
            active = -1; open(true);
        }
        function highlight(index) {
            const options = [...box.querySelectorAll('[role="option"]')]; if (!options.length) return;
            active = (index + options.length) % options.length;
            options.forEach((option, i) => option.classList.toggle('is-active', i === active));
            input.setAttribute('aria-activedescendant', options[active].id); options[active].scrollIntoView({ block: 'nearest' });
        }
        function setSelected(row, notify) {
            hidden.value = String(row.placement_id); input.value = label(row); input.classList.add('has-selection'); filtering = false; open(false);
            if (clearButton) clearButton.hidden = false;
            if (notify !== false) loadCloWorkspace();
        }
        function clear(notify) {
            hidden.value = ''; input.value = ''; input.classList.remove('has-selection'); filtering = false; open(false);
            if (clearButton) clearButton.hidden = true;
            if (notify !== false) { state.cloRequest++; cloData = null; selectedClo = null; closeAcademicDialogs(); showCourseEmpty(); }
        }
        input.addEventListener('focus', () => { if (!input.disabled) { filtering = false; render(); } });
        input.addEventListener('click', () => { if (!input.disabled && box.hidden) { filtering = false; render(); } });
        input.addEventListener('input', () => { filtering = true; if (!input.value.trim() && hidden.value) clear(true); render(); });
        input.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown') { event.preventDefault(); if (box.hidden) render(); highlight(active + 1); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); if (box.hidden) render(); highlight(active - 1); }
            else if (event.key === 'Enter' && !box.hidden && active >= 0) { event.preventDefault(); setSelected(shown[active], true); }
            else if (event.key === 'Escape' && !box.hidden) { event.preventDefault(); open(false); }
        });
        box.addEventListener('mousedown', event => event.preventDefault());
        box.addEventListener('click', event => { const option = event.target.closest('[data-placement]'); if (!option) return; const row = placements().find(r => String(r.placement_id) === option.dataset.placement); if (row) setSelected(row, true); });
        clearButton?.addEventListener('click', () => { clear(true); input.focus(); });
        document.addEventListener('click', event => { if (!input.parentElement.contains(event.target)) open(false); });
        return { setSelected, clear };
    }());
    function renderOutcomeList() {
        if (!state.outcomeData) return;
        const P = window.OutcomesPresentation, all = arr(state.outcomeData.outcomes), query = el('outcomeSearch').value;
        const counts = P.chipCounts(all, query);
        if (!(outcomeFilter in counts)) outcomeFilter = 'all';
        el('outcomeChips').innerHTML = P.filterChips(counts, outcomeFilter);
        el('outcomeZero').hidden = !P.allZero(all);
        el('outcomeMatrix').innerHTML = P.outcomeTable(P.filterOutcomes(all, query, outcomeFilter), selectedOutcome);
    }
    function refreshOutcomeDetails() {
        const data=state.outcomeData,o=arr(data?.outcomes).find(o=>Number(o.id)===selectedOutcome);
        if(!o){if(!el('outcomeViewModal').hidden)outcomeModal.close();el('outcomeDetail').replaceChildren();return false;}
        el('outcomeViewModalTitle').textContent=o.outcome_code+' - Graduate Outcome Details';
        el('outcomeViewModalContext').textContent=[data.context.program_code,data.context.curriculum_name,data.context.version_label].join(' / ');
        el('outcomeDetailsStatus').innerHTML=ui.statusBadge(o.coverage_status);
        el('outcomeDetail').innerHTML=window.OutcomesPresentation.outcomeDetail(data,selectedOutcome,permission.manageOutcomes);
        return true;
    }
    function refreshCloDetails(edit=false) {
        if(!cloData){if(!el('cloViewModal').hidden)cloModal.close();el('cloDetail').replaceChildren();return false;}
        const c=arr(cloData.clos).find(c=>Number(c.id)===selectedClo);
        const html=window.OutcomesPresentation.cloDetail(cloData,selectedClo,permission,edit);
        if(!html){if(!el('cloViewModal').hidden)cloModal.close();el('cloDetail').replaceChildren();return false;}
        el('cloViewModalTitle').textContent=c?c.clo_code+' - Course Learning Outcome Details':'Create Course Learning Outcome';
        el('cloViewModalContext').textContent=[cloData.placement.subject_code,cloData.placement.version_label,cloData.placement.year_level,cloData.placement.semester].filter(Boolean).join(' / ');
        el('cloDetailsStatus').innerHTML=ui.statusBadge(cloData.editable&&(selectedClo==='create'||c?.record_state==='active')&&(permission.manageClos||permission.managePlans)?'editable':'read_only');
        el('cloDetail').innerHTML=html;return true;
    }
    function renderOutcomes() {
        const data=state.outcomeData;if(!data)return;
        el('outcomeSources').innerHTML=window.OutcomesPresentation.sources(data);
        const placement=arr(data.placements).find(p=>String(p.placement_id)===el('cloPlacementSelect').value);
        if(placement){cloPlacementAutocomplete.setSelected(placement,false);loadCloWorkspace();}
        else{cloPlacementAutocomplete.clear(false);cloData=null;selectedClo=null;refreshCloDetails();showCourseEmpty();}
        if(!arr(data.outcomes).length){showPanelState(el('outcomeMatrix'),'The source-traceable IT01-IT13 catalog has not been loaded in this environment.');return;}
        renderOutcomeList();refreshOutcomeDetails();
    }
    el('outcomeSearch').addEventListener('input',renderOutcomeList);
    el('outcomeChips').addEventListener('click',event=>{const button=event.target.closest('[data-outcome-filter]');if(!button)return;outcomeFilter=button.dataset.outcomeFilter;renderOutcomeList();});
    el('outcomeOpenMap').addEventListener('click',()=>setTab('map'));
    el('outcomeMatrix').addEventListener('keydown',event=>{const row=event.target.closest?.('tr[data-outcome-open]');if(!row||event.target!==row||!['Enter',' '].includes(event.key))return;event.preventDefault();row.click();});
    el('viewSourceDetails').addEventListener('click',()=>{
        if(!state.outcomeData||!arr(state.outcomeData.outcomes).length)return;
        closeAcademicDialogs('sourceDetailsModal');
        el('outcomeSources').innerHTML=window.OutcomesPresentation.sources(state.outcomeData);sourceModal.open();
    });
    el('outcomeMatrix').addEventListener('click',event=>{
        const button=event.target.closest('[data-outcome-open]');if(!button||!state.outcomeData)return;
        selectedOutcome=Number(button.dataset.outcomeOpen);
        if(!refreshOutcomeDetails())return;
        closeAcademicDialogs('outcomeViewModal');outcomeModal.open();
    });
    el('cloAssessmentContent').addEventListener('click',event=>{
        const button=event.target.closest('[data-clo-open],[data-clo-edit],[data-clo-create]');if(!button||!cloData)return;
        if(button.dataset.cloCreate!==undefined){openCloCreate();return;}
        selectedClo=Number(button.dataset.cloOpen||button.dataset.cloEdit);
        if(!refreshCloDetails(Boolean(button.dataset.cloEdit)))return;
        closeAcademicDialogs('cloViewModal');cloModal.open();
    });
    // Create CLO: its own dialog, sequence pre-filled with the next free number. The code has no stored pattern (no CLO exists yet), so it stays empty with a placeholder.
    function openCloCreate() {
        if(!cloData||!cloData.editable||!permission.manageClos)return;
        const form=el('cloCreateForm');form.reset();ui.clearFormError(form);
        el('cloCreateSequence').value=window.OutcomesPresentation.nextCloSequence(cloData);
        el('cloCreateContext').textContent=[cloData.placement.subject_code,cloData.placement.version_label,cloData.placement.year_level,cloData.placement.semester].filter(Boolean).join(' / ');
        closeAcademicDialogs('cloCreateModal');cloCreateModal.open();
        const focusFirstEmpty=()=>{const first=[el('cloCreateSequence'),el('cloCreateCode'),el('cloCreateStatement')].find(input=>!String(input.value).trim());(first||el('cloCreateCode')).focus();};
        // the shared dialog focuses its first control in a requestAnimationFrame; ask for the first empty field after that
        (typeof requestAnimationFrame==='function'?requestAnimationFrame:fn=>setTimeout(fn,0))(()=>setTimeout(focusFirstEmpty,30));
    }
    el('cloCreateForm').addEventListener('submit',async event=>{
        event.preventDefault();const form=event.currentTarget;
        if(academicSubmissions.has(form)||!cloData||!cloData.editable||!permission.manageClos)return;
        const body=new FormData(form);body.set('placement_id',el('cloPlacementSelect').value);body.set('action','clo_create');
        ui.clearFormError(form);setAcademicSubmitting(form,true);
        try{const result=await ui.postForm(api.clos,body);ui.showToast(result.message||'Course learning outcome recorded.','success');cloCreateModal.close();selectedClo=Number(result.clo_id)||null;await loadCloWorkspace();}
        catch(error){ui.showFormError(form,error.message||'Record could not be saved.');}
        finally{setAcademicSubmitting(form,false);}
    });

    async function loadCloWorkspace() {
        const placementId = el('cloPlacementSelect')?.value, target = el('cloAssessmentContent');
        const request=++state.cloRequest, version=versionIdInput.value;
        if (!target) return;
        if(!placementId){cloData=null;selectedClo=null;refreshCloDetails();showCourseEmpty();return;}
        if(cloData&&String(cloData.placement.placement_id)!==String(placementId)){cloData=null;selectedClo=null;refreshCloDetails();}
        if(!el('cloViewModal').hidden)showPanelState(el('cloDetail'),'Loading academic planning records...');
        showPanelState(target, 'Loading placement-owned CLO and planned assessment records...');
        try {
            const data = await ui.fetchJson(api.clos, {action:'workspace',placement_id:placementId});
            if (request!==state.cloRequest || version!==versionIdInput.value || String(el('cloPlacementSelect').value) !== String(placementId)) return;
            if (String(data.placement.curriculum_version_id) !== String(version)) throw new Error('Placement does not belong to the selected version.');
            cloData=data;
            target.innerHTML = window.OutcomesPresentation.cloWorkspace(data,permission);
            refreshCloDetails();
        } catch (error) { if(request===state.cloRequest && version===versionIdInput.value){cloData=null;selectedClo=null;refreshCloDetails();showPanelState(target,error.message||'CLO workspace could not be loaded.',true);} }
    }

    el('cloPlacementSelect')?.addEventListener('change', loadCloWorkspace);
    el('cloDetail')?.addEventListener('submit', async event => {
        const form = event.target.closest('.clo-create-form,.clo-edit-form,.clo-alignment-form,.clo-alignment-edit-form,.clo-plan-form,.clo-plan-edit-form'); if (!form) return;
        event.preventDefault(); if (academicSubmissions.has(form)||!cloData||!cloData.editable) return;
        const body = new FormData(form); body.set('placement_id',el('cloPlacementSelect').value);
        body.set('action',form.matches('.clo-create-form')?'clo_create':form.matches('.clo-edit-form')?'clo_update':form.matches('.clo-alignment-form')?'alignment_add':form.matches('.clo-alignment-edit-form')?'alignment_update':form.matches('.clo-plan-edit-form')?'plan_update':'plan_add');
        ui.clearFormError(form); setAcademicSubmitting(form,true);
        try { const result=await ui.postForm(api.clos,body); if(body.get('action')==='clo_create')selectedClo=Number(result.clo_id)||null; ui.showToast(result.message || 'Academic planning record saved.','success'); await loadCloWorkspace(); }
        catch(error) { ui.showFormError(form,error.message || 'Record could not be saved.'); }
        finally { setAcademicSubmitting(form,false); }
    });
    el('cloDetail')?.addEventListener('click', async event => {
        const button=event.target.closest('[data-clo-action]'); if (!button) return;
        if (!window.confirm('Withdraw this record? Its historical record will be retained.')) return;
        const body=new FormData(); body.set('placement_id',el('cloPlacementSelect').value); body.set('action',button.dataset.cloAction); body.set('clo_id',button.dataset.clo || button.dataset.id); if (button.dataset.cloAction==='alignment_withdraw') body.set('alignment_id',button.dataset.id); if (button.dataset.cloAction==='plan_withdraw') body.set('plan_id',button.dataset.id);
        try { const result=await ui.postForm(api.clos,body); ui.showToast(result.message || 'Record withdrawn.','success'); await loadCloWorkspace(); }
        catch(error) { ui.showToast(error.message || 'Record could not be withdrawn.','error'); }
    });

    el('outcomeDetail')?.addEventListener('submit', async event => {
        const form = event.target.closest('[data-outcome-assess]');
        if (!form) return;
        event.preventDefault();
        if(academicSubmissions.has(form)||!state.outcomeData?.context.editable||!permission.manageOutcomes)return;
        if(String(form.dataset.outcomeAssess)!==String(selectedOutcome))return;
        const data = new FormData(form);
        data.set('curriculum_version_id', String(versionIdInput.value));
        data.set('action','assess'); data.set('outcome_id',form.dataset.outcomeAssess);
        data.set('assessment_state',event.submitter?.value || 'incomplete');
        ui.clearFormError(form); setAcademicSubmitting(form,true);
        try { const result=await ui.postForm(api.outcomes,data); ui.showToast(result.message || 'Outcome record saved.','success'); await loadOutcomes(); }
        catch(error) { ui.showFormError(form,error.message || 'Outcome record could not be saved.'); }
        finally { setAcademicSubmitting(form,false); }
    });
    el('outcomeDetail')?.addEventListener('click', async event => {
        // "Map subjects in Curriculum Map": close the dialog, show the Curriculum Map tab and mark that outcome's column.
        const go=event.target.closest('[data-outcome-map]');
        if (go) { outcomeModal.close(); setTab('map'); highlightOutcomeColumn(Number(go.dataset.outcomeMap)); return; }
        const button=event.target.closest('[data-outcome-remove]'); if (!button) return;
        if (!window.confirm('Remove this subject outcome mapping? Dependent CLO alignments will be withdrawn and coverage will require review.')) return;
        const data=new FormData(); data.set('action','remove'); data.set('curriculum_version_id',String(versionIdInput.value)); data.set('mapping_id',button.dataset.outcomeRemove);
        try { const result=await ui.postForm(api.outcomes,data); ui.showToast(result.message || 'Mapping removed.','success'); await loadOutcomes(); }
        catch(error) { ui.showToast(error.message || 'Mapping could not be removed.','error'); }
    });
    const placementView = window.CurriculumPlacementView;
    const matrixView = window.SubjectMappingMatrix;
    const emptyBox = (title, hint) => '<div class="csms-empty"><i class="fa-solid fa-table-list" aria-hidden="true"></i><strong>' + esc(title) + '</strong>' + (hint ? '<span>' + esc(hint) + '</span>' : '') + '</div>';
    async function loadMatrix(version, request) {
        state.matrix = { status: 'loading' };
        try {
            const data = await ui.fetchJson(api.outcomes, { action: 'workspace', curriculum_version_id: version });
            if (request !== state.mapRequest) return;
            state.matrix = { status: 'ready', editable: permission.manageOutcomes && Boolean(data.context && data.context.editable), outcomes: arr(data.outcomes), rows: arr(data.placements).map(p => ({ ...p, id: p.placement_id })), index: matrixView.index(data.mappings), mappings: arr(data.mappings) };
        } catch (error) {
            if (request !== state.mapRequest) return;
            state.matrix = /only for BSIT/i.test(error.message || '') ? { status: 'nocatalog' } : { status: 'error', message: error.message || 'Outcome mappings could not be loaded.' };
        }
        renderFilteredMap();
        if (state.reviewLoaded) renderReviewCards();
    }
    function renderFilteredMap() {
        if (!state.loaded) return;
        state.year = el('mappingYearFilter').value;
        const matrix = state.matrix;
        if (!matrix || matrix.status === 'loading') { el('mappingCount').textContent = 'Loading...'; showPanelState(mapTarget, 'Loading the curriculum map...'); return; }
        if (matrix.status === 'nocatalog') { el('mappingCount').textContent = ''; mapTarget.innerHTML = emptyBox('No outcome catalog for this program yet.', 'Graduate outcomes are loaded for BSIT only.'); return; }
        if (matrix.status === 'error') { el('mappingCount').textContent = ''; showPanelState(mapTarget, matrix.message, true); return; }
        if (!matrix.outcomes.length) { el('mappingCount').textContent = ''; mapTarget.innerHTML = emptyBox('No graduate outcomes are loaded for this program.'); return; }
        if (!matrix.rows.length) { el('mappingCount').textContent = '0 subjects'; mapTarget.innerHTML = emptyBox('No subjects placed yet.', 'Place subjects in the Curriculum Builder.'); return; }
        const query = el('mappingSubjectSearch').value.trim().toLowerCase();
        const semester = el('mappingSemesterFilter').value, status = el('mappingStatusFilter').value;
        const rows = ui.searchRecords(matrix.rows, query, row => [row.subject_code, row.subject_title]).filter(row => (!state.year || row.year_level === state.year) && (!semester || row.semester === semester) &&
            (!status || (status === 'mapped') === matrixView.isMapped(matrix.index, row)));
        el('mappingCount').textContent = rows.length + (rows.length === 1 ? ' subject' : ' subjects');
        if (!rows.length) { mapTarget.innerHTML = emptyBox('No subjects match these filters.'); return; }
        mapTarget.innerHTML = matrixView.render(rows, matrix.outcomes, matrix.index, { esc, group: placementView.group, yearLabel: placementView.yearLabel, editable: matrix.editable });
        updateAccessNote();
        fitMatrix();
        if (highlightedOutcome !== null) applyColumnHighlight();
        if (pendingFocus) { const b = mapTarget.querySelector('[data-placement="' + pendingFocus.placement + '"][data-outcome="' + pendingFocus.outcome + '"]'); pendingFocus = null; if (b) b.focus(); }
    }
    let pendingFocus = null, highlightedOutcome = null;
    // Marks one outcome column of the map (header, cells, footer) and scrolls it into view; cleared when the tab changes.
    function applyColumnHighlight() {
        mapTarget.querySelectorAll('.is-focus-col').forEach(node => node.classList.remove('is-focus-col'));
        if (highlightedOutcome === null) return;
        const cells = mapTarget.querySelectorAll('[data-col="' + highlightedOutcome + '"]');
        cells.forEach(node => node.classList.add('is-focus-col'));
        const head = mapTarget.querySelector('th[data-col="' + highlightedOutcome + '"]');
        if (head) { head.scrollIntoView({ block: 'nearest', inline: 'center' }); head.focus({ preventScroll: true }); }
    }
    function highlightOutcomeColumn(id) { highlightedOutcome = Number(id); applyColumnHighlight(); }
    // Horizontal scroll only when the matrix is wider than its card; otherwise the header and footer stay sticky against the page scroll.
    function fitMatrix() {
        const box = mapTarget.querySelector('.map-scroll'), table = box && box.querySelector('table');
        if (!box) return;
        box.classList.remove('is-scrollable');
        if (table.scrollWidth > box.clientWidth + 1) box.classList.add('is-scrollable');
    }
    window.addEventListener('resize', ui.debounce(fitMatrix, 150));
    function updateAccessNote() {
        const note = el('mapAccessNote'), matrix = state.matrix;
        if (!note || !matrix || matrix.status !== 'ready') return;
        const legend = 'Check mark = this subject is linked to the outcome. ';
        note.textContent = matrix.editable ? legend + 'Click a cell to add or remove a link.'
            : legend + (permission.manageOutcomes ? 'Read-only: this curriculum version cannot be edited.' : 'Read-only for your role.');
    }
    // Editing: a rationale dialog when a cell is checked, a confirmation when it is cleared. The server decides; its message is shown.
    const addModal = ui.createModal(el('mapAddModal'), { restoreFocus: () => null });
    const removeModal = ui.createModal(el('mapRemoveModal'), { restoreFocus: () => null });
    let lastRationale = '';
    try { lastRationale = window.sessionStorage?.getItem('csms.map.lastRationale') || ''; } catch (e) { lastRationale = ''; }
    let pendingAdd = null, pendingRemove = null;
    mapTarget?.addEventListener('click', event => {
        const button = event.target.closest('.map-toggle');
        if (!button || !state.matrix || !state.matrix.editable) return;
        const row = state.matrix.rows.find(r => Number(r.placement_id) === Number(button.dataset.placement));
        const outcome = state.matrix.outcomes.find(o => Number(o.id) === Number(button.dataset.outcome));
        if (!row || !outcome) return;
        if (button.dataset.mapping) {
            pendingRemove = { mapping: Number(button.dataset.mapping), placement: row.placement_id, outcome: outcome.id };
            ui.clearFormError(el('mapRemoveModal'));
            el('mapRemoveMessage').innerHTML = '<p>Remove the mapping of</p><strong>' + esc(row.subject_code + ' \u2014 ' + row.subject_title) + '</strong><p>to</p><strong>' + esc(outcome.outcome_code) + '</strong>';
            removeModal.open(); el('mapRemoveConfirm').focus();
        } else {
            pendingAdd = { placement: row.placement_id, outcome: outcome.id };
            ui.clearFormError(el('mapAddForm'));
            const context = row.subject_code + ' ' + row.subject_title + ' \u2192 ' + outcome.outcome_code + ' ' + (outcome.outcome_text || '');
            el('mapAddContext').textContent = context; el('mapAddContext').title = context; el('mapAddExample').textContent = outcome.outcome_code;
            el('mapAddRationale').value = lastRationale;
            addModal.open(); el('mapAddRationale').focus(); el('mapAddRationale').select();
        }
    });
    el('mapAddRationale')?.addEventListener('keydown', event => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); el('mapAddForm').requestSubmit(); }
    });
    el('mapAddForm')?.addEventListener('submit', async event => {
        event.preventDefault();
        if (!pendingAdd || !state.version) return;
        const text = el('mapAddRationale').value.trim();
        if (!text) { ui.showFormError(el('mapAddForm'), 'Explain why this subject supports this outcome.'); el('mapAddRationale').focus(); return; }
        ui.clearFormError(el('mapAddForm'));
        const data = new FormData();
        data.set('action', 'add'); data.set('curriculum_version_id', String(versionIdInput.value)); data.set('placement_id', String(pendingAdd.placement)); data.set('outcome_id', String(pendingAdd.outcome)); data.set('rationale', text);
        ui.setFormSubmitting(el('mapAddForm'), true, 'Saving\u2026');
        try {
            const result = await ui.postForm(api.outcomes, data);
            lastRationale = text;
            try { window.sessionStorage?.setItem('csms.map.lastRationale', text); } catch (e) { /* storage unavailable */ }
            pendingFocus = { placement: pendingAdd.placement, outcome: pendingAdd.outcome };
            addModal.close(); ui.showToast(result.message || 'Mapping recorded.', 'success');
            await loadMatrix(versionIdInput.value, state.mapRequest);
        } catch (error) { ui.showFormError(el('mapAddForm'), error.message || 'The mapping could not be saved.'); }
        finally { ui.setFormSubmitting(el('mapAddForm'), false); }
    });
    el('mapRemoveConfirm')?.addEventListener('click', async function () {
        if (!pendingRemove) return;
        const button = this;
        ui.clearFormError(el('mapRemoveModal'));
        button.disabled = true;
        try {
            const data = new FormData();
            data.set('action', 'remove'); data.set('curriculum_version_id', String(versionIdInput.value)); data.set('mapping_id', String(pendingRemove.mapping));
            const result = await ui.postForm(api.outcomes, data);
            pendingFocus = { placement: pendingRemove.placement, outcome: pendingRemove.outcome };
            removeModal.close(); ui.showToast(result.message || 'Mapping removed.', 'success');
            await loadMatrix(versionIdInput.value, state.mapRequest);
        } catch (error) { ui.showFormError(el('mapRemoveModal'), error.message || 'The mapping could not be removed.'); }
        finally { button.disabled = false; }
    });

    async function openMappingDetails(mapping) {
        if (!mapping || !detailsModal) return;
        const request = ++state.detailRequest;
        state.selectedMapping = mapping; state.mappings.set(Number(mapping.id), mapping);
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
            const relationships = await (fetchAll(api.requirements, 'relationships', { action: 'list', curriculum_version_id: mapping.curriculum_version_id, requirement_type: 'prerequisite' }));
            if (request !== state.detailRequest) return;
            const requires = relationships.filter(row => row.requirement_type === 'prerequisite' && Number(row.subject_id) === Number(mapping.subject_id));
            el('mappingDetailsRequires').textContent = requires.length ? requires.map(row => row.required_subject_code + ' — ' + row.required_subject_title).join('; ') : 'No prerequisite rules configured.';
        } catch (error) { if (request === state.detailRequest) el('mappingDetailsRequires').textContent = 'Stored prerequisites could not be loaded.'; }
    }

    const reviewLabels = {
        placement: ['Missing / Invalid Placement', 'Indicator must be Required or Optional'],
        duplicates: ['Duplicate Placements', 'Same subject in the same version, year/grade and semester'],
        missingYear: ['Missing Year / Grade', 'Placements without a year or grade label'],
        missingSemester: ['Missing Semester', 'Placements without a semester label'],
        outcomesNoSubject: ['Outcomes with no mapped subject', 'Graduate outcomes that no placement in this version supports'],
        subjectsNoOutcome: ['Subjects mapped to no outcome', 'Placements that support none of the graduate outcomes']
    };
    const outcomeCheckKeys = ['outcomesNoSubject', 'subjectsNoOutcome'];
    const structuralKeys = ['placement', 'duplicates', 'missingYear', 'missingSemester'];
    // The shared review component (js/csms-review.js) draws the rows; this page supplies the checks as data.
    const reviewUi = window.CsmsReview.create(el('mappingReviewCards'), { esc, onAction: (action, id) => openMappingDetails(state.mappings.get(id)) });
    const placementColumns = [{ header: 'Code', code: true, cell: r => esc(r.subject_code).replaceAll('/', '/<wbr>') }, { header: 'Subject', cell: r => esc(r.subject_title) },
        { header: 'Year / Semester', cell: r => esc((r.year_level || 'No year / grade') + ' · ' + (r.semester || 'No semester')) }];
    const viewDetails = r => '<button type="button" class="csms-link" data-review-action="view" data-review-id="' + Number(r.placement_id || r.id) + '">View details<span class="sr-only"> for ' + esc(r.subject_code) + '</span></button>';
    const outcomeColumns = [{ header: 'Outcome', code: true, cell: o => esc(o.outcome_code) }, { header: 'Description', cell: o => esc(o.outcome_text) }];
    function loadReview() {
        if (!state.loaded) return;
        reviewIssues = model.review(state.allMappings); state.reviewLoaded = true; renderReviewCards();
    }
    // Structural checks always apply; the outcome-coverage checks need the outcome catalog (BSIT only) and the loaded outcomes workspace.
    function reviewState() {
        const matrix = state.matrix, issues = { ...reviewIssues };
        if (matrix && matrix.status === 'ready' && matrix.outcomes.length) Object.assign(issues, model.outcomeReview(matrix.outcomes, matrix.rows, matrix.mappings));
        return issues;
    }
    const makeCheck = (key, rows) => ({ key, label: reviewLabels[key][0], hint: reviewLabels[key][1], rows, ...(key === 'outcomesNoSubject' ? { columns: outcomeColumns } : { columns: placementColumns, actions: viewDetails }) });
    function renderReviewCards() {
        if (!reviewIssues) return;
        const issues = reviewState(), matrix = state.matrix;
        const sections = [{ title: 'Structural checks', note: 'Selected version only', checks: structuralKeys.map(key => makeCheck(key, issues[key])) }];
        if (matrix && matrix.status === 'nocatalog') sections.push({ empty: emptyBox('No outcome catalog for this program yet.', 'Graduate outcomes are loaded for BSIT only.') });
        else if (matrix && matrix.status === 'error') sections.push({ empty: '<p class="mapping-state is-error">' + esc(matrix.message) + '</p>' });
        else if (!matrix || matrix.status === 'loading') sections.push({ empty: '<p class="mapping-state">Loading outcome checks…</p>' });
        else if (!matrix.outcomes.length) sections.push({ empty: emptyBox('No graduate outcomes are loaded for this program.') });
        else sections.push({ title: 'Outcome coverage', note: 'From recorded mappings', checks: outcomeCheckKeys.map(key => makeCheck(key, issues[key])) });
        reviewUi.render(sections);
        el('mappingReviewResults').innerHTML = reviewUi.total() ? '' : '<div class="mapping-state mapping-review-success"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><div><strong>No mapping issues detected.</strong><small>Checks cover stored labels, indicators and recorded mappings. Academic correctness or compliance is not evaluated.</small>' + (!state.allMappings.length ? '<p>This version currently has no mapped subjects.</p>' : '') + '</div></div>';
    }

    function setTab(name, persist = true) {
        name = ['map','review','outcomes','course'].includes(name) ? name : 'map';
        state.currentTab = name;
        if (name !== 'map' && highlightedOutcome !== null) { highlightedOutcome = null; mapTarget.querySelectorAll('.is-focus-col').forEach(node => node.classList.remove('is-focus-col')); }
        if(!['outcomes','course'].includes(name))closeAcademicDialogs();
        if (persist) writeUrl(true);
        updateContextMessage();
        [['map', 'mappingTabMap', 'mappingPanelMap'], ['review', 'mappingTabReview', 'mappingPanelReview'], ['outcomes','mappingTabOutcomes','mappingPanelOutcomes'], ['course','mappingTabCourse','mappingPanelCourse']].forEach(([key, tabId, panelId]) => {
            const active = key === name;
            el(tabId).classList.toggle('is-active', active);
            el(tabId).setAttribute('aria-selected', String(active));
            el(tabId).tabIndex = active ? 0 : -1;
            el(panelId).hidden = !active;
        });
        if (name === 'review') loadReview(false);
        if (name === 'outcomes' || name === 'course') loadOutcomes();
    }

    const TAB_BY_ID = { mappingTabMap: 'map', mappingTabReview: 'review', mappingTabOutcomes: 'outcomes', mappingTabCourse: 'course' };
    function urlTab() {
        const params = new URLSearchParams(window.location.search);
        const name = params.has('tab') ? params.get('tab') : (params.get('outcomes') === '1' ? 'outcomes' : 'map');
        return ['map','review','outcomes','course'].includes(name) ? name : 'map';
    }
    function writeUrl(tabOnly = false) {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', state.currentTab);
        if (!tabOnly) for (const [key, input] of [['program_id',programIdInput],['curriculum_id',curriculumIdInput],['version_id',versionIdInput]]) {
            if (input.value) url.searchParams.set(key,input.value); else url.searchParams.delete(key);
        }
        ['outcomes','placement_id','subject_id'].forEach(key => url.searchParams.delete(key));
        if (url.href !== window.location.href) window.history.pushState(null,'',url);
    }
    async function initializeContext() {
        const request = ++contextRequest;
        const params = new URLSearchParams(window.location.search);
        state.program = null; state.curriculum = null; state.version = null;
        contextReset = true; mappingContextCascade.clear(); contextReset = false;
        setTab(urlTab(), false);
        updateContextMessage(); await loadMap();
        const fail = message => {
            ['mappingMap','mappingReviewResults','outcomeMatrix'].forEach(id => showPanelState(el(id),message,true));
        };
        if (!permission.programs) { fail('Program context is not available with your current permissions.'); return; }
        if (!params.get('version_id')) return;
        try {
            const version = (await ui.fetchJson(api.versions, { action: 'get', id: params.get('version_id') })).version;
            const curriculum = (await ui.fetchJson(api.curricula, { action: 'get', id: version.curriculum_id })).curriculum;
            const program = (await ui.fetchJson(api.programs, { action: 'get', id: curriculum.academic_program_id })).program;
            if (request !== contextRequest) return;
            if ((params.has('program_id') && params.get('program_id') !== String(program.id)) || (params.has('curriculum_id') && params.get('curriculum_id') !== String(curriculum.id))) throw new Error('The linked mapping context is inconsistent. Please choose a context.');
            state.program = program; state.curriculum = curriculum; state.version = version;
            mappingContextCascade.setContext([program,curriculum,version]);
            updateContextMessage(); await loadMap();
            if (request !== contextRequest) return;
            if (params.get('placement_id')) openMappingDetails(state.mappings.get(Number(params.get('placement_id'))));
            else if (params.get('subject_id')) {
                const placement = state.allMappings.find(p => Number(p.subject_id) === Number(params.get('subject_id')));
                if (placement) openMappingDetails(placement);
            }
        } catch (error) { if (request === contextRequest) fail(error.message || 'Linked curriculum context could not be loaded.'); }
    }
    window.addEventListener('popstate', initializeContext);

    el('mappingWorkspaceTabs')?.addEventListener('click', event => {
        const tab = event.target.closest('[role="tab"]');
        if (!tab) return;
        setTab(TAB_BY_ID[tab.id] || 'map');
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
        setTab(TAB_BY_ID[tab.id] || 'map');
        tab.focus();
    });
    el('mappingSubjectSearch')?.addEventListener('input', ui.debounce(renderFilteredMap, 150));
    el('mappingYearFilter').addEventListener('change', () => { state.year = el('mappingYearFilter').value; renderFilteredMap(); });
    el('mappingStatusFilter').addEventListener('change', renderFilteredMap);
    el('mappingSemesterFilter')?.addEventListener('change', renderFilteredMap);

    initializeContext();
}());
