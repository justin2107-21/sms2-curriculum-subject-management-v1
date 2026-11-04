'use strict';
// Checks a -CtxScenario run: every expected step must be present and error-free, then its outcome is checked.
// Usage: node tools/uitest/check-scenario.cjs <folder containing <role>/report-<role>.json | folder per width/role>
const fs = require('fs'), path = require('path');
const EXPECTED = ['A', 'B', 'C1', 'C2', 'D1', 'D2', 'D3', 'E1', 'E2', 'F1', 'F2', 'F3', 'G', 'H', 'I'];
const roots = process.argv.slice(2);
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
    ['B', has('B', 'BSIT', 0) && has('B', 'PREORAL', 2)], ['C1', has('C1', 'PREORAL', 2)], ['C2 status', has('C2', 'PREORAL-2026 · Draft', 2)],
    ['D1 status', has('D1', 'FINAL · Draft', 2)], ['D2', has('D2', 'FINAL', 2)], ['D3', has('D3', 'FINAL', 2)],
    ['E1', has('E1', 'BSCRIM', 0)], ['E2', has('E2', 'BSCRIM', 0)], ['F1', has('F1', 'BSCRIM', 0)], ['F2', has('F2', 'BSCRIM', 0)], ['F3', has('F3', 'BSCRIM', 0)], ['G', has('G', 'BSCRIM', 0)],
    ['H guards', g.saveWithoutCsrf === 403 && g.readByPost === 405 && g.saveMismatchedChain === 422 && g.unknownModule === 422 && g.saveGet === 405],
    ['I signed out and back in, restored, Recent shown', has('I', 'BSCRIM', 0) && has('I', 'ROTC', 2) && by.I && by.I.anonymousRead === 401 && by.I.logoutStatus === 200 && (by.I.recentGroups || []).includes('Recent')],
  ];
  for (const c of checks) if (!c[1] && !problems.some(p => p.startsWith(c[0].split(' ')[0] + ' '))) problems.push(c[0] + ' wrong');
  if ((report.failures || []).length) problems.push('run failures: ' + report.failures.join(' | '));
  const label = path.relative(roots[0], file).replace(/report-.*/, '').replace(/\\/g, '/');
  console.log((label || report.role).padEnd(14), problems.length ? 'FAIL ' + problems.join('; ') : `ok (${EXPECTED.length} of ${EXPECTED.length} steps present, all checks pass)`);
  if (problems.length) bad++;
}
if (!files.length) { console.log('no reports found'); bad++; }
process.exit(bad ? 1 : 0);
