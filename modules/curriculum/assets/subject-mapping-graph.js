(function () {
    'use strict';
    const data = window.SubjectMappingData;
    const dimensions = { column: 304, nodeWidth: 208, nodeHeight: 100, row: 132, top: 94, inset: 44 };
    const label = (value, fallback) => data.blank(value) ? fallback : String(value);
    const yearOf = row => label(row.year_level, 'Missing Year / Grade');
    const termOf = row => label(row.semester, 'Missing Semester');
    function placementOrder(a, b) {
        const aOrder = data.blank(a.sequence_order) ? Infinity : Number(a.sequence_order);
        const bOrder = data.blank(b.sequence_order) ? Infinity : Number(b.sequence_order);
        return (aOrder - bOrder) || data.compare(a.subject_code, b.subject_code) || Number(a.id) - Number(b.id);
    }
    function visibleRows(rows, edges, year) {
        if (!year) return rows;
        // Keep the entire stored connected component, including cross-year chains.
        const ids = new Set(rows.filter(row => row.year_level === year).map(row => Number(row.subject_id)));
        const adjacent = new Map();
        edges.forEach(edge => {
            const from = Number(edge.required_subject_id), to = Number(edge.subject_id);
            if (!adjacent.has(from)) adjacent.set(from, new Set());
            if (!adjacent.has(to)) adjacent.set(to, new Set());
            adjacent.get(from).add(to); adjacent.get(to).add(from);
        });
        const queue = [...ids];
        for (let i = 0; i < queue.length; i++) {
            (adjacent.get(queue[i]) || []).forEach(id => { if (!ids.has(id)) { ids.add(id); queue.push(id); } });
        }
        return rows.filter(row => ids.has(Number(row.subject_id)));
    }
    function emphasis(rows, edges, selected, query, highlight) {
        const related = new Set(selected == null ? [] : [Number(selected)]);
        const activeEdges = new Set();
        edges.forEach(edge => {
            if (Number(edge.required_subject_id) === Number(selected) || Number(edge.subject_id) === Number(selected)) {
                related.add(Number(edge.required_subject_id)); related.add(Number(edge.subject_id)); activeEdges.add(edge);
            }
        });
        const search = String(query || '').trim().toLowerCase();
        const matches = new Set(search ? rows.filter(row => (row.subject_code + ' ' + row.subject_title).toLowerCase().includes(search)).map(row => Number(row.subject_id)) : []);
        return { related, activeEdges, matches, search, dim: highlight && selected != null };
    }
    function layout(rows, edges, options = {}) {
        const d = dimensions, nodes = [], years = [], bySubject = new Map();
        let x = 0, height = 300;
        const yearLabels = [...new Set([...(options.years || []), ...rows.map(yearOf)])].sort(data.compare);
        yearLabels.forEach(year => {
            const yearRows = rows.filter(row => yearOf(row) === year);
            const terms = [...new Set(['1st Semester', '2nd Semester', ...yearRows.map(termOf)])].sort(data.compare);
            years.push({ year, x, width: terms.length * d.column, terms });
            terms.forEach((term, column) => {
                yearRows.filter(row => termOf(row) === term).sort(placementOrder).forEach((row, index) => {
                    const node = { row, year, term, x: x + column * d.column + d.inset, y: d.top + index * d.row,
                        width: d.nodeWidth, height: d.nodeHeight, column: x / d.column + column };
                    nodes.push(node);
                    const id = Number(row.subject_id);
                    if (!bySubject.has(id)) bySubject.set(id, []);
                    bySubject.get(id).push(node);
                    height = Math.max(height, node.y + d.row + 12);
                });
            });
            x += terms.length * d.column;
        });
        // Only stored rules can produce paths. Codes are used solely for display ordering.
        const links = [];
        edges.forEach(edge => {
            (bySubject.get(Number(edge.required_subject_id)) || []).forEach(source => {
                (bySubject.get(Number(edge.subject_id)) || []).forEach(target => links.push({ edge, source, target }));
            });
        });
        const incoming = new Map(), outgoing = new Map();
        links.forEach(link => {
            if (!incoming.has(link.target)) incoming.set(link.target, []);
            if (!outgoing.has(link.source)) outgoing.set(link.source, []);
            incoming.get(link.target).push(link); outgoing.get(link.source).push(link);
        });
        links.forEach((link, index) => {
            const { source, target } = link;
            const outputs = outgoing.get(source), inputs = incoming.get(target);
            const sy = source.y + 18 + 64 * (outputs.indexOf(link) + 1) / (outputs.length + 1);
            const ty = target.y + 18 + 64 * (inputs.indexOf(link) + 1) / (inputs.length + 1);
            const sx = source.x + source.width, tx = target.x;
            const track = index % 7;
            const sourceGutter = sx + 12 + track * 3, targetGutter = tx - 12 - track * 3;
            // Horizontal travel stays in row gaps; vertical travel stays in column gutters.
            // This also handles same-term, backward, and self-referencing stored rules.
            const routeY = source.y + source.height + 7 + track * 3;
            link.points = [[sx, sy], [sourceGutter, sy], [sourceGutter, routeY], [targetGutter, routeY], [targetGutter, ty], [tx, ty]];
            // Adjacent columns in the same row can use the unobstructed shared gutter.
            if (target.column === source.column + 1 && source.y === target.y) {
                link.points = sy === ty ? [[sx, sy], [tx, ty]] : [[sx, sy], [sourceGutter, sy], [sourceGutter, ty], [tx, ty]];
            }
            link.path = link.points.map((point, i) => (i ? 'L ' : 'M ') + point.join(' ')).join(' ');
            link.sequencingIssue = source.column > target.column;
        });
        return { nodes, years, links, width: x, height };
    }
    function fit(width, height, viewportWidth, viewportHeight) {
        return Math.max(0.01, Math.min(1, (viewportWidth - 24) / width, (viewportHeight - 24) / height));
    }
    window.SubjectMappingGraph = { dimensions, placementOrder, visibleRows, emphasis, layout, fit };
}());
