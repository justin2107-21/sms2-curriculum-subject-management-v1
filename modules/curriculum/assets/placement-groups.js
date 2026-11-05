(function () {
    'use strict';
    const compare = new Intl.Collator('en', { numeric: true, sensitivity: 'base' }).compare;
    // Display label only: "3rd Yr", "3rd", "Year 3" and "3" read as "3rd Year". Anything else (Grade 11, Summer Bridge) is kept as stored.
    function yearLabel(raw) {
        const text = String(raw ?? '').trim();
        const match = /^(?:(\d+)(?:st|nd|rd|th)?(?:\s*(?:yr|year))?|(?:year|yr)\s*(\d+))$/i.exec(text);
        const n = match ? Number(match[1] || match[2]) : 0;
        if (!n || n > 9) return text;
        const suffix = n % 100 >= 11 && n % 100 <= 13 ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' }[n % 10] || 'th');
        return n + suffix + ' Year';
    }
    function group(rows) {
        const years = new Map();
        let cents = 0;
        const sorted = rows.slice().sort((a, b) =>
            compare(a.year_level || '', b.year_level || '') ||
            compare(a.semester || '', b.semester || '') ||
            compare(a.subject_code, b.subject_code) || Number(a.id) - Number(b.id));
        sorted.forEach(row => {
            const yearKey = row.year_level || '';
            const semesterKey = row.semester || '';
            if (!years.has(yearKey)) years.set(yearKey, new Map());
            const semesters = years.get(yearKey);
            if (!semesters.has(semesterKey)) semesters.set(semesterKey, { rows: [], cents: 0 });
            const semester = semesters.get(semesterKey);
            semester.rows.push(row);
            const value = Math.round(Number(row.units) * 100);
            semester.cents += value;
            cents += value;
        });
        return { years, count: rows.length, cents };
    }
    async function load(fetchPage, isCurrent) {
        let page = 1;
        let total = null;
        const rows = [];
        const seen = new Set();
        while (true) {
            const payload = await fetchPage(page);
            if (!isCurrent()) return null;
            const meta = payload.pagination;
            if (total === null) total = Number(meta.total_records);
            if (total !== Number(meta.total_records) || Number(meta.page) !== page) {
                throw new Error('Placements changed while loading. Please select the version again.');
            }
            for (const row of payload.assignments) {
                if (seen.has(String(row.id))) throw new Error('Placements changed while loading. Please reload the version.');
                seen.add(String(row.id));
                rows.push(row);
            }
            if (page >= Number(meta.total_pages)) break;
            page++;
        }
        if (rows.length !== total) throw new Error('The complete placement list could not be loaded. Please reload the version.');
        return rows;
    }
    function render(rows, ui, canRemove) {
        const data = group(rows);
        const esc = ui.escapeHtml;
        const units = cents => esc(ui.formatUnits(cents / 100));
        let html = '';
        data.years.forEach((semesters, year) => {
            html += '<details class="placement-year" open><summary>' + esc(year || 'Year level not specified') + '</summary><div class="placement-year-body">';
            semesters.forEach((semester, label) => {
                html += '<details class="placement-semester" open><summary><span>' + esc(label || 'Semester not specified') +
                    '</span><span class="placement-group-total">' + semester.rows.length + ' Subjects / ' +
                    units(semester.cents) + ' Units</span></summary><div class="table-scroll"><table class="domain-table placement-table">' +
                    '<thead><tr><th>Subject</th><th>Units</th><th>Placement</th>' + (canRemove ? '<th>Actions</th>' : '') + '</tr></thead><tbody>';
                semester.rows.forEach(row => {
                    const required = Number(row.is_required) === 1;
                    html += '<tr><td><span class="record-code">' + esc(row.subject_code) + '</span><br><span class="record-name">' +
                        esc(row.subject_title) + '</span></td><td>' + esc(ui.formatUnits(row.units)) +
                        '</td><td><span class="placement-indicator ' + (required ? 'placement-required' : 'placement-optional') + '">' +
                        (required ? 'Required' : 'Optional') + '</span></td>';
                    if (canRemove) html += '<td class="row-actions"><button type="button" class="row-action-button destructive-action" data-remove-assignment="' +
                        Number(row.id) + '" data-subject-label="' + esc(row.subject_code + ' — ' + row.subject_title) +
                        '"><i class="fa-solid fa-link-slash" aria-hidden="true"></i> Remove</button></td>';
                    html += '</tr>';
                });
                html += '</tbody></table></div></details>';
            });
            html += '</div></details>';
        });
        return { html, summary: data.count + ' Subjects / ' + ui.formatUnits(data.cents / 100) + ' Units' };
    }
    window.CurriculumPlacementView = { group, load, render, yearLabel };
}());
