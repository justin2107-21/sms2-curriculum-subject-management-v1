(function () {
  'use strict';
  const panel = document.getElementById('csmsCimfPanel');
  if (!panel) return;
  const $ = id => document.getElementById(id);
  const launcher = $('csmsCimfLauncher'), resize = $('csmsCimfResize');
  const profile = panel.dataset.profile, actions = JSON.parse(panel.dataset.actions), fields = JSON.parse(panel.dataset.fields);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let width = 420, mode = 'closed', fingerprint = '', controller = null, resultHtml = '', view = 'home', previousView = 'home', extra = {};
  let compareOptions = [], comparePickers = [], pickerGeneration = 0, busy = false;
  const maxWidth = () => Math.max(320, Math.floor(innerWidth * .65));
  const clamp = value => Math.min(Math.max(320, Number(value) || 420), maxWidth());
  function stored(key) { try { return localStorage.getItem(key); } catch (_) { return null; } }
  function persist() {
    try { localStorage.setItem('csms_cimf_width', String(width)); localStorage.setItem('csms_cimf_state', mode); } catch (_) { /* preferences optional */ }
  }
  width = clamp(stored('csms_cimf_width'));
  function size() { panel.style.width = width + 'px'; resize.setAttribute('aria-valuenow', mode === 'peek' ? '32' : String(width)); resize.setAttribute('aria-valuemax', String(maxWidth())); }
  function state(next) {
    mode = next; panel.hidden = next === 'closed'; panel.classList.toggle('cimf-peek', next === 'peek');
    $('csmsCimfPeek').hidden = next !== 'peek'; launcher.hidden = next !== 'closed'; launcher.setAttribute('aria-expanded', String(next === 'open'));
    size(); persist();
  }
  function value(...ids) { for (const id of ids) { const element = $(id); if (element?.value) return element.value; } return ''; }
  function labelValue(...ids) { for (const id of ids) { const element = $(id); if (element?.value) return element.tagName === 'SELECT' ? element.selectedOptions[0]?.textContent || '' : element.value; } return ''; }
  function context() {
    let data = window.CSMSPageCIMFContext?.() || {};
    if (!window.CSMSPageCIMFContext) {
      data = {academic_program_id: value('programId', 'contextProgramId', 'weightingProgram', 'exportProgram', 'historyProgram', 'compareProgram', 'validationProgram'),
        curriculum_id: value('curriculumId', 'contextCurriculumId', 'weightingCurriculum', 'exportCurriculum', 'historyCurriculum', 'compareCurriculum', 'validationCurriculum'),
        curriculum_version_id: value('versionId', 'contextVersionId', 'weightingVersion', 'exportVersion', 'historyVersion', 'validationVersion'),
        subject_id: value('focusId', 'historySubjectId'), year_level: value('exportYear', 'historyYear', 'yearFilter'), semester: value('exportSemester', 'historySemester', 'semesterFilter'),
        version_a_id: value('compareVersionA'), version_b_id: value('compareVersionB'),
        subject_a_id: value('subjectASelect'), subject_b_id: value('subjectBSelect')};
    }
    data = {...data, ...extra};
    return Object.fromEntries(Object.entries(data).filter(([key, item]) => fields.includes(key) && item !== '' && item !== null && item !== undefined));
  }
  function refresh() {
    const data = context(), next = JSON.stringify(data);
    const labels = [labelValue('programSearch', 'mappingProgramInput', 'curriculumContextProgramInput', 'contextProgramSearch', 'weightingProgramSearch', 'exportProgramSearch', 'historyProgramSearch', 'historyProgram', 'compareProgramSearch', 'complianceProgram'),
      labelValue('curriculumSearch', 'mappingCurriculumInput', 'curriculumContextCurriculumInput', 'contextCurriculumSearch', 'weightingCurriculumSearch', 'exportCurriculumSearch', 'historyCurriculumSearch', 'historyCurriculum', 'compareCurriculumSearch', 'complianceCurriculum'),
      labelValue('versionSearch', 'mappingVersionInput', 'curriculumContextVersionInput', 'contextVersionSearch', 'weightingVersionSearch', 'exportVersionSearch', 'historyVersionSearch', 'complianceVersion', 'compareVersionASearch', 'compareVersionBSearch')].filter(Boolean);
    const selected = [value('weightingPlacementSearch', 'historySubjectSearch', 'subjectASearch', 'focusSearch'), value('subjectBSearch')].filter(Boolean).join(' ↔ ');
    $('csmsCimfContext').textContent = (labels.length ? labels.join(' · ') : panel.dataset.title) + (selected ? ' · ' + selected : '') + (data.year_level || data.semester ? ' · ' + [data.year_level, data.semester].filter(Boolean).join(' · ') : '');
    const decision = window.CSMSPageCIMFStoredDecision?.();
    $('csmsCimfStoredDecision').hidden = !decision;
    if (decision) $('csmsCimfStoredDecisionText').textContent = ({equivalent:'Equivalent',not_equivalent:'Not Equivalent',needs_review:'Needs Further Review',needs_further_review:'Needs Further Review'}[decision.decision_status] || decision.decision_status) + (decision.archived_at ? ' (Archived)' : '');
    if (next !== fingerprint) {
      if (fingerprint) $('csmsCimfStatus').textContent = 'Context updated. Choose an action to review the current records.';
      fingerprint = next; controller?.abort(); controller = null; resultHtml = ''; show('home');
      comparePickers.forEach(picker => picker.destroy?.()); comparePickers = []; pickerGeneration++;
    }
  }
  function show(next) {
    view = next;
    for (const [key, id] of Object.entries({home:'csmsCimfHome', ask:'csmsCimfAsk', compare:'csmsCimfCompare', result:'csmsCimfResult'})) $(id).hidden = key !== next;
    $('csmsCimfBack').hidden = next === 'home';
    if (next === 'result') $('csmsCimfResult').innerHTML = resultHtml;
  }
  function open(action, input) {
    if (input) extra = input;
    state('open'); refresh();
    if (action && Object.hasOwn(actions, action)) choose(action);
    else $('csmsCimfCollapse').focus();
  }
  function close() { controller?.abort(); controller = null; resultHtml = ''; extra = {}; show('home'); state('closed'); launcher.focus(); }
  async function request(action, operation, data = context(), question = '') {
    controller?.abort(); const active = new AbortController(); controller = active;
    const form = new FormData();
    for (const [key, item] of Object.entries({operation, action, profile, route:panel.dataset.route, page_token:panel.dataset.pageToken, context:JSON.stringify(data), question, _csrf_token:panel.dataset.csrf})) form.set(key, item);
    const response = await fetch(panel.dataset.api, {method:'POST', body:form, credentials:'same-origin', headers:{Accept:'application/json'}, signal:active.signal});
    if (response.status === 401) { location.replace(panel.dataset.signin); throw new Error('Session expired.'); }
    let payload;
    try { payload = await response.json(); } catch (_) { throw new Error('CIMF returned an unreadable response.'); }
    if (!response.ok || !payload.success) throw new Error(payload.message || 'CIMF could not complete this request.');
    return payload;
  }
  const section = (title, text) => text ? '<section class="cimf-result-section"><h3>' + esc(title) + '</h3><p>' + esc(text) + '</p></section>' : '';
  const list = (title, items) => items?.length ? '<section class="cimf-result-section"><h3>' + esc(title) + '</h3><ul>' + items.map(item => '<li>' + esc(item) + '</li>').join('') + '</ul></section>' : '';
  async function analyze(action, data = context(), question = '') {
    if (busy) return;
    busy = true;
    const original = fingerprint; const restore = view;
    $('csmsCimfStatus').textContent = 'Preparing CIMF advisory…';
    $('csmsCimfActions').querySelectorAll('button').forEach(button => button.disabled = true);
    $('csmsCimfAsk').querySelector('button[type="submit"]').disabled = true;
    try {
      const payload = await request(action, 'analyze', data, question);
      if (fingerprint !== original) return;
      if (payload.guidance) resultHtml = section('Interface guidance', payload.guidance);
      else {
        const a = payload.analysis;
        resultHtml = '<p class="eyebrow">AI advisory · ' + esc(actions[action]) + '</p><span class="read-only-pill">' + esc(a.status) + '</span>' + section('Summary', a.summary)
          + (a.findings?.length ? '<section class="cimf-result-section"><h3>Findings</h3><ul>' + a.findings.map(item => '<li>' + (item.subject ? '<strong>' + esc(item.subject.code) + '</strong> — ' : '') + esc(item.observation) + '</li>').join('') + '</ul></section>' : '')
          + list('Similarities', a.similarities) + list('Differences', a.differences) + section('Why It Matters', a.why_it_matters)
          + list('Suggested Review', a.suggested_review) + section('Limitations', a.limitations);
      }
      previousView = restore === 'result' ? 'home' : restore; show('result'); $('csmsCimfStatus').textContent = 'Advisory ready. Human review required.';
    } catch (error) { if (error.name !== 'AbortError') $('csmsCimfStatus').textContent = error.message; }
    finally { busy = false; $('csmsCimfActions').querySelectorAll('button').forEach(button => button.disabled = false); $('csmsCimfAsk').querySelector('button[type="submit"]').disabled = false; }
  }
  async function compare(action) {
    previousView = resultHtml ? 'result' : 'home'; show('compare');
    const generation = ++pickerGeneration; $('csmsCimfSubjectPickers').innerHTML = ''; $('csmsCimfCompareSubmit').disabled = true;
    comparePickers.forEach(picker => picker.destroy?.()); comparePickers = [];
    const ui = window.CurriculumUI;
    if (!ui) { $('csmsCimfStatus').textContent = 'Subject comparison is unavailable on this page.'; return; }
    try {
      const versions = action === 'compare_versions';
      if (versions) {
        const selected = context();
        if (!selected.curriculum_id) throw new Error('Select a curriculum before comparing its versions.');
        compareOptions = null;
      } else if (profile !== 'subject_master') {
        const baseAction = profile === 'prerequisite_configuration' ? 'prerequisite_review' : profile === 'subject_mapping' ? 'review' : 'explain';
        const baseContext = context(); delete baseContext.subject_id; delete baseContext.other_subject_id;
        const payload = await request(baseAction, 'context', baseContext);
        if (generation !== pickerGeneration) return;
        compareOptions = payload.display.selected_subjects;
      }
      for (const side of ['A','B']) {
        const label = document.createElement('label'); label.className = 'form-field';
        label.innerHTML = '<span>' + (versions ? 'Version ' : 'Subject ') + side + '</span><div class="record-autocomplete"><input id="globalCimfSubject' + side + 'Search" type="search" placeholder="' + (versions ? 'Search curriculum version' : 'Search subject code or title') + '"><input id="globalCimfSubject' + side + 'Id" type="hidden"><button class="record-autocomplete-clear" type="button" data-autocomplete-clear aria-label="Clear selection ' + side + '" hidden>×</button><div class="record-autocomplete-options" role="listbox" id="globalCimfSubject' + side + 'Options" hidden></div></div>';
        $('csmsCimfSubjectPickers').append(label);
        comparePickers.push(ui.createAutocomplete({input:$('globalCimfSubject' + side + 'Search'), valueInput:$('globalCimfSubject' + side + 'Id'), list:$('globalCimfSubject' + side + 'Options'), minimumLength:1, maxSuggestions:3,
          search: async query => {
            if (versions) {
              const endpoint = new URL('../http/version_actions.php', location.href);
              return (await ui.fetchJson(endpoint.href, {action:'list',curriculum_id:context().curriculum_id,search:query,per_page:20})).versions.map(v => ({id:v.id,code:v.version_label,title:v.effective_academic_year || v.status}));
            }
            if (profile === 'subject_master') {
              const endpoint = new URL('../http/subject_actions.php', location.href);
              return (await ui.fetchJson(endpoint.href, {action:'list',search:query,per_page:20})).subjects.map(s => ({id:s.id,code:s.subject_code,title:s.subject_title}));
            }
            return compareOptions.filter(s => (s.code + ' ' + s.title).toLowerCase().includes(query.toLowerCase()));
          }, getId:s=>s.id, format:s=>({label:s.code + ' — ' + s.title, code:s.code, title:s.title}), onSelect:validateCompare, onClear:validateCompare}));
      }
      $('csmsCimfCompare').dataset.action = action; $('csmsCimfCompareSubmit').textContent = versions ? 'Compare Versions' : 'Compare Subjects'; $('csmsCimfStatus').textContent = 'Select two different ' + (versions ? 'versions of this curriculum.' : 'subjects from the current allowed context.');
    } catch (error) { if (error.name !== 'AbortError') $('csmsCimfStatus').textContent = error.message; }
  }
  function validateCompare() { const a=value('globalCimfSubjectAId'), b=value('globalCimfSubjectBId'); $('csmsCimfCompareSubmit').disabled = !a || !b || a === b; }
  function choose(action) {
    if (action === 'ask') { previousView = resultHtml ? 'result' : 'home'; show('ask'); $('csmsCimfQuestion').focus(); }
    else if (action === 'compare_versions' || (['compare', 'compare_subjects'].includes(action) && ['subject_master','subject_mapping','prerequisite_configuration','electives_manager'].includes(profile))) compare(action);
    else analyze(action);
  }
  $('csmsCimfActions').innerHTML = Object.entries(actions).map(([action, label]) => '<button class="button-muted" type="button" data-cimf-action="' + esc(action) + '"><span>' + esc(label) + '</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>').join('');
  $('csmsCimfActions').addEventListener('click', event => { const button=event.target.closest('[data-cimf-action]'); if (button) choose(button.dataset.cimfAction); });
  $('csmsCimfAsk').addEventListener('submit', event => { event.preventDefault(); analyze('ask', context(), $('csmsCimfQuestion').value.trim()); });
  $('csmsCimfCompare').addEventListener('submit', event => { event.preventDefault(); if ($('csmsCimfCompareSubmit').disabled) return; const versions=event.currentTarget.dataset.action === 'compare_versions'; analyze(event.currentTarget.dataset.action, {...context(), [versions?'version_a_id':'subject_id']:value('globalCimfSubjectAId'), [versions?'version_b_id':'other_subject_id']:value('globalCimfSubjectBId')}); });
  $('csmsCimfBack').addEventListener('click', () => { controller?.abort(); show(view === 'result' ? previousView : resultHtml ? 'result' : 'home'); });
  launcher.addEventListener('click', () => open()); $('csmsCimfClose').addEventListener('click', close);
  $('csmsCimfCollapse').addEventListener('click', () => state('peek')); $('csmsCimfPeek').addEventListener('click', () => open());
  let dragging = false;
  resize.addEventListener('pointerdown', event => { if (innerWidth <= 600) return; dragging = true; resize.setPointerCapture(event.pointerId); event.preventDefault(); });
  resize.addEventListener('pointermove', event => { if (!dragging) return; const proposed = innerWidth - event.clientX; if (proposed < 80) state('peek'); else { width = clamp(proposed); state('open'); } });
  resize.addEventListener('pointerup', () => { dragging = false; persist(); }); resize.addEventListener('pointercancel', () => { dragging = false; });
  resize.addEventListener('keydown', event => { if (event.key === 'ArrowLeft') { width=clamp(width+32);state('open');event.preventDefault(); } if (event.key === 'ArrowRight') { if (width<=320) state('peek'); else {width=clamp(width-32);state('open');} event.preventDefault(); } });
  panel.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
  window.addEventListener('resize', () => { width=clamp(width);size(); });
  let refreshQueued=false;
  function scheduleRefresh() { if(refreshQueued)return;refreshQueued=true;queueMicrotask(()=>{refreshQueued=false;refresh();}); }
  document.addEventListener('csms:context-changed', () => { extra={}; scheduleRefresh(); });
  document.addEventListener('change', event => { if (!panel.contains(event.target)) { extra={};scheduleRefresh(); } });
  document.addEventListener('input', event => { if (!panel.contains(event.target)) scheduleRefresh(); });
  document.addEventListener('click', event => {
    if (!panel.contains(event.target) && event.target !== launcher) scheduleRefresh();
    const shortcut=event.target.closest('[data-global-cimf]'); if(shortcut) {event.preventDefault();open(shortcut.dataset.globalCimf);}
    const review=event.target.closest('[data-cimf-subject]'); if(review) { window.CurriculumUI?.createModal($('detailsModal'))?.close();open('prerequisite_subject_review',{subject_id:review.dataset.cimfSubject}); }
  });
  new MutationObserver(mutations => { if (mutations.some(m => !panel.contains(m.target) && m.target !== launcher && !(m.target === panel))) scheduleRefresh(); }).observe(document.querySelector('main.content') || document.querySelector('.content') || document.body, {childList:true,subtree:true});
  window.CSMSCIMF = {open, close, collapse:()=>state('peek'), updateContext:scheduleRefresh};
  refresh(); const previousMode=stored('csms_cimf_state'); if(previousMode==='open'||previousMode==='peek') state(previousMode);
}());
