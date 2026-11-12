(function () {
    'use strict';

    // Subject Equivalency Tool: one page. The records table is the main view; one dialog records, edits or shows a decision with the
    // stored evidence of both subjects side by side. Decisions are human-only; CIMF only explains.
    // Everything that scales is server-side: search, filters, sort, paging and the total. The page never loads or filters all records.
    const workspace = document.getElementById('subjectEquivalencyWorkspace');
    const ui = window.CurriculumUI;
    if (!workspace || !ui) return;

    const $ = id => document.getElementById(id);
    const esc = ui.escapeHtml;
    const endpoint = workspace.dataset.equivalencyApi;
    const canManage = workspace.dataset.canManage === '1';
    const REASON_MIN = Number(workspace.dataset.reasonMin) || 10;
    const REASON_MAX = Number(workspace.dataset.reasonMax) || 4000;
    const SEARCH_MIN = 2;
    const DECISIONS = {
        equivalent: ['Equivalent', 'csms-chip--approved'],
        not_equivalent: ['Not equivalent', 'csms-chip--returned'],
        needs_review: ['Needs review', 'csms-chip--under_review']
    };
    const state = { records: new Map(), request: 0, abort: null, compareRequest: 0, loaded: false, filterOptionsLoaded: false, programs: new Map(), reviewerLabel: '', reviewerReady: true,
        mode: 'create', record: null, comparison: null, archiveId: null,
        view: { search: '', decision_status: '', status: 'active', academic_program_id: '', reviewed_by: '', reviewed_from: '', reviewed_to: '', range: '', reason: '', sort: 'reviewed_at', direction: 'desc', page: 1, per_page: 20 } };

    const dialogElement = $('equivalencyDialog');
    const dialog = ui.createModal(dialogElement, { restoreFocus: () => $('equivalencySearch') });
    const archiveDialog = ui.createModal($('equivalencyArchiveDialog'));
    const form = $('equivalencyForm');
    const decisionFields = $('equivalencyDecisionFields');
    const decisionSelect = $('equivalencyDecision');
    const reasonInput = $('equivalencyReasonText');
    const saveButton = $('equivalencySave');

    // ---------- shared formatting ----------
    function decisionChip(record) {
        if (record.archived_at) return '<span class="csms-chip csms-chip--archived">Archived</span>';
        const [label, css] = DECISIONS[record.decision_status] || ['Needs review', 'csms-chip--under_review'];
        return '<span class="csms-chip ' + css + '">' + esc(label) + '</span>';
    }
    function decisionLabel(status) { return (DECISIONS[status] || ['Needs review'])[0]; }
    function reviewerName(record) {
        return [record.reviewer_first_name, record.reviewer_last_name].filter(Boolean).join(' ').trim() || record.reviewer_username || 'Not recorded';
    }
    function dateTime(value) {
        if (!value) return 'Not recorded';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return String(value);
        return new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(date);
    }
    const notRecorded = '<span class="csms-sub">Not recorded</span>';
    function subjectCell(code, title) { return '<strong class="csms-ctable__code">' + esc(code) + '</strong><span class="csms-sub">' + esc(title) + '</span>'; }
    function pairLabel(record) { return record.subject_a_code + ' and ' + record.subject_b_code; }
    function plural(count, one, many) { return count + ' ' + (count === 1 ? one : many); }

    // ---------- list state in the URL (refresh, back and share keep the filters) ----------
    const URL_KEYS = { q: 'search', decision: 'decision_status', status: 'status', program: 'academic_program_id', reviewer: 'reviewed_by', from: 'reviewed_from', to: 'reviewed_to', range: 'range', reason: 'reason', sort: 'sort', dir: 'direction', page: 'page', per_page: 'per_page' };
    const SORTS = ['subject_a', 'subject_b', 'decision', 'reviewed_at'];
    const DEFAULTS = { status: 'active', sort: 'reviewed_at', direction: 'desc', page: 1, per_page: 20 };
    function readUrl() {
        const params = new URLSearchParams(window.location.search);
        const view = state.view;
        Object.entries(URL_KEYS).forEach(([key, field]) => { view[field] = params.has(key) ? params.get(key) : (DEFAULTS[field] ?? ''); });
        if (!['', 'today', '7', '30', 'custom'].includes(view.range)) view.range = '';
        if (!view.range && (view.reviewed_from || view.reviewed_to)) view.range = 'custom';
        view.page = Math.max(1, parseInt(view.page, 10) || 1);
        view.per_page = [10, 20, 50, 100].includes(parseInt(view.per_page, 10)) ? parseInt(view.per_page, 10) : 20;
        if (!SORTS.includes(view.sort)) view.sort = 'reviewed_at';
        if (!['asc', 'desc'].includes(view.direction)) view.direction = 'desc';
        if (!['active', 'archived', 'all'].includes(view.status)) view.status = 'active';
    }
    function writeUrl(push) {
        const params = new URLSearchParams();
        Object.entries(URL_KEYS).forEach(([key, field]) => {
            const value = state.view[field];
            if (value !== '' && value !== null && value !== undefined && String(value) !== String(DEFAULTS[field] ?? '')) params.set(key, String(value));
        });
        const query = params.toString();
        const url = window.location.pathname + (query ? '?' + query : '');
        if (url !== window.location.pathname + window.location.search) window.history[push ? 'pushState' : 'replaceState'](null, '', url);
    }
    function syncControls() {
        const view = state.view;
        $('equivalencySearch').value = view.search;
        $('equivalencyDecisionFilter').value = view.decision_status;
        $('equivalencyStatus').value = view.status;
        $('equivalencyPerPage').value = String(view.per_page);
        $('equivalencyProgram').value = view.academic_program_id || 'all';
        $('equivalencyProgram').dispatchEvent(new Event('csms:picker-sync'));
        $('equivalencyRange').value = view.range;
        $('equivalencyFromWrap').hidden = $('equivalencyToWrap').hidden = view.range !== 'custom';
        $('equivalencyFrom').value = view.reviewed_from;
        $('equivalencyTo').value = view.reviewed_to;
        $('equivalencyReason').value = view.reason;
        document.querySelectorAll('.equivalency-sort').forEach(button => {
            const active = button.dataset.sort === view.sort;
            button.closest('th').setAttribute('aria-sort', active ? (view.direction === 'asc' ? 'ascending' : 'descending') : 'none');
            button.dataset.dir = active ? view.direction : '';
            button.innerHTML = esc(button.dataset.label) + (active ? ' <i class="fa-solid fa-arrow-' + (view.direction === 'asc' ? 'up' : 'down') + ' equivalency-sort__icon" aria-hidden="true"></i>' : '');
        });
    }

    // ---------- filters: chips and counters ----------
    function selectedText(select) { return select.selectedOptions[0]?.textContent || select.value; }
    function activeFilters() {
        const view = state.view, filters = [];
        if (view.search) filters.push(['search', 'Search: ' + view.search, '']);
        if (view.decision_status) filters.push(['decision_status', 'Decision: ' + decisionLabel(view.decision_status), '']);
        if (view.status !== 'active') filters.push(['status', 'Status: ' + selectedText($('equivalencyStatus')), '']);
        if (view.academic_program_id) { const program = state.programs.get(String(view.academic_program_id)); filters.push(['academic_program_id', 'Program: ' + (program ? program.code : '#' + view.academic_program_id), program ? program.code + ' — ' + program.name : '']); }
        if (view.reviewed_by) filters.push(['reviewed_by', 'Reviewed by: ' + (state.reviewerLabel || '#' + view.reviewed_by), '']);
        if (view.range && view.range !== 'custom') filters.push(['range', 'Reviewed: ' + selectedText($('equivalencyRange')), '']);
        if (view.range === 'custom' && view.reviewed_from) filters.push(['reviewed_from', 'From: ' + view.reviewed_from, '']);
        if (view.range === 'custom' && view.reviewed_to) filters.push(['reviewed_to', 'To: ' + view.reviewed_to, '']);
        if (view.reason) filters.push(['reason', view.reason === 'has' ? 'Has reason' : 'Missing reason', '']);
        return filters;
    }
    function setMore(open) { $('equivalencyMore').hidden = !open; $('equivalencyMoreToggle').setAttribute('aria-expanded', open ? 'true' : 'false'); }
    function renderChips() {
        const filters = activeFilters();
        const chips = $('equivalencyChips');
        chips.hidden = filters.length === 0;
        chips.innerHTML = filters.map(([field, label, tip]) => '<button type="button" class="csms-chip csms-chip--type equivalency-chip" data-clear="' + esc(field) + '"' + (tip ? ' title="' + esc(tip) + '"' : '') + ' aria-label="Remove filter ' + esc(tip || label) + '">' + esc(label) + ' <i class="fa-solid fa-xmark" aria-hidden="true"></i></button>').join('') +
            (filters.length ? '<button type="button" class="csms-link" id="equivalencyClearAll">Clear all</button>' : '');
        const extra = ['academic_program_id', 'reviewed_by', 'range', 'reason'].filter(field => state.view[field]).length;
        $('equivalencyMoreCount').hidden = extra === 0;
        $('equivalencyMoreCount').textContent = String(extra);
        if (extra && $('equivalencyMore').hidden) setMore(true);
    }
    function clearField(field) {
        const view = state.view;
        if (field === 'range') { view.range = ''; view.reviewed_from = ''; view.reviewed_to = ''; }
        else if (field === 'reviewed_by') { view.reviewed_by = ''; state.reviewerLabel = ''; pickers.reviewer?.clear(false); }
        else view[field] = field === 'status' ? 'active' : '';
        if (!view.reviewed_from && !view.reviewed_to && view.range === 'custom') view.range = '';
    }
    function clearAll() {
        ['search', 'decision_status', 'academic_program_id', 'reviewed_by', 'range', 'reason'].forEach(clearField);
        state.view.status = 'active';
        state.view.page = 1;
        applyView(true);
    }

    // ---------- records table ----------
    function showListState(which, message) {
        $('equivalencyLoading').hidden = which !== 'loading';
        $('equivalencyEmpty').hidden = which !== 'empty';
        $('equivalencyError').hidden = which !== 'error';
        $('equivalencyList').hidden = !['list', 'nomatch'].includes(which);
        $('equivalencyNoMatch').hidden = which !== 'nomatch';
        $('equivalencyTableWrap').hidden = which !== 'list';
        $('equivalencyPagination').hidden = which !== 'list';
        if (which === 'error') $('equivalencyErrorText').textContent = message || '';
    }
    function recordRow(record) {
        const id = Number(record.id);
        const reason = String(record.remarks || '').trim();
        return '<tr class="csms-row-click" tabindex="0" data-row="' + id + '" aria-haspopup="dialog" aria-label="View the decision for ' + esc(pairLabel(record)) + '">' +
            '<td>' + subjectCell(record.subject_a_code, record.subject_a_title) + '</td>' +
            '<td>' + subjectCell(record.subject_b_code, record.subject_b_title) + '</td>' +
            '<td>' + decisionChip(record) + (record.archived_at ? '<span class="csms-sub">' + esc(decisionLabel(record.decision_status)) + '</span>' : '') + '</td>' +
            '<td>' + (reason ? '<span class="equivalency-reason" title="' + esc(reason) + '">' + esc(reason) + '</span>' : '<span class="csms-sub">No reason recorded</span>') + '</td>' +
            '<td>' + esc(reviewerName(record)) + '</td><td>' + esc(dateTime(record.reviewed_at)) + '</td>' +
            '<td class="csms-ctable__action"><button type="button" class="csms-rowmenu" data-menu="' + id + '" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ' + esc(pairLabel(record)) + '"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button></td></tr>';
    }

    // Program picker data comes from the server grouped like the Dashboard (College programs / Senior High School strands). The
    // select is filled once and then enhanced by the shared searchable picker (js/csms-program-picker.js).
    async function loadFilterOptions() {
        if (state.filterOptionsLoaded) return;
        state.filterOptionsLoaded = true;
        try {
            const payload = await ui.fetchJson(endpoint, { action: 'filter_options', reviewed_by: state.view.reviewed_by });
            const select = $('equivalencyProgram');
            const groups = new Map();
            (payload.programs || []).forEach(program => {
                state.programs.set(String(program.id), program);
                if (!groups.has(program.group)) { const group = document.createElement('optgroup'); group.label = program.group; groups.set(program.group, group); select.appendChild(group); }
                const option = new Option(program.code + ' — ' + program.name + (program.inactive ? ' (Inactive)' : ''), String(program.id));
                Object.assign(option.dataset, { code: program.code, name: program.name, level: program.level });
                if (program.inactive) option.dataset.inactive = '1';
                groups.get(program.group).appendChild(option);
            });
            select.value = state.view.academic_program_id || 'all';
            window.CsmsProgramPicker?.enhance(select);
            if (payload.reviewer) {
                state.reviewerLabel = [payload.reviewer.first_name, payload.reviewer.last_name].filter(Boolean).join(' ').trim() || payload.reviewer.username;
                pickers.reviewer?.setSelected({ id: payload.reviewer.id, label: state.reviewerLabel }, false);
            }
            renderChips();
        } catch (error) { state.filterOptionsLoaded = false; }
        state.reviewerReady = true;
    }
    // A newer request aborts the older one, and a response that is not the latest is ignored.
    async function loadRecords() {
        const request = ++state.request;
        state.abort?.abort();
        const controller = state.abort = new AbortController();
        if (!state.loaded) showListState('loading');
        const view = state.view;
        const url = new URL(endpoint, window.location.href);
        const params = { action: 'list', page: view.page, per_page: view.per_page, search: view.search.length >= SEARCH_MIN ? view.search : '', decision_status: view.decision_status,
            status: view.status, academic_program_id: view.academic_program_id, reviewed_by: view.reviewed_by, reviewed_from: view.reviewed_from, reviewed_to: view.reviewed_to,
            reason: view.reason, sort: view.sort, direction: view.direction };
        Object.entries(params).forEach(([key, value]) => { if (value !== '' && value !== null && value !== undefined) url.searchParams.set(key, String(value)); });
        try {
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal });
            const payload = await response.json().catch(() => ({}));
            if (request !== state.request) return;
            if (!response.ok || !payload.success) throw new Error(payload.message || 'The request could not be completed.');
            state.loaded = true;
            state.records.clear();
            const rows = payload.equivalencies || [];
            rows.forEach(record => state.records.set(Number(record.id), record));
            ui.setCount($('equivalencyCount'), Number(payload.pagination?.total_records || 0));
            if (Number(payload.pagination?.page) !== view.page) { view.page = Number(payload.pagination.page); writeUrl(false); }
            renderChips();
            if (!rows.length) { showListState(activeFilters().length ? 'nomatch' : 'empty'); return; }
            $('equivalencyRows').innerHTML = rows.map(recordRow).join('');
            ui.renderPagination($('equivalencyPagination'), payload.pagination, page => { view.page = page; applyView(true); });
            showListState('list');
        } catch (error) {
            if (error.name === 'AbortError' || request !== state.request) return;
            showListState('error', error.message);
        }
    }
    function applyView(push) { syncControls(); renderChips(); writeUrl(push); loadRecords(); }
    function localDate(offsetDays) {
        const date = new Date();
        date.setDate(date.getDate() - offsetDays);
        return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
    }
    // Presets work like the Archives filter: a range select; "Custom range" reveals the two date inputs.
    function applyRange(range) {
        const view = state.view;
        view.range = range;
        if (range === 'today') { view.reviewed_from = view.reviewed_to = localDate(0); }
        else if (range === '7') { view.reviewed_from = localDate(6); view.reviewed_to = localDate(0); }
        else if (range === '30') { view.reviewed_from = localDate(29); view.reviewed_to = localDate(0); }
        else if (range === '') { view.reviewed_from = view.reviewed_to = ''; }
    }
    function filtersChanged() {
        const view = state.view;
        view.search = $('equivalencySearch').value.trim();
        view.decision_status = $('equivalencyDecisionFilter').value;
        view.status = $('equivalencyStatus').value;
        view.per_page = parseInt($('equivalencyPerPage').value, 10);
        const program = $('equivalencyProgram').value;
        view.academic_program_id = program === 'all' ? '' : program;
        if (state.reviewerReady) { view.reviewed_by = $('equivalencyReviewer').value; if (!view.reviewed_by) state.reviewerLabel = ''; }
        const range = $('equivalencyRange').value;
        if (range !== view.range) { applyRange(range); }
        else if (range === 'custom') { view.reviewed_from = $('equivalencyFrom').value; view.reviewed_to = $('equivalencyTo').value; }
        view.reason = $('equivalencyReason').value;
        view.page = 1;
        applyView(true);
    }

    // ---------- subject pickers: server-side, 20 rows, minimum 2 characters ----------
    const pickers = {};
    function pickerOptions(side) {
        const other = side === 'A' ? 'B' : 'A';
        return {
            input: $('subject' + side + 'Search'), valueInput: $('subject' + side + 'Select'), list: $('subject' + side + 'Options'),
            helpText: 'Type at least ' + SEARCH_MIN + ' characters of a code or title.', selectedText: 'Subject selected.', loadingText: 'Searching Subject Master…',
            noResultsText: 'No matching subjects found.', errorText: 'Subject Master records could not be loaded.', minimumLength: SEARCH_MIN,
            search: async query => {
                const payload = await ui.fetchJson(endpoint, { action: 'subject_search', search: query });
                const total = Number(payload.pagination?.total_records || 0);
                $('subject' + side + 'Hint').textContent = total > (payload.subjects || []).length ? 'Showing the first 20 of ' + total + ' matches. Keep typing to narrow.' : '';
                return (payload.subjects || []).filter(subject => String(subject.id) !== String(pickers[other]?.getSelectedId() || ''));
            },
            getId: subject => subject.id,
            format: subject => ({ label: subject.subject_code + ' — ' + subject.subject_title, code: subject.subject_code, title: subject.subject_title }),
            onSelect: pickersChanged, onClear: pickersChanged
        };
    }
    // Reviewed-by picker: server-side search, at most 20 users, two characters minimum.
    pickers.reviewer = ui.createAutocomplete({
        input: $('equivalencyReviewerSearch'), valueInput: $('equivalencyReviewer'), list: $('equivalencyReviewerOptions'),
        helpText: 'Type at least ' + SEARCH_MIN + ' characters of a name or username.', selectedText: 'Reviewer selected.', loadingText: 'Searching reviewers…',
        noResultsText: 'No reviewer matches.', errorText: 'Reviewers could not be loaded.', minimumLength: SEARCH_MIN,
        search: async query => (await ui.fetchJson(endpoint, { action: 'reviewer_search', search: query })).reviewers || [],
        getId: user => user.id,
        format: user => { if (user.label) return { label: user.label }; const name = [user.first_name, user.last_name].filter(Boolean).join(' ').trim() || user.username; state.reviewerLabel = name; return { label: name, code: user.username, title: '' }; },
        onSelect: () => filtersChanged(), onClear: () => filtersChanged()
    });
    pickers.A = ui.createAutocomplete(pickerOptions('A'));
    pickers.B = ui.createAutocomplete(pickerOptions('B'));
    function setPicker(side, id, code, title) { pickers[side]?.setSelected({ id, subject_code: code, subject_title: title }, false); }
    function pickersChanged() { if (state.mode !== 'create') return; state.record = null; loadComparison(); }

    // ---------- evidence table: 5 rows ----------
    const hasValue = value => value !== null && value !== undefined && String(value).trim() !== '';
    const unitText = value => hasValue(value) ? ui.formatUnits(value) : null;
    function unitsParts(subject) { return [unitText(subject.units), unitText(subject.lecture_units), unitText(subject.laboratory_units)]; }
    function unitsCell(subject) {
        const [total, lecture, laboratory] = unitsParts(subject);
        if (total === null && lecture === null && laboratory === null) return notRecorded;
        return (total === null ? 'Not recorded' : esc(total)) + ' · Lec ' + (lecture === null ? '—' : esc(lecture)) + ' · Lab ' + (laboratory === null ? '—' : esc(laboratory));
    }
    function marker(same) {
        if (same === null) return '<span class="csms-sub">Not comparable</span>';
        return same ? '<span class="csms-chip csms-chip--inactive">Same</span>' : '<span class="csms-chip csms-chip--type">Differs</span>';
    }
    function prerequisiteState(prerequisites, usage) {
        return { placed: Number(usage?.summary?.placements || 0) > 0, rows: prerequisites?.rows || [], total: Number(prerequisites?.total || 0) };
    }
    function prerequisiteCell(item) {
        if (!item.placed) return '<span class="csms-sub">Not placed</span>';
        if (!item.total) return '<span class="csms-sub">None in placed curricula</span>';
        const shown = item.rows.map(row => '<li><strong class="csms-ctable__code">' + esc(row.subject_code) + '</strong> ' + esc(row.subject_title) +
            '<span class="csms-sub">' + esc(row.requirement_type) + ' · ' + esc(row.versions) + '</span></li>').join('');
        const more = item.total > item.rows.length ? '<li class="csms-sub">+' + (item.total - item.rows.length) + ' more</li>' : '';
        return '<ul class="equivalency-list">' + shown + more + '</ul>';
    }
    function prerequisiteKey(item) { return item.rows.map(row => row.requirement_type + ':' + row.subject_code).join('|') + '#' + item.total; }
    function usedIn(summary) {
        const s = summary || {};
        return plural(Number(s.placements || 0), 'placement', 'placements') + ' · ' + plural(Number(s.programs || 0), 'program', 'programs') + ' · ' +
            plural(Number(s.curricula || 0), 'curriculum', 'curricula') + ' · ' + plural(Number(s.curriculum_versions || 0), 'version', 'versions');
    }
    function renderComparison(payload) {
        const a = payload.subject_a, b = payload.subject_b;
        const head = subject => '<span class="csms-ctable__code">' + esc(subject.subject_code) + '</span><span class="csms-sub">' + esc(subject.subject_title) + '</span>';
        $('evidenceHeadA').innerHTML = head(a);
        $('evidenceHeadB').innerHTML = head(b);
        const status = (payload.differences || []).find(row => row.field === 'status') || {};
        const describe = value => hasValue(value) ? '<span class="equivalency-long">' + esc(value) + '</span>' : notRecorded;
        const statusText = value => hasValue(value) ? esc(String(value).charAt(0).toUpperCase() + String(value).slice(1)) : notRecorded;
        const unitsA = unitsParts(a), unitsB = unitsParts(b);
        const pa = prerequisiteState(payload.prerequisites?.subject_a, payload.usage?.subject_a), pb = prerequisiteState(payload.prerequisites?.subject_b, payload.usage?.subject_b);
        const rows = [
            ['Description', describe(a.description), describe(b.description), marker(hasValue(a.description) && hasValue(b.description) ? String(a.description).trim() === String(b.description).trim() : null)],
            ['Units', unitsCell(a), unitsCell(b), marker(unitsA[0] !== null && unitsB[0] !== null ? unitsA.join('|') === unitsB.join('|') : null)],
            ['Technical status', statusText(a.status), statusText(b.status), marker(!status.result || status.result === 'not_available' ? null : status.result === 'same')],
            ['Prerequisites', prerequisiteCell(pa), prerequisiteCell(pb), marker(pa.placed && pb.placed ? prerequisiteKey(pa) === prerequisiteKey(pb) : null)],
            ['Used in', '<span class="equivalency-used">' + esc(usedIn(payload.usage?.subject_a?.summary)) + '</span>', '<span class="equivalency-used">' + esc(usedIn(payload.usage?.subject_b?.summary)) + '</span>', '—']
        ];
        let markup = rows.map(([label, left, right, mark]) => '<tr><th scope="row">' + esc(label) + '</th><td>' + left + '</td><td>' + right + '</td><td>' + mark + '</td></tr>').join('');
        // Soft, advisory warning (never blocks): one subject is a prerequisite / corequisite of the other in a version the reader may see.
        const warnings = (payload.requirement_links || []).map(link => {
            const requiredIsA = String(link.required_subject_id) === String(a.id);
            const required = requiredIsA ? a : b, subject = requiredIsA ? b : a;
            return esc(required.subject_code) + ' is a ' + esc(link.requirement_type) + ' of ' + esc(subject.subject_code) + ' in ' + esc(link.versions) + '; equivalence is unlikely. Advisory only.';
        });
        if (warnings.length) markup += '<tr class="equivalency-warning-row"><td colspan="4"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> ' + warnings.join('<br>') + '</td></tr>';
        $('comparisonRows').innerHTML = markup;
        const hidden = Number(payload.usage?.subject_a?.summary?.hidden_placements || 0) + Number(payload.usage?.subject_b?.summary?.hidden_placements || 0);
        $('comparisonScopeNote').hidden = !(payload.scope_restricted && hidden > 0);
        $('comparisonScopeNote').textContent = hidden + (hidden === 1 ? ' draft or unapproved placement is' : ' draft or unapproved placements are') + ' hidden for your role.';
    }
    function showComparisonState(which) {
        $('comparisonEmpty').hidden = which !== 'empty';
        $('comparisonLoading').hidden = which !== 'loading';
        $('comparisonResult').hidden = which !== 'result';
    }
    async function loadComparison() {
        const a = $('subjectASelect').value, b = $('subjectBSelect').value;
        state.comparison = null;
        $('equivalencyCimf').disabled = true;
        setNotice('');
        updateSave();
        if (!a || !b || a === b) { showComparisonState('empty'); applyMode(); return; }
        const request = ++state.compareRequest;
        showComparisonState('loading');
        try {
            const payload = await ui.fetchJson(endpoint, { action: 'compare', subject_a_id: a, subject_b_id: b });
            if (request !== state.compareRequest) return;
            state.comparison = payload;
            renderComparison(payload);
            showComparisonState('result');
            $('equivalencyCimf').disabled = !window.CSMSCIMF;
            const existing = payload.existing_equivalency;
            if (state.mode === 'create' && existing) {
                // The pair already has a record: open it instead of creating a duplicate.
                state.record = existing;
                state.mode = existing.archived_at || !canManage ? 'view' : 'edit';
                fillDecision(existing);
                setNotice(existing.archived_at
                    ? 'This pair has an archived decision. Restore it to change the decision.'
                    : 'This pair already has a decision (' + decisionLabel(existing.decision_status) + ', ' + dateTime(existing.reviewed_at) + '). You are editing that record.');
            }
            applyMode();
        } catch (error) {
            if (request !== state.compareRequest) return;
            showComparisonState('empty');
            ui.showFormError(form, error.message);
        }
    }

    // ---------- dialog modes ----------
    function setNotice(text) { $('equivalencyNotice').textContent = text; $('equivalencyNotice').hidden = !text; }
    function reasonProblem() {
        const length = reasonInput.value.trim().length;
        if (length < REASON_MIN) return 'Enter a reason of at least ' + REASON_MIN + ' characters.';
        if (length > REASON_MAX) return 'The reason must be at most ' + REASON_MAX + ' characters.';
        return '';
    }
    function showReasonError(message) { $('equivalencyReasonError').textContent = message; $('equivalencyReasonError').hidden = !message; reasonInput.setAttribute('aria-invalid', message ? 'true' : 'false'); }
    function updateCounter() { $('equivalencyReasonCount').textContent = String(reasonInput.value.trim().length); }
    function updateSave() { if (saveButton) saveButton.disabled = !state.comparison || !['create', 'edit'].includes(state.mode); }
    function fillDecision(record) {
        $('equivalencyRecordId').value = record ? record.id : '';
        decisionSelect.value = record && DECISIONS[record.decision_status] ? record.decision_status : '';
        reasonInput.value = record?.remarks || '';
        updateCounter();
        showReasonError('');
    }
    function renderViewDetails(record) {
        const reason = String(record?.remarks || '').trim();
        const rows = record ? [
            ['Decision', decisionChip(record) + (record.archived_at ? ' <span class="csms-sub">Was: ' + esc(decisionLabel(record.decision_status)) + '</span>' : '')],
            ['Reason', reason ? esc(reason) : '<span class="csms-sub">No reason recorded</span>'],
            ['Reviewed by', esc(reviewerName(record))],
            ['Reviewed at', esc(dateTime(record.reviewed_at))]
        ] : [];
        if (record?.archived_at) rows.push(['Archived', 'Archived ' + esc(dateTime(record.archived_at)) + '. Restore it to change the decision.']);
        $('equivalencyViewDetails').innerHTML = rows.map(([label, value]) => '<dt>' + esc(label) + '</dt><dd>' + value + '</dd>').join('');
    }
    function applyMode() {
        const mode = state.mode, record = state.record;
        $('equivalencyDialogTitle').textContent = { create: 'Record decision', edit: 'Edit decision', view: 'Equivalency decision' }[mode];
        // Pickers only when recording; an existing pair is shown as plain text.
        $('equivalencyPickers').hidden = mode !== 'create';
        const lines = $('equivalencySubjectLines');
        lines.hidden = mode === 'create' || !record;
        if (record) lines.innerHTML = '<span><strong class="csms-ctable__code">' + esc(record.subject_a_code) + '</strong> ' + esc(record.subject_a_title) + '</span><span class="csms-sub"> and </span><span><strong class="csms-ctable__code">' + esc(record.subject_b_code) + '</strong> ' + esc(record.subject_b_title) + '</span>';
        decisionFields.hidden = mode === 'view' || !canManage;
        $('equivalencyViewDetails').hidden = mode !== 'view' || !record;
        if (mode === 'view') renderViewDetails(record);
        $('equivalencyCancel').textContent = mode === 'view' ? 'Close' : 'Cancel';
        if (saveButton) saveButton.hidden = mode === 'view';
        const active = Boolean(record && !record.archived_at);
        if ($('equivalencyEdit')) $('equivalencyEdit').hidden = !(mode === 'view' && active);
        if ($('equivalencyArchive')) $('equivalencyArchive').hidden = !(mode === 'view' && active);
        if ($('equivalencyRestore')) $('equivalencyRestore').hidden = !(record && record.archived_at);
        if (mode === 'edit' && record && !String(record.remarks || '').trim()) setNotice('This record has no reason yet. Add one to save the decision.');
        updateSave();
    }
    function openDialog(mode, record) {
        if (mode !== 'view' && !canManage) mode = 'view';
        state.mode = mode;
        state.record = record || null;
        state.comparison = null;
        ui.clearFormError(form);
        setNotice('');
        fillDecision(record || null);
        pickers.A?.clear(false);
        pickers.B?.clear(false);
        ['A', 'B'].forEach(side => { $('subject' + side + 'Hint').textContent = ''; });
        if (record) {
            setPicker('A', record.subject_id, record.subject_a_code, record.subject_a_title);
            setPicker('B', record.equivalent_subject_id, record.subject_b_code, record.subject_b_title);
        }
        applyMode();
        showComparisonState('empty');
        dialog.open();
        loadComparison();
    }

    async function submitDecision(event) {
        event.preventDefault();
        if (!canManage || !['create', 'edit'].includes(state.mode)) return;
        ui.clearFormError(form);
        if (!decisionSelect.value) { ui.showFormError(form, 'Select a decision.'); decisionSelect.focus(); return; }
        const problem = reasonProblem();
        showReasonError(problem);
        if (problem) { reasonInput.focus(); return; }
        const data = new FormData();
        const isUpdate = state.mode === 'edit' && state.record;
        data.set('action', isUpdate ? 'update' : 'create');
        if (isUpdate) data.set('id', state.record.id);
        data.set('subject_a_id', $('subjectASelect').value);
        data.set('subject_b_id', $('subjectBSelect').value);
        data.set('decision_status', decisionSelect.value);
        data.set('remarks', reasonInput.value.trim());
        ui.setFormSubmitting(form, true, 'Saving…');
        try {
            const payload = await ui.postForm(endpoint, data);
            dialog.close();
            ui.showToast(payload.message, isUpdate ? 'updated' : 'success');
            await loadRecords();
        } catch (error) {
            ui.showFormError(form, error.message);
        } finally {
            ui.setFormSubmitting(form, false);
            updateSave();
        }
    }
    async function postAction(action, id) {
        const data = new FormData();
        data.set('action', action);
        data.set('id', id);
        const payload = await ui.postForm(endpoint, data);
        ui.showToast(payload.message, 'success');
        await loadRecords();
        return payload;
    }
    async function restore(id) {
        try {
            const payload = await postAction('restore', id);
            if (!dialogElement.hidden && payload.equivalency) { state.record = payload.equivalency; state.mode = 'view'; setNotice(''); applyMode(); }
        } catch (error) { ui.showToast(error.message, 'error'); }
    }

    // ---------- CIMF: advice beside the dialog, never written into the reason ----------
    window.CSMSPageCIMFContext = () => ({ subject_a_id: $('subjectASelect').value || '', subject_b_id: $('subjectBSelect').value || '' });
    window.CSMSPageCIMFStoredDecision = () => state.comparison?.existing_equivalency || null;
    const cimfPanel = $('csmsCimfPanel');
    function setCimfBeside(on) { document.body.classList.toggle('equivalency-cimf-beside', Boolean(on)); }
    if (cimfPanel) new MutationObserver(() => { if (cimfPanel.hidden) setCimfBeside(false); }).observe(cimfPanel, { attributes: true, attributeFilter: ['hidden'] });
    $('equivalencyCimf').addEventListener('click', () => {
        if (!window.CSMSCIMF || !state.comparison) return;
        setCimfBeside(true);
        window.CSMSCIMF.updateContext?.();
        window.CSMSCIMF.open('review');
    });
    dialogElement.querySelectorAll('[data-modal-close]').forEach(button => button.addEventListener('click', () => setCimfBeside(false)));
    dialogElement.addEventListener('keydown', event => { if (event.key === 'Escape') setCimfBeside(false); });

    // ---------- events ----------
    form.addEventListener('submit', submitDecision);
    reasonInput.addEventListener('input', () => { updateCounter(); if (!$('equivalencyReasonError').hidden) showReasonError(reasonProblem()); });
    $('equivalencyEdit')?.addEventListener('click', () => { if (!state.record) return; state.mode = 'edit'; fillDecision(state.record); applyMode(); decisionSelect.focus(); });
    $('equivalencyRestore')?.addEventListener('click', () => { if (state.record) restore(state.record.id); });
    $('equivalencyArchive')?.addEventListener('click', () => { if (state.record) { state.archiveId = Number(state.record.id); archiveDialog?.open(); } });
    document.querySelectorAll('#recordDecisionButton, [data-record-decision]').forEach(button => button.addEventListener('click', () => openDialog('create', null)));
    $('equivalencyArchiveConfirm')?.addEventListener('click', async () => {
        if (!state.archiveId) return;
        try { await postAction('archive', state.archiveId); archiveDialog.close(); dialog.close(); }
        catch (error) { ui.showToast(error.message, 'error'); }
        state.archiveId = null;
    });

    // Search waits 300 ms and two characters; one character changes nothing. Older responses are aborted or ignored.
    const searchLater = ui.debounce(() => {
        if ($('equivalencySearch').value.trim().length === 1) return;
        filtersChanged();
    }, 300);
    $('equivalencySearch').addEventListener('input', searchLater);
    ['equivalencyDecisionFilter', 'equivalencyStatus', 'equivalencyPerPage', 'equivalencyProgram', 'equivalencyRange', 'equivalencyFrom', 'equivalencyTo', 'equivalencyReason']
        .forEach(id => $(id).addEventListener('change', filtersChanged));
    $('equivalencyMoreToggle').addEventListener('click', () => setMore($('equivalencyMore').hidden));
    $('equivalencyChips').addEventListener('click', event => {
        if (event.target.closest('#equivalencyClearAll')) { clearAll(); return; }
        const chipButton = event.target.closest('[data-clear]');
        if (!chipButton) return;
        clearField(chipButton.dataset.clear);
        state.view.page = 1;
        applyView(true);
    });
    $('equivalencyClearFilters').addEventListener('click', clearAll);
    $('equivalencyRetry').addEventListener('click', () => { state.loaded = false; loadRecords(); });
    document.querySelectorAll('.equivalency-sort').forEach(button => button.addEventListener('click', () => {
        const view = state.view, column = button.dataset.sort;
        view.direction = view.sort === column ? (view.direction === 'asc' ? 'desc' : 'asc') : (column === 'reviewed_at' ? 'desc' : 'asc');
        view.sort = column;
        view.page = 1;
        applyView(true);
    }));
    window.addEventListener('popstate', () => { readUrl(); applyView(false); });

    const rowMenu = window.CsmsMenu ? window.CsmsMenu.create($('equivalencyRowMenu'), {
        esc,
        onSelect(action, data) {
            const record = state.records.get(Number(data.record));
            if (!record) return;
            if (action === 'view') openDialog('view', record);
            else if (action === 'edit') openDialog('edit', record);
            else if (action === 'archive') { state.archiveId = Number(record.id); archiveDialog?.open(); }
            else if (action === 'restore') restore(record.id);
        }
    }) : null;
    function menuItems(record) {
        const data = { record: record.id };
        const items = [{ action: 'view', label: 'View', data }];
        if (canManage && !record.archived_at) items.push({ action: 'edit', label: 'Edit decision', data }, { action: 'archive', label: 'Archive', danger: true, data });
        if (canManage && record.archived_at) items.push({ action: 'restore', label: 'Restore', data });
        return items;
    }
    $('equivalencyRows').addEventListener('click', event => {
        const menuButton = event.target.closest('[data-menu]');
        if (menuButton) { const record = state.records.get(Number(menuButton.dataset.menu)); if (record) rowMenu?.open(menuButton, menuItems(record)); return; }
        const row = event.target.closest('[data-row]');
        if (row) openDialog('view', state.records.get(Number(row.dataset.row)));
    });
    $('equivalencyRows').addEventListener('keydown', event => {
        if (event.target.matches?.('[data-row]') && ['Enter', ' '].includes(event.key)) { event.preventDefault(); openDialog('view', state.records.get(Number(event.target.dataset.row))); }
    });

    document.querySelectorAll('.equivalency-sort').forEach(button => { button.dataset.label = button.textContent.trim(); });
    readUrl();
    state.reviewerReady = !state.view.reviewed_by;
    syncControls();
    renderChips();
    loadFilterOptions();
    loadRecords();
}());
