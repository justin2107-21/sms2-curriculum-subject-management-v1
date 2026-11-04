'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
    constructor(id = '') {
        this.id = id; this.value = ''; this.disabled = false; this.hidden = false;
        this.placeholder = ''; this.handlers = {}; this.attributes = {}; this.children = []; this.dataset = {};
        this.classes = new Set();
        this.classList = {
            add: name => this.classes.add(name),
            remove: name => this.classes.delete(name),
            toggle: (name, force) => force ? this.classes.add(name) : this.classes.delete(name)
        };
        this.parentElement = { querySelector: () => null, contains: () => true };
    }
    addEventListener(name, callback) { this.handlers[name] = callback; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    removeAttribute(name) { delete this.attributes[name]; }
    setCustomValidity(value) { this.validityMessage = value; }
    replaceChildren(...children) { this.children = children; }
    appendChild(child) { this.children.push(child); }
    querySelectorAll() { return this.children; }
    closest() { return null; }
    scrollIntoView() {}
    focus() { document.activeElement = this; this.handlers.focus?.(); }
}

const documentHandlers = {};
const document = { dispatchEvent(event) { (documentHandlers[event.type] || []).forEach(fn=>fn(event)); return true; }, createElement: () => new Element(), addEventListener(name, handler) { (documentHandlers[name] ||= []).push(handler); }, getElementById: () => null, querySelectorAll: () => [] };
const context = { window: { innerWidth: 1280, addEventListener() {}, location: { href: 'http://localhost/' } }, document, CustomEvent: class {constructor(type,init){this.type=type;this.detail=init?.detail;}}, setTimeout, clearTimeout, Intl, Map, Set, console, URL, fetch };
vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/curriculum-common.js', 'utf8'), context);
const ui = context.window.CurriculumUI;
const levels = ['program', 'curriculum', 'version'].map(name => ({
    input: new Element(name + 'Input'), valueInput: new Element(name + 'Id'), list: new Element(name + 'Options'),
    placeholder: 'Search ' + name, disabledPlaceholder: 'Choose previous item first',
    search: async () => [], getId: item => item.id, format: item => ({ label: item.label })
}));
const changes = [];
const cascade = ui.createCascadingAutocomplete({ levels, onChange: (index, item, id, action) => changes.push([index, id, action]) });
const [program, curriculum, version] = cascade.selectors;

assert.equal(levels[0].input.disabled, false);
assert.equal(levels[1].input.disabled, true);
assert.equal(levels[2].input.disabled, true);
program.setSelected({ id: 11, label: 'STEM' });
assert.equal(levels[1].input.disabled, false);
assert.equal(levels[2].input.disabled, true);
curriculum.setSelected({ id: 22, label: 'STEM Demo' });
assert.equal(levels[2].input.disabled, false);
version.setSelected({ id: 33, label: '2026' });
assert.equal(cascade.hasSelection(), true);
program.setSelected({ id: 44, label: 'BSIT' });
assert.equal(curriculum.getSelectedId(), '');
assert.equal(version.getSelectedId(), '');
assert.equal(levels[1].input.disabled, false);
assert.equal(levels[2].input.disabled, true);
program.clear();
assert.equal(levels[1].input.disabled, true);
assert.equal(levels[2].input.disabled, true);
assert.equal(cascade.hasSelection(), false);
assert.deepEqual(changes.map(change => change[2]), ['select', 'select', 'select', 'select', 'clear']);

