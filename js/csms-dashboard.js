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

  // Colour-blind-safe set (Okabe-Ito). Meaning is never carried by colour alone: every segment also has
  // a count, either printed on it, in the legend or in the data table.
  const color = {
    navy: '#1a3a8c', slate: '#8a94a6', charcoal: '#2b2f3a', blue: '#0072B2', orange: '#E69F00', green: '#009E73',
    pink: '#CC79A7', sky: '#56B4E9', vermilion: '#D55E00', grid: '#e5e9f0', text: '#374151'
  };
  const series = [color.blue, color.orange, color.green, color.pink, color.sky, color.slate];
  const bucketColor = { draft: color.slate, active: color.blue, in_review: color.orange, returned: color.pink, approved: color.green, archived: color.charcoal };
  const semantic = {
    Pass: color.green, Fail: color.vermilion, 'Not evaluated': color.slate,
    Approved: color.green, Returned: color.pink, Submitted: color.blue
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
  function pct(value, total) {
    if (!total) return '0';
    const p = value / total * 100;
    return value > 0 && p < 1 ? '<1' : String(Math.round(p));
  }
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
  // Text colour that stays readable on a bar segment.
  function ink(hex) {
    const n = parseInt(hex.slice(1), 16);
    const lin = c => { c /= 255; return c <= .03928 ? c / 12.92 : Math.pow((c + .055) / 1.055, 2.4); };
    const L = .2126 * lin((n >> 16) & 255) + .7152 * lin((n >> 8) & 255) + .0722 * lin(n & 255);
    return L > .179 ? '#111827' : '#ffffff';
  }

  const ghosts = {
    bar: '<rect x="20" y="70" width="34" height="40" rx="3"/><rect x="74" y="40" width="34" height="70" rx="3"/><rect x="128" y="58" width="34" height="52" rx="3"/><rect x="182" y="25" width="34" height="85" rx="3"/>',
    line: '<polyline points="15,95 70,70 125,80 180,40 260,55" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round"/>',
    radar: '<polygon points="140,10 220,55 190,112 90,112 60,55" fill="none" stroke="currentColor" stroke-width="4"/><polygon points="140,40 185,62 168,95 112,95 95,62" />',
    doughnut: '<circle cx="140" cy="60" r="48" fill="none" stroke="currentColor" stroke-width="22"/>',
    list: '<rect x="20" y="15" width="240" height="14" rx="4"/><rect x="20" y="50" width="200" height="14" rx="4"/><rect x="20" y="85" width="220" height="14" rx="4"/>'
  };
  function ghost(type) {
    return `<svg class="csms-ghost" viewBox="0 0 280 120" fill="currentColor" aria-hidden="true" focusable="false">${ghosts[type === 'donut' ? 'doughnut' : type] || ghosts.bar}</svg>`;
  }

  function card(key, item, filter, extraClass) {
    const el = document.createElement('section');
    el.className = 'csms-card' + (extraClass ? ' ' + extraClass : '') + (item.status === 'unavailable' ? ' csms-card--unavailable' : '');
    el.dataset.chart = key;
    const titleId = 'dashCard_' + key;
    el.setAttribute('aria-labelledby', titleId);
    const linkText = item.type === 'list' ? 'View all' : 'View in module';
    const link = item.module ? `<a class="csms-card__link" href="${esc(moduleHref(item.module, filter))}">${linkText} <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i><span class="visually-hidden"> (${esc(item.title)})</span></a>` : '';
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
    el.addEventListener('pointerleave', () => clearTooltips(null));
    el.addEventListener('touchend', () => setTimeout(() => clearTooltips(null), 2500), { passive: true });
    return instance;
  }

  // A chart tooltip is drawn on the canvas and stays where the last pointer or touch left it. Clear it when
  // the pointer leaves, after a touch, on scroll, and when the user presses anywhere outside a chart.
  function clearTooltips(except) {
    charts.forEach(c => {
      if (c === except || !c.tooltip || !c.tooltip.getActiveElements().length) return;
      c.setActiveElements([]);
      c.tooltip.setActiveElements([], { x: 0, y: 0 });
      c.update('none');
    });
  }
  document.addEventListener('scroll', () => clearTooltips(null), { capture: true, passive: true });
  document.addEventListener('pointerdown', e => { if (!e.target.closest || !e.target.closest('canvas')) clearTooltips(null); }, { passive: true });

  // Value label at the end of every bar, zero included. Stacked bars get segment counts and a total.
  const valueLabels = {
    id: 'valueLabels',
    afterDatasetsDraw(c, _args, opts) {
      if (!opts || !opts.enabled) return;
      const horizontal = c.options.indexAxis === 'y';
      const fmt = opts.format || num;
      const ctx = c.ctx;
      const labels = c.data.labels || [];
      ctx.save();
      ctx.font = '600 12px ' + Chart.defaults.font.family;
      const put = (text, x, y, align, base, fill) => { ctx.fillStyle = fill; ctx.textAlign = align; ctx.textBaseline = base; ctx.fillText(text, x, y); };
      for (let i = 0; i < labels.length; i++) {
        if (opts.stacked) {
          let total = 0, edge = null, first = null;
          c.data.datasets.forEach((ds, di) => {
            if (!c.isDatasetVisible(di)) return;
            const v = Number(ds.data[i]) || 0;
            const el = c.getDatasetMeta(di).data[i];
            total += v;
            if (!el) return;
            if (first === null) first = el;
            if (v > 0) edge = el;
            if (opts.inside && v > 0) {
              const size = horizontal ? Math.abs(el.x - el.base) : Math.abs(el.base - el.y);
              if (size >= 22) {
                const fill = ink(String(ds.backgroundColor));
                if (horizontal) put(fmt(v), (el.x + el.base) / 2, el.y, 'center', 'middle', fill);
                else put(fmt(v), el.x, (el.y + el.base) / 2, 'center', 'middle', fill);
              }
            }
          });
          const el = edge || first;
          if (!el) continue;
          if (horizontal) put(fmt(total), el.x + 6, el.y, 'left', 'middle', color.text);
          else put(fmt(total), el.x, el.y - 6, 'center', 'bottom', color.text);
        } else {
          c.data.datasets.forEach((ds, di) => {
            if (!c.isDatasetVisible(di)) return;
            const el = c.getDatasetMeta(di).data[i];
            if (!el) return;
            const v = Number(ds.data[i]) || 0;
            if (horizontal) {
              const at = opts.labelAt ? opts.labelAt(i, v) : null;
              const x = at === null ? el.x : c.scales.x.getPixelForValue(at);
              put(fmt(v), x + 6, el.y, 'left', 'middle', color.text);
            } else {
              put(fmt(v), el.x, el.y - 6, 'center', 'bottom', color.text);
            }
          });
        }
      }
      ctx.restore();
    }
  };
  const centerTotal = {
    id: 'centerTotal',
    afterDraw(c, _args, opts) {
      if (!opts || opts.text === undefined) return;
      const a = c.chartArea, x = (a.left + a.right) / 2, y = (a.top + a.bottom) / 2, ctx = c.ctx;
      ctx.save();
      ctx.textAlign = 'center';
      ctx.fillStyle = '#1f2937';
      ctx.textBaseline = 'alphabetic';
      ctx.font = '700 26px ' + Chart.defaults.font.family;
      ctx.fillText(opts.text, x, y + 6);
      ctx.fillStyle = color.text;
      ctx.font = '500 12px ' + Chart.defaults.font.family;
      ctx.fillText(opts.caption || 'Total', x, y + 24);
      ctx.restore();
    }
  };
  // Room for the labels so they are never clipped at the chart edge.
  const padHorizontal = { padding: { right: 44 } };
  const padVertical = { padding: { top: 22 } };

  // Long category labels are shortened on the axis; the tooltip and data table keep the full text.
  function shortTick(value) {
    const label = this.getLabelForValue(value);
    const max = Math.max(12, Math.min(34, Math.floor(this.chart.width * 0.4 / 6.8)));
    return label.length > max ? label.slice(0, max - 1) + '…' : label;
  }
  // Give the label column the width of its longest (shortened) label, so nothing is clipped on the left.
  function fitAxis(scale) {
    const ctx = scale.chart.ctx;
    ctx.save();
    ctx.font = Chart.helpers.toFont(scale.options.ticks.font).string;
    const widest = Math.max(0, ...scale.ticks.map(tick => ctx.measureText(String(tick.label)).width));
    ctx.restore();
    scale.width = Math.min(Math.max(scale.width, widest + 16), scale.chart.width * 0.5);
  }
  function aside(body, html) { if (html) body.insertAdjacentHTML('beforeend', `<div class="csms-card__aside">${html}</div>`); }
  function noCurriculum(list) {
    return list && list.length ? '<p><strong>No curriculum yet:</strong> ' + list.map(esc).join(', ') + '</p>' : '';
  }
  function moreNote(context, d, noun) {
    const shown = (d.rows || d.bars || []).length, total = d.total_rows || 0;
    if (total <= shown) return '';
    const link = context.item.module ? ` <a href="${esc(moduleHref(context.item.module, context.filter))}">View all</a>.` : '';
    return `<p>Showing the top ${shown} of ${total} ${noun}.${link} Choose a program above to narrow this chart.</p>`;
  }

  function periodLabel(d, key) {
    if (d.size === 'month') return monthLabel(key);
    const parts = key.split('-').map(Number);
    return 'Wk ' + new Date(parts[0], parts[1] - 1, parts[2]).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  }
  function discreteBars(body, d, label, stacked) {
    const labels = d.periods.map(p => periodLabel(d, p));
    const colors = d.series.map((x, i) => semantic[x.label] || (d.series.length === 1 ? color.navy : series[i % series.length]));
    chart(canvas(body, label + ': ' + d.series.map(x => x.label + ' total ' + x.values.reduce((m, n) => m + n, 0)).join('; '), 240), {
      type: 'bar',
      data: { labels, datasets: d.series.map((x, i) => ({ label: x.label, data: x.values, backgroundColor: colors[i] })) },
      plugins: [valueLabels],
      options: { layout: padVertical,
        scales: { x: { stacked, grid: { display: false } }, y: { stacked, beginAtZero: true, grace: '8%', ticks: { precision: 0 }, title: { display: true, text: 'Count' } } },
        plugins: { legend: { display: d.series.length > 1 }, valueLabels: { enabled: true, stacked, inside: stacked } } }
    });
    aside(body, dataTable([d.size === 'week' ? 'Week' : 'Month', ...d.series.map(x => x.label)], d.periods.map((p, i) => [labels[i], ...d.series.map(x => x.values[i])])));
  }

  // Part of a whole, up to six fixed categories: total in the centre, count and share in the legend.
  function donut(body, d, label) {
    const colors = d.segments.map((s, i) => semantic[s.label] || series[i % series.length]);
    const wrap = document.createElement('div');
    wrap.className = 'csms-donut';
    const holder = document.createElement('div');
    holder.className = 'csms-donut__chart';
    const c = document.createElement('canvas');
    c.setAttribute('role', 'img');
    c.setAttribute('aria-label', label + ': ' + d.segments.map(s => s.label + ' ' + s.value + ' (' + pct(s.value, d.total) + '%)').join('; ') + '. Total ' + d.total + '.');
    holder.appendChild(c);
    const legend = document.createElement('ul');
    legend.className = 'csms-legend';
    legend.innerHTML = d.segments.map((s, i) => `<li><span class="csms-legend__swatch" style="background:${colors[i]}" aria-hidden="true"></span>` +
      `<span class="csms-legend__label">${esc(s.label)}</span><span class="csms-legend__value">${num(s.value)} <small>${pct(s.value, d.total)}%</small></span></li>`).join('');
    wrap.append(holder, legend);
    body.appendChild(wrap);
    chart(c, {
      type: 'doughnut',
      data: { labels: d.segments.map(s => s.label), datasets: [{ data: d.segments.map(s => s.value), backgroundColor: colors, borderColor: '#ffffff', borderWidth: 2 }] },
      plugins: [centerTotal],
      options: { cutout: '66%', plugins: { legend: { display: false }, centerTotal: { text: num(d.total), caption: 'Total' },
        tooltip: { callbacks: { label: t => ` ${t.label}: ${num(t.parsed)} (${pct(t.parsed, d.total)}%)` } } } }
    });
    aside(body, dataTable(['Category', 'Count', 'Share'], d.segments.map(s => [s.label, num(s.value), pct(s.value, d.total) + '%'])));
  }

  const renderers = {
    accounts_by_role(body, d) { donut(body, d, 'Active accounts by role'); },
    programs_by_level(body, d) { donut(body, d, 'Active programs by level'); },
    decision_split(body, d) { donut(body, d, 'Decisions in the last 90 days'); },
    compliance_working(body, d) { donut(body, d, 'Validation results of the working version'); },
    audit_weekly(body, d) { discreteBars(body, d, 'Audit events', false); },
    review_outcomes(body, d) { discreteBars(body, d, 'Review outcomes', true); },
    pipeline(body, d, context) {
      const keys = Object.keys(d.buckets);
      if (d.rows.length) {
        const totals = keys.map(k => d.rows.reduce((m, r) => m + r.counts[k], 0));
        chart(canvas(body, 'Curriculum pipeline: ' + d.rows.map(r => r.label + ' ' + keys.filter(k => r.counts[k]).map(k => d.buckets[k] + ' ' + r.counts[k]).join(', ')).join('; '), Math.max(170, 34 * d.rows.length + 90)), {
          type: 'bar',
          data: { labels: d.rows.map(r => r.label), datasets: keys.map((k, i) => ({ label: d.buckets[k] + ' (' + totals[i] + ')', data: d.rows.map(r => r.counts[k]), backgroundColor: bucketColor[k], borderColor: '#ffffff', borderWidth: 1 })) },
          plugins: [valueLabels],
          options: { indexAxis: 'y', layout: padHorizontal,
            scales: { x: { stacked: true, beginAtZero: true, grace: '4%', ticks: { precision: 0 }, title: { display: true, text: 'Versions' } }, y: { stacked: true, grid: { display: false }, ticks: { autoSkip: false } } },
            plugins: { valueLabels: { enabled: true, stacked: true, inside: true } } }
        });
      }
      aside(body, moreNote(context, d, 'programs') + noCurriculum(d.no_curriculum) + (d.rows.length ? dataTable(['Program', ...keys.map(k => d.buckets[k])], d.rows.map(r => [r.label, ...keys.map(k => r.counts[k])])) : ''));
    },
    completeness(body, d, context) {
      if (d.rows.length) {
        const labels = d.rows.map(r => r.label);
        const hasTarget = d.rows.some(r => r.target !== null);
        // The required-units marker is drawn on top of each bar; rows without a rule have none.
        const targetMarker = { id: 'targetMarker', afterDatasetsDraw(c) {
          const ctx = c.ctx, meta = c.getDatasetMeta(0);
          d.rows.forEach((r, i) => {
            if (r.target === null || !meta.data[i]) return;
            const x = c.scales.x.getPixelForValue(r.target), y = meta.data[i].y;
            ctx.save(); ctx.strokeStyle = color.vermilion; ctx.lineWidth = 3;
            ctx.beginPath(); ctx.moveTo(x, y - 14); ctx.lineTo(x, y + 14); ctx.stroke(); ctx.restore();
          });
        } };
        const most = Math.max(...d.rows.map(r => Math.max(r.units, r.target || 0)));
        chart(canvas(body, 'Curriculum completeness: ' + d.rows.map(r => r.label + ' ' + num(r.units) + ' units, ' + (r.target === null ? 'no target set' : 'target ' + num(r.target))).join('; '), Math.max(170, 36 * d.rows.length + 70)), {
          type: 'bar', plugins: [targetMarker, valueLabels],
          data: { labels, datasets: [{ label: 'Placed units', data: d.rows.map(r => r.units), backgroundColor: color.navy }] },
          options: { indexAxis: 'y', layout: padHorizontal,
            scales: { x: { beginAtZero: true, suggestedMax: most * 1.05, title: { display: true, text: hasTarget ? 'Units (red mark: required units)' : 'Units' } }, y: { grid: { display: false }, ticks: { callback: shortTick, autoSkip: false }, afterFit: fitAxis } },
            plugins: { legend: { display: false },
              valueLabels: { enabled: true, labelAt: (i, v) => Math.max(v, d.rows[i].target || 0) },
              tooltip: { callbacks: { footer: items => { const r = d.rows[items[0].dataIndex]; return r.target === null ? 'No target set' : 'Required ' + num(r.target) + ': ' + r.target_name; } } } } }
        });
      }
      aside(body, moreNote(context, d, 'curricula') + (d.rows.length ? dataTable(['Curriculum · counted version', 'Placed units', 'Required units'], d.rows.map(r => [r.label, num(r.units), r.target === null ? 'No target set' : num(r.target)])) : ''));
    },
    prerequisite_health(body, d, context) {
      const measured = d.rows.filter(r => r.percent !== null);
      const unmeasured = d.rows.filter(r => r.percent === null);
      if (measured.length) {
        chart(canvas(body, 'Year 2–4 subjects with a prerequisite: ' + measured.map(r => r.label + ' ' + r.percent + '%').join('; '), Math.max(150, 34 * measured.length + 70)), {
          type: 'bar', plugins: [valueLabels],
          data: { labels: measured.map(r => r.label), datasets: [{ label: 'Year 2–4 subjects with a prerequisite', data: measured.map(r => r.percent), backgroundColor: color.navy }] },
          options: { indexAxis: 'y', layout: padHorizontal,
            scales: { x: { beginAtZero: true, max: 100, ticks: { stepSize: 25, callback: v => v + '%' }, title: { display: true, text: 'Year 2–4 subjects with a prerequisite' } }, y: { grid: { display: false }, ticks: { callback: shortTick, autoSkip: false }, afterFit: fitAxis } },
            plugins: { legend: { display: false }, valueLabels: { enabled: true, format: v => v + '%' },
              tooltip: { callbacks: { label: t => { const r = measured[t.dataIndex]; return ` ${r.percent}% (${r.upper_with_prerequisite} of ${r.upper_subjects} subjects)`; },
                footer: items => { const r = measured[items[0].dataIndex]; return r.later_term + ' later-term conflicts · ' + r.circular + ' circular chains'; } } } } }
        });
      }
      const conflicts = d.rows.reduce((m, r) => m + r.later_term, 0), cycles = d.rows.reduce((m, r) => m + r.circular, 0);
      const summary = conflicts + cycles === 0 ? '<p>No sequence conflicts or circular chains.</p>'
        : `<p><strong>${conflicts}</strong> later-term ${conflicts === 1 ? 'conflict' : 'conflicts'} and <strong>${cycles}</strong> circular ${cycles === 1 ? 'chain' : 'chains'} in these curricula.</p>`;
      aside(body, moreNote(context, d, 'curricula') + summary + (unmeasured.length ? '<p>' + unmeasured.length + (unmeasured.length === 1 ? ' curriculum has' : ' curricula have') + ' no Year 2–4 placements, so no share is shown.</p>' : '') +
        dataTable(['Curriculum · counted version', 'Later-term conflicts', 'Circular chains', 'Year 2–4 with a prerequisite'],
          d.rows.map(r => [r.label, r.later_term, r.circular, r.percent === null ? 'Not applicable' : r.percent + '% (' + r.upper_with_prerequisite + ' of ' + r.upper_subjects + ')'])));
      if (d.histogram) {
        chart(canvas(body, 'Prerequisites per subject: ' + d.histogram.bins.map(b => b.label + ' prerequisites, ' + b.subjects + ' subjects').join('; '), 190), {
          type: 'bar', plugins: [valueLabels],
          data: { labels: d.histogram.bins.map(b => b.label), datasets: [{ label: 'Subjects', data: d.histogram.bins.map(b => b.subjects), backgroundColor: color.navy, barPercentage: 1, categoryPercentage: .9 }] },
          options: { layout: padVertical, scales: { x: { grid: { display: false }, title: { display: true, text: 'Stored prerequisites per subject' } }, y: { beginAtZero: true, grace: '8%', ticks: { precision: 0 } } },
            plugins: { legend: { display: false }, valueLabels: { enabled: true } } }
        });
      }
    },
    compliance_under_review(body, d, context) {
      if (d.bars.length) {
        const sets = [['Pass', 'pass'], ['Fail', 'fail'], ['Not evaluated', 'not_evaluated']];
        chart(canvas(body, 'Compliance results: ' + d.bars.map(b => b.label + ' pass ' + b.pass + ', fail ' + b.fail + ', not evaluated ' + b.not_evaluated).join('; '), Math.max(170, 34 * d.bars.length + 90)), {
          type: 'bar', plugins: [valueLabels],
          data: { labels: d.bars.map(b => b.label), datasets: sets.map(([name, key]) => ({ label: name + ' (' + d.bars.reduce((m, b) => m + b[key], 0) + ')', data: d.bars.map(b => b[key]), backgroundColor: semantic[name], borderColor: '#ffffff', borderWidth: 1 })) },
          options: { indexAxis: 'y', layout: padHorizontal,
            scales: { x: { stacked: true, beginAtZero: true, grace: '4%', ticks: { precision: 0 }, title: { display: true, text: 'Rules' } }, y: { stacked: true, grid: { display: false }, ticks: { callback: shortTick, autoSkip: false }, afterFit: fitAxis } },
            plugins: { valueLabels: { enabled: true, stacked: true, inside: true } } }
        });
      }
      aside(body, moreNote(context, d, 'versions') + (d.pending && d.pending.length ? '<p><strong>Not yet validated:</strong> ' + d.pending.map(esc).join(', ') + '</p>' : ''));
    },
    term_load(body, d) {
      const toggle = document.createElement('div');
      toggle.className = 'csms-card__toggle';
      toggle.setAttribute('role', 'group');
      toggle.setAttribute('aria-label', 'Measure');
      toggle.innerHTML = '<button type="button" data-measure="units" aria-pressed="true">Units</button><button type="button" data-measure="subjects" aria-pressed="false">Subjects</button>';
      body.appendChild(toggle);
      const datasets = measure => d.series.map((x, i) => ({ label: x.label, data: x.points.map(p => p[measure]), backgroundColor: series[i % series.length] }));
      const instance = chart(canvas(body, 'Load by year and semester: ' + d.totals.subjects + ' subjects, ' + num(d.totals.units) + ' units.', 240), {
        type: 'bar', data: { labels: d.years, datasets: datasets('units') }, plugins: [valueLabels],
        options: { layout: padVertical, scales: { x: { grid: { display: false }, title: { display: true, text: 'Year level' } }, y: { beginAtZero: true, grace: '10%', title: { display: true, text: 'Units' } } },
          plugins: { valueLabels: { enabled: true } } }
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

  // Card-level filter (Super Admin): the program filter lives in the pipeline card's header and reaches only that card.
  const cardScope = root.dataset.filterScope === 'card';
  function attachCardFilter(el) {
    const box = $('dashboardCardFilter');
    if (!box) return;
    const head = el.querySelector('.csms-card__head');
    const link = head.querySelector('.csms-card__link');
    const tools = document.createElement('div');
    tools.className = 'csms-card__tools';
    head.classList.add('csms-card__head--tools');
    tools.appendChild(box);
    if (link) tools.appendChild(link);
    head.appendChild(tools);
  }

  function render(payload) {
    charts.splice(0).forEach(c => c.destroy());
    const filter = payload.filter || {};
    // Park the card filter before the grid is rebuilt, so re-rendering never destroys it.
    if (cardScope && $('dashboardCardFilter') && $('dashboardFilterHome')) $('dashboardFilterHome').appendChild($('dashboardCardFilter'));
    const filterFor = key => (cardScope && key !== 'pipeline' ? {} : filter);

    $('metricGrid').innerHTML = (payload.stats || []).map(x => {
      const value = x.value === null ? '—' : num(x.value);
      const inner = '<span class="csms-stat__label">' + esc(x.label) + '</span><strong class="csms-stat__value">' + esc(value) + '</strong>' + (x.detail ? '<span class="csms-stat__detail">' + esc(x.detail) + '</span>' : '');
      return x.module ? '<a class="csms-stat" href="' + esc(moduleHref(x.module, filterFor('stat'))) + '">' + inner + '</a>' : '<div class="csms-stat">' + inner + '</div>';
    }).join('');
    $('metricGrid').hidden = !(payload.stats || []).length;

    const grid = $('analyticsGrid');
    grid.innerHTML = '';
    Object.entries(payload.charts || {}).forEach(([key, item]) => {
      const el = card(key, item, filterFor(key), key === 'pipeline' ? 'csms-card--wide' : '');
      if (cardScope && key === 'pipeline') attachCardFilter(el);
      el.classList.toggle('csms-card--compact', item.status === 'empty');
      grid.appendChild(el);
      const body = el.querySelector('.csms-card__body');
      if (item.status === 'ok' && renderers[key]) renderers[key](body, item.data, { item, filter: filterFor(key) });
      else renderState(body, item);
    });
    grid.hidden = !grid.children.length;
    const rule = $('dashboardVersionRule');
    rule.textContent = payload.version_rule || '';
    rule.hidden = !payload.version_rule;

    const lists = $('dashboardLists');
    lists.innerHTML = '';
    Object.entries(payload.lists || {}).forEach(([key, item]) => {
      const el = card(key, Object.assign({}, item, { type: 'list' }), filterFor(key), 'csms-card--list');
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
      if (!cardScope) $('dashboardSubtitle').textContent = program ? `Curriculum overview for ${program.code} — ${program.name}.` : 'Curriculum overview across all programs and strands.';
      const url = new URL(location.href);
      url.searchParams.delete('program'); url.searchParams.delete('version');
      if (data.filter.program_id) url.searchParams.set('program', data.filter.program_id);
      if (data.filter.program_id && versionId) url.searchParams.set('version', data.filter.version_id);
      history[push ? 'pushState' : 'replaceState']({ program: data.filter.program_id, version: versionId || null }, '', url);
      $('dashboardStatus').textContent = (cardScope ? 'Pipeline updated' : 'Dashboard updated') + (program ? ' for ' + program.code : ' for all programs') + '.';
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
  // Canvas text is measured when a chart is built, so wait (at most 1.5 s) for the dashboard font first.
  // Otherwise axis labels are measured in the fallback font and clipped once DM Sans loads.
  if (initial) {
    const faces = ['400 12px "DM Sans"', '500 12px "DM Sans"', '600 12px "DM Sans"', '700 26px "DM Sans"'];
    const ready = document.fonts && document.fonts.load
      ? Promise.race([Promise.all(faces.map(f => document.fonts.load(f))), new Promise(resolve => setTimeout(resolve, 1500))]).catch(() => {})
      : Promise.resolve();
    ready.then(() => render(initial));
  }

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
