(function () {
    'use strict';
    const ui = window.CurriculumUI, $ = id => document.getElementById(id), esc = ui.escapeHtml;
    const root = $('prerequisiteWorkspace'), api = root.dataset.requirementApi, manage = root.dataset.canManageRequirements === '1';
    const state = { placements: [], edges: [], review: {}, context: [null, null, null], request: 0, focus: null, dir: 'requires', target: null, draft: [], expected: [], editing: false, remove: null, loaded: false };
    window.CSMSPageCIMFContext = () => ({academic_program_id:$('programId').value,curriculum_id:$('curriculumId').value,curriculum_version_id:$('versionId').value,subject_id:$('focusId').value});
    window.PrerequisiteCIMFContext = () => ({ versionId: $('versionId').value, revision: state.request, context: state.context, placements: state.placements, loaded: state.loaded });
    const row = id => state.placements.find(p => Number(p.subject_id) === Number(id));
    const rules = id => state.edges.filter(e => Number(e.subject_id) === Number(id));
    const dependents = id => [...new Set(state.edges.filter(e => Number(e.required_subject_id) === Number(id)).map(e => Number(e.subject_id)))];
    const placement = p => [p?.year_level || 'Missing year / grade', p?.semester || 'Missing semester'].join(' · ');
    const subject = (id, showPlacementLabel = false) => { const p = row(id); return '<div class="pr-subject"><strong>' + esc(p?.subject_code || 'Subject #' + id) + '</strong><span>' + esc(p?.subject_title || '') + '</span><small>' + (showPlacementLabel === true ? 'Placement \u00b7 ' : '') + esc(placement(p)) + '</small></div>'; };
    const builderUrl = () => 'curriculum_builder.php?' + new URLSearchParams({ program_id: $('programId').value, curriculum_id: $('curriculumId').value, version_id: $('versionId').value });
    const controls = (id, name, search, onSelect, extra = {}) => ({ input: $(id + 'Search'), valueInput: $(id + 'Id'), list: $(id + 'Options'), minimumLength: 0, showSuggestionsOnFocus: true, showSuggestionsOnEnable: true, placeholder: 'Search ' + name,
        search: async q => await search(q), onSelect, ...extra });
    const cascade = ui.createCascadingAutocomplete({ restoreSavedContext: !new URLSearchParams(location.search).has('version_id'), levels: [
        controls('program', 'program code or name', async q => (await ui.fetchJson(root.dataset.programApi, { action: 'list', search: q, per_page: 50 })).programs, null, { getId: p => p.id, format: p => ({ label: p.program_code + ' — ' + p.program_name, code: p.program_code, title: p.program_name }) }),
        controls('curriculum', 'curriculum code or name', async q => (await ui.fetchJson(root.dataset.curriculumApi, { action: 'list', search: q, academic_program_id: $('programId').value, per_page: 50 })).curricula, null, { disabledPlaceholder: 'Choose program first', getId: p => p.id, format: p => ({ label: p.curriculum_code, code: p.curriculum_code, title: p.curriculum_name }) }),
        controls('version', 'version', async q => (await ui.fetchJson(root.dataset.versionApi, { action: 'list', search: q, curriculum_id: $('curriculumId').value, per_page: 50 })).versions, null, { disabledPlaceholder: 'Choose curriculum first', getId: p => p.id, format: p => ({ label: p.version_label, code: p.version_label, title: p.effective_academic_year || '' }) })
    ], onChange(index, item) { state.context[index] = item; for (let i = index + 1; i < 3; i++) state.context[i] = null; load(); } });
    const findSubjects = q => ui.searchRecords(state.placements,q,p=>[p.subject_code,p.subject_title]);
    // A prerequisite must be placed in an earlier term than its target (period_position from the server; never parsed from labels).
    const position = id => { const v = row(id)?.period_position; return v === null || v === undefined ? null : Number(v); };
    const currentTarget = () => state.editing ? state.target : Number($('targetId')?.value || 0);
    function earlier(targetId) {
        const at = position(targetId);
        if (!targetId || at === null) return [];
        return state.placements.filter(p => Number(p.subject_id) !== Number(targetId) && position(p.subject_id) !== null && position(p.subject_id) < at && !state.draft.includes(Number(p.subject_id)));
    }
    const findEarlier = q => ui.searchRecords(earlier(currentTarget()), q, p => [p.subject_code, p.subject_title]);
    function updateHint() {
        const hint = $('requiredHint'), t = currentTarget();
        if (!hint) return;
        const none = t && !earlier(t).length;
        hint.hidden = !none;
        hint.textContent = !none ? '' : position(t) === null ? "This subject's year or semester is incomplete. Set it in the Curriculum Builder first." : 'No earlier-term subjects in this version.';
    }
    const subjectControl = (id, callback, search = findSubjects) => ui.createAutocomplete(controls(id, 'subject code or title', search, callback, { getId: p => p.subject_id, format: p => ({ label: p.subject_code + ' — ' + p.subject_title, code: p.subject_code, title: p.subject_title, meta: placement(p) }), onClear: () => { if (id === 'focus') { state.focus = null; renderChain(); } else preview(); } }));
    const focus = subjectControl('focus', p => { state.focus = Number(p.subject_id); $('focusSearch').title = p.subject_code + ' — ' + p.subject_title; renderChain(); });
    const target = manage ? subjectControl('target', () => { required.clear(false); updateHint(); preview(); }) : null, required = manage ? subjectControl('required', preview, findEarlier) : null;
    const dialogs = {}; ['details', 'editor', 'remove', 'warn'].forEach(name => dialogs[name] = ui.createModal($(name + 'Modal')));
    function options(id, values, label) { const old = $(id).value; $(id).innerHTML = '<option value="">' + label + '</option>' + values.map(v => '<option value="' + esc(v) + '">' + esc(v) + '</option>').join(''); if (values.includes(old)) $(id).value = old; }
    async function load() {
        const request = ++state.request, version = $('versionId').value;
        root.dataset.contextReady = version && state.context.every(Boolean) ? '1' : '0';
        state.loaded = false; state.placements = []; state.edges = []; state.review = {}; state.focus = null; focus.clear(false);
        ['details', 'editor', 'remove', 'warn'].forEach(n => dialogs[n]?.close());
        if ($('addRequirementButton')) $('addRequirementButton').disabled = true;
        $('workspaceStatus').textContent = version ? 'Loading prerequisites…' : '';
        render();
        if (!version) return;
        try {
            const data = await ui.fetchJson(api, { action: 'workspace', curriculum_version_id: version });
            if (request !== state.request) return;
            state.loaded = true; state.placements = data.placements; state.edges = data.relationships; state.review = data.review;
            document.dispatchEvent?.(new Event('prerequisite-context-loaded'));
            const shs = /shs|senior high|strand/i.test(JSON.stringify(state.context[0])) || state.placements.some(p => /^grade /i.test(p.year_level || ''));
            options('yearFilter', [...new Set([...(shs ? ['Grade 11', 'Grade 12'] : ['1st Year', '2nd Year', '3rd Year', '4th Year']), ...state.placements.map(p => p.year_level).filter(Boolean)])], shs ? 'All Grades' : 'All Years');
            options('semesterFilter', [...new Set(state.placements.map(p => p.semester).filter(Boolean))], 'All Semesters');
            $('workspaceStatus').textContent = '';
            if ($('addRequirementButton')) $('addRequirementButton').disabled = state.placements.length < 2;
            render();
        } catch (e) { if (request === state.request) $('workspaceStatus').textContent = e.message; }
    }
    function render() { renderRules(); renderChain(); renderReview(); }
    const groupView = window.CurriculumPlacementView;
    const codeText = p => esc(p?.subject_code || '').replaceAll('/', '/<wbr>');
    // Issues for one subject, from the server's review (sequencing and missing placement also cover same-term). Messages are the server's.
    function issuesFor(id) {
        const out = [], r = state.review || {};
        (r.cycles || []).forEach(c => { if (c.map(Number).includes(Number(id))) out.push({ kind: 'Circular dependency', text: c.map(n => row(n)?.subject_code || n).join(' \u2192 ') }); });
        ['duplicates', 'sequencing', 'missing'].forEach(key => (r[key] || []).forEach(e => {
            if (Number(e.subject_id) !== Number(id) && Number(e.required_subject_id) !== Number(id)) return;
            const label = { duplicates: 'Duplicate rule', sequencing: 'Sequencing conflict', missing: 'Missing placement' }[key];
            const text = key === 'sequencing' ? (e.message || 'A prerequisite must be placed in an earlier term.') : key === 'missing' ? 'Placement information is incomplete for ' + (row(e.required_subject_id)?.subject_code || e.required_subject_id) + ' \u2192 ' + (row(e.subject_id)?.subject_code || e.subject_id) + '.' : (row(e.subject_id)?.subject_code || e.subject_id) + ' lists ' + (row(e.required_subject_id)?.subject_code || e.required_subject_id) + ' more than once.';
            out.push({ kind: label, text });
        }));
        return out;
    }
    function renderRules() {
        const q = $('ruleSearch').value.trim().toLowerCase(), year = $('yearFilter').value, term = $('semesterFilter').value, configured = $('stateFilter').value;
        const rows = ui.searchRecords(state.placements, q, p => [p.subject_code, p.subject_title]).filter(p => (!year || p.year_level === year) && (!term || p.semester === term) && (!configured || (rules(p.subject_id).length > 0) === (configured === 'with')));
        const withRules = rows.filter(p => rules(p.subject_id).length).length;
        $('ruleSummary').innerHTML = state.loaded ? '<strong>' + rows.length + '</strong>' + (rows.length === 1 ? ' subject' : ' subjects') + ' \u00b7 <strong>' + withRules + '</strong> with rules \u00b7 <strong>' + (rows.length - withRules) + '</strong> without' : '';
        if (!rows.length) { $('ruleCards').innerHTML = '<div class="csms-empty"><i class="fa-solid fa-diagram-project" aria-hidden="true"></i><strong>No subjects match the selected context and filters.</strong></div>'; return; }
        let html = '';
        groupView.group(rows).years.forEach((semesters, yearKey) => semesters.forEach((bucket, semesterKey) => {
            html += '<div class="csms-term"><header><strong>' + esc(groupView.yearLabel(yearKey) || 'Year level not specified') + ' \u00b7 ' + esc(semesterKey || 'Semester not specified') + '</strong><span class="csms-term__count">' + bucket.rows.length + (bucket.rows.length === 1 ? ' subject' : ' subjects') + '</span></header><div class="csms-table-scroll"><table class="csms-ctable pr-table"><thead><tr><th class="csms-ctable__code" scope="col">Code</th><th scope="col">Subject</th><th scope="col">Requires</th><th class="csms-ctable__action" scope="col"><span class="sr-only">Issues</span></th><th class="csms-ctable__action" scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
            bucket.rows.forEach(p => {
                const id = Number(p.subject_id), edges = rules(id), issues = issuesFor(id);
                html += '<tr class="csms-row-click" tabindex="0" data-row="' + id + '" aria-haspopup="dialog" aria-label="' + (manage ? 'Edit' : 'View') + ' prerequisite rule for ' + esc(p.subject_code) + '"><td class="csms-ctable__code">' + codeText(p) + '</td><td>' + esc(p.subject_title) + '</td><td>' + (edges.length ? edges.map(e => codeText(row(e.required_subject_id)) || esc(e.required_subject_id)).join(', ') : '<span class="pr-muted">\u2013</span>') + '</td><td class="csms-ctable__action">' + (issues.length ? '<button type="button" class="csms-warn" data-warn="' + id + '" aria-label="' + issues.length + (issues.length === 1 ? ' issue' : ' issues') + ' for ' + esc(p.subject_code) + '" title="' + issues.length + (issues.length === 1 ? ' issue' : ' issues') + '"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></button>' : '') + '</td><td class="csms-ctable__action"><button type="button" class="csms-rowmenu" data-menu="' + id + '" aria-haspopup="menu" aria-label="Actions for ' + esc(p.subject_code) + '"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button></td></tr>';
            });
            html += '</tbody></table></div></div>';
        }));
        $('ruleCards').innerHTML = html;
    }
    function openWarn(id) {
        const issues = issuesFor(id), p = row(id);
        state.target = id;
        $('warnTitle').textContent = (p?.subject_code || 'Subject') + ' \u00b7 ' + issues.length + (issues.length === 1 ? ' issue' : ' issues');
        $('warnBody').innerHTML = issues.map(i => '<div class="pr-issue"><strong>' + esc(i.kind) + '</strong><p>' + esc(i.text) + '</p></div>').join('') || '<p class="pr-muted">No issues.</p>';
        if ($('warnEdit')) $('warnEdit').hidden = false;
        dialogs.warn.open();
    }
    // Row menu: the shared CsmsMenu; Edit (or View), Remove, View in chain.
    const rowMenu = window.CsmsMenu.create($('prRowMenu'), { esc, onSelect: (action, data) => menuAction(action, Number(data.subject)) });
    function openMenu(button) {
        const id = Number(button.dataset.menu), edges = rules(id), data = { subject: id };
        rowMenu.open(button, [{ action: manage ? 'edit' : 'view', label: manage ? 'Edit rule' : 'View rule', data }, ...(manage && edges.length ? [{ action: 'remove', label: 'Remove rule', data, danger: true }] : []), { action: 'chain', label: 'View in chain', data }]);
    }
    function menuAction(action, id) {
        if (action === 'edit') openEditor(id); else if (action === 'view') details(id); else if (action === 'remove') confirmRemoval(id);
        else if (action === 'chain') { showTab('chain'); focus.setSelected(row(id)); }
    }
    function showTab(name) {
        document.querySelectorAll('[data-tab]').forEach(tab => { const active = tab.dataset.tab === name; tab.setAttribute('aria-selected', String(active)); tab.tabIndex = active ? 0 : -1; $('panel-' + tab.dataset.tab).hidden = !active; });
        if (name === 'chain') renderChain();
    }
    function details(id) {
        state.target = id; const edges = rules(id), deps = dependents(id);
        $('detailsBody').innerHTML = subject(id) + '<h3 class="eyebrow">REQUIRED BEFORE THIS SUBJECT</h3>' + (edges.map(e => subject(e.required_subject_id)).join('') || '<p>No prerequisite configured</p>') + '<h3 class="eyebrow">SUBJECTS THAT DEPEND ON THIS SUBJECT</h3>' + (deps.map(subject).join('') || '<p>No subjects currently depend on this subject.</p>') + '<button type="button" class="button-muted" id="cimfSubjectReview" data-cimf-subject="' + id + '">AI Review</button>';
        if ($('detailsRemove')) $('detailsRemove').disabled = !edges.length;
        dialogs.details.open();
    }
    function openEditor(id = null) {
        if (!manage || !$('versionId').value) return;
        dialogs.details.close(); state.editing = id !== null; state.target = id;
        state.draft = [...new Set(rules(id).map(e => Number(e.required_subject_id)))]; state.expected = rules(id).map(e => Number(e.id));
        $('ruleForm').reset(); target.clear(false); required.clear(false); ui.clearFormError($('ruleForm'));
        $('editorModalTitle').textContent = id === null ? 'Add Prerequisite' : 'Edit Prerequisite Rule';
        $('targetField').hidden = id !== null; $('fixedTarget').hidden = id === null;
        $('fixedTarget').innerHTML = id === null ? '' : '<p class="eyebrow">TARGET SUBJECT</p>' + subject(id);
        $('appendRequired').hidden = id === null; $('saveRule').textContent = id === null ? 'Save Prerequisite' : 'Save Changes';
        renderDraft(); preview(); updateHint(); dialogs.editor.open();
    }
    function renderDraft() { updateHint(); $('editorUnlocks').innerHTML = state.editing ? '<p class="eyebrow">UNLOCKS (READ-ONLY)</p><p class="pr-muted">' + (dependents(state.target).map(id => esc(row(id)?.subject_code || id)).join(', ') || 'No subjects depend on this subject.') + '</p>' : ''; $('existingRequired').innerHTML = state.editing ? '<p class="eyebrow">REQUIRES</p>' + state.draft.map(id => '<div class="pr-draft-row">' + subject(id) + '<button type="button" class="pr-link pr-danger" data-draft-remove="' + id + '">Remove</button></div>').join('') : ''; }
    let sequenceRequest = 0;
    async function preview() {
        if (!manage) return;
        const request = ++sequenceRequest;
        $('saveRule').disabled = true;
        const t = state.editing ? state.target : Number($('targetId').value), r = Number($('requiredId').value);
        const ids = [...state.draft, ...(r ? [r] : [])];
        $('relationshipPreview').innerHTML = t && r ? '<div class="pr-preview"><p class="eyebrow">REQUIRED SUBJECT</p>' + subject(r) + '<p class="pr-arrow">↓<br/>REQUIRED BEFORE<br/>↓</p><p class="eyebrow">TARGET SUBJECT</p>' + subject(t) + '</div>' : '';
        $('sequenceWarning').hidden = true;
        $('editorMapping').hidden = ![t, ...ids].filter(Boolean).some(id => !row(id)?.year_level?.trim() || !row(id)?.semester?.trim());
        $('editorMapping').href = builderUrl();
        if (!t) return;
        try {
            const data = await ui.fetchJson(api, { action: 'preview_sequence', curriculum_version_id: $('versionId').value, subject_id: t, required_ids: ids.join(',') });
            if (request !== sequenceRequest) return;
            $('saveRule').disabled = !data.validation.valid;
            $('sequenceWarning').hidden = data.validation.valid;
            $('sequenceWarning').textContent = data.validation.valid ? '' : (state.editing ? 'Cannot save prerequisite. ' : 'Cannot create prerequisite. ') + data.validation.conflicts.map(c => c.message).join(' ');
        } catch (e) { if (request === sequenceRequest) { $('sequenceWarning').hidden = false; $('sequenceWarning').textContent = e.message; } }
    }
    function append() {
        const id = Number($('requiredId').value); if (!id) return;
        if (state.draft.includes(id)) return ui.showFormError($('ruleForm'), 'This prerequisite relationship already exists.');
        state.draft.push(id); required.clear(false); renderDraft(); preview();
    }
    function confirmRemoval(targetId, requiredId = null) {
        dialogs.details.close();
        state.remove = { targetId, requiredId, draft: requiredId !== null };
        $('removeMessage').innerHTML = '<p>Remove</p>' + (requiredId === null ? rules(targetId).map(e => subject(e.required_subject_id)).join('') : subject(requiredId)) + '<p>as a prerequisite of</p>' + subject(targetId) + (requiredId !== null ? '<p>This change is applied when you save the edited rule.</p>' : '');
        ui.clearFormError($('removeModal')); if (requiredId !== null) dialogs.editor.close(); dialogs.remove.open();
    }
    async function post(values, ids, expected) { const data = new FormData(); Object.entries(values).forEach(([k,v]) => data.set(k,v)); ids?.forEach(id => data.append('required_subject_ids[]',id)); expected?.forEach(id => data.append('expected_ids[]',id)); return ui.postForm(api,data); }
    $('ruleForm')?.addEventListener('submit', async event => {
        event.preventDefault(); const t = state.editing ? state.target : Number($('targetId').value), r = Number($('requiredId').value);
        if (!t || (!state.editing && !r)) return ui.showFormError($('ruleForm'),'Select a target and required subject from the suggestions.');
        if ($('requiredSearch').value.trim() && !r) return ui.showFormError($('ruleForm'), 'Select a required subject from the suggestions or clear the search.');
        if (state.editing && r && state.draft.includes(r)) return ui.showFormError($('ruleForm'),'This prerequisite relationship already exists.');
        $('saveRule').disabled = true; ui.clearFormError($('ruleForm'));
        try {
            await post({ action: state.editing ? 'update_rule' : 'create', curriculum_version_id: $('versionId').value, subject_id: t, required_subject_id: r, requirement_type: 'prerequisite' }, state.editing ? [...state.draft, ...(r ? [r] : [])] : null, state.expected);
            dialogs.editor.close(); ui.showToast('Prerequisite rule saved.', 'success'); await refreshKeepingFocus();
        } catch (e) { ui.showFormError($('ruleForm'), (state.editing ? 'Cannot save prerequisite. ' : 'Cannot create prerequisite. ') + e.message); } finally { preview(); }
    });
    async function refreshKeepingFocus() { const id = state.focus; await load(); if (row(id)) { state.focus = id; focus.setSelected(row(id),false); renderChain(); } }
    $('confirmRemove')?.addEventListener('click', async () => {
        const pending = state.remove; if (!pending) return;
        if (pending.draft) { state.draft = state.draft.filter(id => id !== pending.requiredId); dialogs.remove.close(); renderDraft(); preview(); dialogs.editor.open(); return; }
        $('confirmRemove').disabled = true;
        try { await post({ action: 'update_rule', curriculum_version_id: $('versionId').value, subject_id: pending.targetId }, [], rules(pending.targetId).map(e => e.id)); dialogs.remove.close(); await refreshKeepingFocus(); ui.showToast('Prerequisite rule removed.', 'updated'); }
        catch (e) { ui.showFormError($('removeModal'), e.message); } finally { $('confirmRemove').disabled = false; }
    });
    // Chain layout: columns left to right; arrows always run from the required subject to the subject it is required before.
    const CH_WIDE = { w: 200, h: 78, gapX: 110, gapY: 16, pad: 16 }, CH_NARROW = { w: 150, h: 78, gapX: 64, gapY: 14, pad: 10 };
    function renderChain() {
        const id = state.focus, canvas = $('chainCanvas'), up = state.dir !== 'unlocks';
        canvas.style.transform = ''; canvas.style.width = ''; canvas.style.height = '';
        const narrow = ($('chainViewport')?.clientWidth || 1000) < 600, CH = narrow ? CH_NARROW : CH_WIDE;
        document.querySelectorAll('[data-dir]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.dir === state.dir)));
        const next = n => up ? rules(n).map(e => Number(e.required_subject_id)) : dependents(n);
        const empty = (text, add) => { canvas.innerHTML = '<div class="csms-empty"><i class="fa-solid fa-diagram-project" aria-hidden="true"></i><strong>' + text + '</strong>' + (add && manage ? '<button type="button" class="secondary-action-button" data-add-focused>+ Add Prerequisite</button>' : '') + '</div>'; };
        if (!id) return empty('Select a subject to inspect its chain.');
        if (!next(id).length) return empty(up ? 'No prerequisite chain configured for this subject.' : 'No subjects depend on this subject.', up);
        const level = new Map(), path = new Set(); let cyclic = false;
        (function walk(node, depth) {
            if (path.has(node)) { cyclic = true; return; }
            if (level.has(node) && level.get(node) >= depth) return;
            level.set(node, depth); path.add(node); next(node).forEach(n => walk(n, depth + 1)); path.delete(node);
        })(id, 0);
        if (cyclic) { canvas.innerHTML = '<p class="pr-warning">A circular dependency affects this chain. Review the affected path in Validation Review.</p>'; return; }
        const max = Math.max(...level.values()), columns = [];
        level.forEach((depth, node) => (columns[up ? max - depth : depth] ||= []).push(node));
        const tallest = Math.max(...columns.map(c => c.length)), colHeight = n => n * CH.h + (n - 1) * CH.gapY;
        const width = CH.pad * 2 + columns.length * CH.w + (columns.length - 1) * CH.gapX, height = CH.pad * 2 + colHeight(tallest), positions = new Map();
        columns.forEach((nodes, c) => nodes.forEach((node, i) => positions.set(node, { x: CH.pad + c * (CH.w + CH.gapX), y: CH.pad + (colHeight(tallest) - colHeight(nodes.length)) / 2 + i * (CH.h + CH.gapY) })));
        let svg = '<svg aria-hidden="true" width="' + width + '" height="' + height + '"><defs><marker id="prArrow" markerWidth="9" markerHeight="9" refX="8" refY="4.5" orient="auto" markerUnits="userSpaceOnUse"><path d="M0,0 L9,4.5 L0,9 Z" fill="#6b7f9f"/></marker></defs>';
        let chips = '';
        state.edges.filter(e => level.has(Number(e.subject_id)) && level.has(Number(e.required_subject_id))).forEach(e => {
            const from = positions.get(Number(e.required_subject_id)), to = positions.get(Number(e.subject_id));
            const x1 = from.x + CH.w, y1 = from.y + CH.h / 2, x2 = to.x - 5, y2 = to.y + CH.h / 2, mx = (x1 + x2) / 2, my = (y1 + y2) / 2;
            svg += '<path d="M' + x1 + ',' + y1 + ' C' + mx + ',' + y1 + ' ' + mx + ',' + y2 + ' ' + x2 + ',' + y2 + '" fill="none" stroke="#6b7f9f" stroke-width="1.5" marker-end="url(#prArrow)"/>';
            chips += '<span class="pr-chain-chip" style="left:' + Math.round(mx) + 'px;top:' + Math.round(my - 18) + 'px">' + (narrow ? 'before' : 'required before') + '</span>';
        });
        canvas.innerHTML = svg + '</svg>' + chips + [...positions].map(([node, p]) => '<button type="button" class="pr-chain-node' + (node === id ? ' is-selected' : '') + '" data-row="' + node + '" title="' + esc((row(node)?.subject_code || '') + ' \u2014 ' + (row(node)?.subject_title || '')) + '" style="left:' + p.x + 'px;top:' + p.y + 'px">' + subject(node) + '</button>').join('');
        canvas.style.width = width + 'px'; canvas.style.height = height + 'px'; canvas.dataset.width = width; canvas.dataset.height = height;
        requestAnimationFrame(fit);
    }
    function fit() { const v = $('chainViewport'), c = $('chainCanvas'); if (!c.querySelector('svg')) return; const w = Number(c.dataset.width), h = Number(c.dataset.height), scale = Math.max(.55, Math.min(1, (v.clientWidth - 8) / w, (v.clientHeight - 8) / h)); c.style.transformOrigin = 'top left'; c.style.transform = 'translate(' + Math.max(0, (v.clientWidth - w * scale) / 2) + 'px,' + 0 + 'px) scale(' + scale + ')'; v.scrollLeft = 0; v.scrollTop = 0; }
    // Validation Review: the shared review component draws the rows; this page supplies the checks as data.
    const reviewMeta = {
        cycles: ['Circular Dependencies', 'Subjects that end up requiring themselves'],
        duplicates: ['Duplicate Rules', 'The same prerequisite listed more than once for a subject'],
        sequencing: ['Sequencing Conflicts', 'A prerequisite placed in the same term or later than its subject'],
        missing: ['Missing Placement', 'A rule whose subject has no year or semester'],
        inactive: ['Rule uses a non-active subject', 'A rule whose subject, or its prerequisite, is not active in Subject Master']
    };
    const reviewUi = window.CsmsReview.create($('reviewChecks'), { esc, onAction: (action, id) => (action === 'edit' && manage ? openEditor : details)(id) });
    const codeOf = id => esc(row(id)?.subject_code || id);
    const ruleAction = (id, extra = '') => '<button type="button" class="csms-link" data-review-action="' + (manage ? 'edit' : 'view') + '" data-review-id="' + Number(id) + '">' + (manage ? 'Edit rule' : 'View rule') + '<span class="sr-only"> for ' + codeOf(id) + '</span></button>' + extra;
    // Rules that touch a subject whose status is not "active"; the row shows the real status.
    function inactiveRows() {
        const out = [];
        state.edges.forEach(e => [Number(e.subject_id), Number(e.required_subject_id)].forEach(sid => {
            const status = row(sid)?.subject_status;
            if (status && status !== 'active') out.push({ subject_id: Number(e.subject_id), required_subject_id: Number(e.required_subject_id), bad: sid, status });
        }));
        return out;
    }
    function reviewChecks() {
        const r = state.review || {}, pair = [{ header: 'Subject', code: true, cell: e => codeOf(e.subject_id) }, { header: 'Requires', code: true, cell: e => codeOf(e.required_subject_id) }];
        const fix = e => ruleAction(e.subject_id, ' <a class="csms-link" href="' + esc(builderUrl()) + '">Open in Builder</a>');
        return [
            { key: 'cycles', rows: r.cycles || [], columns: [{ header: 'Path', cell: c => c.map(codeOf).join(' \u2192 ') }], actions: c => ruleAction(c[0]) },
            { key: 'duplicates', rows: r.duplicates || [], columns: pair, actions: e => ruleAction(e.subject_id) },
            { key: 'sequencing', rows: r.sequencing || [], columns: [...pair, { header: 'Detail', cell: e => esc(e.message || 'A prerequisite must be placed in an earlier term.') }], actions: fix },
            { key: 'missing', rows: r.missing || [], columns: [...pair, { header: 'Detail', cell: () => 'Placement information is incomplete.' }], actions: fix },
            { key: 'inactive', rows: inactiveRows(), columns: [...pair, { header: 'Not active', cell: e => codeOf(e.bad) + ' (' + esc(e.status) + ')' }], actions: e => ruleAction(e.subject_id) }
        ].map(c => ({ ...c, label: reviewMeta[c.key][0], hint: reviewMeta[c.key][1] }));
    }
    function renderReview() {
        if (!state.loaded) { $('reviewChecks').innerHTML = ''; $('reviewResult').innerHTML = ''; return; }
        reviewUi.render([{ title: 'Rule checks', note: 'Selected version only', checks: reviewChecks() }]);
        $('reviewResult').innerHTML = reviewUi.total() ? '' : '<div class="csms-empty"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><strong>No prerequisite conflicts detected.</strong><span>Checks cover duplicate, circular, sequencing, missing-placement and non-active-subject rules.</span></div>';
    }
    document.addEventListener('click', event => {
        const b=event.target.closest('button,[data-details],[data-row]'); if(!b)return;
        if(b.dataset.dir){state.dir=b.dataset.dir;renderChain();}
        else if(b.dataset.menu)openMenu(b);
        else if(b.dataset.warn)openWarn(Number(b.dataset.warn));
        else if(b.dataset.row)(manage?openEditor:details)(Number(b.dataset.row));
        else if(b.dataset.edit)openEditor(Number(b.dataset.edit));
        else if(b.dataset.remove)confirmRemoval(Number(b.dataset.remove));
        else if(b.dataset.draftRemove)confirmRemoval(state.target,Number(b.dataset.draftRemove));
        else if(b.dataset.details)details(Number(b.dataset.details));
        else if(b.hasAttribute('data-add-focused'))openEditor(state.focus);
        else if(b.dataset.tab) showTab(b.dataset.tab);
    });
    $('removeModal')?.addEventListener('click', event => { if (event.target.closest('[data-modal-close]') && state.remove?.draft) dialogs.editor.open(); });
    $('removeModal')?.addEventListener('keydown', event => { if (event.key === 'Escape' && state.remove?.draft) dialogs.editor.open(); });
    $('ruleCards').addEventListener('keydown',e=>{if(e.target.matches('[data-row]')&&['Enter',' '].includes(e.key)){e.preventDefault();(manage?openEditor:details)(Number(e.target.dataset.row));}});
    $('warnEdit')?.addEventListener('click',()=>{dialogs.warn.close();openEditor(state.target);});
    window.addEventListener?.('resize',ui.debounce(()=>{if(!$('panel-chain').hidden)renderChain();},200));
    document.querySelector('.csms-tabs').addEventListener('keydown',e=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(e.key))return;e.preventDefault();const tabs=[...document.querySelectorAll('[data-tab]')],i=tabs.indexOf(e.target),next=e.key==='Home'?0:e.key==='End'?2:(i+(e.key==='ArrowRight'?1:2))%3;tabs[next].click();tabs[next].focus();});
    $('ruleSearch').addEventListener('keydown',e=>{if(e.key==='Escape'&&$('ruleSearch').value){$('ruleSearch').value='';renderRules();}});
    $('ruleSearch').addEventListener('input',ui.debounce(renderRules,150)); ['yearFilter','semesterFilter','stateFilter'].forEach(id=>$(id).addEventListener('change',renderRules));
    $('addRequirementButton')?.addEventListener('click',()=>openEditor()); $('appendRequired')?.addEventListener('click',append);
    $('detailsEdit')?.addEventListener('click',()=>openEditor(state.target)); $('detailsRemove')?.addEventListener('click',()=>confirmRemoval(state.target));
    $('fitChain').addEventListener('click',fit); $('resetChain').addEventListener('click',()=>{ $('chainCanvas').style.transform=''; $('chainViewport').scrollTop=0;$('chainViewport').scrollLeft=0; });
    async function init() { const params=new URLSearchParams(location.search); if(params.get('version_id'))try {
        const version=(await ui.fetchJson(root.dataset.versionApi,{action:'get',id:params.get('version_id')})).version;
        const curriculum=(await ui.fetchJson(root.dataset.curriculumApi,{action:'get',id:version.curriculum_id})).curriculum;
        const program=(await ui.fetchJson(root.dataset.programApi,{action:'get',id:curriculum.academic_program_id})).program;
        if ((params.get('program_id') && Number(params.get('program_id'))!==Number(program.id)) || (params.get('curriculum_id') && Number(params.get('curriculum_id'))!==Number(curriculum.id))) throw new Error('The linked prerequisite context is inconsistent.');
        state.context=[program,curriculum,version];state.context.forEach((p,i)=>cascade.get(i).setSelected(p,false));$('curriculumSearch').disabled=false;$('versionSearch').disabled=false;
    }catch(e){ui.showToast(e.message,'error');} await load();
        const linked = row(params.get('subject_id'));
        if (linked) focus.setSelected(linked);
    }
    init();
}());
