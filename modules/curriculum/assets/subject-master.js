(function () {
    'use strict';

    // Subjects (Subject Master). No screen lists every subject: the page is program and strand cards, one curriculum version (the table view)
    // or a capped search result. The "not placed anywhere" card opens a paged list. One shared dialog serves details, create and edit.
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
    const SEARCH_MIN = 2, FIND_MAX = 20, CARD_FILTER_MIN_CARDS = 12;
    const PER_PAGE = ['10', '20', '50', '100'];
    const SORTS = ['code', 'title', 'units', 'status'];
    const VIEWS = ['cards', 'program', 'unplaced'];
    const state = { view: VIEWS.includes(root.dataset.view) && (root.dataset.view === 'cards' || canBrowse) ? root.dataset.view : 'cards', dialog: null, programs: new Map(), ctx: [null, null, null], programTicket: 0 };
    const list = { page: 1, sort: '', direction: 'asc', request: 0, records: new Map(), loaded: false };
    const placements = { rows: [], totals: null, loaded: false };
    const cards = { level: 'all', request: 0, loaded: false, total: 0 };

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
    const OWN = ['view', 'q', 'status', 'sort', 'dir', 'per_page', 'page', 'pq', 'year', 'sem', 'pst', 'subject_id', 'cf', 'lvl'];
    function writeUrl(extra, push) {
        const params = new URLSearchParams(window.location.search);
        OWN.forEach(key => params.delete(key));
        if (state.view !== 'cards') params.set('view', state.view);
        Object.entries(extra || {}).forEach(([key, value]) => { if (value !== '' && value !== null && value !== undefined && !(key === 'per_page' && String(value) === '20') && !(key === 'page' && Number(value) <= 1) && !(key === 'dir' && value === 'asc')) params.set(key, String(value)); });
        const query = params.toString();
        const url = window.location.pathname + (query ? '?' + query : '');
        if (url !== window.location.pathname + window.location.search) window.history[push ? 'pushState' : 'replaceState'](null, '', url);
    }
    const unplacedExtra = () => ({ q: $('subjectSearch').value.trim(), status: $('subjectStatus').value, sort: list.sort, dir: list.direction, per_page: $('subjectPerPage').value, page: list.page });
    const programExtra = () => ({ pq: $('programSearch2').value.trim(), year: $('programYear').value, sem: $('programSemester').value, pst: $('programStatus').value });
    const cardExtra = () => ({ cf: $('cardFilter').value.trim(), lvl: cards.level === 'all' ? '' : cards.level });
    function writeCurrent(push) { writeUrl(state.view === 'unplaced' ? unplacedExtra() : state.view === 'program' ? programExtra() : cardExtra(), push); }
    function readUrl() {
        const p = new URLSearchParams(window.location.search);
        $('subjectSearch').value = p.get('q') || '';
        $('subjectStatus').value = ['active', 'inactive'].includes(p.get('status')) ? p.get('status') : '';
        $('subjectPerPage').value = PER_PAGE.includes(p.get('per_page')) ? p.get('per_page') : '20';
        list.sort = SORTS.includes(p.get('sort')) ? p.get('sort') : '';
        list.direction = p.get('dir') === 'desc' ? 'desc' : 'asc';
        list.page = Math.max(1, parseInt(p.get('page'), 10) || 1);
        $('programSearch2').value = p.get('pq') || '';
        state.pendingYear = p.get('year') || ''; state.pendingSemester = p.get('sem') || '';
        $('programStatus').value = ['active', 'inactive'].includes(p.get('pst')) ? p.get('pst') : '';
        $('cardFilter').value = p.get('cf') || '';
        cards.level = ['college', 'shs'].includes(p.get('lvl')) ? p.get('lvl') : 'all';
    }

    // ---------- views (no tabs: cards, one version, or the unplaced list) ----------
    function showView(view, push) {
        if (view !== 'cards' && !canBrowse) view = 'cards';
        if (view === 'program' && !cascade) view = 'cards';
        state.view = view;
        root.dataset.view = view;
        const table = view === 'program';
        $('viewCards').hidden = view !== 'cards';
        $('cardTools').hidden = view !== 'cards';
        $('viewProgram').hidden = !table;
        $('viewUnplaced').hidden = view !== 'unplaced';
        if ($('subjectContextBar')) $('subjectContextBar').hidden = !table;
        if ($('programBackRow')) $('programBackRow').hidden = !(table || view === 'unplaced');
        writeCurrent(push);
        if (view === 'unplaced') loadList(); else if (table) loadPlacements(); else loadCards();
    }

    // ---------- global search: a capped result panel (at most 20 subjects) ----------
    const find = { request: 0, items: [], active: -1 };
    const findInput = $('subjectFind'), findPanel = $('findResults');
    function closeFind() { findPanel.hidden = true; findInput.setAttribute('aria-expanded', 'false'); find.active = -1; findInput.removeAttribute('aria-activedescendant'); }
    function findRow(row, index) {
        const usage = row.usage_count === null || row.usage_count === undefined ? null : Number(row.usage_count);
        const programs = Array.isArray(row.program_context) && row.program_context.length ? ' · ' + row.program_context.join(', ') : '';
        const used = usage === null ? '' : usage === 0 ? 'Not placed in any curriculum' : 'Used in ' + usage + (usage === 1 ? ' version' : ' versions') + programs;
        return '<button type="button" class="subject-hit" role="option" id="findOption' + index + '" data-id="' + Number(row.id) + '" aria-selected="false">' +
            '<span class="subject-hit__main"><strong class="subject-hit__code">' + esc(row.subject_code) + '</strong><span class="subject-hit__title">' + esc(row.subject_title) + '</span></span>' +
            '<span class="subject-hit__meta"><span class="subject-hit__units">' + esc(num(row.units)) + ' units</span>' + statusChip(row.status) + (used ? '<span class="subject-hit__used">' + esc(used) + '</span>' : '') + '</span></button>';
    }
    async function runFind() {
        const q = findInput.value.trim();
        if (q.length < SEARCH_MIN) { closeFind(); return; }
        const ticket = ++find.request;
        findPanel.hidden = false; findInput.setAttribute('aria-expanded', 'true');
        findPanel.innerHTML = '<p class="subject-find__note" role="status">Searching…</p>';
        try {
            const payload = await ui.fetchJson(api, { action: 'list', search: q, per_page: FIND_MAX, page: 1 });
            if (ticket !== find.request) return;
            const total = Number(payload.pagination.total_records);
            find.items = payload.subjects || [];
            find.active = -1;
            if (!find.items.length) { findPanel.innerHTML = '<p class="subject-find__note" role="status">No subject matches “' + esc(q) + '”.</p>'; return; }
            findPanel.innerHTML = (total > FIND_MAX ? '<p class="subject-find__note subject-find__note--top" role="status">Showing the first ' + FIND_MAX + '. Refine your search (' + total + ' matches)</p>' : '') + find.items.map(findRow).join('');
        } catch (error) {
            if (ticket !== find.request) return;
            findPanel.innerHTML = '<p class="subject-find__note" role="alert">The search could not be completed. ' + esc(friendly(error)) + '</p>';
        }
    }
    function setActive(index) {
        const rows = [...findPanel.querySelectorAll('.subject-hit')];
        if (!rows.length) return;
        find.active = (index + rows.length) % rows.length;
        rows.forEach((row, i) => { row.setAttribute('aria-selected', i === find.active ? 'true' : 'false'); row.classList.toggle('is-active', i === find.active); });
        rows[find.active].scrollIntoView({ block: 'nearest' });
        findInput.setAttribute('aria-activedescendant', rows[find.active].id);
    }
    findInput.addEventListener('input', ui.debounce(() => { if (findInput.value.trim().length === 1) return; runFind(); }, 300));
    findInput.addEventListener('keydown', event => {
        if (event.key === 'ArrowDown') { event.preventDefault(); if (findPanel.hidden) runFind(); else setActive(find.active + 1); }
        else if (event.key === 'ArrowUp') { event.preventDefault(); setActive(find.active - 1); }
        else if (event.key === 'Enter') { const rows = findPanel.querySelectorAll('.subject-hit'); const row = rows[find.active >= 0 ? find.active : 0]; if (row && !findPanel.hidden) { event.preventDefault(); openFromFind(Number(row.dataset.id)); } }
        else if (event.key === 'Escape') { closeFind(); }
    });
    findInput.addEventListener('focus', () => { if (findInput.value.trim().length >= SEARCH_MIN && findPanel.querySelector('.subject-hit, .subject-find__note')) { findPanel.hidden = false; findInput.setAttribute('aria-expanded', 'true'); } });
    findPanel.addEventListener('mousedown', event => event.preventDefault());
    findPanel.addEventListener('click', event => { const row = event.target.closest('.subject-hit'); if (row) openFromFind(Number(row.dataset.id)); });
    document.addEventListener('click', event => { if (!event.target.closest('.subject-find')) closeFind(); });
    function openFromFind(id) { closeFind(); openDetails(id); }

    // ---------- program and strand cards ----------
    const ICON = { college: 'fa-graduation-cap', shs: 'fa-book-open-reader' };
    const STATUS_CHIPS = { active: ['Active', 'csms-chip--active'], draft: ['Draft', 'csms-chip--draft'], approved: ['Approved', 'csms-chip--approved'], in_review: ['In review', 'csms-chip--under_review'], returned: ['Returned', 'csms-chip--returned'], archived: ['Archived', 'csms-chip--archived'] };
    const plural = (n, one, many) => n + ' ' + (n === 1 ? one : (many || one + 's'));
    // One status chip per card: Active if any version is active, else Draft if any is a draft, else Archived. No chip when the reader can see no version.
    function cardChip(statuses) {
        const s = statuses || {};
        if (!Object.values(s).some(n => Number(n) > 0)) return null;
        if (Number(s.active) > 0) return STATUS_CHIPS.active;
        if (Number(s.draft) > 0) return STATUS_CHIPS.draft;
        return STATUS_CHIPS.archived;
    }
    function statusBreakdown(statuses) {
        return ['active', 'draft', 'approved', 'in_review', 'returned', 'archived'].filter(k => Number((statuses || {})[k]) > 0).map(k => statuses[k] + ' ' + STATUS_CHIPS[k][0]).join(', ');
    }
    function cardSummary(program) {
        const breakdown = statusBreakdown(program.version_statuses);
        return plural(Number(program.curriculum_count), 'curriculum', 'curricula') + ' \u00b7 ' + plural(Number(program.version_count), 'version') + (breakdown ? ' (' + breakdown + ')' : '') + ' \u00b7 ' + plural(Number(program.subject_count), 'subject');
    }
    function skeletonCard() {
        return '<div class="csms-program-card csms-program-card--loading" aria-hidden="true"><span class="csms-program-card__main"><span class="csms-skeleton" style="width:40px;height:40px;flex:none"></span><span class="csms-program-card__text"><span class="csms-skeleton" style="width:45%;height:22px"></span><span class="csms-skeleton" style="width:90%;height:14px"></span></span></span><span class="csms-program-card__foot"><span class="csms-skeleton" style="width:40%;height:14px"></span></span></div>';
    }
    function cardHref(program) { return 'subjects.php?view=program&program_id=' + Number(program.id); }
    function cardHtml(program) {
        const level = program.level === 'shs' ? 'shs' : 'college';
        const muted = Number(program.curriculum_count) === 0;
        const summary = muted ? 'No curriculum yet' : cardSummary(program);
        const main = '<span class="csms-program-card__main"><span class="csms-program-card__head"><span class="csms-program-card__icon" aria-hidden="true"><i class="fa-solid ' + ICON[level] + '"></i></span>' +
            '<span class="csms-program-card__code">' + esc(program.program_code) + '</span></span><span class="csms-program-card__name">' + esc(program.program_name) + '</span></span>';
        const chip = cardChip(program.version_statuses);
        const n = Number(program.subject_count);
        const foot = muted ? '<span class="csms-program-card__foot"><span class="csms-program-card__count">No curriculum yet</span>' :
            '<span class="csms-program-card__foot"><span class="csms-program-card__count"><strong>' + n + '</strong> ' + (n === 1 ? 'subject' : 'subjects') + '</span>' + (chip ? '<span class="csms-chip ' + chip[1] + '">' + chip[0] + '</span>' : '');
        return '<a class="csms-program-card csms-program-card--' + level + (muted ? ' csms-program-card--muted' : '') + '" href="' + cardHref(program) + '" title="' + esc(summary) + '" aria-label="Open subjects of ' + esc(program.program_code) + '. ' + esc(summary) + '">' + main +
            foot + '<i class="fa-solid fa-arrow-right csms-program-card__arrow" aria-hidden="true"></i></span></a>';
    }
    function unplacedCardHtml(count) {
        return '<a class="csms-program-card csms-program-card--muted" href="subjects.php?view=unplaced" aria-label="Open the ' + plural(count, 'subject') + ' not placed in any curriculum">' +
            '<span class="csms-program-card__main"><span class="csms-program-card__head"><span class="csms-program-card__icon" aria-hidden="true"><i class="fa-solid fa-box-open"></i></span><span class="csms-program-card__name csms-program-card__name--lead">Not placed in any curriculum</span></span></span>' +
            '<span class="csms-program-card__foot"><span class="csms-program-card__count"><strong>' + count + '</strong> ' + (count === 1 ? 'subject' : 'subjects') + '</span><i class="fa-solid fa-arrow-right csms-program-card__arrow" aria-hidden="true"></i></span></a>';
    }
    function cardStates(which, message) {
        $('cardLoading').hidden = which !== 'loading';
        $('cardError').hidden = which !== 'error';
        $('cardNoMatch').hidden = which !== 'nomatch';
        $('cardGroups').hidden = which !== 'list';
        if (which === 'error') $('cardErrorText').textContent = message || '';
    }
    async function loadCards() {
        const ticket = ++cards.request;
        document.querySelectorAll('#cardLevel [data-level]').forEach(b => b.setAttribute('aria-pressed', b.dataset.level === cards.level ? 'true' : 'false'));
        $('cardLoading').innerHTML = skeletonCard().repeat(5);
        cardStates('loading');
        const params = { action: 'program_cards', page: 1, per_page: 100 };
        const q = $('cardFilter').value.trim();
        if (q.length >= SEARCH_MIN) params.search = q;
        if (cards.level !== 'all') params.level = cards.level;
        try {
            const payload = await ui.fetchJson(api, params);
            if (ticket !== cards.request) return;
            cards.loaded = true;
            cards.total = Number(payload.pagination.total_records);
            (payload.programs || []).forEach(p => state.programs.set(String(p.id), { id: p.id, code: p.program_code, name: p.program_name, level: p.level }));
            $('cardCount').textContent = plural(cards.total, 'program');
            if (cards.total > 100) $('cardCount').textContent = '100 of ' + cards.total + ' programs';
            $('cardFilterWrap').hidden = !(cards.total > CARD_FILTER_MIN_CARDS || q.length >= SEARCH_MIN);
            const unplaced = Number(payload.unplaced_count) || 0;
            if (!payload.programs.length) { cardStates(q.length >= SEARCH_MIN || cards.level !== 'all' ? 'nomatch' : 'list'); }
            else cardStates('list');
            ['college', 'shs'].forEach(level => {
                const rows = (payload.programs || []).filter(p => p.level === level);
                const section = document.querySelector('#cardGroups [data-group="' + level + '"]');
                const hiddenByLevel = cards.level !== 'all' && cards.level !== level;
                section.hidden = hiddenByLevel;
                const key = level === 'college' ? 'College' : 'Shs';
                $('cardGrid' + key).innerHTML = rows.map(cardHtml).join('');
                $('cardCount' + key).textContent = payload.groups[level];
                $('cardEmpty' + key).hidden = rows.length !== 0 || q.length >= SEARCH_MIN;
            });
            const showUnplaced = unplaced > 0 && cards.level === 'all' && q.length < SEARCH_MIN && canBrowse;
            $('unplacedSection').hidden = !showUnplaced;
            $('unplacedGrid').innerHTML = showUnplaced ? unplacedCardHtml(unplaced) : '';
            if (q.length >= SEARCH_MIN && !payload.programs.length) cardStates('nomatch');
        } catch (error) {
            if (ticket !== cards.request) return;
            cardStates('error', friendly(error));
        }
    }
    $('cardFilter').addEventListener('input', ui.debounce(() => { if ($('cardFilter').value.trim().length === 1) return; writeCurrent(false); loadCards(); }, 300));
    $('cardLevel').addEventListener('click', event => { const b = event.target.closest('[data-level]'); if (!b || b.dataset.level === cards.level) return; cards.level = b.dataset.level; writeCurrent(true); loadCards(); });
    $('cardClear').addEventListener('click', () => { $('cardFilter').value = ''; cards.level = 'all'; writeCurrent(true); loadCards(); });
    $('cardRetry').addEventListener('click', loadCards);

    // ---------- the unplaced list (paged; the same table, sort and row actions) ----------
    function listStates(which, message) {
        $('subjectLoading').hidden = which !== 'loading';
        $('subjectEmpty').hidden = which !== 'empty';
        $('subjectNoMatch').hidden = which !== 'nomatch';
        $('subjectError').hidden = which !== 'error';
        $('subjectTableWrap').hidden = which !== 'list';
        $('subjectPagination').hidden = which !== 'list';
        if (which === 'error') $('subjectErrorText').textContent = message || '';
    }
    const filtering = () => Boolean($('subjectSearch').value.trim().length >= SEARCH_MIN || $('subjectStatus').value);
    function listParams() {
        const out = { action: 'list', page: list.page, per_page: $('subjectPerPage').value, placed: 'unplaced' };
        const q = $('subjectSearch').value.trim();
        if (q.length >= SEARCH_MIN) out.search = q;
        if ($('subjectStatus').value) out.status = $('subjectStatus').value;
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
        const box = $('subjectChips');
        box.hidden = chips.length === 0;
        box.innerHTML = chips.map(([field, label]) => '<button type="button" class="csms-chip csms-chip--type subject-chip" data-clear="' + field + '" aria-label="Remove filter ' + esc(label) + '">' + esc(label) + ' <i class="fa-solid fa-xmark" aria-hidden="true"></i></button>').join('') +
            (chips.length > 1 ? '<button type="button" class="button-muted subject-chip" data-clear="all">Clear all</button>' : '');
    }
    function rowHtml(row) {
        return '<tr class="csms-row-click" tabindex="0" data-row="' + Number(row.id) + '" aria-haspopup="dialog" aria-label="Open ' + esc(row.subject_code) + '">' +
            '<td><strong class="csms-ctable__code">' + esc(row.subject_code) + '</strong></td><td class="subject-title-cell">' + esc(row.subject_title) + '</td>' +
            '<td class="csms-ctable__num">' + esc(num(row.units)) + unitsSub(row) + '</td><td>' + statusChip(row.status) + '</td>' +
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
    ['subjectStatus', 'subjectPerPage'].forEach(id => $(id).addEventListener('change', reload));
    $('subjectRetry').addEventListener('click', () => { list.loaded = false; loadList(); });
    function clearFilters() { $('subjectSearch').value = ''; $('subjectStatus').value = ''; reload(); }
    $('subjectClearFilters').addEventListener('click', clearFilters);
    $('subjectChips').addEventListener('click', event => {
        const button = event.target.closest('[data-clear]'); if (!button) return;
        const field = button.dataset.clear;
        if (field === 'all') return clearFilters();
        if (field === 'search') $('subjectSearch').value = '';
        else if (field === 'status') $('subjectStatus').value = '';
        reload();
    });
    document.querySelectorAll('.subject-sort').forEach(button => {
        button.dataset.label = button.textContent.trim();
        button.addEventListener('click', () => {
            list.direction = list.sort === button.dataset.sort && list.direction === 'asc' ? 'desc' : 'asc';
            list.sort = button.dataset.sort; list.page = 1; writeCurrent(true); loadList();
        });
    });
    window.addEventListener('popstate', () => { readUrl(); const view = new URLSearchParams(window.location.search).get('view'); showView(VIEWS.includes(view) ? view : 'cards', false); });
    $('subjectRows').addEventListener('click', event => {
        const menu = event.target.closest('[data-menu]');
        if (menu) { const row = list.records.get(Number(menu.dataset.menu)); if (row) rowMenu?.open(menu, menuItems(row)); return; }
        const tr = event.target.closest('[data-row]'); if (tr) openDetails(Number(tr.dataset.row));
    });
    $('subjectRows').addEventListener('keydown', event => { if (event.target.matches?.('[data-row]') && ['Enter', ' '].includes(event.key)) { event.preventDefault(); openDetails(Number(event.target.dataset.row)); } });

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
        if ($('programExport')) $('programExport').hidden = which !== 'body';
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
    async function refreshAll() { await Promise.all([state.view === 'unplaced' ? loadList() : null, state.view === 'cards' && cards.loaded ? loadCards() : null, placements.loaded ? loadPlacements() : null].filter(Boolean)); }

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
    async function loadProgramNames() {
        // The names of programs, for the bar's prefill when a program is opened by address.
        if (!canBrowse) return;
        try {
            const payload = await ui.fetchJson(api, { action: 'program_filter_options' });
            (payload.programs || []).forEach(program => state.programs.set(String(program.id), { id: program.id, code: program.code, name: program.name, level: program.level }));
        } catch (error) { /* the cards still work */ }
    }
    async function start() {
        readUrl();
        const programFromUrl = new URLSearchParams(window.location.search).get('program_id');
        if (canBrowse && state.view === 'program' && programFromUrl) { initCascade(); await loadProgramNames(); resolveContext(programFromUrl); }
        showView(state.view, false);
        const open = Number(root.dataset.openSubject);
        if (open > 0) openDetails(open);
    }
    start();
}());
