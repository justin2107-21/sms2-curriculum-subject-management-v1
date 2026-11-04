'use strict';
// DB-free tests for js/curriculum-context.js: the shared working context (Program / Curriculum / Version).
// Mock page: a cascade with three levels whose searches are backed by small in-memory lists.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('js/curriculum-context.js', 'utf8');
assert.ok(!/removeItem/.test(source), 'the saved context is never deleted from storage (the old code removed it on reload)');
assert.ok(!/getEntriesByType|performance\./.test(source), 'no navigation-type rule any more');

const PROGRAMS = [{ id: 1, program_code: 'BSIT', program_name: 'Information Technology' }, { id: 2, program_code: 'BSCRIM', program_name: 'Criminology' }];
const CURRICULA = [{ id: 10, academic_program_id: 1, curriculum_code: 'IT-2026', curriculum_name: 'IT 2026' }, { id: 20, academic_program_id: 2, curriculum_code: 'CRIM', curriculum_name: 'Criminalism' }];
const VERSIONS = [
    { id: 100, curriculum_id: 10, version_label: 'PREORAL', status: 'draft' }, { id: 101, curriculum_id: 10, version_label: 'FINAL', status: 'active' },
    { id: 200, curriculum_id: 20, version_label: 'ROTC', status: 'active' }, { id: 102, curriculum_id: 10, version_label: 'V3', status: 'draft' },
    { id: 103, curriculum_id: 10, version_label: 'V4', status: 'draft' }
];

function createPage({ userId = '7', search = '', stored = null, removed = [] } = {}) {
    const handlers = {};
    const sessionData = {};
    if (stored) sessionData['csms.working-context.' + userId] = JSON.stringify(stored);
    const calls = { replaceState: [], setSelected: [], clear: 0 };
    const location = { search, pathname: '/sms-csms/modules/curriculum/pages/subject_mapping.php', hash: '' };
    const document = {
        currentScript: { dataset: { userId } },
        addEventListener: (name, fn) => { (handlers[name] ||= []).push(fn); },
        dispatchEvent: event => { (handlers[event.type] || []).forEach(fn => fn(event)); return true; }
    };
    class CustomEvent { constructor(type, init) { this.type = type; this.detail = init && init.detail; } }
    const history = { state: null, replaceState: (state, title, url) => { calls.replaceState.push(url); const q = url.indexOf('?'); location.search = q >= 0 ? url.slice(q) : ''; } };
    const sessionStorage = { getItem: key => (key in sessionData ? sessionData[key] : null), setItem: (key, value) => { sessionData[key] = String(value); } };
    const window = { history };
    const sandbox = { document, window, location, history, sessionStorage, URLSearchParams, CustomEvent, JSON, Date, Promise, Array, Object, String, Number, setTimeout: fn => fn() };
    vm.runInNewContext(source, sandbox);

    // Mock cascade: three selectors that behave like the engine (selecting clears the levels below, events fire).
    const chosen = [null, null, null];
    const emit = (index, item, action) => document.dispatchEvent(new CustomEvent('csms:curriculum-context-selection', { detail: { index, item, action } }));
    const alive = list => list.filter(entry => !removed.includes(entry.id));
    const levels = [
        { input: { closest: () => ({}) }, getId: r => r.id, search: async () => alive(PROGRAMS) },
        { input: {}, getId: r => r.id, search: async () => { if (!chosen[0]) return []; return alive(CURRICULA).filter(c => c.academic_program_id === chosen[0].id); } },
        { input: {}, getId: r => r.id, search: async () => { if (!chosen[1]) return []; return alive(VERSIONS).filter(v => v.curriculum_id === chosen[1].id); } }
    ];
    const selectors = levels.map((level, index) => ({
        setSelected: item => { calls.setSelected.push([index, item.id]); chosen[index] = item; for (let i = index + 1; i < 3; i += 1) chosen[i] = null; emit(index, item, 'select'); },
        clear: () => { calls.clear += 1; chosen.fill(null); emit(0, null, 'clear'); },
        hasSelection: () => Boolean(chosen[index])
    }));
    const ready = extra => document.dispatchEvent(new CustomEvent('csms:curriculum-context-cascade-ready', { detail: Object.assign({ levels, selectors }, extra) }));
    const settle = () => new Promise(resolve => setImmediate(resolve));
    return { document, calls, chosen, emit, ready, settle, location, sessionData, api: () => window.CSMSWorkingContext, levels, selectors };
}
const ids = page => page.chosen.map(item => item && item.id);
const choose = (page, p, c, v) => { page.emit(0, PROGRAMS.find(x => x.id === p), 'select'); page.emit(1, CURRICULA.find(x => x.id === c), 'select'); page.emit(2, VERSIONS.find(x => x.id === v), 'select'); };
const savedContext = (p, c, v) => {
    const prog = PROGRAMS.find(x => x.id === p), cur = CURRICULA.find(x => x.id === c), ver = VERSIONS.find(x => x.id === v);
    return { current: { program: { id: String(p), code: prog.program_code, name: prog.program_name }, curriculum: { id: String(c), code: cur.curriculum_code, name: cur.curriculum_name }, version: { id: String(v), code: ver.version_label, name: '', status: ver.status, year: '' } }, recent: [] };
};

