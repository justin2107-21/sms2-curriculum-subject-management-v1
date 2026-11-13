'use strict';
// Users page search, filters and sorting (js/users-list.js): pure rules, then the wiring against a small fake DOM, then the page markup.
const assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm');
const source = fs.readFileSync('js/users-list.js', 'utf8');
let checks = 0;
const ok = (value, label) => { assert.ok(value, label); checks += 1; };
const plain = v => JSON.parse(JSON.stringify(v === undefined ? null : v)); // values built inside the vm have another Array prototype
const eq = (a, b, label) => { assert.deepEqual(plain(a), plain(b), label); checks += 1; };

// ---- 1. Rules (no DOM) ---------------------------------------------------------------------------------------------------------------
const loaded = { window: {} };
vm.runInNewContext(source, { ...loaded, Intl, URLSearchParams, Date, Math, Number, String });
const C = loaded.window.CsmsUsersListCore;
ok(C, 'the rules are exposed without a page');
const row = (index, username, name, email, roles, status, created) => C.makeRecord(index, { username, name, email, roles, status, created });
const people = [
    row(0, 'zed', 'Zed Adams', 'zed@example.test', 'Staff', 'Active', '2026-03-01 08:00:00'),
    row(1, 'Amy', 'amy brown', 'Amy@example.test', 'Acad — Faculty', 'Inactive', '2026-01-05 09:30:00'),
    row(2, 'bob', 'Bob Cole', 'bob@example.test', 'Admin, Staff', 'Active', '2026-02-10 10:00:00'),
    row(3, 'cara', 'Cara Diaz', 'cara@example.test', 'Super Admin', 'Active', '2026-12-01 07:00:00'),
    row(4, 'dan', 'Dan Eng', 'dan@example.test', 'Acad — Office', 'Inactive', '2026-02-10 09:00:00'),
    row(5, 'eve', 'Eve Fox', 'eve@example.test', 'No RBAC role', 'Active', '2025-11-30 23:59:59'),
    row(6, 'fay', 'Fay Gil', 'fay@example.test', 'Student', 'Active', '2026-04-04 04:04:04')
];
const order = (sort, dir) => people.slice().sort(C.compare(sort, dir)).map(p => p.username);
eq(order('', 'asc'), people.map(p => p.username), 'no sort keeps the server order');
eq(order('username', 'asc'), ['Amy', 'bob', 'cara', 'dan', 'eve', 'fay', 'zed'], 'username sorts case-insensitively');
eq(order('username', 'desc'), ['zed', 'fay', 'eve', 'dan', 'cara', 'bob', 'Amy'], 'username descending');
eq(order('name', 'asc'), ['Amy', 'bob', 'cara', 'dan', 'eve', 'fay', 'zed'], 'name sorts case-insensitively (amy before Bob)');
eq(order('email', 'asc')[0], 'Amy', 'email sorts case-insensitively');
eq(order('role', 'asc'), ['cara', 'bob', 'zed', 'dan', 'Amy', 'fay', 'eve'], 'role order: Super Admin, Admin (best of Admin + Staff), Staff, Acad Office, Acad Faculty, other roles, no role');
eq(order('role', 'desc'), ['eve', 'fay', 'Amy', 'dan', 'zed', 'bob', 'cara'], 'role descending is the reverse of the ascending order');
eq(order('status', 'asc'), ['zed', 'bob', 'cara', 'eve', 'fay', 'Amy', 'dan'], 'status ascending: Active first, ties in server order');
eq(order('created', 'asc'), ['eve', 'Amy', 'dan', 'bob', 'zed', 'fay', 'cara'], 'created sorts by date, not text');
eq(order('created', 'desc'), ['cara', 'fay', 'zed', 'bob', 'dan', 'Amy', 'eve'], 'created descending');
ok(typeof people[0].created === 'number' && people[3].created > people[0].created, 'created is a number');
eq([C.roleRank('Super Admin'), C.roleRank('Admin'), C.roleRank('Staff'), C.roleRank('Acad - Office'), C.roleRank('Acad – Faculty'), C.roleRank('Student')], [0, 1, 2, 3, 4, 5], 'dash variants rank the same; other roles come after the five');
eq(C.bestRank(['Staff', 'Admin']), 1, 'several roles: the highest-ranked one counts');

