(function () {
    'use strict';
    const $ = id => document.getElementById(id);
    const esc = window.CurriculumUI.escapeHtml;
    const root = $('prerequisiteWorkspace');
    const modal = window.CurriculumUI.createModal($('cimfModal'));
    const state = { version: '', revision: null, view: 'main', analysis: null, answer: null, comparison: null, subjectReview: null, busy: false };

    function context() { return window.PrerequisiteCIMFContext(); }
    function clearForContext() {
        const c = context();
        if (state.version === c.versionId && state.revision === c.revision) return;
        state.version = c.versionId;
        state.revision = c.revision;
        state.analysis = state.answer = state.comparison = state.subjectReview = null;
        $('cimfAnalysis').innerHTML = $('cimfSubjectAnalysis').innerHTML = $('cimfAnswer').innerHTML = $('cimfComparison').innerHTML = '';
        $('cimfStatus').textContent = '';
        $('cimfQuestion').value = '';
        const options = '<option value="">Select subject</option>' + c.placements.map(p => '<option value="' + Number(p.subject_id) + '">' + esc(p.subject_code + ' — ' + p.subject_title) + '</option>').join('');
        $('cimfSubjectA').innerHTML = $('cimfSubjectB').innerHTML = options;
    }
    function renderContext() {
        const c = context(), labels = c.context.map((record, i) => record ? (i === 0 ? record.program_code || record.program_name : i === 1 ? record.curriculum_code || record.curriculum_name : record.version_label) : '—');
        $('cimfContext').textContent = labels.join(' · ');
        $('cimfDemo').hidden = !/demo|synthetic/i.test(String(c.context[2]?.description || ''));
        const ready = Boolean(c.versionId && c.loaded);
        const enabled = ready && root.dataset.cimfStatus === 'SUCCESS';
        ['cimfAnalyze', 'cimfAskView', 'cimfCompareView'].forEach(id => $(id).disabled = !enabled || state.busy);
        $('cimfAskButton').disabled = !enabled || state.busy;
        $('cimfCompareButton').disabled = !enabled || state.busy;
        if (!ready) $('cimfStatus').textContent = 'Select a curriculum version to use CIMF.';
        else if (root.dataset.cimfStatus !== 'SUCCESS') $('cimfStatus').textContent = root.dataset.cimfStatus === 'CONFIG_DISABLED' ? 'CIMF is currently disabled. Existing curriculum functions remain available.' : 'CIMF is unavailable due to its server configuration. Existing curriculum functions remain available.';
    }
    function view(name) {
        state.view = name;
        $('cimfMain').hidden = name !== 'main'; $('cimfAsk').hidden = name !== 'ask'; $('cimfCompare').hidden = name !== 'compare';
        $('cimfBack').hidden = name === 'main';
        $('cimfTitle').textContent = name === 'ask' ? 'Ask CIMF' : name === 'compare' ? 'Compare Subjects' : 'CIMF Prerequisite Review';
    }
    function open(subjectId) {
        clearForContext(); renderContext(); view('main'); modal.open();
        if (subjectId) request('prerequisite_subject_review', { subject_id: subjectId }, 'cimfSubjectAnalysis');
    }
    function resultHtml(data) {
        const a = data.analysis;
        let html = '<div class="cimf-result"><p>' + esc(a.summary) + '</p>';
        for (const item of a.review_items) {
            html += '<article><strong>' + esc(item.subject_code) + '</strong> · ' + esc(item.subject_title);
            if (item.related_subject_code) html += ' ↔ <strong>' + esc(item.related_subject_code) + '</strong>';
            html += '<p>' + esc(item.reason) + '</p><small>Needs human review</small></article>';
        }
        if (a.limitations.length) html += '<p><strong>Limitations:</strong> ' + a.limitations.map(esc).join('; ') + '</p>';
        return html + '</div>';
    }
    async function request(action, fields, destination) {
        if (state.busy || !context().versionId || root.dataset.cimfStatus !== 'SUCCESS') return;
        state.busy = true; renderContext(); $('cimfStatus').textContent = 'CIMF is analyzing…';
        const form = new FormData(); form.set('action', action); form.set('curriculum_version_id', state.version);
        Object.entries(fields).forEach(([key, value]) => form.set(key, value));
        const requestedVersion = state.version, requestedRevision = state.revision;
        try {
            const data = await window.CurriculumUI.postForm(root.dataset.cimfApi, form);
            if (requestedVersion !== state.version || requestedRevision !== state.revision) return;
            $(destination).innerHTML = resultHtml(data);
            $('cimfDemo').hidden = !data.demo_data;
            $('cimfStatus').textContent = '';
            if (action === 'prerequisite_review') state.analysis = data.analysis;
            else if (action === 'ask_cimf') state.answer = data.analysis;
            else if (action === 'compare_subjects') state.comparison = data.analysis;
            else state.subjectReview = data.analysis;
        } catch (error) {
            if (requestedVersion === state.version && requestedRevision === state.revision)
                $('cimfStatus').textContent = error.name === 'CurriculumRequestError' && error.status !== 0
                    ? error.message : 'CIMF is temporarily unavailable. Existing curriculum functions remain available.';
        } finally { state.busy = false; renderContext(); }
    }
    $('openCimfButton').addEventListener('click', () => open());
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-cimf-subject]');
        if (!button) return;
        $('detailsModal').querySelector('[data-modal-close]')?.click();
        open(button.dataset.cimfSubject);
    });
    $('cimfBack').addEventListener('click', () => { $('cimfStatus').textContent = ''; view('main'); });
    $('cimfAskView').addEventListener('click', () => { $('cimfStatus').textContent = ''; view('ask'); });
    $('cimfCompareView').addEventListener('click', () => { $('cimfStatus').textContent = ''; view('compare'); });
    $('cimfAnalyze').addEventListener('click', () => request('prerequisite_review', {}, 'cimfAnalysis'));
    $('cimfAskButton').addEventListener('click', () => {
        const question = $('cimfQuestion').value.trim();
        if (!question || question.length > 600) { $('cimfStatus').textContent = 'Enter a curriculum question of up to 600 characters.'; return; }
        request('ask_cimf', { question }, 'cimfAnswer');
    });
    $('cimfCompareButton').addEventListener('click', () => {
        const a = $('cimfSubjectA').value, b = $('cimfSubjectB').value;
        if (!a || !b || a === b) { $('cimfStatus').textContent = 'Select two different subjects.'; return; }
        request('compare_subjects', { subject_id: a, other_subject_id: b }, 'cimfComparison');
    });
    document.addEventListener('prerequisite-context-loaded', () => { clearForContext(); if (!$('cimfModal').hidden) renderContext(); });
}());
