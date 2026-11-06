'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
    constructor(id) {
        Object.assign(this, { id, value: '', hidden: false, disabled: false, textContent: '', handlers: {}, attributes: {}, children: [], style: {},
            dataset: {}, clientWidth: 1000, clientHeight: 600, scrollLeft: 0, scrollTop: 0 });
        this.classes = new Set();
        this.classList = { toggle: (name, on) => on ? this.classes.add(name) : this.classes.delete(name),
            add: name => this.classes.add(name), remove: name => this.classes.delete(name) };
    }
    set innerHTML(value) {
        this.html = value; this.firstElementChild = value ? { style: {} } : null;
        this.graphElements = [];
        if (this.id === 'mappingRelationshipCanvas') {
            for (const match of value.matchAll(/<(button|path)[^>]*data-(open-relationship-mapping|graph-link)="(\d+)"[^>]*>/g)) {
                const node = new Element(match[1]);
                node.dataset[match[2] === 'graph-link' ? 'graphLink' : 'openRelationshipMapping'] = match[3];
                this.graphElements.push(node);
            }
        }
    }
    get innerHTML() { return this.html || ''; }
    addEventListener(name, fn) { this.handlers[name] = fn; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    getAttribute(name) { return this.attributes[name]; }
    replaceChildren(...items) { this.children = items; this.innerHTML = ''; }
    add(item) { this.children.push(item); }
    focus() {}
    select() { this.selected = true; }
    requestSubmit() { return this.handlers.submit && this.handlers.submit({ preventDefault() {} }); }
    reset() {}
    reportValidity() { return true; }
    querySelector(selector) { return el(this.id + '-save'); }
    querySelectorAll(selector) { return (this.graphElements || []).filter(node => selector === '[data-graph-link]' ? node.dataset.graphLink != null : selector === '[data-open-relationship-mapping]' && node.dataset.openRelationshipMapping != null); }
    setPointerCapture() {}
}
const elements = new Map();
const el = id => { if (!elements.has(id)) elements.set(id, new Element(id)); return elements.get(id); };
const program = { id: 1, program_code: 'BSIT', program_name: 'Information Technology', program_type: 'College' };
const curriculum = { id: 2, academic_program_id: 1, curriculum_code: 'IT-2026', curriculum_name: 'Information Technology' };
const version = { id: 3, curriculum_id: 2, version_label: 'V1' };
const row = (id, changes = {}) => ({ id, subject_id: id, curriculum_version_id: 3, academic_program_id: 1,
    curriculum_id: 2, program_code: 'BSIT', curriculum_code: 'IT-2026', curriculum_name: 'IT', version_label: 'V1',
    subject_code: 'S' + id, subject_title: 'Subject ' + id, units: 0.1, year_level: '1st Year', semester: '1st Semester', is_required: 1, ...changes });
let mappings = Array.from({ length: 52 }, (_, index) => row(index + 1));
const edge = { id: 1, curriculum_version_id: 3, subject_id: 2, required_subject_id: 1,
    requirement_type: 'prerequisite', subject_code: 'S2', subject_title: 'Subject 2', required_subject_code: 'S1', required_subject_title: 'Subject 1' };
let requirements = [edge];
let outcomeLinks = [{ id: 501, placement_id: 101, outcome_id: 1 }, { id: 502, placement_id: 102, outcome_id: 1 }];
let workspaceEditable = true, removeRefusal = false;
const selectors = {}, calls = [], posted = [];
let delayedVersion = null;
let previewValidation = {valid:true, conflicts:[], relationships:[]};
let delayedTrace = null, delayNextTrace = false;
const root = el('subjectMappingWorkspace');
root.dataset = { programApi: 'programs', curriculumApi: 'curricula', versionApi: 'versions', subjectApi: 'subjects',
    assignmentApi: 'mappings', requirementApi: 'requirements', outcomeApi: 'outcomes', canViewPrograms: '1', canViewSubjects: '1', canManageMappings: '1', canManageOutcomes: '1', canAddMappings: '1', canViewPrerequisites: '1' };
