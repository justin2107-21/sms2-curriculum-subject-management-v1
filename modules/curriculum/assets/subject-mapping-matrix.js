(function () {
    'use strict';
    // The only place that knows what a mapped / not mapped mark looks like. A contribution level (I/P/D, L/P/O) would replace the check here.
    function mapCell(mapped) {
        return mapped
            ? '<span class="map-cell map-cell--on" aria-hidden="true"><i class="fa-solid fa-check"></i></span><span class="sr-only">mapped</span>'
            : '<span class="map-cell" aria-hidden="true">–</span><span class="sr-only">not mapped</span>';
    }
    // placement_id -> Set of outcome ids
    function index(mappings) {
        const byPlacement = new Map();
        (mappings || []).forEach(m => {
            const key = Number(m.placement_id);
            if (!byPlacement.has(key)) byPlacement.set(key, new Set());
            byPlacement.get(key).add(Number(m.outcome_id));
        });
        return byPlacement;
    }
    function isMapped(idx, row) { return (idx.get(Number(row.placement_id)) || new Set()).size > 0; }
    // Counts come from the visible rows, so the footer always matches the matrix.
    function counts(rows, outcomes, idx) {
        return outcomes.map(o => rows.filter(r => (idx.get(Number(r.placement_id)) || new Set()).has(Number(o.id))).length);
    }
    function render(rows, outcomes, idx, h) {
        const esc = h.esc, cols = outcomes.length + 2;
        const head = '<tr><th class="map-table__code" scope="col">Code</th><th class="map-table__name" scope="col">Subject name</th>' + outcomes.map(o =>
            '<th scope="col" class="map-table__outcome" tabindex="0" title="' + esc(o.outcome_text) + '" data-tip="' + esc(o.outcome_text) + '">' +
            '<span aria-hidden="true">' + esc(o.outcome_code) + '</span><span class="sr-only">' + esc(o.outcome_code + ': ' + o.outcome_text) + '</span></th>').join('') + '</tr>';
        let body = '';
        h.group(rows).years.forEach((semesters, year) => semesters.forEach((term, semester) => {
            body += '<tr class="map-table__term"><th colspan="' + cols + '" scope="colgroup"><span>' + esc(h.yearLabel(year) || 'Year level not specified') + ' · ' + esc(semester || 'Semester not specified') +
                ' · ' + term.rows.length + (term.rows.length === 1 ? ' subject' : ' subjects') + '</span></th></tr>';
            term.rows.forEach(r => {
                const set = idx.get(Number(r.placement_id)) || new Set();
                body += '<tr><td class="map-table__code">' + esc(r.subject_code).replaceAll('/', '/<wbr>') + '</td><td class="map-table__name">' + esc(r.subject_title) + '</td>' +
                    outcomes.map(o => '<td class="map-table__cell">' + mapCell(set.has(Number(o.id))) + '</td>').join('') + '</tr>';
            });
        }));
        const foot = '<tr><th colspan="2" scope="row">Subjects mapped</th>' + counts(rows, outcomes, idx).map(n => '<td class="map-table__cell">' + n + '</td>').join('') + '</tr>';
        return '<div class="map-scroll"><table class="map-table"><thead>' + head + '</thead><tbody>' + body + '</tbody><tfoot>' + foot + '</tfoot></table></div>' +
            '<p class="map-legend"><span class="map-cell map-cell--on" aria-hidden="true"><i class="fa-solid fa-check"></i></span> mapped · <span class="map-cell" aria-hidden="true">–</span> not mapped. Hover or focus a column heading for the full outcome. Mapping does not establish attainment, compliance or institutional approval.</p>';
    }
    window.SubjectMappingMatrix = { mapCell, index, isMapped, counts, render };
}());