const ids = filters => people.filter(p => C.matches(p, { q: '', role: '', status: '', ...filters })).map(p => p.username);
eq(ids({ q: 'AMY' }), ['Amy'], 'search matches username and email, any case');
eq(ids({ q: 'cole' }), ['bob'], 'search matches the name');
eq(ids({ q: '  ' }), people.map(p => p.username), 'a blank search hides nothing');
eq(ids({ role: 'Staff' }), ['zed', 'bob'], 'role filter matches any of an account\'s roles');
eq(ids({ role: 'Acad - Office' }), ['dan'], 'role filter ignores dash style');
eq(ids({ role: 'No RBAC role' }), ['eve'], 'accounts with no role can be filtered');
eq(ids({ status: 'inactive' }), ['Amy', 'dan'], 'status filter: Inactive');
eq(ids({ status: 'active', role: 'Admin' }), ['bob'], 'filters combine');
eq(ids({ q: 'nobody' }), [], 'no match gives an empty list');

const options = C.roleOptions(people).map(o => o.label);
eq(options.slice(0, 5), C.ROLE_ORDER, 'the five roles come first, in order');
eq(options.slice(5), ['Student', 'No RBAC role'], 'any other role found is added, so no account is hidden by the list');
eq(C.roleOptions(people.slice(0, 1)).map(o => o.label), C.ROLE_ORDER, 'nothing extra when every account has a known role');
eq([C.countText(9, 9), C.countText(3, 9), C.countText(0, 9), C.countText(1, 1)], ['9 accounts', '3 of 9 accounts', '0 of 9 accounts', '1 account'], 'result count text');
eq(C.readQuery('?q=ab&role=Staff&status=active&sort=created&dir=desc'), { q: 'ab', role: 'Staff', status: 'active', sort: 'created', dir: 'desc' }, 'state is read from the address');
eq(C.readQuery('?sort=password&dir=desc&status=x'), { q: '', role: '', status: '', sort: '', dir: 'asc' }, 'unknown sort and status values are ignored');
eq(C.writeQuery('?page=2&q=old', { q: ' ab ', role: '', status: 'inactive', sort: 'name', dir: 'asc' }), '?page=2&q=ab&status=inactive&sort=name&dir=asc', 'state is written to the address and other parameters stay');
eq(C.writeQuery('?q=x&sort=name&dir=desc', { q: '', role: '', status: '', sort: '', dir: 'asc' }), '', 'an empty state leaves a clean address');

