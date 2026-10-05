(function () {
  'use strict';
  const userId = document.currentScript?.dataset.userId || 'guest';
  const storageKey = 'csms.curriculum-context.' + userId;
  let restoring = false;

  try {
    const navigation = performance.getEntriesByType('navigation')[0];
    if (navigation?.type === 'reload') sessionStorage.removeItem(storageKey);
  } catch (_) {}

  function read() {
    try { return JSON.parse(sessionStorage.getItem(storageKey) || '{}'); }
    catch (_) { return {}; }
  }
  function write(context) {
    try { sessionStorage.setItem(storageKey, JSON.stringify(context)); }
    catch (_) {}
  }
  function descriptor(item, index) {
    if (!item) return null;
    if ('curriculum_code' in item) return { key: 'curriculum', query: item.curriculum_code };
    if ('program_code' in item) return { key: 'program', query: item.program_code };
    if ('version_label' in item) return { key: 'version', query: item.version_label };
    return ['program', 'curriculum', 'version'][index] ? { key: ['program', 'curriculum', 'version'][index], query: String(item.id || '') } : null;
  }
  document.addEventListener('csms:curriculum-context-selection', function (event) {
    if (restoring) return;
    const detail = event.detail || {};
    const keys = ['program', 'curriculum', 'version'];
    const context = read();
    const found = descriptor(detail.item, detail.index);
    const key = found?.key || keys[detail.index];
    if (!key) return;
    if (detail.action === 'clear' || !detail.item) {
      keys.slice(keys.indexOf(key)).forEach(name => delete context[name]);
    } else {
      const id = String(detail.item.id || '');
      context[key] = { id, query: found.query };
      keys.slice(keys.indexOf(key) + 1).forEach(name => delete context[name]);
    }
    write(context);
  });

  document.addEventListener('csms:curriculum-context-cascade-ready', async function (event) {
    const context = read();
    const { levels, selectors } = event.detail || {};
    if (!Array.isArray(levels) || !Array.isArray(selectors)) return;
    restoring = true;
    try {
      for (let index = 0; index < Math.min(3, levels.length); index += 1) {
        const key = ['program', 'curriculum', 'version'][index];
        const saved = context[key];
        if (!saved?.id || !selectors[index]) break;
        const candidates = await levels[index].search(String(saved.query || ''));
        const item = (Array.isArray(candidates) ? candidates : []).find(record => String((levels[index].getId ? levels[index].getId(record) : record.id)) === String(saved.id));
        if (!item) break;
        selectors[index].setSelected(item);
      }
    } catch (_) {
      // The current page remains usable if saved context is stale or inaccessible.
    } finally {
      restoring = false;
    }
  });
}());
