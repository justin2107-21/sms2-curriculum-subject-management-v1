<?php
// Compact Program / Curriculum / Version context bar (UI_Decisions D1-D4).
// Server-rendered; works without JavaScript as a GET form, so the selection lives
// in the URL (D4). The cascading behaviour (narrowing, auto-open, auto-pick,
// session memory) is progressive enhancement added with the pilot page; it hooks
// onto the data-* attributes below.
//
// $fields: ordered list of
//   ['name' => 'program', 'label' => 'Program', 'options' => [[value, label], ...], 'selected' => 'BSIT']
// Placed directly under the page title (never in the header). Collapses to one line
// "A / B / C  Change" when every field has a selection (D3).
require_once __DIR__ . '/profile.php';

if (!function_exists('ui_context_bar')) {
    function ui_context_bar(array $fields, string $action = '', array $opts = []): void
    {
        $complete = $fields !== [];
        $summary = [];
        foreach ($fields as $f) {
            $sel = (string)($f['selected'] ?? '');
            if ($sel === '') { $complete = false; continue; }
            $label = $sel;
            foreach ($f['options'] ?? [] as [$value, $text]) {
                if ((string)$value === $sel) { $label = (string)$text; break; }
            }
            $summary[] = $label;
        }
        $id = $opts['id'] ?? 'csmsContextBar';
        ?>
<form class="csms-contextbar<?= $complete ? ' is-collapsed' : '' ?>" id="<?= ui_e($id) ?>" method="get"
      action="<?= ui_e($action) ?>" role="search" aria-label="Curriculum context" data-context-bar>
  <?php foreach ($fields as $i => $f): $fid = $id . '_' . $f['name']; ?>
  <div class="csms-contextbar__field">
    <label for="<?= ui_e($fid) ?>"><?= ui_e($f['label']) ?></label>
    <select class="form-select form-select-sm" id="<?= ui_e($fid) ?>" name="<?= ui_e($f['name']) ?>"
            data-context-field="<?= ui_e($f['name']) ?>" data-context-index="<?= (int)$i ?>">
      <option value="">Select <?= ui_e(strtolower($f['label'])) ?></option>
      <?php foreach ($f['options'] ?? [] as [$value, $text]): ?>
      <option value="<?= ui_e($value) ?>"<?= (string)$value === (string)($f['selected'] ?? '') ? ' selected' : '' ?>><?= ui_e($text) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endforeach; ?>
  <noscript><button type="submit" class="btn btn-primary btn-sm">Apply</button></noscript>
  <span class="csms-contextbar__summary" aria-live="polite"><?php
      echo implode('<span class="csms-contextbar__summary-sep">/</span>', array_map('ui_e', $summary));
  ?></span>
  <a class="csms-contextbar__reset btn btn-link btn-sm p-0" href="<?= ui_e($action !== '' ? $action : '?') ?>" data-context-reset>Reset</a>
  <button type="button" class="csms-contextbar__change btn btn-link btn-sm p-0" data-context-change>Change</button>
</form>
        <?php
    }
}
