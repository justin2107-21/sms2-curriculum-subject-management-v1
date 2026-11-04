(function () {
  'use strict';
  // Program / Curriculum / Version picker (UI_Decisions D2). Three select-like fields with the label above, each
  // opening a searchable panel (the pattern of js/csms-program-picker.js: search box, grouped options, keyboard,
  // ARIA combobox). The page's own autocomplete cascade stays underneath as the engine: this script reads its level
  // definitions (search / format / getId) and selects through its selectors, so every page keeps its own
  // permission-checked lookups, its onChange handlers and its IDs. The original inputs stay in the DOM, hidden.
  // Choosing a program opens Curriculum, then Version; a list with a single option is picked for the user.

  const ROOTS = '.mapping-context-bar, .pr-selectors, .grade-weighting-context-card';
  const LEVELS = 3;
  const NAMES = ['Program', 'Curriculum', 'Version'];
  const PLACEHOLDERS = ['Choose program', 'Choose curriculum', 'Choose version'];
  const STATUS_LABEL = { draft: 'Draft', active: 'Active', archived: 'Archived' };
  const STATUS_ORDER = { active: 0, draft: 1 };
  const HINT = 'Choose a curriculum version to begin';
  let uid = 0;

  // Selection events can arrive before the picker is built (this script loads first): keep them and replay.
  const log = [];
  const listeners = [];
  document.addEventListener('csms:curriculum-context-selection', function (event) {
    const detail = event.detail || {};
    log.push(detail);
    listeners.forEach(function (listener) { listener(detail); });
  });
  const details = [];
  let domReady = document.readyState !== 'loading';
  document.addEventListener('csms:curriculum-context-cascade-ready', function (event) {
    if (event.detail && Array.isArray(event.detail.levels)) details.push(event.detail);
    flush();
  });
  document.addEventListener('DOMContentLoaded', function () { domReady = true; flush(); });
  function flush() {
    if (!domReady) return;
    details.splice(0).forEach(build);
  }

  const isShs = function (item) {
    const type = String((item && item.program_type) || '').toLowerCase();
    return type.indexOf('senior') !== -1 || type === 'shs' || type.indexOf('strand') !== -1;
  };
  const parts = function (index, item) {
    return window.CSMSWorkingContext && window.CSMSWorkingContext.parts ? window.CSMSWorkingContext.parts(index, item) : { code: '', name: '', status: '', year: '' };
  };

  function build(detail) {
    const levels = detail.levels.slice(0, LEVELS);
    const selectors = detail.selectors.slice(0, LEVELS);
    if (levels.length < LEVELS || !levels[0].input || !levels[0].input.closest) return;
    const root = levels[0].input.closest(ROOTS);
    if (!root || root.classList.contains('csms-ctx-root')) return;
    const id = 'csmsCtx' + (++uid);
    const items = [null, null, null];
    let advanceToken = 0;
    let openIndex = -1;

    // ---- hide the original controls (kept in the DOM: pages and the cascade read them) ----
    root.classList.add('csms-ctx-root');
    const boxes = [...root.querySelectorAll('.record-autocomplete')].slice(0, LEVELS);
    boxes.forEach(function (box) { box.parentElement.classList.add('csms-hidden-field'); });
    root.querySelectorAll('[class*="separator"], [class*="context-arrow"]').forEach(function (node) { node.classList.add('csms-hidden-field'); });
    const row = boxes[0] && boxes[0].parentElement.parentElement;
    if (row && row !== root) row.classList.add('csms-hidden-field');

    // ---- build the picker ----
    const ui = document.createElement('div');
    ui.className = 'csms-ctx';
    ui.setAttribute('role', 'group');
    ui.setAttribute('aria-label', 'Curriculum context');
    const fields = [];
    NAMES.forEach(function (name, index) {
      if (index) ui.insertAdjacentHTML('beforeend', '<span class="csms-ctx-sep" aria-hidden="true">›</span>');
      const field = document.createElement('div');
      field.className = 'csms-ctx-field';
      field.innerHTML =
        '<span class="csms-ctx-label" id="' + id + 'L' + index + '">' + name + '</span>' +
        '<button type="button" class="csms-ctx-trigger" id="' + id + 'T' + index + '" aria-haspopup="listbox" aria-expanded="false" aria-controls="' + id + 'P' + index + '" aria-labelledby="' + id + 'L' + index + ' ' + id + 'T' + index + '">' +
          '<span class="csms-ctx-value"></span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>' +
        '<div class="csms-ctx-panel" id="' + id + 'P' + index + '" hidden>' +
          '<input type="search" class="csms-ctx-search" role="combobox" aria-autocomplete="list" aria-expanded="true" aria-controls="' + id + 'O' + index + '" autocomplete="off" placeholder="Search ' + name.toLowerCase() + '" aria-label="Search ' + name.toLowerCase() + '">' +
          '<p class="csms-ctx-count" aria-live="polite"></p>' +
          '<div class="csms-ctx-options" id="' + id + 'O' + index + '" role="listbox" aria-labelledby="' + id + 'L' + index + '"></div>' +
        '</div>';
      ui.appendChild(field);
      fields.push({
        field: field,
        trigger: field.querySelector('.csms-ctx-trigger'),
        value: field.querySelector('.csms-ctx-value'),
        panel: field.querySelector('.csms-ctx-panel'),
        search: field.querySelector('.csms-ctx-search'),
        count: field.querySelector('.csms-ctx-count'),
        list: field.querySelector('.csms-ctx-options'),
        shown: [],
        active: -1,
        seq: 0,
        timer: 0
      });
    });
    const reset = document.createElement('button');
    reset.type = 'button';
    reset.className = 'csms-ctx-reset';
    reset.textContent = 'Reset';
    reset.hidden = true;
    ui.appendChild(reset);
    const hint = document.createElement('span');
    hint.className = 'csms-ctx-hint';
    hint.textContent = HINT;
    ui.appendChild(hint);
    root.insertBefore(ui, root.firstChild);

    // ---- state shown in the fields ----
    function selectedLabel(index) {
      const item = items[index];
      if (item) {
        const p = parts(index, item);
        if (index === 2 && !p.status && window.CSMSWorkingContext) {
          const known = window.CSMSWorkingContext.current().version;
          if (known && known.id === String(item.id)) p.status = known.status || '';
        }
        const label = index < 2 ? [p.code, p.name].filter(Boolean).join(' · ') : [p.code, STATUS_LABEL[p.status] || ''].filter(Boolean).join(' · ');
        if (label) return label;
        const shown = levels[index].format ? levels[index].format(item) : null;
        if (shown && (shown.label || typeof shown === 'string')) return String(shown.label || shown);
      }
      // Selected silently by a page (no item known): use what the engine shows.
      return selectors[index].hasSelection() ? String(selectors[index].getSelectedLabel() || '') : '';
    }
    function render() {
      fields.forEach(function (entry, index) {
        const label = selectedLabel(index);
        entry.value.textContent = label || PLACEHOLDERS[index];
        entry.trigger.title = label || '';
        entry.field.classList.toggle('is-empty', !label);
        const blocked = index === 0 ? Boolean(levels[0].input.disabled) : !selectors[index - 1].hasSelection();
        entry.trigger.disabled = blocked;
        entry.field.classList.toggle('is-disabled', blocked);
      });
      const any = selectors.some(function (selector) { return selector.hasSelection(); });
      reset.hidden = !any;
      hint.hidden = selectors[2].hasSelection();
    }
    function onSelection(change) {
      const index = Number(change.index);
      if (!(index >= 0 && index < LEVELS)) return;
      if (change.action === 'clear' || !change.item) {
        for (let i = index; i < LEVELS; i += 1) items[i] = null;
      } else {
        items[index] = change.item;
        for (let i = index + 1; i < LEVELS; i += 1) items[i] = null;
      }
      render();
    }
    listeners.push(onSelection);
    log.forEach(onSelection);
    document.addEventListener('csms:context-changed', render);   // also reflects silent changes made by a page

    // ---- options ----
    function optionRows(index, list, query) {
      const rows = [];
      const level = levels[index];
      const entries = Array.isArray(list) ? list.slice() : [];
      const format = function (item) {
        const shown = level.format ? level.format(item) : { label: String(item.label || '') };
        return typeof shown === 'string' ? { label: shown } : shown;
      };
      if (index === 0 && !query) {
        const recent = window.CSMSWorkingContext ? window.CSMSWorkingContext.recent() : [];
        if (recent.length) {
          rows.push({ heading: 'Recent' });
          recent.forEach(function (context) {
            rows.push({ kind: 'recent', context: context, code: [context.program.code, context.curriculum.code, context.version.code].filter(Boolean).join(' / '),
              meta: STATUS_LABEL[String(context.version.status || '').toLowerCase()] || '' });
          });
        }
      }
      if (index === 0) {
        const groups = [['College programs', []], ['Senior High School strands', []]];
        entries.forEach(function (item) { groups[isShs(item) ? 1 : 0][1].push(item); });
        groups.forEach(function (group) {
          if (!group[1].length) return;
          rows.push({ heading: group[0] });
          group[1].forEach(function (item) { const p = parts(0, item); const f = format(item); rows.push({ kind: 'item', item: item, code: p.code || f.code || f.label || '', name: p.name || f.title || '' }); });
        });
      } else if (index === 1) {
        entries.forEach(function (item) { const p = parts(1, item); const f = format(item); rows.push({ kind: 'item', item: item, code: p.code || f.code || f.label || '', name: p.name || f.title || '' }); });
      } else {
        entries.sort(function (a, b) { return (STATUS_ORDER[parts(2, a).status] ?? 2) - (STATUS_ORDER[parts(2, b).status] ?? 2); });
        entries.forEach(function (item) {
          const p = parts(2, item);
          const f = format(item);
          rows.push({ kind: 'item', item: item, code: [p.code || f.code || f.label || '', STATUS_LABEL[p.status] || ''].filter(Boolean).join(' · '), name: p.year });
        });
      }
      return rows;
    }
    function paint(index, rows, query, error) {
      const entry = fields[index];
      entry.list.replaceChildren();
      entry.shown = [];
      entry.active = -1;
      entry.search.removeAttribute('aria-activedescendant');
      let found = 0;
      if (error) {
        const p = document.createElement('p');
        p.className = 'csms-ctx-state';
        p.setAttribute('role', 'alert');
        p.textContent = 'Could not load the list.';
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'csms-ctx-link';
        retry.textContent = 'Retry';
        retry.addEventListener('click', function () { load(index, query); });
        p.append(' ', retry);
        entry.list.appendChild(p);
        entry.count.textContent = '';
        return;
      }
      rows.forEach(function (row) {
        if (row.heading) {
          const h = document.createElement('div');
          h.className = 'csms-ctx-group';
          h.setAttribute('role', 'presentation');
          h.textContent = row.heading;
          entry.list.appendChild(h);
          return;
        }
        const el = document.createElement('div');
        el.className = 'csms-ctx-option' + (row.kind === 'recent' ? ' is-recent' : '');
        el.id = id + 'Opt' + index + '_' + entry.shown.length;
        el.setAttribute('role', 'option');
        el.setAttribute('aria-selected', 'false');
        el.dataset.index = String(entry.shown.length);
        const code = document.createElement('strong');
        code.textContent = row.code;
        el.appendChild(code);
        const extra = row.kind === 'recent' ? row.meta : row.name;
        if (extra) { const span = document.createElement('span'); span.textContent = extra; el.appendChild(span); }
        entry.list.appendChild(el);
        entry.shown.push(row);
        if (row.kind === 'item') found += 1;
      });
      entry.count.textContent = found === 1 ? '1 result' : found + ' results';
      if (!found) {
        const p = document.createElement('p');
        p.className = 'csms-ctx-state';
        p.textContent = query ? "No " + NAMES[index].toLowerCase() + " matches '" + query + "'." :
          (index === 0 ? 'No programs are available.' : index === 1 ? 'No curricula for this program yet.' : 'No versions for this curriculum yet.');
        entry.list.appendChild(p);
      }
    }
    async function load(index, query, preloaded) {
      const entry = fields[index];
      const seq = ++entry.seq;
      entry.count.textContent = 'Loading…';
      let list;
      try { list = preloaded || await levels[index].search(query); }
      catch (error) { if (seq === entry.seq) paint(index, [], query, true); return; }
      if (seq !== entry.seq) return;
      paint(index, optionRows(index, list, query), query, false);
    }

    // ---- open / close ----
    function setActive(index, i) {
      const entry = fields[index];
      if (entry.active >= 0) {
        const prev = document.getElementById(id + 'Opt' + index + '_' + entry.active);
        if (prev) { prev.classList.remove('active'); prev.setAttribute('aria-selected', 'false'); }
      }
      entry.active = entry.shown.length ? (i + entry.shown.length) % entry.shown.length : -1;
      if (entry.active < 0) { entry.search.removeAttribute('aria-activedescendant'); return; }
      const el = document.getElementById(id + 'Opt' + index + '_' + entry.active);
      if (!el) return;
      el.classList.add('active');
      el.setAttribute('aria-selected', 'true');
      entry.search.setAttribute('aria-activedescendant', el.id);
      el.scrollIntoView({ block: 'nearest' });
    }
    function closeAll(focusTrigger) {
      fields.forEach(function (entry, index) {
        if (entry.panel.hidden) return;
        entry.panel.hidden = true;
        entry.trigger.setAttribute('aria-expanded', 'false');
        if (focusTrigger && index === openIndex) entry.trigger.focus();
      });
      openIndex = -1;
    }
    function open(index, preloaded) {
      if (fields[index].trigger.disabled) return;
      closeAll(false);
      openIndex = index;
      const entry = fields[index];
      entry.panel.hidden = false;
      entry.trigger.setAttribute('aria-expanded', 'true');
      entry.search.value = '';
      load(index, '', preloaded).then(function () { if (entry.shown.length) setActive(index, 0); });
      entry.search.focus();
    }

    // ---- choosing ----
    async function advance(index) {
      if (index >= LEVELS - 1) { fields[index].trigger.focus(); return; }
      const my = ++advanceToken;
      const next = index + 1;
      let list = null;
      try { list = await levels[next].search(''); } catch (error) { list = null; }
      if (my !== advanceToken) return;                       // the user chose something else meanwhile
      if (Array.isArray(list) && list.length === 1) { choose(next, { kind: 'item', item: list[0] }); return; }
      open(next, Array.isArray(list) ? list : undefined);
    }
    async function choose(index, row) {
      closeAll(false);
      if (row.kind === 'recent') {
        advanceToken += 1;
        const ok = await window.CSMSWorkingContext.apply(row.context);
        if (!ok) {
          window.CSMSWorkingContext.forget(row.context.version.id);
          hint.hidden = false;
          hint.textContent = 'That recent context is no longer available.';
          setTimeout(function () { hint.textContent = HINT; hint.hidden = selectors[2].hasSelection(); }, 4000);
          fields[0].trigger.focus();
        } else {
          fields[2].trigger.focus();
        }
        return;
      }
      selectors[index].setSelected(row.item);                // the engine clears the levels below it and tells the page
      await advance(index);
    }

    // ---- events ----
    fields.forEach(function (entry, index) {
      entry.trigger.addEventListener('click', function () { if (entry.panel.hidden) open(index); else closeAll(true); });
      entry.trigger.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); open(index); }
      });
      entry.search.addEventListener('input', function () {
        clearTimeout(entry.timer);
        entry.timer = setTimeout(function () { load(index, entry.search.value.trim()).then(function () { if (entry.shown.length) setActive(index, 0); }); }, 200);
      });
      entry.search.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown') { event.preventDefault(); setActive(index, entry.active + 1); }
        else if (event.key === 'ArrowUp') { event.preventDefault(); setActive(index, entry.active <= 0 ? entry.shown.length - 1 : entry.active - 1); }
        else if (event.key === 'Home') { event.preventDefault(); setActive(index, 0); }
        else if (event.key === 'End') { event.preventDefault(); setActive(index, entry.shown.length - 1); }
        else if (event.key === 'Enter') { event.preventDefault(); if (entry.active >= 0) choose(index, entry.shown[entry.active]); }
        else if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeAll(true); }
        else if (event.key === 'Tab') { closeAll(false); }
      });
      entry.list.addEventListener('mousedown', function (event) { if (event.target.closest('[role="option"]')) event.preventDefault(); });
      entry.list.addEventListener('click', function (event) {
        const option = event.target.closest('[role="option"]');
        if (option) choose(index, entry.shown[Number(option.dataset.index)]);
      });
    });
    reset.addEventListener('click', function () { advanceToken += 1; closeAll(false); selectors[0].clear(); fields[0].trigger.focus(); });
    document.addEventListener('click', function (event) { if (!ui.contains(event.target)) closeAll(false); });
    render();
  }
}());