// ---- 2. Wiring against a small fake DOM ----------------------------------------------------------------------------------------------
class Node {
    constructor(tag, attrs = {}) { this.tag = tag; this.hidden = false; this.dataset = {}; this.attrs = {}; this.children = []; this.handlers = {}; this.value = ''; this.textContent = ''; this.innerHTML = ''; this.options = []; Object.assign(this, attrs); }
    addEventListener(type, fn) { (this.handlers[type] ||= []).push(fn); }
    fire(type, event = {}) { (this.handlers[type] || []).forEach(fn => fn({ preventDefault() {}, ...event })); }
    setAttribute(k, v) { this.attrs[k] = v; } getAttribute(k) { return this.attrs[k]; }
    appendChild(child) { if (this.children.includes(child)) this.children.splice(this.children.indexOf(child), 1); this.children.push(child); return child; }
    add(option) { this.options.push(option); if (this.value === '' && this.options.length === 1) this.value = option.value; }
    focus() { this.focused = true; }
}
const th = key => { const t = new Node('th'); t.dataset.sort = key; t.attrs['aria-sort'] = 'none'; t.icon = new Node('span'); t.button = new Node('button'); t.button.closest = () => t; t.querySelector = () => t.icon; return t; };
const heads = ['username', 'name', 'email', 'role', 'status', 'created'].map(th);
const makeRow = p => { const r = new Node('tr'); r.cells = [p.username, p.name, p.email, p.roles.length ? p.roles.join(', ') : 'No RBAC role', p.active ? 'Active' : 'Inactive', p.createdText].map(textContent => ({ textContent })); r.username = p.username; return r; };
const data = [
    { username: 'zed', name: 'Zed Adams', email: 'zed@example.test', roles: ['Staff'], active: true, createdText: '2026-03-01 08:00:00' },
    { username: 'Amy', name: 'amy brown', email: 'amy@example.test', roles: ['Acad — Faculty'], active: false, createdText: '2026-01-05 09:30:00' },
    { username: 'bob', name: 'Bob Cole', email: 'bob@example.test', roles: ['Admin', 'Staff'], active: true, createdText: '2026-02-10 10:00:00' },
    { username: 'eve', name: 'Eve Fox', email: 'eve@example.test', roles: [], active: true, createdText: '2025-11-30 23:59:59' }
];
const dataRows = data.map(makeRow), noMatch = new Node('tr', { hidden: true });
const tbody = new Node('tbody'); tbody.children = [...dataRows, noMatch]; Object.defineProperty(tbody, 'rows', { get() { return this.children; } });
const tableNode = new Node('table'); tableNode.tBodies = [tbody]; tableNode.querySelectorAll = selector => selector === 'th[data-sort]' ? heads : selector === 'th[data-sort] .system-admin-sort' ? heads.map(h => h.button) : [];
const els = { usersTable: tableNode, usersNoMatch: noMatch, usersSearch: new Node('input'), usersRole: new Node('select'), usersStatus: new Node('select'), usersClear: new Node('button', { hidden: true }), usersCount: new Node('span'), usersNoMatchClear: new Node('button') };
const run = (search, handlers = {}) => {
    els.usersSearch.value = ''; els.usersRole.options = []; els.usersRole.value = ''; els.usersStatus.value = ''; els.usersSearch.handlers = {}; els.usersRole.handlers = {}; els.usersStatus.handlers = {}; els.usersClear.handlers = {}; els.usersNoMatchClear.handlers = {}; heads.forEach(h => { h.button.handlers = {}; h.attrs['aria-sort'] = 'none'; h.icon.innerHTML = ''; });
    const urls = [], win = { location: { pathname: '/admin/users.php', search, hash: '' }, history: { replaceState: (_s, _t, url) => { urls.push(url); win.location.search = url.includes('?') ? url.slice(url.indexOf('?')) : ''; } }, addEventListener() {} };
    vm.runInNewContext(source, { window: win, document: { getElementById: id => els[id] || null }, Option: class { constructor(label, value) { this.label = label; this.value = value; } }, Intl, URLSearchParams, Date, Math, Number, String, setTimeout: fn => { fn(); return 1; }, clearTimeout() {} });
    return { urls, win };
};
const visible = () => dataRows.filter(r => !r.hidden).map(r => r.username);
const inOrder = () => tbody.children.filter(r => r !== noMatch).map(r => r.username);

let page = run('');
eq(inOrder(), ['zed', 'Amy', 'bob', 'eve'], 'default order is the server order');
eq(visible(), ['zed', 'Amy', 'bob', 'eve'], 'nothing is hidden at first'); eq(els.usersCount.textContent, '4 accounts', 'count at first');
ok(els.usersClear.hidden && noMatch.hidden, 'Clear filters and the empty state are hidden at first');
eq(els.usersRole.options.map(o => o.label), ['Super Admin', 'Admin', 'Staff', 'Acad — Office', 'Acad — Faculty', 'No RBAC role'], 'the role list is built from the data');
ok(tbody.children[tbody.children.length - 1] === noMatch, 'the empty-state row stays last');
heads.forEach(h => eq(h.attrs['aria-sort'], 'none', 'aria-sort is none on every column at first'));

heads[0].button.fire('click');
eq(inOrder(), ['Amy', 'bob', 'eve', 'zed'], 'first click sorts ascending'); eq(heads[0].attrs['aria-sort'], 'ascending', 'aria-sort ascending');
ok(heads[0].icon.innerHTML.includes('fa-arrow-up') && heads.slice(1).every(h => h.icon.innerHTML === '' && h.attrs['aria-sort'] === 'none'), 'only the active column shows an arrow');
heads[0].button.fire('click');
eq(inOrder(), ['zed', 'eve', 'bob', 'Amy'], 'second click reverses'); eq(heads[0].attrs['aria-sort'], 'descending', 'aria-sort descending'); ok(heads[0].icon.innerHTML.includes('fa-arrow-down'), 'down arrow');
eq(page.urls.at(-1), '/admin/users.php?sort=username&dir=desc', 'sort is kept in the address');
heads[3].button.fire('click'); eq(inOrder(), ['bob', 'zed', 'Amy', 'eve'], 'role column sorts by rank'); eq(heads[0].attrs['aria-sort'], 'none', 'the previous column loses its arrow');
heads[5].button.fire('click'); eq(inOrder(), ['eve', 'Amy', 'bob', 'zed'], 'created column sorts by date');