(async () => {
    // 1. Selections are saved and written to the address, with other address parameters kept.
    {
        const page = createPage({ search: '?tab=map' });
        page.ready({});
        await page.settle();
        choose(page, 1, 10, 100);
        const url = page.calls.replaceState.at(-1);
        assert.match(url, /^\/sms-csms\/modules\/curriculum\/pages\/subject_mapping\.php\?/);
        const params = new URLSearchParams(url.split('?')[1]);
        assert.equal(params.get('tab'), 'map');
        assert.deepEqual([params.get('program_id'), params.get('curriculum_id'), params.get('version_id')], ['1', '10', '100']);
        const stored = JSON.parse(page.sessionData['csms.working-context.7']);
        assert.equal(stored.current.version.id, '100');
        assert.equal(stored.current.version.status, 'draft', 'the version status is kept for display');
        // clearing the program removes all three from the address and the saved context
        page.emit(0, null, 'clear');
        assert.ok(!/program_id|curriculum_id|version_id/.test(page.calls.replaceState.at(-1)), 'Reset removes the ids from the address');
        assert.equal(JSON.parse(page.sessionData['csms.working-context.7']).current.program, null);
        console.log('PASS: selections are saved and written to the address; Reset clears both');
    }

    // 2. Recent: newest first, one entry per version, at most three, and kept after Reset.
    {
        const page = createPage();
        page.ready({});
        await page.settle();
        choose(page, 1, 10, 100); choose(page, 2, 20, 200); choose(page, 1, 10, 100); choose(page, 1, 10, 101); choose(page, 1, 10, 102);
        const recent = page.api().recent();
        assert.deepEqual(recent.map(entry => entry.version.id), ['102', '101', '100'], 'three newest, the repeated version appears once');
        page.emit(0, null, 'clear');
        assert.equal(page.api().recent().length, 3, 'Recent survives Reset');
        page.api().forget('101');
        assert.deepEqual(page.api().recent().map(entry => entry.version.id), ['102', '100']);
        console.log('PASS: recent contexts are deduplicated, capped at three, and survive Reset');
    }

    // 3. (b) The saved working context is applied on a page that has nothing in the address.
    {
        const page = createPage({ stored: savedContext(2, 20, 200) });
        page.ready({});
        await page.settle(); await page.settle(); await page.settle(); await page.settle();
        assert.deepEqual(ids(page), [2, 20, 200], 'saved context applied in order');
        console.log('PASS: the saved working context opens on a page with no link');
    }

    // 4. (a) A valid link beats the saved context, and becomes the saved context.
    {
        const page = createPage({ search: '?program_id=1&curriculum_id=10&version_id=101', stored: savedContext(2, 20, 200) });
        page.ready({});
        for (let i = 0; i < 6; i += 1) await page.settle();
        assert.deepEqual(ids(page), [1, 10, 101]);
        assert.equal(JSON.parse(page.sessionData['csms.working-context.7']).current.version.id, '101');
        console.log('PASS: a valid link wins over the saved context and replaces it');
    }

    // 5. An invalid or deleted link falls back to the saved context (half-applied attempt is cleared first).
    {
        const page = createPage({ search: '?program_id=1&curriculum_id=10&version_id=999', stored: savedContext(2, 20, 200) });
        page.ready({});
        for (let i = 0; i < 10; i += 1) await page.settle();
        assert.deepEqual(ids(page), [2, 20, 200], 'fell back to the saved context');
        assert.ok(page.calls.clear >= 1, 'the partly applied link was cleared');
        assert.match(page.location.search, /program_id=2&curriculum_id=20&version_id=200/, 'the address now shows the context in use');
        console.log('PASS: an invalid link falls back to the saved context');
    }

    // 6. A deleted / not permitted item in the saved context: nothing applied, picker left empty, address clean.
    {
        const page = createPage({ stored: savedContext(1, 10, 100), removed: [100] });
        page.ready({});
        for (let i = 0; i < 10; i += 1) await page.settle();
        assert.deepEqual(ids(page), [null, null, null]);
        assert.ok(!/version_id/.test(page.location.search));
        console.log('PASS: a deleted version leaves the picker empty (no stale context)');
    }

    // 7. Pages that handle their own link: if that produces nothing, the saved context applies after a wait.
    {
        const page = createPage({ search: '?program_id=1&curriculum_id=10&version_id=999', stored: savedContext(2, 20, 200) });
        page.ready({ restoreSavedContext: false });
        for (let i = 0; i < 40; i += 1) await page.settle();
        assert.deepEqual(ids(page), [2, 20, 200], 'own link handling failed, saved context applied');
        const page2 = createPage({ search: '?program_id=1&curriculum_id=10&version_id=100', stored: savedContext(2, 20, 200) });
        page2.ready({ restoreSavedContext: false });
        // the page's own link handling selects its context through the cascade, as a real page does
        page2.selectors[0].setSelected(PROGRAMS[0]); page2.selectors[1].setSelected(CURRICULA[0]); page2.selectors[2].setSelected(VERSIONS[0]);
        for (let i = 0; i < 40; i += 1) await page2.settle();
        assert.deepEqual(ids(page2), [1, 10, 100], 'a context the page selected itself is left alone');
        console.log('PASS: page-handled links fall back only when they produce nothing');
    }

    // 8. A context a module does not allow (its lookup throws) is treated as missing.
    {
        const page = createPage({ stored: savedContext(1, 10, 100) });
        page.levels[2].search = async () => { throw new Error('403'); };
        page.ready({});
        for (let i = 0; i < 10; i += 1) await page.settle();
        assert.deepEqual(ids(page), [null, null, null]);
        console.log('PASS: an unpermitted lookup falls through to an empty picker');
    }

    // 9. Applying a Recent entry: all or nothing.
    {
        const page = createPage();
        page.ready({});
        await page.settle();
        choose(page, 1, 10, 100); choose(page, 2, 20, 200);
        const entry = page.api().recent().find(item => item.version.id === '100');
        assert.equal(await page.api().apply(entry), true);
        assert.deepEqual(ids(page), [1, 10, 100]);
        const gone = createPage({ removed: [100] });
        gone.ready({});
        await gone.settle();
        assert.equal(await gone.api().apply(entry), false, 'a recent entry whose version is gone is refused');
        assert.deepEqual(ids(gone), [null, null, null]);
        console.log('PASS: applying a recent context is all or nothing');
    }

    // 10. A choice made by the user while a restore is running stops the restore.
    {
        const page = createPage({ stored: savedContext(2, 20, 200) });
        const release = [];
        const original = page.levels[0].search;
        page.levels[0].search = () => new Promise(resolve => release.push(() => resolve(original())));
        page.ready({});
        await page.settle();
        page.emit(0, PROGRAMS.find(item => item.id === 1), 'select');   // the user picks BSIT meanwhile
        release.forEach(fn => fn());
        for (let i = 0; i < 6; i += 1) await page.settle();
        assert.equal(page.calls.setSelected.length, 0, 'the restore did not override the user\'s choice');
        console.log('PASS: a user choice during restore cancels the restore');
    }

    // 11. Cascades that are not the context picker (for example inside a dialog) are ignored.
    {
        const page = createPage({ stored: savedContext(2, 20, 200) });
        page.levels[0].input = { closest: () => null };
        page.ready({});
        for (let i = 0; i < 6; i += 1) await page.settle();
        assert.equal(page.calls.setSelected.length, 0);
        console.log('PASS: cascades outside the context bar are left alone');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
