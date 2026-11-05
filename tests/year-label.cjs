'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const sandbox = { window: {}, Intl, Map, Set };
vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/placement-groups.js', 'utf8'), sandbox);
const label = sandbox.window.CurriculumPlacementView.yearLabel;
for (const [raw, want] of [['3rd Yr', '3rd Year'], ['3rd yr', '3rd Year'], ['3rd', '3rd Year'], ['3', '3rd Year'], ['Year 3', '3rd Year'], ['1st Year', '1st Year'],
    ['2nd yr', '2nd Year'], ['4th Year', '4th Year'], ['11', '11'], ['Grade 11', 'Grade 11'], ['Summer Bridge', 'Summer Bridge'], ['', ''], [null, '']]) {
    assert.equal(label(raw), want, String(raw));
}
console.log('PASS: year label helper (any version, unknown values kept as stored)');
