'use strict';
// Checks a -CtxScenario run: every expected step must be present and error-free, then its outcome is checked.
// Usage: node tools/uitest/check-scenario.cjs <folder containing <role>/report-<role>.json | folder per width/role>
const fs = require('fs'), path = require('path');
const EXPECTED = ['A', 'B', 'C1', 'C2', 'D1', 'D2', 'D3', 'E1', 'E2', 'F1', 'F2', 'F3', 'G', 'H', 'I'];
const roots = process.argv.slice(2);
const FULL = new Set(['superadmin', 'staff']);          // may read and export drafts
const OFFICIAL_ONLY = new Set(['admin', 'acadoffice']); // read drafts; export approved versions only
const iRestored = (role, has) => FULL.has(role) ? has('I', 'UITEST-PB', 0) && has('I', 'UITEST-D', 2)
  : OFFICIAL_ONLY.has(role) ? has('I', 'UITEST-PA', 0) && has('I', 'UITEST-B', 2) : has('I', 'Active', 2);
// Denials are asserted, never skipped: a role that gains access it should not have fails here.
// Fixtures: tools/uitest/seed-fixtures.php (UITEST-A/B drafts, UITEST-C/D active).
function roleChecks(role, by, has, v) {
  const blank = k => (v(k)[2] || '') === 'Choose version' && (v(k)[0] || '') === 'Choose program';
  const forbidden = k => !!(by[k] && by[k].forbidden) && !by[k].state;
  if (FULL.has(role)) return [
    ['B', has('B', 'UITEST-PA', 0) && has('B', 'UITEST-A', 2)], ['C1', has('C1', 'UITEST-A', 2)], ['C2 status', has('C2', 'UITEST-A · Draft', 2)],
    ['D1 status', has('D1', 'UITEST-B · Draft', 2)], ['D2', has('D2', 'UITEST-B', 2)], ['D3', has('D3', 'UITEST-B', 2)],
    ['E1', has('E1', 'UITEST-PB', 0)], ['E2', has('E2', 'UITEST-PB', 0)], ['F1', has('F1', 'UITEST-PB', 0)], ['F2', has('F2', 'UITEST-PB', 0)], ['F3', has('F3', 'UITEST-PB', 0)], ['G', has('G', 'UITEST-PB', 0)],
  ];
  if (OFFICIAL_ONLY.has(role)) return [
    ['B', has('B', 'UITEST-PA', 0) && has('B', 'UITEST-A', 2)], ['C1', has('C1', 'UITEST-A', 2)], ['C2 status', has('C2', 'UITEST-A · Draft', 2)],
    ['D1 status', has('D1', 'UITEST-B · Draft', 2)], ['D2', has('D2', 'UITEST-B', 2)],
    ['D3 export denies the draft', blank('D3')], ['E1 export denies an unapproved link', blank('E1')],
    ['E2 context kept, link not adopted', has('E2', 'UITEST-B', 2) && !has('E2', 'UITEST-PB', 0)], ['F1 export stays blank', blank('F1')],
    ['F2', has('F2', 'UITEST-B', 2)], ['F3', has('F3', 'UITEST-B', 2)], ['G', has('G', 'UITEST-B', 2)],
  ];
  const active = k => has(k, 'Active', 2) && !has(k, 'Draft', 2);
  return [ // Acad Faculty: drafts are invisible, an active version is chosen for them
    ['B draft version denied', (v('B')[2] || '') === 'Choose version'], ['C1', active('C1')], ['C2 status', active('C2')], ['D1 status', active('D1')], ['D2', active('D2')],
    ['D3 export 403', forbidden('D3')], ['E1 export 403', forbidden('E1')], ['E2 grade weighting 403', forbidden('E2')], ['F1 export 403', forbidden('F1')],
    ['F2', active('F2')], ['F3', active('F3')], ['G', active('G')],
  ];
}

let bad = 0;
const files = [];
(function walk(d) { for (const e of fs.readdirSync(d, { withFileTypes: true })) { const p = path.join(d, e.name); if (e.isDirectory()) walk(p); else if (/^report-.*\.json$/.test(e.name)) files.push(p); } })(roots[0]);
for (const file of files.sort()) {
  const report = JSON.parse(fs.readFileSync(file, 'utf8'));
  const steps = report.ctxscenario || [];
  const by = {}; for (const s of steps) by[s.name.split('.')[0]] = s;
  const problems = [];
  for (const id of EXPECTED) {
    if (!by[id]) problems.push(id + ' MISSING');
    else if (by[id].error) problems.push(id + ' threw: ' + by[id].error);
  }
  const v = k => (by[k] && by[k].state ? by[k].state.values : []);
  const has = (k, t, i) => !!(v(k)[i] && v(k)[i].includes(t));
  const g = (by.H || {}).guards || {};
  const checks = [
    ['A default', v('A')[2] && v('A')[2] !== 'Choose version' && ['last_edited', 'newest_active'].includes(by.A && by.A.rule)],
    ...roleChecks(report.role, by, has, v),
    ['H guards', g.saveWithoutCsrf === 403 && g.readByPost === 405 && g.saveMismatchedChain === 422 && g.unknownModule === 422 && g.saveGet === 405],
    ['I signed out and back in, restored, Recent shown', iRestored(report.role, has) && by.I && by.I.anonymousRead === 401 && by.I.logoutStatus === 200 && (by.I.recentGroups || []).includes('Recent')],
  ];
  for (const c of checks) if (!c[1] && !problems.some(p => p.startsWith(c[0].split(' ')[0] + ' '))) problems.push(c[0] + ' wrong');
  if ((report.failures || []).length) problems.push('run failures: ' + report.failures.join(' | '));
  const label = path.relative(roots[0], file).replace(/report-.*/, '').replace(/\\/g, '/');
  console.log((label || report.role).padEnd(14), problems.length ? 'FAIL ' + problems.join('; ') : `ok (${EXPECTED.length} of ${EXPECTED.length} steps present, all checks pass)`);
  if (problems.length) bad++;
}
if (!files.length) { console.log('no reports found'); bad++; }
process.exit(bad ? 1 : 0);
