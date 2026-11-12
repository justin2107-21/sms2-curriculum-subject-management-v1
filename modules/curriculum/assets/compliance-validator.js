(function () {
    'use strict';

    // CHED/DepEd Compliance Validator: one page, four views (Results | Rule library | Sources | History). Rules and sources are data entered by
    // people; the validator is deterministic PHP over stored curriculum data. Nothing here gives an overall verdict: the page shows
    // each rule's status (Met / Not met / Cannot determine from stored data) and counts, never an overall verdict.
    const root = document.getElementById('complianceWorkspace');
    const ui = window.CurriculumUI;
    if (!root || !ui) return;

    const $ = id => document.getElementById(id);
    const esc = ui.escapeHtml;
    const api = root.dataset.complianceApi;
    const canRun = root.dataset.canRun === '1', canManage = root.dataset.canManage === '1', canVerify = root.dataset.canVerify === '1';
    const SEARCH_MIN = 2;
    const VIEWS = ['results', 'rules', 'sources', 'history'];
    const RESULT_CHIP = { PASS: ['Met', 'csms-chip--approved'], FAIL: ['Not met', 'csms-chip--returned'], NOT_EVALUATED: ['Cannot determine from stored data', 'csms-chip--draft'], PENDING_BCP: ['Pending BCP confirmation', 'csms-chip--type'] };
    const SOURCE_CLASS = { public_regulation: 'Public regulation', client_reply: 'BCP client reply' };
    const ORIGIN = { library: 'Library', custom: 'Custom' };
    const MODE_LABEL = { evaluate: 'Checked in runs', display_only: 'Shown for reference', pending_bcp: 'Pending BCP confirmation' };
    const RULE_TYPES = { MIN_TOTAL_UNITS: 'Minimum total units', MAX_TOTAL_UNITS: 'Maximum total units', REQUIRED_SUBJECT: 'Required subject', MIN_SUBJECT_UNITS: 'Minimum subject units', MAX_SUBJECT_UNITS: 'Maximum subject units', COMPONENT_UNITS: 'Units by component', GE_UNITS: 'General education units', PE_UNITS: 'Physical education', NSTP_REQUIREMENT: 'NSTP requirement', NSTP_PREREQUISITE: 'NSTP prerequisite', NSTP_COMPONENTS: 'NSTP components', SHS_HOURS: 'Senior High hours', GRADING_WEIGHTS: 'Grading weights' };
    const SOURCE_STATUS = { draft: ['Draft', 'csms-chip--draft'], verified: ['Source confirmed', 'csms-chip--approved'], inactive: ['Inactive', 'csms-chip--archived'] };
    const SOURCE_KINDS = { unknown: 'Unknown / not classified', regulatory_document: 'Regulatory document', ched_sample_curriculum: 'CHED sample curriculum', institutional_curriculum: 'Institutional curriculum', institutional_policy: 'Institutional policy', supporting_document: 'Supporting document', other_document: 'Other document' };
    const CATEGORIES = { CHED: 'CHED', DEPED: 'DepEd', INSTITUTIONAL: 'Institutional', OTHER: 'Other' };
    const SUBJECTS = { document_identity: 'Document identity', declared_origin: 'Declared origin', applicability: 'Applicability' };
    const EVIDENCE_RESULT = { confirmed: ['Confirmed', 'csms-chip--approved'], not_confirmed: ['Not confirmed', 'csms-chip--returned'], not_assessed: ['Not assessed', 'csms-chip--draft'] };
    const state = { view: root.dataset.view, run: null, version: null, contextTicket: 0, panelLocked: false, dialog: null, lists: {} };

    // ---------- shared context bar ----------
    const cascade = ui.createCascadingAutocomplete({ levels: ['program', 'curriculum', 'version'].map((key, index) => ({
        input: $(key + 'Search'), valueInput: $(key + 'Id'), list: $(key + 'Options'), minimumLength: 0, showSuggestionsOnFocus: true, showSuggestionsOnEnable: true,
        placeholder: index === 0 ? 'Search program code or name' : 'Search ' + key, disabledPlaceholder: index === 1 ? 'Choose program first' : 'Choose curriculum first',
        search: async query => (await ui.fetchJson(api, { action: 'context_options', level: key, ...ctxIds(), search: query })).options,
        format: item => ({ label: [item.code, item.title].filter(Boolean).join(' — '), code: item.code, title: item.title || '' })
    })), onChange: () => scheduleContext() });
    function ctxIds() {
        const out = {};
        [['program_id', 0], ['curriculum_id', 1]].forEach(([key, i]) => { const id = cascade?.get(i)?.getSelectedId?.(); if (id) out[key] = id; });
        return out;
    }
    const programId = () => cascade.get(0).getSelectedId() || '';
    const curriculumId = () => cascade.get(1).getSelectedId() || '';
    const versionId = () => cascade.get(2).getSelectedId() || '';

    // ---------- small helpers ----------
    const dateTime = value => {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? String(value) : new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(date);
    };
    const personName = r => [r.run_by_first_name, r.run_by_last_name].filter(Boolean).join(' ').trim() || r.run_by_username || 'Unknown user';
    const chip = (pair, extra) => '<span class="csms-chip ' + pair[1] + '">' + esc(pair[0]) + '</span>' + (extra || '');
    const demoChip = '<span class="csms-chip csms-chip--type" title="Development seed data">Demo: not a real source</span>';
    const num = value => ui.formatUnits(value);
    function sourceLine(row) {
        const code = row.reference_code || row.reference_title || row.title || 'Source';
        const year = row.reference_year || (row.effective_date ? String(row.effective_date).slice(0, 4) : '');
        const library = row.origin === 'library' || row.section_label;
        const lines = [esc([row.issuing_body, year].filter(Boolean).join(' · '))];
        if (library && row.section_label) lines.push(esc(row.section_label) + ' · ' + (row.source_page ? 'p. ' + esc(row.source_page) : 'Page not recorded'));
        return '<strong class="csms-ctable__code">' + esc(code) + '</strong>' + lines.filter(Boolean).map(l => '<span class="csms-sub">' + l + '</span>').join('');
    }
    // A client reply has no document: its label says where it came from.
    const sourceCell = row => row.reference_code || row.reference_title ? sourceLine(row) : '<strong class="csms-ctable__code">BCP reply</strong><span class="csms-sub">' + esc(row.source_label || '') + '</span>';
    function requirementText(rule) {
        const subject = rule.subject_code ? rule.subject_code + (rule.subject_title ? ' — ' + rule.subject_title : '') : 'The chosen subject';
        const n = rule.numeric_value === null || rule.numeric_value === undefined ? '' : num(rule.numeric_value);
        return ({ MIN_TOTAL_UNITS: 'At least ' + n + ' units in total (all placements)', MAX_TOTAL_UNITS: 'At most ' + n + ' units in total (all placements)', REQUIRED_SUBJECT: subject + ' must be placed in the version',
            MIN_SUBJECT_UNITS: subject + ': at least ' + n + ' units', MAX_SUBJECT_UNITS: subject + ': at most ' + n + ' units' })[rule.rule_type] || rule.rule_type;
    }
    const appliesTo = rule => rule.origin === 'library' ? ({ all_college: 'All college programs', senior_high: 'Senior High School', program_code: 'Program ' + (rule.applies_to_value || '') })[rule.applies_to_kind] || '—'
        : rule.program_code ? 'Program ' + rule.program_code : (rule.curriculum_code ? 'Curriculum ' + rule.curriculum_code : '—');
    const shorten = (text, n) => String(text || '').length > n ? String(text).slice(0, n - 1).trimEnd() + '…' : String(text || '');
    function ruleStatusCell(rule) {
        const active = chip(rule.technical_status === 'active' ? ['Active', 'csms-chip--approved'] : ['Inactive', 'csms-chip--archived']);
        if (rule.origin !== 'library') return active + '<span class="csms-sub">' + esc(rule.verification_status === 'verified' ? 'Source confirmed' : 'Source not confirmed: not checked') + '</span>';
        const note = rule.evaluation_mode === 'evaluate' && rule.source_class === 'public_regulation' && rule.verification_status !== 'verified' ? 'Source not confirmed: not checked' : MODE_LABEL[rule.evaluation_mode];
        return active + '<span class="csms-sub">' + esc(note) + '</span>';
    }

    // ---------- views and the address ----------
    const OWN_KEYS = ['q', 'type', 'status', 'origin', 'applies', 'demo', 'per_page', 'page', 'only'];
    function writeUrl(extra, push) {
        const params = new URLSearchParams(window.location.search);
        params.delete('tab');
        OWN_KEYS.forEach(key => params.delete(key));
        params.set('view', state.view);
        Object.entries(extra || {}).forEach(([key, value]) => { if (value !== '' && value !== null && value !== undefined && String(value) !== '20' || (key === 'page' && Number(value) > 1)) params.set(key, String(value)); });
        const url = window.location.pathname + '?' + params.toString();
        if (url !== window.location.pathname + window.location.search) window.history[push ? 'pushState' : 'replaceState'](null, '', url);
    }
    function showView(view, push) {
        if (!VIEWS.includes(view)) view = 'results';
        state.view = view;
        VIEWS.forEach(name => { $('view' + name.charAt(0).toUpperCase() + name.slice(1)).hidden = name !== view; });
        document.querySelectorAll('#complianceViews a').forEach(link => {
            const active = link.dataset.view === view;
            link.classList.toggle('is-active', active);
            if (active) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
        });
        $('complianceContextBar').hidden = !['results', 'history'].includes(view);
        if (push) writeUrl({}, true);
        if (view === 'results') loadResultsContext();
        else lists[view].load(true);
    }
    document.querySelectorAll('#complianceViews a').forEach(link => link.addEventListener('click', event => { event.preventDefault(); if (link.dataset.view !== state.view) { Object.values(lists).forEach(list => list.reset()); showView(link.dataset.view, true); } }));

    // ---------- generic server-side list (search 2 characters, filters, page size, paging, empty states) ----------
    function makeList(config) {
        const view = config.view, ids = config.ids;
        const list = { page: 1, loaded: false, request: 0, records: new Map(), timer: null };
        const el = key => $(ids[key]);
        function params() {
            const out = { action: config.action, page: list.page, per_page: el('perPage').value };
            const q = el('search')?.value.trim() || '';
            if (q.length >= SEARCH_MIN) out.search = q;
            Object.entries(config.filters(list)).forEach(([key, value]) => { if (value) out[key] = value; });
            return out;
        }
        list.reset = () => { list.page = 1; };
        list.read = () => {
            const p = new URLSearchParams(window.location.search);
            if (p.get('view') !== view) return;
            if (el('search')) el('search').value = p.get('q') || '';
            config.readFilters(p, el);
            if (p.get('per_page') && ['10', '20', '50', '100'].includes(p.get('per_page'))) el('perPage').value = p.get('per_page');
            list.page = Math.max(1, parseInt(p.get('page'), 10) || 1);
        };
        list.write = push => {
            const q = el('search')?.value.trim() || '';
            writeUrl({ q, ...config.writeFilters(el), per_page: el('perPage').value, page: list.page }, push);
        };
        function states(which, message) {
            el('empty').hidden = which !== 'empty';
            el('noMatch').hidden = which !== 'nomatch';
            el('error').hidden = which !== 'error';
            el('wrap').hidden = which !== 'list';
            el('pagination').hidden = which !== 'list';
            if (which === 'error') $(ids.errorText).textContent = message || '';
        }
        const filtering = () => Boolean((el('search')?.value.trim().length >= SEARCH_MIN) || Object.values(config.filters(list)).some(Boolean));
        list.load = async function (fromView) {
            if (fromView) list.read();
            const ticket = ++list.request;
            try {
                const payload = await ui.fetchJson(api, params());
                if (ticket !== list.request) return;
                list.loaded = true;
                list.records.clear();
                const rows = payload[config.key] || [];
                rows.forEach(record => list.records.set(rowKey(record), record));
                ui.setCount(el('count'), Number(payload.pagination.total_records));
                if (Number(payload.pagination.page) !== list.page) { list.page = Number(payload.pagination.page); }
                if (!rows.length) { states(filtering() ? 'nomatch' : 'empty'); return; }
                el('rows').innerHTML = rows.map(config.row).join('');
                ui.renderPagination(el('pagination'), payload.pagination, page => { list.page = page; list.write(true); list.load(); });
                states('list');
            } catch (error) {
                if (ticket !== list.request) return;
                states('error', error.message);
            }
        };
        const reload = () => { list.page = 1; list.write(false); list.load(); };
        const later = ui.debounce(() => { if (el('search') && el('search').value.trim().length === 1) return; reload(); }, 300);
        el('search')?.addEventListener('input', later);
        config.controls.forEach(id => $(id).addEventListener('change', reload));
        el('rows').addEventListener('click', event => { const tr = event.target.closest('[data-row]'); if (tr) config.open(list.records.get(tr.dataset.row)); });
        el('rows').addEventListener('keydown', event => { if (event.target.matches?.('[data-row]') && ['Enter', ' '].includes(event.key)) { event.preventDefault(); config.open(list.records.get(event.target.dataset.row)); } });
        return list;
    }
    const rowKey = record => (record.origin ? record.origin + ':' : '') + record.id;
    const rowAttrs = (record, label) => 'class="csms-row-click" tabindex="0" data-row="' + esc(rowKey(record)) + '" aria-haspopup="dialog" aria-label="Open ' + esc(label) + '"';

    const lists = {
        rules: makeList({
            view: 'rules', action: 'rule_list', key: 'rules', controls: ['ruleType', 'ruleStatus', 'ruleOrigin', 'ruleApplies', 'ruleDemo', 'rulePerPage'],
            ids: { search: 'ruleSearch', perPage: 'rulePerPage', count: 'ruleCount', rows: 'ruleRows', wrap: 'ruleWrap', pagination: 'rulePagination', empty: 'ruleEmpty', noMatch: 'ruleNoMatch', error: 'ruleError', errorText: 'ruleErrorText' },
            filters: () => ({ rule_type: $('ruleType').value, technical_status: $('ruleStatus').value, origin: $('ruleOrigin').value, applies_to: $('ruleApplies').value, include_demo: $('ruleDemo').checked ? '1' : '' }),
            readFilters: (p, el) => { $('ruleType').value = p.get('type') || ''; $('ruleStatus').value = p.get('status') || ''; $('ruleOrigin').value = p.get('origin') || ''; $('ruleApplies').value = p.get('applies') || ''; $('ruleDemo').checked = p.get('demo') === '1'; },
            writeFilters: () => ({ type: $('ruleType').value, status: $('ruleStatus').value, origin: $('ruleOrigin').value, applies: $('ruleApplies').value, demo: $('ruleDemo').checked ? '1' : '' }),
            row: rule => '<tr ' + rowAttrs(rule, rule.rule_name) + '><td><strong>' + esc(rule.rule_name) + '</strong><span class="csms-sub">' + esc(RULE_TYPES[rule.rule_type] || rule.rule_type) + '</span>' + (Number(rule.is_demo) ? demoChip : '') + '</td><td>' + esc(ORIGIN[rule.origin] || 'Custom') + '</td><td>' + esc(rule.origin === 'library' ? (SOURCE_CLASS[rule.source_class] || rule.source_class) : 'Custom rule') + '</td><td>' + sourceCell(rule) + '</td><td>' + esc(rule.origin === 'library' ? shorten(rule.requirement_text, 150) : requirementText(rule)) + '</td><td>' + esc(appliesTo(rule)) + '</td><td>' +
                ruleStatusCell(rule) + '</td></tr>',
            open: rule => rule && (rule.origin === 'library' ? openLibraryRule(rule.id) : openRule(rule.id))
        }),
        sources: makeList({
            view: 'sources', action: 'reference_list', key: 'references', controls: ['sourceType', 'sourceStatus', 'sourceDemo', 'sourcePerPage'],
            ids: { search: 'sourceSearch', perPage: 'sourcePerPage', count: 'sourceCount', rows: 'sourceRows', wrap: 'sourceWrap', pagination: 'sourcePagination', empty: 'sourceEmpty', noMatch: 'sourceNoMatch', error: 'sourceError', errorText: 'sourceErrorText' },
            filters: () => ({ reference_type: $('sourceType').value, verification_status: $('sourceStatus').value, include_demo: $('sourceDemo').checked ? '1' : '' }),
            readFilters: p => { $('sourceType').value = p.get('type') || ''; $('sourceStatus').value = p.get('status') || ''; $('sourceDemo').checked = p.get('demo') === '1'; },
            writeFilters: () => ({ type: $('sourceType').value, status: $('sourceStatus').value, demo: $('sourceDemo').checked ? '1' : '' }),
            row: ref => '<tr ' + rowAttrs(ref, ref.reference_code || ref.title) + '><td><strong class="csms-ctable__code">' + esc(ref.reference_code || '—') + '</strong><span class="csms-sub">' + esc(ref.title || '') + '</span>' + (Number(ref.is_demo) ? demoChip : '') + '</td><td>' + esc(ref.issuing_body) + '<span class="csms-sub">' + esc(CATEGORIES[ref.reference_type] || ref.reference_type) + '</span></td><td>' + esc(ref.reference_year || '—') + '</td><td>' + esc(SOURCE_KINDS[ref.source_kind] || ref.source_kind) + '</td><td>' +
                chip(SOURCE_STATUS[ref.verification_status] || SOURCE_STATUS.draft) + '</td><td class="csms-ctable__num">' + Number(ref.rule_count || 0) + '</td></tr>',
            open: ref => ref && openSource(ref.id)
        }),
        history: makeList({
            view: 'history', action: 'history_list', key: 'runs', controls: ['historyOnlyVersion', 'historyDemo', 'historyPerPage'],
            ids: { search: 'historySearch', perPage: 'historyPerPage', count: 'historyCount', rows: 'historyRows', wrap: 'historyWrap', pagination: 'historyPagination', empty: 'historyEmpty', noMatch: 'historyNoMatch', error: 'historyError', errorText: 'historyErrorText' },
            filters: () => ({ curriculum_version_id: $('historyOnlyVersion').checked ? versionId() : '', include_demo: $('historyDemo').checked ? '1' : '' }),
            readFilters: p => { $('historyOnlyVersion').checked = p.get('only') === '1'; $('historyDemo').checked = p.get('demo') === '1'; },
            writeFilters: () => ({ only: $('historyOnlyVersion').checked ? '1' : '', demo: $('historyDemo').checked ? '1' : '' }),
            row: run => '<tr ' + rowAttrs(run, 'the run of ' + dateTime(run.created_at)) + '><td>' + esc(dateTime(run.created_at)) + '</td><td>' + esc(run.program_code) + '</td><td>' + esc(run.curriculum_code) + '</td><td>' + esc(run.version_label) + '</td><td class="csms-ctable__num">' + Number(run.rules_evaluated) + '</td><td class="csms-ctable__num">' + Number(run.passed_count) + '</td><td class="csms-ctable__num">' + Number(run.failed_count) + '</td><td class="csms-ctable__num">' + Number(run.not_evaluated_count) + '</td><td>' + esc(personName(run)) + '</td></tr>',
            open: run => run && openRun(run.id)
        })
    };
    // The history view follows the selected version only when asked to.
    document.addEventListener('csms:context-changed', () => { if (state.view === 'history' && $('historyOnlyVersion').checked) lists.history.load(); });

    // ---------- Results ----------
    function scheduleContext() { clearTimeout(state.timer); state.timer = setTimeout(loadResultsContext, 250); }
    function resultsStates(which) {
        $('resultsLoading').hidden = which !== 'loading';
        $('resultsNoVersion').hidden = which !== 'noVersion';
        $('resultsPanel').hidden = !['before', 'norules', 'body'].includes(which);
        $('resultsBeforeRun').hidden = which !== 'before';
        $('resultsNoRules').hidden = which !== 'norules';
        $('resultsBody').hidden = which !== 'body';
    }
    function resultRow(result) {
        const pair = RESULT_CHIP[result.result_status] || RESULT_CHIP.NOT_EVALUATED;
        const demo = String(result.rule_name_snapshot || '').startsWith('DEV-DEMO');
        return '<tr><td><strong>' + esc(result.rule_name_snapshot) + '</strong><span class="csms-sub">' + esc(RULE_TYPES[result.rule_type_snapshot] || result.rule_type_snapshot) + '</span>' + (demo ? demoChip : '') + '</td><td>' +
            (result.reference_code || result.source_label ? sourceCell(result) : '<span class="csms-sub">' + esc(result.reference_identifier_snapshot) + '</span>') + '</td><td>' + esc(result.requirement_snapshot) + '</td><td>' + esc(result.actual_value_snapshot || '—') + '</td><td>' +
            chip(pair) + '</td><td>' + esc(result.explanation) + (result.missing_data_text ? '<span class="csms-sub">Missing: ' + esc(result.missing_data_text) + '</span>' : '') + '</td></tr>';
    }
    function countsHtml(run) {
        return '<span class="compliance-counts__total">Rules checked: <strong>' + Number(run.rules_evaluated) + '</strong></span>' + chip(['Met ' + Number(run.met_count ?? run.passed_count), 'csms-chip--approved']) +
            chip(['Not met ' + Number(run.not_met_count ?? run.failed_count), 'csms-chip--returned']) + chip(['Cannot determine ' + Number(run.cannot_determine_count ?? run.not_evaluated_count), 'csms-chip--draft']) +
            (Number(run.pending_count) > 0 ? chip(['Pending BCP confirmation ' + Number(run.pending_count), 'csms-chip--type']) : '');
    }
    function showRun(run, caption) {
        state.run = run;
        $('resultsCounts').innerHTML = countsHtml(run);
        $('resultsRows').innerHTML = (run.results || []).map(resultRow).join('');
        $('resultsCaption').textContent = caption || '';
        resultsStates('body');
        window.CSMSCIMF?.updateContext?.();
    }
    async function loadResultsContext() {
        const ticket = ++state.contextTicket, version = versionId();
        state.run = null;
        if (!version) { resultsStates('noVersion'); if ($('runValidation')) $('runValidation').disabled = true; return; }
        resultsStates('loading');
        if ($('runValidation')) $('runValidation').disabled = false;
        try {
            // Show the latest stored run of this version when there is one, otherwise the "run validation" prompt.
            const history = await ui.fetchJson(api, { action: 'history_list', curriculum_version_id: version, page: 1, per_page: 10 });
            if (ticket !== state.contextTicket) return;
            const latest = (history.runs || [])[0];
            if (!latest) { $('resultsCaption').textContent = ''; resultsStates('before'); return; }
            const run = (await ui.fetchJson(api, { action: 'run_get', id: latest.id })).run;
            if (ticket !== state.contextTicket) return;
            showRun(run, 'Stored run from ' + dateTime(run.created_at) + ' by ' + personName(run));
        } catch (error) {
            if (ticket !== state.contextTicket) return;
            resultsStates('before');
            ui.showToast(error.message || 'The version could not be loaded.', 'error');
        }
    }
    $('runValidation')?.addEventListener('click', async () => {
        const version = versionId();
        if (!version) return;
        const button = $('runValidation');
        button.disabled = true;
        const data = new FormData();
        data.set('action', 'validation_run');
        data.set('curriculum_version_id', version);
        if ($('runIncludeDemo')?.checked) data.set('include_demo', '1');
        try {
            const payload = await ui.postForm(api, data);
            // The empty state and the caption already carry the message, so no toast repeats it (a toast would also cover the title).
            if (!payload.run) { state.run = null; $('resultsCaption').textContent = ''; resultsStates('norules'); }
            else showRun(payload.run, 'Run just now. ' + payload.message);
        } catch (error) { ui.showToast(error.message, 'error'); }
        finally { button.disabled = !versionId(); }
    });

    // ---------- dialog shell (read-only details, and the create / edit forms) ----------
    const dialogEl = $('complianceDialog');
    const dialog = ui.createModal(dialogEl, { restoreFocus: () => $('complianceViews').querySelector('a.is-active') });
    const form = $('complianceForm');
    function setDialog(title, context, body, buttons, wide) {
        $('complianceDialogTitle').textContent = title;
        $('complianceDialogContext').innerHTML = context || '';
        $('complianceDialogBody').innerHTML = body;
        dialogEl.querySelector('.modal-dialog').classList.toggle('compliance-dialog--wide', Boolean(wide));
        const footer = $('complianceDialogFooter');
        footer.innerHTML = '';
        buttons.forEach(spec => {
            const button = document.createElement('button');
            button.type = spec.submit ? 'submit' : 'button';
            button.className = spec.cls || 'button-muted';
            button.innerHTML = spec.html;
            if (spec.id) button.id = spec.id;
            if (spec.click) button.addEventListener('click', spec.click);
            footer.appendChild(button);
        });
    }
    const closeButton = { html: 'Close', click: () => closeDialog() };
    function closeDialog() { state.dialog = null; dialog.close(); window.CSMSCIMF?.updateContext?.(); }
    $('complianceDialogX').addEventListener('click', closeDialog);
    $('complianceBackdrop').addEventListener('click', closeDialog);
    dialogEl.addEventListener('keydown', event => { if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeDialog(); } }, true);
    const detail = rows => '<dl class="csms-detail-list compliance-detail">' + rows.filter(Boolean).map(([label, value]) => '<dt>' + esc(label) + '</dt><dd>' + value + '</dd>').join('') + '</dl>';
    const field = (label, control, hint) => '<label class="form-field"><span>' + esc(label) + '</span>' + control + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</label>';
    const select = (name, options, current) => '<select class="csms-select" name="' + name + '">' + Object.entries(options).map(([value, label]) => '<option value="' + esc(value) + '"' + (String(current) === value ? ' selected' : '') + '>' + esc(label) + '</option>').join('') + '</select>';
    const picker = (id, label, placeholder, hint) => '<div class="form-field"><label for="' + id + 'Search"><span>' + esc(label) + '</span></label><span class="record-autocomplete"><input type="search" id="' + id + 'Search" placeholder="' + esc(placeholder) + '" autocomplete="off"/><input type="hidden" name="' + id + '" id="' + id + 'Id"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear ' + esc(label) + '" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><span class="record-autocomplete-options" id="' + id + 'Options" role="listbox" aria-label="' + esc(label) + ' suggestions" hidden></span></span>' + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</div>';
    function makePicker(id, action, key, format, extra) {
        return ui.createAutocomplete({ input: $(id + 'Search'), valueInput: $(id + 'Id'), list: $(id + 'Options'), minimumLength: 0, delay: 250, loadingText: 'Searching…', noResultsText: 'Nothing matches.',
            search: async query => (await ui.fetchJson(api, { action, page: 1, per_page: 20, search: query, ...(extra ? extra() : {}) }))[key] || [], getId: item => item.id, format });
    }
    const afterSave = async message => { ui.showToast(message, 'success'); closeDialog(); await Promise.all(Object.values(lists).filter(list => list.loaded).map(list => list.load())); };
    async function submitForm(action, extra) {
        ui.clearFormError(form);
        const data = new FormData(form);
        data.set('action', action);
        Object.entries(extra || {}).forEach(([key, value]) => data.set(key, value));
        const submit = $('complianceDialogFooter').querySelector('[type="submit"]');
        if (submit) submit.disabled = true;
        try { const payload = await ui.postForm(api, data); await afterSave(payload.message); }
        catch (error) { ui.showFormError(form, error.message); }
        finally { if (submit) submit.disabled = false; }
    }
    form.addEventListener('submit', event => { event.preventDefault(); state.dialog?.submit?.(); });
    const errorBox = '<div class="form-error" role="alert" hidden></div>';

    // ---------- rule dialogs ----------
    // Library rules are read-only: a manager can only switch one off or on again; its numbers and text never change here.
    async function openLibraryRule(id) {
        try {
            const rule = (await ui.fetchJson(api, { action: 'library_rule_get', id })).rule;
            state.dialog = { kind: 'libraryRule', rule };
            const hasSource = Boolean(rule.reference_code || rule.reference_title);
            const source = hasSource ? [rule.reference_code || rule.reference_title, rule.issuing_body, rule.reference_year].filter(Boolean).join(' · ') : (rule.source_label || '');
            const toggle = Number(rule.active) === 1 ? ['Deactivate rule', '0', 'fa-ban'] : ['Activate rule', '1', 'fa-check'];
            setDialog('Library rule', esc(rule.title), errorBox + detail([
                ['Rule', esc(rule.title)], ['Requirement', esc(rule.requirement_text)], ['Source', esc(source)],
                hasSource ? ['Source status', chip(SOURCE_STATUS[rule.verification_status] || SOURCE_STATUS.draft)] : null,
                ['Section', esc(rule.section_label)], ['Page', rule.source_page ? 'p. ' + esc(rule.source_page) : '<span class="csms-sub">Page not recorded</span>'],
                ['Source class', esc(SOURCE_CLASS[rule.source_class] || rule.source_class)], ['Applies to', esc(appliesTo(rule))],
                ['How it is used', esc(MODE_LABEL[rule.evaluation_mode] || rule.evaluation_mode)], ['Status', esc(Number(rule.active) === 1 ? 'Active' : 'Inactive')],
                rule.note ? ['Note', esc(rule.note)] : null
            ]) + '<p class="compliance-evidence-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Library rules are recorded from the sources listed above and are not edited here. Pending rules are never evaluated, and no overall verdict is given.</p>',
            [closeButton, canManage ? { html: '<i class="fa-solid ' + toggle[2] + '" aria-hidden="true"></i> ' + toggle[0], click: async () => {
                const data = new FormData(); data.set('action', 'library_rule_active'); data.set('id', rule.id); data.set('active', toggle[1]);
                try { const payload = await ui.postForm(api, data); await afterSave(payload.message); } catch (error) { ui.showFormError(form, error.message); }
            } } : null].filter(Boolean), true);
            dialog.open();
        } catch (error) { ui.showToast(error.message, 'error'); }
    }

    async function openRule(id) {
        try {
            const rule = (await ui.fetchJson(api, { action: 'rule_get', id })).rule;
            state.dialog = { kind: 'rule', rule };
            const source = [rule.reference_code || rule.reference_title, rule.issuing_body, rule.reference_year].filter(Boolean).join(' · ');
            setDialog('Rule', esc(rule.rule_name), errorBox + detail([
                ['Rule', esc(rule.rule_name) + (Number(rule.is_demo) ? ' ' + demoChip : '')], ['Type', esc(RULE_TYPES[rule.rule_type] || rule.rule_type)], ['Requirement', esc(requirementText(rule))],
                ['Source', esc(source) + (rule.source_url ? ' <a href="' + esc(rule.source_url) + '" target="_blank" rel="noopener noreferrer">link</a>' : '')],
                ['Source status', chip(SOURCE_STATUS[rule.verification_status] || SOURCE_STATUS.draft)], ['Applies to', esc(appliesTo(rule))], ['Status', esc(rule.technical_status === 'active' ? 'Active' : 'Inactive')],
                rule.description ? ['Description', esc(rule.description)] : null, ['Used in runs', Number(rule.has_history) ? 'Yes: its type, number, subject and scope can no longer be changed' : 'Not yet']
            ]) + '<p class="compliance-evidence-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> A rule is checked against a version only when it is active and its source is confirmed.</p>',
            [closeButton, canManage ? { html: '<i class="fa-solid fa-pen" aria-hidden="true"></i> Edit rule', click: () => openRuleForm(rule) } : null].filter(Boolean));
            dialog.open();
            window.CSMSCIMF?.updateContext?.();
        } catch (error) { ui.showToast(error.message, 'error'); }
    }
    function openRuleForm(rule) {
        const edit = Boolean(rule);
        const locked = edit && Number(rule.has_history);
        state.dialog = { kind: 'ruleForm', rule, submit: () => submitForm(edit ? 'rule_update' : 'rule_create', edit ? { id: rule.id } : {}) };
        setDialog(edit ? 'Edit rule' : 'Add rule', '', errorBox + (locked ? '<p class="compliance-evidence-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> This rule has validation history. Its source, scope, type, subject and number can no longer be changed; create a new rule instead.</p>' : '') +
            '<div class="compliance-form-grid">' + picker('compliance_reference_id', 'Source', 'Search code or title', 'Only rules from a confirmed source are checked.') +
            field('Rule name', '<input type="text" name="rule_name" maxlength="180" value="' + esc(rule?.rule_name || '') + '"/>') +
            field('Rule type', select('rule_type', RULE_TYPES, rule?.rule_type || 'MIN_TOTAL_UNITS')) +
            '<div id="ruleNumberField">' + field('Number (units)', '<input type="text" inputmode="decimal" name="numeric_value" value="' + esc(rule?.numeric_value === null || rule?.numeric_value === undefined ? '' : num(rule.numeric_value)) + '"/>', 'Total units are the units of all placements of the version.') + '</div>' +
            '<div id="ruleSubjectField">' + picker('subject_id', 'Subject', 'Search subject code or title') + '</div>' +
            field('Applies to', select('scope_type', { program: 'An academic program', curriculum: 'One curriculum' }, rule?.curriculum_id ? 'curriculum' : 'program')) + '<div id="ruleScopeField">' + picker('scope_id', 'Program or curriculum', 'Search') + '</div>' +
            field('Status', select('technical_status', { active: 'Active', inactive: 'Inactive' }, rule?.technical_status || 'active')) +
            '<div class="form-field-wide">' + field('Description (optional)', '<textarea name="description" rows="2" maxlength="4000">' + esc(rule?.description || '') + '</textarea>') + '</div></div>',
            [{ html: 'Cancel', click: closeDialog }, { html: edit ? 'Save rule' : 'Add rule', cls: 'primary-action-button', submit: true }], true);
        dialog.open();
        const refPicker = makePicker('compliance_reference_id', 'reference_list', 'references', item => ({ label: [item.reference_code || item.title, item.issuing_body].filter(Boolean).join(' — '), code: item.reference_code || '', title: item.issuing_body || '' }));
        const subjectPicker = makePicker('subject_id', 'subject_options', 'subjects', item => ({ label: item.subject_code + ' — ' + item.subject_title, code: item.subject_code, title: item.subject_title }));
        let scopePicker = null;
        const typeSelect = form.elements.rule_type, scopeSelect = form.elements.scope_type;
        const syncType = () => {
            const type = typeSelect.value;
            $('ruleNumberField').hidden = type === 'REQUIRED_SUBJECT';
            $('ruleSubjectField').hidden = !['REQUIRED_SUBJECT', 'MIN_SUBJECT_UNITS', 'MAX_SUBJECT_UNITS'].includes(type);
        };
        const syncScope = () => {
            const isProgram = scopeSelect.value === 'program';
            $('scope_idSearch').value = ''; $('scope_idId').value = '';
            scopePicker = makePicker('scope_id', isProgram ? 'program_options' : 'curriculum_options', isProgram ? 'programs' : 'curricula',
                item => isProgram ? { label: item.program_code + ' — ' + item.program_name, code: item.program_code, title: item.program_name } : { label: item.curriculum_code + ' — ' + item.curriculum_name, code: item.curriculum_code, title: item.curriculum_name });
            $('scope_idSearch').placeholder = isProgram ? 'Search program code or name' : 'Search curriculum code or name';
        };
        typeSelect.addEventListener('change', syncType); scopeSelect.addEventListener('change', syncScope);
        syncType(); syncScope();
        if (edit) {
            refPicker?.setSelected({ id: rule.compliance_reference_id, reference_code: rule.reference_code, title: rule.reference_title, issuing_body: rule.issuing_body }, false);
            if (rule.subject_id) subjectPicker?.setSelected({ id: rule.subject_id, subject_code: rule.subject_code, subject_title: rule.subject_title }, false);
            if (rule.academic_program_id) scopePicker?.setSelected({ id: rule.academic_program_id, program_code: rule.program_code, program_name: rule.program_name }, false);
            else if (rule.curriculum_id) scopePicker?.setSelected({ id: rule.curriculum_id, curriculum_code: rule.curriculum_code, curriculum_name: rule.curriculum_name }, false);
        }
    }
    $('ruleAdd')?.addEventListener('click', () => openRuleForm(null));

    // ---------- source dialogs ----------
    async function openSource(id) {
        try {
            const ref = (await ui.fetchJson(api, { action: 'reference_get', id })).reference;
            state.dialog = { kind: 'source', reference: ref };
            const events = (ref.evidence_events || []).map(e => '<tr><td>' + esc(dateTime(e.created_at)) + '</td><td>' + esc(SUBJECTS[e.verification_subject] || e.verification_subject) + '</td><td>' + chip(EVIDENCE_RESULT[e.result] || EVIDENCE_RESULT.not_assessed) + '</td><td>' + (e.evidence_locator ? esc(e.evidence_locator) : '—') +
                (e.evidence_sha256 ? '<span class="csms-sub">SHA-256 ' + esc(e.evidence_sha256) + '</span>' : '') + '</td><td>' + esc(e.rationale) + '<span class="csms-sub">' + esc(e.recorded_by_username || '') + '</span></td></tr>').join('');
            setDialog('Source', esc(ref.reference_code || ref.title), errorBox + detail([
                ['Code', esc(ref.reference_code || '—') + (Number(ref.is_demo) ? ' ' + demoChip : '')], ['Title', esc(ref.title || '—')], ['Issuing body', esc(ref.issuing_body) + ' (' + esc(CATEGORIES[ref.reference_type] || ref.reference_type) + ')'],
                ['Year', esc(ref.reference_year || '—') + (ref.effective_date ? ' <span class="csms-sub">effective ' + esc(ref.effective_date) + '</span>' : '')], ['Kind', esc(SOURCE_KINDS[ref.source_kind] || ref.source_kind)],
                ['Source status', chip(SOURCE_STATUS[ref.verification_status] || SOURCE_STATUS.draft)], ['Link', ref.source_url ? '<a href="' + esc(ref.source_url) + '" target="_blank" rel="noopener noreferrer">' + esc(ref.source_url) + '</a>' : '—'],
                ref.notes ? ['Notes', esc(ref.notes)] : null, ['Rules using it', Number(ref.rule_count || 0)]
            ]) + '<h3 class="compliance-section">Evidence</h3><p class="compliance-evidence-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Evidence is a locator and fingerprint recorded by a person. It is not a stored document. A source is confirmed only after a recorded, confirmed document-identity assessment; institutional adoption is not assessed.</p>' +
                (events ? '<div class="csms-page-table table-scroll" tabindex="0" aria-label="Evidence events"><table class="csms-ctable"><thead><tr><th>Recorded</th><th>About</th><th>Result</th><th>Locator and fingerprint</th><th>Rationale</th></tr></thead><tbody>' + events + '</tbody></table></div>' : '<p class="compliance-none">No evidence has been recorded for this source yet.</p>'),
            [closeButton, canVerify ? { html: '<i class="fa-solid fa-stamp" aria-hidden="true"></i> Record evidence', click: () => openEvidenceForm(ref) } : null, canManage ? { html: '<i class="fa-solid fa-pen" aria-hidden="true"></i> Edit source', click: () => openSourceForm(ref) } : null].filter(Boolean), true);
            dialog.open();
            window.CSMSCIMF?.updateContext?.();
        } catch (error) { ui.showToast(error.message, 'error'); }
    }
    function openSourceForm(ref) {
        const edit = Boolean(ref);
        const statuses = edit ? { draft: 'Draft', verified: 'Source confirmed', inactive: 'Inactive' } : { draft: 'Draft', inactive: 'Inactive' };
        state.dialog = { kind: 'sourceForm', reference: ref, submit: () => submitForm(edit ? 'reference_update' : 'reference_create', edit ? { id: ref.id, supersedes_reference_id: ref.supersedes_reference_id || '' } : {}) };
        setDialog(edit ? 'Edit source' : 'Add source', '', errorBox + '<p class="compliance-evidence-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> A new source starts as Draft. "Source confirmed" is reached by recording a confirmed document-identity evidence assessment.</p>' +
            '<div class="compliance-form-grid">' + field('Category', select('reference_type', CATEGORIES, ref?.reference_type || 'CHED')) + field('Document kind', select('source_kind', SOURCE_KINDS, ref?.source_kind || 'regulatory_document')) +
            field('Code (for example CMO 25 s.2015)', '<input type="text" name="reference_code" maxlength="120" value="' + esc(ref?.reference_code || '') + '"/>') + field('Title', '<input type="text" name="title" maxlength="255" value="' + esc(ref?.title || '') + '"/>') +
            field('Issuing body', '<input type="text" name="issuing_body" maxlength="180" value="' + esc(ref?.issuing_body || '') + '"/>') + field('Effective date (optional)', '<input type="date" name="effective_date" value="' + esc(ref?.effective_date || '') + '"/>') +
            field('Link (optional, http or https)', '<input type="url" name="source_url" maxlength="1000" value="' + esc(ref?.source_url || '') + '"/>') + field('Source status', select('verification_status', statuses, ref?.verification_status || 'draft')) +
            '<div class="form-field-wide">' + field('Notes (optional)', '<textarea name="notes" rows="2">' + esc(ref?.notes || '') + '</textarea>') + '</div></div>',
            [{ html: 'Cancel', click: closeDialog }, { html: edit ? 'Save source' : 'Add source', cls: 'primary-action-button', submit: true }], true);
        dialog.open();
    }
    $('sourceAdd')?.addEventListener('click', () => openSourceForm(null));
    function openEvidenceForm(ref) {
        state.dialog = { kind: 'evidence', reference: ref, submit: () => submitForm('reference_evidence', { id: ref.id }) };
        setDialog('Record evidence', esc(ref.reference_code || ref.title), errorBox + '<p class="compliance-evidence-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Evidence is a locator and fingerprint recorded by a person. It is not a stored document. Nothing is uploaded.</p>' +
            '<div class="compliance-form-grid">' + field('About', select('verification_subject', SUBJECTS, 'document_identity')) + field('Result', select('result', { confirmed: 'Confirmed', not_confirmed: 'Not confirmed', not_assessed: 'Not assessed' }, 'confirmed')) +
            '<div class="form-field-wide">' + field('Locator (file name, address or page)', '<input type="text" name="evidence_locator" maxlength="1000"/>') + '</div>' + '<div class="form-field-wide">' + field('SHA-256 fingerprint (64 hexadecimal characters, optional)', '<input type="text" name="evidence_sha256" maxlength="64" autocomplete="off"/>') + '</div>' +
            '<div class="form-field-wide">' + field('Rationale', '<textarea name="rationale" rows="3" maxlength="2000"></textarea>') + '</div></div>',
            [{ html: 'Cancel', click: () => openSource(ref.id) }, { html: 'Record assessment', cls: 'primary-action-button', submit: true }], true);
        dialog.open();
    }

    // ---------- run dialog (History) ----------
    async function openRun(id) {
        try {
            const run = (await ui.fetchJson(api, { action: 'run_get', id })).run;
            state.dialog = { kind: 'run', run };
            setDialog('Validation run', esc(run.program_code + ' · ' + run.curriculum_code + ' · ' + run.version_label), detail([['Run', esc(dateTime(run.created_at)) + ' by ' + esc(personName(run))]]) + '<div class="compliance-counts">' + countsHtml(run) + '</div>' +
                '<div class="csms-page-table table-scroll" tabindex="0" aria-label="Results of this run"><table class="csms-ctable"><thead><tr><th>Rule</th><th>Source</th><th>Requirement</th><th>Actual</th><th>Result</th><th>Explanation</th></tr></thead><tbody>' + (run.results || []).map(resultRow).join('') + '</tbody></table></div>', [closeButton], true);
            dialog.open();
            window.CSMSCIMF?.updateContext?.();
        } catch (error) { ui.showToast(error.message, 'error'); }
    }

    // ---------- CIMF reads what the page shows; it never runs or changes validation ----------
    window.CSMSPageCIMFContext = () => {
        const out = { academic_program_id: programId(), curriculum_id: curriculumId(), curriculum_version_id: versionId() };
        const d = state.dialog;
        if (d?.kind === 'rule') out.rule_id = d.rule.id;
        else if (d?.kind === 'source') out.reference_id = d.reference.id;
        else if (d?.kind === 'run') { out.run_id = d.run.id; out.curriculum_version_id = d.run.curriculum_version_id; }
        else if (state.view === 'results' && state.run) out.run_id = state.run.id;
        return out;
    };

    // ---------- start ----------
    window.addEventListener('popstate', () => { const view = new URLSearchParams(window.location.search).get('view') || 'results'; showView(view, false); });
    async function start() {
        showView(state.view, false);
        if (state.view === 'results') resultsStates('loading');
        try {
            if (!(await ui.fetchJson(api, { action: 'context_options', level: 'program', search: '' })).options.length && state.view === 'results') { resultsStates('noVersion'); }
        } catch (error) { ui.showToast(error.message || 'Programs could not be loaded.', 'error'); }
        setTimeout(() => { if (state.view === 'results' && !versionId() && !state.run) resultsStates('noVersion'); }, 4000);
    }
    start();
}());
