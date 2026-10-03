'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const sandbox = { window: {}, Intl, Map, Set };
vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/placement-groups.js', 'utf8'), sandbox);
const view = sandbox.window.CurriculumPlacementView;
const source = JSON.parse(fs.readFileSync('database/seeds/bsit_preoral_manifest.json', 'utf8'));
const rows = source.subjects.map((s, i) => ({ ...s, id: i + 1, subject_title: s.subject_name, units: s.total_units, is_required: 1 }));
const ui = {
    escapeHtml: x => String(x ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;'),
    formatUnits: x => x == null ? '—' : String(Number(x))
};
(async () => {
    const groups = view.group(rows.slice().reverse());
    assert.equal(groups.count, 50);
    assert.equal(groups.cents, 15200);
    assert.equal(groups.years.size, 4);
    const actual = [];
    groups.years.forEach((semesters, year) => semesters.forEach((s, term) => actual.push([year, term, s.rows.length, s.cents / 100])));
    assert.deepEqual(actual.map(x => x.slice(2)), [[9, 26], [9, 26], [8, 23], [8, 23], [6, 18], [6, 18], [4, 18]]);
    const rendered = view.render(rows, ui, true);
    assert.equal(rendered.summary, '50 Subjects / 152 Units');
    assert.equal((rendered.html.match(/data-remove-assignment=/g) || []).length, 50);
    assert.equal((rendered.html.match(/class="placement-year"/g) || []).length, 4);
    assert.equal((rendered.html.match(/class="placement-semester"/g) || []).length, 7);
    assert.ok(!rendered.html.includes('<th>Order</th>'));
    assert.ok(!view.render(rows, ui, false).html.includes('data-remove-assignment'));
    assert.ok(view.render([{ ...rows[0], subject_title: '<script>alert(1)</script>' }], ui, true).html.includes('&lt;script&gt;'));
    assert.equal(view.render([], ui, false).summary, '0 Subjects / 0 Units');
    const pages = [rows.slice(0, 20), rows.slice(20, 40), rows.slice(40)];
    const loaded = await view.load(async page => ({
        assignments: pages[page - 1],
        pagination: { page, total_pages: 3, total_records: 50 }
    }), () => true);
    assert.equal(loaded.length, 50);
    assert.equal(await view.load(async () => ({}), () => false), null);
    await assert.rejects(() => view.load(async page => ({
        assignments: [rows[0]], pagination: { page, total_pages: 2, total_records: 2 }
    }), () => true), /changed/);
    await assert.rejects(() => view.load(async page => ({
        assignments: [], pagination: { page, total_pages: 1, total_records: 50 }
    }), () => true), /complete/);
    // Independent of sequence values, within-group subject codes sort ascending.
    const sorted = view.group([{...rows[0],subject_code:'Z',sequence_order:1},{...rows[1],subject_code:'A',sequence_order:99}]);
    assert.equal(sorted.years.values().next().value.values().next().value.rows[0].subject_code, 'A');
    const subjectJs = fs.readFileSync('modules/curriculum/assets/subjects.js','utf8');
    const subjectsPhp = fs.readFileSync('modules/curriculum/pages/subjects.php','utf8');
    for (const field of ['curriculum_version_id','year_level','semester']) assert.ok(subjectJs.includes(field));
    const builderJs = fs.readFileSync('modules/curriculum/assets/curriculum-builder.js','utf8');
    const subjectCreateIndex = subjectJs.indexOf('payload = await ui.postForm(api, subjectData)');
    const placementCreateIndex = subjectJs.indexOf('await ui.postForm(assignmentApi, assignmentData)');
    assert.ok(subjectCreateIndex >= 0 && placementCreateIndex > subjectCreateIndex,
        'Subject Master must be created before its optional placement');
    assert.ok(subjectJs.includes('Subject was created, but curriculum assignment failed.'));
    for (const field of ['subjectLectureUnits', 'subjectLaboratoryUnits', 'assignSubjectToCurriculum',
        'createSubjectOnlyButton', 'createAndAssignSubjectButton', 'subjectAssignmentSequence']) {
        assert.ok(!subjectsPhp.includes(field), 'Subject UI must not expose ' + field);
    }
    for (const field of ['lecture_units', 'laboratory_units']) {
        assert.ok(!subjectJs.includes("['subject_code', 'subject_title', 'description', 'units', '" + field),
            'New Subject must not submit ' + field);
    }
    assert.ok(subjectsPhp.includes('id="subjectAssignmentSection"'),
        'Curriculum Placement must be present in the Subject modal');
    assert.ok(subjectsPhp.includes('id="subjectSubmitButton">Save Changes</button>'));
    assert.equal((builderJs.match(/curriculumDetailModal\?\.open\(\)/g) || []).length, 1,
        'Curriculum Details should have one explicit open call');
    assert.equal((builderJs.match(/assignmentModal\.open\(\)/g) || []).length, 1,
        'Assign Subject should have one explicit open call');
    assert.ok(builderJs.includes('onSelect: function (item, id) { setCurriculumContext(id); }'),
        'Curriculum context selector must not open the details modal');
    assert.ok(builderJs.includes('setVersionContext(created.id, created.version_label, created.status);'),
        'Version creation must restore context without opening subjects');
    assert.ok(builderJs.includes('loadCurriculumWorkspace();'),
        'URL context hydration must use the non-modal restore path');
    assert.ok(builderJs.includes('onSelect: function (item, id) { setCurriculumContext(id); }'));
    console.log('PASS: grouping, all seven totals, sorting, permission-aware actions, escaping, pagination, stale response guards, Units-only Subject UI, create-before-assign sequencing, and explicit-only curriculum/version modal opens (non-visual).');
})().catch(error => { console.error(error); process.exitCode = 1; });