const css = fs.readFileSync('modules/curriculum/assets/curriculum.css', 'utf8');
assert.match(css, /\.record-autocomplete-options\s*\{[^}]*max-height:\s*146px/s);
assert.match(css, /\.record-autocomplete-options\s*\{\s*max-height:\s*146px;/s);
for (const file of [
    'modules/curriculum/assets/subjects-redesign.js',
    'modules/curriculum/assets/subject-mapping-final.js',
    'modules/curriculum/assets/curriculum-export.js'
]) assert.match(fs.readFileSync(file, 'utf8'), /createCascadingAutocomplete/);
const subjects = fs.readFileSync('modules/curriculum/assets/subjects-redesign.js', 'utf8');
assert.match(subjects, /Grade 11.*Grade 12/);
assert.match(subjects, /1st Year.*2nd Year.*3rd Year.*4th Year/);
assert.match(subjects, /1st Semester.*2nd Semester/);
assert.match(subjects, /function programIsShs\(item\)[\s\S]*?item\?\.program_type/, 'program type detection must accept a cleared/null program selection');
const subjectsPage = fs.readFileSync('modules/curriculum/pages/subjects.php', 'utf8');
assert.match(subjectsPage, /hash_file\('sha256'.*curriculum-common\.js/);
assert.match(subjectsPage, /hash_file\('sha256'.*subjects-redesign\.js/);
assert.match(subjects, /root\.addEventListener\('click',[\s\S]*?closest\('#newSubjectButton'\)[\s\S]*?openCreateSubject\(\)/);
assert.ok(subjects.indexOf("root.addEventListener('click'") < subjects.indexOf('const assignmentCascade ='), 'New Subject click handler must be installed before cascade initialization');
assert.match(subjects, /function openCreateSubject\(\)[\s\S]*?subjectModal\.open\(\)[\s\S]*?resetSubjectForm\(\)/);
console.log('PASS: cascade dependencies, stale-ID clearing, null-safe program reset, three-row dropdown cap, and early New Subject click binding');

(async () => {
    const searches = [];
    const suggestionLevels = ['program', 'curriculum', 'version'].map((name, index) => {
        const input = new Element(name + 'SuggestInput'), list = new Element(name + 'SuggestOptions');
        input.parentElement = { querySelector: () => null, contains: target => target === input || target === list };
        return {
            input, list, valueInput: new Element(name + 'SuggestId'), minimumLength: 0,
            autoSelectSingle: false, // this scenario is about the dropdown opening; auto-pick is tested below
            showSuggestionsOnEnable: index > 0, showSuggestionsOnFocus: true, focusOnEnable: true,
            search: async query => { searches.push([name, query]); return [{ id: index + 1, label: name }]; },
            getId: item => item.id, format: item => ({ label: item.label })
        };
    });
    const suggestions = ui.createCascadingAutocomplete({ levels: suggestionLevels }).selectors;
    const settle = () => new Promise(resolve => setTimeout(resolve, 10));
    const outsideClick = target => documentHandlers.click.forEach(handler => handler({ target }));
    suggestions[0].setSelected({ id: 1, label: 'program' });
    outsideClick(suggestionLevels[0].input);
    await settle();
    assert.equal(suggestionLevels[1].list.hidden, false, 'curriculum suggestions must survive the program selection click');
    assert.equal(document.activeElement, suggestionLevels[1].input);
    assert.deepEqual(searches, [['curriculum', '']], 'enabling curriculum must fetch its available options once');
    suggestions[1].setSelected({ id: 2, label: 'curriculum' });
    outsideClick(suggestionLevels[1].input);
    await settle();
    assert.equal(suggestionLevels[2].list.hidden, false, 'version suggestions must survive the curriculum selection click');
    assert.deepEqual(searches[1], ['version', '']);
    outsideClick(new Element('elsewhere'));
    assert.equal(suggestionLevels[2].list.hidden, true);
    suggestionLevels[2].input.focus();
    await settle();
    assert.equal(suggestionLevels[2].list.hidden, false, 'focusing an unselected version should reopen available suggestions');
    suggestions[0].setSelected({ id: 10, label: 'changed' });
    suggestions[0].clear();
    await settle();
    assert.equal(suggestionLevels[1].input.disabled, true);
    assert.equal(suggestionLevels[1].list.hidden, true, 'a deferred suggestion must not reopen after its parent is cleared');
    console.log('PASS: automatic curriculum/version suggestions survive click bubbling, reopen on focus, and cancel on parent reset');
    // Auto-pick (UI_Decisions D4): a dependent level with exactly one option, browsed with an empty search, is
    // selected for the user; two options, typed text, or autoSelectSingle:false are never auto-picked.
    const makeCascade = (counts, extra = {}) => {
        const levels = ['program', 'curriculum', 'version'].map((name, index) => {
            const input = new Element(name + 'AutoInput'), list = new Element(name + 'AutoOptions');
            input.parentElement = { querySelector: () => null, contains: target => target === input || target === list };
            return {
                input, list, valueInput: new Element(name + 'AutoId'), minimumLength: 0,
                showSuggestionsOnEnable: index > 0, showSuggestionsOnFocus: true, focusOnEnable: true,
                search: async () => Array.from({ length: counts[index] }, (_, i) => ({ id: index * 10 + i + 1, label: name + i })),
                getId: item => item.id, format: item => ({ label: item.label })
            };
        });
        return { levels, cascade: ui.createCascadingAutocomplete({ levels, ...extra }) };
    };
    const single = makeCascade([2, 1, 1]);
    single.cascade.selectors[0].setSelected({ id: 1, label: 'program0' });
    await settle(); await settle(); await settle();
    assert.equal(single.cascade.selectors[1].getSelectedId(), '11', 'a single curriculum option is picked automatically');
    assert.equal(single.cascade.selectors[2].getSelectedId(), '21', 'a single version option is picked automatically after it');
    assert.equal(single.cascade.hasSelection(), true);
    const two = makeCascade([2, 2, 1]);
    two.cascade.selectors[0].setSelected({ id: 1, label: 'program0' });
    await settle(); await settle();
    assert.equal(two.cascade.selectors[1].getSelectedId(), '', 'two curriculum options are never picked for the user');
    assert.equal(two.levels[1].list.hidden, false, 'the two options are listed instead');
    const optOut = makeCascade([2, 1, 1], { autoSelectSingle: false });
    optOut.cascade.selectors[0].setSelected({ id: 1, label: 'program0' });
    await settle(); await settle();
    assert.equal(optOut.cascade.selectors[1].getSelectedId(), '', 'autoSelectSingle:false is honoured');
    const typedInput = new Element('typedAuto');
    const typed = ui.createAutocomplete({
        input: typedInput, valueInput: new Element('typedAutoId'), list: new Element('typedAutoList'), minimumLength: 0,
        autoSelectSingle: true, search: async () => [{ id: 7, label: 'only' }], getId: item => item.id, format: item => ({ label: item.label })
    });
    typedInput.value = 'on'; typed.refresh(); await settle();
    assert.equal(typed.getSelectedId(), '', 'typed searches are never auto-picked');
    typedInput.value = ''; typed.refresh(); await settle();
    assert.equal(typed.getSelectedId(), '7', 'an empty search with one option is auto-picked');
    console.log('PASS: single dependent options are auto-picked (D4); two options, typed text and autoSelectSingle:false are not');
    const rankedInput = new Element('rankedInput'), rankedList = new Element('rankedList');
    const candidates = [
        {id:1,subject_code:'BPM101',subject_title:'Business Process Management in IT'},
        {id:2,subject_code:'UNRELATED',subject_title:'Plain title',description:'GE'},
        ...Array.from({length:7},(_,i)=>({id:i+3,subject_code:'GE'+(i+1),subject_title:'General subject'}))
    ];
    const ranked = ui.createAutocomplete({input:rankedInput,valueInput:new Element('rankedId'),list:rankedList,
        search:async()=>candidates,format:item=>({code:item.subject_code,title:item.subject_title,label:item.subject_code})});
    rankedInput.value=' Ge ';ranked.refresh();await settle();
    assert.equal(rankedList.children.length,5,'Subject suggestions capped after ranking');
    assert.equal(rankedList.children[0].children[0].textContent,'GE1','Prefix code outranks contains title');
    rankedInput.value='ZZZZ';ranked.refresh();await settle();
    assert.equal(rankedList.children[0].textContent,'No matching records found.','Never fill empty results with unrelated records');
    const programInput=new Element('programRank'),programList=new Element('programRankOptions');
    const programSearch=ui.createAutocomplete({input:programInput,valueInput:new Element('programRankId'),list:programList,
        search:async()=>Array.from({length:8},(_,i)=>({id:i+1,program_code:'IT'+i,program_name:'Computing'})),
        format:item=>({code:item.program_code,title:item.program_name,label:item.program_code})});
    programInput.value='IT';programSearch.refresh();await settle();
    assert.equal(programList.children.length,3,'Standalone context selectors also capped at three');
    console.log('PASS: autocomplete excludes nonmatches/hidden descriptions, ranks before capping, and returns genuine empty states.');
})().catch(error => { console.error(error); process.exitCode = 1; });
