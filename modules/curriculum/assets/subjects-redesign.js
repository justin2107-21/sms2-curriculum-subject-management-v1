(function () {
    'use strict';

    const root = document.getElementById('subjectWorkspace');
    if (!root || !window.CurriculumUI) return;
    const ui = window.CurriculumUI;
    const subjectApi = root.dataset.subjectApi;
    const assignmentApi = root.dataset.assignmentApi;
    const programApi = root.dataset.programApi;
    const curriculumApi = root.dataset.curriculumApi;
    const versionApi = root.dataset.versionApi;
    const programView = root.dataset.isProgramView === '1';
    const selectedProgramId = root.dataset.selectedProgramId || '';
    const canBrowse = root.dataset.canViewPlacementContext === '1';
    const canCreate = root.dataset.canCreateSubjects === '1' && root.dataset.canAssignCreatedSubject === '1';
    const canUpdate = root.dataset.canUpdateSubjects === '1';
    const sections = document.getElementById('subjectProgramSections');
    const detailsModal = ui.createModal(document.getElementById('subjectDetailsModal'));
    const archiveModal = ui.createModal(document.getElementById('subjectArchiveModal'));
    const subjectModal = ui.createModal(document.getElementById('subjectModal'));
    const form = document.getElementById('subjectForm');

    // Bind the primary action before initializing the optional placement selectors.
    // The button itself is only rendered by PHP when all create/placement permissions
    // are granted, so this handler can safely serve either page variant.
    root.addEventListener('click', function (event) {
        const button = event.target.closest('#newSubjectButton');
        if (!button) return;
        event.preventDefault();
        openCreateSubject();
    });
    let programRecords = [];
    let currentProgram = null;
    let currentCurricula = [];
    let currentVersions = [];
    let currentSubjects = [];
    let viewingSubjectId = null;
    let viewingSubject = null;
    let currentMode = 'create';
    let legacyLectureUnits = '';
    let legacyLaboratoryUnits = '';
    let detailSequence = 0;
    let viewSequence = 0;
    let savedSubjectWithoutPlacement = false;
    let assignmentSequence = 0;
    let versionsSequence = 0;
    let category = 'all';
    let yearTabValue = '';

    const assignmentSection = document.getElementById('subjectAssignmentSection');
    const assignmentProgramInput = document.getElementById('subjectAssignmentProgramInput');
    const assignmentCurriculumInput = document.getElementById('subjectAssignmentCurriculumInput');
    const assignmentVersionInput = document.getElementById('subjectAssignmentVersionInput');
    const assignmentYear = document.getElementById('subjectAssignmentYear');
    const assignmentSemester = document.getElementById('subjectAssignmentSemester');
    let selectedAssignmentProgram = null;

    const assignmentControlsReady = canCreate && assignmentSection && assignmentYear && assignmentSemester
        && document.getElementById('subjectAssignmentRequired');
    const assignmentCascade = assignmentControlsReady ? ui.createCascadingAutocomplete({
        levels: [
            {
                input: assignmentProgramInput, valueInput: document.getElementById('subjectAssignmentProgramId'),
                list: document.getElementById('subjectAssignmentProgramOptions'),
                helpText: 'Choose an existing program or strand.', selectedText: 'Program or strand selected.',
                noResultsText: 'No matching programs or strands found.', placeholder: 'Search program code or name',
                search: async query => (await ui.fetchJson(programApi, { action: 'list', page: 1, per_page: 50, search: query, status: '' })).programs,
                getId: item => item.id,
                format: item => ({ label: item.program_code + ' — ' + item.program_name, code: item.program_code, title: item.program_name })
            },
            {
                input: assignmentCurriculumInput, valueInput: document.getElementById('subjectAssignmentCurriculumId'),
                list: document.getElementById('subjectAssignmentCurriculumOptions'),
                helpText: 'Choose a curriculum for the selected program or strand.', selectedText: 'Curriculum selected.',
                noResultsText: 'No curricula found for this program.', placeholder: 'Search curriculum code or name',
                disabledPlaceholder: 'Select a program or strand first', minimumLength: 0, showSuggestionsOnEnable: true,
                search: async query => {
                    const id = assignmentCascade?.get(0)?.getSelectedId();
                    return id ? (await ui.fetchJson(curriculumApi, { action: 'list', page: 1, per_page: 50, search: query, status: '', academic_program_id: id })).curricula : [];
                },
                getId: item => item.id,
                format: item => ({ label: item.curriculum_code + ' — ' + item.curriculum_name, code: item.curriculum_code, title: item.curriculum_name })
            },
            {
                input: assignmentVersionInput, valueInput: document.getElementById('subjectAssignmentVersionId'),
                list: document.getElementById('subjectAssignmentVersionOptions'),
                helpText: 'Choose a version for the selected curriculum.', selectedText: 'Curriculum version selected.',
                noResultsText: 'No curriculum versions found.', placeholder: 'Search version label or academic year',
                disabledPlaceholder: 'Select a curriculum first', minimumLength: 0, showSuggestionsOnEnable: true,
                search: async query => {
                    const id = assignmentCascade?.get(1)?.getSelectedId();
                    return id ? (await ui.fetchJson(versionApi, { action: 'list', curriculum_id: id, page: 1, per_page: 50, search: query })).versions : [];
                },
                getId: item => item.id,
                format: item => ({ label: item.version_label, code: item.version_label, title: item.effective_academic_year || item.status })
            }
        ],
        onChange: function (index, item, id, action) {
            if (index === 0) {
                selectedAssignmentProgram = action === 'select' ? item : null;
                updateAssignmentYearLabel();
                resetAssignmentValues();
            } else if (index === 1 || action !== 'select') {
                resetAssignmentValues();
            }
            if (index === 2 && action === 'select') loadAssignmentValues(id);
        }
    }) : null;
    const programAutocomplete = assignmentCascade?.get(0);
    const curriculumAutocomplete = assignmentCascade?.get(1);
    const versionAutocomplete = assignmentCascade?.get(2);

    function escape(value) { return ui.escapeHtml(value == null ? '' : String(value)); }
    function asArray(value) { return Array.isArray(value) ? value : []; }
    function programIsShs(item) {
        const type = String(item?.program_type || '').toLowerCase();
        return type.includes('senior high') || type === 'shs' || type.includes('strand');
    }
    function sortLabel(a, b) {
        return String(a || '').localeCompare(String(b || ''), undefined, { numeric: true, sensitivity: 'base' });
    }
    function setOptions(select, values, placeholder, fallback) {
        if (!select) return;
        const selected = select.value;
        select.replaceChildren(new Option(placeholder, ''));
        (values.length ? values : (fallback || [])).forEach(value => select.add(new Option(value, value)));
        if (Array.from(select.options).some(option => option.value === selected)) select.value = selected;
    }
    function showError(target, message) {
        if (!target) return;
        target.textContent = message;
        target.hidden = false;
    }
    function statusChip(status) { return ui.statusBadge(status); }

    async function loadPrograms() {
        if (!sections || !canBrowse) return;
        sections.innerHTML = '<p class="subtle-text">Loading programs…</p>';
        try {
            const response = await ui.fetchJson(subjectApi, {
                action: 'contexts', page: 1, per_page: 50, academic_program_id: '', curriculum_id: ''
            });
            programRecords = asArray(response.programs);
            renderCards();
        } catch (error) {
            sections.innerHTML = '<p class="subject-empty-state">' + escape(error.message || 'Programs could not be loaded.') + '</p>';
        }
    }

    function renderCards() {
        if (!sections) return;
        const query = document.getElementById('subjectCardSearch')?.value.trim().toLowerCase() || '';
        const cardPrograms = ui.searchRecords(programRecords, query, item => [item.program_code, item.program_name]).filter(item => {
            const matchesCategory = category === 'all' || (category === 'shs' ? programIsShs(item) : !programIsShs(item));
            return matchesCategory;
        });
        const finish = function () {
            const withSubjectSearch = cardPrograms;
            const college = withSubjectSearch.filter(item => !programIsShs(item));
            const shs = withSubjectSearch.filter(programIsShs);
            sections.innerHTML = [
                sectionMarkup('College Degree Programs', 'Stored college program records', college, 'college'),
                sectionMarkup('Senior High School Strands', 'Stored Senior High School offerings', shs, 'shs')
            ].filter(Boolean).join('<div class="subject-section-divider"></div>');
        };
        finish();
    }

    function sectionMarkup(title, subtitle, programs, type) {
        if (category !== 'all' && category !== type) return '';
        const cards = programs.length ? programs.map(item => {
            const label = item.card_context_label || (Number(item.curriculum_count) ? Number(item.curriculum_count) + ' curricula' : 'No curriculum yet');
            return '<a class="subject-program-card" href="subjects.php?program_id=' + Number(item.id) + '" aria-label="Browse ' + escape(item.program_code + ' ' + item.program_name) + '">' +
                '<span class="subject-program-icon" aria-hidden="true"><i class="fa-solid ' + (type === 'shs' ? 'fa-book-open' : 'fa-graduation-cap') + '" aria-hidden="true"></i></span>' +
                '<strong class="program-code-pill">' + escape(item.program_code) + '</strong><span class="subject-program-name">' + escape(item.program_name) + '</span>' +
                '<span class="subject-program-meta"><small>' + Number(item.subject_count || 0) + ' Subjects</small><small>' + escape(label) + '</small><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span></a>';
        }).join('') : '<p class="subject-empty-state">' + (type === 'shs' ? 'No Senior High School strands are currently recorded.' : 'No college programs match this search.') + '</p>';
        return '<section class="subject-program-section" data-program-category="' + type + '"><header><div><h2>' + title + '</h2><p>' + subtitle + '</p></div><span class="subject-section-count">' + programs.length + '</span></header><div class="subject-program-grid">' + cards + '</div></section>';
    }

    async function loadProgramView() {
        if (!programView || !canBrowse) return;
        try {
            const result = await ui.fetchJson(programApi, { action: 'get', id: selectedProgramId });
            currentProgram = result.program;
            document.getElementById('subjectProgramTitle').textContent = currentProgram.program_code;
            document.getElementById('subjectProgramBreadcrumb').textContent = currentProgram.program_code;
            document.getElementById('subjectProgramName').textContent = currentProgram.program_name;
            document.getElementById('subjectProgramSummary').textContent = 'Loading curriculum summary…';
            await loadProgramContexts();
        } catch (error) {
            document.getElementById('subjectProgramTitle').textContent = 'Program unavailable';
            document.getElementById('subjectProgramName').textContent = error.message || 'This program could not be loaded.';
        }
    }

    async function loadProgramContexts() {
        const response = await ui.fetchJson(subjectApi, {
            action: 'contexts', page: 1, per_page: 50, academic_program_id: selectedProgramId
        });
        currentCurricula = asArray(response.curricula);
        const select = document.getElementById('subjectCurriculumSelect');
        select.replaceChildren(new Option('Select curriculum', ''));
        currentCurricula.forEach(item => select.add(new Option(item.curriculum_code + ' — ' + item.curriculum_name, item.id)));
        if (currentCurricula.length === 1) {
            select.value = String(currentCurricula[0].id);
            await loadProgramVersions();
        } else if (!currentCurricula.length) {
            document.getElementById('subjectGroupedList').innerHTML = '<p class="subject-empty-state">No curriculum is associated with this program yet.</p>';
            document.getElementById('subjectProgramSummary').textContent = '0 Subjects';
        } else {
            document.getElementById('subjectProgramSummary').textContent = 'Select a curriculum and version to browse its subjects.';
        }
    }

    async function loadProgramVersions() {
        const sequence = ++versionsSequence;
        viewSequence += 1;
        const curriculumId = document.getElementById('subjectCurriculumSelect').value;
        const select = document.getElementById('subjectVersionSelect');
        select.replaceChildren(new Option('Select version', ''));
        select.disabled = true;
        currentSubjects = [];
        renderGroupedSubjects();
        if (!curriculumId) return;
        const response = await ui.fetchJson(subjectApi, {
            action: 'contexts', page: 1, per_page: 50, academic_program_id: selectedProgramId, curriculum_id: curriculumId
        });
        if (sequence !== versionsSequence) return;
        currentVersions = asArray(response.versions);
        currentVersions.forEach(item => select.add(new Option(item.version_label, item.id)));
        select.disabled = currentVersions.length === 0;
        if (currentVersions.length === 1) {
            select.value = String(currentVersions[0].id);
            await loadVersionSubjects();
        } else {
            document.getElementById('subjectProgramSummary').textContent = currentVersions.length ? 'Select a curriculum version to browse its subjects.' : 'No versions are available for this curriculum.';
        }
    }

    async function loadVersionSubjects() {
        const request = ++viewSequence;
        const versionId = document.getElementById('subjectVersionSelect').value;
        if (!versionId) { currentSubjects = []; renderGroupedSubjects(); return; }
        const subjects = [];
        let page = 1;
        let pages = 1;
        try {
            do {
                const response = await ui.fetchJson(subjectApi, {
                    action: 'list', page, per_page: 50, search: '', status: '',
                    academic_program_id: selectedProgramId,
                    curriculum_id: document.getElementById('subjectCurriculumSelect').value,
                    curriculum_version_id: versionId
                });
                if (request !== viewSequence) return;
                subjects.push(...response.subjects);
                pages = Number(response.pagination.total_pages) || 1;
                page += 1;
            } while (page <= pages);
            if (request !== viewSequence) return;
            currentSubjects = subjects;
            updateYearOptions();
            const totalUnits = subjects.reduce((sum, item) => sum + (Number(item.units) || 0), 0);
            document.getElementById('subjectProgramSummary').textContent = subjects.length + ' Subjects · ' + ui.formatUnits(totalUnits) + ' Units';
            renderGroupedSubjects();
        } catch (error) {
            if (request !== viewSequence) return;
            document.getElementById('subjectGroupedList').innerHTML = '<p class="subject-empty-state">' + escape(error.message || 'Subject placements could not be loaded.') + '</p>';
        }
    }

    function updateYearOptions() {
        const contexts = currentSubjects.flatMap(placementContexts);
        const yearValues = [...new Set(contexts.map(context => context.year_level).filter(Boolean))].sort(sortLabel);
        const semesterValues = [...new Set(contexts.map(context => context.semester).filter(Boolean))].sort(sortLabel);
        if (!yearValues.includes(yearTabValue)) yearTabValue = '';
        setOptions(document.getElementById('subjectYearFilter'), yearValues, 'All year levels');
        setOptions(document.getElementById('subjectSemesterFilter'), semesterValues, 'All semesters');
        const tabs = document.getElementById('subjectYearTabs');
        tabs.innerHTML = '<button type="button" class="' + (!yearTabValue ? 'is-active' : '') + '" data-year="" aria-pressed="' + (!yearTabValue) + '">All Years</button>' + yearValues.map(value =>
            '<button type="button" class="' + (yearTabValue === value ? 'is-active' : '') + '" data-year="' + escape(value) + '" aria-pressed="' + (yearTabValue === value) + '">' + escape(value) + '</button>').join('');
    }

    function placementContexts(subject) {
        const values = asArray(subject.placement_contexts);
        let contexts = values;
        if (!contexts.length) {
            const term = String(asArray(subject.placement_context)[0] || '');
            const parts = term.split(' / ');
            contexts = parts.length >= 2 ? [{ year_level: parts[0], semester: parts[1] }] : [];
        }
        return contexts.map(context => ({
            ...context,
            year_level: isPreoral2026Version() && /^3rd\s*yr$/i.test(String(context.year_level || '').trim())
                ? '3rd Year'
                : context.year_level
        }));
    }

    function isPreoral2026Version() {
        const select = document.getElementById('subjectVersionSelect');
        return select?.selectedOptions[0]?.textContent.trim().toUpperCase() === 'PREORAL-2026';
    }

    function renderGroupedSubjects() {
        const target = document.getElementById('subjectGroupedList');
        if (!target) return;
        if (!document.getElementById('subjectVersionSelect')?.value) {
            target.innerHTML = '<p class="subject-empty-state">Select a curriculum version to view its subject placements.</p>';
            return;
        }
        const query = document.getElementById('subjectSearch')?.value.trim().toLowerCase() || '';
        const status = document.getElementById('subjectStatus')?.value || '';
        const year = document.getElementById('subjectYearFilter')?.value || yearTabValue;
        const semester = document.getElementById('subjectSemesterFilter')?.value || '';
        const groups = new Map();
        ui.searchRecords(currentSubjects, query, subject => [subject.subject_code, subject.subject_title]).forEach(subject => {
            if (status && subject.status !== status) return;
            placementContexts(subject).forEach(context => {
                if (year && context.year_level !== year) return;
                if (semester && context.semester !== semester) return;
                const key = String(context.year_level || 'Unspecified') + '\0' + String(context.semester || 'Unspecified');
                if (!groups.has(key)) groups.set(key, { year: context.year_level || 'Unspecified', semester: context.semester || 'Unspecified', subjects: new Map() });
                groups.get(key).subjects.set(String(subject.id), subject);
            });
        });
        const ordered = [...groups.values()].sort((a, b) => sortLabel(a.year, b.year) || sortLabel(a.semester, b.semester));
        if (!ordered.length) { target.innerHTML = '<p class="subject-empty-state">No subjects match the selected filters.</p>'; return; }
        const byYear = new Map();
        ordered.forEach(group => {
            if (!byYear.has(group.year)) byYear.set(group.year, []);
            byYear.get(group.year).push(group);
        });
        target.innerHTML = [...byYear.entries()].map(([yearLabel, semesterGroups]) => {
            const unique = new Map(); semesterGroups.forEach(group => group.subjects.forEach((subject, id) => unique.set(id, subject)));
            return '<section class="subject-year-group"><header class="subject-year-heading"><h2>' + escape(yearLabel) + '</h2><span>' + unique.size + (unique.size === 1 ? ' subject' : ' subjects') + '</span></header>' +
                semesterGroups.map(group => {
                    const items = ui.searchRecords([...group.subjects.values()], query, subject => [subject.subject_code, subject.subject_title]);
                    const units = items.reduce((sum, item) => sum + (Number(item.units) || 0), 0);
                    return '<section class="subject-semester-card"><header><div><h3>' + escape(group.semester) + '</h3><span>' + items.length + ' Subjects · ' + ui.formatUnits(units) + ' Units</span></div><span class="subject-organized-badge">Organized</span></header><div class="subject-list-rows">' +
                        items.map(subjectRowMarkup).join('') + '</div></section>';
                }).join('') + '</section>';
        }).join('');
    }

    function subjectRowMarkup(subject) {
        return '<button type="button" class="subject-list-row" data-view-subject="' + Number(subject.id) + '" aria-label="View ' + escape(subject.subject_code + ' ' + subject.subject_title) + '">' +
            '<span class="subject-list-code">' + escape(subject.subject_code) + '</span><span class="subject-list-description"><strong>' + escape(subject.subject_title) + '</strong>' +
            (subject.description ? '<small>' + escape(subject.description) + '</small>' : '') + '</span><strong class="subject-list-units">' + escape(ui.formatUnits(subject.units)) + '</strong><span class="subject-list-status">' + statusChip(subject.status) + '</span></button>';
    }

    function resetAssignmentValues() {
        const hasProgram = Boolean(selectedAssignmentProgram);
        const shs = programIsShs(selectedAssignmentProgram);
        const yearValues = hasProgram ? (shs ? ['Grade 11', 'Grade 12'] : ['1st Year', '2nd Year', '3rd Year', '4th Year']) : [];
        const semesterValues = hasProgram ? ['1st Semester', '2nd Semester'] : [];
        setOptions(assignmentYear, yearValues, shs ? 'Select grade level' : 'Select year level', []);
        setOptions(assignmentSemester, semesterValues, 'Select semester', []);
    }
    function updateAssignmentYearLabel() {
        const label = document.getElementById('subjectAssignmentYearLabel');
        if (label) label.innerHTML = (programIsShs(selectedAssignmentProgram) ? 'Grade Level' : 'Year Level') + ' <strong>*</strong>';
    }
    async function loadAssignmentValues(versionId) {
        const sequence = ++assignmentSequence;
        resetAssignmentValues();
        try {
            const result = await ui.fetchJson(assignmentApi, {
                action: 'mapping_list', page: 1, per_page: 1,
                academic_program_id: programAutocomplete.getSelectedId(),
                curriculum_id: curriculumAutocomplete.getSelectedId(),
                curriculum_version_id: versionId, year_level: '', semester: '', search: ''
            });
            if (sequence !== assignmentSequence) return;
            const shs = programIsShs(selectedAssignmentProgram);
            const yearFallback = shs ? ['Grade 11', 'Grade 12'] : ['1st Year', '2nd Year', '3rd Year', '4th Year'];
            setOptions(assignmentYear, result.filter_values?.year_levels || [], shs ? 'Select grade level' : 'Select year level', yearFallback);
            setOptions(assignmentSemester, result.filter_values?.semesters || [], 'Select semester', ['1st Semester', '2nd Semester']);
        } catch (error) {
            if (sequence === assignmentSequence) ui.showToast(error.message || 'Placement options could not be loaded.', 'warning');
        }
    }

    function resetSubjectForm() {
        if (!form) return;
        form.reset();
        document.getElementById('subjectId').value = '';
        document.getElementById('subjectFormStatus').value = 'active';
        legacyLectureUnits = '';
        legacyLaboratoryUnits = '';
        savedSubjectWithoutPlacement = false;
        assignmentCascade?.clear();
        resetAssignmentValues();
        ui.clearFormError(form);
    }
    function openCreateSubject() {
        if (!canCreate || !form || !subjectModal || !assignmentCascade) {
            ui.showToast('New Subject is unavailable with your current permissions.', 'warning');
            return;
        }
        currentMode = 'create';
        document.getElementById('subjectModalTitle').textContent = 'New Subject';
        document.getElementById('subjectModalSubtitle').textContent = 'Create the subject record and its curriculum placement together.';
        const section = document.getElementById('subjectAssignmentSection');
        if (section) section.hidden = false;
        const fields = document.getElementById('subjectAssignmentFields');
        if (fields) fields.disabled = false;
        subjectModal.open();
        try {
            resetSubjectForm();
        } catch (error) {
            console.error('New Subject form initialization failed.', error);
            ui.showToast('The New Subject form could not be initialized. Please reload the page.', 'error');
            return;
        }
        if (currentProgram) {
            programApi && ui.fetchJson(programApi, { action: 'list', page: 1, per_page: 50, search: currentProgram.program_code, status: '' })
                .then(result => {
                    const match = result.programs.find(item => Number(item.id) === Number(currentProgram.id));
                    if (match && currentMode === 'create' && subjectModal) programAutocomplete.setSelected(match);
                }).catch(() => {});
        }
        document.getElementById('subjectCode')?.focus();
    }
    async function openEditSubject(id) {
        if (!canUpdate || !form || !subjectModal) return;
        currentMode = 'update';
        resetSubjectForm();
        document.getElementById('subjectModalTitle').textContent = 'Edit Subject';
        document.getElementById('subjectModalSubtitle').textContent = 'Update the subject master record.';
        document.getElementById('subjectSubmitButton').textContent = 'Save Changes';
        if (assignmentSection) assignmentSection.hidden = true;
        const fields = document.getElementById('subjectAssignmentFields');
        if (fields) fields.disabled = true;
        subjectModal.open();
        ui.setFormSubmitting(form, true, 'Loading…');
        try {
            const result = await ui.fetchJson(subjectApi, { action: 'get', id });
            const subject = result.subject;
            document.getElementById('subjectId').value = subject.id;
            document.getElementById('subjectCode').value = subject.subject_code || '';
            document.getElementById('subjectTitle').value = subject.subject_title || '';
            document.getElementById('subjectDescription').value = subject.description || '';
            document.getElementById('subjectUnits').value = subject.units ?? '';
            document.getElementById('subjectFormStatus').value = subject.status || 'active';
            legacyLectureUnits = subject.lecture_units ?? '';
            legacyLaboratoryUnits = subject.laboratory_units ?? '';
        } catch (error) {
            ui.showFormError(form, error.message || 'Subject could not be loaded.');
        } finally {
            ui.setFormSubmitting(form, false);
        }
    }

    async function openDetails(id) {
        if (!detailsModal) return;
        const sequence = ++detailSequence;
        viewingSubjectId = Number(id);
        detailsModal.open();
        document.getElementById('subjectDetailsTitle').textContent = 'Loading…';
        document.getElementById('subjectDetailsCode').textContent = '';
        document.getElementById('subjectDetailsName').textContent = '';
        document.getElementById('subjectDetailsUnits').textContent = '';
        document.getElementById('subjectDetailsStatus').innerHTML = '';
        document.getElementById('subjectUsageRows').replaceChildren();
        document.getElementById('subjectUsageRows').hidden = true;
        document.getElementById('subjectPlacementDetail').hidden = false;
        document.getElementById('subjectPlacementDetail').textContent = 'Loading curriculum usage…';
        document.getElementById('subjectUsageCount').textContent = 'Loading';
        try {
            const result = await ui.fetchJson(subjectApi, { action: 'get', id });
            if (sequence !== detailSequence) return;
            const subject = result.subject;
            viewingSubject = subject;
            const archiveButton = document.getElementById('subjectDetailsArchive');
            if (archiveButton) archiveButton.hidden = subject.status !== 'active';
            document.getElementById('subjectDetailsTitle').textContent = subject.subject_code || 'Subject';
            document.getElementById('subjectDetailsCode').textContent = subject.subject_title || '';
            document.getElementById('subjectDetailsName').textContent = subject.description || 'No description provided.';
            document.getElementById('subjectDetailsUnits').textContent = ui.formatUnits(subject.units) + ' Units';
            document.getElementById('subjectDetailsStatus').innerHTML = statusChip(subject.status);
            await loadUsage(subject, sequence);
        } catch (error) {
            if (sequence !== detailSequence) return;
            document.getElementById('subjectDetailsTitle').textContent = 'Subject unavailable';
            document.getElementById('subjectDetailsCode').textContent = error.message || 'The record could not be loaded.';
        }
    }
    async function loadUsage(subject, sequence) {
        const note = document.getElementById('subjectPlacementDetail');
        const list = document.getElementById('subjectUsageRows');
        const count = document.getElementById('subjectUsageCount');
        if (!canBrowse) { count.textContent = 'Unavailable'; note.textContent = 'Curriculum usage is not available with your current permissions.'; return; }
        const found = [];
        let page = 1; let totalPages = 1; let expected = null;
        do {
            const response = await ui.fetchJson(assignmentApi, {
                action: 'mapping_list', page, per_page: 50, search: subject.subject_code,
                academic_program_id: '', curriculum_id: '', curriculum_version_id: '', year_level: '', semester: ''
            });
            if (sequence !== detailSequence) return;
            if (expected === null) expected = Number(response.pagination.total_records);
            if (Number(response.pagination.total_records) !== expected || Number(response.pagination.page) !== page) throw new Error('Placement data changed while loading. Please reopen the subject.');
            totalPages = Number(response.pagination.total_pages) || 1;
            response.mappings.forEach(mapping => { if (Number(mapping.subject_id) === Number(subject.id)) found.push(mapping); });
            page += 1;
        } while (page <= totalPages);
        count.textContent = found.length + (found.length === 1 ? ' placement' : ' placements');
        if (!found.length) { note.textContent = 'No curriculum placements are stored for this subject.'; return; }
        note.hidden = true;
        list.innerHTML = found.map(mapping => {
            const usageTarget = 'curricula.php?curriculum_id=' + encodeURIComponent(mapping.curriculum_id);
            const term = [mapping.year_level, mapping.semester].filter(Boolean).join(' · ') || 'Placement not specified';
            return '<li><div class="subject-usage-context"><a href="' + usageTarget + '">' + escape(mapping.program_code) + '</a><span>' + escape(mapping.curriculum_code + ' · ' + mapping.version_label) + '</span></div><span class="subject-usage-term">' + escape(term) + '</span></li>';
        }).join('');
        list.hidden = false;
    }

    async function saveSubject(event) {
        event.preventDefault();
        if (!form || savedSubjectWithoutPlacement) return;
        const creating = currentMode === 'create';
        if (creating && !assignmentCascade) { ui.showFormError(form, 'Curriculum placement controls are unavailable. Reload the page.'); return; }
        if (creating && !programAutocomplete?.hasSelection()) { ui.showFormError(form, 'Please select a valid program or strand.'); assignmentProgramInput.focus(); return; }
        if (creating && !curriculumAutocomplete?.hasSelection()) { ui.showFormError(form, 'Please select a valid curriculum.'); assignmentCurriculumInput.focus(); return; }
        if (creating && !versionAutocomplete?.hasSelection()) { ui.showFormError(form, 'Please select a valid curriculum version.'); assignmentVersionInput.focus(); return; }
        if (!form.reportValidity()) return;
        ui.clearFormError(form);
        ui.setFormSubmitting(form, true, 'Saving…');
        try {
            const data = new FormData();
            ['subject_code', 'subject_title', 'description', 'units', 'status'].forEach(name => data.set(name, form.elements.namedItem(name).value));
            data.set('action', creating ? 'create' : 'update');
            if (!creating) {
                data.set('id', document.getElementById('subjectId').value);
                data.set('lecture_units', legacyLectureUnits);
                data.set('laboratory_units', legacyLaboratoryUnits);
            }
            const result = await ui.postForm(subjectApi, data);
            if (creating) {
                const assignment = new FormData();
                assignment.set('action', 'assign');
                assignment.set('subject_id', String(result.subject.id));
                assignment.set('curriculum_version_id', versionAutocomplete.getSelectedId());
                assignment.set('year_level', assignmentYear.value.trim());
                assignment.set('semester', assignmentSemester.value.trim());
                assignment.set('is_required', document.getElementById('subjectAssignmentRequired').value);
                try {
                    await ui.postForm(assignmentApi, assignment);
                } catch (placementError) {
                    savedSubjectWithoutPlacement = true;
                    ui.showFormError(form, 'Subject record was saved, but its placement was not. ' + (placementError.message || 'Assign it later from Curriculum Builder.'));
                    ui.showToast('Subject saved without its curriculum placement.', 'error');
                    return;
                }
            }
            subjectModal.close();
            resetSubjectForm();
            ui.showToast(creating ? 'Subject created and assigned.' : (result.message || 'Subject saved.'), 'success');
            if (programView) await loadVersionSubjects(); else await loadPrograms();
        } catch (error) {
            ui.showFormError(form, error.message || 'The subject could not be saved.');
            ui.showToast(error.message || 'The subject could not be saved.', 'error');
        } finally {
            ui.setFormSubmitting(form, false);
        }
    }

    form?.addEventListener('submit', saveSubject);
    document.getElementById('subjectDetailsEdit')?.addEventListener('click', function () {
        if (!canUpdate || !viewingSubjectId) return;
        detailsModal.close();
        openEditSubject(viewingSubjectId);
    });
    document.getElementById('subjectDetailsArchive')?.addEventListener('click', function () {
        if (!canUpdate || viewingSubject?.status !== 'active') return;
        document.getElementById('subjectArchiveName').textContent = viewingSubject.subject_code;
        detailsModal.close(); archiveModal?.open();
    });
    document.getElementById('confirmSubjectArchive')?.addEventListener('click', async function () {
        if (!viewingSubjectId || !archiveModal) return;
        this.disabled = true;
        const data = new FormData(); data.set('action','archive'); data.set('id',String(viewingSubjectId));
        try {
            await ui.postForm(subjectApi,data);
            archiveModal.close();
            ui.showToast('Subject archived.','success');
            if (programView) await loadVersionSubjects(); else await loadPrograms();
        } catch (error) { ui.showFormError(document.getElementById('subjectArchiveModal'),error.message); }
        finally { this.disabled = false; }
    });
    document.getElementById('subjectCardSearch')?.addEventListener('input', ui.debounce(renderCards, 300));
    document.querySelectorAll('[data-category]').forEach(button => button.addEventListener('click', function () {
        category = this.dataset.category;
        document.querySelectorAll('[data-category]').forEach(tab => {
            const active = tab === this;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', String(active));
        });
        renderCards();
    }));
    document.getElementById('subjectCurriculumSelect')?.addEventListener('change', function () {
        loadProgramVersions().catch(error => ui.showToast(error.message || 'Versions could not be loaded.', 'error'));
    });
    document.getElementById('subjectVersionSelect')?.addEventListener('change', loadVersionSubjects);
    document.getElementById('subjectGroupedList')?.addEventListener('click', event => {
        const row = event.target.closest('[data-view-subject]');
        if (row) openDetails(row.dataset.viewSubject);
    });
    document.getElementById('subjectYearTabs')?.addEventListener('click', function (event) {
        const button = event.target.closest('[data-year]');
        if (!button) return;
        yearTabValue = button.dataset.year;
        const filter = document.getElementById('subjectYearFilter');
        filter.value = yearTabValue;
        this.querySelectorAll('[data-year]').forEach(tab => {
            const active = tab === button;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-pressed', String(active));
        });
        renderGroupedSubjects();
    });
    document.getElementById('subjectYearFilter')?.addEventListener('change', function () {
        yearTabValue = this.value;
        document.querySelectorAll('#subjectYearTabs [data-year]').forEach(tab => {
            const active = tab.dataset.year === yearTabValue;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-pressed', String(active));
        });
        renderGroupedSubjects();
    });
    ['subjectSearch', 'subjectSemesterFilter', 'subjectStatus'].forEach(id => {
        const input = document.getElementById(id);
        input?.addEventListener(input.tagName === 'INPUT' ? 'input' : 'change', input.tagName === 'INPUT' ? ui.debounce(renderGroupedSubjects, 200) : renderGroupedSubjects);
    });

    const linkedSubject = Number(new URLSearchParams(window.location.search).get('subject_id'));
    if (linkedSubject > 0) openDetails(linkedSubject);
    if (programView) loadProgramView();
    else loadPrograms();
}());
