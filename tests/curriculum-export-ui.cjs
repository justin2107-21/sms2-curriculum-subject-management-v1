'use strict';
// Drives the real curriculum-export.js against a minimal stub DOM (no browser): protect tick + password behaviour on CSV and PDF, Print.
const assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm');
let checks = 0; const check = (v, l) => { assert.ok(v, l); checks++; };
const make = (id, extra = {}) => {
    const listeners = {};
    return Object.assign({ id, value: '', hidden: false, disabled: false, textContent: '', innerHTML: '', checked: false, dataset: {}, attrs: {}, type: 'text',
        addEventListener(n, f) { (listeners[n] = listeners[n] || []).push(f); }, fire(n) { return Promise.all((listeners[n] || []).map(f => f({ target: this }))); },
        setAttribute(k, v) { this.attrs[k] = v; }, getAttribute(k) { return this.attrs[k] ?? null; }, focus() { this.focused = true; },
        querySelector() { return { className: '' }; }, replaceChildren() {}, add() {}, get options() { return []; }, classList: { add() {}, remove() {} } }, extra);
};
const els = {};
for (const id of ['curriculumExportWorkspace', 'exportProgram', 'exportCurriculum', 'exportVersion', 'exportProgramSearch', 'exportCurriculumSearch', 'exportVersionSearch', 'exportProgramOptions', 'exportCurriculumOptions', 'exportVersionOptions',
    'downloadCurriculumCsv', 'downloadCurriculumPdf', 'printCurriculumExport', 'curriculumExportPreview', 'exportActionStatus', 'exportYear', 'exportSemester', 'exportEmpty', 'exportPanel', 'exportPreviewSummary', 'exportPreviewTitle',
    'exportProtect', 'exportProtectFields', 'exportPassword', 'exportPasswordConfirm', 'exportPasswordError']) els[id] = make(id);
els.curriculumExportWorkspace.dataset = { exportApi: '/api' };
for (const id of ['exportProgram', 'exportCurriculum', 'exportVersion']) els[id].value = '1';
els.exportProtectFields.hidden = true; els.exportPasswordError.hidden = true;
const toggles = ['exportPassword', 'exportPasswordConfirm'].map(t => make('t_' + t, { dataset: { pwToggle: t } }));
let printed = 0, downloads = 0, lastForm = null, nextResponse = null; const toasts = [];
const documentStub = { getElementById: id => els[id] || null, querySelector: s => s.includes('csrf') ? { content: 'tok' } : null, querySelectorAll: s => s.includes('pw-toggle') ? toggles : [],
    createElement: () => ({ click() { downloads++; }, remove() {} }), body: { appendChild() {} }, addEventListener() {} };
const ui = { escapeHtml: String, showToast: (m, t) => toasts.push([m, t]), fetchJson: async () => ({ year_levels: [], semesters: [] }), createCascadingAutocomplete: () => ({}) };
const ctx = { window: { CurriculumUI: ui, print: () => printed++, location: { href: 'http://x/' } }, document: documentStub, URL: Object.assign(function () {}, { createObjectURL: () => 'blob:x', revokeObjectURL() {} }), FormData: class { constructor() { this.m = new Map(); } set(k, v) { this.m.set(k, v); } },
    fetch: async (url, opts) => { lastForm = opts.body; return nextResponse; }, setTimeout, clearTimeout, Option: function () {}, console };
