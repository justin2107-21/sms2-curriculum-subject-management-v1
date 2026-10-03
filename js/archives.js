(() => {
  const panel = document.getElementById('archivesPanel');
  if (!panel || panel.hidden) return;
  const $ = id => document.getElementById(id);
  const filters = $('archiveFilters');
  const labels = {program:'Academic Programs', curriculum:'Curricula', subject:'Subjects', elective_group:'Elective Groups'};
  const api = 'archive_actions.php';
  const canRestore = panel.dataset.canRestore === '1';
  let page = 1, pages = 1, selected = null, pending = false, timer, request = 0;
  const format = value => value ? new Date(value.replace(' ', 'T') + 'Z').toLocaleString('en-PH', {timeZone:'Asia/Manila',year:'numeric',month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}) : 'Unknown';
  const node = (tag, className, value) => { const n = document.createElement(tag); if(className) n.className = className; if(value != null) n.textContent = value; return n; };
  async function call(params, post = false) {
    const options = post ? {method:'POST', body:params} : {};
    const url = post ? api : api + '?' + params.toString();
    const response = await fetch(url, options);
    const data = await response.json();
    if (!response.ok || !data.success) throw new Error(data.message || 'Request failed.');
    return data;
  }
  function show(modal) { modal.hidden = false; document.body.classList.add('archive-modal-open'); modal.querySelector('button[data-archive-close]')?.focus(); }
  function close() { document.querySelectorAll('.archive-modal').forEach(m => m.hidden = true); document.body.classList.remove('archive-modal-open'); }
  document.querySelectorAll('[data-archive-close]').forEach(el => el.addEventListener('click', close));
  document.addEventListener('keydown', e => { if(e.key === 'Escape') close(); });
  function renderSummary(counts) {
    const entries = [['All', Object.values(counts).reduce((a,b) => a + b, 0)], ['Programs', counts.program], ['Curricula', counts.curriculum], ['Subjects', counts.subject], ['Other', counts.elective_group]];
    $('archiveSummary').replaceChildren(...entries.map(([label, value]) => { const card = node('div','archive-summary-card'); card.append(node('span','',label),node('strong','',String(value || 0))); return card; }));
    $('archiveChips').replaceChildren(...[['','All'],...Object.entries(labels).map(([key,value]) => [key,value])].map(([key,value]) => {
      const button = node('button',filters.elements.module.value === key ? 'selected' : '',value);
      button.type='button'; button.addEventListener('click',() => { filters.elements.module.value=key; page=1; load(); }); return button;
    }));
  }
  async function load() {
    const sequence = ++request;
    const params = new URLSearchParams(new FormData(filters));
    params.set('action','list'); params.set('page',String(page)); params.set('per_page',$('archivePageSize').value);
    const rows = $('archiveRows'); rows.replaceChildren();
    try {
      const data = await call(params);
      if(sequence !== request) return;
      page = data.page; pages = data.pages;
      renderSummary(data.counts);
      const actorFilter=filters.elements.archived_by, old=actorFilter.value;
      actorFilter.replaceChildren(new Option('Anyone',''),new Option('Unknown','unknown'),...data.actors.map(a => new Option(a.name,a.id)));
      actorFilter.value=old;
      for (const record of data.records) {
        const tr=node('tr'); const identity=node('td','archive-identity');
        identity.append(node('strong','',record.identifier),node('span','',record.title || ''));
        const statusCell=node('td'); statusCell.append(node('span','archive-status','Archived'));
        tr.append(identity,node('td','',labels[record.module_key] || record.module_key),node('td','',record.context || '—'),
          node('td','',format(record.archived_at)),node('td','',record.actor_name || 'Unknown'),statusCell);
        const actions=node('td','archive-actions');
        const view=node('button','','View'); view.type='button'; view.addEventListener('click',() => details(record.module_key,record.record_id)); actions.append(view);
        if(canRestore) { const restore=node('button','','Restore'); restore.type='button'; restore.addEventListener('click',async() => { await details(record.module_key,record.record_id,true); }); actions.append(restore); }
        tr.append(actions); rows.append(tr);
      }
      const empty=$('archiveEmpty'); empty.hidden=data.records.length !== 0;
      if(!empty.hidden) { empty.replaceChildren(node('strong','',data.total===0 && ![...new FormData(filters).values()].some(v => v && v !== 'all') ? 'No archived records.' : 'No archived records match your filters.'));
        if(data.total===0) { const p=node('p','', 'Archived records from CSMS modules will appear here.'); empty.append(p); }
        const clear=node('button','','Clear Filters'); clear.type='button'; clear.addEventListener('click',() => {filters.reset();page=1;load();}); empty.append(clear);
      }
      $('archivePageStatus').textContent = data.total + ' records · Page ' + page + ' of ' + pages;
      $('archivePrev').disabled=page<=1; $('archiveNext').disabled=page>=pages;
    } catch(error) { $('archiveEmpty').hidden=false; $('archiveEmpty').textContent=error.message; }
  }
  async function details(module,id,confirmation=false) {
    try {
      const params=new URLSearchParams({action:'details',module,id});
      const data=await call(params); selected=data.record;
      const body=$('archiveDetailsBody'); body.replaceChildren();
      body.append(node('div','archive-detail-code',selected.identifier),node('p','archive-detail-title',selected.title || ''));
      const fields=[['Source Module',selected.module_label],['Previous Status',selected.original_status || 'Unknown'],['Archived On',format(selected.archived_at)],['Archived By',selected.archived_by || 'Unknown']];
      if(selected.units != null) fields.splice(1,0,['Units',selected.units]);
      const dl=node('dl','archive-detail-grid'); for(const [label,value] of fields) dl.append(node('dt','',label),node('dd','',value)); body.append(dl);
      if(selected.context.length) { body.append(node('h3','', 'Original Context')); for(const item of selected.context) {
        const line=node('p','archive-context',item.label + ': ' + item.value + ' — ' + item.state);
        if(item.module && item.archived) { const link=node('button','','View archived parent'); link.type='button'; link.addEventListener('click',()=>details(item.module,item.id)); line.append(' ',link); }
        body.append(line);
      }}
      if(selected.issues.length) { const issues=node('div','archive-issues'); selected.issues.forEach(issue => issues.append(node('p','',issue))); body.append(issues); }
      if(canRestore) $('archiveDetailsRestore').disabled=!selected.can_restore;
      close(); if(confirmation && selected.can_restore) confirmRestore(); else show($('archiveDetailsModal'));
    } catch(error) { if (typeof showToast === 'function') showToast(error.message, 'error'); load(); }
  }
  function confirmRestore() { if(!selected || !selected.can_restore) return; close(); $('archiveConfirmTitle').textContent='Restore this ' + selected.module_label.toLowerCase().replace(/s$/,'') + '?'; $('archiveConfirmName').textContent=selected.identifier; $('archiveRestoreError').hidden=true; show($('archiveConfirmModal')); }
  $('archiveDetailsRestore')?.addEventListener('click',confirmRestore);
  $('archiveConfirmRestore').addEventListener('click',async() => {
    if(pending || !selected) return; pending=true; const button=$('archiveConfirmRestore'); button.disabled=true;
    const data=new FormData(); data.set('action','restore'); data.set('module',selected.module); data.set('id',selected.id); data.set('_csrf_token',document.querySelector('meta[name="csrf-token"]').content);
    try { await call(data,true); close(); selected=null; load(); }
    catch(error) { $('archiveRestoreError').textContent=error.message; $('archiveRestoreError').hidden=false; }
    finally {pending=false;button.disabled=false;}
  });
  filters.addEventListener('submit',e=>e.preventDefault());
  filters.addEventListener('input',() => {if(filters.elements.date.value==='custom' && (!filters.elements.from.value || !filters.elements.to.value)) return; clearTimeout(timer);timer=setTimeout(() => {page=1;load();},300);});
  filters.addEventListener('change',() => {const custom=filters.elements.date.value==='custom';filters.elements.from.hidden=!custom;filters.elements.to.hidden=!custom;if(!custom || (filters.elements.from.value && filters.elements.to.value)) {page=1;load();}});
  $('archivePageSize').addEventListener('change',()=>{page=1;load();});
  $('archivePrev').addEventListener('click',()=>{if(page>1){page--;load();}});
  $('archiveNext').addEventListener('click',()=>{if(page<pages){page++;load();}});
  load();
})();