els.usersSearch.value = 'BOB'; els.usersSearch.fire('input');
eq(visible(), ['bob'], 'search filters live'); eq(els.usersCount.textContent, '1 of 4 accounts', 'count follows the filter'); ok(!els.usersClear.hidden, 'Clear filters appears');
ok(page.urls.at(-1).includes('q=BOB') && page.urls.at(-1).includes('sort=created'), 'search and sort are both in the address');
els.usersSearch.value = 'nobody'; els.usersSearch.fire('input');
eq(visible(), [], 'nothing matches'); ok(!noMatch.hidden, 'the empty state row shows'); eq(els.usersCount.textContent, '0 of 4 accounts', 'count says 0 of 4');
els.usersNoMatchClear.fire('click');
eq(visible().length, 4, 'Clear filters in the empty state shows every account again'); eq(inOrder(), ['eve', 'Amy', 'bob', 'zed'], 'and the sort is kept'); ok(noMatch.hidden && els.usersClear.hidden, 'empty state and link hide again');
ok(page.urls.at(-1).includes('sort=created') && !page.urls.at(-1).includes('q='), 'clearing keeps the sort and drops the filters from the address');
els.usersRole.value = 'Staff'; els.usersRole.fire('change'); eq(visible().sort(), ['bob', 'zed'], 'role filter');
els.usersStatus.value = 'inactive'; els.usersStatus.fire('change'); eq(visible(), [], 'role and status combine');
els.usersRole.value = ''; els.usersRole.fire('change'); eq(visible(), ['Amy'], 'status filter alone');
els.usersClear.fire('click'); eq(visible().length, 4, 'Clear filters link resets every filter');

// A reload (after Create User / Edit User) restores the same view from the address.
page = run('?q=example&role=Staff&status=active&sort=name&dir=desc');
eq(inOrder(), ['zed', 'eve', 'bob', 'Amy'], 'the sort comes back from the address'); eq(visible().sort(), ['bob', 'zed'], 'the filters come back from the address');
eq([els.usersSearch.value, els.usersRole.value, els.usersStatus.value], ['example', 'Staff', 'active'], 'the controls show the restored values'); eq(heads[1].attrs['aria-sort'], 'descending', 'aria-sort restored');
page = run('?role=Registrar'); ok(els.usersRole.options.some(o => o.value === 'Registrar'), 'a role from the address that no account has stays selectable'); eq(visible(), [], 'and shows the empty state rather than a blank table');

// ---- 3. Page markup ------------------------------------------------------------------------------------------------------------------
const page_php = fs.readFileSync('admin/users.php', 'utf8');
for (const key of ['username', 'name', 'email', 'role', 'status', 'created']) ok(new RegExp('<th scope="col" data-sort="' + key + '" aria-sort="none"><button type="button" class="system-admin-sort">').test(page_php), 'sortable header is a real button with aria-sort: ' + key);
ok(/<th scope="col">Actions<\/th>/.test(page_php), 'Actions is not sortable');
ok((page_php.match(/data-sort="/g) || []).length === 6, 'exactly six sortable columns');
for (const id of ['usersSearch', 'usersRole', 'usersStatus', 'usersClear', 'usersCount', 'usersNoMatch', 'usersNoMatchClear']) ok(page_php.includes('id="' + id + '"'), 'toolbar piece ' + id);
ok(page_php.includes('<option value="">All roles</option>') && page_php.includes('<option value="">All statuses</option>') && page_php.includes('No accounts match these filters.'), 'texts');
ok(page_php.includes('class="csms-filter-row system-admin-toolbar"') && page_php.includes('role="status" aria-live="polite"'), 'shared toolbar pattern, live count');
ok(page_php.includes('js/users-list.js') && page_php.includes("require_permission('user.manage')"), 'script loaded; the page permission is unchanged');
ok(!/js\/users-list\.js[\s\S]*\bfetch\b/.test(source) && !/fetch|XMLHttpRequest/.test(source), 'the script adds no request: rows come from the server response');
console.log('PASS: ' + checks + ' Users list checks (rules, wiring, address state, markup)');
