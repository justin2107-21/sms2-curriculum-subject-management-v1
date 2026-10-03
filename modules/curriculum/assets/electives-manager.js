(function () {
    'use strict';
    const $ = id => document.getElementById(id);
    const root = $('electivesWorkspace');
    if (!root || !window.CurriculumUI) return;
    const ui = window.CurriculumUI, esc = ui.escapeHtml, api = root.dataset.groupApi;
    const canManage = root.dataset.canManageElectives === '1';
    const labels = {empty:'Empty Elective Groups',selection:'Invalid Selection Rules',duplicate:'Duplicate Subjects',mismatch:'Placement Mismatches',unmapped:'Unmapped Subjects',inactive:'Inactive Subjects',context:'Missing Context'};
    const state = {data:null, request:0, tab:'groups', selected:null, edit:null, members:[], busy:false};
    const view = ui.createModal($('viewGroupModal')), editor = ui.createModal($('groupFormModal')), archive = ui.createModal($('archiveGroupModal'));
    const form = $('groupForm');
    const context = () => ({program_id:$('programId').value,curriculum_id:$('curriculumId').value,curriculum_version_id:$('versionId').value});
    const link = (page, subject) => page + '?' + new URLSearchParams({...context(),version_id:$('versionId').value,...(subject ? {subject_id:subject} : {})});
    const option = (value, label) => '<option value="'+esc(value)+'">'+esc(label ?? value)+'</option>';
    const placement = s => [s.year_level || 'Unset year',s.semester || 'Unset semester'].join(' / ');
    const cascade = ui.createCascadingAutocomplete({levels:['program','curriculum','version'].map((key,index) => ({
        input:$(key+'Search'),valueInput:$(key+'Id'),list:$(key+'Options'),minimumLength:0,showSuggestionsOnFocus:true,showSuggestionsOnEnable:true,
        placeholder:index === 0 ? 'Search program code or name' : 'Search '+key,disabledPlaceholder:index === 1 ? 'Choose program first' : 'Choose curriculum first',
        search:async query => (await ui.fetchJson(api,{action:'context_options',level:key,...context(),search:query})).options,
        format:item => ({label:[item.code,item.title].filter(Boolean).join(' — '),code:item.code,title:item.title || ''})
    })),onChange:() => {
        state.request++; state.data=null; state.selected=null;
        view?.close(); editor?.close(); archive?.close();
        ['groupSearch','yearFilter','termFilter','statusFilter'].forEach(id => { $(id).value=''; });
        if ($('createElectiveGroupButton')) $('createElectiveGroupButton').disabled=true;
        render();
        if (cascade.hasSelection()) load();
    }});
    async function load() {
        const ticket=++state.request;
        $('electiveState').hidden=false; $('electiveState').textContent='Loading elective groups…';
        $('groupsPanel').hidden=true; $('reviewPanel').hidden=true;
        try {
            const data=await ui.fetchJson(api,{action:'workspace',...context()});
            if (ticket!==state.request) return;
            state.data=data;
            $('yearFilter').innerHTML=option('','Year / Grade')+data.years.map(v=>option(v)).join('');
            $('termFilter').innerHTML=option('','Semester / Term')+data.terms.map(v=>option(v)).join('');
            if ($('createElectiveGroupButton')) $('createElectiveGroupButton').disabled=false;
            render();
        } catch(error) {
            if(ticket!==state.request) return;
            state.data=null; $('electiveState').textContent=error.message;
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
    const rule = g => '<div class="elective-rule"><strong>Choose '+Number(g.required_selections)+'</strong> of '+g.members.length+' eligible subjects</div>';
    function render() {
        const ready=!!state.data;
        $('electiveState').hidden=ready;
        $('groupsPanel').hidden=!ready || state.tab!=='groups'; $('reviewPanel').hidden=!ready || state.tab!=='review';
        if(!ready) {
            $('groupCards').innerHTML=''; $('reviewCounters').innerHTML=''; $('reviewIssues').innerHTML='';
            $('electiveState').innerHTML='<i class="fa-solid fa-bullseye"></i><h2>Select a Program, Curriculum, and Version</h2><p>Select context to view elective groups.</p>';
            return;
        }
        const groups=ui.searchRecords(state.data.groups,$('groupSearch').value,g=>[g.group_name]).filter(g=> (!$('yearFilter').value || g.year_level===$('yearFilter').value) && (!$('termFilter').value || g.semester===$('termFilter').value) && (!$('statusFilter').value || g.status===$('statusFilter').value));
        $('groupCards').innerHTML=groups.length?groups.map(g=>'<article class="elective-card"><div class="elective-card-top"><span class="elective-placement">'+esc(placement(g))+'</span>'+ui.statusBadge(g.status)+'</div><h2>'+esc(g.group_name)+'</h2>'+rule(g)+'<p class="elective-label">Eligible Subjects</p>'+g.members.map(s=>subjectMarkup(s)).join('')+'<div class="elective-card-actions"><button class="button-muted" data-view="'+Number(g.id)+'">View</button>'+(canManage?'<button class="button-muted" data-edit="'+Number(g.id)+'">Edit</button>'+(g.status!=='archived'?'<button class="button-muted" data-archive="'+Number(g.id)+'">Archive</button>':''):'')+'</div></article>').join(''):'<div class="elective-empty">'+(state.data.groups.length?'No elective groups match these filters.':'No elective groups yet. Create a group to define eligible subject choices.')+'</div>';
        const review=state.data.review;
        $('reviewCounters').innerHTML=Object.entries(labels).map(([key,label])=>'<div class="elective-counter">'+label+'<strong>'+Number(review.counts[key])+'</strong><small>'+(review.counts[key]?'Issues found':'No issues found')+'</small></div>').join('');
        $('reviewIssues').innerHTML=review.issues.length?'<section class="elective-card"><p class="elective-placement">Review required</p><h2>Elective group issues</h2>'+review.issues.map(issue=>{
            let action;
            if(['mismatch','unmapped'].includes(issue.type)) action='<a href="'+esc(link('subject_mapping.php',issue.subject_id))+'">Open Subject Mapping</a>';
            else if(issue.type==='inactive') action='<a href="'+esc(link('subjects.php',issue.subject_id))+'">Open Subject Master</a>';
            else action='<button class="button-muted" data-'+(canManage?'edit':'view')+'="'+issue.group_id+'">'+(canManage?'Edit':'View')+' Elective Group</button>';
            return '<div class="elective-issue"><div><strong>'+labels[issue.type]+'</strong><p>'+esc(issue.group_name)+' · '+esc(issue.message)+'</p></div>'+action+'</div>';
        }).join('')+'</section>':'<div class="elective-empty"><h2>No elective group issues detected.</h2><p>All reviewed elective groups contain valid subject choices and selection rules.</p></div>';
    }
    function openView(g) {
        state.selected=g; $('viewGroupTitle').textContent=g.group_name;
        $('viewGroupBody').innerHTML=ui.statusBadge(g.status)+rule(g)+'<p class="elective-placement">'+esc(placement(g))+'</p><p class="elective-label">Eligible Subjects</p>'+g.members.map(s=>subjectMarkup(s,true)).join('')+'<p><a href="'+esc(link('prerequisite_configuration.php'))+'">Pre-requisite Configuration</a> · <a href="'+esc(link('subject_mapping.php'))+'">Subject Mapping</a></p>';
        if($('viewArchive')) $('viewArchive').hidden=g.status==='archived';
        view.open();
    }
    function openEdit(g=null) {
        if(!canManage || !state.data || state.busy) return;
        view.close(); state.edit=g; state.members=g?g.members.map(s=>({...s})):[];
        form.reset(); ui.clearFormError(form);
        $('groupFormTitle').textContent=g?'Edit Elective Group':'Create Elective Group';
        form.elements.year_level.innerHTML=option('','Select year / grade')+[...new Set([...state.data.years,...(g?.year_level?[g.year_level]:[])])].map(v=>option(v)).join('');
        form.elements.semester.innerHTML=option('','Select semester / term')+[...new Set([...state.data.terms,...(g?.semester?[g.semester]:[])])].map(v=>option(v)).join('');
        form.elements.status.innerHTML=option('active','Active')+option('inactive','Inactive')+(g?.status==='archived'?option('archived','Archived'):'');
        if(g) ['group_name','year_level','semester','required_selections','status'].forEach(key=>{ form.elements[key].value=g[key] ?? ''; });
        subjectAutocomplete.clear(false); renderMembers(); editor.open();
    }
    function renderMembers() {
        $('subjectSearch').disabled=!form.elements.year_level.value || !form.elements.semester.value;
        $('selectedSubjects').innerHTML=state.members.map(s=>subjectMarkup(s,true,true)).join('');
        form.elements.required_selections.max=String(Math.max(1,state.members.length));
        const year=form.elements.year_level.value,term=form.elements.semester.value;
        const mismatch=state.members.find(s=>s.year_level!==year || s.semester!==term || !s.year_level || !s.semester);
        $('placementWarning').hidden=!mismatch || !year || !term;
        if(mismatch) {
            $('placementWarningText').textContent=(mismatch.subject_code || 'Subject')+' is currently placed in '+placement(mismatch)+' but this elective group is configured for '+year+' / '+term+'.';
            $('mappingLink').href=link('subject_mapping.php',mismatch.subject_id);
        }
        form.querySelector('[type="submit"]').disabled=!!mismatch || !state.members.length;
    }
    const subjectAutocomplete=ui.createAutocomplete({input:$('subjectSearch'),valueInput:$('subjectId'),list:$('subjectOptions'),minimumLength:0,showSuggestionsOnFocus:true,maxSuggestions:5,noResultsText:'No matching subjects found.',
        search:async query=>ui.searchRecords((state.data?.placements || []).filter(s=>s.subject_status==='active' && s.year_level && s.semester && s.year_level===form.elements.year_level.value && s.semester===form.elements.semester.value && !state.members.some(m=>Number(m.subject_id)===Number(s.subject_id))),query,s=>[s.subject_code,s.subject_title]),
        getId:s=>s.subject_id,format:s=>({label:s.subject_code+' — '+s.subject_title,code:s.subject_code,title:s.subject_title,meta:ui.formatUnits(s.units)+' Units · '+placement(s)}),
        onSelect:s=>{ if(!state.members.some(m=>Number(m.subject_id)===Number(s.subject_id))) state.members.push(s); subjectAutocomplete.clear(false);renderMembers(); }
    });
    function requestArchive(g) { if(!canManage || state.busy) return;view.close();state.selected=g;$('archiveGroupName').textContent=g.group_name;ui.clearFormError($('archiveGroupModal'));archive.open(); }
    root.addEventListener('click',event=>{
        const button=event.target.closest('[data-view],[data-edit],[data-archive]'); if(!button || !state.data) return;
        const kind=['view','edit','archive'].find(key=>button.dataset[key]);
        const g=state.data.groups.find(item=>Number(item.id)===Number(button.dataset[kind]));if(!g)return;
        if(kind==='view')openView(g);else if(kind==='edit')openEdit(g);else requestArchive(g);
    });
    ['groups','review'].forEach(key=>$(key+'Tab').addEventListener('click',()=>setTab(key)));
    document.querySelector('.elective-tabs').addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;event.preventDefault();setTab(event.key==='Home'?'groups':event.key==='End'?'review':state.tab==='groups'?'review':'groups');$(state.tab+'Tab').focus();});
    ['groupSearch','yearFilter','termFilter','statusFilter'].forEach(id=>$(id).addEventListener(id==='groupSearch'?'input':'change',render));
    $('createElectiveGroupButton')?.addEventListener('click',()=>openEdit());
    $('viewEdit')?.addEventListener('click',()=>openEdit(state.selected));
    $('viewArchive')?.addEventListener('click',()=>requestArchive(state.selected));
    $('cancelPlacement')?.addEventListener('click',()=>editor.close());
    $('selectedSubjects')?.addEventListener('click',event=>{const button=event.target.closest('[data-remove]');if(!button)return;state.members=state.members.filter(s=>Number(s.subject_id)!==Number(button.dataset.remove));renderMembers();});
    ['year_level','semester'].forEach(key=>form?.elements[key].addEventListener('change',()=>{subjectAutocomplete.clear(false);renderMembers();}));
    form?.addEventListener('submit',async event=>{
        event.preventDefault(); if(state.busy || !cascade.hasSelection())return;
        const count=Number(form.elements.required_selections.value);
        if(!Number.isInteger(count)||count<1||count>state.members.length){ui.showFormError(form,'Required selections must be at least 1 and cannot exceed the number of eligible subjects.');return;}
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
