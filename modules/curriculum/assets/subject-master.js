(function () {
    'use strict';

    // Subjects (Subject Master). Two views of one page: All subjects (server-side listing, paging, sorting and filters; ready for
    // thousands of subjects) and By program (the placements of one curriculum version). One shared dialog for details, create and edit.
    // Nothing here trusts stored values: unexpected years or semesters are shown as "Unassigned", and a failing request shows the
    // empty-state error with Retry, never a raw script error.
    const root = document.getElementById('subjectWorkspace');
    const ui = window.CurriculumUI;
    if (!root || !ui) return;

    const $ = id => document.getElementById(id);
    const esc = ui.escapeHtml;
    const api = root.dataset.subjectApi;
    const canCreate = root.dataset.canCreate === '1', canUpdate = root.dataset.canUpdate === '1', canPlace = root.dataset.canPlace === '1';
    const canBrowse = root.dataset.canBrowse === '1', canExport = root.dataset.canExport === '1';
    const SEARCH_MIN = 2;
    const PER_PAGE = ['10', '20', '50', '100'];
    const SORTS = ['code', 'title', 'units', 'status', 'usage'];
    const state = { view: root.dataset.view === 'program' && canBrowse ? 'program' : 'all', dialog: null, programs: new Map(), ctx: [null, null, null], programTicket: 0 };
    const list = { page: 1, sort: '', direction: 'asc', request: 0, records: new Map(), loaded: false };
    const placements = { rows: [], totals: null, loaded: false };

    // ---------- small helpers ----------
    const num = value => ui.formatUnits(value);
    const statusChip = status => '<span class="csms-chip ' + (status === 'active' ? 'csms-chip--approved' : 'csms-chip--archived') + '">' + (status === 'active' ? 'Active' : 'Inactive') + '</span>';
    const friendly = error => (error && typeof error.status === 'number' && error.message) ? error.message : 'Please try again.';
    const ordinal = n => n + (n % 100 >= 11 && n % 100 <= 13 ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' }[n % 10] || 'th'));
    // Year and semester labels for any stored value: anything that is not clearly a known year or semester is "Unassigned".
    function yearInfo(raw) {
        const text = String(raw ?? '').trim();
        const match = /^(?:(\d+)(?:st|nd|rd|th)?(?:\s*(?:yr|year))?|(?:year|yr)\s*(\d+))$/i.exec(text);
        if (match) {
            const n = Number(match[1] || match[2]);
            return n >= 1 && n <= 4 ? { label: ordinal(n) + ' Year', key: n } : { label: 'Unassigned year', key: 99 };
        }
        const grade = /^grade\s*(11|12)$/i.exec(text);
        return grade ? { label: 'Grade ' + grade[1], key: Number(grade[1]) } : { label: 'Unassigned year', key: 99 };
    }
    function semesterInfo(raw) {
        const text = String(raw ?? '').trim();
        const match = /^(?:(1|2)(?:st|nd)?(?:\s*sem(?:ester)?)?|sem(?:ester)?\s*(1|2))$/i.exec(text);
        if (match) { const n = Number(match[1] || match[2]); return { label: ordinal(n) + ' Semester', key: n }; }
        return /^summer$/i.test(text) ? { label: 'Summer', key: 3 } : { label: 'Unassigned semester', key: 99 };
    }
    const unitsSub = row => (row.lecture_units !== null && row.lecture_units !== undefined && row.laboratory_units !== null && row.laboratory_units !== undefined)
        ? '<span class="csms-sub">' + num(row.lecture_units) + ' lecture · ' + num(row.laboratory_units) + ' lab</span>' : '';

    // ---------- the address (view, filters, sort and page) ----------
    const OWN = ['view', 'q', 'status', 'program', 'placed', 'sort', 'dir', 'per_page', 'page', 'pq', 'year', 'sem', 'pst', 'subject_id', 'cq', 'lvl', 'cpage'];
    function writeUrl(extra, push) {
        const params = new URLSearchParams(window.location.search);
        OWN.forEach(key => params.delete(key));
        params.set('view', state.view);
        Object.entries(extra || {}).forEach(([key, value]) => { if (value !== '' && value !== null && value !== undefined && !(key === 'per_page' && String(value) === '20') && !((key === 'page' || key === 'cpage') && Number(value) <= 1) && !(key === 'dir' && value === 'asc')) params.set(key, String(value)); });
        const url = window.location.pathname + '?' + params.toString();
        if (url !== window.location.pathname + window.location.search) window.history[push ? 'pushState' : 'replaceState'](null, '', url);
    }
    const allExtra = () => ({ q: $('subjectSearch').value.trim(), status: $('subjectStatus').value, program: programValue(), placed: $('subjectPlaced')?.value || '', sort: list.sort, dir: list.direction, per_page: $('subjectPerPage').value, page: list.page });
    const programExtra = () => ({ pq: $('programSearch2').value.trim(), year: $('programYear').value, sem: $('programSemester').value, pst: $('programStatus').value });
    const cardExtra = () => ({ cq: $('cardSearch').value.trim(), lvl: cards.level === 'all' ? '' : cards.level, cpage: cards.page });
    function writeCurrent(push) { writeUrl(state.view === 'all' ? allExtra() : (cascade ? programExtra() : cardExtra()), push); }
    function readUrl() {
        const p = new URLSearchParams(window.location.search);
        $('subjectSearch').value = p.get('q') || '';
        $('subjectStatus').value = ['active', 'inactive'].includes(p.get('status')) ? p.get('status') : '';
        if ($('subjectPlaced')) $('subjectPlaced').value = ['placed', 'unplaced'].includes(p.get('placed')) ? p.get('placed') : '';
        $('subjectPerPage').value = PER_PAGE.includes(p.get('per_page')) ? p.get('per_page') : '20';
        list.sort = SORTS.includes(p.get('sort')) ? p.get('sort') : '';
        list.direction = p.get('dir') === 'desc' ? 'desc' : 'asc';
        list.page = Math.max(1, parseInt(p.get('page'), 10) || 1);
        state.pendingProgram = p.get('program') || '';
        $('cardSearch').value = p.get('cq') || '';
        cards.level = ['college', 'shs'].includes(p.get('lvl')) ? p.get('lvl') : 'all';
        cards.page = Math.max(1, parseInt(p.get('cpage'), 10) || 1);
        $('programSearch2').value = p.get('pq') || '';
        state.pendingYear = p.get('year') || ''; state.pendingSemester = p.get('sem') || '';
        $('programStatus').value = ['active', 'inactive'].includes(p.get('pst')) ? p.get('pst') : '';
    }
    const programValue = () => { const v = $('subjectProgram')?.value || ''; return v === 'all' ? '' : v; };

    // ---------- views ----------
    function showView(view, push) {
        if (view === 'program' && !canBrowse) view = 'all';
        state.view = view;
        $('viewAll').hidden = view !== 'all';
        $('viewProgram').hidden = view !== 'program';
        const table = view === 'program' && Boolean(cascade);
        if ($('subjectContextBar')) $('subjectContextBar').hidden = !table;
        if ($('programBackRow')) $('programBackRow').hidden = !table;
        $('programCardsView').hidden = !(view === 'program' && !cascade);
        $('programTableView').hidden = !table;
        document.querySelectorAll('#subjectViews [role="tab"]').forEach(tab => {
            const active = tab.dataset.view === view;
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            if (active) tab.removeAttribute('tabindex'); else tab.setAttribute('tabindex', '-1');
        });
        writeCurrent(push);
        if (view === 'all') loadList(); else if (cascade) loadPlacements(); else loadCards();
    }
    document.querySelectorAll('#subjectViews [role="tab"]').forEach(tab => tab.addEventListener('click', () => { if (tab.dataset.view !== state.view) showView(tab.dataset.view, true); }));
    document.getElementById('subjectViews').addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
        const tabs = [...document.querySelectorAll('#subjectViews [role="tab"]')];
        const next = tabs[(tabs.indexOf(document.activeElement) + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length];
        next?.focus(); next?.click();
    });

    // ---------- All subjects ----------
    function listStates(which, message) {
        $('subjectLoading').hidden = which !== 'loading';
        $('subjectEmpty').hidden = which !== 'empty';
        $('subjectNoMatch').hidden = which !== 'nomatch';
        $('subjectError').hidden = which !== 'error';
        $('subjectTableWrap').hidden = which !== 'list';
        $('subjectPagination').hidden = which !== 'list';
        if (which === 'error') $('subjectErrorText').textContent = message || '';
    }
    const filtering = () => Boolean($('subjectSearch').value.trim().length >= SEARCH_MIN || $('subjectStatus').value || programValue() || $('subjectPlaced')?.value);
    function listParams() {
        const out = { action: 'list', page: list.page, per_page: $('subjectPerPage').value };
        const q = $('subjectSearch').value.trim();
        if (q.length >= SEARCH_MIN) out.search = q;
        if ($('subjectStatus').value) out.status = $('subjectStatus').value;
        if (programValue()) out.academic_program_id = programValue();
        if ($('subjectPlaced')?.value) out.placed = $('subjectPlaced').value;
        if (list.sort) { out.sort = list.sort; out.direction = list.direction; }
        return out;
    }
    function renderSortHeaders() {
        document.querySelectorAll('.subject-sort').forEach(button => {
            const active = list.sort === button.dataset.sort;
            button.closest('th').setAttribute('aria-sort', active ? (list.direction === 'asc' ? 'ascending' : 'descending') : 'none');
            button.innerHTML = esc(button.dataset.label) + (active ? ' <i class="fa-solid fa-arrow-' + (list.direction === 'asc' ? 'up' : 'down') + ' subject-sort__icon" aria-hidden="true"></i>' : '');
        });
    }
    function renderChips() {
        const chips = [];
        const q = $('subjectSearch').value.trim();
        if (q.length >= SEARCH_MIN) chips.push(['search', 'Search: ' + q]);
        if ($('subjectStatus').value) chips.push(['status', $('subjectStatus').value === 'active' ? 'Active' : 'Inactive']);
        if (programValue()) chips.push(['program', 'Program: ' + (state.programs.get(programValue())?.code || programValue())]);
        if ($('subjectPlaced')?.value) chips.push(['placed', $('subjectPlaced').value === 'placed' ? 'Placed' : 'Not placed anywhere']);
        const box = $('subjectChips');
        box.hidden = chips.length === 0;
        box.innerHTML = chips.map(([field, label]) => '<button type="button" class="csms-chip csms-chip--type subject-chip" data-clear="' + field + '" aria-label="Remove filter ' + esc(label) + '">' + esc(label) + ' <i class="fa-solid fa-xmark" aria-hidden="true"></i></button>').join('') +
            (chips.length > 1 ? '<button type="button" class="button-muted subject-chip" data-clear="all">Clear all</button>' : '');
    }
    function rowHtml(row) {
        const usage = row.usage_count === null || row.usage_count === undefined ? null : Number(row.usage_count);
        return '<tr class="csms-row-click" tabindex="0" data-row="' + Number(row.id) + '" aria-haspopup="dialog" aria-label="Open ' + esc(row.subject_code) + '">' +
            '<td><strong class="csms-ctable__code">' + esc(row.subject_code) + '</strong></td><td class="subject-title-cell">' + esc(row.subject_title) + '</td>' +
            '<td class="csms-ctable__num">' + esc(num(row.units)) + unitsSub(row) + '</td><td>' + statusChip(row.status) + '</td>' +
            (canBrowse ? '<td class="csms-ctable__num">' + (usage === null ? '—' : usage + (usage === 0 ? '<span class="csms-sub">Not placed</span>' : '')) + '</td>' : '') +
            '<td class="csms-ctable__action"><button type="button" class="csms-rowmenu" data-menu="' + Number(row.id) + '" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ' + esc(row.subject_code) + '"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button></td></tr>';
    }
    async function loadList() {
        const ticket = ++list.request;
        renderSortHeaders(); renderChips();
        if (!list.loaded) listStates('loading');
        try {
            const payload = await ui.fetchJson(api, listParams());
            if (ticket !== list.request) return;
            list.loaded = true;
            list.records.clear();
            (payload.subjects || []).forEach(row => list.records.set(Number(row.id), row));
            ui.setCount($('subjectCount'), Number(payload.pagination.total_records));
            if (Number(payload.pagination.page) !== list.page) list.page = Number(payload.pagination.page);
            if (!payload.subjects.length) { listStates(filtering() ? 'nomatch' : 'empty'); return; }
            $('subjectRows').innerHTML = payload.subjects.map(rowHtml).join('');
            ui.renderPagination($('subjectPagination'), payload.pagination, page => { list.page = page; writeCurrent(true); loadList(); });
            listStates('list');
        } catch (error) {
            if (ticket !== list.request) return;
            listStates('error', friendly(error));
        }
    }
    const reload = () => { list.page = 1; writeCurrent(false); loadList(); };
    $('subjectSearch').addEventListener('input', ui.debounce(() => { if ($('subjectSearch').value.trim().length === 1) return; reload(); }, 300));
    ['subjectStatus', 'subjectPlaced', 'subjectPerPage'].forEach(id => $(id)?.addEventListener('change', reload));
    $('subjectProgram')?.addEventListener('change', reload);
    $('subjectRetry').addEventListener('click', () => { list.loaded = false; loadList(); });
    function clearFilters() { $('subjectSearch').value = ''; $('subjectStatus').value = ''; if ($('subjectPlaced')) $('subjectPlaced').value = ''; if ($('subjectProgram')) { $('subjectProgram').value = 'all'; syncProgramPicker(); } reload(); }
    $('subjectClearFilters').addEventListener('click', clearFilters);
    $('subjectChips').addEventListener('click', event => {
        const button = event.target.closest('[data-clear]'); if (!button) return;
        const field = button.dataset.clear;
        if (field === 'all') return clearFilters();
        if (field === 'search') $('subjectSearch').value = '';
        else if (field === 'status') $('subjectStatus').value = '';
        else if (field === 'placed') $('subjectPlaced').value = '';
        else if (field === 'program') { $('subjectProgram').value = 'all'; syncProgramPicker(); }
        reload();
    });
    document.querySelectorAll('.subject-sort').forEach(button => {
        button.dataset.label = button.textContent.trim();
        button.addEventListener('click', () => {
            list.direction = list.sort === button.dataset.sort && list.direction === 'asc' ? 'desc' : 'asc';
            list.sort = button.dataset.sort; list.page = 1; writeCurrent(true); loadList();
        });
    });
    window.addEventListener('popstate', () => { readUrl(); syncProgramPicker(); const view = new URLSearchParams(window.location.search).get('view'); showView(view === 'program' && canBrowse ? 'program' : 'all', false); });
    $('subjectRows').addEventListener('click', event => {
        const menu = event.target.closest('[data-menu]');
        if (menu) { const row = list.records.get(Number(menu.dataset.menu)); if (row) rowMenu?.open(menu, menuItems(row)); return; }
        const tr = event.target.closest('[data-row]'); if (tr) openDetails(Number(tr.dataset.row));
    });
    $('subjectRows').addEventListener('keydown', event => { if (event.target.matches?.('[data-row]') && ['Enter', ' '].includes(event.key)) { event.preventDefault(); openDetails(Number(event.target.dataset.row)); } });

    // Program picker (shared, searchable). Its option list comes from the server; the select stays the value holder.
    let pickerReady = false;
    function syncProgramPicker() { const select = $('subjectProgram'); if (!select) return; if (pickerReady) { select.dispatchEvent(new Event('csms:picker-sync')); } }
    async function loadPrograms() {
        const select = $('subjectProgram');
        if (!select) return;
        try {
            const payload = await ui.fetchJson(api, { action: 'program_filter_options' });
            const groups = new Map();
            (payload.programs || []).forEach(program => {
                state.programs.set(String(program.id), program);
                if (!groups.has(program.group)) { const group = document.createElement('optgroup'); group.label = program.group; groups.set(program.group, group); select.appendChild(group); }
                const option = new Option(program.code + ' — ' + program.name + (program.inactive ? ' (Inactive)' : ''), String(program.id));
                Object.assign(option.dataset, { code: program.code, name: program.name, level: program.level });
                if (program.inactive) option.dataset.inactive = '1';
                groups.get(program.group).appendChild(option);
            });
            select.value = state.pendingProgram && state.programs.has(state.pendingProgram) ? state.pendingProgram : 'all';
            window.CsmsProgramPicker?.enhance(select);
            pickerReady = true;
            renderChips();
        } catch (error) { /* the list works without the program filter */ }
    }

    // ---------- the row menu ----------
    const rowMenu = window.CsmsMenu ? window.CsmsMenu.create($('subjectRowMenu'), {
        esc,
        onSelect(action, data) {
            const id = Number(data.record);
            if (action === 'view') openDetails(id);
            else if (action === 'edit') openEdit(id);
            else if (action === 'archive') openDetails(id, 'archive');
            else if (action === 'activate') activate(id);
        }
    }) : null;
    function menuItems(row) {
        const data = { record: row.id };
        const items = [{ action: 'view', label: 'View', data }];
        if (canUpdate) {
            items.push({ action: 'edit', label: 'Edit subject', data });
            items.push(row.status === 'active' ? { action: 'archive', label: 'Archive', danger: true, data } : { action: 'activate', label: 'Activate', data });
        }
        return items;
    }

    // ---------- By program ----------
    // The shared Program > Curriculum > Version bar exists only in the table view (a program was chosen from a card or the address names one).
    let cascade = null;
    function initCascade() { cascade = ui.createCascadingAutocomplete({ levels: ['program', 'curriculum', 'version'].map((key, index) => ({
        input: $(key + 'Search'), valueInput: $(key + 'Id'), list: $(key + 'Options'), minimumLength: 0, showSuggestionsOnFocus: true, showSuggestionsOnEnable: true,
        placeholder: index === 0 ? 'Search program code or name' : 'Search ' + key, disabledPlaceholder: index === 1 ? 'Choose program first' : 'Choose curriculum first',
        search: async query => (await ui.fetchJson(api, { action: 'context_options', level: key, ...ctxIds(), search: query })).options,
        format: item => ({ label: [item.code, item.title].filter(Boolean).join(' — '), code: item.code, title: item.title || '' })
    })), onChange: (index, item) => { state.ctx[index] = item || null; for (let i = index + 1; i < 3; i += 1) state.ctx[i] = null; clearTimeout(state.timer); state.timer = setTimeout(loadPlacements, 250); } }); }
    function ctxIds() {
        const out = {};
        [['program_id', 0], ['curriculum_id', 1]].forEach(([key, i]) => { const id = cascade?.get(i)?.getSelectedId?.(); if (id) out[key] = id; });
        return out;
    }
    const ctxId = i => cascade?.get(i)?.getSelectedId?.() || '';
    // Opened from a card (or an address with program_id): the shared context resolves what it can; whatever it leaves empty is filled in here so
    // the bar is always prefilled with the chosen program, and with its curriculum and version when there is exactly one curriculum.
    function resolveContext(programId) {
        setTimeout(async () => {
            const item = state.programs.get(String(programId));
            if (!cascade || !item || cascade.get(2).hasSelection()) return;
            try {
                const program = { id: item.id, code: item.code, title: item.name, program_type: item.level === 'shs' ? 'Senior High School' : "Bachelor's Degree" };
                if (!cascade.get(0).hasSelection()) { cascade.get(0).setSelected(program, false); state.ctx[0] = program; }
                if (cascade.get(1).hasSelection()) return;
                const curricula = (await ui.fetchJson(api, { action: 'context_options', level: 'curriculum', program_id: programId, search: '' })).options || [];
                if (curricula.length !== 1 || cascade.get(1).hasSelection()) return;
                cascade.get(1).setSelected(curricula[0], false); state.ctx[1] = curricula[0];
                const versions = (await ui.fetchJson(api, { action: 'context_options', level: 'version', program_id: programId, curriculum_id: curricula[0].id, search: '' })).options || [];
                const pick = [...versions].reverse().find(v => v.status === 'active') || versions[versions.length - 1];
                if (pick && !cascade.get(2).hasSelection()) cascade.get(2).setSelected(pick, true);
            } catch (error) { /* the bar stays as it is; the user can choose */ }
        }, 1800);
    }
    function programStates(which, message) {
        $('programNoVersion').hidden = which !== 'noVersion';
        $('programLoading').hidden = which !== 'loading';
        $('programError').hidden = which !== 'error';
        $('programBody').hidden = which !== 'body';
        if (which === 'error') $('programErrorText').textContent = message || '';
    }
    function exportHref() {
        const params = new URLSearchParams({ program_id: ctxId(0), curriculum_id: ctxId(1), version_id: ctxId(2) });
        return (root.dataset.exportUrl || 'curriculum_export.php') + '?' + params.toString();
    }
    async function loadPlacements() {
        if (state.view !== 'program') return;
        const ticket = ++state.programTicket;
        const version = ctxId(2);
        if (!version) { placements.loaded = false; programStates('noVersion'); return; }
        programStates('loading');
        try {
            const payload = await ui.fetchJson(api, { action: 'version_placements', curriculum_version_id: version });
            if (ticket !== state.programTicket) return;
            placements.rows = (payload.placements || []).map(row => ({ ...row, _year: yearInfo(row.year_level), _sem: semesterInfo(row.semester) }));
            placements.rows.sort((a, b) => a._year.key - b._year.key || a._sem.key - b._sem.key || (Number(a.sequence_order) || 1e9) - (Number(b.sequence_order) || 1e9) || String(a.subject_code).localeCompare(String(b.subject_code)));
            placements.totals = payload.totals;
            placements.loaded = true;
            fillTermFilters();
            if ($('programExport')) $('programExport').href = exportHref();
            renderPlacements();
            programStates('body');
        } catch (error) {
            if (ticket !== state.programTicket) return;
            programStates('error', friendly(error));
        }
    }
    function fillTermFilters() {
        const years = [...new Map(placements.rows.map(r => [r._year.label, r._year])).values()].sort((a, b) => a.key - b.key);
        const sems = [...new Map(placements.rows.map(r => [r._sem.label, r._sem])).values()].sort((a, b) => a.key - b.key);
        const fill = (select, label, items, wanted) => {
            const current = wanted ?? select.value;
            select.innerHTML = '<option value="">' + label + '</option>' + items.map(i => '<option value="' + esc(i.label) + '">' + esc(i.label) + '</option>').join('');
            select.value = items.some(i => i.label === current) ? current : '';
        };
        fill($('programYear'), 'All year levels', years, state.pendingYear); fill($('programSemester'), 'All semesters', sems, state.pendingSemester);
        state.pendingYear = state.pendingSemester = null;
    }
    function renderPlacements() {
        const q = $('programSearch2').value.trim().toLowerCase();
        const year = $('programYear').value, sem = $('programSemester').value, status = $('programStatus').value;
        const rows = placements.rows.filter(r => (!year || r._year.label === year) && (!sem || r._sem.label === sem) && (!status || r.status === status)
            && (q.length < SEARCH_MIN || String(r.subject_code).toLowerCase().includes(q) || String(r.subject_title).toLowerCase().includes(q)));
        const t = placements.totals;
        $('programSummary').textContent = t ? t.subjects + (t.subjects === 1 ? ' subject' : ' subjects') + ' · ' + num(t.units) + ' units of all placements · ' + num(t.required_units) + ' units of required placements' : '';
        $('programEmpty').hidden = placements.rows.length !== 0;
        $('programNoMatch').hidden = !(placements.rows.length && rows.length === 0);
        $('programTableWrap').hidden = rows.length === 0;
        $('programRows').innerHTML = rows.map(r => '<tr class="csms-row-click" tabindex="0" data-subject="' + Number(r.id) + '" aria-haspopup="dialog" aria-label="Open ' + esc(r.subject_code) + '">' +
            '<td title="' + esc(r.year_level ?? '') + '">' + esc(r._year.label) + '</td><td title="' + esc(r.semester ?? '') + '">' + esc(r._sem.label) + '</td>' +
            '<td><strong class="csms-ctable__code">' + esc(r.subject_code) + '</strong></td><td class="subject-title-cell">' + esc(r.subject_title) + '</td>' +
            '<td class="csms-ctable__num">' + esc(num(r.units)) + '</td><td>' + (Number(r.is_required) === 1 ? 'Required' : 'Optional') + '</td><td>' + statusChip(r.status) + '</td></tr>').join('');
        if (placements.loaded) writeCurrent(false);
    }
    $('programSearch2').addEventListener('input', ui.debounce(renderPlacements, 300));
    ['programYear', 'programSemester', 'programStatus'].forEach(id => $(id).addEventListener('change', renderPlacements));
    $('programRetry').addEventListener('click', loadPlacements);
    $('programRows').addEventListener('click', event => { const tr = event.target.closest('[data-subject]'); if (tr) openDetails(Number(tr.dataset.subject)); });
    $('programRows').addEventListener('keydown', event => { if (event.target.matches?.('[data-subject]') && ['Enter', ' '].includes(event.key)) { event.preventDefault(); openDetails(Number(event.target.dataset.subject)); } });

    // ---------- By program: program and strand cards ----------
    const cards = { page: 1, level: 'all', request: 0 };
    function cardStates(which, message) {
        $('cardLoading').hidden = which !== 'loading';
        $('cardEmpty').hidden = which !== 'empty';
        $('cardNoMatch').hidden = which !== 'nomatch';
        $('cardError').hidden = which !== 'error';
        $('cardGroups').hidden = which !== 'list';
        $('cardPagination').hidden = which !== 'list';
        if (which === 'error') $('cardErrorText').textContent = message || '';
    }
    function cardHtml(program) {
        const subjects = Number(program.subject_count), curricula = Number(program.curriculum_count);
        const meta = curricula === 0 ? 'No curriculum yet' : subjects + (subjects === 1 ? ' subject' : ' subjects') + ' · ' + curricula + (curricula === 1 ? ' curriculum' : ' curricula');
        return '<a class="csms-card subject-card" href="subjects.php?view=program&program_id=' + Number(program.id) + '" aria-label="Open ' + esc(program.program_code) + ', ' + esc(meta) + '">' +
            '<span class="subject-card__code">' + esc(program.program_code) + '</span><span class="subject-card__name">' + esc(program.program_name) + '</span><span class="subject-card__meta">' + esc(meta) + '</span></a>';
    }
    async function loadCards() {
        const ticket = ++cards.request;
        document.querySelectorAll('#cardLevel [data-level]').forEach(b => b.setAttribute('aria-pressed', b.dataset.level === cards.level ? 'true' : 'false'));
        cardStates('loading');
        const params = { action: 'program_cards', page: cards.page, per_page: 60 };
        const q = $('cardSearch').value.trim();
        if (q.length >= SEARCH_MIN) params.search = q;
        if (cards.level !== 'all') params.level = cards.level;
        try {
            const payload = await ui.fetchJson(api, params);
            if (ticket !== cards.request) return;
            const total = Number(payload.pagination.total_records);
            ui.setCount($('cardCount'), total);
            if (Number(payload.pagination.page) !== cards.page) cards.page = Number(payload.pagination.page);
            if (!payload.programs.length) { cardStates(q.length >= SEARCH_MIN || cards.level !== 'all' ? 'nomatch' : 'empty'); return; }
            ['college', 'shs'].forEach(level => {
                const rows = payload.programs.filter(p => p.level === level);
                const section = document.querySelector('#cardGroups [data-group="' + level + '"]');
                section.hidden = rows.length === 0;
                $(level === 'college' ? 'cardGridCollege' : 'cardGridShs').innerHTML = rows.map(cardHtml).join('');
                $(level === 'college' ? 'cardCountCollege' : 'cardCountShs').textContent = payload.groups[level];
            });
            ui.renderPagination($('cardPagination'), payload.pagination, page => { cards.page = page; writeCurrent(true); loadCards(); });
            $('cardPagination').hidden = Number(payload.pagination.total_pages) <= 1;
            cardStates('list');
            $('cardPagination').hidden = Number(payload.pagination.total_pages) <= 1;
        } catch (error) {
            if (ticket !== cards.request) return;
            cardStates('error', friendly(error));
        }
    }
    $('cardSearch').addEventListener('input', ui.debounce(() => { if ($('cardSearch').value.trim().length === 1) return; cards.page = 1; writeCurrent(false); loadCards(); }, 300));
    $('cardLevel').addEventListener('click', event => { const b = event.target.closest('[data-level]'); if (!b || b.dataset.level === cards.level) return; cards.level = b.dataset.level; cards.page = 1; writeCurrent(true); loadCards(); });
    $('cardClear').addEventListener('click', () => { $('cardSearch').value = ''; cards.level = 'all'; cards.page = 1; writeCurrent(true); loadCards(); });
    $('cardRetry').addEventListener('click', loadCards);

    // ---------- the one dialog (details, create, edit) ----------
    const dialogEl = $('subjectDialog');
    const dialog = ui.createModal(dialogEl, { restoreFocus: () => document.querySelector('#subjectViews [aria-selected="true"]') });
    const form = $('subjectForm');
    const errorBox = '<div class="form-error" role="alert" hidden></div>';
    function setDialog(title, context, body, buttons, wide) {
        $('subjectDialogTitle').textContent = title;
        $('subjectDialogContext').textContent = context || '';
        $('subjectDialogBody').innerHTML = body;
        dialogEl.querySelector('.modal-dialog').classList.toggle('subject-dialog--wide', Boolean(wide));
        const footer = $('subjectDialogFooter');
        footer.innerHTML = '';
        buttons.forEach(spec => {
            const button = document.createElement('button');
            button.type = spec.submit ? 'submit' : 'button';
            button.className = spec.cls || 'button-muted';
            button.innerHTML = spec.html;
            if (spec.disabled) button.disabled = true;
            if (spec.title) button.title = spec.title;
            if (spec.click) button.addEventListener('click', spec.click);
            footer.appendChild(button);
        });
    }
    const closeButton = { html: 'Close', click: () => closeDialog() };
    function closeDialog() { state.dialog = null; dialog.close(); window.CSMSCIMF?.updateContext?.(); }
    $('subjectDialogX').addEventListener('click', closeDialog);
    $('subjectBackdrop').addEventListener('click', closeDialog);
    dialogEl.addEventListener('keydown', event => { if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeDialog(); } }, true);
    const detail = rows => '<dl class="csms-detail-list subject-detail">' + rows.filter(Boolean).map(([label, value]) => '<dt>' + esc(label) + '</dt><dd>' + value + '</dd>').join('') + '</dl>';
    const field = (label, control, cls) => '<label class="form-field ' + (cls || '') + '"><span>' + esc(label) + '</span>' + control + '</label>';
    const input = (name, value, extra) => '<input name="' + name + '" value="' + esc(value ?? '') + '" ' + (extra || '') + '/>';

    async function fetchSubject(id) { return (await ui.fetchJson(api, { action: 'get', id })).subject; }

    async function openDetails(id, mode) {
        try {
            const subject = await fetchSubject(id);
            state.dialog = { kind: 'details', subject };
            const used = subject.placements || [];
            const usedHtml = !canBrowse ? '' : '<h3 class="subject-section-title">Used in</h3>' + (used.length
                ? '<div class="csms-page-table table-scroll" tabindex="0" aria-label="Where this subject is placed"><table class="csms-ctable"><thead><tr><th>Program</th><th>Curriculum</th><th>Version</th><th>Year</th><th>Semester</th><th>Version status</th></tr></thead><tbody>' +
                    used.map(p => '<tr><td>' + esc(p.program_code) + '</td><td>' + esc(p.curriculum_code) + '</td><td>' + esc(p.version_label) + '</td><td>' + esc(yearInfo(p.year_level).label) + '</td><td>' + esc(semesterInfo(p.semester).label) + '</td><td>' + esc(p.version_editable ? 'Editable' : p.version_status === 'archived' ? 'Archived' : 'Locked') + '</td></tr>').join('') + '</tbody></table></div>'
                : '<p class="csms-sub">Not placed in any curriculum version you can see.</p>');
            const lock = subject.editable === false && subject.reason_text ? '<p class="subject-lock" role="note"><i class="fa-solid fa-lock" aria-hidden="true"></i>' + esc(subject.reason_text) + '</p>' : '';
            const buttons = [closeButton];
            if (canUpdate) {
                buttons.push({ html: '<i class="fa-solid fa-pen" aria-hidden="true"></i> Edit', disabled: subject.editable === false, title: subject.editable === false ? subject.reason_text : '', click: () => openEdit(id, subject) });
                if (subject.status === 'active') buttons.push({ html: '<i class="fa-solid fa-box-archive" aria-hidden="true"></i> Archive', cls: 'button-muted', disabled: subject.editable === false, title: subject.editable === false ? subject.reason_text : '', click: () => confirmArchive(subject) });
            }
            setDialog(subject.subject_code, subject.subject_title, errorBox + lock + detail([
                ['Code', esc(subject.subject_code)], ['Title', esc(subject.subject_title)], ['Units', esc(num(subject.units))],
                subject.lecture_units !== null && subject.lecture_units !== undefined ? ['Lecture units', esc(num(subject.lecture_units))] : null,
                subject.laboratory_units !== null && subject.laboratory_units !== undefined ? ['Laboratory units', esc(num(subject.laboratory_units))] : null,
                ['Status', statusChip(subject.status)], subject.description ? ['Description', esc(subject.description)] : null
            ]) + usedHtml, buttons, true);
            dialog.open();
            window.CSMSCIMF?.updateContext?.();
            if (mode === 'archive') confirmArchive(subject);
        } catch (error) { ui.showToast(friendly(error), 'error'); }
    }

    function confirmArchive(subject) {
        $('subjectDialogFooter').innerHTML = '';
        const box = form.querySelector('.form-error');
        if (box) { box.hidden = false; box.textContent = 'Archive ' + subject.subject_code + '? It leaves the active list and can be restored from Settings > Archives.'; }
        const footer = $('subjectDialogFooter');
        [{ html: 'Cancel', click: () => openDetails(subject.id) }, { html: 'Archive subject', cls: 'button-danger', click: async () => {
            const data = new FormData(); data.set('action', 'archive'); data.set('id', subject.id);
            try { await ui.postForm(api, data); ui.showToast('Subject archived.', 'success'); closeDialog(); await refreshAll(); }
            catch (error) { ui.showFormError(form, friendly(error)); }
        } }].forEach(spec => { const b = document.createElement('button'); b.type = 'button'; b.className = spec.cls || 'button-muted'; b.innerHTML = spec.html; b.addEventListener('click', spec.click); footer.appendChild(b); });
    }
    async function activate(id) {
        try {
            const s = await fetchSubject(id);
            const data = new FormData();
            data.set('action', 'update'); data.set('id', s.id);
            ['subject_code', 'subject_title', 'units'].forEach(k => data.set(k, s[k]));
            data.set('description', s.description ?? ''); data.set('lecture_units', s.lecture_units ?? ''); data.set('laboratory_units', s.laboratory_units ?? ''); data.set('status', 'active');
            await ui.postForm(api, data); ui.showToast('Subject activated.', 'success'); await refreshAll();
        } catch (error) { ui.showToast(friendly(error), 'error'); }
    }
    async function refreshAll() { await Promise.all([state.view === 'all' || list.loaded ? loadList() : null, placements.loaded ? loadPlacements() : null].filter(Boolean)); }

    function formFields(subject) {
        return '<div class="subject-form-grid">' +
            field('Code', input('subject_code', subject?.subject_code, 'maxlength="60" required autocomplete="off"')) +
            field('Title', input('subject_title', subject?.subject_title, 'maxlength="255" required autocomplete="off"')) +
            field('Units', input('units', subject ? num(subject.units) : '', 'inputmode="decimal" required'), 'is-third') +
            field('Lecture units (optional)', input('lecture_units', subject?.lecture_units === null || subject?.lecture_units === undefined ? '' : num(subject.lecture_units), 'inputmode="decimal"'), 'is-third') +
            field('Laboratory units (optional)', input('laboratory_units', subject?.laboratory_units === null || subject?.laboratory_units === undefined ? '' : num(subject.laboratory_units), 'inputmode="decimal"'), 'is-third') +
            field('Description (optional)', '<textarea name="description" rows="2" maxlength="4000">' + esc(subject?.description || '') + '</textarea>', 'is-full') +
            field('Status', '<select class="csms-select" name="status"><option value="active"' + (!subject || subject.status === 'active' ? ' selected' : '') + '>Active</option><option value="inactive"' + (subject?.status === 'inactive' ? ' selected' : '') + '>Inactive</option></select>') +
            '</div>';
    }
    async function openEdit(id, known) {
        try {
            const subject = known || await fetchSubject(id);
            if (subject.editable === false) { ui.showToast(subject.reason_text || 'This subject cannot be edited.', 'warning'); return openDetails(id); }
            state.dialog = { kind: 'edit', subject, submit: () => submitForm('update', { id: subject.id }) };
            setDialog('Edit subject', subject.subject_code, errorBox + formFields(subject), [{ html: 'Cancel', click: () => openDetails(id) }, { html: 'Save subject', cls: 'primary-action-button', submit: true }]);
            dialog.open();
            form.elements.subject_code.focus();
        } catch (error) { ui.showToast(friendly(error), 'error'); }
    }

    // Create: the master record only; placing it in a version is optional, collapsed, and only for roles that may do it.
    let placementCascade = null;
    function yearOptions(item) {
        const shs = item && /senior high|shs|strand/i.test(String(item.program_type || state.programs.get(String(item.id))?.level || ''));
        return shs ? ['Grade 11', 'Grade 12'] : ['1st Year', '2nd Year', '3rd Year', '4th Year'];
    }
    function placementHtml() {
        return '<button type="button" class="subject-section-toggle" id="placementToggle" aria-expanded="false" aria-controls="placementFields"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i> Also place in a curriculum version</button>' +
            '<div class="subject-placement-fields" id="placementFields" hidden><div class="subject-placement-bar">' +
            ['program', 'curriculum', 'version'].map((key, i) => '<div class="form-field"><label for="dlg' + key + 'Search"><span>' + ['Program / Strand', 'Curriculum', 'Curriculum Version'][i] + '</span></label><span class="record-autocomplete"><input type="search" id="dlg' + key + 'Search" autocomplete="off" ' + (i ? 'disabled' : '') + '/><input type="hidden" id="dlg' + key + 'Id" name="dlg_' + key + '"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><span class="record-autocomplete-options" id="dlg' + key + 'Options" role="listbox" hidden></span></span></div>').join('') +
            '</div><div class="subject-form-grid"><label class="form-field"><span>Year level</span><select class="csms-select" name="year_level" id="dlgYear"></select></label><label class="form-field"><span>Semester</span><select class="csms-select" name="semester" id="dlgSemester"><option value="">Select semester</option><option>1st Semester</option><option>2nd Semester</option></select></label></div></div>';
    }
    function setYearChoices(item) {
        const select = $('dlgYear'); if (!select) return;
        select.innerHTML = '<option value="">Select year level</option>' + yearOptions(item).map(y => '<option>' + y + '</option>').join('');
    }
    function openCreate() {
        state.dialog = { kind: 'create', submit: () => submitForm('create', {}) };
        setDialog('New subject', '', errorBox + formFields(null) + (canPlace ? placementHtml() : ''), [{ html: 'Cancel', click: closeDialog }, { html: 'Create subject', cls: 'primary-action-button', submit: true }]);
        dialog.open();
        form.elements.subject_code.focus();
        if (!canPlace) return;
        const toggle = $('placementToggle'), fields = $('placementFields');
        const setOpen = open => { fields.hidden = !open; toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); toggle.firstElementChild.className = 'fa-solid ' + (open ? 'fa-chevron-down' : 'fa-chevron-right'); };
        toggle.addEventListener('click', () => setOpen(fields.hidden));
        placementCascade = ui.createCascadingAutocomplete({ uiManaged: false, levels: ['program', 'curriculum', 'version'].map((key, index) => ({
            input: $('dlg' + key + 'Search'), valueInput: $('dlg' + key + 'Id'), list: $('dlg' + key + 'Options'), minimumLength: 0, showSuggestionsOnFocus: true, showSuggestionsOnEnable: true, autoSelectSingle: false,
            placeholder: index === 0 ? 'Search program code or name' : 'Search ' + key, disabledPlaceholder: index === 1 ? 'Choose program first' : 'Choose curriculum first',
            search: async query => { const ids = {}; [['program_id', 0], ['curriculum_id', 1]].forEach(([k, i]) => { const id = placementCascade?.get(i)?.getSelectedId?.(); if (id) ids[k] = id; }); return (await ui.fetchJson(api, { action: 'context_options', level: key, ...ids, search: query })).options; },
            format: item => ({ label: [item.code, item.title].filter(Boolean).join(' — '), code: item.code, title: item.title || '' })
        })), onChange: (index, item) => { if (index === 0) setYearChoices(item); } });
        setYearChoices(null);
        // From the By program tab: expanded and prefilled with the page's program, curriculum and version.
        if (state.view === 'program' && ctxId(2) && state.ctx[0] && state.ctx[1] && state.ctx[2]) {
            setOpen(true);
            [0, 1, 2].forEach(i => placementCascade.get(i).setSelected(state.ctx[i], false));
            setYearChoices(state.ctx[0]);
        }
    }
    async function submitForm(action, extra) {
        ui.clearFormError(form);
        const data = new FormData();
        ['subject_code', 'subject_title', 'units', 'lecture_units', 'laboratory_units', 'description', 'status'].forEach(name => data.set(name, form.elements.namedItem(name).value.trim?.() ?? form.elements.namedItem(name).value));
        data.set('action', action);
        Object.entries(extra || {}).forEach(([k, v]) => data.set(k, v));
        if (action === 'create' && canPlace && $('placementFields') && !$('placementFields').hidden) {
            const version = placementCascade?.get(2)?.getSelectedId?.();
            if (!version) { ui.showFormError(form, 'Select a curriculum version, or collapse "Also place in a curriculum version".'); return; }
            if (!$('dlgYear').value || !$('dlgSemester').value) { ui.showFormError(form, 'Choose a year level and a semester for the placement.'); return; }
            data.set('curriculum_version_id', version); data.set('year_level', $('dlgYear').value); data.set('semester', $('dlgSemester').value);
        }
        const submit = $('subjectDialogFooter').querySelector('[type="submit"]');
        if (submit) submit.disabled = true;
        try {
            const payload = await ui.postForm(api, data);
            if (payload.placement_error) ui.showToast('Subject created, but its placement was not saved: ' + payload.placement_error, 'warning');
            else ui.showToast(action === 'create' ? (payload.placement ? 'Subject created and placed.' : 'Subject created.') : 'Subject saved.', 'success');
            closeDialog();
            await refreshAll();
        } catch (error) { ui.showFormError(form, friendly(error)); }
        finally { if (submit) submit.disabled = false; }
    }
    form.addEventListener('submit', event => { event.preventDefault(); state.dialog?.submit?.(); });
    $('newSubjectButton')?.addEventListener('click', openCreate);

    // CIMF reads what the page shows; it never changes a subject.
    window.CSMSPageCIMFContext = () => {
        const out = { academic_program_id: ctxId(0), curriculum_id: ctxId(1), curriculum_version_id: ctxId(2) };
        if (state.dialog?.subject) out.subject_id = state.dialog.subject.id;
        return out;
    };

    // ---------- start ----------
    async function start() {
        readUrl();
        listStates('loading');
        const programFromUrl = new URLSearchParams(window.location.search).get('program_id');
        if (canBrowse && state.view === 'program' && programFromUrl) { initCascade(); resolveContext(programFromUrl); }
        await loadPrograms();
        showView(state.view, false);
        const open = Number(root.dataset.openSubject);
        if (open > 0) openDetails(open);
    }
    start();
}());
