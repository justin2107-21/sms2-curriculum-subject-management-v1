// Review panel on the version workspace: submit, approve, return. The server decides what is allowed.
(function () {
    'use strict';
    const panel = document.getElementById('versionReviewPanel');
    const ui = window.CurriculumUI;
    if (!panel || !ui) return;
    const endpoint = '../http/review_actions.php';
    const statusEl = panel.querySelector('[data-review-status]');
    const rows = panel.querySelector('[data-review-events]');
    const form = panel.querySelector('form');
    const message = panel.querySelector('[data-review-message]');
    let versionId = null;

    function show(text, isError) {
        message.textContent = text;
        message.hidden = !text;
        message.setAttribute('role', isError ? 'alert' : 'status');
        message.className = isError ? 'form-error' : 'curriculum-notice';
    }

    async function load() {
        if (!versionId) return;
        const data = await ui.fetchJson(endpoint, {action: 'history', curriculum_version_id: versionId});
        statusEl.innerHTML = ui.statusBadge(data.review_status);
        const summary = document.querySelector('#referenceReviewStatus .status-badge');
        if (summary) summary.outerHTML = ui.statusBadge(data.review_status);
        panel.querySelectorAll('[data-review-submit]').forEach(b => { b.hidden = !data.can_submit; });
        panel.querySelectorAll('[data-review-decide]').forEach(b => { b.hidden = !data.can_decide; });
        const field = panel.querySelector('[data-review-field]');
        const hasAction = !!panel.querySelector('button[data-review-submit]:not([hidden]), button[data-review-decide]:not([hidden])');
        if (field) field.hidden = !hasAction;
        const actions = panel.querySelector('.modal-actions');
        if (actions) actions.hidden = !hasAction;
        rows.innerHTML = data.events.length ? data.events.map(e => '<tr><td>' + ui.escapeHtml(e.created_at) + '</td><td>' + ui.escapeHtml(e.actor_name) + '</td><td>'
            + ui.escapeHtml(ui.statusLabel(e.action)) + '</td><td>' + ui.escapeHtml(ui.statusLabel(e.new_status)) + '</td><td>' + ui.escapeHtml(e.comments || '') + '</td></tr>').join('')
            : '<tr><td colspan="5" class="empty-cell">No review activity yet.</td></tr>';
    }

    async function send(button) {
        const body = new FormData();
        body.set('curriculum_version_id', versionId);
        const text = form.elements.reason ? form.elements.reason.value : '';
        if (button.dataset.reviewSubmit !== undefined) {
            body.set('action', 'submit');
            body.set('comment', text);
        } else {
            body.set('action', 'decide');
            body.set('decision', button.dataset.reviewDecide);
            body.set('reason', text);
        }
        try {
            const data = await ui.postForm(endpoint, body);
            show(data.message || 'Saved.', false);
            form.reset();
            document.dispatchEvent(new CustomEvent('csms:review-changed', {detail: {versionId}}));
            await load();
        } catch (error) {
            show(error.message || 'Unable to save.', true);
        }
    }

    panel.addEventListener('click', event => {
        const button = event.target.closest('button[data-review-submit], button[data-review-decide]');
        if (button) send(button);
    });
    form.addEventListener('submit', event => event.preventDefault());
    document.addEventListener('csms:version-workspace', event => {
        versionId = event.detail.id;
        panel.hidden = false;
        show('', false);
        load().catch(error => show(error.message || 'Unable to load review history.', true));
    });
})();
