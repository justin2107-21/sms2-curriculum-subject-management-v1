'use strict';
// DB-free guard for the page-header / context-bar / state-message work (UI_Audit Batch 5).
// Static checks only: the behaviour itself is exercised in a real browser by tools/uitest/run.ps1.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const read = file => fs.readFileSync(file, 'utf8');

const topbar = read('shared/topbar.php');
for (const asset of ['css/csms-tokens.css', 'css/csms-fonts.css', 'css/csms-components.css', 'css/session-warning.css',
    'js/csms-context-bar.js', 'js/csms-live-states.js', 'js/session-timeout.js']) {
    assert.ok(topbar.includes(asset), 'shared topbar must load ' + asset);
}
for (const asset of ['css/csms-fonts.css', 'css/csms-components.css', 'js/csms-context-bar.js', 'js/csms-live-states.js']) {
    assert.ok(fs.existsSync(asset), asset + ' must exist');
}
assert.ok(topbar.indexOf('csms-tokens.css') < topbar.indexOf('csms-components.css'), 'tokens load before the component layer');

// Page header: Newsreader comes from one shared file, the gutter is one token.
const fonts = read('css/csms-fonts.css');
assert.equal((fonts.match(/font-family: "Newsreader"/g) || []).length, 2, 'Newsreader latin + latin-ext faces');
assert.ok(!/@font-face\s*\{[^}]*Newsreader/.test(read('css/csms-dashboard.css')), 'dashboard css no longer declares the Newsreader faces');
assert.match(read('css/csms-tokens.css'), /--csms-page-gutter:\s*24px/);
const components = read('css/csms-components.css');
assert.match(components, /\.page-title-bar, \.mapping-workspace-header, \.subject-landing-header/);
assert.match(components, /--csms-page-gutter/);
assert.match(components, /\.csms-ctx-trigger/);
assert.match(components, /\.csms-ctx-panel/);
assert.ok(!/\.csms-context-bar|is-collapsed/.test(components), 'the collapsed summary bar is gone (D3 removed)');
assert.ok(!/overflow-x: clip/.test(components), 'the pinned-bar overflow workaround is gone');

// Pages: every header has an h1; Audit Logs and Reports no longer use h2 titles.
for (const [file, title] of [['admin/audit_logs.php', 'Audit Logs'], ['reports_tab/reports.php', 'Reports'], ['dashboard/account.php', 'Account Settings']]) {
    const source = read(file);
    assert.match(source, new RegExp('<h1 class="page-title">[^<]*(<i[^>]*></i>)?\\s*' + title), file + ' needs an h1 page title');
    assert.ok(!/<h2 class="page-title">/.test(source), file + ' must not use an h2 page title');
}
const reports = read('reports_tab/reports.php');
assert.ok(reports.includes("require __DIR__ . '/../shared/topbar.php'"), 'reports.php uses the shared topbar');
assert.ok(!/class="topbar"/.test(reports), 'reports.php has no private topbar copy');
assert.ok(!reports.includes('$sess_initial'), 'unescaped avatar initial is gone');

// Subject Mapping: the year dropdown is a hidden state holder (page script and tests keep their element); pills stay.
const mapping = read('modules/curriculum/pages/subject_mapping.php');
assert.match(mapping, /csms-hidden-field[^>]*><span class="sr-only">Year \/ Grade<\/span><select id="mappingYearFilter" tabindex="-1" aria-hidden="true">/);
assert.ok(mapping.includes('id="mappingYearTabs"'), 'year pills stay');
assert.match(components, /\.csms-hidden-field \{ display: none !important; \}/);

// Context picker: labels above select-like fields, searchable panels, Reset only (no collapsed summary / Change).
const bar = read('js/csms-context-bar.js');
assert.match(bar, /csms:curriculum-context-selection/);
assert.match(bar, /csms-ctx-reset/);
assert.ok(!/data-context-change|is-collapsed/.test(bar), 'no collapsed summary or Change link');
assert.match(bar, /role="listbox"/);
assert.match(bar, /aria-haspopup="listbox"/);
assert.match(bar, /Choose a curriculum version to begin/);
// The working context (address, saved context, resolution order) lives in one controller.
const controller = read('js/curriculum-context.js');
assert.match(controller, /window\.CSMSWorkingContext/);
assert.match(controller, /replaceState/);
assert.ok(!read('modules/curriculum/pages/prerequisite_configuration.php').includes('data-context-url'), 'URL writing belongs to the shared controller now');

// ST2: shared helpers announce errors as alerts and the rest as status.
const common = read('modules/curriculum/assets/curriculum-common.js');
assert.match(common, /toast\.setAttribute\('role', safeType === 'error' \? 'alert' : 'status'\)/);
assert.match(common, /errorBox\.setAttribute\('role', 'alert'\)/);
assert.match(common, /row\.setAttribute\('role', kind === 'error' \? 'alert' : 'status'\)/);
const live = read('js/csms-live-states.js');
assert.match(live, /\.error-state/);
assert.match(live, /aria-live/);
assert.match(live, /never overwritten/);

// Sidebar (D7): one icon column wide enough for every icon, no break inside "Course", drawer below the topbar on phones.
assert.match(read('css/csms-tokens.css'), /--sms2-sidebar-icon-column-size:\s*20px/);
const sidebar = read('shared/sidebar.php');
assert.ok(sidebar.includes('Academic Strand/<wbr>Course Assignment'), 'the strand label can break after the slash');
assert.ok(!sidebar.includes('sidebar-no-break">Academic Strand'), 'no no-break span that forces "/Course" to split');
assert.match(components, /body \.sidebar \{ height: calc\(100dvh - var\(--sms2-topbar-height, 56px\)\); top: var\(--sms2-topbar-height, 56px\); \}/);
assert.match(components, /overflow-wrap: break-word;\s*word-break: normal;/);

console.log('PASS: Batch 5 wiring (shared header assets, h1 titles, reports on shared topbar, hidden year holder, context bar hooks, live-state helpers)');
