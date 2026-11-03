(function () {
    'use strict';

    const workspace = document.getElementById('subjectEquivalencyWorkspace');
    const ui = window.CurriculumUI;
    if (!workspace || !ui) return;

    const endpoint = workspace.dataset.equivalencyApi;
    const canManage = workspace.dataset.canManageEquivalency === '1';
    const recordColumnCount = Number(workspace.dataset.recordColumnCount || 6);
    const state = {
        comparisonRequest: 0, recordRequest: 0, recordPage: 1,
        records: new Map(), comparison: null,
    };
    const elements = {
        comparisonPanel: document.getElementById('comparisonPanel'),
        recordsPanel: document.getElementById('recordsPanel'),
        subjectASearch: document.getElementById('subjectASearch'),
        subjectASelect: document.getElementById('subjectASelect'),
        subjectBSearch: document.getElementById('subjectBSearch'),
        subjectBSelect: document.getElementById('subjectBSelect'),
        compareButton: document.getElementById('compareSubjectsButton'),
        headerRecordDecision: document.getElementById('headerRecordDecision'),
        reviewWithCimf: document.getElementById('reviewWithCimf'),
        summary: document.getElementById('equivalencySummary'),
        existingPairMessage: document.getElementById('existingPairMessage'),
        clearComparison: document.getElementById('clearSubjectComparison'),
        comparisonMessage: document.getElementById('comparisonMessage'),
        comparisonRows: document.getElementById('comparisonRows'),
        subjectAHeader: document.getElementById('subjectAHeader'),
        subjectBHeader: document.getElementById('subjectBHeader'),
        subjectAUsageTitle: document.getElementById('subjectAUsageTitle'),
        subjectBUsageTitle: document.getElementById('subjectBUsageTitle'),
        subjectAUsage: document.getElementById('subjectAUsage'),
        subjectBUsage: document.getElementById('subjectBUsage'),
        recordSearch: document.getElementById('equivalencySearch'),
        recordStatus: document.getElementById('equivalencyStatus'),
        recordPerPage: document.getElementById('equivalencyPerPage'),
        clearFilters: document.getElementById('clearEquivalencyFilters'),
        recordRows: document.getElementById('equivalencyRows'),
        recordCount: document.getElementById('equivalencyCount'),
        recordPagination: document.getElementById('equivalencyPagination'),
    };
    const isComparisonView = Boolean(elements.comparisonPanel);
    const reviewForm = document.getElementById('equivalencyReviewForm');
    const reviewModal = ui.createModal(document.getElementById('equivalencyReviewModal'));
    const detailsModal = ui.createModal(document.getElementById('equivalencyDetailsModal'));
    const archiveModal = ui.createModal(document.getElementById('equivalencyArchiveModal'));
    window.CSMSPageCIMFContext = () => ({subject_a_id:state.comparison?.subject_a.id || elements.subjectASelect?.value || '',subject_b_id:state.comparison?.subject_b.id || elements.subjectBSelect?.value || ''});
    window.CSMSPageCIMFStoredDecision = () => state.comparison?.existing_equivalency || null;
    let archiveRecordId = null;
    let subjectAAutocomplete;
    let subjectBAutocomplete;
    function autocompleteOptions(side) {
        const isA = side === 'a';
        const input = isA ? elements.subjectASearch : elements.subjectBSearch;
        const valueInput = isA ? elements.subjectASelect : elements.subjectBSelect;
        const list = document.getElementById(isA ? 'subjectAOptions' : 'subjectBOptions');
        const other = isA ? () => subjectBAutocomplete : () => subjectAAutocomplete;
        return {
            input,
            valueInput,
            list,
            helpText: isA ? 'Choose the first subject to compare.' : 'Choose a different subject to compare.',
            selectedText: 'Subject selected.',
            loadingText: 'Searching Subject Master…',
            noResultsText: 'No matching subjects found.',
            errorText: 'Subject Master records could not be loaded.',
            search: async function (query) {
                const payload = await ui.fetchJson(endpoint, {
                    action: 'subject_search', page: 1, per_page: 20, search: query
                });
                return (payload.subjects || []).filter(function (subject) {
                    return Number(subject.id) !== Number(other()?.getSelectedId() || 0);
                });
            },
            getId: subject => subject.id,
            format: subject => ({ label: subjectLabel(subject), code: subject.subject_code,
                title: subject.subject_title }),
            onError: error => ui.showToast(error.message || 'Subject Master records could not be loaded.', 'error'),
            onSelect: function (subject, id) {
                if (other()?.getSelectedId() === id) other().clear();
                synchronizeSelectors();
            }
        };
    }
    subjectAAutocomplete = ui.createAutocomplete(autocompleteOptions('a'));
    subjectBAutocomplete = ui.createAutocomplete(autocompleteOptions('b'));

    function subjectLabel(subject) {
        return String(subject.subject_code || '') + ' — ' + String(subject.subject_title || '');
    }

    function setTableMessage(rows, columns, message, isError) {
        rows.innerHTML = '<tr><td colspan="' + columns + '" class="empty-cell' +
            (isError ? ' error-cell' : '') + '">' + ui.escapeHtml(message) + '</td></tr>';
    }

    function synchronizeSelectors() {
        updateCompareButton();
        clearRenderedComparison('Select Compare Subjects to review the selected pair.');
    }

    function updateCompareButton() {
        const subjectAId = subjectAAutocomplete?.getSelectedId();
        const subjectBId = subjectBAutocomplete?.getSelectedId();
        elements.compareButton.disabled = !(subjectAId && subjectBId && subjectAId !== subjectBId);
        if (elements.headerRecordDecision) elements.headerRecordDecision.disabled = elements.compareButton.disabled ||
            !state.comparison || Boolean(state.comparison.existing_equivalency?.archived_at);
        if (elements.reviewWithCimf) elements.reviewWithCimf.disabled = elements.compareButton.disabled || !state.comparison;
    }

    function clearRenderedComparison(message) {
        state.comparison = null;
        elements.comparisonMessage.textContent = message || 'Select two subjects to review their stored fields.';
        elements.subjectAHeader.textContent = 'Subject A';
        elements.subjectBHeader.textContent = 'Subject B';
        setTableMessage(elements.comparisonRows, 4, 'No subjects selected for comparison.', false);
        renderUsage(elements.subjectAUsage, null);
        renderUsage(elements.subjectBUsage, null);
        elements.subjectAUsageTitle.textContent = 'Subject A Usage';
        elements.subjectBUsageTitle.textContent = 'Subject B Usage';
        if (elements.summary) elements.summary.hidden = true;
        if (elements.existingPairMessage) elements.existingPairMessage.hidden = true;
        if (elements.headerRecordDecision) elements.headerRecordDecision.disabled = true;
        if (elements.reviewWithCimf) elements.reviewWithCimf.disabled = true;
    }

    function displayValue(field, value) {
        if (value === null || value === undefined || value === '') return 'Not Available';
        if (['units', 'lecture_units', 'laboratory_units'].includes(field)) return ui.formatUnits(value);
        return String(value);
    }

    function resultBadge(result, field) {
        const labels = { same: ['fa-check', 'Same'], different: ['fa-not-equal', 'Different'],
            not_available: ['fa-minus', 'Not Available'] };
        const safe = Object.prototype.hasOwnProperty.call(labels, result) ? result : 'not_available';
        const label = safe === 'same' && field === 'units' ? 'Same Unit Value' : labels[safe][1];
        return '<span class="equivalency-result-badge equivalency-result-' + safe.replaceAll('_', '-') +
            '"><i class="fa-solid ' + labels[safe][0] + '" aria-hidden="true"></i> ' + label + '</span>';
    }

    function renderUsage(container, usage) {
        if (!usage) {
            container.innerHTML = '<p class="equivalency-empty-context">Compare subjects to view bounded curriculum usage.</p>';
            return;
        }
        const summary = usage.summary || {};
        let markup = '<div class="equivalency-usage-summary"><div><strong>' + Number(summary.placements || 0) +
            '</strong><span>Placements</span></div><div><strong>' + Number(summary.programs || 0) +
            '</strong><span>Programs</span></div><div><strong>' + Number(summary.curricula || 0) +
            '</strong><span>Curricula</span></div><div><strong>' + Number(summary.curriculum_versions || 0) +
            '</strong><span>Versions</span></div></div>';
        const assignments = usage.program_assignments || [];
        if (assignments.length) {
            markup += '<p class="equivalency-usage-programs"><strong>Programs:</strong> ' +
                assignments.map(function (program) {
                    return ui.escapeHtml(program.program_code) + ' — ' + ui.escapeHtml(program.program_name);
                }).join('; ') + '</p>';
        }
        const records = usage.records || [];
        if (!records.length) {
            markup += '<p class="equivalency-empty-context equivalency-usage-empty">No stored curriculum placements found.</p>';
        } else {
            markup += '<ul class="equivalency-usage-list">' + records.map(function (record) {
                return '<li><strong>' + ui.escapeHtml(record.program_code) + '</strong> / ' +
                    ui.escapeHtml(record.curriculum_code) + ' / ' + ui.escapeHtml(record.version_label) +
                    ' <span>· ' + ui.escapeHtml(record.year_level || 'Year not recorded') + ' · ' +
                    ui.escapeHtml(record.semester || 'Semester not recorded') + ' · ' +
                    ui.escapeHtml(record.version_status) + '</span></li>';
            }).join('') + '</ul>';
        }
        container.innerHTML = markup;
    }

    function renderComparison(payload) {
        state.comparison = payload;
        const subjectA = payload.subject_a;
        const subjectB = payload.subject_b;
        elements.summary.hidden = false;
        [['A', subjectA], ['B', subjectB]].forEach(function ([side, subject]) {
            document.getElementById('summary' + side + 'Code').textContent = subject.subject_code;
            document.getElementById('summary' + side + 'Title').textContent = subject.subject_title;
            document.getElementById('summary' + side + 'Units').textContent = displayValue('units', subject.units) + ' Units';
            document.getElementById('summary' + side + 'Status').textContent = subject.status || 'Not Available';
        });
        elements.subjectAHeader.textContent = subjectA.subject_code;
        elements.subjectBHeader.textContent = subjectB.subject_code;
        elements.comparisonMessage.textContent = subjectLabel(subjectA) + ' compared with ' + subjectLabel(subjectB) + '.';
        elements.comparisonRows.innerHTML = (payload.differences || []).map(function (difference) {
            const wide = difference.field === 'description' ? ' equivalency-description-value' : '';
            return '<tr><td><span class="equivalency-field-label">' + ui.escapeHtml(difference.label) +
                '</span></td><td><span class="equivalency-field-value' + wide + '">' +
                ui.escapeHtml(displayValue(difference.field, difference.subject_a)) +
                '</span></td><td><span class="equivalency-field-value' + wide + '">' +
                ui.escapeHtml(displayValue(difference.field, difference.subject_b)) +
                '</span></td><td>' + resultBadge(difference.result, difference.field) + '</td></tr>';
        }).join('');
        elements.subjectAUsageTitle.textContent = subjectA.subject_code + ' Usage';
        elements.subjectBUsageTitle.textContent = subjectB.subject_code + ' Usage';
        renderUsage(elements.subjectAUsage, payload.usage?.subject_a);
        renderUsage(elements.subjectBUsage, payload.usage?.subject_b);
        if (elements.headerRecordDecision) {
            elements.headerRecordDecision.disabled = Boolean(payload.existing_equivalency?.archived_at);
            elements.headerRecordDecision.title = payload.existing_equivalency?.archived_at
                ? 'This subject pair has an archived equivalency record.' : '';
        }
        if (elements.reviewWithCimf) elements.reviewWithCimf.disabled = false;
        if (elements.existingPairMessage) {
            const existing = payload.existing_equivalency;
            elements.existingPairMessage.hidden = !existing;
            const view = document.getElementById('viewExistingEquivalency');
            const edit = document.getElementById('editExistingEquivalency');
            if (view) view.onclick = function () { showDetails(existing); };
            if (edit) {
                edit.hidden = Boolean(existing?.archived_at);
                edit.onclick = function () { if (!existing?.archived_at) openReview(existing); };
            }
        }
    }

    async function compareSubjects() {
        const subjectAId = subjectAAutocomplete?.getSelectedId();
        const subjectBId = subjectBAutocomplete?.getSelectedId();
        if (!subjectAId || !subjectBId || subjectAId === subjectBId) return;
        const requestId = ++state.comparisonRequest;
        elements.compareButton.disabled = true;
        elements.compareButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Comparing...';
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'compare', subject_a_id: subjectAId, subject_b_id: subjectBId,
            });
            if (requestId === state.comparisonRequest) renderComparison(payload);
        } catch (error) {
            if (requestId === state.comparisonRequest) {
                clearRenderedComparison(error.message);
                setTableMessage(elements.comparisonRows, 4, error.message, true);
                ui.showToast(error.message, 'error');
            }
        } finally {
            if (requestId === state.comparisonRequest) {
                elements.compareButton.innerHTML = '<i class="fa-solid fa-scale-balanced" aria-hidden="true"></i> Compare Subjects';
                updateCompareButton();
            }
        }
    }

    function reviewerName(record) {
        const fullName = [record.reviewer_first_name, record.reviewer_last_name]
            .filter(Boolean).join(' ').trim();
        return fullName || record.reviewer_username || 'Pending review';
    }

    function decisionLabel(status) {
        return { pending: 'Decision Required', equivalent: 'Equivalent', not_equivalent: 'Not Equivalent',
            needs_review: 'Needs Further Review' }[status] || 'Needs Further Review';
    }

    function decisionBadge(status) {
        const safe = ['equivalent', 'not_equivalent', 'needs_review'].includes(status)
            ? status : 'needs_review';
        return '<span class="equivalency-decision-badge equivalency-decision-' +
            safe.replaceAll('_', '-') + '">' + ui.escapeHtml(decisionLabel(safe)) + '</span>';
    }

    function dateTime(value) {
        if (!value) return '-';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return String(value);
        return new Intl.DateTimeFormat(undefined, {
            year: 'numeric', month: 'short', day: 'numeric',
            hour: 'numeric', minute: '2-digit'
        }).format(date);
    }

    function subjectCell(code, title) {
        return '<span class="equivalency-subject-label"><strong>' + ui.escapeHtml(code) +
            '</strong><span>' + ui.escapeHtml(title) + '</span></span>';
    }

    function recordRow(record) {
        const action = '<td><div class="equivalency-action-group"><button type="button" class="row-action-button" data-view-equivalency="' + Number(record.id) + '">View</button>' +
            (canManage ? '<button type="button" class="row-action-button" data-edit-equivalency="' + Number(record.id) + '">Edit Decision</button><button type="button" class="row-action-button danger" data-archive-equivalency="' + Number(record.id) + '">Archive</button>' : '') + '</div></td>';
        return '<tr><td>' + subjectCell(record.subject_a_code, record.subject_a_title) +
            '</td><td>' + subjectCell(record.subject_b_code, record.subject_b_title) +
            '</td><td>' + decisionBadge(record.decision_status) +
            '</td><td><span class="equivalency-reviewer">' + ui.escapeHtml(reviewerName(record)) +
            '</span></td><td>' + ui.escapeHtml(dateTime(record.reviewed_at)) + action + '</tr>';
    }

    async function loadRecords(page) {
        state.recordPage = page || 1;
        const requestId = ++state.recordRequest;
        setTableMessage(elements.recordRows, recordColumnCount, 'Loading equivalency records...', false);
        try {
            const payload = await ui.fetchJson(endpoint, {
                action: 'list', page: state.recordPage,
                per_page: elements.recordPerPage?.value || 20,
                search: elements.recordSearch.value.trim(),
                decision_status: elements.recordStatus.value,
            });
            if (requestId !== state.recordRequest) return;
            state.records.clear();
            (payload.equivalencies || []).forEach(function (record) {
                state.records.set(Number(record.id), record);
            });
            ui.setCount(elements.recordCount, Number(payload.pagination.total_records));
            if (!payload.equivalencies.length) {
                setTableMessage(elements.recordRows, recordColumnCount,
                    'No subject equivalency records found.', false);
            } else {
                elements.recordRows.innerHTML = payload.equivalencies.map(recordRow).join('');
            }
            ui.renderPagination(elements.recordPagination, payload.pagination, loadRecords);
        } catch (error) {
            if (requestId !== state.recordRequest) return;
            setTableMessage(elements.recordRows, recordColumnCount, error.message, true);
            ui.showToast(error.message, 'error');
        }
    }

    function setReviewRequirements() {
        if (!reviewForm) return;
        const decision = document.getElementById('equivalencyDecision').value;
        document.getElementById('equivalencyRemarks').required = false;
    }

    function openReview(record) {
        if (!canManage || !reviewModal || !reviewForm) return;
        ui.clearFormError(reviewForm);
        reviewForm.reset();
        const fromComparison = !record && state.comparison;
        if (!record && !fromComparison) return;
        const subjectA = fromComparison ? state.comparison.subject_a : null;
        const subjectB = fromComparison ? state.comparison.subject_b : null;
        const current = record || (fromComparison ? state.comparison.existing_equivalency : null);
        const subjectAId = current ? current.subject_id : subjectA.id;
        const subjectBId = current ? current.equivalent_subject_id : subjectB.id;
        const subjectALabel = current
            ? current.subject_a_code + ' - ' + current.subject_a_title : subjectLabel(subjectA);
        const subjectBLabel = current
            ? current.subject_b_code + ' - ' + current.subject_b_title : subjectLabel(subjectB);
        document.getElementById('equivalencyRecordId').value = current ? current.id : '';
        document.getElementById('equivalencySubjectAId').value = subjectAId;
        document.getElementById('equivalencySubjectBId').value = subjectBId;
        document.getElementById('equivalencySubjectALabel').textContent = subjectALabel;
        document.getElementById('equivalencySubjectBLabel').textContent = subjectBLabel;
        document.getElementById('equivalencyReviewContext').textContent =
            subjectALabel + ' compared with ' + subjectBLabel;
        document.getElementById('equivalencyDecision').value =
            current && current.decision_status !== 'pending' ? current.decision_status : '';
        document.getElementById('equivalencyRemarks').value = current?.remarks || '';
        document.getElementById('equivalencyReviewTitle').textContent = current
            ? 'Edit Equivalency Decision' : 'Record Equivalency Decision';
        document.getElementById('saveEquivalencyButton').textContent = 'Save Decision';
        setReviewRequirements();
        reviewModal.open();
    }

    async function submitReview(event) {
        event.preventDefault();
        if (!reviewForm.reportValidity()) return;
        ui.clearFormError(reviewForm);
        const data = new FormData(reviewForm);
        const isUpdate = Boolean(data.get('id'));
        data.set('action', isUpdate ? 'update' : 'create');
        ui.setFormSubmitting(reviewForm, true, isUpdate ? 'Saving...' : 'Creating...');
        try {
            const payload = await ui.postForm(endpoint, data);
            reviewModal.close();
            ui.showToast(payload.message, isUpdate ? 'updated' : 'success');
            if (elements.recordRows) await loadRecords(state.recordPage);
            if (state.comparison) await compareSubjects();
        } catch (error) {
            ui.showFormError(reviewForm, error.message);
        } finally {
            ui.setFormSubmitting(reviewForm, false);
        }
    }

    function showDetails(record) {
        const detail = document.getElementById('equivalencyDetails');
        const pairs = [
            ['Subject A', record.subject_a_code + ' — ' + record.subject_a_title],
            ['Subject B', record.subject_b_code + ' — ' + record.subject_b_title],
            ['Decision', decisionLabel(record.decision_status)],
            ['Review Notes', record.remarks || '—'],
            ['Reviewed By', reviewerName(record)],
            ['Reviewed At', dateTime(record.reviewed_at)],
            ['Last Updated', dateTime(record.updated_at)],
        ];
        detail.innerHTML = pairs.map(([label, value]) => '<div><strong>' + ui.escapeHtml(label) +
            '</strong><p>' + ui.escapeHtml(value) + '</p></div>').join('');
        detailsModal.open();
    }

    function reviewWithCimf() { if (state.comparison) window.CSMSCIMF?.open('review'); }

    async function archiveSelected() {
        if (!archiveRecordId) return;
        const data = new FormData();
        data.set('action', 'archive'); data.set('id', archiveRecordId);
        try {
            const payload = await ui.postForm(endpoint, data);
            archiveModal.close(); archiveRecordId = null;
            ui.showToast(payload.message, 'success'); await loadRecords(state.recordPage);
        } catch (error) { ui.showToast(error.message, 'error'); }
    }

    elements.subjectASearch?.addEventListener('input', synchronizeSelectors);
    elements.subjectBSearch?.addEventListener('input', synchronizeSelectors);
    elements.compareButton?.addEventListener('click', compareSubjects);
    elements.clearComparison?.addEventListener('click', function () {
        subjectAAutocomplete?.clear();
        subjectBAutocomplete?.clear();
        clearRenderedComparison();
        updateCompareButton();
    });
    elements.headerRecordDecision?.addEventListener('click', function () { openReview(null); });
    elements.reviewWithCimf?.addEventListener('click', reviewWithCimf);

    const reloadRecords = ui.debounce(function () { loadRecords(1); }, 300);
    elements.recordSearch?.addEventListener('input', reloadRecords);
    elements.recordStatus?.addEventListener('change', function () { loadRecords(1); });
    elements.recordPerPage?.addEventListener('change', function () { loadRecords(1); });
    elements.clearFilters?.addEventListener('click', function () {
        elements.recordSearch.value = '';
        elements.recordStatus.value = '';
        loadRecords(1);
    });
    elements.recordRows?.addEventListener('click', function (event) {
        const viewButton = event.target.closest('[data-view-equivalency]');
        if (viewButton) return showDetails(state.records.get(Number(viewButton.dataset.viewEquivalency)));
        const editButton = event.target.closest('[data-edit-equivalency]');
        if (editButton) return openReview(state.records.get(Number(editButton.dataset.editEquivalency)));
        const archiveButton = event.target.closest('[data-archive-equivalency]');
        if (archiveButton) { archiveRecordId = Number(archiveButton.dataset.archiveEquivalency); archiveModal.open(); }
    });
    document.getElementById('confirmArchiveEquivalency')?.addEventListener('click', archiveSelected);
    document.getElementById('equivalencyDecision')?.addEventListener('change', setReviewRequirements);
    reviewForm?.addEventListener('submit', submitReview);

    if (isComparisonView) {
        clearRenderedComparison();
    } else {
        loadRecords(1);
    }
}());
