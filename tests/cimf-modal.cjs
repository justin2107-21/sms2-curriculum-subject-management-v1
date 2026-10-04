'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const elements = new Map();
function element(id) {
    if (!elements.has(id)) elements.set(id, {
        id, hidden: id === 'cimfModal', disabled: false, value: '', textContent: '', innerHTML: '', dataset: {}, handlers: {},
        addEventListener(name, fn) { (this.handlers[name] ||= []).push(fn); },
        async click() { for (const fn of this.handlers.click || []) await fn({ target: this }); },
        querySelector() { return { click() {} }; },
    });
    return elements.get(id);
}
element('prerequisiteWorkspace').dataset = { cimfApi: 'cimf', cimfStatus: 'SUCCESS' };
const listeners = {};
const document = { getElementById: element, addEventListener(name, fn) { listeners[name] = fn; } };
let calls = 0;
const analysis = { summary: 'Human review advised.', review_items: [], limitations: [] };
const window = { CurriculumUI: {
    escapeHtml: value => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;'),
    createModal: node => ({ open() { node.hidden = false; }, close() { node.hidden = true; } }),
    async postForm() { calls++; return { analysis, demo_data: true }; },
}, PrerequisiteCIMFContext: () => ({ versionId: '1', loaded: true,
    context: [{ program_code: 'BSIT' }, { curriculum_code: 'C1' }, { version_label: 'V1', description: 'synthetic demo' }],
    placements: [{ subject_id: 1, subject_code: 'CAP101', subject_title: 'Capstone' }, { subject_id: 2, subject_code: 'BPM101', subject_title: 'BPM' }],
}) };
vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/cimf-prerequisite.js', 'utf8'), { window, document, FormData });
(async () => {
    await element('openCimfButton').click();
    assert.equal(element('cimfModal').hidden, false);
    assert.equal(calls, 0, 'Opening modal has no API cost');
    await element('cimfAnalyze').click();
    assert.equal(calls, 1);
    assert.match(element('cimfAnalysis').innerHTML, /Human review advised/);
    await element('cimfAskView').click();
    assert.equal(element('cimfMain').hidden, true);
    assert.equal(element('cimfBack').hidden, false);
    await element('cimfBack').click();
    assert.equal(element('cimfModal').hidden, false, 'Back stays in modal');
    assert.equal(element('cimfMain').hidden, false);
    assert.match(element('cimfAnalysis').innerHTML, /Human review advised/, 'Analysis preserved');
    assert.equal(calls, 1, 'Back does not repeat API request');
    await element('cimfCompareView').click();
    await element('cimfBack').click();
    assert.equal(element('cimfModal').hidden, false);
    assert.equal(calls, 1);
    console.log('PASS: CIMF modal Back navigation and analysis preservation.');
})().catch(error => { console.error(error); process.exitCode = 1; });
