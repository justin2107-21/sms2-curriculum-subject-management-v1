(function () {
    'use strict';

    const root = document.getElementById('subjectMappingWorkspace');
    if (!root || !window.CurriculumUI) return;
    const ui = window.CurriculumUI;
    const api = {
        programs: root.dataset.programApi,
        curricula: root.dataset.curriculumApi,
        versions: root.dataset.versionApi,
        subjects: root.dataset.subjectApi,
        mappings: root.dataset.assignmentApi
    };
    const permission = {
        programs: root.dataset.canViewPrograms === '1',
        subjects: root.dataset.canViewSubjects === '1',
        manage: root.dataset.canManageMappings === '1',
        add: root.dataset.canAddMappings === '1'
    };
    const state = {
        mappings: new Map(), allMappings: [], pendingRemoval: null,
        selectedMapping: null, currentTab: 'map', mapRequest: 0,
        reviewRequest: 0, reviewLoaded: false, yearValues: [], semesterValues: []
    };
    const el = id => document.getElementById(id);
    const programInput = el('mappingProgramInput');
    const curriculumInput = el('mappingCurriculumInput');
    const versionInput = el('mappingVersionInput');
    const programIdInput = el('mappingProgramFilter');
    const curriculumIdInput = el('mappingCurriculumFilter');
    const versionIdInput = el('mappingVersionFilter');
    const mapTarget = el('mappingMap');
    const detailDrawer = ui.createModal(el('mappingDetailsModal'));
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
    let reviewMappings = [];
    let reviewSubjects = [];
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
    function updateContextMessage() {
        const parts = contextLabels();
        el('mappingContextMessage').textContent = parts.length === 3
            ? parts.join(' / ')
            : 'Choose a program, curriculum, and version to view its map.';
        const add = el('addMappingButton');
        if (add) add.disabled = !permission.add || !versionIdInput.value;
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
    function setDatalist(id, values) {
        const list = el(id);
        if (!list) return;
        list.replaceChildren(...[...new Set(arr(values).filter(value => !isBlank(value)))].sort(sortLabel).map(value => {
            const option = document.createElement('option');
            option.value = value;
            return option;
        }));
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
                disabledPlaceholder: 'Choose program first', minimumLength: 0, showSuggestionsOnEnable: true,
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
                disabledPlaceholder: 'Choose curriculum first', minimumLength: 0, showSuggestionsOnEnable: true,
                search: async query => curriculumIdInput.value ? (await ui.fetchJson(api.versions, {
                    action: 'list', curriculum_id: curriculumIdInput.value, page: 1, per_page: 50, search: query
                })).versions : [],
                getId: item => item.id,
                format: item => ({ label: item.version_label, code: item.version_label, title: item.effective_academic_year || '' })
            }
        ],
        onChange: function (index) {
            if (contextReset) return;
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
        noResultsText: 'No matching Subject Master records found.',
        search: async query => (await ui.fetchJson(api.subjects, { action: 'list', page: 1, per_page: 50, search: query, status: '' })).subjects,
        getId: subject => subject.id,
        format: subject => ({ label: subject.subject_code + ' — ' + subject.subject_title, code: subject.subject_code, title: subject.subject_title })
    });
    const traceAutocomplete = ui.createAutocomplete({
        input: el('mappingTraceSearch'), valueInput: el('mappingTraceSubjectId'), list: el('mappingTraceOptions'),
        helpText: 'Search the Subject Master.', selectedText: 'Subject selected.', noResultsText: 'No matching subjects found.',
        search: async query => (await ui.fetchJson(api.subjects, { action: 'list', page: 1, per_page: 50, search: query, status: '' })).subjects,
        getId: item => item.id,
        format: item => ({ label: item.subject_code + ' — ' + item.subject_title, code: item.subject_code, title: item.subject_title }),
        onSelect: item => loadTrace(item),
        onClear: () => { traceRequest += 1; showPanelState(el('mappingTraceResults'), 'Choose a Subject Master record to view all its placements.'); }
    });

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
        return records;
    }

    async function loadMap() {
        const request = ++state.mapRequest;
        state.mappings.clear();
        if (!versionIdInput.value) {
            el('mappingCount').textContent = 'Select a version';
            showPanelState(mapTarget, 'Select a curriculum version to view its map.');
            return;
        }
        showPanelState(mapTarget, 'Loading curriculum map…');
        try {
            const filters = {
                curriculum_version_id: versionIdInput.value,
                year_level: el('mappingYearFilter').value,
                semester: el('mappingSemesterFilter').value,
                search: el('mappingSubjectSearch').value.trim()
            };
            const mappings = await fetchAll(api.mappings, 'mappings', { action: 'mapping_list', ...filters });
            if (request !== state.mapRequest) return;
            const allForVersion = await fetchAll(api.mappings, 'mappings', {
                action: 'mapping_list', curriculum_version_id: versionIdInput.value
            });
            if (request !== state.mapRequest) return;
            state.mappings = new Map(mappings.map(item => [Number(item.id), item]));
            state.allMappings = allForVersion;
            state.yearValues = [...new Set(allForVersion.map(item => item.year_level).filter(value => !isBlank(value)))].sort(sortLabel);
            state.semesterValues = [...new Set(allForVersion.map(item => item.semester).filter(value => !isBlank(value)))].sort(sortLabel);
            setSelectOptions(el('mappingYearFilter'), state.yearValues, 'All Years', filters.year_level);
            setSelectOptions(el('mappingSemesterFilter'), state.semesterValues, 'All Semesters', filters.semester);
            setDatalist('mappingYearOptions', state.yearValues);
            setDatalist('mappingSemesterOptions', state.semesterValues);
            el('mappingCount').textContent = mappings.length + (mappings.length === 1 ? ' placement' : ' placements');
            renderMap(mappings);
        } catch (error) {
            if (request !== state.mapRequest) return;
            el('mappingCount').textContent = 'Unavailable';
            showPanelState(mapTarget, error.message || 'Curriculum map could not be loaded.', true);
        }
    }

    function renderMap(mappings) {
        if (!mappings.length) {
            showPanelState(mapTarget, el('mappingSubjectSearch').value.trim() || el('mappingYearFilter').value || el('mappingSemesterFilter').value
                ? 'No subject placements match these filters.' : 'This curriculum version has no subject placements yet.');
            return;
        }
        const years = new Map();
        mappings.forEach(mapping => {
            const year = isBlank(mapping.year_level) ? 'Missing Year Level' : String(mapping.year_level);
            const semester = isBlank(mapping.semester) ? 'Missing Semester' : String(mapping.semester);
            if (!years.has(year)) years.set(year, new Map());
            const semesters = years.get(year);
            if (!semesters.has(semester)) semesters.set(semester, []);
            semesters.get(semester).push(mapping);
        });
        mapTarget.innerHTML = [...years.entries()].sort((a, b) => sortLabel(a[0], b[0])).map(([year, semesters]) => {
            const yearMappings = [...semesters.values()].flat();
            const yearUnits = yearMappings.reduce((sum, item) => sum + (Number(item.units) || 0), 0);
            const semesterPanels = [...semesters.entries()].sort((a, b) => sortLabel(a[0], b[0])).map(([semester, items]) => {
                items.sort((a, b) => sortLabel(a.subject_code, b.subject_code));
                const units = items.reduce((sum, item) => sum + (Number(item.units) || 0), 0);
                return '<section class="mapping-semester-panel"><header><div><h3>' + esc(semester) + '</h3><span>' + items.length + ' Subjects · ' + ui.formatUnits(units) + ' Units</span></div></header><div class="mapping-subject-list">' +
                    items.map(mappingCard).join('') + '</div></section>';
            }).join('');
            return '<section class="mapping-year-section"><header class="mapping-year-header"><h2>' + esc(year) + '</h2><span>' + yearMappings.length + ' Subjects · ' + ui.formatUnits(yearUnits) + ' Units</span></header><div class="mapping-semester-grid">' + semesterPanels + '</div></section>';
        }).join('');
    }

    function mappingCard(mapping) {
        const required = Number(mapping.is_required) === 1;
        return '<button type="button" class="mapping-subject-card" data-open-mapping="' + Number(mapping.id) + '" aria-label="View placement for ' + esc(mapping.subject_code + ' ' + mapping.subject_title) + '">' +
            '<span class="mapping-card-main"><strong>' + esc(mapping.subject_code) + '</strong><span>' + esc(mapping.subject_title) + '</span></span>' +
            '<span class="mapping-card-meta"><strong>' + esc(ui.formatUnits(mapping.units)) + ' Units</strong><span class="placement-indicator ' + (required ? 'placement-required' : 'placement-optional') + '">' + (required ? 'Required' : 'Optional') + '</span></span></button>';
    }

    function openMappingDetails(mapping) {
        if (!mapping || !detailDrawer) return;
        state.selectedMapping = mapping;
        state.mappings.set(Number(mapping.id), mapping);
        el('mappingDetailsCode').textContent = mapping.subject_code || 'Subject';
        el('mappingDetailsTitle').textContent = mapping.subject_title || '';
        el('mappingDetailsCurriculum').textContent = mapping.curriculum_code + ' — ' + mapping.curriculum_name;
        el('mappingDetailsVersion').textContent = mapping.version_label || '—';
        el('mappingDetailsYear').textContent = mapping.year_level || 'Not set';
        el('mappingDetailsSemester').textContent = mapping.semester || 'Not set';
        el('mappingDetailsPlacement').textContent = Number(mapping.is_required) === 1 ? 'Required' : 'Optional';
        el('mappingDetailsPlacement').className = 'placement-indicator ' + (Number(mapping.is_required) === 1 ? 'placement-required' : 'placement-optional');
        el('mappingDetailsUnits').textContent = ui.formatUnits(mapping.units) + ' Units';
        detailDrawer.open();
    }

    function prepareEdit(mapping) {
        if (!mapping || !permission.manage || !editForm || !editModal) return;
        editForm.reset();
        ui.clearFormError(editForm);
        el('editMappingId').value = mapping.id;
        el('editMappingContext').textContent = [mapping.program_code, mapping.curriculum_code, mapping.version_label].join(' / ');
        el('editMappingSubject').textContent = mapping.subject_code + ' — ' + mapping.subject_title;
        el('editMappingVersion').textContent = mapping.curriculum_code + ' — ' + mapping.version_label;
        editYear.value = mapping.year_level || '';
        editSemester.value = mapping.semester || '';
        el('editMappingSequence').value = mapping.sequence_order == null ? '' : mapping.sequence_order;
        el('editMappingRequired').value = Number(mapping.is_required) === 1 ? '1' : '0';
        detailDrawer?.close();
        editModal.open();
        editYear.focus();
    }
    function requestRemoval(mapping) {
        if (!mapping || !permission.manage || !removeModal) return;
        state.pendingRemoval = mapping;
        ui.clearFormError(el('removeMappingModal'));
        el('removeMappingMessage').textContent = 'Remove ' + mapping.subject_code + ' — ' + mapping.subject_title + ' from ' + mapping.curriculum_code + ' / ' + mapping.version_label + '? Only this placement will be removed.';
        detailDrawer?.close();
        removeModal.open();
        el('confirmRemoveMapping').focus();
    }

    async function openAddMapping() {
        if (!permission.add || !versionIdInput.value || !addForm || !addModal) return;
        addForm.reset();
        ui.clearFormError(addForm);
        el('addMappingVersionId').value = versionIdInput.value;
        el('addMappingContext').textContent = contextLabels().join(' / ');
        addSubjectAutocomplete?.clear();
        setDatalist('mappingYearOptions', state.yearValues);
        setDatalist('mappingSemesterOptions', state.semesterValues);
        addModal.open();
        addSubjectInput.focus();
    }

    async function loadTrace(subject) {
        const request = ++traceRequest;
        const target = el('mappingTraceResults');
        if (!permission.subjects) { showPanelState(target, 'Subject Trace requires Subject Master view permission.'); return; }
        showPanelState(target, 'Loading stored placements…');
        try {
            const records = await fetchAll(api.mappings, 'mappings', { action: 'mapping_list', search: subject.subject_code });
            if (request !== traceRequest) return;
            const usage = records.filter(item => Number(item.subject_id) === Number(subject.id));
            usage.forEach(item => state.mappings.set(Number(item.id), item));
            const rows = usage.length ? usage.map(item => '<button type="button" class="mapping-trace-row" data-open-trace-mapping="' + Number(item.id) + '"><span><strong>' + esc(item.program_code) + '</strong><small>' + esc(item.curriculum_code + ' · ' + item.version_label) + '</small></span><span>' + esc(item.year_level || 'Not set') + ' · ' + esc(item.semester || 'Not set') + '</span><span class="placement-indicator ' + (Number(item.is_required) === 1 ? 'placement-required' : 'placement-optional') + '">' + (Number(item.is_required) === 1 ? 'Required' : 'Optional') + '</span></button>').join('') : '<p class="mapping-state">No curriculum placements are stored for this subject.</p>';
            target.innerHTML = '<article class="mapping-trace-subject"><div><span>Subject</span><h2>' + esc(subject.subject_code) + '</h2><strong>' + esc(subject.subject_title) + '</strong></div><span class="mapping-unit-chip">' + esc(ui.formatUnits(subject.units)) + ' Units</span></article><section class="mapping-trace-usage"><header><h3>Current Usage</h3><span>' + usage.length + (usage.length === 1 ? ' placement' : ' placements') + '</span></header>' + rows + '</section>';
        } catch (error) {
            if (request === traceRequest) showPanelState(target, error.message || 'Subject usage could not be loaded.', true);
        }
    }

    async function loadReview(force) {
        if (state.reviewLoaded && !force) return;
        if (!permission.subjects) {
            showPanelState(el('mappingReviewCards'), 'Mapping Review requires Subject Master view permission.');
            showPanelState(el('mappingReviewResults'), 'No issue details are available with your current permissions.');
            return;
        }
        const request = ++state.reviewRequest;
        showPanelState(el('mappingReviewCards'), 'Checking stored mappings…');
        showPanelState(el('mappingReviewResults'), 'Select a check to inspect affected records.');
        try {
            const [subjects, mappings] = await Promise.all([
                fetchAll(api.subjects, 'subjects', { action: 'list', status: '' }),
                fetchAll(api.mappings, 'mappings', { action: 'mapping_list' })
            ]);
            if (request !== state.reviewRequest) return;
            reviewSubjects = subjects;
            reviewMappings = mappings;
            state.allMappings = mappings;
            mappings.forEach(mapping => state.mappings.set(Number(mapping.id), mapping));
            const mappedIds = new Set(mappings.map(item => Number(item.subject_id)));
            const unmapped = subjects.filter(item => !mappedIds.has(Number(item.id)));
            const groups = new Map();
            mappings.forEach(mapping => {
                const key = Number(mapping.curriculum_version_id) + ':' + Number(mapping.subject_id);
                if (!groups.has(key)) groups.set(key, []);
                groups.get(key).push(mapping);
            });
            const duplicates = [...groups.values()].filter(items => items.length > 1).flat();
            const missingYear = mappings.filter(item => isBlank(item.year_level));
            const missingSemester = mappings.filter(item => isBlank(item.semester));
            reviewIssues = { unmapped, duplicates, missingYear, missingSemester };
            state.reviewLoaded = true;
            renderReviewCards();
        } catch (error) {
            if (request !== state.reviewRequest) return;
            showPanelState(el('mappingReviewCards'), error.message || 'Mapping checks could not be loaded.', true);
        }
    }
    function renderReviewCards() {
        const checks = [
            ['unmapped', 'Unmapped Subjects', reviewIssues.unmapped.length, 'Subject Master records with no placement anywhere'],
            ['duplicates', 'Duplicate Placements', reviewIssues.duplicates.length, 'Repeated subject/version placement rows'],
            ['missingYear', 'Missing Year Level', reviewIssues.missingYear.length, 'Placements with a blank year'],
            ['missingSemester', 'Missing Semester', reviewIssues.missingSemester.length, 'Placements with a blank semester']
        ];
        el('mappingReviewCards').innerHTML = checks.map(([key, label, count, hint]) => '<button type="button" class="mapping-review-card" data-review-issue="' + key + '"><strong>' + count + '</strong><span>' + label + '</span><small>' + hint + '</small></button>').join('');
        const total = checks.reduce((sum, [, , count]) => sum + count, 0);
        if (total === 0) showPanelState(el('mappingReviewResults'), 'No mapping issues found.');
    }
    function showReviewIssue(key) {
        if (!reviewIssues) return;
        const items = reviewIssues[key] || [];
        const target = el('mappingReviewResults');
        const labels = { unmapped: 'Unmapped Subjects', duplicates: 'Duplicate Placements', missingYear: 'Missing Year Level', missingSemester: 'Missing Semester' };
        if (!items.length) { showPanelState(target, 'No ' + labels[key].toLowerCase() + ' found.'); return; }
        if (key === 'unmapped') {
            target.innerHTML = '<section class="mapping-issue-list"><h3>' + labels[key] + ' <span>' + items.length + '</span></h3>' + items.map(item => '<div class="mapping-issue-row"><strong>' + esc(item.subject_code) + '</strong><span>' + esc(item.subject_title) + '</span><small>' + esc(ui.formatUnits(item.units)) + ' Units · No stored placement</small></div>').join('') + '</section>';
            return;
        }
        target.innerHTML = '<section class="mapping-issue-list"><h3>' + labels[key] + ' <span>' + items.length + '</span></h3>' + items.map(item => '<button type="button" class="mapping-issue-row" data-open-review-mapping="' + Number(item.id) + '"><strong>' + esc(item.subject_code) + '</strong><span>' + esc(item.subject_title) + '</span><small>' + esc(item.program_code + ' · ' + item.curriculum_code + ' · ' + item.version_label) + ' · ' + esc(item.year_level || 'No year') + ' · ' + esc(item.semester || 'No semester') + '</small></button>').join('') + '</section>';
    }

    function setTab(name) {
        state.currentTab = name;
        [['map', 'mappingTabMap', 'mappingPanelMap'], ['trace', 'mappingTabTrace', 'mappingPanelTrace'], ['review', 'mappingTabReview', 'mappingPanelReview']].forEach(([key, tabId, panelId]) => {
            const active = key === name;
            el(tabId).classList.toggle('is-active', active);
            el(tabId).setAttribute('aria-selected', String(active));
            el(tabId).tabIndex = active ? 0 : -1;
            el(panelId).hidden = !active;
        });
        if (name === 'review') loadReview(false);
    }

    async function initializeContext() {
        updateContextMessage();
        if (!permission.programs) { showPanelState(mapTarget, 'Program context is not available with your current permissions.'); return; }
        try {
            const response = await ui.fetchJson(api.programs, { action: 'list', page: 1, per_page: 50, search: '', status: '' });
            const programs = arr(response.programs);
            const program = programs.find(item => String(item.status).toLowerCase() === 'active') || programs[0];
            if (!program) { showPanelState(mapTarget, 'No academic programs are available.'); return; }
            programAutocomplete.setSelected(program, false);
            curriculumInput.disabled = false;
            curriculumInput.placeholder = 'Search curriculum';
            const curriculumResponse = await ui.fetchJson(api.curricula, {
                action: 'list', page: 1, per_page: 50, search: '', status: '', academic_program_id: program.id
            });
            const curricula = arr(curriculumResponse.curricula);
            const curriculum = curricula.find(item => String(item.status).toLowerCase() === 'active') || curricula[0];
            if (!curriculum) { updateContextMessage(); showPanelState(mapTarget, 'This program has no curricula.'); return; }
            curriculumAutocomplete.setSelected(curriculum, false);
            versionInput.disabled = false;
            versionInput.placeholder = 'Search version';
            const versionResponse = await ui.fetchJson(api.versions, { action: 'list', curriculum_id: curriculum.id, page: 1, per_page: 50, search: '' });
            const versions = arr(versionResponse.versions);
            const version = versions.find(item => String(item.status).toLowerCase() === 'active') || versions[0];
            if (version) versionAutocomplete.setSelected(version, false);
            updateContextMessage();
            await loadMap();
        } catch (error) {
            showPanelState(mapTarget, error.message || 'Current curriculum context could not be loaded.', true);
        }
    }

    el('mappingWorkspaceTabs')?.addEventListener('click', event => {
        const tab = event.target.closest('[role="tab"]');
        if (!tab) return;
        setTab(tab.id === 'mappingTabMap' ? 'map' : tab.id === 'mappingTabTrace' ? 'trace' : 'review');
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
        setTab(tab.id === 'mappingTabMap' ? 'map' : tab.id === 'mappingTabTrace' ? 'trace' : 'review');
        tab.focus();
    });
    mapTarget?.addEventListener('click', event => {
        const card = event.target.closest('[data-open-mapping]');
        if (card) openMappingDetails(state.mappings.get(Number(card.dataset.openMapping)));
    });
    el('mappingTraceResults')?.addEventListener('click', event => {
        const row = event.target.closest('[data-open-trace-mapping]');
        if (row) openMappingDetails(state.mappings.get(Number(row.dataset.openTraceMapping)));
    });
    el('mappingReviewCards')?.addEventListener('click', event => {
        const card = event.target.closest('[data-review-issue]');
        if (card) showReviewIssue(card.dataset.reviewIssue);
    });
    el('mappingReviewResults')?.addEventListener('click', event => {
        const row = event.target.closest('[data-open-review-mapping]');
        if (row) openMappingDetails(state.mappings.get(Number(row.dataset.openReviewMapping)));
    });
    el('mappingDetailsEdit')?.addEventListener('click', () => prepareEdit(state.selectedMapping));
    el('mappingDetailsRemove')?.addEventListener('click', () => requestRemoval(state.selectedMapping));
    el('addMappingButton')?.addEventListener('click', openAddMapping);
    el('mappingSubjectSearch')?.addEventListener('input', ui.debounce(loadMap, 250));
    el('mappingYearFilter')?.addEventListener('change', loadMap);
    el('mappingSemesterFilter')?.addEventListener('change', loadMap);

    addForm?.addEventListener('submit', async event => {
        event.preventDefault();
        if (!addSubjectAutocomplete?.hasSelection()) {
            ui.showFormError(addForm, 'Select an existing Subject Master record.');
            addSubjectInput.focus();
            return;
        }
        if (!addForm.reportValidity()) return;
        ui.clearFormError(addForm);
        const data = new FormData(addForm);
        data.set('action', 'assign');
        ui.setFormSubmitting(addForm, true, 'Saving…');
        try {
            const result = await ui.postForm(api.mappings, data);
            addModal.close();
            ui.showToast(result.message || 'Subject mapped.', 'success');
            await loadMap();
            state.reviewLoaded = false;
            if (state.currentTab === 'review') loadReview(true);
        } catch (error) {
            ui.showFormError(addForm, error.message || 'Subject mapping could not be saved.');
        } finally { ui.setFormSubmitting(addForm, false); }
    });
    editForm?.addEventListener('submit', async event => {
        event.preventDefault();
        if (!editForm.reportValidity()) return;
        ui.clearFormError(editForm);
        const data = new FormData(editForm);
        data.set('action', 'update');
        ui.setFormSubmitting(editForm, true, 'Saving…');
        try {
            const result = await ui.postForm(api.mappings, data);
            editModal.close();
            ui.showToast(result.message || 'Mapping updated.', 'success');
            await loadMap();
            state.reviewLoaded = false;
            if (state.currentTab === 'review') loadReview(true);
        } catch (error) {
            ui.showFormError(editForm, error.message || 'Mapping could not be updated.');
        } finally { ui.setFormSubmitting(editForm, false); }
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
            state.reviewLoaded = false;
            if (state.currentTab === 'review') loadReview(true);
        } catch (error) {
            ui.showFormError(el('removeMappingModal'), error.message || 'Mapping could not be removed.');
        } finally { button.disabled = false; }
    });

    setTab('map');
    initializeContext();
}());
