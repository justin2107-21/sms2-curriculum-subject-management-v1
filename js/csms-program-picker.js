(function () {
  'use strict';
  // Searchable Program / Strand picker. Enhances a native <select data-program-picker>, which
  // stays the value holder (and the no-JavaScript fallback); choosing an item sets select.value
  // and fires "change", so page code keeps listening to the select as before.
  // Options use the shared record-autocomplete classes so the picker looks like the other
  // record pickers (Subject Mapping, Pre-requisite Configuration).
  // Options are built once; typing only toggles `hidden` (debounced), so 100+ programs stay fast.

  const LEVELS = [['all', 'All'], ['college', 'College'], ['shs', 'Senior High']];
  let uid = 0;

  function enhance(select) {
    const id = 'csmsPicker' + (++uid);
    const label = document.querySelector(`label[for="${select.id}"]`);
    const wrap = document.createElement('div');
    wrap.className = 'csms-picker';
    wrap.innerHTML =
      `<button type="button" class="csms-picker__trigger" id="${id}Trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="${id}Panel">` +
        '<span class="csms-picker__value"></span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>' +
      `<div class="csms-picker__panel" id="${id}Panel" hidden>` +
        `<input type="search" class="csms-picker__search" id="${id}Search" role="combobox" aria-autocomplete="list" aria-expanded="true" aria-controls="${id}List" autocomplete="off" placeholder="Search code or name" aria-label="Search programs and strands">` +
        `<div class="csms-picker__levels" role="group" aria-label="Level">` +
          LEVELS.map(([v, t], i) => `<button type="button" data-level="${v}" aria-pressed="${i === 0}">${t}</button>`).join('') + '</div>' +
        `<p class="csms-picker__count" id="${id}Count" aria-live="polite"></p>` +
        `<div class="csms-picker__list record-autocomplete-options" id="${id}List" role="listbox"></div>` +
      '</div>';
    select.after(wrap);
    select.hidden = true;
    select.setAttribute('aria-hidden', 'true');
    select.tabIndex = -1;
    if (label) { label.removeAttribute('for'); label.id = label.id || id + 'Label'; }

    const trigger = wrap.querySelector('.csms-picker__trigger');
    const valueEl = wrap.querySelector('.csms-picker__value');
    const panel = wrap.querySelector('.csms-picker__panel');
    const search = wrap.querySelector('.csms-picker__search');
    const list = wrap.querySelector('.csms-picker__list');
    const count = wrap.querySelector('.csms-picker__count');
    const levelButtons = [...wrap.querySelectorAll('[data-level]')];
    if (label) { trigger.setAttribute('aria-labelledby', `${label.id} ${id}Trigger`); list.setAttribute('aria-labelledby', label.id); }

    // Build every row once.
    const items = [];
    const groups = [];
    const addOption = (opt, groupEl) => {
      const all = opt.value === 'all';
      const row = document.createElement('div');
      row.className = 'record-autocomplete-option csms-picker__option' + (all ? ' csms-picker__option--pinned' : '');
      row.id = `${id}Opt${items.length}`;
      row.setAttribute('role', 'option');
      row.setAttribute('aria-selected', 'false');
      const code = opt.dataset.code || '';
      const name = opt.dataset.name || opt.textContent.trim();
      const inactive = opt.dataset.inactive === '1';
      row.innerHTML = all ? '<span class="record-autocomplete-label"><strong>All programs</strong></span>' :
        `<span class="record-autocomplete-code"></span><span class="record-autocomplete-label"></span>${inactive ? '<span class="csms-picker__inactive">Inactive</span>' : ''}`;
      if (!all) {
        row.querySelector('.record-autocomplete-code').textContent = code;
        row.querySelector('.record-autocomplete-label').textContent = name;
      }
      const item = { value: opt.value, code, name, all, inactive, level: opt.dataset.level || 'college', row, groupEl,
        haystack: (code + ' ' + name).toLowerCase(), display: all ? 'All programs' : `${code} · ${name}` };
      row.dataset.index = String(items.length);
      items.push(item);
      (groupEl || list).appendChild(row);
    };
    [...select.children].forEach(child => {
      if (child.tagName === 'OPTGROUP') {
        const g = document.createElement('div');
        g.setAttribute('role', 'group');
        g.className = 'csms-picker__group';
        const head = document.createElement('div');
        head.className = 'csms-picker__group-label';
        head.id = `${id}G${groups.length}`;
        head.textContent = child.label;
        g.setAttribute('aria-labelledby', head.id);
        g.appendChild(head);
        list.appendChild(g);
        groups.push(g);
        [...child.children].forEach(opt => addOption(opt, g));
      } else {
        addOption(child, null);
      }
    });
    const empty = document.createElement('p');
    empty.className = 'record-autocomplete-state empty';
    empty.hidden = true;
    list.appendChild(empty);

    let level = 'all';
    let visible = items.slice();
    let active = -1;
    let timer = 0;

    function syncTrigger() {
      const cur = items.find(i => i.value === select.value) || items[0];
      valueEl.textContent = cur.display + (cur.inactive ? ' (Inactive)' : '');
      trigger.title = cur.display;
      items.forEach(i => i.row.classList.toggle('is-current', i === cur));
    }
    function setActive(index) {
      if (active >= 0 && visible[active]) { visible[active].row.classList.remove('active'); visible[active].row.setAttribute('aria-selected', 'false'); }
      active = visible.length ? (index + visible.length) % visible.length : -1;
      if (active < 0) { search.removeAttribute('aria-activedescendant'); return; }
      const row = visible[active].row;
      row.classList.add('active');
      row.setAttribute('aria-selected', 'true');
      search.setAttribute('aria-activedescendant', row.id);
      row.scrollIntoView({ block: 'nearest' });
    }
    function filter() {
      const q = search.value.trim().toLowerCase();
      visible = [];
      items.forEach(i => {
        const show = i.all ? !q : (level === 'all' || i.level === level) && (!q || i.haystack.includes(q));
        i.row.hidden = !show;
        if (show) visible.push(i);
      });
      groups.forEach(g => { g.hidden = !g.querySelector('[role="option"]:not([hidden])'); });
      const matches = visible.filter(i => !i.all).length;
      count.textContent = matches === 1 ? '1 program' : `${matches} programs`;
      empty.hidden = matches > 0;
      if (!matches) empty.textContent = q ? `No program matches '${search.value.trim()}'` : 'No programs at this level.';
      setActive(-1);
      if (visible.length && q) setActive(0);
    }
    function open() {
      panel.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      filter();
      const cur = visible.findIndex(i => i.value === select.value);
      if (cur >= 0) setActive(cur);
      search.focus();
    }
    function close(focusTrigger) {
      if (panel.hidden) return;
      panel.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      if (focusTrigger) trigger.focus();
    }
    function choose(item) {
      close(true);
      if (!item || item.value === select.value) return;
      select.value = item.value;
      syncTrigger();
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    trigger.addEventListener('click', () => (panel.hidden ? open() : close(true)));
    trigger.addEventListener('keydown', e => { if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); open(); } });
    search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(filter, 120); });
    search.addEventListener('keydown', e => {
      if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active <= 0 ? visible.length - 1 : active - 1); }
      else if (e.key === 'Home' && e.ctrlKey) { e.preventDefault(); setActive(0); }
      else if (e.key === 'End' && e.ctrlKey) { e.preventDefault(); setActive(visible.length - 1); }
      else if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); if (active < 0) filter(); choose(visible[Math.max(active, 0)]); }
      else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(true); }
    });
    levelButtons.forEach(btn => btn.addEventListener('click', () => {
      level = btn.dataset.level;
      levelButtons.forEach(b => b.setAttribute('aria-pressed', String(b === btn)));
      filter();
      search.focus();
    }));
    list.addEventListener('mousedown', e => { if (e.target.closest('[role="option"]')) e.preventDefault(); });
    list.addEventListener('click', e => { const row = e.target.closest('[role="option"]'); if (row) choose(items[Number(row.dataset.index)]); });
    panel.addEventListener('keydown', e => { if (e.key === 'Escape') { e.preventDefault(); close(true); } });
    document.addEventListener('click', e => { if (!wrap.contains(e.target)) close(false); });
    wrap.addEventListener('focusout', e => { if (e.relatedTarget && !wrap.contains(e.relatedTarget)) close(false); });
    select.addEventListener('csms:picker-sync', syncTrigger);
    syncTrigger();
  }

  // Pages that build the select after load (Subject Equivalency filter) call CsmsProgramPicker.enhance(select).
  window.CsmsProgramPicker = { enhance };
  document.querySelectorAll('select[data-program-picker]').forEach(enhance);
}());