ctx.window.document = documentStub; vm.createContext(ctx);
const source = fs.readFileSync('modules/curriculum/assets/curriculum-export.js', 'utf8');
check(!/printPage|curriculum_print|window\.open/.test(source), 'script no longer navigates to the separate print page');
vm.runInContext(source, ctx);
const reply = (ok, type, body = 'x', message) => ({ ok, headers: { get: h => /type/i.test(h) ? type : 'attachment; filename="a.zip"' }, blob: async () => ({ size: body.length }), json: async () => ({ message }) });
(async () => {
    // Make the page "ready": a loaded dataset is set by the preview fetch.
    ui.fetchJson = async (api, args) => args.action === 'preview' ? { summary: { total_subjects: 2, total_units: 6 }, context: { curriculum_name: 'C', version_label: 'V', curriculum_code: 'c', program_name: 'p' }, scope: { label: 'All' }, years: [], generated_at: 'now', disclaimer: 'd' } : { year_levels: [], semesters: [] };
    await els.exportYear.fire('change'); await new Promise(r => setTimeout(r, 20));
    check(els.downloadCurriculumCsv.disabled === false, 'buttons enabled once a preview is loaded');
    // Checkbox shows / hides and clears the fields
    els.exportProtect.checked = true; await els.exportProtect.fire('change');
    check(els.exportProtectFields.hidden === false, 'ticking shows the password fields');
    els.exportPassword.value = 'abc'; els.exportPasswordConfirm.value = 'abc';
    els.exportProtect.checked = false; await els.exportProtect.fire('change');
    check(els.exportProtectFields.hidden === true && els.exportPassword.value === '' && els.exportPasswordConfirm.value === '', 'unticking hides and clears');
    // Invalid inputs on BOTH buttons: visible inline error, no request
    els.exportProtect.checked = true; await els.exportProtect.fire('change');
    const bad = [['', '', 'Enter a password'], ['short', 'short', 'at least 10'], ['a'.repeat(129), 'a'.repeat(129), 'at most 128'], ['Valid-password-1', 'Valid-password-2', 'do not match'], ['Valid-password-1', '', 'Confirm']];
    for (const button of ['downloadCurriculumCsv', 'downloadCurriculumPdf']) for (const [pw, again, text] of bad) {
        els.exportPassword.value = pw; els.exportPasswordConfirm.value = again; lastForm = null;
        await els[button].fire('click');
        check(els.exportPasswordError.hidden === false && els.exportPasswordError.textContent.includes(text) && lastForm === null, button + ' invalid (' + text + '): visible error, nothing sent');
    }
    // Valid: ZIP downloads, fields and error cleared, no password in status
    for (const [button, action] of [['downloadCurriculumCsv', 'download_csv'], ['downloadCurriculumPdf', 'download_pdf']]) {
        els.exportPassword.value = 'Valid-password-1'; els.exportPasswordConfirm.value = 'Valid-password-1'; nextResponse = reply(true, 'application/zip'); const before = downloads;
        await els[button].fire('click');
        check(downloads === before + 1 && lastForm.m.get('action') === action && lastForm.m.get('protect') === '1' && lastForm.m.get('export_password') === 'Valid-password-1', button + ': ticked + valid password downloads the ZIP');
        check(els.exportPassword.value === '' && els.exportPasswordConfirm.value === '' && els.exportPasswordError.hidden === true && !els.exportActionStatus.textContent.includes('Valid-password'), button + ': fields and error cleared after download');
        check(els.exportActionStatus.textContent.includes('downloaded'), 'status shows the result (fades later)');
    }
    // Server refusal surfaces inline
    els.exportPassword.value = 'Valid-password-1'; els.exportPasswordConfirm.value = 'Valid-password-1'; nextResponse = reply(false, 'application/json', '{}', 'Your role cannot export.');
    await els.downloadCurriculumPdf.fire('click');
    check(els.exportPasswordError.hidden === false && els.exportPasswordError.textContent.includes('role cannot export') && els.downloadCurriculumPdf.disabled === false, 'server error shown inline and buttons stay usable');
    // Unprotected download is not blocked by hidden password state
    els.exportProtect.checked = false; await els.exportProtect.fire('change'); nextResponse = reply(true, 'application/pdf'); const n = downloads;
    await els.downloadCurriculumPdf.fire('click');
    check(downloads === n + 1 && !lastForm.m.has('protect') && !lastForm.m.has('export_password'), 'unprotected PDF downloads and sends no password');
    // Show/hide toggle
    await toggles[0].fire('click'); check(els.exportPassword.type === 'text', 'toggle reveals'); await toggles[0].fire('click'); check(els.exportPassword.type === 'password', 'toggle hides again');
    // Print: window.print() on this page
    await els.printCurriculumExport.fire('click'); check(printed === 1, 'Print calls window.print()');
    console.log('PASS: ' + checks + ' export UI checks');
})().catch(e => { console.error(e); process.exit(1); });
