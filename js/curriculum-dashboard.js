(function () {
  'use strict';
  const root=document.getElementById('curriculumDashboard');
  if(!root || root.dataset.canViewCurriculum!=='1' || !window.CurriculumUI)return;
  const ui=window.CurriculumUI, $=id=>document.getElementById(id), esc=ui.escapeHtml, getJson=ui.fetchJson;
  const state={program:null,curriculum:null,version:null,metricsRequest:0};
  function fail(message){$('dashboardError').textContent=message;$('dashboardError').hidden=false;}
  function clearError(){$('dashboardError').hidden=true;}
  function resetDashboard(){
    state.metricsRequest++;
    clearError();$('currentSnapshot').hidden=true;$('dashboardResults').hidden=true;$('contextStatus').hidden=true;
    $('dashboardEmpty').hidden=false;$('dashboardEmpty').textContent='Select a Program / Strand, Curriculum, and Curriculum Version to view curriculum analytics.';
  }
  function contextHref(page){
    if(!state.version || !state.curriculum || !state.program)return page;
    return page+'?'+new URLSearchParams({program_id:state.program.id,curriculum_id:state.curriculum.id,version_id:state.version.id});
  }
  const levels=['program','curriculum','version'].map((key,index)=>{
    const cap=key[0].toUpperCase()+key.slice(1);
    return {
      input:$('dash'+cap+'Search'),valueInput:$('dash'+cap),list:$('dash'+cap+'Options'),
      minimumLength:0,showSuggestionsOnFocus:true,showSuggestionsOnEnable:true,
      placeholder:'Search '+(key==='version'?'version label':key+' code or name'),
      disabledPlaceholder:index===1?'Choose program first':'Choose curriculum first',
      noResultsText:'No matching '+(key==='version'?'versions':key==='curriculum'?'curricula':'programs')+' found.',
      search:async query=>{
        if(index===0)return (await getJson(root.dataset.programApi,{action:'list',search:query,per_page:50})).programs;
        if(index===1)return state.program ? (await getJson(root.dataset.curriculumApi,{action:'list',academic_program_id:state.program.id,search:query,per_page:50})).curricula : [];
        return state.curriculum ? (await getJson(root.dataset.versionApi,{action:'list',curriculum_id:state.curriculum.id,search:query,per_page:50})).versions : [];
      },
      format:item=>index===0 ? {label:item.program_code+' - '+item.program_name,code:item.program_code,title:item.program_name}
        : index===1 ? {label:item.curriculum_code+' - '+item.curriculum_name,code:item.curriculum_code,title:item.curriculum_name}
        : {label:item.version_label,code:item.version_label,title:item.effective_academic_year||'',meta:item.status},
      onError:error=>fail(error.message || 'Context records could not be loaded. Retry the search.')
    };
  });
  const cascade=ui.createCascadingAutocomplete({
    levels,onChange:(index,item,id,action)=>{
      resetDashboard();
      if(index===0){state.program=action==='select'?item:null;state.curriculum=null;state.version=null;}
      if(index===1){state.curriculum=action==='select'?item:null;state.version=null;}
      if(index===2){state.version=action==='select'?item:null;if(state.version)loadMetrics();}
    }
  });
  document.querySelectorAll('.program-row').forEach(button=>button.addEventListener('click',()=>{
    cascade.get(0).setSelected({id:button.dataset.programId,program_code:button.querySelector('strong').textContent,program_name:button.querySelector('b').textContent});
  }));
  if(root.dataset.canViewPrograms==='0'){$('dashProgramSearch').disabled=true;fail('Program browsing requires the Academic Programs view permission.');}

  async function loadMetrics() {
    if (!state.version) return;
    const request=++state.metricsRequest, versionId=String(state.version.id), curriculumId=String(state.curriculum.id), programId=String(state.program.id);
    clearError();
    const empty = $('dashboardEmpty');
    empty.hidden = false; empty.textContent = 'Loading curriculum analytics...';
    $('dashboardResults').hidden = true;
    try {
      const data = await getJson(root.dataset.analyticsApi, { version_id: versionId, curriculum_id: curriculumId, program_id: programId });
      if(request!==state.metricsRequest || String(state.version?.id)!==versionId)return;
      if(String(data.context.curriculum_id)!==curriculumId || String(data.context.program_id)!==programId)throw new Error('The selected context changed. Choose the version again.');
      const context = data.context, metrics = data.metrics;
      $('snapshotProgram').textContent = context.program_code + '  -  ' + context.program_name;
      $('snapshotCurriculum').textContent = context.curriculum_name;
      $('snapshotVersion').textContent = context.version_label + '  -  Technical status: ' + context.status;
      $('contextStatus').textContent = context.status; $('contextStatus').hidden = false;
      $('currentSnapshot').hidden = false;
      $('snapshotChips').innerHTML = `<span>${metrics.mapped_subjects} Mapped Subjects</span><span>${metrics.units} Units</span><span>${metrics.year_levels} Year Levels</span>`;
      $('openSnapshotCurriculum').href = contextHref('../modules/curriculum/pages/curricula.php');
      const links = {
        'Mapped Subjects': ['../modules/curriculum/pages/subject_mapping.php', true],
        'Total Units': ['../modules/curriculum/pages/subject_mapping.php', true],
        'Year Levels': ['../modules/curriculum/pages/subject_mapping.php', true],
        'Prerequisite Relationships': ['../modules/curriculum/pages/prerequisite_configuration.php', root.dataset.canViewPrerequisite === '1'],
        'Elective Groups': ['../modules/curriculum/pages/electives_manager.php', root.dataset.canViewElective === '1'],
        'Grade Weighting Status': ['../modules/curriculum/pages/grade_weighting_setup.php', root.dataset.canViewWeighting === '1']
      };
      const items = [
        ['Mapped Subjects', metrics.mapped_subjects, 'Selected version'], ['Total Units', metrics.units, 'Stored subject units'],
        ['Year Levels', metrics.year_levels, 'With placements'], ['Prerequisite Relationships', metrics.requirements, 'Stored prerequisite and corequisite rules'],
        ['Elective Groups', metrics.elective_groups, 'Stored system groups'], ['Grade Weighting Status', metrics.grade_status, metrics.weight_summary]
      ];
      $('metricGrid').innerHTML = items.map(item => {
        const link = links[item[0]];
        const card = `<small>${esc(item[0])}</small><strong>${esc(item[1])}</strong><span>${esc(item[2])}</span>`;
        return link[1] ? `<a class="metric-card metric-card-link" href="${contextHref(link[0])}">${card}</a>` : `<article class="metric-card">${card}</article>`;
      }).join('');
      $('analyticsSubtitle').textContent = 'Based on stored placements for ' + context.version_label + '.';
      $('analyticsGrid').innerHTML = barCard('Subjects by Year Level', data.years, 'subjects') +
        barCard('Units by Year Level', data.years, 'units') + barCard('Subjects by Semester', data.terms, null) +
        donut(data.required, data.optional, data.total) + prereq(metrics.mapped_subjects, metrics.subjects_with_prerequisites);
      $('dashboardResults').hidden = false; empty.hidden = true;
    } catch (error) {
      if(request!==state.metricsRequest || String(state.version?.id)!==versionId)return;
      fail(error.message); empty.textContent = 'Curriculum analytics could not be loaded. Please try selecting the version again.';
    }
  }
  function barCard(title, values, field) {
    const pairs = field ? Object.entries(values).map(([label, value]) => [label, value[field]]) : Object.entries(values);
    const max = Math.max(1, ...pairs.map(pair => Number(pair[1])));
    return `<a class="dashboard-card analytic-card metric-card-link" href="${contextHref('../modules/curriculum/pages/subject_mapping.php')}"><h3>${esc(title)}</h3>${pairs.map(([label, value]) => `<div class="bar-row"><span>${esc(label)}</span><div class="bar-track"><i style="width:${Math.max(0, Number(value) / max * 100)}%"></i></div><b>${value}</b></div>`).join('') || '<p>No stored placements.</p>'}</a>`;
  }
  function donut(required, optional, total) {
    const portion = total ? required / total * 100 : 0;
    return `<a class="dashboard-card analytic-card metric-card-link" href="${contextHref('../modules/curriculum/pages/subject_mapping.php')}"><h3>Placement Distribution</h3><div class="donut-layout"><div class="donut" style="--required:${portion}%"><b>${total}<small>placements</small></b></div><div class="donut-legend"><p><i class="legend-required"></i>Required <b>${required}</b></p><p><i class="legend-optional"></i>Optional <b>${optional}</b></p><small>Optional placement is not automatically elective membership.</small></div></div></a>`;
  }
  function prereq(total, withPrerequisites) {
    const values = [['Subjects with stored prerequisite relationships', withPrerequisites], ['Subjects without stored prerequisite relationships', Math.max(0, total - withPrerequisites)]];
    const content = `<h3>Prerequisite Relationships</h3>${values.map(([label, value]) => `<div class="bar-row"><span>${esc(label)}</span><div class="bar-track"><i style="width:${total ? value / total * 100 : 0}%"></i></div><b>${value}</b></div>`).join('')}<small>Subjects without stored prerequisites are not automatically considered incomplete.</small>`;
    return root.dataset.canViewPrerequisite === '1' ? `<a class="dashboard-card analytic-card metric-card-link" href="${contextHref('../modules/curriculum/pages/prerequisite_configuration.php')}">${content}</a>` : `<article class="dashboard-card analytic-card">${content}</article>`;
  }
}());
