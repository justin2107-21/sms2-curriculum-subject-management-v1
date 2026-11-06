'use strict';
// Logged-in UI check: drives headless Edge over the DevTools protocol (no extra packages),
// signs in through the real sign-in form, screenshots each URL at 1440 and 390 px and
// records console errors, exceptions and failed requests in report.json.
// Credentials come from the environment (UITEST_USER / UITEST_PASS), never from argv or output.
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const EDGE = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const arg = (name, fallback) => {
  const hit = process.argv.find(a => a.startsWith(`--${name}=`));
  return hit ? hit.slice(name.length + 3) : fallback;
};
const base = arg('base', 'http://127.0.0.1:8090/sms-csms');
const role = arg('role', 'admin');
const out = path.resolve(arg('out', '.'));
const urls = arg('urls', '/dashboard/dashboard.php').split(',').filter(Boolean);
const widths = arg('widths', '1440,390').split(',').map(Number);
const idle = arg('idle', '');
const port = Number(arg('cdp-port', '9340'));
const viewportHeight = Number(arg('height', '0')); // 0 = 900 (844 on phones)
const sleep = ms => new Promise(r => setTimeout(r, ms));

async function main() {
  const user = process.env.UITEST_USER;
  const pass = process.env.UITEST_PASS;
  if (!user || !pass) throw new Error('UITEST_USER and UITEST_PASS must be set.');
  fs.mkdirSync(out, { recursive: true });
  const profile = fs.mkdtempSync(path.join(require('node:os').tmpdir(), 'csms-uitest-edge-'));
  const edge = spawn(EDGE, ['--headless=new', '--disable-gpu', `--remote-debugging-port=${port}`,
    `--user-data-dir=${profile}`, '--no-first-run', '--window-size=1440,900', 'about:blank']);
  const report = { role, base, pages: [], login: null, idle: null, failures: [] };
  try {
    let target;
    for (let i = 0; i < 60 && !target; i++) {
      await sleep(250);
      try { target = (await (await fetch(`http://127.0.0.1:${port}/json`)).json()).find(t => t.type === 'page'); } catch {}
    }
    if (!target) throw new Error('Edge did not start.');
    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((r, j) => { ws.onopen = r; ws.onerror = j; });
    let seq = 0;
    const pending = new Map();
    let issues = [];
    let loadResolve = null;
    ws.onmessage = e => {
      const m = JSON.parse(e.data);
      if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result || { error: m.error }); pending.delete(m.id); return; }
      const p = m.params || {};
      if (m.method === 'Page.loadEventFired' && loadResolve) { loadResolve(); loadResolve = null; }
      if (m.method === 'Runtime.consoleAPICalled' && (p.type === 'error' || p.type === 'assert')) {
        issues.push({ kind: 'console', text: p.args.map(a => a.value ?? a.description ?? '').join(' ') });
      }
      if (m.method === 'Runtime.exceptionThrown') {
        issues.push({ kind: 'exception', text: p.exceptionDetails.exception?.description || p.exceptionDetails.text });
      }
      if (m.method === 'Log.entryAdded' && p.entry.level === 'error') {
        issues.push({ kind: 'log', text: `${p.entry.text} ${p.entry.url || ''}`.trim() });
      }
      if (m.method === 'Network.responseReceived' && p.response.status >= 400) {
        issues.push({ kind: 'http', text: `${p.response.status} ${p.response.url}` });
      }
      if (m.method === 'Network.loadingFailed' && !p.canceled) {
        issues.push({ kind: 'network', text: `${p.errorText} (${p.type})` });
      }
    };
    const send = (method, params = {}) => new Promise(r => { pending.set(++seq, r); ws.send(JSON.stringify({ id: seq, method, params })); });
    const evaluate = expr => send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true }).then(r => r.result?.value);
    const navigate = async url => {
      const loaded = new Promise(r => { loadResolve = r; });
      await send('Page.navigate', { url });
      await Promise.race([loaded, sleep(15000)]);
    };
    // Charts animate; screenshots must show final bars, not a frame of the animation.
    await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
    const setWidth = w => send('Emulation.setDeviceMetricsOverride', { width: w, height: viewportHeight || (w < 600 ? 844 : 900), deviceScaleFactor: 1, mobile: w < 600 });
    const shot = async file => {
      const metrics = await send('Page.getLayoutMetrics');
      const size = metrics.cssContentSize || metrics.contentSize;
      const r = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true,
        clip: { x: 0, y: 0, width: size.width, height: Math.min(size.height, 12000), scale: 1 } });
      fs.writeFileSync(file, Buffer.from(r.data, 'base64'));
    };
    // Viewport only: fixed dialogs are not reliable in a beyond-viewport capture.
    const shotView = async file => { const r = await send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(file, Buffer.from(r.data, 'base64')); };
    for (const domain of ['Page', 'Runtime', 'Log', 'Network']) await send(`${domain}.enable`);

    // Sidebar check, run on every visited page: a real mouse click on the burger must flip the
    // sidebar's collapsed state, and a second click must restore it. Pages without a burger
    // and sidebar are reported as "n/a"; a page with only one of them fails.
    const sidebarState = () => evaluate(`(() => { const s = document.getElementById('sidebar'); return s ? s.classList.contains('collapsed') : null; })()`);
    const clickBurger = async () => {
      const spot = await evaluate(`(() => {
        const b = document.getElementById('hamburgerBtn'); b.scrollIntoView({ block: 'center' });
        const r = b.getBoundingClientRect(); const x = r.left + r.width / 2, y = r.top + r.height / 2;
        const hit = document.elementFromPoint(x, y);
        return { x, y, hitIsBurger: !!hit && (hit === b || b.contains(hit)), hit: hit ? (hit.id || hit.className || hit.tagName) : null };
      })()`);
      for (const type of ['mouseMoved', 'mousePressed', 'mouseReleased']) {
        await send('Input.dispatchMouseEvent', { type, x: spot.x, y: spot.y, button: 'left', clickCount: 1 });
      }
      await sleep(400);
      return spot;
    };
    async function sidebarCheck() {
      const has = await evaluate(`({ burger: !!document.getElementById('hamburgerBtn'), sidebar: !!document.getElementById('sidebar') })`);
      if (!has.burger && !has.sidebar) return { status: 'n/a' };
      if (!has.burger || !has.sidebar) return { status: 'fail', reason: `missing ${has.burger ? 'sidebar' : 'burger'}` };
      const start = await sidebarState();
      const first = await clickBurger();
      const afterFirst = await sidebarState();
      // Step 2: click the burger again. On narrow screens the open sidebar covers the burger (on
      // every page, by design); closing then means tapping the dimmed area outside the sidebar.
      const burgerReachable = await evaluate(`(() => { const b = document.getElementById('hamburgerBtn'); const r = b.getBoundingClientRect(); const h = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return !!h && (h === b || b.contains(h)); })()`);
      let closedVia = 'burger', second = { hitIsBurger: true };
      if (burgerReachable) {
        second = await clickBurger();
      } else {
        closedVia = 'overlay';
        const spot = await evaluate(`(() => { const x = window.innerWidth - 8, y = Math.round(window.innerHeight / 2); const h = document.elementFromPoint(x, y); return { x, y, hit: h ? (h.id || h.className || h.tagName) : null }; })()`);
        second = { hitIsBurger: false, hit: spot.hit };
        if (spot.hit === 'sidebar-overlay' || spot.hit === 'sidebarOverlay') {
          for (const type of ['mouseMoved', 'mousePressed', 'mouseReleased']) {
            await send('Input.dispatchMouseEvent', { type, x: spot.x, y: spot.y, button: 'left', clickCount: 1 });
          }
          await sleep(400);
        }
      }
      const afterSecond = await sidebarState();
      const result = { status: 'pass', start, afterFirst, afterSecond, firstHitIsBurger: first.hitIsBurger, closedVia };
      if (!first.hitIsBurger) { result.status = 'fail'; result.reason = `first click landed on ${first.hit}, not the burger`; }
      else if (afterFirst === start) { result.status = 'fail'; result.reason = 'first click did not change the sidebar state'; }
      else if (afterSecond !== start) { result.status = 'fail'; result.reason = closedVia === 'burger' ? 'second click did not restore the sidebar state' : `burger covered and the overlay click (${second.hit}) did not restore the sidebar state`; }
      return result;
    }

    // Sign in through the real form.
    await setWidth(1440);
    await navigate(`${base}/auth/signin.php`);
    await evaluate(`new Promise(r => { const t = setInterval(() => { if (document.getElementById('username')) { clearInterval(t); r(true); } }, 100); })`);
    for (const [id, value] of [['username', user], ['password', pass]]) {
      await evaluate(`document.getElementById('${id}').focus(); true`);
      await send('Input.insertText', { text: value });
    }
    const loginNav = new Promise(r => { loadResolve = r; });
    await evaluate(`document.getElementById('btnSignin').click(); true`);
    await Promise.race([loginNav, sleep(15000)]);
    const landed = await evaluate('location.pathname');
    report.login = { ok: !/signin\.php$/.test(landed), landed, issues };
    issues = [];
    if (!report.login.ok) throw new Error(`Login failed for role ${role}; still on ${landed}.`);

    for (const url of urls) {
      for (const width of widths) {
        issues = [];
        await setWidth(width);
        await navigate(base + url);
        await sleep(1500); // charts and fonts settle
        const finalPath = await evaluate('location.pathname + location.search');
        const name = `${role}-${url.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '')}-${width}.png`;
        await shot(path.join(out, name));
        const overflow = await evaluate('document.documentElement.scrollWidth > window.innerWidth');
        const contentOverflow = await evaluate('(() => { const c = document.querySelector("main.content, .main .content"); return c ? c.scrollWidth - c.clientWidth : 0; })()');
        const sidebar = await sidebarCheck();
        if (sidebar.status === 'fail') report.failures.push(`${role} ${url} @${width}: sidebar burger — ${sidebar.reason}`);
        report.pages.push({ url, width, finalPath, screenshot: name, horizontalOverflow: overflow, contentOverflow, sidebar, issues });
      }
    }

    // ---- Context picker helpers (Program / Curriculum / Version) ----
    const pickerState = () => evaluate('(() => { const u = document.querySelector(".csms-ctx"); if (!u) return null; const f = [...u.querySelectorAll(".csms-ctx-field")]; const r = u.querySelector(".csms-ctx-reset"); const h = u.querySelector(".csms-ctx-hint"); const rect = u.getBoundingClientRect(); const root = u.closest(".csms-ctx-root"); const rr = root.getBoundingClientRect(); return { values: f.map(x => x.querySelector(".csms-ctx-value").textContent), titles: f.map(x => x.querySelector(".csms-ctx-trigger").title), disabled: f.map(x => x.querySelector(".csms-ctx-trigger").disabled), openPanel: f.findIndex(x => !x.querySelector(".csms-ctx-panel").hidden), resetVisible: !!r && !r.hidden, hintVisible: !!h && !h.hidden, hintText: h ? h.textContent : "", rowH: Math.round(rect.height), left: Math.round(rect.left), fieldW: f.map(x => Math.round(x.getBoundingClientRect().width)), fieldH: f.map(x => Math.round(x.querySelector(".csms-ctx-trigger").getBoundingClientRect().height)), search: location.search, path: location.pathname.split("/").pop() }; })()');
    const openField = async index => { await evaluate('document.querySelectorAll(".csms-ctx-trigger")[' + index + '].click(); true'); await sleep(700); };
    const clickOption = prefer => evaluate('((prefer) => { const p = [...document.querySelectorAll(".csms-ctx-panel")].find(x => !x.hidden); if (!p) return null; const opts = [...p.querySelectorAll("[role=option]")]; const o = opts.find(x => prefer && x.textContent.includes(prefer)) || opts.find(x => !x.classList.contains("is-recent")) || opts[0]; if (!o) return null; const t = o.textContent; o.click(); return t; })(' + JSON.stringify(prefer || '') + ')');
    const panelText = () => evaluate('(() => { const p = [...document.querySelectorAll(".csms-ctx-panel")].find(x => !x.hidden); return p ? { text: p.innerText.slice(0, 600), options: p.querySelectorAll("[role=option]").length, groups: [...p.querySelectorAll(".csms-ctx-group")].map(g => g.textContent) } : null; })()');
    // Choose a program by typing in the open Program panel, then follow the chain: levels with one option pick themselves,
    // otherwise the next panel is open and gets the preferred option (comma list: curriculum text, version text).
    async function choosePath(programText, prefer) {
      const prefers = (prefer || '').split(',');
      await openField(0);
      await evaluate('(() => { const s = document.querySelector(".csms-ctx-panel:not([hidden]) .csms-ctx-search"); s.focus(); return true; })()');
      await send('Input.insertText', { text: programText });
      await sleep(900);
      const picked = [await clickOption('')];
      for (let step = 0; step < 3; step++) {
        await sleep(1300);
        const s = await pickerState();
        if (!s.hintVisible) break;
        if (s.openPanel < 0) break;
        picked.push(await clickOption(prefers[step] || ''));
      }
      await sleep(1500);
      return picked;
    }
    async function runChosenFlow(url, programText, prefer, isNav) { return null; }

    if (arg('ctxnav', '') === '1') {
      // Shared working context across pages: choose on the FIRST url, then open each following url bare (like a sidebar
      // click) and record whether the context arrived; then reload (F5) on the last page.
      const program = arg('program', 'UITEST-PA');
      const prefer = arg('prefer', '');
      const stored = () => evaluate('(() => { const o = {}; for (let i = 0; i < sessionStorage.length; i++) { const k = sessionStorage.key(i); if (/csms/i.test(k)) o[k] = sessionStorage.getItem(k); } return o; })()');
      report.ctxnav = { steps: [] };
      await setWidth(widths[0]);
      await navigate(base + urls[0]);
      await sleep(1800);
      report.ctxnav.picked = await choosePath(program, prefer);
      report.ctxnav.chosenOn = { url: urls[0], state: await pickerState(), storage: await stored() };
      for (const url of urls.slice(1)) {
        issues = [];
        await navigate(base + url);
        await sleep(3500);
        report.ctxnav.steps.push({ url, state: await pickerState(), storage: await stored(), issues: issues.length });
      }
      await send('Page.reload');
      await sleep(3500);
      report.ctxnav.afterReload = { state: await pickerState(), storage: await stored() };
    }

    if (arg('ctxflow', '') === '1') {
      // The picker on each page in --urls: look, open a panel, choose a program through the real UI (auto-open and
      // auto-pick), reload from the address, then Reset. --program=UITEST-PA, --prefer="curriculumText,versionText".
      const program = arg('program', 'UITEST-PA');
      const prefer = arg('prefer', '');
      report.ctxflow = {};
      for (const url of urls) {
        issues = [];
        const o = {};
        await setWidth(widths[0]);
        await navigate(base + url);
        await sleep(2200);
        o.initial = await pickerState();
        if (!o.initial) { report.ctxflow[url] = { noPicker: true }; continue; }
        await shot(path.join(out, role + '-picker-' + url.split('/').pop().replace('.php', '') + '-initial-' + widths[0] + '.png'));
        await openField(0);
        o.programPanel = await panelText();
        await shot(path.join(out, role + '-picker-' + url.split('/').pop().replace('.php', '') + '-open-' + widths[0] + '.png'));
        await evaluate('document.querySelector(".csms-ctx-trigger").click(); true');
        o.picked = await choosePath(program, prefer);
        o.complete = await pickerState();
        await shot(path.join(out, role + '-picker-' + url.split('/').pop().replace('.php', '') + '-chosen-' + widths[0] + '.png'));
        if (/version_id=/.test(o.complete.search)) {
          await navigate(base + url.split('?')[0] + o.complete.search);
          await sleep(3000);
          o.reloadFromUrl = await pickerState();
        }
        await evaluate('(() => { const b = document.querySelector(".csms-ctx-reset"); if (b) b.click(); return !!b; })()');
        await sleep(900);
        o.afterReset = await pickerState();
        o.issues = issues;
        report.ctxflow[url] = o;
      }
    }

    // Scenario fixture ids (created by tools/uitest/seed-fixtures.php); not secrets. Never reads passwords.
    const fx = {};
    try {
      for (const line of fs.readFileSync(path.join(__dirname, '..', '..', '.env.uitest'), 'utf8').split(/\r?\n/)) {
        const m = /^UITEST_FX_([A-Z]+)=(\d+)$/.exec(line);
        if (m) fx[m[1]] = m[2];
      }
    } catch (_) { /* no env file */ }
    if (arg('ctxscenario', '') === '1') {
      if (!fx.PA) throw new Error('Scenario fixtures missing: run tools/uitest/setup-db.ps1.');
      // The shared working context end to end (UI_Decisions D2 / working-context rules). Starts with an empty session.
      const P = '/modules/curriculum/pages/';
      const pages = { mapping: P + 'subject_mapping.php', prereq: P + 'prerequisite_configuration.php', electives: P + 'electives_manager.php',
        export: P + 'curriculum_export.php', weighting: P + 'grade_weighting_setup.php' };
      const clickOptionExcluding = text => evaluate('((text) => { const p = [...document.querySelectorAll(".csms-ctx-panel")].find(x => !x.hidden); if (!p) return null; const o = [...p.querySelectorAll("[role=option]")].find(x => !x.classList.contains("is-recent") && !x.textContent.includes(text)); if (!o) return null; const t = o.textContent; o.click(); return t; })(' + JSON.stringify(text) + ')');
      const visit = async (key, query, shotName, settle) => {
        issues = [];
        await navigate(base + pages[key] + (query || ''));
        await sleep(settle || 3500);   // pages with their own link handling wait about 4 s before falling back
        if (shotName) await shot(path.join(out, role + '-ctx-' + shotName + '-' + widths[0] + '.png'));
        return { page: key, state: await pickerState(), issues: issues.length, forbidden: issues.some(i => i.kind === 'http' && String(i.text).startsWith('403') && String(i.text).includes(pages[key])) };
      };
      report.ctxscenario = [];
      // A step that throws is recorded (with its error) and fails the run; it can never silently disappear.
      const failStep = arg('fail-step', '');   // self-test: --fail-step=I makes that step throw
      const step = async (name, fn) => {
        try {
          if (failStep && name.startsWith(failStep + '.')) throw new Error('injected failure for the self-test');
          report.ctxscenario.push(Object.assign({ name }, await fn()));
        } catch (error) {
          report.ctxscenario.push({ name, error: String(error && error.message || error) });
          report.failures.push('scenario step "' + name + '" threw: ' + String(error && error.message || error));
        }
      };
      await setWidth(widths[0]);
      await navigate(base + '/dashboard/dashboard.php');
      await sleep(800);
      const api = base + '/modules/curriculum/http/working_context_actions.php';
      const post = (fields, withToken) => evaluate('((api, fields, withToken) => { const s = document.querySelector("script[src*=\\"curriculum-context.js\\"]"); const b = new URLSearchParams(fields); if (withToken && s) b.set("_csrf_token", s.dataset.csrfToken); return fetch(api, { method: "POST", body: b, credentials: "same-origin" }).then(async r => ({ status: r.status, body: await r.json().catch(() => null) })); })(' + JSON.stringify(api) + ',' + JSON.stringify(fields) + ',' + JSON.stringify(withToken) + ')');
      const get = query => evaluate('fetch(' + JSON.stringify(api + query) + ', { credentials: "same-origin" }).then(async r => ({ status: r.status, body: await r.json().catch(() => null) }))');
      const scenarioModule = 'subject_mapping';
      await navigate(base + pages.mapping);
      await sleep(1500);
      await post({ action: 'save', module: scenarioModule, clear: '1' }, true);
      await navigate(base + '/dashboard/dashboard.php');
      await sleep(500);
      await evaluate('sessionStorage.clear(); true');
      await step('A. Subject Mapping, nothing saved: server default (rule c or d)', async () => {
        const r = await visit('mapping', '', 'A-default');
        r.rule = (await get('?action=read&module=' + scenarioModule)).body.rule;
        return r;
      });
      await step('B. choose UITEST-PA / UITEST-CA / UITEST-A on Subject Mapping', async () => { const picked = await choosePath('UITEST-PA', 'UITEST-CA,UITEST-A'); await shot(path.join(out, role + '-ctx-B-chosen-' + widths[0] + '.png')); return { picked, state: await pickerState() }; });
      await step('C1. open Pre-requisite', () => visit('prereq', '', 'C1-prereq'));
      await step('C2. open Electives', () => visit('electives', '', 'C2-electives'));
      await step('D1. on Electives choose another version', async () => {
        await openField(2);
        const panel = await panelText();
        const picked = await clickOption('UITEST-B');
        await sleep(2200);
        await shot(path.join(out, role + '-ctx-D1-changed-' + widths[0] + '.png'));
        return { panel: panel && panel.text.replace(/\s+/g, ' ').slice(0, 160), picked, state: await pickerState() };
      });
      await step('D2. open Subject Mapping', () => visit('mapping', '', 'D2-mapping'));
      await step('D3. open Export', () => visit('export', '', 'D3-export'));
      await step('E1. link with other ids (UITEST-PB) on Export', () => visit('export', `?program_id=${fx.PB}&curriculum_id=${fx.CB}&version_id=${fx.VD}`, 'E1-link'));
      await step('E2. then Grade Weighting follows it', () => visit('weighting', '', 'E2-weighting'));
      await step('F1. invalid link on Export (missing version) falls back to saved', () => visit('export', `?program_id=${fx.PA}&curriculum_id=${fx.CA}&version_id=99999`, 'F1-invalid-export'));
      await step('F2. invalid link on Pre-requisite falls back to saved', () => visit('prereq', `?program_id=${fx.PA}&curriculum_id=${fx.CA}&version_id=99999`, 'F2-invalid-prereq', 8000));
      await step('F3. invalid link on Subject Mapping falls back to saved', () => visit('mapping', `?program_id=${fx.PA}&curriculum_id=${fx.CA}&version_id=99999`, 'F3-invalid-mapping', 8000));
      await step('G. reload (F5) on Subject Mapping', async () => { await send('Page.reload'); await sleep(8000); return { state: await pickerState() }; });
      await step('H. endpoint guards', async () => ({ guards: {
        saveWithoutCsrf: (await post({ action: 'save', module: scenarioModule, program_id: fx.PB, curriculum_id: fx.CB, version_id: fx.VD }, false)).status,
        readByPost: (await post({ action: 'read', module: scenarioModule }, true)).status,
        saveMismatchedChain: (await post({ action: 'save', module: scenarioModule, program_id: fx.PA, curriculum_id: fx.CB, version_id: fx.VD }, true)).status,
        unknownModule: (await get('?action=read&module=nope')).status,
        saveGet: (await get('?action=save&module=' + scenarioModule)).status,
      } }));
      await step('I. sign out, sign in on an empty session', async () => {
        const logout = await evaluate('((u) => { const t = document.querySelector("script[src*=\\"curriculum-context.js\\"]").dataset.csrfToken; return fetch(u, { method: "POST", body: new URLSearchParams({ action: "logout", _csrf_token: t }), credentials: "same-origin" }).then(r => ({ status: r.status })); })(' + JSON.stringify(base + '/shared/auth_actions.php') + ')');
        const anonymous = await get('?action=read&module=' + scenarioModule);
        await evaluate('sessionStorage.clear(); true');
        await navigate(base + '/auth/signin.php');
        await evaluate(`new Promise(r => { const t = setInterval(() => { if (document.getElementById('username')) { clearInterval(t); r(true); } }, 100); })`);
        for (const [id, value] of [['username', user], ['password', pass]]) {
          await evaluate(`document.getElementById('${id}').focus(); true`);
          await send('Input.insertText', { text: value });
        }
        const back = new Promise(r => { loadResolve = r; });
        await evaluate(`document.getElementById('btnSignin').click(); true`);
        await Promise.race([back, sleep(15000)]);
        const r = await visit('mapping', '', 'I-after-signin', 5000);
        r.logoutStatus = logout.status;
        r.anonymousRead = anonymous.status;
        await openField(0);
        const opened = await panelText();
        r.recentGroups = opened ? opened.groups : null;   // null means the panel did not open: the check below fails, the step still reports
        return r;
      });
    }

    // The old review-panel flow drove the retired Curricula page; the Builder probe (-Probe builder -BuilderFlow) covers review dialogs.
    if (arg('probe', '') === 'nav') {
      // Sidebar sections, links and the active link per page, plus the in-module view links.
      report.navProbe = {};
      for (const url of urls) {
        for (const width of widths) {
          await setWidth(width);
          await navigate(base + url);
          await sleep(2500);
          if (width < 700) { await evaluate(`(() => { const b = document.querySelector('.menu-toggle, #menuToggle, .burger, [aria-controls="sidebar"]'); if (b) b.click(); return true; })()`); await sleep(500); }
          report.navProbe[url.split('/').pop() + '@' + width] = await evaluate(`(() => ({
            path: location.pathname.split('/').pop(), title: (document.querySelector('h1') || {}).textContent.trim().slice(0, 50),
            sections: [...document.querySelectorAll('.sidebar-nav .brand-title')].map(e => e.textContent.trim()),
            links: [...document.querySelectorAll('.sidebar-nav a.sidebar-item')].map(a => a.textContent.trim().replace(/\s+/g, ' ')),
            active: [...document.querySelectorAll('.sidebar-nav a.sidebar-item.active')].map(a => a.textContent.trim().replace(/\s+/g, ' ')),
            dropdowns: document.querySelectorAll('.sidebar-nav [data-sidebar-menu-toggle], .sidebar-nav .sidebar-builder-submenu').length,
            views: [...document.querySelectorAll('.module-views a')].map(a => a.textContent.trim() + (a.classList.contains('is-active') ? '*' : '')),
            overflow: document.documentElement.scrollWidth > innerWidth
          }))()`);
          if (url.includes('subject_history_view') || url.includes('validate_curriculum') || url === urls[0]) await shotView(path.join(out, `${role}-nav-${url.split('/').pop().replace(/[^a-z0-9]+/gi, '_')}-${width}.png`));
        }
      }
    }

    if (arg('probe', '') === 'sidebar') {
      // Sidebar measurements (D7): headings and their contrast, every label (lines, mid-word breaks), indents, active style.
      const collect = () => {
        const parse = c => { const m = c.match(/[\d.]+/g).map(Number); return m.slice(0, 3); };
        const lum = ([r, g, b]) => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
        const ratio = (fg, bg) => { const a = lum(parse(fg)) + 0.05, b = lum(parse(bg)) + 0.05; return Math.round((Math.max(a, b) / Math.min(a, b)) * 100) / 100; };
        const side = document.getElementById('sidebar');
        const bgOf = el => { for (let e = el; e; e = e.parentElement) { const c = getComputedStyle(e).backgroundColor; if (c && !/rgba\(0, 0, 0, 0\)|transparent/.test(c)) return c; } return 'rgb(255, 255, 255)'; };
        const brokenWords = el => {
          const out = [];
          const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
          for (let n = walker.nextNode(); n; n = walker.nextNode()) {
            const text = n.textContent; const re = /\S+/g; let m;
            while ((m = re.exec(text))) { const r = document.createRange(); r.setStart(n, m.index); r.setEnd(n, m.index + m[0].length); const tops = new Set([...r.getClientRects()].map(x => Math.round(x.top))); if (tops.size > 1) out.push(m[0]); }
          }
          return out;
        };
        // Background actually seen behind an element: every translucent layer composited over the sidebar colour.
        const baseBg = parse(getComputedStyle(side).backgroundColor);
        const layerOf = e => { const m = getComputedStyle(e).backgroundColor.match(/[\d.]+/g); return m && (m.length < 4 || Number(m[3]) > 0) ? { rgb: m.slice(0, 3).map(Number), a: m.length < 4 ? 1 : Number(m[3]) } : null; };
        const effective = el => { const layers = []; for (let e = el; e && e !== side; e = e.parentElement) { const l = layerOf(e); if (l) layers.push(l); } let out = baseBg; for (const l of layers.reverse()) out = out.map((v, i) => Math.round(l.rgb[i] * l.a + v * (1 - l.a))); return 'rgb(' + out.join(', ') + ')'; };
        const radiusOf = el => { for (let e = el; e && e !== side; e = e.parentElement) { if (layerOf(e)) return getComputedStyle(e).borderRadius + ' on ' + (e === el ? 'item' : String(e.className).split(' ')[0]); } return '-'; };
        const headings = [...side.querySelectorAll('.brand-title')].map(el => { const cs = getComputedStyle(el); return { text: el.textContent.trim(), fs: cs.fontSize, fw: cs.fontWeight, color: cs.color, contrast: ratio(cs.color, bgOf(el)) }; });
        const items = [...side.querySelectorAll('.sidebar-item, .sidebar-submenu-item')].filter(el => el.getBoundingClientRect().width > 0 && el.getBoundingClientRect().height > 0).map(el => {
          const cs = getComputedStyle(el); const r = el.getBoundingClientRect(); const sub = el.classList.contains('sidebar-submenu-item');
          const label = el.querySelector('span:not(.sidebar-submenu-chevron)') || el; const tr = document.createRange(); tr.selectNodeContents(label); const rects = [...tr.getClientRects()].filter(x => x.width > 0); const lr = rects.length ? rects.reduce((a, b) => (b.left < a.left ? b : a)) : label.getBoundingClientRect(); const lcs = getComputedStyle(label); const lineTops = new Set(rects.map(x => Math.round(x.top)));
          const icon = el.querySelector('i:not(.sidebar-submenu-chevron)');
          const lines = lineTops.size;
          return { text: el.textContent.trim().replace(/\s+/g, ' ').slice(0, 40), sub, active: el.classList.contains('active'), fs: cs.fontSize, fw: cs.fontWeight, color: cs.color, bg: cs.backgroundColor, radius: cs.borderRadius, left: Math.round(r.left), iconLeft: icon ? Math.round(icon.getBoundingClientRect().left) : null, iconW: icon ? Math.round(icon.getBoundingClientRect().width) : null, iconFs: icon ? getComputedStyle(icon).fontSize : null, textLeft: Math.round(lr.left), w: Math.round(r.width), h: Math.round(r.height), lines, broken: brokenWords(label), effBg: effective(el), effRadius: radiusOf(el), contrast: ratio(cs.color, effective(el)) };
        });
        const burger = document.getElementById('hamburgerBtn');
        let burgerInfo = null;
        if (burger) { const r = burger.getBoundingClientRect(); const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); burgerInfo = { x: Math.round(r.left), y: Math.round(r.top), reachable: !!hit && (hit === burger || burger.contains(hit)), hit: hit ? (hit.id || String(hit.className).split(' ')[0] || hit.tagName) : null }; }
        const sr = side.getBoundingClientRect();
        return { headings, items, burger: burgerInfo, sidebar: { left: Math.round(sr.left), top: Math.round(sr.top), w: Math.round(sr.width), h: Math.round(sr.height), collapsed: side.classList.contains('collapsed') }, vw: innerWidth };
      };
      report.sidebarProbe = {};
      for (const url of urls) {
        await setWidth(widths[0]);
        await navigate(base + url);
        await sleep(1500);
        // open every submenu group so their items can be measured
        await evaluate('(() => { document.querySelectorAll("#sidebar .sidebar-item.dropdown-trigger, #sidebar [data-sidebar-menu-toggle]").forEach(b => { if (b.getAttribute("aria-expanded") !== "true") b.click(); }); return true; })()');
        await sleep(700);
        report.sidebarProbe[url] = await evaluate('(' + collect.toString() + ')()');
        if (widths[0] <= 900) {
          await evaluate('(() => { const s = document.getElementById("sidebar"); if (s.classList.contains("collapsed")) document.getElementById("hamburgerBtn").click(); return true; })()');
          await sleep(600);
          report.sidebarProbe[url].open = await evaluate('(' + collect.toString() + ')()');
          await shot(path.join(out, role + '-sidebar-open-' + url.split('/').pop().replace('.php', '') + '-' + widths[0] + '.png'));
        }
      }
    }

    if (arg('probe', '') === 'context') {
      // Which pages have a Program / Curriculum / Version selector, what kind, and how tall is it?
      const collector = '(() => {' +
        'const hit = [...document.querySelectorAll("[class*=context], [class*=selectors], [class*=selector]")].filter(e => { const f = e.querySelectorAll("input:not([type=hidden]), select"); const r = e.getBoundingClientRect(); return f.length >= 2 && f.length <= 6 && r.height > 30 && r.width > 400 && !e.closest("dialog, .modal-dialog, [hidden]"); });' +
        'const top = hit.filter(e => !hit.some(o => o !== e && o.contains(e)));' +
        'return top.slice(0, 3).map(e => { const r = e.getBoundingClientRect(); const f = [...e.querySelectorAll("input:not([type=hidden]), select")]; return { tag: e.tagName.toLowerCase(), cls: String(e.className).split(" ").slice(0, 3).join("."), id: e.id, h: Math.round(r.height), w: Math.round(r.width), fields: f.map(x => x.tagName.toLowerCase() + (x.id ? "#" + x.id : "")), clearButtons: e.querySelectorAll("[data-autocomplete-clear], .record-autocomplete-clear").length, kind: e.querySelector(".record-autocomplete") ? "autocomplete" : "native-select" }; }); })()';
      report.contextProbe = {};
      for (const url of urls) {
        await setWidth(widths[0]);
        await navigate(base + url);
        await sleep(2000);
        report.contextProbe[url] = await evaluate(collector);
      }
    }

    if (arg('probe', '') === 'headers') {
      // Page header probe: title element, its header container, subtitle and header actions, per page.
      const collector = '(() => {' +
        'const h = document.querySelector(".main .content h1, main h1, .main h1, h1"); if (!h) return { none: true };' +
        'const cs = e => getComputedStyle(e);' +
        'let box = h; while (box.parentElement && !/(title-bar|page-header|header|workspace-header|landing-header)/i.test(String(box.className)) && box.parentElement.tagName !== "MAIN" && !box.parentElement.classList.contains("content")) box = box.parentElement;' +
        'const br = box.getBoundingClientRect(), hr = h.getBoundingClientRect(), hc = cs(h), bc = cs(box);' +
        'const sub = [...box.querySelectorAll("p, .page-subtitle, .dashboard-subtitle")].find(p => p.getBoundingClientRect().width > 0 && !p.closest("button"));' +
        'const acts = [...box.querySelectorAll("button, a[class*=button], a[class*=btn]")].filter(b => b.getBoundingClientRect().width > 0).map(b => ({ txt: (b.textContent || "").trim().slice(0, 24), cls: String(b.className).split(" ")[0], right: Math.round(b.getBoundingClientRect().right), top: Math.round(b.getBoundingClientRect().top - br.top) }));' +
        'const mainL = document.querySelector(".main") ? document.querySelector(".main").getBoundingClientRect().left : 0; const host = box.parentElement || document.body; const kids = [...host.children].filter(k => k !== box && k.getBoundingClientRect().height > 8 && k.getBoundingClientRect().width > 100 && !/^(SCRIPT|STYLE|LINK)$/.test(k.tagName) && cs(k).position !== "fixed").slice(0, 8).map(k => ({ cls: String(k.className).split(" ")[0] || k.tagName.toLowerCase(), left: Math.round(k.getBoundingClientRect().left - mainL), w: Math.round(k.getBoundingClientRect().width) }));return { kids, boxLeft: Math.round(br.left - mainL), boxW: Math.round(br.width), h1cls: String(h.className), h1fs: hc.fontSize, h1fw: hc.fontWeight, h1ff: hc.fontFamily.split(",")[0], h1color: hc.color, boxcls: String(box.className).split(" ").slice(0, 3).join("."), boxtag: box.tagName.toLowerCase(), boxh: Math.round(br.height), boxpad: bc.padding, boxbg: bc.backgroundColor, boxborder: bc.borderBottomWidth + " " + bc.borderBottomColor, h1x: Math.round(hr.left), h1y: Math.round(hr.top), sub: sub ? { cls: String(sub.className).split(" ")[0], fs: cs(sub).fontSize, color: cs(sub).color, txt: sub.textContent.trim().slice(0, 40) } : null, acts, mainw: Math.round(document.querySelector(".main") ? document.querySelector(".main").getBoundingClientRect().width : 0) }; })()';
      report.headerProbe = {};
      for (const url of urls) {
        issues = [];
        await setWidth(widths[0]);
        await navigate(base + url);
        await sleep(2000);
        report.headerProbe[url] = await evaluate(collector);
      }
    }

    if (arg('probe', '') === 'styles') {
      // Design-consistency probe: computed styles of buttons, form controls and tables on each page (static
      // state only; modals and JS-opened panels are not opened). Used to measure before/after a styling batch.
      const collector = '(() => {' +
        'const rgb = v => v;' +
        'const fp = (el, extra) => { const c = getComputedStyle(el), r = el.getBoundingClientRect();' +
        ' return Object.assign({ cls: (el.className && el.className.baseVal === undefined ? String(el.className) : "").trim().split(/\\s+/).slice(0, 3).join("."), tag: el.tagName.toLowerCase(),' +
        ' h: Math.round(r.height), fs: c.fontSize, fw: c.fontWeight, pad: c.padding, rad: c.borderRadius, bg: c.backgroundColor, fg: c.color, bd: c.borderTopWidth + " " + c.borderTopColor, vis: r.width > 0 && r.height > 0, id: el.id || "", txt: (el.textContent || "").trim().replace(/ +/g, " ").slice(0, 22), par: (el.parentElement && typeof el.parentElement.className === "string" ? el.parentElement.className : "").trim().split(/ +/).slice(0, 2).join("."), dis: !!el.disabled }, extra || {}); };' +
        'const out = { buttons: [], fields: [], th: [], td: [], tables: [], empties: [] };' +
        'document.querySelectorAll("button, a[class*=button], a[class*=btn], input[type=submit], input[type=button]").forEach(el => { const f = fp(el); if (f.vis) out.buttons.push(f); });' +
        'document.querySelectorAll("input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=search]), select, textarea").forEach(el => { const f = fp(el, { type: el.type || "" }); if (f.vis) out.fields.push(f); });' +
        'document.querySelectorAll("table").forEach(t => { const f = fp(t); if (f.vis) out.tables.push(f); });' +
        'document.querySelectorAll("th").forEach(el => { const f = fp(el, { ta: getComputedStyle(el).textAlign }); if (f.vis) out.th.push(f); });' +
        'document.querySelectorAll("td").forEach(el => { const t = el.textContent.trim(); const f = fp(el, { ta: getComputedStyle(el).textAlign, numeric: /^[-+]?[0-9][0-9,.]*%?$/.test(t), tab: getComputedStyle(el).fontVariantNumeric }); if (f.vis) out.td.push(f); });' +
        'document.querySelectorAll("[class*=empty], [class*=state]").forEach(el => { const c = String(el.className); if (!/empty|no-results|state/.test(c) || el.children.length > 8) return; const f = fp(el); if (f.vis) out.empties.push(f); });' +
        'return out; })()';
      report.styleProbe = {};
      for (const url of urls) {
        issues = [];
        await setWidth(widths[0]);
        await navigate(base + url);
        await sleep(2200);
        report.styleProbe[url] = await evaluate(collector);
        // Keyboard focus: after one real Tab press (so :focus-visible applies), focus each control and check that
        // an outline or box-shadow shows. Sidebar and the page's own skip targets are included on purpose.
        for (const type of ['keyDown', 'keyUp']) await send('Input.dispatchKeyEvent', { type, key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
        report.styleProbe[url].focus = await evaluate('(() => { const els = [...document.querySelectorAll("a[href], button, input:not([type=hidden]), select, textarea, summary, [tabindex=\\"0\\"]")].filter(e => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0 && !e.disabled; }).slice(0, 80); const missing = []; for (const e of els) { e.focus({ focusVisible: true }); const c = getComputedStyle(e); const ring = (c.outlineStyle !== "none" && parseFloat(c.outlineWidth) > 0) || (c.boxShadow && c.boxShadow !== "none"); if (!ring) missing.push({ tag: e.tagName.toLowerCase(), cls: String(e.className).split(" ")[0], id: e.id || "" }); } return { checked: els.length, missing }; })()');
      }
    }

    if (arg('warnpreview', '') === '1') {
      // Quick look at the timeout warning: a second copy of session-timeout.js with short timers is added to the
      // real, logged-in page (16 s timeout, 12 s warning window => opens after 4 s idle). Visual and keyboard
      // check only; the real 240 s / 300 s timing is covered by -IdleSeconds.
      const results = {};
      for (const width of widths) {
        issues = [];
        await setWidth(width);
        await navigate(base + '/dashboard/dashboard.php');
        await sleep(800);
        await evaluate('(() => { window.__actions = []; const f = window.fetch; window.fetch = function (u, o) { try { if (o && o.body && o.body.get) window.__actions.push(o.body.get("action")); } catch (e) {} return f.apply(this, arguments); }; return true; })()');
        // The page's own (300 s) copy must not tell the short copy about activity through localStorage, or the short
        // copy would treat it as another tab and dismiss its warning. Drop activity writes for this harness only.
        await evaluate('(() => { const set = Storage.prototype.setItem; Storage.prototype.setItem = function (k) { if (String(k).indexOf("csms:last-activity:") === 0) return; return set.apply(this, arguments); }; return true; })()');
        await evaluate('(() => { const src = document.querySelector("script[src*=\\"session-timeout.js\\"]"); const s = document.createElement("script"); s.src = src.src; for (const [k, v] of Object.entries(src.dataset)) s.dataset[k] = v; s.dataset.timeoutSeconds = "16"; s.dataset.warningSeconds = "12"; document.body.appendChild(s); return true; })()');
        let opened = null;
        for (let i = 0; i < 24 && !opened; i++) {
          await sleep(500);
          opened = await evaluate('(() => { const d = document.getElementById("csmsSessionWarning"); if (!d || !d.open) return null; const r = d.getBoundingClientRect(); return { left: r.left, top: r.top, width: r.width, height: r.height, vw: innerWidth, vh: innerHeight, focus: document.activeElement.id, count: document.getElementById("csmsSessionWarningCount").textContent }; })()');
        }
        const raw = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(out, role + '-warning-preview-' + width + '.png'), Buffer.from(raw.data, 'base64'));
        const centered = !!opened && Math.abs(opened.left + opened.width / 2 - opened.vw / 2) < 20 /* page scrollbar */ && Math.abs(opened.top + opened.height / 2 - opened.vh / 2) < 2;
        // Tab must stay inside the dialog (focus trap); Escape must keep the session ("Stay signed in").
        const tabbed = [];
        for (let i = 0; i < 4; i++) {
          for (const type of ['keyDown', 'keyUp']) await send('Input.dispatchKeyEvent', { type, key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
          tabbed.push(await evaluate('(() => { const a = document.activeElement; const d = document.getElementById("csmsSessionWarning"); return (a ? (a.id || a.tagName) : null) + (a && d.contains(a) ? " (in dialog)" : " (OUTSIDE)"); })()'));
        }
        const actionsBefore = await evaluate('window.__actions.length');
        for (const type of ['keyDown', 'keyUp']) await send('Input.dispatchKeyEvent', { type, key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
        await sleep(1500);
        const afterEscape = await evaluate('({ open: document.getElementById("csmsSessionWarning").open, path: location.pathname, focus: document.activeElement ? (document.activeElement.id || document.activeElement.tagName) : null })');
        // Did Escape call the keep-alive, and did a fresh warning cycle start afterwards (timer reset)?
        const keepAliveCalls = (await evaluate('window.__actions.slice(' + actionsBefore + ')')).filter(a => a === 'activity').length;
        await sleep(5500);
        const reopened = await evaluate('(() => { const d = document.getElementById("csmsSessionWarning"); return { open: d.open, count: d.open ? document.getElementById("csmsSessionWarningCount").textContent : null, path: location.pathname }; })()');
        results[width] = { opened, centered, tabbed, afterEscape, keepAliveCallsAfterEscape: keepAliveCalls, reopenedAfterStay: reopened, issues };
      }
      report.warnPreview = results;
    }

    if (arg('probe', '') === 'programs') {
      // Academic Programs: row menu, record modal and its keyboard behaviour. Super Admin also clicks through
      // Edit and Archive on UITEST-PA (sms_db_uitest only, rebuild afterwards) with --programwrite=1.
      report.programsProbe = {};
      const rowOf = code => `[...document.querySelectorAll('#programRows tr[data-record-view]')].find(r => r.querySelector('.record-code').textContent.trim() === '${code}')`;
      const key = async (k, code) => { for (const type of ['keyDown', 'keyUp']) await send('Input.dispatchKeyEvent', { type, key: k, code: code || k }); };
      for (const width of widths) {
        issues = [];
        await setWidth(width);
        await navigate(`${base}/modules/curriculum/pages/academic_programs.php`);
        await sleep(1200);
        const result = {};
        await shotView(path.join(out, `${role}-programs-list-${width}.png`));
        result.counts = await evaluate(`[...document.querySelectorAll('[data-program-tab]')].map(b => b.innerText.replace(/[\\r\\n]+/g, ' ').trim())`);
        for (const code of ['ASD', 'BSIT']) {
          result['menu' + code] = await evaluate(`(() => { const row = ${rowOf(code)}; const t = row.querySelector('[data-row-menu]'); if (!t) return null; t.click(); const l = t.parentElement.querySelector('.row-menu__list'); const r = l.getBoundingClientRect(); return { status: row.querySelector('.status-badge')?.textContent, inView: r.left >= 0 && r.right <= innerWidth && r.bottom <= innerHeight, items: [...l.querySelectorAll('button')].map(b => b.textContent) }; })()`);
          if (result['menu' + code]) { await shotView(path.join(out, `${role}-programs-menu-${code}-${width}.png`)); await evaluate(`document.body.click(); true`); }
        }
        result.actionsHeader = await evaluate(`(() => { const th = document.querySelector('#programPanel th:last-child'); return { text: th.innerText, clipped: th.scrollWidth > th.clientWidth, right: Math.round(th.getBoundingClientRect().right), tableScroll: document.querySelector('#programPanel .table-scroll').scrollWidth - document.querySelector('#programPanel .table-scroll').clientWidth }; })()`);
        await evaluate(`document.querySelector('[data-program-tab="shs"]').click(); true`);
        await sleep(700);
        result.shsRows = await evaluate(`document.querySelectorAll('#programRows tr[data-record-view]').length`);
        await evaluate(`document.querySelector('[data-program-tab=""]').click(); true`);
        await sleep(700);
        await evaluate(`${rowOf('BSIT')}.querySelector('.record-name').click(); true`);
        await sleep(1400);
        const modalState = `(() => { const m = document.getElementById('programDetailsModal'); const d = m.querySelector('.modal-dialog').getBoundingClientRect(); const a = document.activeElement; return { open: !m.hidden, w: Math.round(d.width), h: Math.round(d.height), focusInside: m.contains(a), focusRow: a.dataset ? a.dataset.recordView || null : null, summary: [...m.querySelectorAll('.program-record__summary dd')].map(x => x.textContent), blocks: m.querySelectorAll('.program-record__curriculum').length, links: m.querySelectorAll('.program-record__curriculum a').length, footer: [...m.querySelectorAll('.modal-footer button')].filter(b => !b.hidden).map(b => b.textContent.trim()), bodyScrolls: getComputedStyle(m.querySelector('.modal-body')).overflowY }; })()`;
        result.modal = await evaluate(modalState);
        result.collapse = await evaluate(`[...document.querySelectorAll('#programDetailsModal details.program-record__curriculum')].map(d => d.open)`);
        await shotView(path.join(out, `${role}-programs-modal-${width}.png`));
        for (let i = 0; i < 30; i++) await key('Tab');
        result.afterTabs = (await evaluate(modalState)).focusInside;
        await key('Escape');
        await sleep(200);
        result.afterEscape = await evaluate(modalState);
        await evaluate(`${rowOf('BSIT')}.querySelector('.record-name').click(); true`);
        await sleep(600);
        await evaluate(`document.querySelector('#programDetailsModal .modal-backdrop').click(); true`);
        await sleep(200);
        result.afterBackdrop = await evaluate(modalState);
        if (arg('programwrite', '') === '1' && width === widths[0]) {
          await evaluate(`${rowOf('UITEST-PA')}.querySelector('.record-name').click(); true`);
          await sleep(1000);
          await evaluate(`document.getElementById('programDetailsEdit').click(); true`);
          await sleep(800);
          result.editDialog = await evaluate(`({ open: !document.getElementById('programModal').hidden, title: document.getElementById('programModalTitle').textContent, subtitle: document.getElementById('programModalContext').textContent, code: document.getElementById('programCode').value })`);
          await shotView(path.join(out, `${role}-programs-edit-${width}.png`));
          await evaluate(`document.getElementById('programName').value = 'UI test program edited'; document.getElementById('programSubmitButton').click(); true`);
          await sleep(1200);
          result.afterEdit = await evaluate(`(() => { const r = ${rowOf('UITEST-PA')}; return { name: r.querySelector('.record-name').textContent, formOpen: !document.getElementById('programModal').hidden }; })()`);
          await evaluate(`${rowOf('UITEST-PA')}.querySelector('[data-row-menu]').click(); ${rowOf('UITEST-PA')}.querySelector('[data-archive-program]').click(); true`);
          await sleep(800);
          result.archiveDialog = await evaluate(`({ open: !document.getElementById('programArchiveModal').hidden, message: document.getElementById('programArchiveMessage').textContent })`);
          await shotView(path.join(out, `${role}-programs-archive-${width}.png`));
          await evaluate(`document.getElementById('confirmProgramArchive').click(); true`);
          await sleep(1400);
          result.afterArchive = await evaluate(`({ rowGone: !${rowOf('UITEST-PA')}, dialogOpen: !document.getElementById('programArchiveModal').hidden, toast: document.querySelector('[class*=toast]')?.textContent || '' })`);
          // An inactive program (set up by the caller on UITEST-PB): archive it from the menu, restore it from Archives, expect it inactive again.
          result.inactiveBefore = await evaluate(`({ status: ${rowOf('UITEST-PB')}.querySelector('.status-badge').textContent, items: (${rowOf('UITEST-PB')}.querySelector('[data-row-menu]').click(), [...${rowOf('UITEST-PB')}.querySelectorAll('.row-menu__list button')].map(b => b.textContent)) })`);
          await shotView(path.join(out, `${role}-programs-menu-inactive-${width}.png`));
          await evaluate(`${rowOf('UITEST-PB')}.querySelector('[data-archive-program]').click(); true`);
          await sleep(700);
          await evaluate(`document.getElementById('confirmProgramArchive').click(); true`);
          await sleep(1400);
          result.inactiveArchived = await evaluate(`({ rowGone: !${rowOf('UITEST-PB')}, toast: document.querySelector('[class*=toast]')?.textContent || '' })`);
          await navigate(`${base}/dashboard/account.php?tab=archives`);
          await sleep(1800);
          result.inArchives = await evaluate(`(() => { const r = [...document.querySelectorAll('#archiveRows tr')].find(t => t.textContent.includes('UITEST-PB')); if (!r) return null; r.querySelector('.archive-actions button:nth-child(2)').click(); return true; })()`);
          await sleep(1200);
          result.restoreConfirm = await evaluate(`({ open: !document.getElementById('archiveConfirmModal').hidden, title: document.getElementById('archiveConfirmTitle').textContent })`);
          await shotView(path.join(out, `${role}-archives-restore-${width}.png`));
          await evaluate(`document.getElementById('archiveConfirmRestore').click(); true`);
          await sleep(1500);
          await navigate(`${base}/modules/curriculum/pages/academic_programs.php`);
          await sleep(1200);
          result.restoredInactive = await evaluate(`(() => { const r = ${rowOf('UITEST-PB')}; return r ? { status: r.querySelector('.status-badge').textContent, items: [...r.querySelectorAll('.row-menu__list button')].map(b => b.textContent) } : null; })()`);
        }
        result.issues = issues.slice();
        report.programsProbe[width] = result;
      }
    }

    if (arg('probe', '') === 'planedit') {
      // Plan controls for this role; with --builderflow=1 also add, move, a refused move and remove, in the browser.
      report.planEdit = {};
      for (const width of widths) {
        const result = {};
        await setWidth(width);
        await navigate(base + urls[0]);
        await sleep(3000);
        const counts = `({ add: !document.getElementById('builderAddOpen').hidden, termAdd: document.querySelectorAll('.builder-term__add').length, move: document.querySelectorAll('[data-move]').length, remove: document.querySelectorAll('[data-remove]').length, rows: document.querySelectorAll('.builder-subjects tbody tr').length, overflow: document.documentElement.scrollWidth > innerWidth })`;
        result.controls = await evaluate(counts);
        await evaluate(`document.querySelector('.builder-tabs').scrollIntoView(); window.scrollBy(0, -70); true`);
        await sleep(300);
        await shotView(path.join(out, `${role}-plan-controls-${width}.png`));
        if (arg('builderflow', '') === '1' && result.controls.move) {
          await evaluate(`document.querySelector('.builder-term__add').click(); true`);
          await sleep(400);
          const box = `document.getElementById('builderAddSubject')`;
          await evaluate(`(() => { const i = ${box}; i.focus(); i.value = 'a'; i.dispatchEvent(new Event('input', { bubbles: true })); return true; })()`);
          await sleep(1400);
          result.addTerm = await evaluate(`[document.getElementById('builderAddYear').value, document.getElementById('builderAddSemester').value]`);
          await shotView(path.join(out, `${role}-plan-add-${width}.png`));
          result.picked = await evaluate(`(() => { const o = document.querySelector('#builderAddOptions [role=option]'); if (!o) return null; const text = o.textContent; o.dispatchEvent(new MouseEvent('mousedown', { bubbles: true })); o.click(); return text; })()`);
          await sleep(300);
          await evaluate(`document.getElementById('builderAddForm').requestSubmit(); true`);
          await sleep(2500);
          result.afterAdd = await evaluate(counts);
          result.focusAfterAdd = await evaluate(`document.activeElement.getAttribute('aria-label')`);
          // Open the move menu on the new row; the focused move button belongs to it.
          await evaluate(`document.activeElement.click(); true`);
          await sleep(300);
          result.menu = await evaluate(`[...document.querySelectorAll('#builderMoveMenu [role=menuitem]')].length`);
          await shotView(path.join(out, `${role}-plan-menu-${width}.png`));
          await evaluate(`[...document.querySelectorAll('#builderMoveMenu [role=menuitem]')].find(b => b.dataset.year === '4th Year' && b.dataset.semester === '2nd Semester').click(); true`);
          await sleep(2500);
          result.afterMove = await evaluate(`[...document.querySelectorAll('.builder-term')].map(t => t.querySelector('header strong').textContent)`);
          await shotView(path.join(out, `${role}-plan-moved-${width}.png`));
          // Refused move: GE4 is a prerequisite of GE1 and cannot go later.
          await evaluate(`[...document.querySelectorAll('[data-move]')].find(b => b.getAttribute('aria-label').includes('GE4')).click(); true`);
          await sleep(300);
          await evaluate(`[...document.querySelectorAll('#builderMoveMenu [role=menuitem]')].find(b => b.dataset.year === '2nd Year').click(); true`);
          await sleep(1500);
          result.refused = await evaluate(`(() => { const m = document.getElementById('builderConflictModal'); return { open: !m.hidden, context: document.getElementById('builderConflictSubject').textContent, body: document.getElementById('builderConflictBody').textContent.slice(0, 160) }; })()`);
          await shotView(path.join(out, `${role}-plan-refused-${width}.png`));
          await evaluate(`document.querySelector('#builderConflictModal [data-modal-close]').click(); true`);
          await sleep(300);
          // Refused remove: GE4 has prerequisite links.
          await evaluate(`[...document.querySelectorAll('[data-remove]')].find(b => b.getAttribute('aria-label').includes('GE4')).click(); true`);
          await sleep(300);
          await evaluate(`document.getElementById('builderRemoveForm').requestSubmit(); true`);
          await sleep(1200);
          result.removeRefused = await evaluate(`document.querySelector('#builderRemoveForm .form-error').textContent`);
          await shotView(path.join(out, `${role}-plan-remove-refused-${width}.png`));
          await evaluate(`document.querySelector('#builderRemoveModal [data-modal-close]').click(); true`);
          await sleep(300);
          // Remove the row added above: the one in 4th Year.
          await evaluate(`document.querySelector('.builder-term:last-of-type, .builder-terms .builder-term'); [...document.querySelectorAll('.builder-term')].find(t => t.querySelector('header strong').textContent.includes('4th Year')).querySelector('[data-remove]').click(); true`);
          await sleep(300);
          await evaluate(`document.getElementById('builderRemoveForm').requestSubmit(); true`);
          await sleep(2500);
          result.afterRemove = await evaluate(counts);
          result.focusAfterRemove = await evaluate(`document.activeElement.id`);
        }
        report.planEdit[width] = result;
      }
    }

    if (arg('probe', '') === 'mappingtabs') {
      // Subject Mapping: Mapping Review with every check expanded, then a Subject Trace search; overflow and console are reported per width.
      report.mappingTabs = {};
      for (const width of widths) {
        const result = {};
        await setWidth(width);
        await navigate(base + urls[0]);
        await sleep(3500);
        await evaluate(`document.getElementById('mappingTabReview').click(); true`);
        await sleep(600);
        result.checks = await evaluate(`[...document.querySelectorAll('.mapping-check__head')].map(b => b.querySelector('.mapping-check__label').firstChild.textContent + ': ' + b.querySelector('.mapping-check__count').textContent)`);
        await shotView(path.join(out, `${role}-review-collapsed-${width}.png`));
        for (const key of ['outcomesNoSubject', 'subjectsNoOutcome']) await evaluate(`(() => { const b = document.querySelector('[data-review-issue="${key}"]:not([aria-disabled])'); if (b) b.click(); return true; })()`);
        await sleep(400);
        result.expanded = await evaluate(`document.querySelectorAll('.mapping-check.is-open').length`);
        await evaluate(`window.scrollTo(0, 0); true`);
        await shotView(path.join(out, `${role}-review-expanded-${width}.png`));
        await evaluate(`document.getElementById('mappingTabTrace').click(); true`);
        await sleep(400);
        await evaluate(`(() => { const i = document.getElementById('mappingTraceSearch'); i.focus(); i.value = 'a'; i.dispatchEvent(new Event('input', { bubbles: true })); return true; })()`);
        await sleep(1500);
        await evaluate(`(() => { const o = document.querySelector('#mappingTraceOptions [role=option]'); if (!o) return false; o.dispatchEvent(new MouseEvent('mousedown', { bubbles: true })); o.click(); return true; })()`);
        await sleep(2000);
        result.trace = await evaluate(`({ rows: document.querySelectorAll('#mappingTraceResults tbody tr').length, link: (document.querySelector('.mapping-open-link') || {}).textContent, legacy: /Open Placement/.test(document.getElementById('mappingTraceResults').textContent) })`);
        await shotView(path.join(out, `${role}-trace-${width}.png`));
        result.overflow = await evaluate(`document.documentElement.scrollWidth > innerWidth`);
        report.mappingTabs[width] = result;
      }
    }

    if (arg('probe', '') === 'versions') {
      // Selector footers (+ New curriculum / + New version) and the More menu in the version header, for this role.
      // With --builderflow=1 also the create and rename flows in the browser.
      report.versionsProbe = {};
      const key = async (k, code) => { for (const type of ['keyDown', 'keyUp']) await send('Input.dispatchKeyEvent', { type, key: k, code: code || k, windowsVirtualKeyCode: { Escape: 27 }[k] || 0 }); };
      const openLevel = async index => {
        await evaluate(`(() => { const c = document.querySelector('.csms-ctx-change'); if (c) c.click(); return true; })()`);
        await sleep(300);
        await evaluate(`document.querySelectorAll('.csms-ctx-trigger')[${index}].click(); true`);
        await sleep(1500);
      };
      const levelState = index => evaluate(`(() => { const f = document.querySelectorAll('.csms-ctx-field')[${index}]; const b = f.querySelector('.csms-ctx-footer-btn'); const foot = f.querySelector('.csms-ctx-footer'); return { footer: foot.hidden ? '' : b.textContent, options: [...f.querySelectorAll('[role=option]')].map(o => o.textContent.trim().replace(/\\s+/g, ' ')).slice(0, 8) }; })()`);
      const openMore = async () => {
        await evaluate(`(() => { const b = document.getElementById('builderMoreBtn'); b.focus(); b.click(); return true; })()`);
        await sleep(300);
      };
      const moreItems = () => evaluate(`[...document.querySelectorAll('#builderMoreMenu [role=menuitem]')].map(x => x.textContent.trim())`);
      for (const width of widths) {
        const result = {};
        await setWidth(width);
        await navigate(base + urls[0]);
        await sleep(3000);
        result.page = await evaluate(`({ oldButtons: !!document.getElementById('builderVersionsOpen') || !!document.getElementById('builderVersionsModal') || !!document.getElementById('builderNewCurriculum'), more: !document.getElementById('builderMore').hidden, overflow: document.documentElement.scrollWidth > innerWidth, title: document.getElementById('builderVersionTitle').textContent })`);
        await shotView(path.join(out, `${role}-header-more-${width}.png`));
        if (result.page.more) {
          await openMore();
          result.more = await moreItems();
          await shotView(path.join(out, `${role}-more-menu-${width}.png`));
          await key('Escape');
          await sleep(300);
          result.moreClosed = await evaluate(`({ hidden: document.getElementById('builderMoreMenu').hidden, focus: document.activeElement.id })`);
        }
        await openLevel(2);
        result.versionLevel = await levelState(2);
        await shotView(path.join(out, `${role}-version-dropdown-${width}.png`));
        await key('Escape');
        await sleep(300);
        await openLevel(1);
        result.curriculumLevel = await levelState(1);
        await shotView(path.join(out, `${role}-curriculum-dropdown-${width}.png`));
        await key('Escape');
        await sleep(300);
        if (arg('builderflow', '') === '1' && width === widths[0]) {
          const stamp = String(Date.now()).slice(-5);
          const clickFooter = async index => { await openLevel(index); await evaluate(`document.querySelectorAll('.csms-ctx-footer-btn')[${index}].click(); true`); await sleep(1200); };
          const submit = async (form, fields) => { await evaluate(`(() => { const f = document.getElementById('${form}'); ${Object.entries(fields).map(([n, v]) => `f.elements.${n}.value = '${v}';`).join(' ')} f.requestSubmit(); return true; })()`); await sleep(3500); };
          const where = () => evaluate(`({ url: location.search, title: document.getElementById('builderVersionTitle').textContent, selector: [...document.querySelectorAll('.csms-ctx-sum-value')].map(v => v.textContent).join(' / ') })`);
          if (result.curriculumLevel.footer) {
            await clickFooter(1);
            result.newCurriculumDialog = await evaluate(`({ title: document.getElementById('builderCurriculumTitle').textContent, program: document.querySelector('#builderCurriculumForm [name=academic_program_id]').selectedOptions[0]?.textContent })`);
            await shotView(path.join(out, `${role}-new-curriculum-${width}.png`));
            await submit('builderCurriculumForm', { curriculum_code: 'P5-' + stamp, curriculum_name: 'Probe curriculum ' + stamp, first_version_label: 'P5V1-' + stamp });
            result.afterCurriculum = await where();
          }
          // New version from the Version dropdown.
          await clickFooter(2);
          await shotView(path.join(out, `${role}-new-version-${width}.png`));
          await submit('builderVersionForm', { version_label: 'P5V-' + stamp });
          result.afterVersion = await where();
          // Rename version from More.
          await openMore();
          result.moreOnNew = await moreItems();
          await evaluate(`[...document.querySelectorAll('#builderMoreMenu [role=menuitem]')].find(x => x.textContent === 'Rename version').click(); true`);
          await sleep(600);
          result.renameDialog = await evaluate(`({ title: document.getElementById('builderVersionFormTitle').textContent, label: document.querySelector('#builderVersionForm [name=version_label]').value, yearHidden: document.getElementById('builderVersionYearField').hidden })`);
          await shotView(path.join(out, `${role}-rename-version-${width}.png`));
          await submit('builderVersionForm', { version_label: 'P5R-' + stamp });
          result.afterRename = await where();
          // Rename curriculum from More (the server may refuse it, e.g. when a protected version exists).
          await openMore();
          await evaluate(`[...document.querySelectorAll('#builderMoreMenu [role=menuitem]')].find(x => x.textContent === 'Rename curriculum').click(); true`);
          await sleep(1500);
          await shotView(path.join(out, `${role}-rename-curriculum-${width}.png`));
          await submit('builderCurriculumForm', { curriculum_name: 'Probe renamed ' + stamp });
          result.afterCurriculumRename = await evaluate(`({ url: location.search, error: (document.querySelector('#builderCurriculumForm .form-error') || {}).textContent || '', dialogOpen: !document.getElementById('builderCurriculumModal').hidden, selector: document.getElementById('curriculumSearch').value })`);
          await shotView(path.join(out, `${role}-rename-curriculum-result-${width}.png`));
        }
        report.versionsProbe[width] = result;
      }
    }

    if (arg('probe', '') === 'ctxlayout') {
      // Phase 1 geometry of the context picker on each page: field boxes, label and separator styles, gaps.
      report.ctxLayout = {};
      for (const url of urls) {
        for (const width of widths) {
          await setWidth(width);
          await navigate(base + url);
          await sleep(2500);
          await evaluate(`(() => { const c = document.querySelector('.csms-ctx-change'); if (c) c.click(); return true; })()`);
          await sleep(300);
          report.ctxLayout[url + '@' + width] = await evaluate(`(() => { const r = e => { const b = e.getBoundingClientRect(); return [Math.round(b.left), Math.round(b.top), Math.round(b.width), Math.round(b.height)]; }; const root = document.querySelector('.csms-ctx-root'); if (!root) return null; const fields = [...root.querySelectorAll('.csms-ctx-field')]; const l = root.querySelector('.csms-ctx-label'); const s = root.querySelector('.csms-ctx-sep'); const t = root.querySelector('.csms-ctx-trigger'); const cs = e => getComputedStyle(e); return { root: r(root), bg: cs(root).backgroundColor, pad: cs(root).padding, fields: fields.map(r), label: [cs(l).fontSize, cs(l).fontWeight, cs(l).color, l.textContent], sep: s ? [cs(s).fontSize, s.textContent] : null, trigger: [cs(t).height, cs(t).fontSize, cs(t).borderRadius] }; })()`);
          await shotView(path.join(out, `${role}-ctx-` + url.replace(/[^a-z0-9]+/gi, '_').slice(-40) + `-${width}.png`));
        }
      }
    }

    if (arg('probe', '') === 'fonts') {
      // Every visible text node: first font family and size. Lists anything not DM Sans / Newsreader, or below 12 px.
      report.fontProbe = {};
      for (const url of urls) {
        await setWidth(widths[0]);
        await navigate(base + url);
        await sleep(1800);
        report.fontProbe[url] = await evaluate(`(() => { const out = { families: {}, small: {}, ready: document.fonts.check('14px "DM Sans"') && document.fonts.check('14px "Newsreader"') }; const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT); let n; while ((n = w.nextNode())) { if (!n.textContent.trim()) continue; const e = n.parentElement; if (!e || ['SCRIPT','STYLE','NOSCRIPT','OPTION'].includes(e.tagName) || !e.getClientRects().length) continue; const c = getComputedStyle(e); if (c.visibility === 'hidden' || e.closest('.sr-only, .visually-hidden')) continue; const f = c.fontFamily.split(',')[0].replace(/"/g, '').trim(); out.families[f] = (out.families[f] || 0) + 1; const s = parseFloat(c.fontSize); if (s < 12) { const k = (e.className && String(e.className).split(' ')[0] || e.tagName) + ' ' + c.fontSize; out.small[k] = (out.small[k] || 0) + 1; } } return out; })()`);
      }
    }

    if (arg('probe', '') === 'builder') {
      // Curriculum Builder: header, tabs and the first action's dialog, for each URL (one version each) and width.
      report.builderProbe = {};
      const key = async (k, code) => { for (const type of ['keyDown', 'keyUp']) await send('Input.dispatchKeyEvent', { type, key: k, code: code || k }); };
      for (const url of urls) {
        for (const width of widths) {
          issues = [];
          await setWidth(width);
          await navigate(base + url);
          await sleep(3200);
          const collect = `(() => { const t = id => (document.getElementById(id) || {}).textContent || ''; const v = document.getElementById('builderVersion');
            return { shown: v && !v.hidden, state: t('builderState').slice(0, 120), title: t('builderVersionTitle'), sub: t('builderVersionSub'), lastEdit: t('builderLastEdit'), summary: (() => { const s = document.querySelector('.csms-ctx-summary'); return s && !s.hidden ? s.innerText.replace(/[\\r\\n]+/g, ' | ') : ''; })(), chips: [...document.querySelectorAll('.csms-ctx-summary .csms-chip')].map(x => x.className.split('--')[1] + ':' + x.textContent), badges: [...document.querySelectorAll('#builderVersionBadges .status-badge')].map(b => b.textContent.trim()).join(' | '),
              steps: [...document.querySelectorAll('.builder-step')].map(s => (s.classList.contains('is-done') ? 'done:' : s.classList.contains('is-current') ? 'now:' : 'todo:') + s.querySelector('.builder-step__name').firstChild.textContent.trim()),
              units: t('builderUnits'), target: t('builderTarget'), conflicts: t('builderConflicts').trim(),
              actions: [...document.querySelectorAll('#builderActions button')].map(b => b.textContent.trim()), next: document.getElementById('builderNext').hidden ? '' : t('builderNext'),
              links: [...document.querySelectorAll('#builderLinks a')].map(a => a.textContent.trim()), note: t('builderPlanNote').trim(),
              terms: document.querySelectorAll('.builder-term').length, subjects: document.querySelectorAll('.builder-subjects tbody tr').length, flags: document.querySelectorAll('.builder-subjects tbody tr.has-conflict').length,
              overflow: document.documentElement.scrollWidth > innerWidth, tabs: [...document.querySelectorAll('.builder-tabs [role=tab]')].map(b => b.textContent) }; })()`;
          const result = { page: await evaluate(collect) };
          await shotView(path.join(out, `${role}-builder-${url.match(/version_id=(\d+)/)[1]}-${width}.png`));
          await evaluate(`window.scrollTo(0, 0); true`);
          await shotView(path.join(out, `${role}-builder-selector-summary-${width}.png`));
          await evaluate(`document.querySelector('.csms-ctx-change').click(); true`);
          await sleep(300);
          result.phase1 = await evaluate(`({ fields: document.querySelectorAll('.csms-ctx-field').length && getComputedStyle(document.querySelector('.csms-ctx-field')).display !== 'none', summaryHidden: document.querySelector('.csms-ctx-summary').hidden, done: !document.querySelector('.csms-ctx-done').hidden, reset: !document.querySelector('.csms-ctx-reset').hidden })`);
          await shotView(path.join(out, `${role}-builder-selector-edit-${width}.png`));
          await evaluate(`document.querySelector('.csms-ctx-done').click(); true`);
          await sleep(300);
          result.phase2again = await evaluate(`!document.querySelector('.csms-ctx-summary').hidden`);
          if (arg('builderflow', '') === '1' && result.page.actions.length) {
            await evaluate(`document.querySelector('#builderActions button').click(); true`);
            await sleep(1500);
            result.dialog = await evaluate(`(() => { const m = document.querySelector('.curriculum-modal:not([hidden])'); return m ? { id: m.id, title: m.querySelector('h2').textContent, text: m.querySelector('form') ? m.querySelector('form').innerText.slice(0, 260) : '' } : null; })()`);
            await shotView(path.join(out, `${role}-builder-dialog-${url.match(/version_id=(\d+)/)[1]}-${width}.png`));
            result.dialogAttach = await evaluate(`(() => { const b = document.getElementById('builderClassifyAttachOpen'); if (!b || b.offsetParent === null) return null; b.click(); return true; })()`);
            if (result.dialogAttach) { await sleep(900); result.dialogAttach = await evaluate(`document.querySelector('.curriculum-modal:not([hidden])').id`); }
            await evaluate(`document.querySelector('.curriculum-modal:not([hidden]) [data-modal-close]').click(); true`);
            await sleep(300);
          }
          const tag = url.match(/version_id=(\d+)/)[1];
          await evaluate(`document.querySelector('.builder-tabs').scrollIntoView(); window.scrollBy(0, -70); true`);
          await sleep(200);
          await shotView(path.join(out, `${role}-builder-plan-${tag}-${width}.png`));
          result.search = await evaluate(`(() => { const box = document.getElementById('builderPlanSearch'); const st = () => document.getElementById('builderPlanSearchStatus').textContent; const marked = () => document.querySelectorAll('tr.is-match, tr.is-dimmed').length; const key = (k, s) => box.dispatchEvent(new KeyboardEvent('keydown', { key: k, shiftKey: !!s, bubbles: true })); box.value = 'GE1'; box.dispatchEvent(new Event('input')); const typing = marked(); key('Enter'); const one = st() + '/' + document.querySelectorAll('tr.is-dimmed').length; key('Escape'); const esc = marked() + ':' + box.value; box.value = 'GE'; box.dispatchEvent(new Event('input')); key('Enter'); const a = st(); key('Enter'); const b = st(); key('ArrowDown'); const c = st(); key('ArrowUp'); const d = st(); key('Enter', true); const e = st(); return { typing, one, esc, steps: [a, b, c, d, e], access: document.getElementById('builderAccessBadge').textContent.trim() }; })()`);
          await sleep(700);
          result.searchCentred = await evaluate(`(() => { const r = document.querySelector('tr.is-current-match'); if (!r) return null; const b = r.getBoundingClientRect(); return Math.round(Math.abs((b.top + b.bottom) / 2 - innerHeight / 2)); })()`);
          await shotView(path.join(out, `${role}-builder-search-${tag}-${width}.png`));
          await evaluate(`document.getElementById('tabDetails').click(); document.querySelector('.builder-tabs').scrollIntoView(); window.scrollBy(0, -70); true`);
          await sleep(300);
          await shotView(path.join(out, `${role}-builder-details-${tag}-${width}.png`));
          result.details = await evaluate(`({ facts: document.querySelectorAll('#builderDetails > div').length, refs: document.querySelectorAll('#builderReferences tr').length, attach: !!document.getElementById('builderAttachOpen') && !document.getElementById('builderAttachOpen').hidden })`);
          await evaluate(`document.getElementById('tabReview').click(); true`);
          await sleep(300);
          await shotView(path.join(out, `${role}-builder-review-${tag}-${width}.png`));
          result.conflictLabel = await evaluate(`(() => { document.getElementById('tabPlan').click(); const b = document.querySelector('.builder-conflict-btn'); if (!b) return null; const l = b.getAttribute('aria-label'); b.focus(); b.click(); return l; })()`);
          await sleep(500);
          result.conflictModal = result.conflictLabel ? await evaluate(`(() => { const m = document.getElementById('builderConflictModal'); try { return { open: !m.hidden, title: m.querySelector('h2').textContent, subject: document.getElementById('builderConflictSubject').textContent, cards: m.querySelectorAll('.builder-conflict-card').length, text: m.querySelector('.builder-conflict-body').innerText.replace(/\\s+/g, ' | ').slice(0, 420), titleFont: getComputedStyle(m.querySelector('h2')).fontFamily.split(',')[0] }; } catch (e) { return { error: String(e) }; } })()`) : null;
          if (result.conflictModal) {
            await shotView(path.join(out, `${role}-builder-conflict-modal-${tag}-${width}.png`));
            await key('Escape');
            await sleep(300);
            result.conflictClosed = await evaluate(`({ closed: document.getElementById('builderConflictModal').hidden, focusBack: document.activeElement && document.activeElement.className })`);
          }
          result.tabKeys = await evaluate(`(() => { const t = document.getElementById('tabReview'); t.focus(); t.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true })); const a = document.activeElement.id; document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true })); return [a, document.activeElement.id]; })()`);
          result.exportHref = await evaluate(`[...document.querySelectorAll('#builderLinks a')].filter(a => a.href.includes('curriculum_export')).map(a => a.getAttribute('href'))[0] || ''`);
          result.classifyAttach = await evaluate(`(() => { const b = document.getElementById('builderClassifyAttachOpen'); return b ? true : false; })()`);
          result.history = await evaluate(`document.querySelectorAll('#builderHistory tr').length + ':' + document.querySelector('#builderHistory').textContent.slice(0, 80)`);
          if (result.exportHref) {
            await navigate(base + '/modules/curriculum/pages/' + result.exportHref.replace(/&amp;/g, '&'));
            await sleep(3000);
            result.exportContext = await evaluate(`[...document.querySelectorAll('.csms-ctx-trigger')].map(t => t.textContent.trim())`);
            await shotView(path.join(out, `${role}-export-${tag}-${width}.png`));
          }
          result.issues = issues.slice();
          (report.builderProbe[url] = report.builderProbe[url] || {})[width] = result;
        }
      }
    }

    if (arg('picker', '') === '1') {
      // Dashboard Program / Strand picker: open, search, keyboard-select, then back/forward.
      const results = {};
      for (const width of widths) {
        issues = [];
        await setWidth(width);
        await navigate(`${base}/dashboard/dashboard.php`);
        await sleep(800);
        const key = async (k, code) => { for (const type of ['keyDown', 'keyUp']) await send('Input.dispatchKeyEvent', { type, key: k, code: code || k, windowsVirtualKeyCode: { ArrowDown: 40, Enter: 13, Escape: 27 }[k] || 0 }); };
        await evaluate(`document.querySelector('.csms-picker__trigger').click(); true`);
        await sleep(200);
        const opened = await evaluate(`({ expanded: document.querySelector('.csms-picker__trigger').getAttribute('aria-expanded'), focus: document.activeElement.className, count: document.querySelector('.csms-picker__count').textContent })`);
        await send('Input.insertText', { text: 'zzzz' });
        await sleep(300);
        const emptyText = await evaluate(`document.querySelector('.csms-picker .record-autocomplete-state').textContent`);
        await shot(path.join(out, `${role}-picker-empty-${width}.png`));
        await evaluate(`const s = document.querySelector('.csms-picker__search'); s.value = ''; s.dispatchEvent(new Event('input')); true`);
        await send('Input.insertText', { text: 'crim' });
        await sleep(300);
        const filtered = await evaluate(`document.querySelector('.csms-picker__count').textContent`);
        await shot(path.join(out, `${role}-picker-open-${width}.png`));
        await key('ArrowDown');
        await key('Enter');
        await sleep(1500);
        const chosen = await evaluate(`({ url: location.search, trigger: document.querySelector('.csms-picker__value').textContent, panelHidden: document.querySelector('.csms-picker__panel').hidden, focus: document.activeElement.className })`);
        await shot(path.join(out, `${role}-picker-chosen-${width}.png`));
        await evaluate('history.back(); true');
        await sleep(1500);
        const back = await evaluate(`({ url: location.search, trigger: document.querySelector('.csms-picker__value').textContent })`);
        results[width] = { opened, emptyText, filtered, chosen, back, issues };
      }
      report.picker = results;
    }

    if (idle && arg('nojs', '') === '1') {
      // Server-only check: JavaScript off, wait past the timeout, then request a page.
      const seconds = Number(idle);
      await send('Emulation.setScriptExecutionDisabled', { value: true });
      await navigate(`${base}/dashboard/dashboard.php`);
      const before = await evaluate('location.pathname');
      await sleep((seconds + 5) * 1000);
      await navigate(`${base}/dashboard/dashboard.php`);
      const after = await evaluate('location.pathname + location.search');
      report.idle = { javascript: false, seconds, before, afterIdlePlus5s: after };
    } else if (idle) {
      // Idle check with the warning. No input at all unless --stay=1, which clicks "Stay signed in" with a real
      // mouse click --stay-delay seconds after the warning opens. Records when the warning opens and what it
      // says, focus, whether the session is still alive past the original timeout, and when sign-out happens.
      const seconds = Number(idle);
      const stay = arg('stay', '') === '1';
      const stayDelay = Number(arg('stay-delay', '10'));
      issues = [];
      await setWidth(widths[0]);
      await navigate(base + '/dashboard/dashboard.php');
      const probe = () => evaluate('(() => { const d = document.getElementById("csmsSessionWarning"); const open = !!(d && d.open); const q = id => open ? d.querySelector("#" + id).textContent : null;' +
        ' return { path: location.pathname, open, count: q("csmsSessionWarningCount"), unit: q("csmsSessionWarningUnit"), title: q("csmsSessionWarningTitle"), text: q("csmsSessionWarningText"),' +
        ' buttons: open ? [...d.querySelectorAll("button")].map(b => b.textContent) : null, focus: open ? (document.activeElement && document.activeElement.id) : null,' +
        ' role: open ? d.getAttribute("role") : null, modal: open ? d.matches(":modal") : null }; })()').catch(() => null);
      const start = Date.now();
      const warnings = [];
      let loggedOutAt = null, stayAt = null, afterStay = null, alive = null, wasOpen = false, stayClicked = false;
      const deadline = seconds + (stay ? seconds : 0) + 90;
      while ((Date.now() - start) / 1000 < deadline) {
        await sleep(1000);
        const t = Math.round((Date.now() - start) / 1000);
        const state = await probe();
        if (!state) continue; // navigation in progress
        if (/signin\.php$/.test(state.path)) { loggedOutAt = t; break; }
        if (state.open && !wasOpen) {
          warnings.push({ at: t, count: state.count, unit: state.unit, title: state.title, text: state.text, buttons: state.buttons, focus: state.focus, role: state.role, modal: state.modal });
          await shot(path.join(out, role + '-timeout-warning-' + widths[0] + '-' + warnings.length + '.png'));
        }
        wasOpen = state.open;
        if (stay && !stayClicked && state.open && t >= warnings[0].at + stayDelay) {
          const spot = await evaluate('(() => { const b = document.getElementById("csmsSessionWarningStay"); const r = b.getBoundingClientRect(); const x = r.left + r.width / 2, y = r.top + r.height / 2; const h = document.elementFromPoint(x, y); return { x, y, hitIsButton: h === b }; })()');
          for (const type of ['mouseMoved', 'mousePressed', 'mouseReleased']) await send('Input.dispatchMouseEvent', { type, x: spot.x, y: spot.y, button: 'left', clickCount: 1 });
          stayClicked = true; stayAt = t;
          await sleep(1500);
          afterStay = await probe();
          afterStay.activeElement = await evaluate('document.activeElement ? (document.activeElement.id || document.activeElement.tagName) : null');
          afterStay.hitIsButton = spot.hitIsButton;
        }
        if (stay && stayClicked && alive === null && t >= seconds + 15) {
          // Past the original timeout: the session must still be valid (an authenticated request is not redirected).
          alive = { at: t, path: state.path, dialogOpen: state.open, server: await evaluate("fetch('" + base + "/dashboard/dashboard.php', { redirect: 'manual', credentials: 'include' }).then(r => r.type + ' ' + r.status)") };
        }
      }
      // Server-side check: once signed out, the old cookie must be rejected without JavaScript.
      const server = await evaluate("fetch('" + base + "/dashboard/dashboard.php', { redirect: 'manual', credentials: 'include' }).then(r => r.type + ' ' + r.status)");
      report.idle = { expectedSeconds: seconds, stay, warnings, stayAt, afterStay, aliveAfterOriginalTimeout: alive, loggedOutAt, serverAfterIdle: server, issues };
    }
    ws.close();
  } finally {
    edge.kill();
    fs.writeFileSync(path.join(out, `report-${role}.json`), JSON.stringify(report, null, 2));
    setTimeout(() => { try { fs.rmSync(profile, { recursive: true, force: true }); } catch {} }, 1500);
  }
  console.log(JSON.stringify({ role, login: report.login.ok,
    pages: report.pages.map(p => ({ url: p.url, width: p.width, issues: p.issues.length, overflow: p.horizontalOverflow, sidebar: p.sidebar && p.sidebar.status })),
    failures: report.failures,
    idle: report.idle }, null, 1));
  if (report.failures.length) { console.error('FAILED: ' + report.failures.join(' | ')); process.exitCode = 1; }
}

main().catch(error => { console.error(error.message); process.exitCode = 1; });
