(function () {
    'use strict';
    const $ = id => document.getElementById(id);
    const root = $('electivesWorkspace');
    if (!root || !window.CurriculumUI) return;
    const ui = window.CurriculumUI, esc = ui.escapeHtml, api = root.dataset.groupApi;
    const canManage = root.dataset.canManageElectives === '1';
    // Validation Review: one definition of the checks, computed here from the workspace (groups, members, placements).
    const meta = {
        empty: ['Empty group', 'A group with no subject in its pool'],
        small: ['Pool smaller than required', 'Fewer subjects in the pool than the group asks students to choose (by subject count)'],
        shared: ['Same subject in two groups', 'A subject in the pool of more than one active group; the schema allows it, so check it is intended'],
        inactive: ['Inactive subject in a pool', 'A pool subject whose status in Subject Master is not active'],
        noplacement: ['Group has no placement', 'A group with no year or semester, or no subject placed in that term'],
        mismatch: ['Pool subject placed in a different term', 'A pool subject whose placement in the Builder differs from the group\'s term']
    };
    const state = {data:null, request:0, tab:'groups', selected:null, edit:null, members:[], busy:false};
    window.CSMSPageCIMFContext = () => ({academic_program_id:$('programId').value,curriculum_id:$('curriculumId').value,curriculum_version_id:$('versionId').value,group_id:state.selected?.id || state.selected || ''});
    const view = ui.createModal($('viewGroupModal')), editor = ui.createModal($('groupFormModal')), archive = ui.createModal($('archiveGroupModal'));
    const form = $('groupForm');
    const warnModal = ui.createModal($('warnGroupModal'));
    const rowMenu = window.CsmsMenu.create($('electiveRowMenu'), { esc, onSelect: (action, data) => { const g = groupById(data.group); if (!g) return; if (action === 'view') openView(g); else if (action === 'edit') openEdit(g); else if (action === 'archive') requestArchive(g); } });
    const groupById = id => state.data?.groups.find(item => Number(item.id) === Number(id));
    const context = () => ({program_id:$('programId').value,curriculum_id:$('curriculumId').value,curriculum_version_id:$('versionId').value});
    const link = (page, subject) => page + '?' + new URLSearchParams({...context(),version_id:$('versionId').value,...(subject ? {subject_id:subject} : {})});
    const option = (value, label) => '<option value="'+esc(value)+'">'+esc(label ?? value)+'</option>';
    const yearText = v => window.CurriculumPlacementView?.yearLabel ? window.CurriculumPlacementView.yearLabel(v) : v;
    const stateBox = (title, text, icon = 'fa-bullseye') => { $('electiveState').innerHTML = '<i class="fa-solid ' + icon + '" aria-hidden="true"></i><strong>' + esc(title) + '</strong>' + (text ? '<span>' + esc(text) + '</span>' : ''); };
    const plural = (n, one, many) => '<strong>' + n + '</strong> ' + (n === 1 ? one : many);
    const unitsOf = members => Math.round(members.reduce((sum, m) => sum + Number(m.units || 0) * 100, 0)) / 100;
    const statusChip = status => '<span class="csms-chip csms-chip--' + esc(status) + '">' + esc(String(status).charAt(0).toUpperCase() + String(status).slice(1)) + '</span>';
    // Type is shown only when the workspace carries it (a later slice adds the column); an unset type reads "Type not set", never a guess.
    const typeNames = { professional: 'Professional Elective', ge: 'GE Elective', mandatory: 'Mandatory Elective', free: 'Free Elective' };
    const typeChip = g => typeNames[g.elective_type] ? '<span class="csms-chip csms-chip--type">' + esc(typeNames[g.elective_type]) + '</span>' : '<span class="csms-chip csms-chip--type-unset">Type not set</span>';
    const placement = s => [s.year_level || 'Unset year',s.semester || 'Unset semester'].join(' / ');
    const cascade = ui.createCascadingAutocomplete({levels:['program','curriculum','version'].map((key,index) => ({
        input:$(key+'Search'),valueInput:$(key+'Id'),list:$(key+'Options'),minimumLength:0,showSuggestionsOnFocus:true,showSuggestionsOnEnable:true,
        placeholder:index === 0 ? 'Search program code or name' : 'Search '+key,disabledPlaceholder:index === 1 ? 'Choose program first' : 'Choose curriculum first',
        search:async query => (await ui.fetchJson(api,{action:'context_options',level:key,...context(),search:query})).options,
        format:item => ({label:[item.code,item.title].filter(Boolean).join(' — '),code:item.code,title:item.title || ''})
    })),onChange:() => {
        state.request++; state.data=null; state.selected=null;
        view?.close(); editor?.close(); archive?.close(); warnModal.close(); rowMenu.close(false);
        ['groupSearch','yearFilter','termFilter','statusFilter'].forEach(id => { $(id).value=''; });
        if ($('createElectiveGroupButton')) $('createElectiveGroupButton').disabled=true;
        render();
        if (cascade.hasSelection()) load();
    }});
    async function load() {
        const ticket=++state.request;
        $('electiveState').hidden=false; stateBox('Loading elective groups…','','fa-spinner');
        $('groupsPanel').hidden=true; $('reviewPanel').hidden=true;
        try {
            const data=await ui.fetchJson(api,{action:'workspace',...context()});
            if (ticket!==state.request) return;
            state.data=data;
            $('yearFilter').innerHTML=option('','All Years')+data.years.map(v=>option(v,yearText(v))).join('');
            $('termFilter').innerHTML=option('','All Semesters')+data.terms.map(v=>option(v)).join('');
            if ($('createElectiveGroupButton')) $('createElectiveGroupButton').disabled=false;
            render();
        } catch(error) {
            if(ticket!==state.request) return;
            state.data=null; $('electiveState').hidden=false; stateBox(error.message,'','fa-triangle-exclamation');
            if ($('createElectiveGroupButton')) $('createElectiveGroupButton').disabled=true;
        }
    }
    function setTab(tab) {
        state.tab=tab;
        ['groups','review'].forEach(key=>{ const active=key===tab; $(key+'Tab').setAttribute('aria-selected',String(active));$(key+'Tab').tabIndex=active?0:-1; });
        render();
    }
    function subjectMarkup(subject, details=false, removable=false) {
        const prerequisites=(state.data?.prerequisites || []).filter(p=>Number(p.subject_id)===Number(subject.subject_id));
        return '<div class="elective-subject"><strong>'+esc(subject.subject_code || 'Missing subject')+'</strong><div><b>'+esc(subject.subject_title || '')+'</b><small>'+esc(ui.formatUnits(subject.units))+' Units'+(details?' · '+esc(placement(subject)):'')+'</small>'+
            (details && prerequisites.length ? '<small>Prerequisite: '+prerequisites.map(p=>esc(p.subject_code)).join(', ')+'</small>' : '')+
            '</div>'+(removable?'<button type="button" class="button-muted" data-remove="'+Number(subject.subject_id)+'" aria-label="Remove '+esc(subject.subject_code)+'">Remove</button>':'')+'</div>';
    }
    const rule = g => '<div class="elective-rule"><strong>Choose '+Number(g.required_selections)+'</strong> of '+g.members.length+' available subjects</div>'+(g.members.length >= 2 && Number(g.required_selections)===g.members.length?'<p class="elective-rule-warning">All available subjects are currently required. This group does not provide an alternative choice.</p>':'');
    // Groups by Year / Semester; a group with no term goes last. The warning icon opens that group's issues.
    function checkData() {
        const groups = state.data.groups.filter(g => g.status !== 'archived'), active = groups.filter(g => g.status === 'active'), out = { empty: [], small: [], shared: [], inactive: [], noplacement: [], mismatch: [] };
        const placed = new Set((state.data.placements || []).map(p => p.year_level + '\u0000' + p.semester));
        groups.forEach(g => {
            const n = g.members.length, term = g.year_level && g.semester;
            if (!n) out.empty.push({ g, detail: 'No subject in the pool.' });
            else if (n < Number(g.required_selections)) out.small.push({ g, detail: 'Pool has ' + n + (n === 1 ? ' subject' : ' subjects') + ' but students choose ' + Number(g.required_selections) + '.' });
            if (!term) out.noplacement.push({ g, detail: 'The group has no year or semester.' });
            else if (!placed.has(g.year_level + '\u0000' + g.semester)) out.noplacement.push({ g, detail: 'No subject is placed in ' + placement(g) + ' in this version.' });
            g.members.forEach(m => {
                if (term && (m.year_level !== g.year_level || m.semester !== g.semester)) out.mismatch.push({ g, m, detail: (m.subject_code || 'Subject') + ' is placed in ' + placement(m) + ', the group is in ' + placement(g) + '.' });
            });
        });
        active.forEach(g => g.members.forEach(m => { if (m.subject_status && m.subject_status !== 'active') out.inactive.push({ g, m, detail: 'Status: ' + m.subject_status + '.' }); }));
        const bySubject = new Map();
        active.forEach(g => g.members.forEach(m => { const k = Number(m.subject_id); if (!bySubject.has(k)) bySubject.set(k, { m, groups: [] }); bySubject.get(k).groups.push(g); }));
        bySubject.forEach(v => { if (v.groups.length > 1) out.shared.push({ m: v.m, groups: v.groups, g: v.groups[0], detail: v.groups.map(x => x.group_name).join(', ') }); });
        return out;
    }
    // Issues for one group, from the same checks as the Review tab.
    function issuesFor(g) {
        const data = checkData(), list = [];
        Object.entries(data).forEach(([key, rows]) => rows.forEach(r => { if (Number(r.g.id) === Number(g.id) || (r.groups || []).some(x => Number(x.id) === Number(g.id))) list.push({ kind: meta[key][0], text: r.detail }); }));
        return list;
    }
    function groupTables(groups) {
        const cmp = new Intl.Collator('en', { numeric: true, sensitivity: 'base' }).compare, last = (a, b) => (a === '') - (b === '') || cmp(a, b);
        const buckets = new Map();
        groups.slice().sort((a, b) => last(a.year_level || '', b.year_level || '') || last(a.semester || '', b.semester || '') || cmp(a.group_name, b.group_name) || a.id - b.id).forEach(g => {
            const key = (g.year_level || '') + '\u0000' + (g.semester || '');
            if (!buckets.has(key)) buckets.set(key, { year: g.year_level || '', term: g.semester || '', rows: [] });
            buckets.get(key).rows.push(g);
        });
        const showType = groups.some(g => 'elective_type' in g);
        let html = '';
        buckets.forEach(b => {
            html += '<div class="csms-term"><header><strong>' + esc(b.year ? yearText(b.year) : 'Year level not specified') + ' \u00b7 ' + esc(b.term || 'Semester not specified') + '</strong><span class="csms-term__count">' + b.rows.length + (b.rows.length === 1 ? ' group' : ' groups') + '</span></header><div class="csms-table-scroll"><table class="csms-ctable elective-table"><thead><tr><th class="csms-ctable__code" scope="col">Group</th>' + (showType ? '<th scope="col">Type</th>' : '') + '<th scope="col">Required</th><th scope="col">Pool</th><th class="csms-ctable__action" scope="col"><span class="sr-only">Issues</span></th><th class="csms-ctable__action" scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
            b.rows.forEach(g => {
                const id = Number(g.id), issues = issuesFor(g), n = g.members.length;
                html += '<tr class="csms-row-click" tabindex="0" data-row="' + id + '" aria-haspopup="dialog" aria-label="View pool for ' + esc(g.group_name) + '"><td class="csms-ctable__code">' + esc(g.group_name) + (g.status !== 'active' ? ' ' + statusChip(g.status) : '') + '</td>' + (showType ? '<td>' + typeChip(g) + '</td>' : '') +
                    '<td>Choose ' + Number(g.required_selections) + '</td><td>' + n + (n === 1 ? ' subject' : ' subjects') + ' \u00b7 ' + esc(ui.formatUnits(unitsOf(g.members))) + ' units</td>' +
                    '<td class="csms-ctable__action">' + (issues.length ? '<button type="button" class="csms-warn" data-warn="' + id + '" aria-label="' + issues.length + (issues.length === 1 ? ' issue' : ' issues') + ' for ' + esc(g.group_name) + '" title="' + issues.length + (issues.length === 1 ? ' issue' : ' issues') + '"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></button>' : '') + '</td>' +
                    '<td class="csms-ctable__action"><button type="button" class="csms-rowmenu" data-menu="' + id + '" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ' + esc(g.group_name) + '"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button></td></tr>';
            });
            html += '</tbody></table></div></div>';
        });
        return '<div class="csms-plan">' + html + '</div>';
    }
    function openMenu(button) {
        const g = groupById(button.dataset.menu); if (!g) return;
        const data = { group: g.id }, edit = canManage && g.status !== 'archived';
        rowMenu.open(button, [{ action: 'view', label: 'View pool', data }, ...(edit ? [{ action: 'edit', label: 'Edit group', data }, { action: 'archive', label: 'Archive group', data, danger: true }] : [])]);
    }
    function openWarn(g) {
        const issues = issuesFor(g); state.selected = g;
        $('warnGroupTitle').textContent = g.group_name + ' \u00b7 ' + issues.length + (issues.length === 1 ? ' issue' : ' issues');
        $('warnGroupBody').innerHTML = issues.map(i => '<div class="elective-issue-item"><strong>' + esc(i.kind) + '</strong><p>' + esc(i.text) + '</p></div>').join('') || '<p class="elective-muted">No issues.</p>';
        if ($('warnGroupEdit')) $('warnGroupEdit').hidden = g.status === 'archived';
        warnModal.open();
    }
    function emptyGroups() {
        if (state.data.groups.length) return '<div class="csms-empty"><i class="fa-solid fa-filter" aria-hidden="true"></i><strong>No groups match these filters</strong><button type="button" class="secondary-action-button" data-clear-filters>Clear filters</button></div>';
        return '<div class="csms-empty"><i class="fa-solid fa-layer-group" aria-hidden="true"></i><strong>No elective groups yet</strong><span>Create a group when this curriculum contains alternative subject choices.</span>'+(canManage?'<button type="button" class="primary-action-button" data-create-group>Create elective group</button>':'<span>Ask someone who can manage electives to create one.</span>')+'</div>';
    }
    const reviewUi = window.CsmsReview.create($('reviewChecks'), { esc, onAction: (action, id) => { const g = groupById(id); if (!g) return; if (action === 'edit' && g.status !== 'archived') openEdit(g); else openView(g); } });
    const groupAction = (g, label) => '<button type="button" class="csms-link" data-review-action="' + (canManage && g.status !== 'archived' ? 'edit' : 'view') + '" data-review-id="' + Number(g.id) + '">' + (canManage && g.status !== 'archived' ? 'Edit' : 'View') + ' group<span class="sr-only"> ' + esc(g.group_name) + '</span></button>';
    function renderReview() {
        const data = checkData(), builder = ' <a class="csms-link" href="' + esc(link('curriculum_builder.php')) + '">Open in Builder</a>';
        const groupCol = { header: 'Group', code: true, cell: r => esc(r.g.group_name) }, subjectCol = { header: 'Subject', cell: r => esc((r.m.subject_code || '') + ' ' + (r.m.subject_title || '')) }, detailCol = { header: 'Detail', cell: r => esc(r.detail) };
        const defs = {
            empty: { columns: [groupCol, detailCol], actions: r => groupAction(r.g) },
            small: { columns: [groupCol, detailCol], actions: r => groupAction(r.g) },
            shared: { severity: 'warning', columns: [subjectCol, { header: 'In groups', cell: r => esc(r.detail) }], actions: r => r.groups.map(x => groupAction(x)).join(' ') },
            inactive: { columns: [groupCol, subjectCol, detailCol], actions: r => groupAction(r.g) },
            noplacement: { columns: [groupCol, detailCol], actions: r => groupAction(r.g) + builder },
            mismatch: { columns: [groupCol, subjectCol, detailCol], actions: r => groupAction(r.g) + builder }
        };
        const checks = Object.keys(meta).map(key => ({ key, label: meta[key][0], hint: meta[key][1], rows: data[key], ...defs[key] }));
        reviewUi.render([{ title: 'Group checks', note: 'Selected version only; archived groups are not checked', checks }]);
        $('reviewResult').innerHTML = reviewUi.total() ? '' : '<div class="csms-empty"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><strong>No elective group issues detected.</strong><span>Checks cover empty and short pools, shared and inactive subjects, and placement.</span></div>';
    }
    function render() {
        const ready=!!state.data;
        $('electiveState').hidden=ready;
        $('groupsPanel').hidden=!ready || state.tab!=='groups'; $('reviewPanel').hidden=!ready || state.tab!=='review';
        if(!ready) {
            $('groupCards').innerHTML=''; $('reviewChecks').innerHTML=''; $('reviewResult').innerHTML='';
            stateBox('Select a Program, Curriculum, and Version','Select context to view elective groups.');
            $('groupSummary').innerHTML='';
            return;
        }
        const groups=ui.searchRecords(state.data.groups,$('groupSearch').value,g=>[g.group_name,...g.members.flatMap(m=>[m.subject_code,m.subject_title])]).filter(g=> (!$('yearFilter').value || g.year_level===$('yearFilter').value) && (!$('termFilter').value || g.semester===$('termFilter').value) && (!$('statusFilter').value ? g.status!=='archived' : g.status===$('statusFilter').value));
        const pools=new Set(groups.flatMap(g=>g.members.map(m=>Number(m.subject_id))));
        $('groupSummary').innerHTML=plural(groups.length,'group','groups')+' · '+plural(pools.size,'pool subject','pool subjects');
        $('groupCards').innerHTML=groups.length?groupTables(groups):emptyGroups();
        renderReview();
    }
    function openView(g) {
        state.selected=g; $('viewGroupTitle').textContent=g.group_name;
        const members=g.members;
        $('viewGroupBody').innerHTML='<div class="elective-meta">'+(('elective_type' in g)?typeChip(g):'')+'<span>'+esc(placement(g))+'</span>'+statusChip(g.status)+'</div>'+rule(g)+
            '<div class="csms-table-scroll"><table class="csms-ctable elective-pool"><thead><tr><th class="csms-ctable__code" scope="col">Code</th><th scope="col">Subject</th><th class="csms-ctable__num" scope="col">Units</th><th scope="col">Placement</th><th scope="col">Status</th></tr></thead><tbody>'+
            (members.map(m=>'<tr><td class="csms-ctable__code">'+esc(m.subject_code||'Missing subject')+'</td><td>'+esc(m.subject_title||'')+'</td><td class="csms-ctable__num">'+esc(ui.formatUnits(m.units))+'</td><td>'+esc(placement(m))+'</td><td>'+esc(m.subject_status||'')+'</td></tr>').join('')||'<tr><td colspan="5" class="csms-ctable__muted">No subjects in this pool.</td></tr>')+
            '</tbody></table></div><p class="elective-muted">Placements are edited in the <a class="csms-link" href="'+esc(link('curriculum_builder.php'))+'">Curriculum Builder</a>.</p>';
        if($('viewArchive')) $('viewArchive').hidden=g.status==='archived';
        if($('viewEdit')) $('viewEdit').hidden=g.status==='archived';
        view.open();
    }
    function openEdit(g=null) {
        if(!canManage || !state.data || state.busy || g?.status==='archived') return;
        view.close(); state.edit=g; state.members=g?g.members.map(s=>({...s})):[];
        form.reset(); ui.clearFormError(form);
        $('groupFormTitle').textContent=g?'Edit Elective Group':'Create Elective Group';
        form.elements.year_level.innerHTML=option('','Select year / grade')+[...new Set([...state.data.years,...(g?.year_level?[g.year_level]:[])])].map(v=>option(v)).join('');
        form.elements.semester.innerHTML=option('','Select year / grade first');
        form.elements.status.innerHTML=option('active','Active')+option('inactive','Inactive')+(g?.status==='archived'?option('archived','Archived'):'');
        if(g) ['group_name','year_level','semester','required_selections','status'].forEach(key=>{ form.elements[key].value=g[key] ?? ''; });
        updateTermOptions(g?.semester || '');
        subjectAutocomplete.clear(false); renderMembers(); editor.open();
    }
    function updateTermOptions(previous='') {
        const year=form.elements.year_level.value;
        const terms=state.data?.terms_by_year?.[year] || (state.data?.placements || []).filter(p=>p.year_level===year).map(p=>p.semester);
        form.elements.semester.innerHTML=option('',year?'Select semester / term':'Select year / grade first')+[...new Set(terms.filter(Boolean))].map(v=>option(v)).join('');
        if(terms.includes(previous)) form.elements.semester.value=previous;
    }
    function renderMembers() {
        $('subjectSearch').disabled=!form.elements.year_level.value || !form.elements.semester.value;
        $('selectedSubjects').innerHTML=state.members.length?'<p class="elective-selected-count">Selected subjects ('+state.members.length+')</p>'+state.members.map(s=>subjectMarkup(s,true,true)).join(''):'';
        form.elements.required_selections.max=String(Math.max(1,state.members.length));
        $('allRequiredWarning').hidden=state.members.length<2 || Number(form.elements.required_selections.value)!==state.members.length;
        const year=form.elements.year_level.value,term=form.elements.semester.value;
        const mismatch=state.members.find(s=>s.year_level!==year || s.semester!==term || !s.year_level || !s.semester);
        $('placementWarning').hidden=!mismatch || !year || !term;
        if(mismatch) {
            $('placementWarningText').textContent=(mismatch.subject_code || 'Subject')+' is currently placed in '+placement(mismatch)+' but this elective group is configured for '+year+' / '+term+'.';
            $('mappingLink').href=link('subject_mapping.php',mismatch.subject_id);
        }
        form.querySelector('[type="submit"]').disabled=!!mismatch || state.members.length<2;
    }
    const subjectAutocomplete=ui.createAutocomplete({input:$('subjectSearch'),valueInput:$('subjectId'),list:$('subjectOptions'),minimumLength:0,showSuggestionsOnFocus:true,maxSuggestions:5,noResultsText:'No matching subjects found.',
        search:async query=>ui.searchRecords((state.data?.placements || []).filter(s=>s.subject_status==='active' && s.year_level && s.semester && s.year_level===form.elements.year_level.value && s.semester===form.elements.semester.value && !state.members.some(m=>Number(m.subject_id)===Number(s.subject_id))),query,s=>[s.subject_code,s.subject_title]),
        getId:s=>s.subject_id,format:s=>({label:s.subject_code+' — '+s.subject_title,code:s.subject_code,title:s.subject_title,meta:ui.formatUnits(s.units)+' Units · '+placement(s)}),
        onSelect:s=>{ if(!state.members.some(m=>Number(m.subject_id)===Number(s.subject_id))) state.members.push(s); subjectAutocomplete.clear(false);renderMembers(); }
    });
    function requestArchive(g) { if(!canManage || state.busy) return;view.close();state.selected=g;$('archiveGroupName').textContent=g.group_name;ui.clearFormError($('archiveGroupModal'));archive.open(); }
    root.addEventListener('click',event=>{
        if(event.target.closest('[data-create-group]')) { openEdit(); return; }
        if(event.target.closest('[data-clear-filters]')) { ['groupSearch','yearFilter','termFilter','statusFilter'].forEach(id => { $(id).value=''; }); render(); return; }
        if(!state.data) return;
        const warn=event.target.closest('[data-warn]'); if(warn) { const g=groupById(warn.dataset.warn); if(g) openWarn(g); return; }
        const menuButton=event.target.closest('[data-menu]'); if(menuButton) { openMenu(menuButton); return; }
        const rowEl=event.target.closest('[data-row]'); if(rowEl) { const g=groupById(rowEl.dataset.row); if(g) openView(g); return; }
        const button=event.target.closest('[data-view],[data-edit],[data-archive]'); if(!button) return;
        const kind=['view','edit','archive'].find(key=>button.dataset[key]);
        const g=state.data.groups.find(item=>Number(item.id)===Number(button.dataset[kind]));if(!g)return;
        if(kind==='view')openView(g);else if(kind==='edit')openEdit(g);else requestArchive(g);
    });
    ['groups','review'].forEach(key=>$(key+'Tab').addEventListener('click',()=>setTab(key)));
    document.querySelector('.csms-tabs').addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;event.preventDefault();setTab(event.key==='Home'?'groups':event.key==='End'?'review':state.tab==='groups'?'review':'groups');$(state.tab+'Tab').focus();});
    $('groupSearch').addEventListener('keydown',e=>{if(e.key==='Escape'&&$('groupSearch').value){$('groupSearch').value='';render();}});
    $('groupSearch').addEventListener('input',ui.debounce?ui.debounce(render,150):render);
    ['yearFilter','termFilter','statusFilter'].forEach(id=>$(id).addEventListener('change',render));
    $('groupCards').addEventListener('keydown',e=>{if(e.target.matches?.('[data-row]')&&['Enter',' '].includes(e.key)){e.preventDefault();const g=groupById(e.target.dataset.row);if(g)openView(g);}});
    $('warnGroupEdit')?.addEventListener('click',()=>{warnModal.close();openEdit(state.selected);});
    $('createElectiveGroupButton')?.addEventListener('click',()=>openEdit());
    $('viewEdit')?.addEventListener('click',()=>openEdit(state.selected));
    $('viewArchive')?.addEventListener('click',()=>requestArchive(state.selected));
    $('cancelPlacement')?.addEventListener('click',()=>editor.close());
    $('selectedSubjects')?.addEventListener('click',event=>{const button=event.target.closest('[data-remove]');if(!button)return;state.members=state.members.filter(s=>Number(s.subject_id)!==Number(button.dataset.remove));renderMembers();});
    form?.elements.required_selections.addEventListener('input',renderMembers);
    ['year_level','semester'].forEach(key=>form?.elements[key].addEventListener('change',()=>{if(key==='year_level')updateTermOptions();subjectAutocomplete.clear(false);renderMembers();}));
    form?.addEventListener('submit',async event=>{
        event.preventDefault(); if(state.busy || !cascade.hasSelection())return;
        const count=Number(form.elements.required_selections.value);
        if(state.members.length<2){ui.showFormError(form,'Choose at least two available subjects to form an elective group.');return;}
        if(!Number.isInteger(count)||count<1||count>state.members.length){ui.showFormError(form,'Number of subjects to choose must be at least 1 and cannot exceed the available subjects.');return;}
        const data=new FormData(form);data.set('action',state.edit?'update':'create');if(state.edit)data.set('id',state.edit.id);
        Object.entries(context()).forEach(([key,value])=>data.set(key,value));state.members.forEach(s=>data.append('subject_ids[]',s.subject_id));
        state.busy=true;ui.setFormSubmitting(form,true);ui.clearFormError(form);
        try {await ui.postForm(api,data);editor.close();ui.showToast('Elective group saved.');await load();}
        catch(error){ui.showFormError(form,error.message);}
        finally{state.busy=false;ui.setFormSubmitting(form,false);if(!$('groupFormModal').hidden)renderMembers();}
    });
    $('confirmArchive')?.addEventListener('click',async()=>{
        if(state.busy || !state.selected)return;state.busy=true;$('confirmArchive').disabled=true;
        const data=new FormData();data.set('action','archive');data.set('id',state.selected.id);Object.entries(context()).forEach(([key,value])=>data.set(key,value));
        try{await ui.postForm(api,data);archive.close();ui.showToast('Elective group archived.');await load();}
        catch(error){ui.showFormError($('archiveGroupModal'),error.message);}
        finally{state.busy=false;$('confirmArchive').disabled=false;}
    });
    render();
})();
