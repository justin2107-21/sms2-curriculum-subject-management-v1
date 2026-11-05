(function () {
    'use strict';
    // Curriculum Builder workspace. One page: context bar, version header (status, review stepper, units against
    // the target, conflicts, the next step for this user), and tabs for the plan, details and review history.
    // Every rule is the server's; this page only shows what the endpoints return and hides controls that would be refused.

    const root = document.getElementById('builderRoot');
    const ui = window.CurriculumUI;
    const view = window.CurriculumPlacementView;
    if (!root || !ui || !view) return;

    const $ = id => document.getElementById(id);
    const esc = ui.escapeHtml;
    const api = {
        programs: root.dataset.programApi, curricula: root.dataset.curriculumApi, versions: root.dataset.versionApi,
        assignments: root.dataset.assignmentApi, requirements: root.dataset.requirementApi, review: root.dataset.reviewApi,
        references: root.dataset.referenceApi, compliance: root.dataset.complianceApi
    };
    const flag = key => root.dataset[key] === '1';
    const can = {
        submit: flag('canSubmit'), decide: flag('canDecide'), classify: flag('canClassify'), attach: flag('canAttach'),
        clone: flag('canClone'), editPlan: flag('canEditPlan'), prerequisites: flag('canViewPrerequisites'),
        exportVersion: flag('canExport'), exportDraft: flag('canExportDraft'), exportOfficial: flag('canExportOfficial'), history: flag('canHistory')
    };
    const me = Number(root.dataset.userId) || 0;
    const state = { ticket: 0, versionId: 0, ws: null, summary: null, review: null, placements: [], graph: null, tab: 'plan' };

    // ---- Context bar --------------------------------------------------------------------------------------------
    const cascade = ui.createCascadingAutocomplete({
        // The shared working-context script resolves the address, then the saved context (UI_Decisions D4).
        levels: [
            {
                input: $('programSearch'), valueInput: $('programId'), list: $('programOptions'),
                helpText: 'Search stored academic programs.', selectedText: 'Program selected.',
                noResultsText: 'No matching programs found.', placeholder: 'Search program code or name',
                search: async query => (await ui.fetchJson(api.programs, { action: 'list', page: 1, per_page: 50, search: query, status: '' })).programs,
                getId: item => item.id,
                format: item => ({ label: item.program_code + ' — ' + item.program_name, code: item.program_code, title: item.program_name })
            },
            {
                input: $('curriculumSearch'), valueInput: $('curriculumId'), list: $('curriculumOptions'),
                helpText: 'Select a program first.', selectedText: 'Curriculum selected.',
                noResultsText: 'No matching curricula found.', placeholder: 'Search curriculum code or name',
                disabledPlaceholder: 'Choose program first', minimumLength: 0, showSuggestionsOnEnable: true, showSuggestionsOnFocus: true, focusOnEnable: true,
                search: async query => $('programId').value ? (await ui.fetchJson(api.curricula, {
                    action: 'list', page: 1, per_page: 50, search: query, status: '', academic_program_id: $('programId').value
                })).curricula : [],
                getId: item => item.id,
                format: item => ({ label: item.curriculum_code + ' — ' + item.curriculum_name, code: item.curriculum_code, title: item.curriculum_name })
            },
            {
                input: $('versionSearch'), valueInput: $('versionId'), list: $('versionOptions'),
                helpText: 'Select a curriculum first.', selectedText: 'Version selected.',
                noResultsText: 'No matching versions found.', placeholder: 'Search version label',
                disabledPlaceholder: 'Choose curriculum first', minimumLength: 0, showSuggestionsOnEnable: true, showSuggestionsOnFocus: true, focusOnEnable: true,
                search: async query => $('curriculumId').value ? (await ui.fetchJson(api.versions, {
                    action: 'list', curriculum_id: $('curriculumId').value, page: 1, per_page: 50, search: query
                })).versions : [],
                getId: item => item.id,
                format: item => ({ label: item.version_label, code: item.version_label, title: item.effective_academic_year || '' })
            }
        ],
        onChange: function (index, item, id, action) {
            if (index === 2 && action === 'select') loadVersion(Number(id));
            else clearVersion();
        }
    });

    function showState(title, text, icon) {
        $('builderVersion').hidden = true;
        const box = $('builderState');
        box.hidden = false;
        box.innerHTML = '<i class="fa-solid ' + (icon || 'fa-table-list') + '" aria-hidden="true"></i><h2>' + esc(title) + '</h2>' + (text ? '<p>' + esc(text) + '</p>' : '');
    }

    function clearVersion() {
        state.ticket++;
        state.versionId = 0;
        $('builderAccessBadge').hidden = true;
        $('builderActions').innerHTML = '';
        $('builderLinks').innerHTML = '';
        showState('Choose a curriculum version to begin', 'Pick a program, curriculum and version above. Your last choice is remembered.');
    }

    // ---- Loading ------------------------------------------------------------------------------------------------
    async function loadVersion(id) {
        if (!id) return;
        const ticket = ++state.ticket;
        state.versionId = id;
        showState('Loading version…', '', 'fa-spinner fa-spin');
        try {
            const [ws, summary, review, placements, graph] = await Promise.all([
                ui.fetchJson(api.references, { action: 'workspace', curriculum_version_id: id }),
                ui.fetchJson(api.versions, { action: 'builder_summary', curriculum_version_id: id }),
                ui.fetchJson(api.review, { action: 'history', curriculum_version_id: id }),
                view.load(page => ui.fetchJson(api.assignments, { action: 'list', curriculum_version_id: id, page: page, per_page: 50 }), () => ticket === state.ticket),
                can.prerequisites ? ui.fetchJson(api.requirements, { action: 'workspace', curriculum_version_id: id }).catch(() => null) : Promise.resolve(null)
            ]);
            if (ticket !== state.ticket || placements === null) return;
            Object.assign(state, { ws: ws.workspace, summary: summary.summary, review: review, placements: placements, graph: graph });
            render();
        } catch (error) {
            if (ticket !== state.ticket) return;
            showState('This version could not be opened', error.message || 'Try again, or choose another version.', 'fa-circle-exclamation');
        }
    }

    // ---- Conflicts ----
    // subject id -> the conflicts that involve it: { kind: 'sequence', item } or { kind: 'cycle', chain }.
    function conflictMap() {
        const map = new Map();
        const review = state.graph && state.graph.review;
        if (!review) return null;
        const add = (id, entry) => { const key = String(id); map.set(key, (map.get(key) || []).concat([entry])); };
        (review.sequencing || []).forEach(item => {
            const entry = { kind: 'sequence', item: item };
            add(item.target_subject_id, entry);
            if (String(item.prerequisite_subject_id) !== String(item.target_subject_id)) add(item.prerequisite_subject_id, entry);
        });
        (review.cycles || []).forEach(cycle => {
            const chain = cycle.slice(0, -1);
            const entry = { kind: 'cycle', chain: chain };
            chain.forEach(subject => add(subject, entry));
        });
        return map;
    }

    const CONFLICT_TYPE = {
        prerequisite_same_period: 'Prerequisite placed in the same period',
        prerequisite_after_target: 'Prerequisite placed after the subject',
        self_prerequisite: 'Subject listed as its own prerequisite',
        missing_placement: 'Placement missing or incomplete',
        unresolved_sequence: 'Sequence cannot be verified'
    };

    function placementText(year, semester) {
        const parts = [year ? view.yearLabel(year) : '', semester || ''].filter(Boolean);
        return parts.length ? parts.join(' / ') : 'Not placed';
    }

    function subjectPlacement(subjectId) {
        const row = state.placements.find(item => String(item.subject_id) === String(subjectId));
        return row ? { code: row.subject_code, title: row.subject_title, where: placementText(row.year_level, row.semester) } : { code: 'Subject #' + subjectId, title: '', where: 'Not placed' };
    }

    function openConflict(subjectId) {
        const map = conflictMap();
        const entries = (map && map.get(String(subjectId))) || [];
        const me = subjectPlacement(subjectId);
        $('builderConflictSubject').textContent = me.code + (me.title ? ' — ' + me.title : '') + ' · ' + me.where;
        const line = (label, code, title, where, current) => '<tr' + (current ? ' class="is-current"' : '') + '><th scope="row">' + esc(label) + '</th><td><strong>' + esc(code) + '</strong> ' + esc(title || '') + (current ? ' <span class="builder-conflict-this">this subject</span>' : '') + '</td><td>' + esc(where) + '</td></tr>';
        $('builderConflictBody').innerHTML = entries.map(function (entry, index) {
            let head, body;
            if (entry.kind === 'sequence') {
                const it = entry.item;
                head = CONFLICT_TYPE[it.type] || 'Prerequisite conflict';
                body = '<p>' + esc(it.message || '') + '</p><table class="builder-conflict-table"><thead><tr><th scope="col"><span class="sr-only">Role</span></th><th scope="col">Subject</th><th scope="col">Placed in</th></tr></thead><tbody>' +
                    line('Prerequisite', it.prerequisite_code, it.prerequisite_title, placementText(it.prerequisite_year, it.prerequisite_semester), String(it.prerequisite_subject_id) === String(subjectId)) +
                    line('Subject', it.target_code, it.target_title, placementText(it.target_year, it.target_semester), String(it.target_subject_id) === String(subjectId)) + '</tbody></table>';
            } else {
                head = 'Circular prerequisite chain';
                body = '<p>These subjects require each other in a loop, so none can be taken first.</p><table class="builder-conflict-table"><thead><tr><th scope="col"><span class="sr-only">Step</span></th><th scope="col">Subject</th><th scope="col">Placed in</th></tr></thead><tbody>' +
                    entry.chain.map((id, step) => { const p = subjectPlacement(id); return line('Step ' + (step + 1), p.code, p.title, p.where, String(id) === String(subjectId)); }).join('') + '</tbody></table>';
            }
            return '<section class="builder-conflict-card"><h3>' + (entries.length > 1 ? (index + 1) + '. ' : '') + esc(head) + '</h3>' + body + '</section>';
        }).join('') || '<p>No conflict is recorded for this subject any more. Reload the version.</p>';
        dialogs.conflict.open();
    }
    // ---- Rendering ----------------------------------------------------------------------------------------------
    const ROLE_LABEL = { unclassified: 'Unclassified', proposed: 'Proposed revision', reference: 'Reference' };
    const REVIEW_LABEL = { not_started: 'Not started', draft: 'Not started', under_review: 'In review', returned: 'Returned', approved: 'Approved' };
    const STATUS_NAME = { draft: 'Draft', active: 'Active', archived: 'Archived' };

    function chip(kind, label) { return '<span class="csms-chip csms-chip--' + esc(kind) + '">' + esc(label) + '</span>'; }

    function lastEdit() {
        const edit = state.summary.last_edit;
        if (!edit) return 'Last edit not recorded';
        const when = new Date(String(edit.at).replace(' ', 'T'));
        let text = '';
        if (!isNaN(when)) {
            const time = when.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', hour12: false });
            const today = new Date().toDateString() === when.toDateString();
            text = today ? 'Today, ' + time : when.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) + ', ' + time;
        }
        return 'Last edited' + (edit.by ? ' by ' + edit.by : '') + (text ? ' · ' + text : '');
    }

    function facts() {
        const summary = state.summary;
        $('builderUnits').textContent = ui.formatUnits(summary.placed_units);
        $('builderTarget').textContent = summary.target ? 'Target · ' + ui.formatUnits(summary.target.value) + ' units' : 'No target set';
        $('builderTarget').title = summary.target ? summary.target.rule_name : '';
        const map = conflictMap();
        const conflicts = $('builderConflicts');
        if (map === null) { conflicts.textContent = ''; return; }
        const review = state.graph.review;
        const total = (review.sequencing || []).length + (review.cycles || []).length;
        conflicts.innerHTML = total ? '<span class="builder-flag"><i class="fa-solid fa-flag" aria-hidden="true"></i> ' + total + (total === 1 ? ' conflict' : ' conflicts') + '</span>' : 'No conflicts';
    }

    function button(kind, label, primary) {
        return '<button type="button" class="' + (primary ? 'primary-action-button' : 'secondary-action-button') + '" data-builder-action="' + kind + '">' + esc(label) + '</button>';
    }

    function actions() {
        const ws = state.ws, review = state.review;
        const buttons = [];
        let next = '';
        const lastSubmitter = (review.events.find(event => event.action === 'submit') || {}).actor_user_id;
        const ownSubmission = lastSubmitter !== undefined && Number(lastSubmitter) === me;
        if (can.submit && review.can_submit) buttons.push(button('submit', 'Submit for review', true));
        if (can.decide && review.can_decide) {
            if (ownSubmission) next = 'You submitted this version, so someone else must decide it.';
            else buttons.push(button('approve', 'Approve', true), button('return', 'Return', false));
        }
        if (!buttons.length && !next) {
            const worker = can.submit || can.decide || can.classify || can.clone || can.attach || can.editPlan;
            const unclassified = ws.version_role === 'unclassified' && ws.review_status === 'not_started' && !ws.baseline_version_id;
            if (unclassified) {
                if (can.classify) buttons.push(button('classify', 'Classify as reference', true));
                else if (can.attach) { buttons.push(button('attach', 'Attach documentary source', false)); next = 'To send this for review, a reviewer classifies it as a protected reference, then a revision is created from it.'; }
                else if (worker) next = 'This version is not a proposed revision, so it cannot be submitted. A reviewer classifies a reference, then a revision is created from it.';
            } else if (ws.version_role === 'reference') {
                if (can.clone) buttons.push(button('clone', 'Create revision from this reference', true));
                else if (worker) next = 'This is a protected reference. Staff create an editable revision from it.';
            } else if (ws.review_status === 'under_review') {
                if (worker) next = 'Waiting for a decision from Acad Office.';
            } else if (ws.review_status === 'approved') {
                if (worker) next = 'Approved and protected. Changes start from a new revision.';
            }
        }
        $('builderActions').innerHTML = buttons.join('');
        const note = $('builderNext');
        note.hidden = !next;
        note.textContent = next;
    }

    function links() {
        const ws = state.ws;
        const query = new URLSearchParams({ program_id: ws.academic_program_id || '', curriculum_id: ws.curriculum_id, version_id: ws.id });
        const parts = [];
        // Same split as the server: approved versions need official export, others draft export.
        if (can.exportVersion) {
            const approved = ws.review_status === 'approved';
            if (approved ? can.exportOfficial : can.exportDraft) {
                parts.push('<a href="curriculum_export.php?' + esc(query.toString()) + '" class="secondary-action-button"><i class="fa-solid fa-download" aria-hidden="true"></i> Export</a>');
            } else {
                const why = approved ? 'Your role exports versions that are not yet approved.' : 'Your role exports approved versions only.';
                parts.push('<button type="button" class="secondary-action-button" id="builderExportOff" disabled title="' + esc(why) + '" aria-describedby="builderExportWhy"><i class="fa-solid fa-download" aria-hidden="true"></i> Export</button><span class="sr-only" id="builderExportWhy">' + esc(why) + '</span>');
            }
        }
        parts.push('<a href="subject_mapping.php?' + esc(query.toString()) + '&amp;tab=outcomes" class="secondary-action-button"><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i> Graduate outcomes</a>');
        if (can.history && ws.version_role === 'proposed' && ws.baseline_version_id) {
            parts.push('<a href="curriculum_history_view.php?curriculum_id=' + Number(ws.curriculum_id) + '&amp;version_b_id=' + Number(ws.id) + '" class="secondary-action-button"><i class="fa-solid fa-code-compare" aria-hidden="true"></i> Compare with reference</a>');
        }
        $('builderLinks').innerHTML = parts.join('');
    }

    // A code may wrap only after a slash ("CCS1102/" then "CC102"), never inside a segment.
    function codeHtml(code) {
        return String(code).split('/').map((part, index, all) => '<span class="builder-code-part">' + esc(part) + (index < all.length - 1 ? '/' : '') + '</span>').join('<wbr>');
    }

    function plan() {
        const ws = state.ws;
        const conflicts = conflictMap();
        const editable = Boolean(ws.editable) && can.editPlan;
        const query = new URLSearchParams({ program_id: ws.academic_program_id || '', curriculum_id: ws.curriculum_id, version_id: ws.id });
        // Read-only is shown by the badge in the page header; only editors get a line here.
        $('builderPlanNote').innerHTML = editable ? 'Edit placements in <a href="subject_mapping.php?' + esc(query.toString()) + '">Subject Mapping</a>.' : '';
        $('builderPlanSearchBox').hidden = !state.placements.length;
        if (!state.placements.length) { $('builderPlan').innerHTML = '<div class="builder-empty"><i class="fa-solid fa-table-list" aria-hidden="true"></i><strong>No subjects placed yet</strong><span>Placed subjects appear here by year and semester.</span></div>'; return; }
        const rows = state.placements.map(row => Object.assign({}, row, { year_level: view.yearLabel(row.year_level) }));
        const grouped = view.group(rows);
        let html = '';
        grouped.years.forEach(function (semesters, year) {
            let yearCents = 0;
            semesters.forEach(term => { yearCents += term.cents; });
            const yearName = year || 'Year not specified';
            html += '<section class="builder-year"><header><h3>' + esc(yearName) + '</h3><span>' + esc(ui.formatUnits(yearCents / 100)) + ' units</span></header><div class="builder-terms">';
            semesters.forEach(function (term, name) {
                const count = term.rows.length;
                html += '<article class="builder-term"><header><strong>' + esc(yearName) + ' · ' + esc(name || 'Semester not specified') + ' · <span class="builder-term__count">' + count + (count === 1 ? ' subject' : ' subjects') + '</span></strong><span class="builder-term__units">' + esc(ui.formatUnits(term.cents / 100)) + ' units</span></header>' +
                    '<table class="builder-subjects"><thead><tr><th scope="col" class="builder-subjects__flag"><span class="sr-only">Conflict</span></th><th scope="col" class="builder-subjects__code">Code</th><th scope="col" class="builder-subjects__title">Subject name</th><th scope="col" class="builder-subjects__units">Units</th><th scope="col" class="builder-subjects__actions"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
                term.rows.forEach(function (row) {
                    const problems = conflicts && conflicts.get(String(row.subject_id));
                    const required = Number(row.is_required) === 1;
                    html += '<tr data-search="' + esc((row.subject_code + ' ' + row.subject_title).toLowerCase()) + '"' + (problems ? ' class="has-conflict"' : '') + '>' +
                        '<td class="builder-subjects__flag">' + (problems ? '<button type="button" class="builder-conflict-btn" data-conflict="' + Number(row.subject_id) + '" aria-label="View conflict for ' + esc(row.subject_code) + '" title="View conflict"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></button>' : '') + '</td>' +
                        '<td class="builder-subjects__code">' + codeHtml(row.subject_code) + '</td>' +
                        '<td class="builder-subjects__title">' + esc(row.subject_title) + (required ? '' : ' <span class="placement-indicator placement-optional">Optional</span>') + '</td>' +
                        '<td class="builder-subjects__units">' + esc(ui.formatUnits(row.units)) + '</td><td class="builder-subjects__actions"></td></tr>';
                });
                html += '</tbody></table></article>';
            });
            html += '</div></section>';
        });
        $('builderPlan').innerHTML = html + '<p class="builder-total">Total ' + esc(ui.formatUnits(grouped.cents / 100)) + ' units · ' + grouped.count + ' subjects</p>';
        clearPlanResults();
    }

    // Typing changes nothing. Enter runs the search; Enter and the arrow keys then move between matches.
    // Escape, the box's own clear button, or editing the text restores the plan.
    let planMatches = [];
    let planMatchIndex = -1;
    let planSearchActive = false;
    function clearPlanResults() {
        planMatches = [];
        planMatchIndex = -1;
        planSearchActive = false;
        $('builderPlan').querySelectorAll('tr[data-search]').forEach(row => row.classList.remove('is-match', 'is-dimmed', 'is-current-match'));
        $('builderPlanSearchStatus').textContent = '';
    }
    function showPlanMatch(index) {
        if (!planMatches.length) return;
        planMatchIndex = (index + planMatches.length) % planMatches.length;
        planMatches.forEach((row, i) => row.classList.toggle('is-current-match', i === planMatchIndex));
        $('builderPlanSearchStatus').textContent = (planMatchIndex + 1) + ' of ' + planMatches.length;
        const still = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
        planMatches[planMatchIndex].scrollIntoView({ block: 'center', behavior: still ? 'auto' : 'smooth' });
    }
    function runPlanSearch() {
        clearPlanResults();
        const query = $('builderPlanSearch').value.trim().toLowerCase();
        if (!query) return;
        planSearchActive = true;
        $('builderPlan').querySelectorAll('tr[data-search]').forEach(function (row) {
            const hit = row.dataset.search.includes(query);
            if (hit) planMatches.push(row);
            row.classList.toggle('is-match', hit);
            row.classList.toggle('is-dimmed', !hit);
        });
        if (planMatches.length) showPlanMatch(0);
        else $('builderPlanSearchStatus').textContent = 'No matches';
    }
    $('builderPlanSearch').addEventListener('input', clearPlanResults);
    $('builderPlanSearch').addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.currentTarget.value = '';
            clearPlanResults();
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (!planSearchActive) runPlanSearch();
            else showPlanMatch(planMatchIndex + (event.shiftKey ? -1 : 1));
        } else if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && planSearchActive) {
            event.preventDefault();
            showPlanMatch(planMatchIndex + (event.key === 'ArrowDown' ? 1 : -1));
        }
    });

    function sourceKind(kind) {
        return ({ regulatory_document: 'Regulatory document', ched_sample_curriculum: 'CHED sample curriculum', institutional_curriculum: 'Institution-provided curriculum',
            institutional_policy: 'Institutional policy', supporting_document: 'Supporting document', other_document: 'Other documented source' })[kind] || 'Unknown source kind';
    }

    function assessment(item) {
        if (!item) return 'not assessed';
        if (item.status === 'stale_for_current_metadata') return 'STALE (previous result: ' + String(item.historical_result || 'unknown') + ')';
        return String(item.status || 'not assessed').replaceAll('_', ' ');
    }

    function details() {
        const ws = state.ws;
        const summary = ws.snapshot_summary;
        const primary = ws.references.find(item => item.reference_purpose === 'school_curriculum');
        const fields = [
            ['Program', ws.program_code + ' — ' + ws.program_name],
            ['Curriculum / version', ws.curriculum_code + ' — ' + ws.curriculum_name + ' / ' + ws.version_label],
            ['Version role', ui.statusBadge(ws.version_role), true],
            ['Review workflow', ui.statusBadge(ws.review_status) + '<span class="compliance-subtext">Workflow state; not proof of adoption.</span>', true],
            ['Reference basis', primary ? sourceKind(primary.source_kind_snapshot) : 'Unknown / no curriculum source attached'],
            ['Baseline', ws.baseline_version_id ? ws.baseline_version_label + ' (' + ui.statusLabel(ws.baseline_version_role) + ')' : 'None'],
            ['Snapshot capture', summary.complete_snapshots + '/' + summary.placements + ' complete' + (summary.last_capture ? '; captured ' + summary.last_capture : '')],
            ['Institutional adoption', 'Institutional adoption/approval not verified']
        ];
        $('builderDetails').innerHTML = fields.map(([label, value, html]) => '<div><dt>' + esc(label) + '</dt><dd>' + (html ? value : esc(value)) + '</dd></div>').join('');
        $('builderReferences').innerHTML = ws.references.length ? ws.references.map(function (item) {
            const name = item.reference_code_snapshot || item.reference_title_snapshot || ('Source #' + item.compliance_reference_id);
            const assess = ['document_identity', 'declared_origin', 'applicability'].map(key => key.replaceAll('_', ' ') + ': ' + assessment(item.current_assessments && item.current_assessments[key])).join(' · ');
            const warning = (item.attachment_matches_current_source ? '' : ' · CURRENT SOURCE CHANGED SINCE ATTACHMENT') + (item.verification_status === 'inactive' ? ' · CURRENT SOURCE INACTIVE/WITHDRAWN' : '');
            return '<tr><td><strong>' + esc(name) + '</strong><span class="compliance-subtext">' + esc(item.reference_title_snapshot || '') + ' · ' + esc(item.issuing_body_snapshot || 'Issuer unavailable') + '</span></td>' +
                '<td>' + esc(ui.statusLabel(item.reference_purpose)) + '</td><td>' + esc(sourceKind(item.source_kind_snapshot)) + '<span class="compliance-subtext">' + esc(assess + warning) + '</span></td>' +
                '<td>' + esc(item.linked_by_username || 'Unknown user') + '<span class="compliance-subtext">' + esc(item.linked_at) + '</span></td></tr>';
        }).join('') : '';
        $('builderReferencesTable').hidden = !ws.references.length;
        $('builderReferencesEmpty').hidden = Boolean(ws.references.length);
        const attach = $('builderAttachOpen');
        if (attach) attach.hidden = !(ws.version_role === 'unclassified' && ws.review_status === 'not_started' && !ws.baseline_version_id);
    }

    function history() {
        const events = state.review.events;
        $('builderHistory').innerHTML = events.length ? events.map(e => '<tr><td>' + esc(e.created_at) + '</td><td>' + esc(e.actor_name) + '</td><td>' + esc(ui.statusLabel(e.action)) +
            '</td><td>' + esc(ui.statusLabel(e.new_status)) + '</td><td>' + esc(e.comments || '') + '</td></tr>').join('') : '';
        $('builderHistoryTable').hidden = !events.length;
        $('builderHistoryEmpty').hidden = Boolean(events.length);
    }

    function render() {
        const ws = state.ws;
        $('builderState').hidden = true;
        $('builderVersion').hidden = false;
        $('builderVersionTitle').textContent = [ws.program_code, ws.curriculum_code, ws.version_label].join(' · ');
        $('builderVersionSub').textContent = (ROLE_LABEL[ws.version_role] || ui.statusLabel(ws.version_role)) + ' · ' + (ws.baseline_version_id ? 'based on ' + ws.baseline_version_label : 'no baseline');
        const reviewKind = state.review.review_status === 'draft' ? 'not_started' : state.review.review_status;
        $('builderVersionBadges').innerHTML = chip(ws.status, STATUS_NAME[ws.status] || ws.status) + ' ' + chip(reviewKind, REVIEW_LABEL[state.review.review_status] || ui.statusLabel(state.review.review_status));
        $('builderLastEdit').textContent = lastEdit();
        const access = $('builderAccessBadge');
        access.innerHTML = ws.editable && can.editPlan ? ui.statusBadge('editable') : ui.statusBadge('read_only');
        access.hidden = false;
        facts(); actions(); links(); plan(); details(); history();
    }

    // ---- Tabs ---------------------------------------------------------------------------------------------------
    const tabs = Array.from(document.querySelectorAll('.builder-tabs [role="tab"]'));
    function selectTab(name, focus) {
        state.tab = name;
        tabs.forEach(function (tab) {
            const on = tab.dataset.tab === name;
            tab.setAttribute('aria-selected', String(on));
            tab.tabIndex = on ? 0 : -1;
            $('panel' + tab.dataset.tab.charAt(0).toUpperCase() + tab.dataset.tab.slice(1)).hidden = !on;
            if (on && focus) tab.focus();
        });
    }
    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', () => selectTab(tab.dataset.tab, false));
        tab.addEventListener('keydown', function (event) {
            const move = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
            if (move) { event.preventDefault(); selectTab(tabs[(index + move + tabs.length) % tabs.length].dataset.tab, true); }
            else if (event.key === 'Home') { event.preventDefault(); selectTab(tabs[0].dataset.tab, true); }
            else if (event.key === 'End') { event.preventDefault(); selectTab(tabs[tabs.length - 1].dataset.tab, true); }
        });
    });

    // ---- Dialogs ------------------------------------------------------------------------------------------------
    const dialogs = {};
    ['Review', 'Attach', 'Classify', 'Clone', 'Conflict'].forEach(function (name) {
        const element = $('builder' + name + 'Modal');
        if (element) dialogs[name.toLowerCase()] = ui.createModal(element);
    });

    async function reload() { await loadVersion(state.versionId); }

    // Review: submit, approve, return.
    let reviewKind = '';
    function openReview(kind) {
        reviewKind = kind;
        const form = $('builderReviewForm');
        form.reset(); ui.clearFormError(form);
        const decide = kind !== 'submit';
        $('builderReviewTitle').textContent = kind === 'submit' ? 'Submit for review' : kind === 'approve' ? 'Approve this version' : 'Return this version';
        $('builderReviewContext').textContent = state.ws.curriculum_code + ' / ' + state.ws.version_label;
        $('builderReviewLabel').textContent = decide ? 'Reason (required)' : 'Comment for the reviewer (optional)';
        $('builderReviewSubmit').textContent = kind === 'submit' ? 'Submit' : kind === 'approve' ? 'Approve' : 'Return';
        dialogs.review.open();
    }
    $('builderReviewForm').addEventListener('submit', async function (event) {
        event.preventDefault();
        const form = event.currentTarget;
        const text = form.elements.reason.value.trim();
        if (reviewKind !== 'submit' && !text) { ui.showFormError(form, 'Enter the reason for this decision.'); return; }
        const data = new FormData();
        data.set('curriculum_version_id', state.versionId);
        if (reviewKind === 'submit') { data.set('action', 'submit'); data.set('comment', text); }
        else { data.set('action', 'decide'); data.set('decision', reviewKind); data.set('reason', text); }
        ui.setFormSubmitting(form, true, 'Saving…');
        try {
            const result = await ui.postForm(api.review, data);
            dialogs.review.close();
            ui.showToast(result.message || 'Saved.', 'success');
            await reload();
        } catch (error) { ui.showFormError(form, error.message || 'Unable to save.'); }
        finally { ui.setFormSubmitting(form, false); }
    });

    // Attach a documentary source.
    async function openAttach() {
        if (!dialogs.attach) return;
        const form = $('builderAttachForm');
        form.reset(); ui.clearFormError(form);
        form.elements.curriculum_version_id.value = state.versionId;
        const select = form.elements.compliance_reference_id;
        dialogs.attach.open();
        try {
            const payload = await ui.fetchJson(api.compliance, { action: 'reference_list', page: 1, per_page: 50, search: '' });
            const available = payload.references.filter(item => item.verification_status !== 'inactive');
            select.innerHTML = available.length ? '<option value="">Select a source</option>' + available.map(item =>
                '<option value="' + Number(item.id) + '">' + esc((item.reference_code || item.title || 'Reference') + ' — ' + sourceKind(item.source_kind) + ' — ' + item.issuing_body) + '</option>').join('')
                : '<option value="">No available source records</option>';
        } catch (error) { select.innerHTML = '<option value="">Source records unavailable</option>'; }
    }
    $('builderAttachForm')?.addEventListener('submit', async function (event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        const data = new FormData(form); data.set('action', 'attach');
        ui.setFormSubmitting(form, true, 'Attaching…');
        try {
            const result = await ui.postForm(api.references, data);
            dialogs.attach.close();
            ui.showToast(result.message, 'success');
            await reload();
        } catch (error) { ui.showFormError(form, error.message); }
        finally { ui.setFormSubmitting(form, false); }
    });

    // Classify as a protected reference.
    function openClassify() {
        if (!dialogs.classify) return;
        const form = $('builderClassifyForm');
        form.reset(); ui.clearFormError(form);
        form.elements.curriculum_version_id.value = state.versionId;
        const choices = state.ws.references.filter(item => item.reference_purpose === 'school_curriculum');
        const select = form.elements.compliance_reference_id;
        select.innerHTML = choices.map(item => '<option value="' + Number(item.compliance_reference_id) + '">' +
            esc((item.reference_code_snapshot || item.reference_title_snapshot || 'Source') + ' — ' + sourceKind(item.source_kind_snapshot)) + '</option>').join('');
        select.disabled = !choices.length;
        $('builderClassifySource').hidden = !choices.length;
        form.querySelector('button[type="submit"]').disabled = !choices.length;
        const attachBox = $('builderClassifyAttach');
        if (attachBox) attachBox.hidden = Boolean(choices.length);
        const reason = $('builderClassifyReason');
        reason.hidden = Boolean(choices.length);
        reason.textContent = choices.length ? '' : attachBox
            ? 'Classify is unavailable until a curriculum source is attached.'
            : 'Classify is unavailable: no curriculum source is attached. Staff attach one first in Details and references.';
        dialogs.classify.open();
    }
    $('builderClassifyForm')?.addEventListener('submit', async function (event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        const data = new FormData(form); data.set('action', 'classify_reference');
        ui.setFormSubmitting(form, true, 'Capturing and protecting…');
        try {
            const result = await ui.postForm(api.references, data);
            dialogs.classify.close();
            ui.showToast(result.message, 'success');
            await reload();
        } catch (error) { ui.showFormError(form, error.message); }
        finally { ui.setFormSubmitting(form, false); }
    });

    // Create a revision from a reference.
    async function openClone() {
        if (!dialogs.clone) return;
        const form = $('builderCloneForm');
        form.reset(); ui.clearFormError(form);
        form.elements.source_version_id.value = state.ws.id;
        form.elements.curriculum_id.value = state.ws.curriculum_id;
        form.querySelector('button[type="submit"]').disabled = true;
        const scope = $('builderCloneScope');
        scope.textContent = 'Checking the protected reference and copy scope…';
        dialogs.clone.open();
        const ticket = state.ticket;
        try {
            const result = await ui.fetchJson(api.versions, { action: 'clone_preview', source_version_id: state.ws.id, curriculum_id: state.ws.curriculum_id });
            if (ticket !== state.ticket) return;
            scope.textContent = state.ws.program_code + ' / ' + state.ws.version_label + '. Copy: ' +
                Object.entries(result.preview.counts).map(([key, value]) => value + ' ' + key).join(', ') + '. ' + result.preview.notice;
            form.querySelector('button[type="submit"]').disabled = false;
        } catch (error) { scope.textContent = 'Cannot create a revision: ' + error.message; }
    }
    $('builderCloneForm')?.addEventListener('submit', async function (event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        const data = new FormData(form); data.set('action', 'clone_reference');
        ui.setFormSubmitting(form, true, 'Creating revision…');
        try {
            const result = await ui.postForm(api.versions, data);
            ui.showToast(result.message, 'success');
            const ws = state.ws;
            // Open the new revision: the address carries the context.
            window.location.search = new URLSearchParams({ program_id: ws.academic_program_id || '', curriculum_id: ws.curriculum_id, version_id: result.version.id }).toString();
        } catch (error) { ui.showFormError(form, error.message || 'Revision creation failed.'); ui.setFormSubmitting(form, false); }
    });

    $('builderPlan').addEventListener('click', function (event) {
        const button = event.target.closest('[data-conflict]');
        if (button) openConflict(button.dataset.conflict);
    });

    // Header buttons.
    $('builderActions').addEventListener('click', function (event) {
        const target = event.target.closest('[data-builder-action]');
        if (!target) return;
        const kind = target.dataset.builderAction;
        if (kind === 'submit' || kind === 'approve' || kind === 'return') openReview(kind);
        else if (kind === 'classify') openClassify();
        else if (kind === 'clone') openClone();
        else if (kind === 'attach') openAttach();
    });
    $('builderAttachOpen')?.addEventListener('click', openAttach);
    $('builderClassifyAttachOpen')?.addEventListener('click', function () { dialogs.classify.close(); openAttach(); });
}());
