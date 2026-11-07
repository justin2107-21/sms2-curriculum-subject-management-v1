(function () {
    'use strict';
    // Shared review component: sections of compact check rows (label, hint, count) that expand to a table of the affected records.
    // The page supplies plain data; this file owns the markup, expand/collapse, focus and the action buttons inside rows.
    //   const review = CsmsReview.create(target, { esc, onAction(action, id) {} });
    //   review.render([{ title, note, checks: [{ key, label, hint, rows, columns: [{ header, code, cell(row) }], actions(row) }] },
    //                  { empty: '<div class="csms-empty">...</div>' }]);
    // cell() and actions() return HTML the page has already escaped. An action is any element with data-review-action and data-review-id.
    function create(target, options) {
        const esc = options.esc;
        const expanded = new Set();
        let sections = [];
        function table(check) {
            const head = check.columns.map(c => '<th' + (c.code ? ' class="csms-ctable__code"' : '') + ' scope="col">' + esc(c.header) + '</th>').join('') +
                (check.actions ? '<th class="csms-ctable__action" scope="col"><span class="sr-only">Action</span></th>' : '');
            const body = check.rows.map(row => '<tr>' + check.columns.map(c => '<td' + (c.code ? ' class="csms-ctable__code"' : '') + '>' + c.cell(row) + '</td>').join('') +
                (check.actions ? '<td class="csms-ctable__action">' + check.actions(row) + '</td>' : '') + '</tr>').join('');
            return '<table class="csms-ctable"><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table>';
        }
        function checkRow(check) {
            const count = check.rows.length, open = expanded.has(check.key) && count > 0;
            return '<div class="csms-check' + (open ? ' is-open' : '') + '"><h3><button type="button" class="csms-check__head" data-review-issue="' + check.key + '" aria-expanded="' + open + '" aria-controls="reviewList-' + check.key + '"' + (count ? '' : ' aria-disabled="true"') + '>' +
                '<i class="fa-solid fa-chevron-right csms-check__chev" aria-hidden="true"></i><span class="csms-check__label">' + esc(check.label) + '<small>' + esc(check.hint) + '</small></span>' +
                '<span class="csms-check__count' + (count ? ' has-issues' : '') + '">' + count + '</span></button></h3>' +
                '<div class="csms-check__list" id="reviewList-' + check.key + '"' + (open ? '' : ' hidden') + '>' + (open ? table(check) : '') + '</div></div>';
        }
        function render(next) {
            if (next) sections = next;
            target.innerHTML = sections.map(section => section.empty ? section.empty :
                '<div class="csms-term csms-checks"><header><strong>' + esc(section.title) + '</strong><span class="csms-term__count">' + esc(section.note || '') + '</span></header>' + section.checks.map(checkRow).join('') + '</div>').join('');
        }
        function total() { return sections.reduce((sum, s) => sum + (s.checks || []).reduce((n, c) => n + c.rows.length, 0), 0); }
        target.addEventListener('click', event => {
            const action = event.target.closest('[data-review-action]');
            if (action) { options.onAction(action.dataset.reviewAction, Number(action.dataset.reviewId)); return; }
            const head = event.target.closest('[data-review-issue]');
            if (!head || head.getAttribute('aria-disabled') === 'true') return;
            const key = head.dataset.reviewIssue;
            if (expanded.has(key)) expanded.delete(key); else expanded.add(key);
            render();
            target.querySelector('[data-review-issue="' + key + '"]')?.focus();
        });
        return { render, total, reset() { expanded.clear(); } };
    }
    window.CsmsReview = { create };
}());
