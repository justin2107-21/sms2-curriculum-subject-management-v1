'use strict';
module.exports = function createHarness(options = {}) {
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
    focus() { context.document.activeElement=this; }
    reset() {}
    reportValidity() { return true; }
    querySelector(selector) { return el(this.id + '-save'); }
    querySelectorAll(selector) {
        if(this.modalCloseNodes) return selector==='[data-modal-close]' ? this.modalCloseNodes : this.modalCloseNodes.filter(n=>n.id.endsWith('-icon')||n.id.endsWith('-footer'));
        return (this.graphElements || []).filter(node => selector === '[data-graph-link]' ? node.dataset.graphLink != null : selector === '[data-open-relationship-mapping]' && node.dataset.openRelationshipMapping != null); }
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
    assignmentApi: 'mappings', requirementApi: 'requirements', outcomeApi: 'outcomes', cloApi: 'clos', canManageClos:'1', canManagePlans:'1', canViewPrograms: '1', canViewSubjects: '1', canManageMappings: '1', canManageOutcomes: '1', canAddMappings: '1', canViewPrerequisites: '1' };
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
        if (endpoint === 'outcomes' && params.action === 'workspace') return {context:{version_id:3,version_label:'V1',program_code:'BSIT',curriculum_name:'Information Technology',editable:true},
            outcomes:[{id:1,outcome_code:'IT01',outcome_text:'Apply knowledge of computing, science, and mathematics appropriate to the discipline',document_code:'CHED Memorandum Order No. 25, series of 2015',source_section:'Section 6.3',printed_page:8,pdf_page_index:8,coverage_status:'not_assessed',mapping_count:2}],
            placements:[row(1,{placement_id:101}),row(2,{placement_id:102})],mappings:[],limitations:{}};
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
        if(form?.fields)for(const [key,value] of Object.entries(form.fields))this.set(key,value);
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
const historyEntries=[], windowEvents={}, toasts=[];
ui.showToast=(message,type)=>toasts.push({message,type});
const originalFetch=ui.fetchJson;
ui.fetchJson=async (endpoint,params)=>{ if(options.fetch) {const result=await options.fetch(endpoint,params);if(result!==undefined)return result;} return originalFetch(endpoint,params); };
Object.assign(root.dataset,options.permissions || {});
const context = { window: { CurriculumUI: ui, location: new URL(options.url || 'http://localhost/?program_id=1&curriculum_id=2&version_id=3'), confirm:()=>options.confirm !== false, addEventListener(name,fn) { windowEvents[name]=fn; }, history: {pushState(_a,_b,url) { historyEntries.push(String(url));context.window.location = new URL(url); }} },
    document: {activeElement:null,body:new Element('body'),getElementById:el,createElement:()=>new Element('option'),querySelector(selector){return selector==='.curriculum-modal:not([hidden])'?[...elements.values()].find(e=>e.id.endsWith('Modal')&&!e.hidden):null;},querySelectorAll:()=>[],addEventListener(){},dispatchEvent(){} },
    requestAnimationFrame:fn=>fn(),
    Option: class { constructor(text, value) { this.text = text; this.value = value; } },
    URL, URLSearchParams, FormData: FormDataMock, setTimeout, clearTimeout, console };
if(options.realModals){
    const modalContext={...context,window:{addEventListener(){}},CustomEvent:class{constructor(type,init){this.type=type;this.detail=init?.detail;}}};
    context.document.getElementById=()=>null;
    vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/curriculum-common.js','utf8'),modalContext);
    context.document.getElementById=el;
    ui.createModal=(element,settings)=>{
        element.hidden=true;
        element.modalCloseNodes=['backdrop','icon','footer'].map(name=>el(element.id+'-'+name));
        return modalContext.window.CurriculumUI.createModal(element,settings);
    };
}
vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/subject-mapping-data.js', 'utf8'), context);
vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/subject-mapping-graph.js', 'utf8'), context);
const data = context.window.SubjectMappingData;
assert.equal(data.unitCents(mappings), 520, 'decimal unit totals must be exact');
const badRows = [row(1), row(2, { subject_id: 1 }), row(3, { subject_id: 1, semester: '2nd Semester' }), row(4, { year_level: '  ', semester: null, is_required: null })];
const issues = data.review(badRows);
assert.equal(issues.duplicates.length, 2, 'different semester placements are not duplicates');
assert.equal(issues.missingYear.length, 1); assert.equal(issues.missingSemester.length, 1); assert.equal(issues.placement.length, 1);
assert.equal(data.edges(mappings, [edge, edge, { ...edge, requirement_type: 'corequisite' }, { ...edge, subject_id: 999 }]).length, 1);
vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/outcomes-presentation.js', 'utf8'), context);
vm.runInNewContext(options.source || fs.readFileSync('modules/curriculum/assets/subject-mapping-final.js', 'utf8'), context);
const settle = async () => { for (let i = 0; i < 12; i++) await new Promise(resolve => setImmediate(resolve)); };
const click = (id, selector, dataset) => el(id).handlers.click({ target: { closest: name => name === selector ? { dataset } : null } });
const displayedUnitCents = () => [...el('mappingMap').innerHTML.matchAll(/<\/div><span>([\d.]+) Units<\/span><\/header>/g)]
    .reduce((total, match) => total + Math.round(Number(match[1]) * 100), 0);
return {el, context, calls, posted, selectors, settle, click, historyEntries, windowEvents, toasts, ui, root};
};
