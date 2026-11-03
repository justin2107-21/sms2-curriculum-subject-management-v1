(function () {
  'use strict';
  // Dashboard renderer. All numbers come from dashboard/dashboard_data.php (or the
  // same payload embedded by dashboard.php); nothing here invents values.
  // Envelope per chart/list: {title, type, module, status: ok|empty|unavailable, reason, data}.

  const root = document.getElementById('curriculumDashboard');
  if (!root) return;
  const $ = id => document.getElementById(id);
  const appRoot = root.dataset.appRoot || '../';
  const charts = [];
  let request = 0;

  const color = {
    navy: '#1a3a8c', teal: '#0f766e', amber: '#b45309', violet: '#6d28d9', slate: '#64748b', rose: '#be123c',
    success: '#17653a', danger: '#b3261e', neutral: '#94a3b8', grid: '#e5e9f0', text: '#374151'
  };
  const series = [color.navy, color.teal, color.amber, color.violet, color.rose, color.slate];
  const statusColor = { draft: color.amber, active: color.success, archived: color.slate };

  // Charts that only make sense for one version; in "All programs" their "select a
  // program" prompts are combined into a single hint instead of four empty cards.
  const versionScoped = ['term_load', 'outcome_coverage', 'required_optional', 'grade_weighting'];
  const chartOrder = ['version_status', 'units_per_program', 'compliance', 'activity', 'prerequisites',
    'term_load', 'outcome_coverage', 'required_optional', 'grade_weighting', 'units_by_category', 'outcome_ipd', 'review_trend'];
  const listOrder = ['my_drafts', 'review_queue', 'compliance_failures', 'active_curricula', 'my_subjects', 'recent_activity'];
  const descriptions = {
    version_status: 'Draft, active and archived curriculum versions.',
    units_per_program: 'Required and optional units of placed subjects.',
    compliance: 'Pass, fail and not-evaluated rules from the latest validation run.',
    activity: 'Versions created, subject placements added and compliance runs per month.',
    prerequisites: 'How many stored prerequisites each placed subject has.',
    term_load: 'Subjects or units per year level and semester.',
    outcome_coverage: 'Subjects mapped to each graduate outcome, with the recorded coverage state.',
    required_optional: 'Required and optional subject placements in this version.',
    grade_weighting: 'Placements whose grade weighting adds up to 100%.',
    units_by_category: 'Units per subject category (GE, core, professional, elective, PE, NSTP).',
    outcome_ipd: 'Introduced, practised and demonstrated levels per graduate outcome.',
    review_trend: 'Versions submitted, approved and returned per month.'
  };

  if (window.Chart) {
    Chart.defaults.font.family = getComputedStyle(root).fontFamily;
    Chart.defaults.font.size = 12;
    Chart.defaults.datasets.bar.maxBarThickness = 22;
    Chart.defaults.scale.ticks.maxTicksLimit = 6;
    Chart.defaults.color = color.text;
    Chart.defaults.borderColor = color.grid;
    Chart.defaults.plugins.legend.labels.boxWidth = 14;
    Chart.defaults.maintainAspectRatio = false;
    if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) Chart.defaults.animation = false;
  }

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function num(value) { return Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 }); }
  function moduleHref(path, filter) {
    const params = new URLSearchParams();
    if (filter && filter.program_id) params.set('program_id', filter.program_id);
    if (filter && filter.version_id) params.set('version_id', filter.version_id);
    const query = params.toString();
    return appRoot + path + (query ? '?' + query : '');
  }
  function monthLabel(ym) {
    const [y, m] = ym.split('-').map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString(undefined, { month: 'short', year: '2-digit' });
  }
  function dateTime(value) {
    const d = new Date(String(value).replace(' ', 'T'));
    return isNaN(d) ? String(value) : d.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
  }
  function table(headers, rows) {
    return '<table class="csms-data-table"><thead><tr>' + headers.map((h, i) => `<th scope="col"${i ? ' class="num"' : ''}>${esc(h)}</th>`).join('') +
      '</tr></thead><tbody>' + rows.map(r => '<tr>' + r.map((c, i) => i ? `<td class="num">${esc(c)}</td>` : `<th scope="row">${esc(c)}</th>`).join('') + '</tr>').join('') + '</tbody></table>';
  }
  function dataTable(headers, rows) {
    return `<details><summary>Show data table</summary>${table(headers, rows)}</details>`;
  }

  const ghosts = {
    bar: '<rect x="20" y="70" width="34" height="40" rx="3"/><rect x="74" y="40" width="34" height="70" rx="3"/><rect x="128" y="58" width="34" height="52" rx="3"/><rect x="182" y="25" width="34" height="85" rx="3"/>',
    line: '<polyline points="15,95 70,70 125,80 180,40 260,55" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round"/>',
    radar: '<polygon points="140,10 220,55 190,112 90,112 60,55" fill="none" stroke="currentColor" stroke-width="4"/><polygon points="140,40 185,62 168,95 112,95 95,62" />',
    doughnut: '<circle cx="140" cy="60" r="48" fill="none" stroke="currentColor" stroke-width="22"/>',
    list: '<rect x="20" y="15" width="240" height="14" rx="4"/><rect x="20" y="50" width="200" height="14" rx="4"/><rect x="20" y="85" width="220" height="14" rx="4"/>'
  };
  function ghost(type) {
    return `<svg class="csms-ghost" viewBox="0 0 280 120" fill="currentColor" aria-hidden="true" focusable="false">${ghosts[type] || ghosts.bar}</svg>`;
  }

  function card(key, item, filter, extraClass) {
    const el = document.createElement('section');
    el.className = 'csms-card' + (extraClass ? ' ' + extraClass : '') + (item.status === 'unavailable' ? ' csms-card--unavailable' : '');
    el.dataset.chart = key;
    const titleId = 'dashCard_' + key;
    el.setAttribute('aria-labelledby', titleId);
    const link = item.module ? `<a class="csms-card__link" href="${esc(moduleHref(item.module, filter))}">View in module <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i><span class="visually-hidden"> (${esc(item.title)})</span></a>` : '';
    const pending = item.status === 'unavailable' ? ' <span class="csms-badge-pending"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>Pending backend</span>' : '';
    el.innerHTML = `<div class="csms-card__head"><div><h2 class="csms-card__title" id="${titleId}">${esc(item.title)}${pending}</h2>` +
      (descriptions[key] ? `<p class="csms-card__desc">${esc(descriptions[key])}</p>` : '') + `</div>${link}</div><div class="csms-card__body"></div>`;
    return el;
  }

  function renderState(body, item) {
    if (item.status === 'unavailable') {
      body.innerHTML = `<div class="csms-state">${ghost(item.type)}<p class="csms-state__title">Not available yet</p><p class="csms-state__reason">${esc(item.reason)}</p></div>`;
    } else {
      body.innerHTML = `<div class="csms-state"><p class="csms-state__title">No data yet for this selection.</p>${item.reason && item.reason !== 'No data yet for this selection.' ? `<p class="csms-state__reason">${esc(item.reason)}</p>` : ''}</div>`;
    }
  }

  function canvas(body, label, height) {
    const wrap = document.createElement('div');
    wrap.style.height = (height || 220) + 'px';
    wrap.className = 'csms-chart-wrap';
    wrap.style.position = 'relative';
    const c = document.createElement('canvas');
    c.setAttribute('role', 'img');
    c.setAttribute('aria-label', label);
    wrap.appendChild(c);
    body.appendChild(wrap);
    return c;
  }
  function chart(el, config) {
    if (!window.Chart) { el.parentElement.innerHTML = '<p class="csms-state__reason">The chart library could not be loaded. Use the data table below.</p>'; return null; }
    const instance = new Chart(el, config);
    charts.push(instance);
    return instance;
  }
  // Long category labels are shortened on the axis; the tooltip and data table keep the full text.
  function shortTick(value) { const label = this.getLabelForValue(value); return label.length > 24 ? label.slice(0, 23) + '…' : label; }
  function aside(body, html) { body.insertAdjacentHTML('beforeend', `<div class="csms-card__aside">${html}</div>`); }

  const renderers = {
    version_status(body, d) {
      const segments = d.segments.filter(s => s.value > 0);
      const summary = d.segments.map(s => `${s.label}: ${s.value}`).join(', ');
      chart(canvas(body, `Curriculum versions by status. ${summary}. Total ${d.total}.`, 200), {
        type: 'doughnut',
        data: { labels: segments.map(s => `${s.label} (${s.value})`), datasets: [{ data: segments.map(s => s.value), backgroundColor: segments.map(s => statusColor[s.key] || color.slate), borderColor: '#fff', borderWidth: 2 }] },
        options: { cutout: '58%', plugins: { legend: { position: 'right' } } }
      });
      let extra = '';
      if (d.review) extra = '<p><strong>Review status:</strong> ' + d.review.map(r => `${esc(r.label)} ${r.value}`).join(' · ') + '</p>';
      aside(body, extra + dataTable(['Status', 'Versions'], d.segments.map(s => [s.label, s.value])));
    },
    units_per_program(body, d) {
      const labels = d.bars.map(b => b.label);
      chart(canvas(body, 'Required and optional units per ' + (labels.length ? 'item' : '') + ': ' + d.bars.map(b => `${b.label} ${num(b.required_units)} required, ${num(b.optional_units)} optional`).join('; '), Math.max(160, 30 * labels.length + 70)), {
        type: 'bar',
        data: { labels, datasets: [
          { label: 'Required units', data: d.bars.map(b => b.required_units), backgroundColor: color.navy },
          { label: 'Optional units', data: d.bars.map(b => b.optional_units), backgroundColor: color.amber }
        ] },
        options: { indexAxis: 'y', scales: { x: { stacked: true, beginAtZero: true, title: { display: true, text: 'Units' } }, y: { stacked: true, grid: { display: false }, ticks: { callback: shortTick } } },
          plugins: { tooltip: { callbacks: { footer: items => { const b = d.bars[items[0].dataIndex]; return `${b.subjects} subjects · ${num(b.required_units + b.optional_units)} units`; } } } } }
      });
      aside(body, dataTable(['Item', 'Required units', 'Optional units', 'Subjects'], d.bars.map(b => [b.label, num(b.required_units), num(b.optional_units), b.subjects])));
    },
    compliance(body, d) {
      const validated = d.bars.filter(b => b.validated);
      const pending = d.bars.filter(b => !b.validated);
      if (validated.length) {
        chart(canvas(body, 'Latest compliance results: ' + validated.map(b => `${b.label} pass ${b.pass}, fail ${b.fail}, not evaluated ${b.not_evaluated}`).join('; '), Math.max(160, 30 * validated.length + 70)), {
          type: 'bar',
          data: { labels: validated.map(b => b.label), datasets: [
            { label: 'Pass', data: validated.map(b => b.pass), backgroundColor: color.success },
            { label: 'Fail', data: validated.map(b => b.fail), backgroundColor: color.danger },
            { label: 'Not evaluated', data: validated.map(b => b.not_evaluated), backgroundColor: color.neutral }
          ] },
          options: { indexAxis: 'y', scales: { x: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Rules' } }, y: { stacked: true, grid: { display: false }, ticks: { callback: shortTick } } } }
        });
      }
      aside(body, (pending.length ? `<p><strong>Not yet validated:</strong> ${pending.map(b => esc(b.label)).join(', ')}</p>` : '') +
        dataTable(['Curriculum', 'Pass', 'Fail', 'Not evaluated'], d.bars.map(b => b.validated ? [b.label, b.pass, b.fail, b.not_evaluated] : [b.label, '—', '—', 'Not yet validated'])));
    },
    activity(body, d) { lineChart(body, d, 'Curriculum activity per month'); },
    review_trend(body, d) { lineChart(body, d, 'Review events per month'); },
    prerequisites(body, d) {
      chart(canvas(body, `Prerequisites per subject: ${d.bins.map(b => `${b.label} prerequisites, ${b.subjects} subjects`).join('; ')}.`, 220), {
        type: 'bar',
        data: { labels: d.bins.map(b => b.label), datasets: [{ label: 'Subjects', data: d.bins.map(b => b.subjects), backgroundColor: color.navy, barPercentage: 1, categoryPercentage: .9 }] },
        options: { scales: { x: { grid: { display: false }, title: { display: true, text: 'Stored prerequisites per subject' } }, y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Subjects' } } }, plugins: { legend: { display: false } } }
      });
      const top = d.top.length ? '<p><strong>Most prerequisites:</strong></p><ol>' + d.top.map(t => `<li>${esc(t.subject_code)} — ${esc(t.subject_title)} (${t.prerequisites})</li>`).join('') + '</ol>' : '';
      aside(body, `<p>${d.with_prerequisites} of ${d.subjects} placed subjects have stored prerequisites. Subjects without prerequisites are not automatically incomplete.</p>` + top +
        dataTable(['Prerequisites', 'Subjects'], d.bins.map(b => [b.label, b.subjects])));
    },
    term_load(body, d) {
      const toggle = document.createElement('div');
      toggle.className = 'csms-card__toggle';
      toggle.setAttribute('role', 'group');
      toggle.setAttribute('aria-label', 'Measure');
      toggle.innerHTML = '<button type="button" data-measure="units" aria-pressed="true">Units</button><button type="button" data-measure="subjects" aria-pressed="false">Subjects</button>';
      body.appendChild(toggle);
      const datasets = measure => d.series.map((s, i) => ({ label: s.label, data: s.points.map(p => p[measure]), backgroundColor: series[i % series.length] }));
      const instance = chart(canvas(body, `Load by year and semester for this version: ${d.totals.subjects} subjects, ${num(d.totals.units)} units.`, 220), {
        type: 'bar', data: { labels: d.years, datasets: datasets('units') },
        options: { scales: { x: { grid: { display: false }, title: { display: true, text: 'Year level' } }, y: { beginAtZero: true, title: { display: true, text: 'Units' } } } }
      });
      toggle.addEventListener('click', e => {
        const btn = e.target.closest('button[data-measure]');
        if (!btn || !instance) return;
        toggle.querySelectorAll('button').forEach(b => b.setAttribute('aria-pressed', String(b === btn)));
        instance.data.datasets = datasets(btn.dataset.measure);
        instance.options.scales.y.title.text = btn.dataset.measure === 'units' ? 'Units' : 'Subjects';
        instance.options.scales.y.ticks = btn.dataset.measure === 'units' ? {} : { precision: 0 };
        instance.update();
      });
      const merged = d.merged_labels.length ? '<p><strong>Labels combined:</strong> ' + d.merged_labels.map(m => `${esc(m.label)} (stored as ${m.stored_values.map(v => '“' + esc(v || 'blank') + '”').join(', ')})`).join('; ') + '</p>' : '';
      const rows = [];
      d.years.forEach((y, yi) => d.series.forEach(s => rows.push([`${y} · ${s.label}`, s.points[yi].subjects, num(s.points[yi].units)])));
      aside(body, merged + dataTable(['Year · semester', 'Subjects', 'Units'], rows));
    },
    outcome_coverage(body, d) {
      chart(canvas(body, 'Subjects mapped per graduate outcome: ' + d.outcomes.map(o => `${o.code} ${o.mapped_subjects}`).join(', '), Math.max(220, 24 * d.outcomes.length + 60)), {
        type: 'bar',
        data: { labels: d.outcomes.map(o => o.code), datasets: [{ label: 'Mapped subjects', data: d.outcomes.map(o => o.mapped_subjects), backgroundColor: d.outcomes.map(o => o.assessment_state === 'complete' ? color.success : color.navy) }] },
        options: { indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Mapped subjects' } } },
          plugins: { legend: { display: false }, tooltip: { callbacks: { afterLabel: i => { const o = d.outcomes[i.dataIndex]; return [o.text.slice(0, 90) + (o.text.length > 90 ? '…' : ''), 'Coverage: ' + (o.assessment_state || 'not assessed')]; } } } } }
      });
      aside(body, dataTable(['Outcome', 'Mapped subjects', 'Coverage'], d.outcomes.map(o => [o.code, o.mapped_subjects, o.assessment_state || 'not assessed'])));
    },
    required_optional(body, d) {
      chart(canvas(body, `Required ${d.required}, optional ${d.optional}, total ${d.total} placements.`, 200), {
        type: 'doughnut',
        data: { labels: [`Required (${d.required})`, `Optional (${d.optional})`], datasets: [{ data: [d.required, d.optional], backgroundColor: [color.navy, color.amber], borderColor: '#fff', borderWidth: 2 }] },
        options: { cutout: '58%', plugins: { legend: { position: 'right' } } }
      });
      aside(body, '<p>Optional placement is not automatically elective membership.</p>' + dataTable(['Placement', 'Subjects'], [['Required', d.required], ['Optional', d.optional]]));
    },
    grade_weighting(body, d) {
      const pct = d.placements ? Math.round(d.complete / d.placements * 100) : 0;
      body.innerHTML = `<p class="csms-stat__value">${d.complete} <span class="csms-stat__label" style="display:inline">of ${d.placements} placements complete</span></p>` +
        `<div class="csms-progress" role="progressbar" aria-valuemin="0" aria-valuemax="${d.placements}" aria-valuenow="${d.complete}" aria-label="Placements with complete grade weighting"><span style="width:${pct}%"></span></div>` +
        `<p class="csms-card__desc">${d.configured} configured · ${d.placements - d.complete} not fully configured</p>`;
      body.style.minHeight = '0';
    }
  };
  function lineChart(body, d, label) {
    const labels = d.months.map(monthLabel);
    chart(canvas(body, label + ': ' + d.series.map(s => `${s.label} total ${s.values.reduce((a, b) => a + b, 0)}`).join('; '), 240), {
      type: 'line',
      data: { labels, datasets: d.series.map((s, i) => ({ label: s.label, data: s.values, borderColor: series[i % series.length], backgroundColor: series[i % series.length], pointStyle: ['circle', 'rect', 'triangle'][i % 3], pointRadius: 2.5, tension: 0.2 })) },
      options: { interaction: { mode: 'index', intersect: false }, scales: { y: { beginAtZero: true, min: 0, ticks: { precision: 0 }, title: { display: true, text: 'Count' } }, x: { grid: { display: false }, title: { display: true, text: 'Month' } } } }
    });
    aside(body, dataTable(['Month', ...d.series.map(s => s.label)], d.months.map((m, mi) => [monthLabel(m), ...d.series.map(s => s.values[mi])])));
  }

  const listRenderers = {
    my_drafts: (i, f) => `<strong>${esc(i.label)}</strong><small>${esc(i.context)} · ${i.placements} placements · Next: ${esc(i.next_step)}</small>`,
    review_queue: i => `<strong>${esc(i.label)}</strong><small>${esc(i.context)} · ${esc(i.responsibility)}</small>`,
    compliance_failures: i => `<strong>${esc(i.program_code)} · ${esc(i.curriculum_code)} · ${esc(i.version_label)}</strong><small>${i.failed_count} failed · ${i.passed_count} passed · ${i.not_evaluated_count} not evaluated</small>`,
    active_curricula: i => `<strong>${esc(i.program_code)} · ${esc(i.curriculum_code)}</strong><small>${esc(i.curriculum_name)} · ${esc(i.version_label)}${i.effective_academic_year ? ' · ' + esc(i.effective_academic_year) : ''}</small>`,
    recent_activity: i => `<strong>${i.link ? `<a href="${esc(appRoot + i.link)}">${esc(i.label)}</a>` : esc(i.label)}</strong><small>${esc(i.description)} · ${esc(i.user)}</small>`
  };
  function listMeta(key, i) {
    if (key === 'recent_activity') return dateTime(i.created_at);
    if (key === 'compliance_failures') return dateTime(i.created_at);
    return i.updated_at ? 'Updated ' + dateTime(i.updated_at) : '';
  }

  function render(payload) {
    charts.splice(0).forEach(c => c.destroy());
    const filter = payload.filter || {};
    const overall = !filter.program_id;

    // Stat row (max 4)
    $('metricGrid').innerHTML = (payload.stats || []).map(s => {
      const inner = `<span class="csms-stat__label">${esc(s.label)}</span><strong class="csms-stat__value">${esc(num(s.value))}</strong>${s.detail ? `<span class="csms-stat__detail">${esc(s.detail)}</span>` : ''}`;
      return s.module ? `<a class="csms-stat" href="${esc(moduleHref(s.module, filter))}">${inner}</a>` : `<div class="csms-stat">${inner}</div>`;
    }).join('');
    $('metricGrid').hidden = !(payload.stats || []).length;

    // Charts
    const grid = $('analyticsGrid');
    grid.innerHTML = '';
    const items = payload.charts || {};
    const prompts = [];
    chartOrder.forEach(key => {
      const item = items[key];
      if (!item) return;
      if (overall && versionScoped.includes(key) && item.status === 'empty') { prompts.push(item.title); return; }
      const el = card(key, item, filter, key === 'activity' || key === 'review_trend' ? 'csms-card--wide' : '');
      grid.appendChild(el);
      const body = el.querySelector('.csms-card__body');
      if (item.status === 'ok' && renderers[key]) renderers[key](body, item.data);
      else renderState(body, item);
    });
    if (prompts.length) {
      grid.insertAdjacentHTML('beforeend', `<p class="csms-hint-card"><i class="fa-solid fa-filter" aria-hidden="true"></i><span>Select a program or strand in the filter above to see: ${prompts.map(esc).join(', ')}.</span></p>`);
    }
    grid.hidden = !grid.children.length;
    const rule = $('dashboardVersionRule');
    rule.textContent = overall && payload.version_rule ? payload.version_rule : '';
    rule.hidden = !(overall && payload.version_rule);

    // Lists
    const lists = $('dashboardLists');
    lists.innerHTML = '';
    listOrder.forEach(key => {
      const item = (payload.lists || {})[key];
      if (!item) return;
      const el = card(key, Object.assign({}, item, { type: 'list' }), key === 'recent_activity' ? null : filter, 'csms-card--list');
      if (key === 'recent_activity') el.querySelector('.csms-card__link').firstChild.textContent = 'View audit logs ';
      const body = el.querySelector('.csms-card__body');
      if (item.status === 'ok') {
        body.innerHTML = '<ul class="csms-list">' + item.data.items.map(i => `<li><span>${listRenderers[key](i)}</span><span class="csms-list__meta">${esc(listMeta(key, i))}</span></li>`).join('') + '</ul>';
      } else {
        renderState(body, Object.assign({}, item, { type: 'list' }));
        body.querySelector('.csms-state').style.minHeight = '120px';
      }
      lists.appendChild(el);
    });
    lists.hidden = !lists.children.length;

    const nothing = !grid.children.length && !lists.children.length && !(payload.stats || []).length;
    $('dashboardEmpty').hidden = !nothing;
    if (nothing) $('dashboardEmpty').textContent = 'Your account does not have access to curriculum analytics. Ask an administrator if you need it.';
  }

  function syncVersionSelect(payload) {
    const wrap = $('dashboardVersionWrap'), select = $('dashboardVersion');
    if (!wrap || !select) return;
    const versions = (payload.filter && payload.filter.versions) || [];
    select.innerHTML = versions.map(v => `<option value="${v.id}"${v.id === payload.filter.version_id ? ' selected' : ''}>${esc(`${v.label} · ${v.status.charAt(0).toUpperCase() + v.status.slice(1)} (${v.curriculum_code})`)}</option>`).join('');
    wrap.hidden = !payload.filter.program_id || !versions.length;
  }

  async function load(programId, versionId, push) {
    const id = ++request;
    root.classList.add('is-loading');
    $('dashboardStatus').textContent = 'Loading dashboard…';
    const params = new URLSearchParams();
    if (programId) params.set('program_id', programId);
    if (programId && versionId) params.set('version_id', versionId);
    try {
      const response = await fetch(root.dataset.dashboardApi + (params.toString() ? '?' + params : ''), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const data = await response.json().catch(() => ({}));
      if (id !== request) return;
      if (!response.ok || !data.success) throw new Error(data.message || 'Dashboard analytics could not be loaded. Try again.');
      $('dashboardError').hidden = true;
      render(data);
      syncVersionSelect(data);
      const program = (data.programs || []).find(p => p.id === data.filter.program_id);
      $('dashboardSubtitle').textContent = program ? `Curriculum overview for ${program.code} — ${program.name}.` : 'Curriculum overview across all programs and strands.';
      const url = new URL(location.href);
      url.searchParams.delete('program'); url.searchParams.delete('version');
      if (data.filter.program_id) url.searchParams.set('program', data.filter.program_id);
      if (data.filter.program_id && versionId) url.searchParams.set('version', data.filter.version_id);
      history[push ? 'pushState' : 'replaceState']({ program: data.filter.program_id, version: versionId || null }, '', url);
      $('dashboardStatus').textContent = 'Dashboard updated' + (program ? ' for ' + program.code : ' for all programs') + '.';
    } catch (error) {
      if (id !== request) return;
      $('dashboardError').textContent = error.message;
      $('dashboardError').hidden = false;
      $('dashboardStatus').textContent = '';
    } finally {
      if (id === request) root.classList.remove('is-loading');
    }
  }

  // Initial render from the embedded payload.
  let initial = null;
  try { initial = JSON.parse($('dashboardInitialData').textContent || 'null'); } catch (_) {}
  if (initial) render(initial);

  const programSelect = $('dashboardProgram'), versionSelect = $('dashboardVersion');
  if (programSelect) {
    programSelect.addEventListener('change', () => load(programSelect.value === 'all' ? null : programSelect.value, null, true));
    if (versionSelect) versionSelect.addEventListener('change', () => load(programSelect.value === 'all' ? null : programSelect.value, versionSelect.value, true));
    window.addEventListener('popstate', () => {
      const params = new URLSearchParams(location.search);
      programSelect.value = params.get('program') || 'all';
      programSelect.dispatchEvent(new CustomEvent('csms:picker-sync'));
      load(params.get('program'), params.get('version'), false);
    });
  }
}());
