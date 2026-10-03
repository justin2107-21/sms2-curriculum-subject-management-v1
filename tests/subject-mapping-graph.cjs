'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = { window: {} };
for (const file of ['subject-mapping-data.js', 'subject-mapping-graph.js']) {
    vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/' + file, 'utf8'), context);
}
const data = context.window.SubjectMappingData, graph = context.window.SubjectMappingGraph;
const row = (id, year = '1st Year', term = '1st Semester', changes = {}) => ({ id, subject_id: id,
    curriculum_version_id: 7, subject_code: 'CC10' + id, subject_title: 'Subject ' + id,
    year_level: year, semester: term, units: 3, ...changes });
const edge = (from, to, changes = {}) => ({ required_subject_id: from, subject_id: to,
    curriculum_version_id: 7, requirement_type: 'prerequisite', ...changes });
const rows = [row(1), row(2, '1st Year', '2nd Semester'), row(3, '2nd Year'), row(4, '3rd Year'), row(5, '4th Year'), row(6)];
function draw(rows, rules, options) { return graph.layout(rows, data.edges(rows, rules), options); }
function validateGeometry(result) {
    for (const node of result.nodes) {
        assert.equal(node.year, node.row.year_level);
        assert.equal(node.term, node.row.semester);
        assert.ok(node.x >= 0 && node.y >= 0 && node.x + node.width <= result.width && node.y + node.height <= result.height);
        for (const other of result.nodes) {
            if (node === other) continue;
            assert.ok(node.x + node.width <= other.x || other.x + other.width <= node.x ||
                node.y + node.height <= other.y || other.y + other.height <= node.y, 'Subject cards must never overlap');
        }
    }
    for (const link of result.links) {
        assert.equal(link.source.row.subject_id, link.edge.required_subject_id, 'Arrow must start at the prerequisite');
        assert.equal(link.target.row.subject_id, link.edge.subject_id, 'Arrow must end at the dependent');
        assert.equal(link.points[0][0], link.source.x + link.source.width);
        assert.equal(link.points.at(-1)[0], link.target.x);
        for (let i = 1; i < link.points.length; i++) {
            const [ax, ay] = link.points[i - 1], [bx, by] = link.points[i];
            assert.ok(ax === bx || ay === by, 'Connectors must follow gutters and row gaps');
            for (const node of result.nodes) {
                const crosses = ax === bx
                    ? ax > node.x && ax < node.x + node.width && Math.max(ay, by) > node.y && Math.min(ay, by) < node.y + node.height
                    : ay > node.y && ay < node.y + node.height && Math.max(ax, bx) > node.x && Math.min(ax, bx) < node.x + node.width;
                assert.equal(crosses, false, 'Connector must not cross any card interior');
            }
        }
    }
}
// Sequential identifiers and similar titles do not create edges, even when sorted.
const unconfigured = draw(rows, []);
assert.equal(unconfigured.nodes.length, rows.length); assert.equal(unconfigured.links.length, 0);
assert.equal(draw([row(1, '1st Year', '1st Semester', { subject_code: 'ITE1' }),
    row(2, '1st Year', '1st Semester', { subject_code: 'ITE2' })], []).links.length, 0);
const single = draw(rows, [edge(2, 3)]);
assert.equal(single.links.length, 1); validateGeometry(single);
const chain = [edge(1, 2), edge(2, 3), edge(3, 4)];
assert.equal(draw(rows, chain).links.length, 3);
const multiple = draw(rows, [edge(1, 3), edge(2, 3)]);
assert.equal(multiple.links.filter(link => link.target.row.subject_id === 3).length, 2);
assert.notEqual(multiple.links[0].points.at(-1)[1], multiple.links[1].points.at(-1)[1], 'Incoming edges use distinct ports');
const fanout = draw(rows, [edge(1, 2), edge(1, 3)]);
assert.equal(fanout.links.filter(link => link.source.row.subject_id === 1).length, 2);
const backward = draw(rows, [edge(5, 1)]);
assert.equal(backward.links[0].sequencingIssue, true); validateGeometry(backward);
const sameTerm = draw(rows, [edge(1, 6), edge(6, 1), edge(1, 1)]);
validateGeometry(sameTerm);
for (const fixture of [unconfigured, draw(rows, chain), multiple, fanout]) validateGeometry(fixture);
assert.equal(data.edges(rows, [edge(1, 2), edge(1, 2), edge(1, 2, { requirement_type: 'corequisite' }),
    edge(1, 999), edge(1, 3, { curriculum_version_id: 99 })]).length, 1, 'Only unique mapped prerequisites of this version can appear');
const related = graph.emphasis(rows, chain, 2, '', true);
assert.deepEqual([...related.related].sort(), [1, 2, 3]); assert.equal(related.activeEdges.size, 2);
assert.ok(!related.related.has(4), 'Selection highlights direct relationships');
assert.equal(graph.emphasis(rows, chain, null, '', true).dim, false);
const searched = graph.emphasis(rows, chain, null, 'subject 3', false);
assert.deepEqual([...searched.matches], [3]); assert.equal(rows.length, 6, 'Search preserves all graph nodes');
assert.equal(graph.emphasis(rows, chain, null, 'no match', false).matches.size, 0);
const filtered = graph.visibleRows(rows, chain, '2nd Year');
assert.deepEqual(filtered.map(row => row.subject_id), [1, 2, 3, 4], 'Year filter preserves complete stored chains');
assert.equal(graph.visibleRows(rows, [], '2nd Year').length, 1);
const ordered = draw([row(1, undefined, undefined, { sequence_order: 2, subject_code: 'A' }),
    row(2, undefined, undefined, { sequence_order: 1, subject_code: 'Z' })], []);
assert.equal(ordered.nodes[0].row.subject_code, 'Z', 'Stored placement order takes precedence over code');
const shs = draw([row(1, 'Grade 11'), row(2, 'Grade 12', '2nd Semester')], [edge(1, 2)], { years: ['Grade 11', 'Grade 12'] });
assert.deepEqual(Array.from(shs.years, lane => lane.year), ['Grade 11', 'Grade 12']);
assert.ok(shs.years.every(lane => lane.terms.length === 2)); validateGeometry(shs);
const duplicatePlacements = draw([row(1), row(2), row(8, '2nd Year', '2nd Semester', { subject_id: 1 })], [edge(1, 2)]);
assert.equal(duplicatePlacements.nodes.length, 3); assert.equal(duplicatePlacements.links.length, 2); validateGeometry(duplicatePlacements);
const largeRows = Array.from({ length: 120 }, (_, i) => row(i + 1, (Math.floor(i / 30) + 1) + 'th Year', i % 2 ? '2nd Semester' : '1st Semester'));
const largeRules = Array.from({ length: 119 }, (_, i) => edge(i + 1, i + 2));
largeRules.push(edge(120, 1), edge(1, 120), edge(2, 120));
const large = draw(largeRows, largeRules);
assert.equal(large.nodes.length, 120); assert.equal(large.links.length, 122); validateGeometry(large);
const scale = graph.fit(large.width, large.height, 1000, 600);
assert.ok(large.width * scale <= 976 && large.height * scale <= 576, 'Fit respects both viewport dimensions');
assert.equal(graph.fit(100, 100, 1000, 600), 1, 'Fit does not enlarge cards');
console.log('PASS: no code-derived edges, single/chain/multiple/fanout rules, cross-year and backward arrows, exact ports, zero card intersections, actual placement/order, year context, direct highlight/search, SHS, duplicate placements, and 120-node/122-edge fit.');
