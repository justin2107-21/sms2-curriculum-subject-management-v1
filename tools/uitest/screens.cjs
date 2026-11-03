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
    const setWidth = w => send('Emulation.setDeviceMetricsOverride', { width: w, height: viewportHeight || (w < 600 ? 844 : 900), deviceScaleFactor: 1, mobile: w < 600 });
    const shot = async file => {
      const metrics = await send('Page.getLayoutMetrics');
      const size = metrics.cssContentSize || metrics.contentSize;
      const r = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true,
        clip: { x: 0, y: 0, width: size.width, height: Math.min(size.height, 12000), scale: 1 } });
      fs.writeFileSync(file, Buffer.from(r.data, 'base64'));
    };
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
        const sidebar = await sidebarCheck();
        if (sidebar.status === 'fail') report.failures.push(`${role} ${url} @${width}: sidebar burger — ${sidebar.reason}`);
        report.pages.push({ url, width, finalPath, screenshot: name, horizontalOverflow: overflow, sidebar, issues });
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
