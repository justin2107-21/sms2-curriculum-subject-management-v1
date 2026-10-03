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
const selectors = {}, calls = [], posted = [];
let delayedVersion = null;
let previewValidation = {valid:true, conflicts:[], relationships:[]};
let delayedTrace = null, delayNextTrace = false;
const root = el('subjectMappingWorkspace');
root.dataset = { programApi: 'programs', curriculumApi: 'curricula', versionApi: 'versions', subjectApi: 'subjects',
    assignmentApi: 'mappings', requirementApi: 'requirements', canViewPrograms: '1', canViewSubjects: '1', canManageMappings: '1', canAddMappings: '1', canViewPrerequisites: '1' };
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
    escapeHtml: value => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;'),
    formatUnits: value => String(Number(value)), debounce: fn => fn,
    createModal: element => ({ open() { element.hidden = false; }, close() { element.hidden = true; } }),
    createAutocomplete: autocomplete,
    createCascadingAutocomplete(config) {
        const instances = config.levels.map((level, index) => autocomplete({ ...level,
            onSelect(item) { instances.slice(index + 1).forEach(selector => selector.clear(false)); config.onChange(index, item, item.id, 'select'); },
            onClear() { instances.slice(index + 1).forEach(selector => selector.clear(false)); config.onChange(index, null, '', 'clear'); } }));
        return { get: index => instances[index] };
    },
    async fetchJson(endpoint, params) {
        calls.push({ endpoint, params });
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
const context = { window: { CurriculumUI: ui, location: { search: '?program_id=1&curriculum_id=2&version_id=3' } },
    document: { getElementById: el, createElement: () => new Element('option') },
    Option: class { constructor(text, value) { this.text = text; this.value = value; } },
    URLSearchParams, FormData: FormDataMock, setTimeout, clearTimeout, console };
vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/subject-mapping-data.js', 'utf8'), context);
vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/subject-mapping-graph.js', 'utf8'), context);
const data = context.window.SubjectMappingData;
assert.equal(data.unitCents(mappings), 520, 'decimal unit totals must be exact');
const badRows = [row(1), row(2, { subject_id: 1 }), row(3, { subject_id: 1, semester: '2nd Semester' }), row(4, { year_level: '  ', semester: null, is_required: null })];
const issues = data.review(badRows);
assert.equal(issues.duplicates.length, 2, 'different semester placements are not duplicates');
assert.equal(issues.missingYear.length, 1); assert.equal(issues.missingSemester.length, 1); assert.equal(issues.placement.length, 1);
assert.equal(data.edges(mappings, [edge, edge, { ...edge, requirement_type: 'corequisite' }, { ...edge, subject_id: 999 }]).length, 1);
vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/subject-mapping-final.js', 'utf8'), context);
const settle = async () => { for (let i = 0; i < 12; i++) await new Promise(resolve => setImmediate(resolve)); };
const click = (id, selector, dataset) => el(id).handlers.click({ target: { closest: name => name === selector ? { dataset } : null } });
const displayedUnitCents = () => [...el('mappingMap').innerHTML.matchAll(/<\/div><span>([\d.]+) Units<\/span><\/header>/g)]
    .reduce((total, match) => total + Math.round(Number(match[1]) * 100), 0);
(async () => {
    await settle();
    assert.match(el('mappingMap').innerHTML, /5\.2 Units/);
    assert.equal((el('mappingMap').innerHTML.match(/data-open-mapping=/g) || []).length, 52);
    assert.equal(calls.filter(call => call.endpoint === 'mappings' && call.params.curriculum_version_id).length, 2, 'all pages fetched');
    assert.equal(el('mappingConnections').hidden, true, 'Connections default OFF');
    el('mappingShowConnections').checked = true; el('mappingShowConnections').handlers.change();
    assert.match(el('mappingConnections').innerHTML, /S1 → S2/);
    assert.match(el('mappingConnections').innerHTML, /S2 requires S1/);
    click('mappingConnections', '[data-connection]', { connection: 0 });
    assert.equal(el('mappingConnectionTitle').textContent, 'S1 → S2');
    assert.equal(el('mappingConnectionText').textContent, 'S2 requires S1.');
    assert.match(el('mappingConnectionManage').href, /program_id=1&curriculum_id=2&version_id=3&subject_id=2/);
    el('mappingShowConnections').checked = false; el('mappingShowConnections').handlers.change();
    assert.equal(el('mappingConnections').innerHTML, '');
    click('mappingMap', '[data-open-mapping]', { openMapping: 2 }); await settle();
    assert.equal(el('mappingDetailsModal').hidden, false);
    assert.match(el('mappingDetailsRequires').textContent, /S1/);
    assert.match(el('mappingDetailsSubjectLink').href, /subject_id=2/);
    assert.match(el('mappingDetailsVersionLink').href, /curriculum_id=2&version_id=3/);
    assert.equal(el('mappingDetailsProgram').textContent, 'BSIT');
    el('mappingDetailsEdit').handlers.click();
    assert.equal(el('mappingDetailsModal').hidden, true); assert.equal(el('editMappingModal').hidden, false);
    assert.equal(el('editMappingYear').value, '1st Year');
    el('mappingSubjectSearch').value = 'Subject 52'; el('mappingSubjectSearch').handlers.input();
    assert.equal((el('mappingMap').innerHTML.match(/data-open-mapping=/g) || []).length, 1);
    el('mappingPlacementFilter').value = '0'; el('mappingPlacementFilter').handlers.change();
    assert.match(el('mappingMap').innerHTML, /No subject placements match/);
    selectors.mappingTraceSearch.setSelected({ id: 1, subject_code: 'S1', subject_title: '<unsafe>', units: .1 }); await settle();
    for (const label of ['Program', 'Curriculum', 'Version', 'Year / Grade Level', 'Semester / Term', 'Placement', 'Units']) assert.ok(el('mappingTraceResults').innerHTML.includes('<dt>' + label + '</dt>'));
    assert.match(el('mappingTraceResults').innerHTML, /&lt;unsafe&gt;/);
    assert.match(el('mappingReviewResults').innerHTML, /No structural mapping issues detected/);
    requirements = [];
    selectors.mappingVersionInput.setSelected(version); await settle();
    el('mappingShowConnections').checked = true; el('mappingShowConnections').handlers.change();
    assert.match(el('mappingConnections').innerHTML, /No prerequisite configured/);
    mappings.push(row(90, { curriculum_version_id: 8, version_label: 'V2', subject_id: 1 }));
    selectors.mappingTraceSearch.setSelected({ id: 1, subject_code: 'S1', subject_title: 'Subject 1', units: .1 }); await settle();
    assert.equal((el('mappingTraceResults').innerHTML.match(/class="mapping-usage-card/g) || []).length, 1);
    assert.match(el('mappingTraceResults').innerHTML, /Placement in Selected Curriculum Version/);
    click('mappingMap', '[data-open-mapping]', { openMapping: 1 }); await settle();
    el('mappingDetailsRemove').handlers.click(); assert.match(el('removeMappingMessage').innerHTML, /Are you sure/);
    await el('confirmRemoveMapping').handlers.click.call(el('confirmRemoveMapping')); await settle();
    assert.equal(posted[0].action, 'remove'); assert.equal(mappings.some(item => item.id === 1), false);
    assert.match(el('mappingMap').innerHTML, /5\.1 Units/);
    click('mappingMap', '[data-open-mapping]', { openMapping: 2 }); await settle();
    el('mappingDetailsEdit').handlers.click(); el('editMappingYear').value = '2nd Year'; el('editMappingRequired').value = '0';
    await el('editMappingYear').handlers.change(); await settle();
    await el('editMappingForm').handlers.submit({ preventDefault() {} }); await settle();
    const updated = mappings.find(item => item.id === 2);
    assert.equal(updated.year_level, '2nd Year'); assert.equal(updated.is_required, '0');
    assert.equal(updated.subject_code, 'S2'); assert.equal(updated.units, .1);
    click('mappingMap', '[data-open-mapping]', { openMapping: 2 }); await settle();
    el('mappingDetailsEdit').handlers.click(); await settle();
    previewValidation = {valid:false, relationships:[], conflicts:[{message:'A prerequisite must be placed in an earlier academic period.',prerequisite_subject_id:2,prerequisite_code:'S2',prerequisite_year:'4th Year',prerequisite_semester:'2nd Semester',target_subject_id:3,target_code:'S3',target_title:'Dependent',target_year:'4th Year',target_semester:'1st Semester'}]};
    el('editMappingYear').value = '4th Year'; el('editMappingSemester').value = '2nd Semester';
    await el('editMappingYear').handlers.change(); await settle();
    assert.equal(el('editMappingForm-save').disabled,true);
    assert.match(el('mappingSequenceResult').innerHTML,/PREREQUISITE CONFLICT/);
    assert.match(el('mappingSequenceResult').innerHTML,/Selected: 4th Year/);
    const beforeBlocked = posted.length;
    await el('editMappingForm').handlers.submit({preventDefault(){}});
    assert.equal(posted.length,beforeBlocked,'Invalid preview prevents form submission');
    assert.equal(el('editMappingModal').hidden,false,'Blocked modal remains open');
    assert.equal(el('editMappingYear').value,'4th Year','Blocked edit preserves entered values');
    previewValidation = {valid:true,relationships:[{}],conflicts:[]};
    await el('editMappingSemester').handlers.change(); await settle();
    assert.equal(el('editMappingForm-save').disabled,false);
    assert.match(el('mappingSequenceResult').innerHTML,/Dependency affected/);
    previewValidation = {valid:true,relationships:[],conflicts:[]};
    assert.equal(posted[1].action, 'update_mapping');
    assert.ok(!('subject_code' in posted[1]) && !('units' in posted[1]) && !('subject_id' in posted[1]));
    el('addMappingButton').handlers.click();
    selectors.mappingSubjectSelectorSearch.setSelected({ id: 2, subject_code: 'S2', subject_title: 'Subject 2' });
    await el('addMappingForm').handlers.submit({ preventDefault() {} });
    assert.equal(posted.length, 2, 'duplicate subject is rejected before sending');
    selectors.mappingSubjectSelectorSearch.setSelected({ id: 99, subject_code: 'S99', subject_title: 'Existing master subject' });
    el('addMappingYear').value = '2nd Year'; el('addMappingSemester').value = '2nd Semester';
    await el('addMappingForm').handlers.submit({ preventDefault() {} }); await settle();
    assert.equal(posted[2].action, 'map_subject'); assert.equal(String(posted[2].curriculum_version_id), '3');
    assert.equal(displayedUnitCents(), 810);
    el('mappingYearFilter').value = '2nd Year'; el('mappingYearFilter').handlers.change();
    assert.equal((el('mappingMap').innerHTML.match(/data-open-mapping=/g) || []).length, 2, 'year selector filters the same map as year tabs');
    assert.match(el('mappingYearTabs').innerHTML, /aria-pressed="true" class="is-active">2nd Year/);
    click('mappingYearTabs', '[data-map-year]', { mapYear: '' });
    assert.equal(el('mappingYearFilter').value, '', 'All Years tab resets the year selector');
    assert.equal((el('mappingMap').innerHTML.match(/data-open-mapping=/g) || []).length, 52);
    selectors.mappingVersionInput.setSelected({ id: 88, curriculum_id: 2, version_label: 'Slow previous version' }); await settle();
    assert.equal(typeof delayedVersion, 'function');
    selectors.mappingVersionInput.setSelected(version); await settle();
    delayedVersion(); await settle();
    assert.equal(el('mappingVersionFilter').value, '3');
    assert.ok(!el('mappingVersionInput').value.includes('Slow previous version'));
    assert.equal(displayedUnitCents(), 810, 'stale response cannot overwrite current map');
    selectors.mappingProgramInput.setSelected({ id: 7, program_code: 'STEM', program_name: 'STEM', program_type: 'SHS' }); await settle();
    assert.equal(el('mappingVersionFilter').value, ''); assert.equal(el('mappingCurriculumFilter').value, '');
    assert.equal(el('addMappingButton').disabled, true);
    assert.equal(el('mappingTraceSubjectId').value, '', 'Context changes clear selected trace subject');
    assert.ok(!el('mappingTraceResults').innerHTML.includes('mapping-usage-card'));
    mappings = [];
    selectors.mappingCurriculumInput.setSelected({ ...curriculum, id: 20, academic_program_id: 7 });
    selectors.mappingVersionInput.setSelected({ ...version, id: 21, curriculum_id: 20 }); await settle();
    assert.match(el('mappingYearTabs').innerHTML, /All Grades/); assert.match(el('mappingYearTabs').innerHTML, /Grade 11/);
    assert.match(el('mappingYearTabs').innerHTML, /Grade 12/); assert.ok(!el('mappingYearTabs').innerHTML.includes('1st Year'));
    // Explicit required regression, stored IDs supply direction regardless of code order.
    mappings = [row(102, { subject_code: 'CC102' }), row(103, { subject_code: 'CC103' })];
    requirements = [{ ...edge, required_subject_id:102, subject_id:103, required_subject_code:'CC102', subject_code:'CC103' }];
    selectors.mappingProgramInput.setSelected(program); selectors.mappingCurriculumInput.setSelected(curriculum); selectors.mappingVersionInput.setSelected(version); await settle();
    assert.match(el('mappingConnections').innerHTML, /CC102 → CC103/);
    assert.match(el('mappingConnections').innerHTML, /CC103 requires CC102/);
    assert.ok(!el('mappingConnections').innerHTML.includes('CC102 requires CC103'));
    selectors.mappingTraceSearch.setSelected({id:102,subject_code:'CC102',subject_title:'Programming 1',units:3}); await settle();
    click('mappingTraceResults','[data-open-trace-mapping]',{openTraceMapping:102});
    await settle();
    assert.equal(el('mappingDetailsModal').hidden,false,'Open Placement opens compact modal directly');
    assert.equal(el('mappingDetailsCode').textContent,'CC102');
    assert.equal(el('mappingDetailsRemove').hidden,true,'Trace details footer is Close and Edit');
    selectors.mappingTraceSearch.setSelected({id:999,subject_code:'UNUSED',subject_title:'Unused',units:3}); await settle();
    assert.match(el('mappingTraceResults').innerHTML,/No matching subject is placed in the selected context/);
    assert.ok(!el('mappingTraceResults').innerHTML.includes('data-place-trace'));
    for (const count of [10,50,120]) {
        mappings = Array.from({length:count}, (_,i)=>row(i+1));
        selectors.mappingVersionInput.setSelected(version); await settle();
        assert.equal((el('mappingMap').innerHTML.match(/data-open-mapping=/g)||[]).length,count);
    }
    mappings = badRows; selectors.mappingVersionInput.setSelected(version); await settle();
    click('mappingReviewCards', '[data-review-issue]', {reviewIssue:'missingYear'});
    assert.match(el('mappingReviewResults').innerHTML, /data-edit-review-mapping="4"/);
    click('mappingReviewResults','[data-edit-review-mapping]',{editReviewMapping:4});
    assert.equal(el('editMappingId').value,4);
    // Two trace modes use the same SQL-backed filters for suggestions and results.
    mappings = [row(201,{subject_id:501}),row(202,{subject_id:501,curriculum_version_id:4,version_label:'V2'}),
        row(203,{subject_id:501,curriculum_id:20,curriculum_version_id:5}),
        row(204,{subject_id:501,academic_program_id:2,curriculum_id:30,curriculum_version_id:6})];
    const traceSubject={id:501,subject_code:'BPM101',subject_title:'Synthetic subject',units:3};
    const traceCount=()=> (el('mappingTraceResults').innerHTML.match(/class="mapping-usage-card/g)||[]).length;
    const tab=name=>el('mappingWorkspaceTabs').handlers.click({target:{closest:()=>({id:name})}});
    selectors.mappingProgramInput.clear(); await settle();
    assert.equal(el('mappingCurriculumFilter').value,'');assert.equal(el('mappingVersionFilter').value,'');
    assert.equal(el('addMappingButton').disabled,true);
    tab('mappingTabTrace');assert.equal(el('addMappingButton').hidden,true);
    await selectors.mappingTraceSearch.config.search('BPM101');
    assert.equal(calls.at(-1).params.academic_program_id,'');
    selectors.mappingTraceSearch.setSelected(traceSubject);await settle();
    assert.equal(traceCount(),4);assert.match(el('mappingTraceResults').innerHTML,/Usage Across All Contexts/);
    selectors.mappingProgramInput.setSelected(program);await settle();
    assert.equal(el('mappingTraceSubjectId').value,'');
    await selectors.mappingTraceSearch.config.search('BPM101');
    assert.equal(calls.at(-1).params.academic_program_id,'1');assert.equal(calls.at(-1).params.curriculum_id,'');
    selectors.mappingTraceSearch.setSelected(traceSubject);await settle();assert.equal(traceCount(),3);
    assert.match(el('mappingTraceResults').innerHTML,/Placements in Selected Context/);
    selectors.mappingCurriculumInput.setSelected(curriculum);await settle();assert.equal(el('mappingTraceSubjectId').value,'');
    await selectors.mappingTraceSearch.config.search('BPM101');assert.equal(calls.at(-1).params.curriculum_id,'2');
    selectors.mappingTraceSearch.setSelected(traceSubject);await settle();assert.equal(traceCount(),2);
    selectors.mappingVersionInput.setSelected(version);await settle();assert.equal(el('mappingTraceSubjectId').value,'');
    await selectors.mappingTraceSearch.config.search('BPM101');assert.equal(calls.at(-1).params.curriculum_version_id,'3');
    selectors.mappingTraceSearch.setSelected(traceSubject);await settle();assert.equal(traceCount(),1);
    assert.match(el('mappingTraceResults').innerHTML,/Placement in Selected Curriculum Version/);
    assert.ok(!el('mappingTraceResults').innerHTML.includes('data-place-trace'));
    tab('mappingTabReview');assert.equal(el('addMappingButton').hidden,true);
    tab('mappingTabMap');assert.equal(el('addMappingButton').hidden,false);assert.equal(el('addMappingButton').disabled,false);
    await selectors.mappingSubjectSelectorSearch.config.search('NEW');
    assert.equal(calls.at(-1).params.action,'placement_subject_options');
    assert.equal(calls.at(-1).params.curriculum_version_id,'3');
    delayNextTrace=true;selectors.mappingTraceSearch.setSelected(traceSubject);await settle();
    selectors.mappingCurriculumInput.clear();await settle();
    assert.equal(el('mappingVersionFilter').value,'');assert.equal(el('mappingTraceSubjectId').value,'');
    delayedTrace();await settle();assert.equal(traceCount(),0,'Delayed prior-context result cannot restore stale subject');
    selectors.mappingProgramInput.clear();await settle();
    selectors.mappingTraceSearch.setSelected({id:9999,subject_code:'UNUSED',subject_title:'Unplaced',units:3});await settle();
    assert.match(el('mappingTraceResults').innerHTML,/This subject is not placed in any curriculum version/);
    assert.ok(!el('mappingTraceResults').innerHTML.includes('data-place-trace'));
    console.log('PASS: complete pagination, exact unit totals, shared context invalidation, map filters, centered modal data, edit/removal flow, stored connection direction, default OFF, connection modal/navigation, 10/50/120 rows, issue editing, labeled multiple usage, escaping and structural review (mock DOM; visual review remains manual).');
})().catch(error => { console.error(error); process.exitCode = 1; });
