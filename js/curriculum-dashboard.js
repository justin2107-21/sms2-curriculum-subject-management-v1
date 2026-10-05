(function () {
  'use strict';
  const root = document.getElementById('curriculumDashboard');
  if (!root || root.dataset.canViewCurriculum !== '1') return;
  const $ = id => document.getElementById(id);
  const state = { program: null, curriculum: null, version: null };
  const ids = { program: 0, curriculum: 1, version: 2 };

  async function getJson(endpoint, parameters) {
    const url = new URL(endpoint, window.location.href);
    Object.entries(parameters).forEach(([key, value]) => url.searchParams.set(key, String(value)));
    const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const payload = await response.json();
    if (!response.ok || !payload.success) throw new Error(payload.message || 'Dashboard data could not be loaded.');
    return payload;
  }
  function fail(message) {
    const error = $('dashboardError');
    error.textContent = message;
    error.hidden = false;
  }
  function clearError() { $('dashboardError').hidden = true; }
  function savedContext() {
    try { return JSON.parse(sessionStorage.getItem('csms.curriculum-context.' + (root.dataset.userId || 'guest')) || '{}'); }
    catch (_) { return {}; }
  }
  function publish(key, item, action) {
    document.dispatchEvent(new CustomEvent('csms:curriculum-context-selection', {
      detail: { index: ids[key], item: item || null, action }
    }));
  }
  function rank(text, query) {
    text = text.toLowerCase(); query = query.toLowerCase();
    if (text === query) return 0;
    if (text.startsWith(query)) return 1;
    if (text.split(/\s+/).some(word => word.startsWith(query))) return 2;
    return text.includes(query) ? 3 : 9;
  }
  function reset(key, persist) {
    state[key] = null;
    const input = $('dash' + key[0].toUpperCase() + key.slice(1) + 'Search');
    const hidden = $('dash' + key[0].toUpperCase() + key.slice(1));
    input.value = '';
    hidden.value = '';
    const clear = input.parentElement.querySelector('[data-autocomplete-clear]');
    if (clear) clear.hidden = true;
    if (key === 'program') {
      $('dashCurriculumSearch').disabled = true;
      $('dashVersionSearch').disabled = true;
    } else if (key === 'curriculum') $('dashVersionSearch').disabled = true;
    const options = $('dash' + key[0].toUpperCase() + key.slice(1) + 'Options');
    options.hidden = true;
    $('currentSnapshot').hidden = true;
    $('dashboardResults').hidden = true;
    $('dashboardEmpty').hidden = false;
    $('dashboardEmpty').textContent = 'Select a Program / Strand, Curriculum, and Curriculum Version to view curriculum analytics.';
    $('contextStatus').hidden = true;
    if (persist !== false) publish(key, null, 'clear');
  }
  function picker(key, onPick) {
    const cap = key[0].toUpperCase() + key.slice(1);
    const input = $('dash' + cap + 'Search');
    const hidden = $('dash' + cap);
    const list = $('dash' + cap + 'Options');
    async function records() {
      if (key === 'program') return (await getJson(root.dataset.programApi, { action: 'list', page: 1, per_page: 50, search: '', status: '' })).programs;
      if (key === 'curriculum') return (await getJson(root.dataset.curriculumApi, { action: 'list', page: 1, per_page: 50, search: '', status: '', academic_program_id: state.program.id })).curricula;
      return (await getJson(root.dataset.versionApi, { action: 'list', curriculum_id: state.curriculum.id, page: 1, per_page: 50, search: '' })).versions;
    }
    function label(item) {
      if (key === 'program') return item.program_code + ' - ' + item.program_name;
      if (key === 'curriculum') return item.curriculum_code + ' - ' + item.curriculum_name;
      return item.version_label;
    }
    async function search() {
      if (input.disabled) return;
      try {
        const all = await records();
        const query = input.value.trim();
        const matches = all.map(item => ({ item, rank: rank(label(item), query) }))
          .filter(entry => !query || entry.rank < 4)
          .sort((a, b) => a.rank - b.rank).slice(0, 3);
        list.replaceChildren();
        matches.forEach(({ item }) => {
          const option = document.createElement('button');
          option.type = 'button'; option.className = 'record-autocomplete-option'; option.setAttribute('role', 'option');
          const title = document.createElement('strong'); title.textContent = label(item); option.appendChild(title);
          if (key === 'version') {
            const meta = document.createElement('small');
            meta.textContent = [item.status, item.effective_academic_year].filter(Boolean).join('  -  ');
            option.appendChild(meta);
          }
          option.addEventListener('click', () => choose(item));
          list.appendChild(option);
        });
        list.hidden = matches.length === 0;
      } catch (error) { list.hidden = true; fail(error.message); }
    }
    function choose(item, restoring) {
      state[key] = item; hidden.value = item.id; input.value = label(item); list.hidden = true;
      const clear = input.parentElement.querySelector('[data-autocomplete-clear]');
      if (clear) clear.hidden = false;
      onPick(item);
      if (!restoring) publish(key, item, 'select');
    }
    input.addEventListener('input', () => {
      if (state[key]) reset(key, false);
      if (key === 'program') { reset('curriculum', false); reset('version', false); }
      if (key === 'curriculum') reset('version', false);
      publish(key, null, 'clear');
      search();
    });
    input.addEventListener('focus', search);
    document.addEventListener('click', event => { if (!list.parentElement.contains(event.target)) list.hidden = true; });
    return { search, choose };
  }

  const pickers = {};
  pickers.program = picker('program', () => { reset('curriculum', false); reset('version', false); $('dashCurriculumSearch').disabled = false; });
  pickers.curriculum = picker('curriculum', () => { reset('version', false); $('dashVersionSearch').disabled = false; });
  pickers.version = picker('version', loadMetrics);
  document.querySelectorAll('.program-row').forEach(button => button.addEventListener('click', () => {
    pickers.program.choose({ id: button.dataset.programId, program_code: button.querySelector('strong').textContent, program_name: button.querySelector('b').textContent });
  }));
  document.querySelectorAll('[data-autocomplete-clear]').forEach(button => button.addEventListener('click', () => {
    const search = button.closest('.record-autocomplete').querySelector('input[type=search]');
    reset(search.id.includes('Program') ? 'program' : search.id.includes('CurriculumSearch') ? 'curriculum' : 'version');
  }));

  async function restoreContext() {
    const context = savedContext();
    for (const key of ['program', 'curriculum', 'version']) {
      if (!context[key]?.id) break;
      if (key === 'curriculum' && state.program) $('dashCurriculumSearch').disabled = false;
      if (key === 'version' && state.curriculum) $('dashVersionSearch').disabled = false;
      const input = $('dash' + key[0].toUpperCase() + key.slice(1) + 'Search');
      input.value = context[key].query || '';
      let candidates;
      try {
        if (key === 'program') candidates = (await getJson(root.dataset.programApi, { action: 'list', page: 1, per_page: 50, search: context[key].query || '', status: '' })).programs;
        else if (key === 'curriculum') candidates = (await getJson(root.dataset.curriculumApi, { action: 'list', page: 1, per_page: 50, search: context[key].query || '', status: '', academic_program_id: state.program.id })).curricula;
        else candidates = (await getJson(root.dataset.versionApi, { action: 'list', curriculum_id: state.curriculum.id, page: 1, per_page: 50, search: context[key].query || '' })).versions;
        const item = candidates.find(candidate => String(candidate.id) === String(context[key].id));
        if (!item) break;
        pickers[key].choose(item, true);
      } catch (error) { fail(error.message); break; }
    }
  }
  async function loadMetrics() {
    if (!state.version) return;
    clearError();
    const empty = $('dashboardEmpty');
    empty.hidden = false; empty.textContent = 'Loading curriculum analytics...';
    $('dashboardResults').hidden = true;
    try {
      const data = await getJson(root.dataset.analyticsApi, { version_id: state.version.id });
      const context = data.context, metrics = data.metrics;
      $('snapshotProgram').textContent = context.program_code + '  -  ' + context.program_name;
      $('snapshotCurriculum').textContent = context.curriculum_name;
      $('snapshotVersion').textContent = context.version_label + '  -  Technical status: ' + context.status;
      $('contextStatus').textContent = context.status; $('contextStatus').hidden = false;
      $('currentSnapshot').hidden = false;
      $('snapshotChips').innerHTML = `<span>${metrics.mapped_subjects} Mapped Subjects</span><span>${metrics.units} Units</span><span>${metrics.year_levels} Year Levels</span>`;
      $('openSnapshotCurriculum').href = '../modules/curriculum/pages/curricula.php?curriculum_id=' + encodeURIComponent(context.curriculum_id);
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
        const card = `<small>${item[0]}</small><strong>${item[1]}</strong><span>${item[2]}</span>`;
        return link[1] ? `<a class="metric-card metric-card-link" href="${link[0]}">${card}</a>` : `<article class="metric-card">${card}</article>`;
      }).join('');
      $('analyticsSubtitle').textContent = 'Based on stored placements for ' + context.version_label + '.';
      $('analyticsGrid').innerHTML = barCard('Subjects by Year Level', data.years, 'subjects') +
        barCard('Units by Year Level', data.years, 'units') + barCard('Subjects by Semester', data.terms, null) +
        donut(data.required, data.optional, data.total) + prereq(metrics.mapped_subjects, metrics.subjects_with_prerequisites);
      $('dashboardResults').hidden = false; empty.hidden = true;
    } catch (error) {
      fail(error.message); empty.textContent = 'Curriculum analytics could not be loaded. Please try selecting the version again.';
    }
  }
  function barCard(title, values, field) {
    const pairs = field ? Object.entries(values).map(([label, value]) => [label, value[field]]) : Object.entries(values);
    const max = Math.max(1, ...pairs.map(pair => Number(pair[1])));
    return `<a class="dashboard-card analytic-card metric-card-link" href="../modules/curriculum/pages/subject_mapping.php"><h3>${title}</h3>${pairs.map(([label, value]) => `<div class="bar-row"><span>${label}</span><div class="bar-track"><i style="width:${Math.max(0, Number(value) / max * 100)}%"></i></div><b>${value}</b></div>`).join('') || '<p>No stored placements.</p>'}</a>`;
  }
  function donut(required, optional, total) {
    const portion = total ? required / total * 100 : 0;
    return `<a class="dashboard-card analytic-card metric-card-link" href="../modules/curriculum/pages/subject_mapping.php"><h3>Placement Distribution</h3><div class="donut-layout"><div class="donut" style="--required:${portion}%"><b>${total}<small>placements</small></b></div><div class="donut-legend"><p><i class="legend-required"></i>Required <b>${required}</b></p><p><i class="legend-optional"></i>Optional <b>${optional}</b></p><small>Optional placement is not automatically elective membership.</small></div></div></a>`;
  }
  function prereq(total, withPrerequisites) {
    const values = [['Subjects with stored prerequisite relationships', withPrerequisites], ['Subjects without stored prerequisite relationships', Math.max(0, total - withPrerequisites)]];
    const content = `<h3>Prerequisite Relationships</h3>${values.map(([label, value]) => `<div class="bar-row"><span>${label}</span><div class="bar-track"><i style="width:${total ? value / total * 100 : 0}%"></i></div><b>${value}</b></div>`).join('')}<small>Subjects without stored prerequisites are not automatically considered incomplete.</small>`;
    return root.dataset.canViewPrerequisite === '1' ? `<a class="dashboard-card analytic-card metric-card-link" href="../modules/curriculum/pages/prerequisite_configuration.php">${content}</a>` : `<article class="dashboard-card analytic-card">${content}</article>`;
  }
  restoreContext();
}());
