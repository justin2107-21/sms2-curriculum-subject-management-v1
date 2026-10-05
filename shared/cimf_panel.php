<?php
require_once __DIR__ . '/../modules/curriculum/services/CIMFProfileRegistry.php';
$cimfScriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$cimfRoute = null;
foreach (array_keys(CIMFProfileRegistry::routes()) as $candidate) {
    if (str_ends_with($cimfScriptPath, '/' . $candidate)) { $cimfRoute = $candidate; break; }
}
$cimfProfile = $cimfRoute ? CIMFProfileRegistry::forRoute($cimfRoute) : null;
if (!$cimfProfile || !current_user_id() || !user_can(current_user_id(), $cimfProfile['route_permission'])
    || !user_can(current_user_id(), $cimfProfile['permission'])) return;
$cimfRoot = $APP_ROOT ?? '../';
$cimfEscape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$cimfVersion = (string)filemtime(__DIR__ . '/../js/cimf-panel.js');
?>
<?php if (!str_contains($cimfRoute, 'modules/curriculum/')): ?>
<link rel="stylesheet" href="<?= $cimfEscape($cimfRoot) ?>modules/curriculum/assets/curriculum.css">
<?php endif; ?>
<link rel="stylesheet" href="<?= $cimfEscape($cimfRoot) ?>css/cimf-panel.css?v=<?= (int)filemtime(__DIR__ . '/../css/cimf-panel.css') ?>">
<button type="button" id="csmsCimfLauncher" aria-label="Open CIMF Assistant" title="CIMF Assistant" aria-controls="csmsCimfPanel" aria-expanded="false"><img src="<?= $cimfEscape($cimfRoot) ?>images/CIMF%20ICON%20BUTTON.png" alt="" aria-hidden="true" width="42" height="42"></button>
<aside id="csmsCimfPanel" aria-labelledby="csmsCimfTitle" hidden
    data-profile="<?= $cimfEscape($cimfProfile['id']) ?>" data-route="<?= $cimfEscape($cimfRoute) ?>"
    data-page-token="<?= $cimfEscape(CIMFProfileRegistry::issueToken($cimfRoute)) ?>"
    data-title="<?= $cimfEscape($cimfProfile['title']) ?>"
    data-actions="<?= $cimfEscape(json_encode($cimfProfile['actions'])) ?>"
    data-fields="<?= $cimfEscape(json_encode($cimfProfile['fields'])) ?>"
    data-api="<?= $cimfEscape($cimfRoot) ?>modules/curriculum/http/global_cimf_actions.php"
    data-csrf="<?= $cimfEscape(csrf_token()) ?>" data-signin="<?= $cimfEscape($cimfRoot) ?>auth/signin.php?reason=session_timeout">
  <div id="csmsCimfResize" role="separator" aria-label="Resize CIMF Assistant" aria-orientation="vertical" tabindex="0" aria-valuemin="32" aria-valuemax="800" aria-valuenow="420"></div>
  <button type="button" id="csmsCimfPeek" class="button-muted" aria-label="Expand CIMF Assistant" hidden><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i></button>
  <div class="cimf-panel-surface">
    <header class="cimf-panel-header"><div><h2 id="csmsCimfTitle"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> CIMF Assistant</h2><p><?= $cimfEscape($cimfProfile['title']) ?></p></div><div class="card-header-actions"><button type="button" id="csmsCimfCollapse" class="button-muted" aria-label="Collapse CIMF to a narrow strip"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button><button type="button" id="csmsCimfClose" class="button-muted" aria-label="Close CIMF Assistant"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div></header>
    <div class="cimf-panel-body">
      <section class="domain-card cimf-context-card"><p class="eyebrow">Current Context</p><strong id="csmsCimfContext"></strong><p class="subtle-text">Current module: <?= $cimfEscape($cimfProfile['title']) ?></p></section>
      <section id="csmsCimfStoredDecision" class="domain-card cimf-context-card" hidden><p class="eyebrow">Stored Human Decision</p><strong id="csmsCimfStoredDecisionText"></strong></section>
      <p id="csmsCimfStatus" class="subtle-text" role="status" aria-live="polite"></p>
      <button type="button" id="csmsCimfBack" class="button-muted" hidden><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back</button>
      <section id="csmsCimfHome"><p class="subtle-text">Choose a review action. CIMF explains available stored records and does not change academic data.</p><div id="csmsCimfActions"></div></section>
      <form id="csmsCimfAsk" hidden><label class="form-field"><span>Ask CIMF about this page</span><textarea id="csmsCimfQuestion" rows="4" maxlength="600" required placeholder="Ask about the current module and selected stored records"></textarea></label><button type="submit" class="button-primary">Ask CIMF</button></form>
      <form id="csmsCimfCompare" hidden><div id="csmsCimfSubjectPickers"></div><button type="submit" class="button-primary" id="csmsCimfCompareSubmit" disabled>Compare Subjects</button></form>
      <section id="csmsCimfResult" hidden aria-live="polite"></section>
    </div>
    <footer class="cimf-panel-footer subtle-text">AI suggestion · Human review required · No records are changed automatically<br>CIMF is limited to the current module.</footer>
  </div>
</aside>
<script src="<?= $cimfEscape($cimfRoot) ?>js/cimf-panel.js?v=<?= $cimfVersion ?>" defer></script>
