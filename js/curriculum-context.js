(function () {
  'use strict';
  // One working context (Program / Curriculum / Version) shared by every module page.
  //
  // - Every selection on any page is saved here and written to the address (?program_id=&curriculum_id=&version_id=),
  //   so links work and the next page opens on the same context.
  // - On page load the context is resolved in this order:
  //     (a) ids in the address, if they are valid and permitted for the page's module;
  //     (b) the user's saved working context;
  //     (c) the version the user last edited; (d) the newest active version the user may access;
  //     (e) nothing: an empty picker.
  //   "Valid and permitted" is checked by loading each level through the page's own, permission-checked option lookup;
  //   a deleted or unpermitted item makes that rule fail and the next rule is tried.
  // - Storage: per user on the server (modules/curriculum/http/working_context_actions.php), which also answers rules
  //   (a) to (d). sessionStorage is the fallback when that request fails.
  // The page scripts and the picker talk to this through two events from the shared autocomplete cascade
  // (csms:curriculum-context-cascade-ready, csms:curriculum-context-selection) and window.CSMSWorkingContext.

  const script = document.currentScript;
  const userId = (script && script.dataset && script.dataset.userId) || 'guest';
  const KEYS = ['program', 'curriculum', 'version'];
  const URL_KEYS = ['program_id', 'curriculum_id', 'version_id'];
  const MAX_RECENT = 3;
  const storageKey = 'csms.working-context.' + userId;

  // ---- store -------------------------------------------------------------------------------------------------
  const store = {
    read: function () {
      try {
        const value = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
        return value && typeof value === 'object' ? value : {};
      } catch (error) { return {}; }
    },
    write: function (value) {
      try { sessionStorage.setItem(storageKey, JSON.stringify(value)); } catch (error) { /* storage may be disabled */ }
    }
  };

  // ---- server store ------------------------------------------------------------------------------------------
  const apiUrl = (script && script.dataset && script.dataset.contextApi) || '';
  const csrfToken = (script && script.dataset && script.dataset.csrfToken) || '';
  const MODULES = { curriculum_builder: 'builder', subject_mapping: 'subject_mapping', prerequisite_configuration: 'prerequisite', electives_manager: 'electives', program_centric_assignment: 'assignment', subject_centric_assignment: 'assignment', academic_program_assignment: 'assignment', curriculum_export: 'export', grade_weighting_setup: 'grade_weighting', subject_history_view: 'history', curriculum_history_view: 'history' };
  const moduleName = function () { return MODULES[(location.pathname.split('/').pop() || '').replace(/\.php$/, '')] || ''; };

  async function serverRead(ids) {
    if (!apiUrl || !moduleName()) return null;
    try {
      const params = new URLSearchParams({ action: 'read', module: moduleName() });
      URL_KEYS.forEach(function (key, index) { if (ids[index]) params.set(key, ids[index]); });
      const response = await fetch(apiUrl + '?' + params.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const data = await response.json();
      return response.ok && data.success ? data : null;
    } catch (error) { return null; }
  }

  function serverSave(context) {
    if (!apiUrl || !moduleName()) return;
    const body = new URLSearchParams({ action: 'save', module: moduleName(), _csrf_token: csrfToken });
    if (context) URL_KEYS.forEach(function (key, index) { body.set(key, context[KEYS[index]].id); });
    else body.set('clear', '1');
    fetch(apiUrl, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true, headers: { Accept: 'application/json' } })
      .then(function (response) { return response.json(); })
      .then(function (data) { if (data && data.success && Array.isArray(data.recent)) { state.recent = data.recent; persist(); } })
      .catch(function () { /* sessionStorage still holds it for this tab */ });
  }

  const clone = function (value) { return value === undefined ? value : JSON.parse(JSON.stringify(value)); };
  const validId = function (value) { return typeof value === 'string' && /^[1-9][0-9]{0,18}$/.test(value); };
  const emptyContext = function () { return { program: null, curriculum: null, version: null }; };
  const complete = function (context) { return KEYS.every(function (key) { return context[key] && validId(context[key].id); }); };

  const saved = store.read();
  const state = {
    current: Object.assign(emptyContext(), saved.current && typeof saved.current === 'object' ? saved.current : {}),
    recent: Array.isArray(saved.recent) ? saved.recent.filter(function (entry) { return entry && complete(entry); }).slice(0, MAX_RECENT) : []
  };
  if (!complete(state.current)) {
    // A partly saved context cannot be applied; keep what is complete only.
    state.current = emptyContext();
  }

  function persist() { store.write({ current: state.current, recent: state.recent }); }

  // The modules return slightly different record shapes (program_code / curriculum_code / version_label, or the generic
  // { code, title } of the Electives lookup). This reads a code, a name and (for versions) a status from any of them.
  function parts(index, item) {
    const pick = function () {
      for (let i = 0; i < arguments.length; i += 1) {
        const value = item ? item[arguments[i]] : undefined;
        if (value !== undefined && value !== null && String(value) !== '') return String(value);
      }
      return '';
    };
    if (index === 0) return { code: pick('program_code', 'code'), name: pick('program_name', 'title', 'name') };
    if (index === 1) return { code: pick('curriculum_code', 'code'), name: pick('curriculum_name', 'title', 'name') };
    return { code: pick('version_label', 'code', 'label'), name: '', status: pick('status', 'version_status').toLowerCase(), year: pick('effective_academic_year', 'year') };
  }

  // What is kept about each level: the id, the code (used to find it again with a search) and what the UI shows.
  function describe(index, item) {
    if (!item || item.id === undefined || item.id === null) return null;
    return Object.assign({ id: String(item.id) }, parts(index, item));
  }

  function pushRecent(list, context) {
    const entry = { program: clone(context.program), curriculum: clone(context.curriculum), version: clone(context.version), at: Date.now() };
    return [entry].concat(list.filter(function (other) { return other.version.id !== entry.version.id; })).slice(0, MAX_RECENT);
  }

  // ---- address -----------------------------------------------------------------------------------------------
  let cascadePage = false;
  function readUrlIds() {
    const params = new URLSearchParams(location.search);
    return URL_KEYS.map(function (key) { const value = params.get(key); return validId(value) ? value : ''; });
  }
  function syncUrl() {
    if (!cascadePage || !window.history || !history.replaceState) return;
    const params = new URLSearchParams(location.search);
    URL_KEYS.forEach(function (key, index) {
      const level = state.current[KEYS[index]];
      if (level && level.id) params.set(key, level.id); else params.delete(key);
    });
    const query = params.toString();
    history.replaceState(history.state, '', location.pathname + (query ? '?' + query : '') + (location.hash || ''));
  }

  // ---- selection events from the cascade ---------------------------------------------------------------------
  let generation = 0;   // bumps when the user acts, so an older restore stops
  let applying = 0;     // > 0 while this script is selecting items itself
  let active = null;    // { levels, selectors } of the cascade on this page

  document.addEventListener('csms:curriculum-context-selection', function (event) {
    const detail = event.detail || {};
    const index = Number(detail.index);
    if (!(index >= 0 && index < KEYS.length)) return;
    if (!applying) generation += 1;
    if (detail.action === 'clear' || !detail.item) {
      for (let i = index; i < KEYS.length; i += 1) state.current[KEYS[i]] = null;
    } else {
      const level = describe(index, detail.item);
      if (!level) return;
      const before = state.current[KEYS[index]];
      if (before && before.id === level.id) {
        ['name', 'status', 'year'].forEach(function (field) { if (!level[field] && before[field]) level[field] = before[field]; });
      }
      state.current[KEYS[index]] = level;
      for (let i = index + 1; i < KEYS.length; i += 1) state.current[KEYS[i]] = null;
      if (complete(state.current)) state.recent = pushRecent(state.recent, state.current);
    }
    persist();
    syncUrl();
    // Saved when the user (or a page's own link handling) completes or resets the context, not while restoring one.
    if (!applying) {
      if (complete(state.current)) serverSave(state.current);
      else if (index === 0 && !detail.item) serverSave(null);
    }
  });

  // ---- applying a context through the page's own cascade ----------------------------------------------------
  async function findItem(level, want) {
    const lookup = async function (query) {
      const list = await level.search(query);
      return (Array.isArray(list) ? list : []).find(function (record) {
        return String(level.getId ? level.getId(record) : record.id) === String(want.id);
      });
    };
    return (want.code ? await lookup(want.code) : null) || await lookup('');
  }

  // Selects all three levels in order. false as soon as one is missing, deleted or not permitted.
  async function applyChain(context, detail, token) {
    for (let index = 0; index < KEYS.length; index += 1) {
      const want = context[KEYS[index]];
      if (!want || !validId(want.id)) return false;
      let item = null;
      try { item = await findItem(detail.levels[index], want); } catch (error) { item = null; }
      if (token !== generation || !item) return false;
      applying += 1;
      try { detail.selectors[index].setSelected(item); } finally { applying -= 1; }
    }
    return true;
  }

  function clearSelection(detail) {
    applying += 1;
    try { detail.selectors[0].clear(); } finally { applying -= 1; }
  }

  function takeRemote(remote) {
    if (!remote) return;
    if (Array.isArray(remote.recent)) state.recent = remote.recent;
    if (!remote.context) state.current = emptyContext();
    persist();
  }

  async function resolve(detail) {
    const token = ++generation;
    const urlIds = readUrlIds();
    // Subject Mapping and Pre-requisite restore a linked context through their own address handling. If that produces
    // nothing (the link is invalid, deleted or not permitted) the next rule applies: the saved working context.
    if (detail.restoreSavedContext === false) {
      const fallback = await serverRead(['', '', '']);
      if (token !== generation) return;
      if (fallback && Array.isArray(fallback.recent)) { state.recent = fallback.recent; persist(); }
      for (let wait = 0; wait < 13; wait += 1) {
        await new Promise(function (done) { setTimeout(done, 300); });
        if (token !== generation || detail.selectors[2].hasSelection()) return;
      }
      const next = fallback ? fallback.context : (complete(state.current) ? clone(state.current) : null);
      if (next && !(await applyChain(next, detail, token)) && token === generation) clearSelection(detail);
      return;
    }
    const remote = await serverRead(urlIds);
    if (token !== generation) return;
    if (remote) {
      takeRemote(remote);
      if (!remote.context) return;
      const ok = await applyChain(remote.context, detail, token);
      if (token !== generation) return;
      if (!ok) clearSelection(detail);
      else if (remote.rule === 'url') serverSave(state.current);     // a followed link becomes the working context
      return;
    }
    // Server not reachable: rules (a) and (b) from this tab.
    const candidates = [];
    if (urlIds.some(Boolean)) {
      const known = {};
      state.recent.concat([state.current]).forEach(function (entry) {
        KEYS.forEach(function (key) { if (entry && entry[key]) known[key + ':' + entry[key].id] = entry[key]; });
      });
      const fromUrl = emptyContext();
      KEYS.forEach(function (key, index) { fromUrl[key] = urlIds[index] ? (known[key + ':' + urlIds[index]] || { id: urlIds[index], code: '' }) : null; });
      candidates.push(clone(fromUrl));                                   // (a)
    }
    if (complete(state.current)) candidates.push(clone(state.current));  // (b)
    for (let i = 0; i < candidates.length; i += 1) {
      if (token !== generation) return;
      if (await applyChain(candidates[i], detail, token)) return;
      if (token !== generation) return;
      clearSelection(detail);                                            // drop a half-applied attempt
    }
    // (e) nothing applied: the picker stays empty.
  }

  document.addEventListener('csms:curriculum-context-cascade-ready', function (event) {
    const detail = event.detail || {};
    if (!Array.isArray(detail.levels) || !Array.isArray(detail.selectors) || detail.levels.length < KEYS.length) return;
    // Cascades that are not the Program / Curriculum / Version context (for example inside a dialog) are not shared.
    if (!detail.levels[0].input || !detail.levels[0].input.closest || !detail.levels[0].input.closest('.mapping-context-bar, .pr-selectors, .grade-weighting-context-card')) return;
    cascadePage = true;
    active = detail;
    resolve(detail);
  });

  // ---- for the picker -----------------------------------------------------------------------------------------
  window.CSMSWorkingContext = {
    parts: parts,
    current: function () { return clone(state.current); },
    recent: function () { return clone(state.recent); },
    // Apply a context (a Recent entry) through the page's cascade. false if any part is gone or not permitted.
    apply: async function (context) {
      if (!active) return false;
      const token = ++generation;
      const ok = await applyChain(clone(context), active, token);
      if (!ok && token === generation) clearSelection(active);
      if (ok) serverSave(state.current);
      return ok;
    },
    forget: function (versionId) {
      state.recent = state.recent.filter(function (entry) { return entry.version.id !== String(versionId); });
      persist();
    }
  };
}());