el('mappingHighlightPrerequisites').setAttribute('aria-pressed', false);
function autocomplete(config) {
    let selected = null;
    const instance = { clear(notify = true) { selected = null; config.input.value = ''; config.valueInput.value = ''; if (notify) config.onClear?.(); },
        setSelected(item, notify = true) { selected = item; config.valueInput.value = String(config.getId(item)); config.input.value = config.format(item).label; if (notify) config.onSelect?.(item); },
        hasSelection: () => Boolean(selected), getSelectedLabel: () => selected ? config.format(selected).label : '', refresh() {},
        config };
    selectors[config.input.id] = instance;
    return instance;
}
const paginated = (key, rows, params) => ({ [key]: rows.slice((Number(params.page || 1) - 1) * 50, Number(params.page || 1) * 50),
    pagination: { page: Number(params.page || 1), total_records: rows.length, total_pages: Math.ceil(rows.length / 50) || 1 } });
const ui = {
    ...require('./search-ranking.cjs'),
    escapeHtml: value => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;'),
    formatUnits: value => String(Number(value)), debounce: fn => fn,
    createModal: element => ({ open() { element.hidden = false; }, close() { element.hidden = true; } }),
    createAutocomplete: autocomplete,
    createCascadingAutocomplete(config) {
        const instances = config.levels.map((level, index) => autocomplete({ ...level,
            onSelect(item) { instances.slice(index + 1).forEach(selector => selector.clear(false)); config.onChange(index, item, item.id, 'select'); },
            onClear() { instances.slice(index + 1).forEach(selector => selector.clear(false)); config.onChange(index, null, '', 'clear'); } }));
        return { get: index => instances[index], setContext(items) { instances.forEach((selector,index)=>selector.setSelected(items[index],false)); }, clear() { instances.forEach(selector=>selector.clear(false)); } };
    },
    async fetchJson(endpoint, params) {
        calls.push({ endpoint, params });
        if (endpoint === 'outcomes' && params.action === 'workspace' && String(params.curriculum_version_id) === '21') throw new Error('CHED CMO 25 BSIT outcomes are available only for BSIT curriculum versions.');
        if (endpoint === 'outcomes' && params.action === 'workspace') return {context:{version_id:3,version_label:'V1',program_code:'BSIT',curriculum_name:'Information Technology',editable:workspaceEditable},
            outcomes:[{id:1,outcome_code:'IT01',outcome_text:'Apply knowledge of computing, science, and mathematics appropriate to the discipline',document_code:'CHED Memorandum Order No. 25, series of 2015',source_section:'Section 6.3',printed_page:8,pdf_page_index:8,coverage_status:'not_assessed',mapping_count:2}],
            placements:mappings.map(m=>({...m,placement_id:m.id+100})),mappings:outcomeLinks,limitations:{}};
        if (params.action === 'preview_placement') return {validation: previewValidation};
        if (endpoint === 'mappings' && params.subject_id && delayNextTrace) {
            delayNextTrace = false;
            return new Promise(resolve => { delayedTrace = () => resolve(paginated('mappings', [row(999, {subject_id:Number(params.subject_id)})], params)); });
        }
        if (endpoint === 'mappings' && String(params.curriculum_version_id) === '88') {
            return new Promise(resolve => { delayedVersion = () => resolve(paginated('mappings', [row(88, { curriculum_version_id: 88 })], params)); });
        }
        if (params.action === 'get') return endpoint === 'programs' ? { program } : endpoint === 'curricula' ? { curriculum } : { version };
        if (endpoint === 'mappings') return paginated('mappings', mappings.filter(item =>
            (!params.academic_program_id || String(item.academic_program_id) === String(params.academic_program_id)) &&
            (!params.curriculum_id || String(item.curriculum_id) === String(params.curriculum_id)) &&
            (!params.curriculum_version_id || String(item.curriculum_version_id) === String(params.curriculum_version_id)) &&
            (!params.subject_id || Number(item.subject_id) === Number(params.subject_id))), params);
        if (endpoint === 'requirements') return paginated('relationships', requirements, params);
        return paginated(endpoint, [], params);
    },
    clearFormError() {}, showFormError(element, message) { element.error = message; }, showToast() {}, setFormSubmitting() {},
    async postForm(endpoint, payload) {
        posted.push(Object.fromEntries(payload));
        if (endpoint === 'outcomes') {
            if (payload.get('action') === 'remove') {
                if (removeRefusal) throw new Error('Withdraw dependent CLO alignments before removing this graduate-outcome mapping.');
                outcomeLinks = outcomeLinks.filter(link => link.id !== Number(payload.get('mapping_id')));
            }
            if (payload.get('action') === 'add') outcomeLinks.push({ id: 600 + outcomeLinks.length, placement_id: Number(payload.get('placement_id')), outcome_id: Number(payload.get('outcome_id')) });
            return { message: 'Saved' };
        }
        if (payload.get('action') === 'remove') mappings = mappings.filter(item => item.id !== Number(payload.get('id')));
        if (payload.get('action') === 'update_mapping') {
            const mapping = mappings.find(item => item.id === Number(payload.get('id')));
            Object.assign(mapping, { year_level: payload.get('year_level'), semester: payload.get('semester'), is_required: payload.get('is_required') });
        }
        if (payload.get('action') === 'map_subject') mappings.push(row(99, { units: 3, subject_id: Number(payload.get('subject_id')),
            year_level: payload.get('year_level'), semester: payload.get('semester'), is_required: payload.get('is_required') }));
        return { message: 'Saved' };
    }
};
class FormDataMock extends Map {
    constructor(form) {
        super();
        if (form?.id === 'editMappingForm') {
            this.set('id', el('editMappingId').value); this.set('year_level', el('editMappingYear').value);
            this.set('semester', el('editMappingSemester').value); this.set('is_required', el('editMappingRequired').value);
            this.set('sequence_order', el('editMappingSequence').value);
        }
        if (form?.id === 'addMappingForm') {
            this.set('academic_program_id', el('addMappingProgramId').value); this.set('curriculum_id', el('addMappingCurriculumId').value);
            this.set('curriculum_version_id', el('addMappingVersionId').value); this.set('subject_id', el('mappingSubjectId').value);
            this.set('year_level', el('addMappingYear').value); this.set('semester', el('addMappingSemester').value); this.set('is_required', '1');
        }
    }
}
const context = { window: { CurriculumUI: ui, location: new URL('http://localhost/?program_id=1&curriculum_id=2&version_id=3'), addEventListener() {}, history: {pushState(_a,_b,url) { context.window.location = new URL(url); }} },
    document: { getElementById: el, createElement: () => new Element('option') },
    Option: class { constructor(text, value) { this.text = text; this.value = value; } },
    URL, URLSearchParams, FormData: FormDataMock, setTimeout, clearTimeout, console };
vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/subject-mapping-data.js', 'utf8'), context);
vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/subject-mapping-graph.js', 'utf8'), context);
const data = context.window.SubjectMappingData;
assert.equal(data.unitCents(mappings), 520, 'decimal unit totals must be exact');
const badRows = [row(1), row(2, { subject_id: 1 }), row(3, { subject_id: 1, semester: '2nd Semester' }), row(4, { year_level: '  ', semester: null, is_required: null })];
const issues = data.review(badRows);
assert.equal(issues.duplicates.length, 2, 'different semester placements are not duplicates');
assert.equal(issues.missingYear.length, 1); assert.equal(issues.missingSemester.length, 1); assert.equal(issues.placement.length, 1);
const cover = data.outcomeReview([{ id: 1 }, { id: 2 }, { id: 3 }], [{ placement_id: 10 }, { placement_id: 11 }], [{ placement_id: 10, outcome_id: 1 }, { placement_id: 10, outcome_id: 3 }]);
assert.deepEqual(cover.outcomesNoSubject.map(o => o.id), [2]); assert.deepEqual(cover.subjectsNoOutcome.map(p => p.placement_id), [11]);
assert.equal(JSON.stringify(data.outcomeReview([], [], [])), '{"outcomesNoSubject":[],"subjectsNoOutcome":[]}');
assert.equal(data.edges(mappings, [edge, edge, { ...edge, requirement_type: 'corequisite' }, { ...edge, subject_id: 999 }]).length, 1);
vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/outcomes-presentation.js', 'utf8'), context);
vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/placement-groups.js', 'utf8'), context);
vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/subject-mapping-matrix.js', 'utf8'), context);
vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/subject-mapping-final.js', 'utf8'), context);
const settle = async () => { for (let i = 0; i < 12; i++) await new Promise(resolve => setImmediate(resolve)); };
const click = (id, selector, dataset) => el(id).handlers.click({ target: { closest: name => name === selector ? { dataset } : null } });
const rowCount = () => (el('mappingMap').innerHTML.match(/<td class="map-table__code">/g) || []).length;
const terms = () => [...el('mappingMap').innerHTML.matchAll(/<tr class="map-table__term"><th colspan="\d+" scope="colgroup"><span>([^<]*)<\/span>/g)].map(m => m[1]);
(async () => {
    await settle();
    // Slice 1: the Curriculum Map tab is a read-only, Builder-style list of the version's placements.
    assert.equal(el('mappingVersionFilter').value, '3');
    assert.deepEqual(terms(), ['1st Year · 1st Semester · 52 subjects']);
    assert.equal(rowCount(), 52); assert.equal(el('mappingCount').textContent, '52 subjects');
    assert.ok(!/data-open-mapping|data-map-year|Place Subject|data-connection/.test(el('mappingMap').innerHTML), 'no placement editing, pills or prerequisite connections in the map');
    // Matrix: outcome columns with full text, check marks, footer counts from the visible rows.
    assert.ok(el('mappingMap').innerHTML.includes('title="Apply knowledge of computing'));
    assert.ok(el('mappingMap').innerHTML.includes('<span class="sr-only">IT01: Apply knowledge'));
    assert.equal((el('mappingMap').innerHTML.match(/map-cell--on/g) || []).length, 2 + 1, 'two mapped cells plus the legend sample');
    assert.ok(el('mappingMap').innerHTML.includes('<th colspan="2" scope="row">Subjects mapped</th><td class="map-table__cell">2</td>'));
    assert.equal(context.window.SubjectMappingMatrix.mapCell(false).includes('not mapped'), true);
    assert.equal(el('mappingBuilderLink').hidden, false);
    assert.equal(el('mappingBuilderLink').href, 'curriculum_builder.php?program_id=1&curriculum_id=2&version_id=3');
    el('mappingSubjectSearch').value = 'Subject 52'; el('mappingSubjectSearch').handlers.input();
    assert.equal(rowCount(), 1); assert.equal(el('mappingCount').textContent, '1 subject');
    el('mappingStatusFilter').value = 'mapped'; el('mappingStatusFilter').handlers.change();
    assert.match(el('mappingMap').innerHTML, /No subjects match these filters/);
    el('mappingSubjectSearch').value = ''; el('mappingSubjectSearch').handlers.input();
    el('mappingStatusFilter').value = 'unmapped'; el('mappingStatusFilter').handlers.change(); assert.equal(rowCount(), 50);
    el('mappingStatusFilter').value = ''; el('mappingYearFilter').value = '2nd Year'; el('mappingYearFilter').handlers.change();
    assert.match(el('mappingMap').innerHTML, /No subjects match these filters/);
    el('mappingYearFilter').value = ''; el('mappingYearFilter').handlers.change();
    assert.equal(rowCount(), 52);
    // Terms are grouped and ordered like the Builder; text is escaped.
    mappings = [row(1, { subject_title: '<b>x</b>', semester: '2nd Semester' }), row(2, { year_level: '2nd Year' }), row(3)];
    selectors.mappingVersionInput.setSelected(version); await settle();
    assert.deepEqual(terms(), ['1st Year · 1st Semester · 1 subject', '1st Year · 2nd Semester · 1 subject', '2nd Year · 1st Semester · 1 subject']);
    assert.ok(el('mappingMap').innerHTML.includes('&lt;b&gt;x&lt;/b&gt;'));
    // A slow response for a previous version cannot overwrite the current map.
    selectors.mappingVersionInput.setSelected({ id: 88, curriculum_id: 2, version_label: 'Slow previous version' }); await settle();
    selectors.mappingVersionInput.setSelected(version); await settle();
    delayedVersion(); await settle();
    assert.equal(el('mappingVersionFilter').value, '3'); assert.equal(rowCount(), 3);
    // Slice 3: editing. Cells are buttons; checking asks for a rationale, clearing asks for confirmation; the server's refusal is shown.
    const cell = (placement, mapping) => ({ dataset: { placement: String(placement), outcome: '1', ...(mapping ? { mapping: String(mapping) } : {}) } });
    const clickCell = target => el('mappingMap').handlers.click({ target: { closest: selector => selector === '.map-toggle' ? target : null } });
    const sendAdd = async () => { await el('mapAddForm').handlers.submit({ preventDefault() {} }); await settle(); };
    outcomeLinks = [{ id: 501, placement_id: 101, outcome_id: 1 }, { id: 502, placement_id: 102, outcome_id: 1 }];
    selectors.mappingVersionInput.setSelected(version); await settle();
    assert.match(el('mappingMap').innerHTML, /class="map-toggle" aria-pressed="false" aria-label="Map S3 to IT01"/);
    assert.match(el('mappingMap').innerHTML, /aria-pressed="true" aria-label="Remove mapping of S1 to IT01"/);
    assert.match(el('mapAccessNote').textContent, /Click a cell/);
    clickCell(cell(103));
    assert.equal(el('mapAddModal').hidden, false); assert.equal(el('mapAddRationale').value, ''); assert.equal(el('mapAddRationale').selected, true);
    assert.equal(posted.length, 0, 'opening the dialog saves nothing');
    await sendAdd(); assert.equal(posted.length, 0, 'a blank rationale is not sent'); assert.equal(el('mapAddModal').hidden, false);
    el('mapAddRationale').value = 'Covers IT01 through lab work.'; await sendAdd();
    assert.deepEqual([posted[0].action, posted[0].placement_id, posted[0].outcome_id, posted[0].rationale], ['add', '103', '1', 'Covers IT01 through lab work.']);
    assert.equal(el('mapAddModal').hidden, true);
    assert.match(el('mappingMap').innerHTML, /aria-label="Remove mapping of S3 to IT01"/);
    clickCell(cell(101, 501));
    assert.equal(el('mapRemoveModal').hidden, false); assert.equal(posted.length, 1, 'opening the confirmation removes nothing');
    await el('mapRemoveConfirm').handlers.click.call(el('mapRemoveConfirm')); await settle();
    assert.deepEqual([posted[1].action, posted[1].mapping_id], ['remove', '501']);
    assert.match(el('mappingMap').innerHTML, /aria-label="Map S1 to IT01"/);
    clickCell(cell(101));
    assert.equal(el('mapAddRationale').value, 'Covers IT01 through lab work.', 'last rationale is prefilled'); assert.equal(el('mapAddRationale').selected, true);
    assert.equal(posted.length, 2, 'the prefilled rationale is not saved until confirmed');
    el('mapAddModal').hidden = true;
    removeRefusal = true; clickCell(cell(102, 502));
    await el('mapRemoveConfirm').handlers.click.call(el('mapRemoveConfirm')); await settle();
    assert.match(el('mapRemoveModal').error, /Withdraw dependent CLO alignments/); assert.equal(el('mapRemoveModal').hidden, false, 'refusal keeps the dialog open');
    assert.match(el('mappingMap').innerHTML, /aria-label="Remove mapping of S2 to IT01"/, 'refused removal leaves the mapping');
    removeRefusal = false; el('mapRemoveModal').hidden = true;
    workspaceEditable = false; selectors.mappingVersionInput.setSelected(version); await settle();
    assert.ok(!/<button|map-toggle/.test(el('mappingMap').innerHTML), 'read-only version has no cell controls');
    assert.match(el('mapAccessNote').textContent, /Read-only: this curriculum version cannot be edited/);
    workspaceEditable = true; selectors.mappingVersionInput.setSelected(version); await settle();
    // Subject Trace and Mapping Review keep working.
    selectors.mappingTraceSearch.setSelected({ id: 1, subject_code: 'S1', subject_title: '<unsafe>', units: .1 }); await settle();
    assert.match(el('mappingTraceResults').innerHTML, /&lt;unsafe&gt;/);
    // Slice 4: Trace rows are a compact table linking out to the Builder; no placement modal button.
    assert.match(el('mappingTraceResults').innerHTML, /<td class="csms-ctable__code">S1<\/td>/);
    assert.match(el('mappingTraceResults').innerHTML, /href="curriculum_builder\.php\?program_id=1&amp;curriculum_id=2&amp;version_id=3"[^>]*>Open in Builder/);
    assert.ok(!/Open Placement|data-open-trace-mapping/.test(el('mappingTraceResults').innerHTML));
    // Mapping Review: structural checks plus the two outcome-coverage checks, each a row with a count and an expandable list.
    outcomeLinks = [{ id: 501, placement_id: 101, outcome_id: 1 }, { id: 502, placement_id: 102, outcome_id: 1 }]; selectors.mappingVersionInput.setSelected(version); await settle();
    const review = () => el('mappingReviewCards').innerHTML;
    const count = key => Number(new RegExp('data-review-issue="' + key + '"[^>]*>.*?<span class="mapping-check__count[^"]*">(\\d+)<\/span>').exec(review())[1]);
    const toggle = key => el('mappingReviewCards').handlers.click({ target: { closest: sel => sel === '[data-review-issue]' ? { dataset: { reviewIssue: key }, getAttribute: () => null } : null } });
    ['placement', 'duplicates', 'missingYear', 'missingSemester', 'outcomesNoSubject', 'subjectsNoOutcome'].forEach(key => assert.ok(review().includes('data-review-issue="' + key + '"'), key));
    assert.ok(!review().includes('outcomes never') && !/never reach/i.test(review()), 'no level-based check');
    assert.equal(count('missingYear'), 0); assert.equal(count('outcomesNoSubject'), 0, 'IT01 is mapped'); assert.equal(count('subjectsNoOutcome'), 1, 'S3 has no mapping');
    assert.ok(review().includes('aria-expanded="false"')); assert.ok(!/<td class="csms-ctable__code">S3/.test(review()));
    toggle('subjectsNoOutcome');
    assert.match(review(), /aria-expanded="true"[^>]*aria-controls="reviewList-subjectsNoOutcome"/);
    assert.match(review(), /<td class="csms-ctable__code">S3<\/td><td>Subject 3<\/td>/);
    toggle('outcomesNoSubject'); assert.ok(!/reviewList-outcomesNoSubject"[^>]*>\s*<table/.test(review()), 'an empty check does not expand');
    toggle('subjectsNoOutcome'); assert.ok(!/<td class="csms-ctable__code">S3/.test(review()), 'second click collapses');
    // Nothing is mapped: every outcome is unsupported and every placement unmapped.
    outcomeLinks = []; selectors.mappingVersionInput.setSelected(version); await settle();
    assert.equal(count('outcomesNoSubject'), 1); assert.equal(count('subjectsNoOutcome'), 3);
    toggle('outcomesNoSubject'); assert.match(review(), /<td class="csms-ctable__code">IT01<\/td><td>Apply knowledge/);
    outcomeLinks = [{ id: 501, placement_id: 101, outcome_id: 1 }, { id: 502, placement_id: 102, outcome_id: 1 }, { id: 503, placement_id: 103, outcome_id: 1 }];
    selectors.mappingVersionInput.setSelected(version); await settle();
    assert.equal(count('subjectsNoOutcome'), 0); assert.equal(count('outcomesNoSubject'), 0);
    assert.match(el('mappingReviewResults').innerHTML, /No mapping issues detected/);
    // Another program: context clears, SHS grade levels are offered.
    selectors.mappingProgramInput.setSelected({ id: 7, program_code: 'STEM', program_name: 'STEM', program_type: 'SHS' }); await settle();
    assert.equal(el('mappingVersionFilter').value, ''); assert.equal(el('mappingBuilderLink').hidden, true);
    mappings = [];
    selectors.mappingCurriculumInput.setSelected({ ...curriculum, id: 20, academic_program_id: 7 });
    selectors.mappingVersionInput.setSelected({ ...version, id: 21, curriculum_id: 20 }); await settle();
    assert.match(el('mappingMap').innerHTML, /No outcome catalog for this program yet./);
    assert.ok(!el('mappingMap').innerHTML.includes('<table'), 'no empty or invented matrix without a catalog');
    assert.match(el('mappingReviewCards').innerHTML, /No outcome catalog for this program yet./);
    assert.ok(!/outcomesNoSubject|subjectsNoOutcome/.test(el('mappingReviewCards').innerHTML), 'no outcome checks without a catalog, not zero');
    assert.ok(el('mappingReviewCards').innerHTML.includes('data-review-issue="missingYear"'), 'structural checks still apply');
    console.log('PASS: complete pagination, editing (rationale dialog with prefill, remove confirmation, server refusal shown, read-only version), Builder-style map (terms, order, escaping, filters, Builder link), stale-response guard, Subject Trace, Mapping Review, empty state');
})().catch(error => { console.error(error); process.exitCode = 1; });
