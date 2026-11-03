(function () {
    'use strict';
    const ui = window.CurriculumUI, root = document.getElementById('programAssignmentWorkspace');
    if (!ui || !root) return;
    const $ = id => document.getElementById(id), esc = ui.escapeHtml;
    const api = root.dataset.assignmentApi, view = root.dataset.view, manage = root.dataset.canManage === '1';
    const state = { context: null, records: [], page: 1, pages: 1, total: 0, selected: [], pendingRemove: null };
    const dialogs = { assign: ui.createModal($('assignModal')), remove: ui.createModal($('removeModal')), details: ui.createModal($('detailsModal')) };
    window.CSMSPageCIMFContext = () => ({view, [view==='program'?'academic_program_id':'subject_id']:$('contextId').value});
    const paramsForSearch = (type, q, context = false) => ({ action:'search', type, search:q,
        ...(context ? {context_type:view,context_id:$('contextId').value,available:'1'} : {}) });
    const contextType = view === 'program' ? 'program' : 'subject';
    const otherType = view === 'program' ? 'subject' : 'program';
    const label = (type, r) => type === 'program' ? `${r.program_code} — ${r.program_name}` : `${r.subject_code} — ${r.subject_title}`;
    const fmt = (type, r) => type === 'program'
        ? ({label:label(type,r),code:r.program_code,title:r.program_name,meta:r.program_type || ''})
        : ({label:label(type,r),code:r.subject_code,title:r.subject_title,meta:`${r.units} units`});
    const contextPicker = ui.createAutocomplete({input:$('contextSearch'),valueInput:$('contextId'),list:$('contextOptions'),minimumLength:0,maxSuggestions:3,showSuggestionsOnFocus:true,
        search:async q=>(await ui.fetchJson(api,paramsForSearch(contextType,q))).records,getId:r=>r.id,format:r=>fmt(contextType,r),
        onSelect:loadContext,onClear:clearContext,errorText:'Records could not be searched.'});
    let assignPicker = null;
    function initAssignPicker() {
        if (!manage) return;
        assignPicker = ui.createAutocomplete({input:$('assignSearch'),valueInput:$('assignId'),list:$('assignOptions'),minimumLength:0,maxSuggestions:3,showSuggestionsOnFocus:true,
            search:async q=>(await ui.fetchJson(api,paramsForSearch(otherType,q,true))).records.filter(r=>!state.selected.some(x=>Number(x.id)===Number(r.id))),
            getId:r=>r.id,format:r=>fmt(otherType,r),onSelect:r=>{if(!state.selected.some(x=>Number(x.id)===Number(r.id)))state.selected.push(r);renderSelected();assignPicker.clear(false);$('assignSearch').focus();},
            onClear:()=>{},errorText:'Matching records could not be loaded.'});
    }
    function empty(message, action=false) { return `<div class="program-assignment-empty"><p>${esc(message)}</p>${action&&manage?'<button type="button" class="primary-action-button" id="emptyAssignButton">+ Assign</button>':''}</div>`; }
    function statusBadge(status) { return `<span class="status-badge ${status==='active'?'status-active':'status-archived'}">${esc(status)}</span>`; }
    function usageText(r) { const n=Number(r.curriculum_usage_count||0); return n ? `Used in ${n} Curriculum${n===1?'':'a'}` : 'Not Yet Mapped'; }
    function setFiltersVisible(visible) {
        $('assignmentFilters').hidden=!visible;
        $('assignmentSearch').disabled=!visible;
        $('statusFilter').disabled=!visible;
        $('perPage').disabled=!visible;
    }
    function renderSummary() {
        const r=state.context, box=$('assignmentSummary');
        if(!r){box.hidden=false;box.classList.add('assignment-summary-empty');box.innerHTML=`<div class="assignment-summary-placeholder"><strong>${view==='program'?'Program summary':'Subject summary'}</strong><span>${view==='program'?'Select a Program / Strand to view its summary.':'Select a subject to view its summary.'}</span></div>`;return;}
        box.hidden=false;box.classList.remove('assignment-summary-empty');
        if(view==='program') box.innerHTML=`<div class="assignment-summary-main"><strong class="assignment-summary-code">${esc(r.program_code)}</strong><strong class="assignment-summary-name">${esc(r.program_name)}</strong><span class="assignment-summary-meta">Type: ${esc(r.program_type||'—')} · Status: ${esc(r.status)}</span></div><div class="assignment-summary-metric"><strong>${esc(r.assigned_count)}</strong><span>Assigned Subjects</span>${statusBadge(r.status)}</div>`;
        else box.innerHTML=`<div class="assignment-summary-main"><strong class="assignment-summary-code">${esc(r.subject_code)}</strong><strong class="assignment-summary-name">${esc(r.subject_title)}</strong><span class="assignment-summary-meta">${esc(r.units)} units · Status: ${esc(r.status)}</span></div><div class="assignment-summary-metric"><strong>${esc(r.assigned_count)}</strong><span>Assigned Programs / Strands</span><small>${esc(r.usage_count)} Curriculum Uses</small>${statusBadge(r.status)}</div>`;
    }
    async function loadContext(record) {
        root.querySelector('.mapping-results-card')?.classList.remove('assignment-context-empty');
        state.page=1;
        state.context=null;state.records=[];renderSummary();setFiltersVisible(false);
        $('assignButton').disabled=true;$('cimfReviewButton').disabled=true;
        $('assignmentRows').innerHTML='<tr><td colspan="5" class="state-cell">Loading assignment context…</td></tr>';
        try { state.context=(await ui.fetchJson(api,{action:'context',type:view,id:record.id})).record;renderSummary();
            $('assignButton').disabled=!manage||state.context.status!=='active';$('cimfReviewButton').disabled=false;
            $('contextStatus').textContent=view==='program'?`Showing subjects assigned to ${state.context.program_code}.`:`Showing Program / Strand assignments for ${state.context.subject_code}.`;
            setFiltersVisible(true);await loadRows();
        } catch(error) { state.context=null;renderSummary();$('assignButton').disabled=true;$('cimfReviewButton').disabled=true;setFiltersVisible(false);
            $('contextStatus').textContent='The selected record could not be loaded. Choose it again to retry.';
            $('assignmentHead').innerHTML='';$('assignmentRows').innerHTML=`<tr><td class="state-cell">${esc(view==='program'?'Select a Program / Strand to view its assigned subjects.':'Select a subject to view its Program / Strand assignments.')}</td></tr>`;$('assignmentPagination').hidden=true;ui.showToast(error.message||'Selected record could not be loaded.','error'); }
    }
    function clearContext() {
        root.querySelector('.mapping-results-card')?.classList.add('assignment-context-empty');
        state.context=null;state.records=[];state.page=1;renderSummary();$('assignButton').disabled=true;$('cimfReviewButton').disabled=true;
        setFiltersVisible(false);$('assignmentSearch').value='';$('statusFilter').value='';
        $('contextStatus').textContent=view==='program'?'Select a Program / Strand to view its assigned subjects.':'Select a subject to view its Program / Strand assignments.';
        $('assignmentRows').innerHTML=`<tr><td class="state-cell">${esc(view==='program'?'Select a Program / Strand to view its assigned subjects.':'Select a subject to view its Program / Strand assignments.')}</td></tr>`;
        $('assignmentHead').innerHTML='';$('assignmentCount').textContent='0 records';$('assignmentPagination').innerHTML='';$('assignmentPagination').hidden=true;
    }
    function renderHead() {
        $('assignmentHead').innerHTML='<tr>'+(view==='program'?'<th>Subject Code</th><th>Subject Title</th><th>Units</th>':'<th>Program / Strand</th><th>Program Name</th>')+'<th>Assignment Status</th><th>Curriculum Usage</th><th>Actions</th></tr>';
    }
    function renderRows() {
        renderHead();
        const rows=state.records;
        $('assignmentRows').innerHTML=rows.length?rows.map(r=>`<tr>${view==='program'?`<td><strong>${esc(r.subject_code)}</strong></td><td>${esc(r.subject_title)}</td><td>${esc(r.units)}</td>`:`<td><strong>${esc(r.program_code)}</strong></td><td>${esc(r.program_name)}</td>`}<td>${statusBadge(r.assignment_status)}</td><td>${esc(usageText(r))}</td><td class="assignment-actions"><button type="button" class="row-action-button" data-view="${Number(r.id)}">View</button><button type="button" class="row-action-button assignment-ai-action" data-review="${Number(r.id)}"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> AI Review</button>${manage&&r.assignment_status==='active'?`<button type="button" class="remove-action-button" data-remove="${Number(r.id)}">Remove Assignment</button>`:''}</td></tr>`).join(''):`<tr><td colspan="5" class="state-cell">${empty(view==='program'?'No subjects have been assigned to this Program / Strand yet.':'This subject has not been assigned to any Program / Strand yet.',true)}</td></tr>`;
        $('assignmentRows').querySelector('#emptyAssignButton')?.addEventListener('click',openAssign);
        $('assignmentCount').textContent=`${state.total} record${state.total===1?'':'s'}`;
        $('assignmentPagination').innerHTML=state.pages>1?`<button class="button-muted" data-page="${state.page-1}" ${state.page<=1?'disabled':''}>Previous</button><span>Page ${state.page} of ${state.pages}</span><button class="button-muted" data-page="${state.page+1}" ${state.page>=state.pages?'disabled':''}>Next</button>`:'';
    }
    async function loadRows() {
        if(!state.context)return;
        $('assignmentRows').innerHTML='<tr><td colspan="5" class="state-cell">Loading assignments…</td></tr>';
        try {
            const payload=await ui.fetchJson(api,{action:'list',type:view,id:$('contextId').value,search:$('assignmentSearch').value.trim(),status:$('statusFilter').value,page:state.page,per_page:$('perPage').value});
            state.records=payload.assignments;state.page=payload.pagination.page;state.pages=payload.pagination.total_pages;state.total=payload.pagination.total_records;renderRows();
            $('assignmentPagination').hidden=state.pages<=1;
        } catch(error) { $('assignmentRows').innerHTML=`<tr><td colspan="5" class="state-cell">${esc(error.message||'Assignments could not be loaded.')}</td></tr>`;$('assignmentPagination').hidden=true;ui.showToast(error.message||'Assignments could not be loaded.','error'); }
    }
    async function refreshSummary() {
        if(!state.context)return;
        const p=await ui.fetchJson(api,{action:'context',type:view,id:$('contextId').value});state.context=p.record;renderSummary();
    }
    async function openAssign() {
        if(!manage||!state.context||state.context.status!=='active')return;
        state.selected=[];renderSelected();ui.clearFormError($('assignForm'));
        $('assignModalContext').textContent=view==='program'?label('program',state.context):label('subject',state.context);
        $('assignSearch').value='';$('assignId').value='';dialogs.assign.open();$('assignSearch').focus();
    }
    function renderSelected() {
        $('selectedTitle').textContent=`Selected (${state.selected.length})`;
        $('selectedRecords').innerHTML=state.selected.map((r,i)=>`<li><span><strong>${esc(view==='program'?r.subject_code:r.program_code)}</strong> — ${esc(view==='program'?r.subject_title:r.program_name)}</span><button type="button" class="row-action-button" data-remove-selected="${i}">Remove</button></li>`).join('')||'<li class="subtle-text">No records selected.</li>';
        $('assignForm').querySelector('[type="submit"]').disabled=state.selected.length===0;
    }
    function detailsMarkup(a) {
        const subject=`<div class="assignment-detail"><span>Subject</span><strong>${esc(a.subject_code)} — ${esc(a.subject_title)}</strong></div>`;
        const program=`<div class="assignment-detail"><span>Program / Strand</span><strong>${esc(a.program_code)} — ${esc(a.program_name)}</strong></div>`;
        const placement=a.placements.length?a.placements.map(p=>`<li><strong>${esc(p.curriculum_code)}</strong> / ${esc(p.version_label)}<br><span>${esc([p.year_level,p.semester].filter(Boolean).join(' · ')||'Placement not specified')}</span></li>`).join(''):'<li>Not Yet Mapped</li>';
        return `${subject}${program}<div class="assignment-detail"><span>Units</span><strong>${esc(a.units)}</strong></div><div class="assignment-detail"><span>Assignment Status</span><strong>${statusBadge(a.assignment_status)}</strong></div><h3>Curriculum Usage — Informational</h3><ul class="placement-list">${placement}</ul><p class="subtle-text">Curriculum placement is managed in Subject Mapping and cannot be edited here.</p><a class="button-muted" href="subject_mapping.php?program_id=${encodeURIComponent(a.academic_program_id)}&subject_id=${encodeURIComponent(a.subject_id)}">View in Subject Mapping</a>`;
    }
    async function openDetails(id) { try { const p=await ui.fetchJson(api,{action:'details',id});$('detailsBody').innerHTML=detailsMarkup(p.assignment);dialogs.details.open(); } catch(e){ui.showToast(e.message||'Assignment details could not be loaded.','error');} }
    function openRemove(id) {
        const r=state.records.find(x=>Number(x.id)===Number(id));if(!r||!manage)return;
        state.pendingRemove=id;ui.clearFormError($('removeModal'));
        $('removeMessage').textContent=`Remove ${r.subject_code} — ${r.subject_title} from ${r.program_code} — ${r.program_name}?`;
        dialogs.remove.open();
    }
    function showCimfReview(id) { if(state.context) window.CSMSCIMF?.open('review',id ? {assignment_id:id} : {}); }
    $('assignmentRows').addEventListener('click',e=>{const v=e.target.closest('[data-view]'),r=e.target.closest('[data-remove]'),review=e.target.closest('[data-review]');if(v)openDetails(v.dataset.view);if(r)openRemove(r.dataset.remove);if(review)showCimfReview(review.dataset.review);});
    $('assignmentPagination').addEventListener('click',e=>{const b=e.target.closest('[data-page]');if(b&&!b.disabled){state.page=Number(b.dataset.page);loadRows();}});
    $('selectedRecords')?.addEventListener('click',e=>{const b=e.target.closest('[data-remove-selected]');if(b){state.selected.splice(Number(b.dataset.removeSelected),1);renderSelected();}});
    $('assignButton').addEventListener('click',openAssign);$('cimfReviewButton').addEventListener('click',()=>showCimfReview());
    $('assignmentSearch').addEventListener('input',ui.debounce(()=>{state.page=1;loadRows();},250));$('statusFilter').addEventListener('change',()=>{state.page=1;loadRows();});$('perPage').addEventListener('change',()=>{state.page=1;loadRows();});
    $('assignForm')?.addEventListener('submit',async e=>{e.preventDefault();if(!state.selected.length)return;const data=new FormData();data.set('action','assign');data.set('view',view);data.set('context_id',$('contextId').value);data.set('record_ids',JSON.stringify(state.selected.map(r=>Number(r.id))));ui.setFormSubmitting(e.currentTarget,true,'Assigning…');try{const result=await ui.postForm(api,data);dialogs.assign.close();ui.showToast(result.message,'success');await refreshSummary();await loadRows();}catch(error){ui.showFormError(e.currentTarget,error.message||'Assignments could not be saved.');}finally{ui.setFormSubmitting(e.currentTarget,false);}});
    $('confirmRemove')?.addEventListener('click',async function(){if(!state.pendingRemove)return;const data=new FormData();data.set('action','remove');data.set('id',state.pendingRemove);this.disabled=true;try{const p=await ui.postForm(api,data);dialogs.remove.close();ui.showToast(p.message,'updated');state.pendingRemove=null;await refreshSummary();await loadRows();}catch(error){ui.showFormError($('removeModal'),error.message||'Assignment could not be removed.');}finally{this.disabled=false;}});
    initAssignPicker();clearContext();
}());
