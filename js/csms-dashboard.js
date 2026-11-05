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
  const bucketColor = { draft: color.amber, active: color.teal, in_review: color.violet, returned: color.rose, approved: color.success, archived: color.neutral };

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
      (item.description ? `<p class="csms-card__desc">${esc(item.description)}</p>` : '') + `</div>${link}</div><div class="csms-card__body"></div>`;
    return el;
  }

  function renderState(body, item) {
    if (item.status === 'unavailable') {
      body.innerHTML = `<div class="csms-state">${ghost(item.type)}<p class="csms-state__title">Not available yet</p><p class="csms-state__reason">${esc(item.reason)}</p></div>`;
    } else {
      body.innerHTML = `<p class="csms-state-compact">${esc(item.reason || 'No data yet for this selection.')}</p>`;
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

  function periodLabel(d, key) {
    if (d.size === 'month') return monthLabel(key);
    const parts = key.split('-').map(Number);
    return 'Wk ' + new Date(parts[0], parts[1] - 1, parts[2]).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  }
  function discreteBars(body, d, label, stacked) {
    const labels = d.periods.map(p => periodLabel(d, p));
    chart(canvas(body, label + ': ' + d.series.map(x => x.label + ' total ' + x.values.reduce((m, n) => m + n, 0)).join('; '), 230), {
      type: 'bar',
      data: { labels, datasets: d.series.map((x, i) => ({ label: x.label, data: x.values, backgroundColor: series[i % series.length] })) },
      options: { scales: { x: { stacked, grid: { display: false } }, y: { stacked, beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Count' } } }, plugins: { legend: { display: d.series.length > 1 } } }
    });
    aside(body, dataTable([d.size === 'week' ? 'Week' : 'Month', ...d.series.map(x => x.label)], d.periods.map((p, i) => [labels[i], ...d.series.map(x => x.values[i])])));
  }
  function simpleBars(body, d, label, axis) {
    chart(canvas(body, label + ': ' + d.bars.map(x => x.label + ' ' + x.value).join('; '), Math.max(150, 30 * d.bars.length + 60)), {
      type: 'bar',
      data: { labels: d.bars.map(x => x.label), datasets: [{ label: axis, data: d.bars.map(x => x.value), backgroundColor: color.navy }] },
      options: { indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: axis } }, y: { grid: { display: false }, ticks: { callback: shortTick } } }, plugins: { legend: { display: false } } }
    });
    aside(body, dataTable(['Item', axis], d.bars.map(x => [x.label, x.value])));
  }
  function noCurriculum(list) {
    return list && list.length ? '<p><strong>No curriculum yet:</strong> ' + list.map(esc).join(', ') + '</p>' : '';
  }

  const renderers = {
    accounts_by_role(body, d) { simpleBars(body, d, 'Active accounts by role', 'Accounts'); },
    programs_by_level(body, d) { simpleBars(body, d, 'Active programs by level', 'Programs'); },
    audit_weekly(body, d) { discreteBars(body, d, 'Audit events', false); },
    review_outcomes(body, d) { discreteBars(body, d, 'Review outcomes', true); },
    pipeline(body, d) {
      const keys = Object.keys(d.buckets);
      if (d.rows.length) {
        chart(canvas(body, 'Curriculum pipeline: ' + d.rows.map(r => r.label + ' ' + keys.filter(k => r.counts[k]).map(k => d.buckets[k] + ' ' + r.counts[k]).join(', ')).join('; '), Math.max(170, 34 * d.rows.length + 80)), {
          type: 'bar',
          data: { labels: d.rows.map(r => r.label), datasets: keys.map(k => ({ label: d.buckets[k], data: d.rows.map(r => r.counts[k]), backgroundColor: bucketColor[k] })) },
          options: { indexAxis: 'y', scales: { x: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Versions' } }, y: { stacked: true, grid: { display: false } } } }
        });
      }
      aside(body, noCurriculum(d.no_curriculum) + (d.rows.length ? dataTable(['Program', ...keys.map(k => d.buckets[k])], d.rows.map(r => [r.label, ...keys.map(k => r.counts[k])])) : ''));
    },
    completeness(body, d) {
      if (d.rows.length) {
        const labels = d.rows.map(r => r.label);
        const hasTarget = d.rows.some(r => r.target !== null);
        // The required-units marker is drawn on top of each bar; rows without a rule have none.
        const targetMarker = { id: 'targetMarker', afterDatasetsDraw(c) {
          const ctx = c.ctx, meta = c.getDatasetMeta(0);
          d.rows.forEach((r, i) => {
            if (r.target === null || !meta.data[i]) return;
            const x = c.scales.x.getPixelForValue(r.target), y = meta.data[i].y;
            ctx.save(); ctx.strokeStyle = color.rose; ctx.lineWidth = 3;
            ctx.beginPath(); ctx.moveTo(x, y - 14); ctx.lineTo(x, y + 14); ctx.stroke(); ctx.restore();
          });
        } };
        const most = Math.max(...d.rows.map(r => Math.max(r.units, r.target || 0)));
        chart(canvas(body, 'Curriculum completeness: ' + d.rows.map(r => r.label + ' ' + num(r.units) + ' units, ' + (r.target === null ? 'no target set' : 'target ' + num(r.target))).join('; '), Math.max(170, 40 * d.rows.length + 70)), {
          type: 'bar', plugins: [targetMarker],
          data: { labels, datasets: [{ label: 'Placed units', data: d.rows.map(r => r.units), backgroundColor: color.navy }] },
          options: { indexAxis: 'y', scales: { x: { beginAtZero: true, suggestedMax: most * 1.05, title: { display: true, text: hasTarget ? 'Units (red mark: required units)' : 'Units' } }, y: { grid: { display: false }, ticks: { callback: shortTick } } },
            plugins: { legend: { display: false }, tooltip: { callbacks: { footer: items => { const r = d.rows[items[0].dataIndex]; return r.target === null ? 'No target set' : 'Required ' + num(r.target) + ': ' + r.target_name; } } } } }
        });
      }
      aside(body, noCurriculum(d.no_curriculum) + (d.rows.length ? dataTable(['Curriculum · counted version', 'Placed units', 'Required units'], d.rows.map(r => [r.label, num(r.units), r.target === null ? 'No target set' : num(r.target)])) : ''));
    },
    prerequisite_health(body, d) {
      body.insertAdjacentHTML('beforeend', table(['Curriculum · counted version', 'Later-term conflicts', 'Circular chains', 'Year 2–4 with a prerequisite'],
        d.rows.map(r => [r.label, r.later_term, r.circular, r.percent === null ? 'Not applicable' : r.percent + '% (' + r.upper_with_prerequisite + ' of ' + r.upper_subjects + ')'])));
      if (d.histogram) {
        chart(canvas(body, 'Prerequisites per subject: ' + d.histogram.bins.map(b => b.label + ' prerequisites, ' + b.subjects + ' subjects').join('; '), 170), {
          type: 'bar',
          data: { labels: d.histogram.bins.map(b => b.label), datasets: [{ label: 'Subjects', data: d.histogram.bins.map(b => b.subjects), backgroundColor: color.navy, barPercentage: 1, categoryPercentage: .9 }] },
          options: { scales: { x: { grid: { display: false }, title: { display: true, text: 'Stored prerequisites per subject' } }, y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } }
        });
      }
    },
    compliance_under_review(body, d) {
      const validated = d.bars.filter(b => b.validated);
      const pending = d.bars.filter(b => !b.validated);
      if (validated.length) {
        chart(canvas(body, 'Compliance results: ' + validated.map(b => b.label + ' pass ' + b.pass + ', fail ' + b.fail + ', not evaluated ' + b.not_evaluated).join('; '), Math.max(160, 30 * validated.length + 70)), {
          type: 'bar',
          data: { labels: validated.map(b => b.label), datasets: [
            { label: 'Pass', data: validated.map(b => b.pass), backgroundColor: color.success },
            { label: 'Fail', data: validated.map(b => b.fail), backgroundColor: color.danger },
            { label: 'Not evaluated', data: validated.map(b => b.not_evaluated), backgroundColor: color.neutral }
          ] },
          options: { indexAxis: 'y', scales: { x: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Rules' } }, y: { stacked: true, grid: { display: false }, ticks: { callback: shortTick } } } }
        });
      }
      aside(body, pending.length ? '<p><strong>Not yet validated:</strong> ' + pending.map(b => esc(b.label)).join(', ') + '</p>' : '');
    },
    term_load(body, d) {
      const toggle = document.createElement('div');
      toggle.className = 'csms-card__toggle';
      toggle.setAttribute('role', 'group');
      toggle.setAttribute('aria-label', 'Measure');
      toggle.innerHTML = '<button type="button" data-measure="units" aria-pressed="true">Units</button><button type="button" data-measure="subjects" aria-pressed="false">Subjects</button>';
      body.appendChild(toggle);
      const datasets = measure => d.series.map((x, i) => ({ label: x.label, data: x.points.map(p => p[measure]), backgroundColor: series[i % series.length] }));
      const instance = chart(canvas(body, 'Load by year and semester: ' + d.totals.subjects + ' subjects, ' + num(d.totals.units) + ' units.', 220), {
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
      const merged = d.merged_labels.length ? '<p><strong>Labels combined:</strong> ' + d.merged_labels.map(m => esc(m.label) + ' (stored as ' + m.stored_values.map(v => '“' + esc(v || 'blank') + '”').join(', ') + ')').join('; ') + '</p>' : '';
      const rows = [];
      d.years.forEach((y, yi) => d.series.forEach(x => rows.push([y + ' · ' + x.label, x.points[yi].subjects, num(x.points[yi].units)])));
      aside(body, merged + dataTable(['Year · semester', 'Subjects', 'Units'], rows));
    },
    outcome_coverage(body, d) {
      chart(canvas(body, 'Subjects mapped per graduate outcome: ' + d.outcomes.map(o => o.code + ' ' + o.mapped_subjects).join(', '), 300), {
        type: 'radar',
        data: { labels: d.outcomes.map(o => o.code), datasets: [{ label: 'Mapped subjects', data: d.outcomes.map(o => o.mapped_subjects), borderColor: color.navy, backgroundColor: 'rgba(26,58,140,.15)', pointBackgroundColor: color.navy }] },
        options: { scales: { r: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } }
      });
      aside(body, dataTable(['Outcome', 'Mapped subjects', 'Coverage'], d.outcomes.map(o => [o.code, o.mapped_subjects, o.assessment_state || 'not assessed'])));
    }
  };

  function render(payload) {
    charts.splice(0).forEach(c => c.destroy());
    const filter = payload.filter || {};

    $('metricGrid').innerHTML = (payload.stats || []).map(x => {
      const value = x.value === null ? '—' : num(x.value);
      const inner = '<span class="csms-stat__label">' + esc(x.label) + '</span><strong class="csms-stat__value">' + esc(value) + '</strong>' + (x.detail ? '<span class="csms-stat__detail">' + esc(x.detail) + '</span>' : '');
      return x.module ? '<a class="csms-stat" href="' + esc(moduleHref(x.module, filter)) + '">' + inner + '</a>' : '<div class="csms-stat">' + inner + '</div>';
    }).join('');
    $('metricGrid').hidden = !(payload.stats || []).length;

    const grid = $('analyticsGrid');
    grid.innerHTML = '';
    Object.entries(payload.charts || {}).forEach(([key, item]) => {
      const el = card(key, item, filter, key === 'pipeline' || key === 'prerequisite_health' ? 'csms-card--wide' : '');
      el.classList.toggle('csms-card--compact', item.status === 'empty');
      grid.appendChild(el);
      const body = el.querySelector('.csms-card__body');
      if (item.status === 'ok' && renderers[key]) renderers[key](body, item.data);
      else renderState(body, item);
    });
    grid.hidden = !grid.children.length;
    const rule = $('dashboardVersionRule');
    rule.textContent = payload.version_rule || '';
    rule.hidden = !payload.version_rule;

    const lists = $('dashboardLists');
    lists.innerHTML = '';
    Object.entries(payload.lists || {}).forEach(([key, item]) => {
      const el = card(key, Object.assign({}, item, { type: 'list' }), filter, 'csms-card--list');
      el.classList.toggle('csms-card--compact', item.status === 'empty');
      const body = el.querySelector('.csms-card__body');
      if (item.status === 'ok') {
        body.innerHTML = '<ul class="csms-list">' + item.data.items.map(i => '<li><span><strong>' + esc(i.primary) + '</strong><small>' + esc(i.secondary) + '</small></span><span class="csms-list__meta">' + (i.meta ? esc(dateTime(i.meta)) : '') + '</span></li>').join('') + '</ul>';
      } else {
        renderState(body, Object.assign({}, item, { type: 'list' }));
      }
      lists.appendChild(el);
    });
    lists.hidden = !lists.children.length;

    const nothing = !grid.children.length && !lists.children.length && !(payload.stats || []).length;
    $('dashboardEmpty').hidden = !nothing;
    if (nothing) $('dashboardEmpty').textContent = 'No dashboard items for your account. Ask an administrator if you need access.';
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
