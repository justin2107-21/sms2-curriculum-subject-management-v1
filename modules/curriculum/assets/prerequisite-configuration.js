(function () {
    'use strict';
    const ui = window.CurriculumUI, $ = id => document.getElementById(id), esc = ui.escapeHtml;
    const root = $('prerequisiteWorkspace'), api = root.dataset.requirementApi, manage = root.dataset.canManageRequirements === '1';
    const state = { placements: [], edges: [], review: {}, context: [null, null, null], request: 0, focus: null, target: null, draft: [], expected: [], editing: false, remove: null, loaded: false };
    window.CSMSPageCIMFContext = () => ({academic_program_id:$('programId').value,curriculum_id:$('curriculumId').value,curriculum_version_id:$('versionId').value,subject_id:$('focusId').value});
    window.PrerequisiteCIMFContext = () => ({ versionId: $('versionId').value, revision: state.request, context: state.context, placements: state.placements, loaded: state.loaded });
    const row = id => state.placements.find(p => Number(p.subject_id) === Number(id));
    const rules = id => state.edges.filter(e => Number(e.subject_id) === Number(id));
    const dependents = id => [...new Set(state.edges.filter(e => Number(e.required_subject_id) === Number(id)).map(e => Number(e.subject_id)))];
    const placement = p => [p?.year_level || 'Missing year / grade', p?.semester || 'Missing semester'].join(' · ');
    const subject = (id, showPlacementLabel = false) => { const p = row(id); return '<div class="pr-subject"><strong>' + esc(p?.subject_code || 'Subject #' + id) + '</strong><span>' + esc(p?.subject_title || '') + '</span><small>' + (showPlacementLabel === true ? 'Placement \u00b7 ' : '') + esc(placement(p)) + '</small></div>'; };
    const mappingUrl = (subjectId = '') => 'subject_mapping.php?' + new URLSearchParams({ program_id: $('programId').value, curriculum_id: $('curriculumId').value, version_id: $('versionId').value, subject_id: subjectId });
    const controls = (id, name, search, onSelect, extra = {}) => ({ input: $(id + 'Search'), valueInput: $(id + 'Id'), list: $(id + 'Options'), minimumLength: 0, showSuggestionsOnFocus: true, showSuggestionsOnEnable: true, placeholder: 'Search ' + name,
        search: async q => await search(q), onSelect, ...extra });
    const cascade = ui.createCascadingAutocomplete({ restoreSavedContext: !new URLSearchParams(location.search).has('version_id'), levels: [
        controls('program', 'program code or name', async q => (await ui.fetchJson(root.dataset.programApi, { action: 'list', search: q, per_page: 50 })).programs, null, { getId: p => p.id, format: p => ({ label: p.program_code + ' — ' + p.program_name, code: p.program_code, title: p.program_name }) }),
        controls('curriculum', 'curriculum code or name', async q => (await ui.fetchJson(root.dataset.curriculumApi, { action: 'list', search: q, academic_program_id: $('programId').value, per_page: 50 })).curricula, null, { disabledPlaceholder: 'Choose program first', getId: p => p.id, format: p => ({ label: p.curriculum_code, code: p.curriculum_code, title: p.curriculum_name }) }),
        controls('version', 'version', async q => (await ui.fetchJson(root.dataset.versionApi, { action: 'list', search: q, curriculum_id: $('curriculumId').value, per_page: 50 })).versions, null, { disabledPlaceholder: 'Choose curriculum first', getId: p => p.id, format: p => ({ label: p.version_label, code: p.version_label, title: p.effective_academic_year || '' }) })
    ], onChange(index, item) { state.context[index] = item; for (let i = index + 1; i < 3; i++) state.context[i] = null; load(); } });
    const findSubjects = q => ui.searchRecords(state.placements,q,p=>[p.subject_code,p.subject_title]);
    const subjectControl = (id, callback) => ui.createAutocomplete(controls(id, 'subject code or title', findSubjects, callback, { getId: p => p.subject_id, format: p => ({ label: p.subject_code + ' — ' + p.subject_title, code: p.subject_code, title: p.subject_title, meta: placement(p) }), onClear: () => { if (id === 'focus') { state.focus = null; renderChain(); } else preview(); } }));
    const focus = subjectControl('focus', p => { state.focus = Number(p.subject_id); renderChain(); });
    const target = manage ? subjectControl('target', preview) : null, required = manage ? subjectControl('required', preview) : null;
    const dialogs = {}; ['details', 'editor', 'remove'].forEach(name => dialogs[name] = ui.createModal($(name + 'Modal')));
    function options(id, values, label) { const old = $(id).value; $(id).innerHTML = '<option value="">' + label + '</option>' + values.map(v => '<option value="' + esc(v) + '">' + esc(v) + '</option>').join(''); if (values.includes(old)) $(id).value = old; }
    async function load() {
        const request = ++state.request, version = $('versionId').value;
        root.dataset.contextReady = version && state.context.every(Boolean) ? '1' : '0';
        state.loaded = false; state.placements = []; state.edges = []; state.review = {}; state.focus = null; focus.clear(false);
        ['details', 'editor', 'remove'].forEach(n => dialogs[n]?.close());
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
    function renderRules() {
        const q = $('ruleSearch').value.trim().toLowerCase(), year = $('yearFilter').value, term = $('semesterFilter').value, configured = $('stateFilter').value;
        const rows = ui.searchRecords(state.placements,q,p=>[p.subject_code,p.subject_title]).filter(p => (!year || p.year_level === year) && (!term || p.semester === term) && (!configured || (rules(p.subject_id).length > 0) === (configured === 'with')));
        $('ruleCards').innerHTML = rows.map(p => { const edges = rules(p.subject_id), id = Number(p.subject_id); return '<article class="pr-rule" tabindex="0" role="button" aria-label="View prerequisite details for ' + esc(p.subject_code) + '" data-details="' + id + '"><div class="pr-rule-main">' + subject(id, true) + '<div class="pr-requires">' + (edges.length ? '<p class="eyebrow">REQUIRES</p><div class="pr-required-list">' + edges.map(e => subject(e.required_subject_id)).join('') + '</div>' : '<span class="pr-muted">No prerequisite configured</span>') + '</div></div><footer><span class="pr-badge">' + edges.length + ' Prerequisite' + (edges.length === 1 ? '' : 's') + '</span><div><button class="pr-link" data-details="' + id + '">View</button>' + (manage ? '<button class="pr-link" data-edit="' + id + '">Edit</button>' + (edges.length ? '<button class="pr-link pr-danger" data-remove="' + id + '">Remove</button>' : '') : '') + '</div></footer></article>'; }).join('') || '<p class="pr-empty">No subjects match the selected context and filters.</p>';
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
        renderDraft(); preview(); dialogs.editor.open();
    }
    function renderDraft() { $('existingRequired').innerHTML = state.editing ? '<p class="eyebrow">EXISTING PREREQUISITES</p>' + state.draft.map(id => '<div class="pr-draft-row">' + subject(id) + '<button type="button" class="pr-link pr-danger" data-draft-remove="' + id + '">Remove</button></div>').join('') : ''; }
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
        $('editorMapping').href = mappingUrl(t);
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
    function renderChain() {
        const id = state.focus, canvas = $('chainCanvas'); canvas.style.transform = ''; canvas.style.width = ''; canvas.style.height = '';
        $('dependents').innerHTML = id ? '<h3 class="eyebrow">SUBJECTS THAT DEPEND ON ' + esc(row(id)?.subject_code || id) + '</h3>' + (dependents(id).map(subject).join('') || '<p class="pr-muted">No subjects currently depend on this subject.</p>') : '';
        if (!id || !rules(id).length) { canvas.innerHTML = '<div class="pr-empty">' + (id ? 'No prerequisite chain configured for this subject.' : 'Select a subject to inspect its prerequisite chain.') + (id && manage ? '<p><button class="button-muted" data-add-focused>+ Add Prerequisite</button></p>' : '') + '</div>'; return; }
        const ids = new Set(), levels = new Map(), visiting = new Set(); let cyclic = false;
        function visit(node) { if (visiting.has(node)) { cyclic = true; return 0; } if (levels.has(node)) return levels.get(node); visiting.add(node); ids.add(node); const depths = rules(node).map(e => visit(Number(e.required_subject_id))); visiting.delete(node); const depth = depths.length ? Math.max(...depths) + 1 : 0; levels.set(node,depth); return depth; }
        visit(id);
        if (cyclic) { canvas.innerHTML = '<p class="pr-warning">A circular dependency affects this chain. Review the affected path in Validation Review.</p>'; return; }
        const groups = []; ids.forEach(node => { const level = levels.get(node); (groups[level] ||= []).push(node); });
        const width = Math.max(300,...groups.map(g => g.length * 260)), height = groups.length * 190, positions = new Map();
        groups.forEach((group,level) => group.forEach((node,i) => positions.set(node,{ x: (width-group.length*260)/2+i*260+15, y:level*190+15 })));
        let svg = '<svg aria-hidden="true" width="' + width + '" height="' + height + '"><defs><marker id="prArrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto"><path d="M0,0 L8,4 L0,8" fill="#8294b6"/></marker></defs>';
        state.edges.filter(e => ids.has(Number(e.subject_id)) && ids.has(Number(e.required_subject_id))).forEach(e => { const a = positions.get(Number(e.required_subject_id)), b = positions.get(Number(e.subject_id)); const x=a.x+115,y=a.y+108,tx=b.x+115,ty=b.y; svg += '<path d="M'+x+','+y+' C'+x+','+(y+40)+' '+tx+','+(ty-40)+' '+tx+','+ty+'" fill="none" stroke="#8294b6" stroke-width="1.5" marker-end="url(#prArrow)"/><text x="'+((x+tx)/2)+'" y="'+((y+ty)/2)+'" text-anchor="middle" fill="#64748b" font-size="9">REQUIRED BEFORE</text>'; });
        canvas.innerHTML = svg + '</svg>' + [...positions].map(([node,p]) => '<button class="pr-chain-node '+(node===id?'is-selected':'')+'" data-details="'+node+'" style="left:'+p.x+'px;top:'+p.y+'px">'+subject(node)+'</button>').join('');
        canvas.style.width=width+'px'; canvas.style.height=height+'px'; canvas.dataset.width=width; canvas.dataset.height=height;
        requestAnimationFrame(fit);
    }
    function fit() { const v=$('chainViewport'),c=$('chainCanvas'); if (!c.querySelector('svg')) return; const w=Number(c.dataset.width),h=Number(c.dataset.height),scale=Math.max(.8,Math.min(1,(v.clientWidth-24)/w,(v.clientHeight-24)/h)); c.style.transformOrigin='top left'; c.style.transform='translateX('+Math.max(0,(v.clientWidth-w*scale)/2)+'px) scale('+scale+')'; v.scrollLeft=0;v.scrollTop=0; }
    const reviewLabels = { cycles:'Circular Dependencies', duplicates:'Duplicate Rules', sequencing:'Sequencing Conflicts', missing:'Missing Placement' };
    function renderReview() {
        $('reviewCounts').innerHTML = Object.entries(reviewLabels).map(([key,label]) => '<button class="pr-panel pr-review-count" data-review="'+key+'"><strong>'+ (state.review[key]?.length || 0) +'</strong><span>'+label+'</span></button>').join('');
        const structural = ['cycles','duplicates','sequencing','missing'].reduce((sum,k)=>sum+(state.review[k]?.length||0),0);
        $('reviewIssues').innerHTML = !state.loaded ? '' : (!structural ? '<div class="pr-panel pr-success"><strong>No prerequisite conflicts detected.</strong><p>Current prerequisite rules contain no duplicate, circular, sequencing, or missing-placement conflicts.</p></div>' : '');
        Object.keys(reviewLabels).forEach(key => { const items=state.review[key] || []; if (!items.length) return; $('reviewIssues').innerHTML += '<section class="pr-panel" id="issue-'+key+'"><h3>'+reviewLabels[key]+'</h3>'+items.map(e => { const id=key==='cycles'?e[0]:Number(e.subject_id); return '<div class="pr-issue">'+(key==='cycles'?'<strong>'+e.map(n=>esc(row(n)?.subject_code||n)).join(' → ')+'</strong>':'<div class="pr-issue-pair"><div><small>REQUIRED</small>'+subject(e.required_subject_id)+'</div><span>→</span><div><small>TARGET</small>'+subject(e.subject_id)+'</div></div>')+(key==='sequencing'?'<p>'+esc(e.message || 'A prerequisite must be placed in an earlier academic period.')+'</p>':'')+(key==='missing'?'<p>Placement information is incomplete.</p>':'')+'<div class="pr-issue-actions"><button class="button-muted" data-'+(manage?'edit':'details')+'="'+id+'">'+(key==='duplicates'&&manage?'Resolve':'View Rule')+'</button>'+(['sequencing','missing'].includes(key)?'<a class="button-muted" href="'+mappingUrl(e.required_subject_id)+'">'+'Fix Placement'+'</a>':'')+'</div></div>'; }).join('')+'</section>'; });
    }
    document.addEventListener('click', event => {
        const b=event.target.closest('button,[data-details]'); if(!b)return;
        if(b.dataset.edit)openEditor(Number(b.dataset.edit));
        else if(b.dataset.remove)confirmRemoval(Number(b.dataset.remove));
        else if(b.dataset.draftRemove)confirmRemoval(state.target,Number(b.dataset.draftRemove));
        else if(b.dataset.details)details(Number(b.dataset.details));
        else if(b.hasAttribute('data-add-focused'))openEditor(state.focus);
        else if(b.dataset.review)$('issue-'+b.dataset.review)?.scrollIntoView({behavior:'smooth',block:'start'});
        else if(b.dataset.tab) { document.querySelectorAll('[data-tab]').forEach(tab=>{const active=tab===b;tab.setAttribute('aria-selected',String(active));tab.tabIndex=active?0:-1;$('panel-'+tab.dataset.tab).hidden=!active;});if(b.dataset.tab==='chain')fit(); }
    });
    $('removeModal')?.addEventListener('click', event => { if (event.target.closest('[data-modal-close]') && state.remove?.draft) dialogs.editor.open(); });
    $('removeModal')?.addEventListener('keydown', event => { if (event.key === 'Escape' && state.remove?.draft) dialogs.editor.open(); });
    $('ruleCards').addEventListener('keydown',e=>{if(e.target.matches('article')&&['Enter',' '].includes(e.key)){e.preventDefault();details(Number(e.target.dataset.details));}});
    document.querySelector('.pr-tabs').addEventListener('keydown',e=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(e.key))return;e.preventDefault();const tabs=[...document.querySelectorAll('[data-tab]')],i=tabs.indexOf(e.target),next=e.key==='Home'?0:e.key==='End'?2:(i+(e.key==='ArrowRight'?1:2))%3;tabs[next].click();tabs[next].focus();});
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
