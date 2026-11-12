(function () {
    'use strict';

    // Subject Equivalency Tool: one page. The records table is the main view; one dialog records, edits or shows a
    // decision with the stored evidence of both subjects side by side. Decisions are human-only; CIMF only explains.
    const workspace = document.getElementById('subjectEquivalencyWorkspace');
    const ui = window.CurriculumUI;
    if (!workspace || !ui) return;

    const $ = id => document.getElementById(id);
    const esc = ui.escapeHtml;
    const endpoint = workspace.dataset.equivalencyApi;
    const canManage = workspace.dataset.canManage === '1';
    const REASON_MIN = Number(workspace.dataset.reasonMin) || 10;
    const REASON_MAX = Number(workspace.dataset.reasonMax) || 4000;
    const PREREQUISITE_LIMIT = 5;
    const DECISIONS = {
        equivalent: ['Equivalent', 'csms-chip--approved'],
        not_equivalent: ['Not equivalent', 'csms-chip--returned'],
        needs_review: ['Needs review', 'csms-chip--under_review']
    };
    const state = { records: new Map(), page: 1, request: 0, compareRequest: 0, loaded: false,
        mode: 'create', record: null, comparison: null, archiveId: null };

    const dialogElement = $('equivalencyDialog');
    const dialog = ui.createModal(dialogElement, { restoreFocus: () => $('equivalencySearch') });
    const archiveDialog = ui.createModal($('equivalencyArchiveDialog'));
    const form = $('equivalencyForm');
    const decisionFields = $('equivalencyDecisionFields');
    const decisionSelect = $('equivalencyDecision');
    const reasonInput = $('equivalencyReason');
    const saveButton = $('equivalencySave');

    // ---------- shared formatting ----------
    function decisionChip(record) {
        if (record.archived_at) return '<span class="csms-chip csms-chip--archived">Archived</span>';
        const [label, css] = DECISIONS[record.decision_status] || ['Needs review', 'csms-chip--under_review'];
        return '<span class="csms-chip ' + css + '">' + esc(label) + '</span>';
    }
    function decisionLabel(status) { return (DECISIONS[status] || ['Needs review'])[0]; }
    function reviewerName(record) {
        return [record.reviewer_first_name, record.reviewer_last_name].filter(Boolean).join(' ').trim() || record.reviewer_username || 'Not recorded';
    }
    function dateTime(value) {
        if (!value) return 'Not recorded';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return String(value);
        return new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(date);
    }
    const notRecorded = '<span class="csms-sub">Not recorded</span>';
    function subjectCell(code, title) {
        return '<strong class="csms-ctable__code">' + esc(code) + '</strong><span class="csms-sub">' + esc(title) + '</span>';
    }
    function pairLabel(record) { return record.subject_a_code + ' and ' + record.subject_b_code; }

    // ---------- records table ----------
    function showListState(which, message) {
        $('equivalencyLoading').hidden = which !== 'loading';
        $('equivalencyEmpty').hidden = which !== 'empty';
        $('equivalencyError').hidden = which !== 'error';
        $('equivalencyList').hidden = !['list', 'nomatch'].includes(which);
        $('equivalencyNoMatch').hidden = which !== 'nomatch';
        $('equivalencyTableWrap').hidden = which !== 'list';
        $('equivalencyPagination').hidden = which !== 'list';
        if (which === 'error') $('equivalencyErrorText').textContent = message || '';
    }
    function filtersActive() { return Boolean($('equivalencySearch').value.trim() || $('equivalencyStatus').value); }

    function recordRow(record) {
        const id = Number(record.id);
        const reason = String(record.remarks || '').trim();
        return '<tr class="csms-row-click" tabindex="0" data-row="' + id + '" aria-haspopup="dialog" aria-label="View the decision for ' + esc(pairLabel(record)) + '">' +
            '<td>' + subjectCell(record.subject_a_code, record.subject_a_title) + '</td>' +
            '<td>' + subjectCell(record.subject_b_code, record.subject_b_title) + '</td>' +
            '<td>' + decisionChip(record) + (record.archived_at ? '<span class="csms-sub">' + esc(decisionLabel(record.decision_status)) + '</span>' : '') + '</td>' +
            '<td>' + (reason ? '<span class="equivalency-reason" title="' + esc(reason) + '">' + esc(reason) + '</span>' : '<span class="csms-sub">No reason recorded</span>') + '</td>' +
            '<td>' + esc(reviewerName(record)) + '</td><td>' + esc(dateTime(record.reviewed_at)) + '</td>' +
            '<td class="csms-ctable__action"><button type="button" class="csms-rowmenu" data-menu="' + id + '" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ' + esc(pairLabel(record)) + '"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button></td></tr>';
    }

    async function loadRecords(page) {
        state.page = page || 1;
        const request = ++state.request;
        if (!state.loaded) showListState('loading');
        try {
            const payload = await ui.fetchJson(endpoint, { action: 'list', page: state.page, per_page: $('equivalencyPerPage').value,
                search: $('equivalencySearch').value.trim(), decision_status: $('equivalencyStatus').value });
            if (request !== state.request) return;
            state.loaded = true;
            state.records.clear();
            const rows = payload.equivalencies || [];
            rows.forEach(record => state.records.set(Number(record.id), record));
            ui.setCount($('equivalencyCount'), Number(payload.pagination?.total_records || 0));
            if (!rows.length) { showListState(filtersActive() ? 'nomatch' : 'empty'); return; }
            $('equivalencyRows').innerHTML = rows.map(recordRow).join('');
            ui.renderPagination($('equivalencyPagination'), payload.pagination, loadRecords);
            showListState('list');
        } catch (error) {
            if (request !== state.request) return;
            showListState('error', error.message);
        }
    }

    // ---------- subject pickers ----------
    const pickers = {};
    function pickerOptions(side) {
        const other = side === 'A' ? 'B' : 'A';
        return {
            input: $('subject' + side + 'Search'), valueInput: $('subject' + side + 'Select'), list: $('subject' + side + 'Options'),
            helpText: 'Choose a Subject Master record.', selectedText: 'Subject selected.', loadingText: 'Searching Subject Master…',
            noResultsText: 'No matching subjects found.', errorText: 'Subject Master records could not be loaded.',
            search: async query => ((await ui.fetchJson(endpoint, { action: 'subject_search', page: 1, per_page: 20, search: query })).subjects || [])
                .filter(subject => String(subject.id) !== String(pickers[other]?.getSelectedId() || '')),
            getId: subject => subject.id,
            format: subject => ({ label: subject.subject_code + ' — ' + subject.subject_title, code: subject.subject_code, title: subject.subject_title }),
            onSelect: pickersChanged, onClear: pickersChanged
        };
    }
    pickers.A = ui.createAutocomplete(pickerOptions('A'));
    pickers.B = ui.createAutocomplete(pickerOptions('B'));
    function setPickersEnabled(enabled) {
        ['A', 'B'].forEach(side => {
            $('subject' + side + 'Search').disabled = !enabled;
            const clear = $('subject' + side + 'Search').parentElement.querySelector('[data-autocomplete-clear]');
            if (clear && !enabled) clear.hidden = true;
        });
    }
    function setPicker(side, id, code, title) {
        pickers[side]?.setSelected({ id, subject_code: code, subject_title: title }, false);
    }
    function pickersChanged() {
        if (state.mode !== 'create') return;
        state.record = null;
        loadComparison();
    }

    // ---------- evidence comparison ----------
    function compareMarker(same) {
        if (same === null) return '<span class="csms-sub">Not comparable</span>';
        return same ? '<span class="csms-chip csms-chip--inactive">Same</span>' : '<span class="csms-chip csms-chip--type">Differs</span>';
    }
    function blank(value) { return value === null || value === undefined || String(value).trim() === ''; }
    function prerequisiteCell(rows) {
        if (!rows.length) return notRecorded;
        const shown = rows.slice(0, PREREQUISITE_LIMIT).map(row => '<li><strong class="csms-ctable__code">' + esc(row.subject_code) + '</strong> ' + esc(row.subject_title) +
            ' <span class="csms-sub">' + esc(row.requirement_type) + ', as placed in ' + esc(row.versions) + '</span></li>').join('');
        const more = rows.length > PREREQUISITE_LIMIT ? '<li class="csms-sub">+' + (rows.length - PREREQUISITE_LIMIT) + ' more</li>' : '';
        return '<ul class="equivalency-list">' + shown + more + '</ul>';
    }
    function prerequisiteKey(rows) { return rows.map(row => row.requirement_type + ':' + row.subject_code).sort().join('|'); }

    function renderComparison(payload) {
        const a = payload.subject_a, b = payload.subject_b;
        $('evidenceHeadA').innerHTML = '<span class="csms-ctable__code">' + esc(a.subject_code) + '</span>';
        $('evidenceHeadB').innerHTML = '<span class="csms-ctable__code">' + esc(b.subject_code) + '</span>';
        const unitFields = ['units', 'lecture_units', 'laboratory_units'];
        const rows = (payload.differences || []).map(diff => {
            const show = value => blank(value) ? notRecorded : esc(unitFields.includes(diff.field) ? ui.formatUnits(value) : (diff.field === 'status' ? String(value).charAt(0).toUpperCase() + String(value).slice(1) : value));
            const cellClass = diff.field === 'subject_code' ? ' class="csms-ctable__code"' : (diff.field === 'description' ? ' class="equivalency-long"' : '');
            const same = diff.result === 'not_available' ? null : diff.result === 'same';
            return '<tr><th scope="row">' + esc(diff.label) + '</th><td' + cellClass + '>' + show(diff.subject_a) + '</td><td' + cellClass + '>' + show(diff.subject_b) + '</td><td>' + compareMarker(same) + '</td></tr>';
        });
        const preA = payload.prerequisites?.subject_a || [], preB = payload.prerequisites?.subject_b || [];
        rows.push('<tr><th scope="row">Prerequisites</th><td>' + prerequisiteCell(preA) + '</td><td>' + prerequisiteCell(preB) + '</td><td>' +
            compareMarker(!preA.length && !preB.length ? null : prerequisiteKey(preA) === prerequisiteKey(preB)) + '</td></tr>');
        const ua = payload.usage?.subject_a?.summary || {}, ub = payload.usage?.subject_b?.summary || {};
        [['placements', 'Placements'], ['programs', 'Programs'], ['curricula', 'Curricula'], ['curriculum_versions', 'Curriculum versions']].forEach(([key, label]) => {
            const x = Number(ua[key] || 0), y = Number(ub[key] || 0);
            rows.push('<tr><th scope="row">' + label + '</th><td class="csms-ctable__num">' + x + '</td><td class="csms-ctable__num">' + y + '</td><td>' + compareMarker(x === y) + '</td></tr>');
        });
        $('comparisonRows').innerHTML = rows.join('');
        const hidden = Number(ua.hidden_placements || 0) + Number(ub.hidden_placements || 0);
        $('comparisonScopeNote').hidden = !(payload.scope_restricted && hidden > 0);
        $('comparisonScopeNote').textContent = hidden + (hidden === 1 ? ' draft or unapproved placement is' : ' draft or unapproved placements are') + ' hidden for your role.';
    }

    function showComparisonState(which) {
        $('comparisonEmpty').hidden = which !== 'empty';
        $('comparisonLoading').hidden = which !== 'loading';
        $('comparisonResult').hidden = which !== 'result';
    }

    async function loadComparison() {
        const a = $('subjectASelect').value, b = $('subjectBSelect').value;
        state.comparison = null;
        $('equivalencyCimf').disabled = true;
        setNotice('');
        updateSave();
        if (!a || !b || a === b) { showComparisonState('empty'); applyMode(); return; }
        const request = ++state.compareRequest;
        showComparisonState('loading');
        try {
            const payload = await ui.fetchJson(endpoint, { action: 'compare', subject_a_id: a, subject_b_id: b });
            if (request !== state.compareRequest) return;
            state.comparison = payload;
            renderComparison(payload);
            showComparisonState('result');
            $('equivalencyCimf').disabled = !window.CSMSCIMF;
            const existing = payload.existing_equivalency;
            if (state.mode === 'create' && existing) {
                // The pair already has a record: open it instead of creating a duplicate.
                state.record = existing;
                state.mode = existing.archived_at || !canManage ? 'view' : 'edit';
                fillDecision(existing);
                setNotice(existing.archived_at
                    ? 'This pair has an archived decision. Restore it to change the decision.'
                    : 'This pair already has a decision (' + decisionLabel(existing.decision_status) + ', ' + dateTime(existing.reviewed_at) + '). You are editing that record.');
            }
            applyMode();
        } catch (error) {
            if (request !== state.compareRequest) return;
            showComparisonState('empty');
            ui.showFormError(form, error.message);
        }
    }

    // ---------- dialog modes ----------
    function setNotice(text) { $('equivalencyNotice').textContent = text; $('equivalencyNotice').hidden = !text; }
    function reasonProblem() {
        const length = reasonInput.value.trim().length;
        if (length < REASON_MIN) return 'Enter a reason of at least ' + REASON_MIN + ' characters.';
        if (length > REASON_MAX) return 'The reason must be at most ' + REASON_MAX + ' characters.';
        return '';
    }
    function showReasonError(message) { $('equivalencyReasonError').textContent = message; $('equivalencyReasonError').hidden = !message; reasonInput.setAttribute('aria-invalid', message ? 'true' : 'false'); }
    function updateCounter() { $('equivalencyReasonCount').textContent = String(reasonInput.value.trim().length); }
    function updateSave() {
        if (!saveButton) return;
        saveButton.disabled = !state.comparison || !['create', 'edit'].includes(state.mode);
    }
    function fillDecision(record) {
        $('equivalencyRecordId').value = record ? record.id : '';
        decisionSelect.value = record && DECISIONS[record.decision_status] ? record.decision_status : '';
        reasonInput.value = record?.remarks || '';
        updateCounter();
        showReasonError('');
    }
    function renderViewDetails(record) {
        const reason = String(record?.remarks || '').trim();
        $('equivalencyViewDetails').innerHTML = record ? [
            ['Decision', decisionChip(record) + (record.archived_at ? ' <span class="csms-sub">' + esc(decisionLabel(record.decision_status)) + ', archived ' + esc(dateTime(record.archived_at)) + '</span>' : '')],
            ['Reason', reason ? esc(reason) : '<span class="csms-sub">No reason recorded</span>'],
            ['Reviewed by', esc(reviewerName(record))],
            ['Reviewed at', esc(dateTime(record.reviewed_at))],
            ['Last updated', esc(dateTime(record.updated_at))]
        ].map(([label, value]) => '<dt>' + esc(label) + '</dt><dd>' + value + '</dd>').join('') : '';
    }
    function applyMode() {
        const mode = state.mode, record = state.record;
        const titles = { create: 'Record decision', edit: 'Edit decision', view: 'Equivalency decision' };
        $('equivalencyDialogTitle').textContent = titles[mode];
        $('equivalencyDialogContext').textContent = record ? record.subject_a_code + ' — ' + record.subject_a_title + ' and ' + record.subject_b_code + ' — ' + record.subject_b_title : 'Mutual equivalency between two Subject Master records.';
        setPickersEnabled(mode === 'create');
        decisionFields.hidden = mode === 'view' || !canManage;
        $('equivalencyViewDetails').hidden = mode !== 'view' || !record;
        if (mode === 'view') renderViewDetails(record);
        $('equivalencyCancel').textContent = mode === 'view' ? 'Close' : 'Cancel';
        if (saveButton) saveButton.hidden = mode === 'view';
        if ($('equivalencyEdit')) $('equivalencyEdit').hidden = !(mode === 'view' && record && !record.archived_at);
        if ($('equivalencyRestore')) $('equivalencyRestore').hidden = !(record && record.archived_at);
        if (mode === 'edit' && record && !String(record.remarks || '').trim()) setNotice('This record has no reason yet. Add one to save the decision.');
        updateSave();
    }

    function openDialog(mode, record) {
        if (mode !== 'view' && !canManage) mode = 'view';
        state.mode = mode;
        state.record = record || null;
        state.comparison = null;
        ui.clearFormError(form);
        setNotice('');
        fillDecision(record || null);
        pickers.A?.clear(false);
        pickers.B?.clear(false);
        if (record) {
            setPicker('A', record.subject_id, record.subject_a_code, record.subject_a_title);
            setPicker('B', record.equivalent_subject_id, record.subject_b_code, record.subject_b_title);
        }
        applyMode();
        showComparisonState('empty');
        dialog.open();
        loadComparison();
    }

    async function submitDecision(event) {
        event.preventDefault();
        if (!canManage || !['create', 'edit'].includes(state.mode)) return;
        ui.clearFormError(form);
        if (!decisionSelect.value) { ui.showFormError(form, 'Select a decision.'); decisionSelect.focus(); return; }
        const problem = reasonProblem();
        showReasonError(problem);
        if (problem) { reasonInput.focus(); return; }
        const data = new FormData();
        const isUpdate = state.mode === 'edit' && state.record;
        data.set('action', isUpdate ? 'update' : 'create');
        if (isUpdate) data.set('id', state.record.id);
        data.set('subject_a_id', $('subjectASelect').value);
        data.set('subject_b_id', $('subjectBSelect').value);
        data.set('decision_status', decisionSelect.value);
        data.set('remarks', reasonInput.value.trim());
        ui.setFormSubmitting(form, true, 'Saving…');
        try {
            const payload = await ui.postForm(endpoint, data);
            dialog.close();
            ui.showToast(payload.message, isUpdate ? 'updated' : 'success');
            await loadRecords(state.page);
        } catch (error) {
            ui.showFormError(form, error.message);
        } finally {
            ui.setFormSubmitting(form, false);
            updateSave();
        }
    }

    async function postAction(action, id) {
        const data = new FormData();
        data.set('action', action);
        data.set('id', id);
        const payload = await ui.postForm(endpoint, data);
        ui.showToast(payload.message, 'success');
        await loadRecords(state.page);
        return payload;
    }
    async function restore(id) {
        try {
            const payload = await postAction('restore', id);
            if (!dialogElement.hidden && payload.equivalency) { state.record = payload.equivalency; state.mode = 'view'; setNotice(''); applyMode(); }
        } catch (error) { ui.showToast(error.message, 'error'); }
    }

    // ---------- CIMF: advice beside the dialog, never written into the reason ----------
    window.CSMSPageCIMFContext = () => ({ subject_a_id: $('subjectASelect').value || '', subject_b_id: $('subjectBSelect').value || '' });
    window.CSMSPageCIMFStoredDecision = () => state.comparison?.existing_equivalency || null;
    const cimfPanel = $('csmsCimfPanel');
    function setCimfBeside(on) { document.body.classList.toggle('equivalency-cimf-beside', Boolean(on)); }
    if (cimfPanel) new MutationObserver(() => { if (cimfPanel.hidden) setCimfBeside(false); }).observe(cimfPanel, { attributes: true, attributeFilter: ['hidden'] });
    $('equivalencyCimf').addEventListener('click', () => {
        if (!window.CSMSCIMF || !state.comparison) return;
        setCimfBeside(true);
        window.CSMSCIMF.updateContext?.();
        window.CSMSCIMF.open('review');
    });
    dialogElement.querySelectorAll('[data-modal-close]').forEach(button => button.addEventListener('click', () => setCimfBeside(false)));
    dialogElement.addEventListener('keydown', event => { if (event.key === 'Escape') setCimfBeside(false); });

    // ---------- events ----------
    form.addEventListener('submit', submitDecision);
    reasonInput.addEventListener('input', () => { updateCounter(); if (!$('equivalencyReasonError').hidden) showReasonError(reasonProblem()); });
    $('equivalencyEdit')?.addEventListener('click', () => { if (!state.record) return; state.mode = 'edit'; fillDecision(state.record); applyMode(); decisionSelect.focus(); });
    $('equivalencyRestore')?.addEventListener('click', () => { if (state.record) restore(state.record.id); });
    document.querySelectorAll('#recordDecisionButton, [data-record-decision]').forEach(button => button.addEventListener('click', () => openDialog('create', null)));
    $('equivalencyArchiveConfirm')?.addEventListener('click', async () => {
        if (!state.archiveId) return;
        try { await postAction('archive', state.archiveId); archiveDialog.close(); }
        catch (error) { ui.showToast(error.message, 'error'); }
        state.archiveId = null;
    });

    const reload = ui.debounce(() => loadRecords(1), 300);
    $('equivalencySearch').addEventListener('input', reload);
    $('equivalencyStatus').addEventListener('change', () => loadRecords(1));
    $('equivalencyPerPage').addEventListener('change', () => loadRecords(1));

    const rowMenu = window.CsmsMenu ? window.CsmsMenu.create($('equivalencyRowMenu'), {
        esc,
        onSelect(action, data) {
            const record = state.records.get(Number(data.record));
            if (!record) return;
            if (action === 'view') openDialog('view', record);
            else if (action === 'edit') openDialog('edit', record);
            else if (action === 'archive') { state.archiveId = Number(record.id); archiveDialog?.open(); }
            else if (action === 'restore') restore(record.id);
        }
    }) : null;
    function menuItems(record) {
        const data = { record: record.id };
        const items = [{ action: 'view', label: 'View', data }];
        if (canManage && !record.archived_at) items.push({ action: 'edit', label: 'Edit decision', data }, { action: 'archive', label: 'Archive', danger: true, data });
        if (canManage && record.archived_at) items.push({ action: 'restore', label: 'Restore', data });
        return items;
    }
    $('equivalencyRows').addEventListener('click', event => {
        const menuButton = event.target.closest('[data-menu]');
        if (menuButton) { const record = state.records.get(Number(menuButton.dataset.menu)); if (record) rowMenu?.open(menuButton, menuItems(record)); return; }
        const row = event.target.closest('[data-row]');
        if (row) openDialog('view', state.records.get(Number(row.dataset.row)));
    });
    $('equivalencyRows').addEventListener('keydown', event => {
        if (event.target.matches?.('[data-row]') && ['Enter', ' '].includes(event.key)) { event.preventDefault(); openDialog('view', state.records.get(Number(event.target.dataset.row))); }
    });

    loadRecords(1);
}());
