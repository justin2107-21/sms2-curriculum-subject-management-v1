(function () {
    'use strict';
    // Users page (Setup > Users): search, role and status filters, and column sorting, all in the browser.
    // The rows come from the server-rendered table; this script only hides and reorders them (the Edit User buttons keep working).
    // State lives in the query string (q, role, status, sort, dir) so a refresh, the back button and the reload after Create / Edit User
    // return to the same view. With no sort in the address the rows stay in the server's order.

    const ROLE_ORDER = ['Super Admin', 'Admin', 'Staff', 'Acad — Office', 'Acad — Faculty'];
    const NO_ROLE = 'No RBAC role';
    const SORT_KEYS = ['username', 'name', 'email', 'role', 'status', 'created'];
    const collator = new Intl.Collator('en', { sensitivity: 'base', numeric: true });

    // "Acad - Office", "Acad – Office" and "Acad — Office" are the same role.
    const norm = text => String(text || '').replace(/[‐-―−]/g, '-').replace(/\s+/g, ' ').trim().toLowerCase();
    const ORDER_KEYS = ROLE_ORDER.map(norm);
    // Rank of one role name: its place in ROLE_ORDER, then every other role, then no role at all.
    const roleRank = name => { const i = ORDER_KEYS.indexOf(norm(name)); return i >= 0 ? i : (norm(name) === norm(NO_ROLE) ? ORDER_KEYS.length + 1 : ORDER_KEYS.length); };
    // An account with several roles sorts by its highest-ranked one.
    const bestRank = roles => roles.length ? Math.min(...roles.map(roleRank)) : ORDER_KEYS.length + 1;
    const splitRoles = text => { const value = String(text || '').trim(); return !value || norm(value) === norm(NO_ROLE) ? [] : value.split(',').map(part => part.trim()).filter(Boolean); };
    const parseCreated = text => { const t = Date.parse(String(text || '').trim().replace(' ', 'T')); return Number.isNaN(t) ? 0 : t; };

    // One record per row: { index, username, name, email, roles, rank, active, created }.
    function makeRecord(index, cells) {
        const roles = splitRoles(cells.roles);
        return { index, username: cells.username, name: cells.name, email: cells.email, roles, rank: bestRank(roles),
            active: norm(cells.status) === 'active', created: parseCreated(cells.created) };
    }
    function matches(record, filters) {
        const q = norm(filters.q);
        if (q && !norm(record.username + ' ' + record.name + ' ' + record.email).includes(q)) return false;
        if (filters.role) {
            const wanted = norm(filters.role);
            if (wanted === norm(NO_ROLE) ? record.roles.length > 0 : !record.roles.some(role => norm(role) === wanted)) return false;
        }
        if (filters.status === 'active' && !record.active) return false;
        if (filters.status === 'inactive' && record.active) return false;
        return true;
    }
    function compare(sort, dir) {
        const sign = dir === 'desc' ? -1 : 1;
        const byKey = {
            username: (a, b) => collator.compare(a.username, b.username),
            name: (a, b) => collator.compare(a.name, b.name),
            email: (a, b) => collator.compare(a.email, b.email),
            role: (a, b) => a.rank - b.rank,
            status: (a, b) => Number(b.active) - Number(a.active),   // ascending = Active first
            created: (a, b) => a.created - b.created
        }[sort];
        // Ties keep the server's order in both directions.
        return (a, b) => (byKey ? sign * byKey(a, b) : 0) || a.index - b.index;
    }
    // Roles for the filter list: the five known roles first, then any other role found, then "No RBAC role" if some account has none.
    function roleOptions(records, labels) {
        const found = new Map();
        records.forEach(r => r.roles.forEach(role => { if (!found.has(norm(role))) found.set(norm(role), role); }));
        const known = ROLE_ORDER.map(label => ({ value: found.get(norm(label)) || label, label: found.get(norm(label)) || label }));
        const extra = [...found.entries()].filter(([key]) => !ORDER_KEYS.includes(key)).map(([, label]) => ({ value: label, label })).sort((a, b) => collator.compare(a.label, b.label));
        const none = records.some(r => !r.roles.length) ? [{ value: NO_ROLE, label: NO_ROLE }] : [];
        return [...known, ...extra, ...none];
    }
    function countText(shown, total) {
        const noun = total === 1 ? 'account' : 'accounts';
        return shown === total ? total + ' ' + noun : shown + ' of ' + total + ' ' + noun;
    }
    function readQuery(search) {
        const p = new URLSearchParams(search || '');
        const sort = SORT_KEYS.includes(p.get('sort')) ? p.get('sort') : '';
        return { q: (p.get('q') || '').slice(0, 100), role: p.get('role') || '', status: ['active', 'inactive'].includes(p.get('status')) ? p.get('status') : '',
            sort, dir: sort && p.get('dir') === 'desc' ? 'desc' : 'asc' };
    }
    function writeQuery(baseSearch, state) {
        const p = new URLSearchParams(baseSearch || '');
        ['q', 'role', 'status', 'sort', 'dir'].forEach(key => p.delete(key));
        if (state.q.trim()) p.set('q', state.q.trim());
        if (state.role) p.set('role', state.role);
        if (state.status) p.set('status', state.status);
        if (state.sort) { p.set('sort', state.sort); p.set('dir', state.dir); }
        const text = p.toString();
        return text ? '?' + text : '';
    }
    const core = { ROLE_ORDER, norm, roleRank, bestRank, makeRecord, matches, compare, roleOptions, countText, readQuery, writeQuery, parseCreated };
    if (typeof window !== 'undefined') window.CsmsUsersListCore = core;

    const table = typeof document !== 'undefined' ? document.getElementById('usersTable') : null;
    if (!table) return;

    const tbody = table.tBodies[0];
    const empty = document.getElementById('usersNoMatch');
    const search = document.getElementById('usersSearch'), roleSelect = document.getElementById('usersRole'), statusSelect = document.getElementById('usersStatus');
    const clear = document.getElementById('usersClear'), count = document.getElementById('usersCount');
    const rows = [...tbody.rows].filter(row => row !== empty);
    const text = cell => (cell ? cell.textContent : '').replace(/\s+/g, ' ').trim();
    const records = rows.map((row, index) => Object.assign(makeRecord(index, {
        username: text(row.cells[0]), name: text(row.cells[1]), email: text(row.cells[2]), roles: text(row.cells[3]), status: text(row.cells[4]), created: text(row.cells[5])
    }), { row }));

    roleOptions(records).forEach(option => roleSelect.add(new Option(option.label, option.value)));

    let state = readQuery(window.location.search);
    // A role in the address that no account has still has to be selectable, or the list would look empty with no explanation.
    function syncControls() {
        if (state.role && ![...roleSelect.options].some(o => o.value === state.role)) roleSelect.add(new Option(state.role, state.role));
        search.value = state.q; roleSelect.value = state.role; statusSelect.value = state.status;
    }
    function render(writeUrl) {
        const sorted = records.slice().sort(compare(state.sort, state.dir));
        let shown = 0;
        sorted.forEach(record => {
            const visible = matches(record, state);
            record.row.hidden = !visible;
            if (visible) shown += 1;
            tbody.appendChild(record.row);
        });
        tbody.appendChild(empty);
        empty.hidden = shown !== 0;
        count.textContent = countText(shown, records.length);
        clear.hidden = !(state.q.trim() || state.role || state.status);
        table.querySelectorAll('th[data-sort]').forEach(th => {
            const active = th.dataset.sort === state.sort;
            th.setAttribute('aria-sort', active ? (state.dir === 'desc' ? 'descending' : 'ascending') : 'none');
            const icon = th.querySelector('.system-admin-sort__icon');
            icon.innerHTML = active ? '<i class="fa-solid ' + (state.dir === 'desc' ? 'fa-arrow-down' : 'fa-arrow-up') + '"></i>' : '';
        });
        if (writeUrl) window.history.replaceState(null, '', window.location.pathname + writeQuery(window.location.search, state) + window.location.hash);
    }
    function clearFilters() { state = Object.assign({}, state, { q: '', role: '', status: '' }); syncControls(); render(true); search.focus(); }

    let timer = 0;
    search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { state.q = search.value; render(true); }, 150); });
    search.addEventListener('keydown', event => { if (event.key === 'Escape' && search.value) { event.preventDefault(); search.value = ''; state.q = ''; render(true); } });
    roleSelect.addEventListener('change', () => { state.role = roleSelect.value; render(true); });
    statusSelect.addEventListener('change', () => { state.status = statusSelect.value; render(true); });
    clear.addEventListener('click', clearFilters);
    document.getElementById('usersNoMatchClear')?.addEventListener('click', clearFilters);
    table.querySelectorAll('th[data-sort] .system-admin-sort').forEach(button => button.addEventListener('click', () => {
        const key = button.closest('th').dataset.sort;
        state.dir = state.sort === key && state.dir === 'asc' ? 'desc' : 'asc';
        state.sort = key;
        render(true);
    }));
    window.addEventListener('popstate', () => { state = readQuery(window.location.search); syncControls(); render(false); });

    syncControls();
    render(false);
}());
