<?php
// CHED/DepEd Compliance Validator: one page. Results | Rule library | Sources | History are views of the same page (?view=).
// Rules and sources are data entered by people; nothing here is a default and nothing says a curriculum: the page shows
// what the stored curriculum data does or does not show against each rule, as counts and per-rule statuses only.
// The old addresses (validate_curriculum.php, compliance_rules.php, compliance_references.php, validation_history.php) redirect here.
require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('compliance.view');

$views = ['results' => 'Results', 'rules' => 'Rule library', 'sources' => 'Sources', 'history' => 'History'];
$view = (string)($_GET['view'] ?? 'results');
if (!isset($views[$view])) $view = 'results';
$userId = current_user_id();
$canRun = $userId !== null && user_can($userId, 'compliance.run');
$canManage = $userId !== null && user_can($userId, 'compliance.manage');
$canVerify = $userId !== null && user_can($userId, 'compliance.reference.verify');
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$v = static fn(string $file): int => (int)filemtime(__DIR__ . '/' . $file);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../../../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= $e(csrf_token()) ?>"/>
  <title>CHED/DepEd Compliance Validator - BCP Student Management System</title>
  <link rel="stylesheet" href="../../../css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/curriculum.css?v=<?= $v('../assets/curriculum.css') ?>"/>
  <link rel="stylesheet" href="../assets/subject-mapping.css?v=<?= $v('../assets/subject-mapping.css') ?>"/>
  <link rel="stylesheet" href="../../../css/csms-typography.css?v=<?= $v('../../../css/csms-typography.css') ?>"/>
  <link rel="stylesheet" href="../assets/subject-mapping-final.css?v=<?= $v('../assets/subject-mapping-final.css') ?>"/>
  <link rel="stylesheet" href="../assets/compliance-validator.css?v=<?= $v('../assets/compliance-validator.css') ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../../../';
$ACTIVE_NAV = 'compliance-validator';
require __DIR__ . '/../../../shared/sidebar.php';
?>
<div class="main">
  <?php require __DIR__ . '/../../../shared/topbar.php'; ?>
  <main class="content curriculum-content mapping-content compliance-content" id="complianceWorkspace"
        data-compliance-api="../http/compliance_actions.php" data-view="<?= $e($view) ?>"
        data-can-run="<?= $canRun ? '1' : '0' ?>" data-can-manage="<?= $canManage ? '1' : '0' ?>" data-can-verify="<?= $canVerify ? '1' : '0' ?>">
    <div class="mapping-workspace-header">
      <div>
        <h1 class="page-title">CHED/DepEd Compliance Validator <span class="csms-info"><button type="button" class="csms-info__btn" aria-label="About the Compliance Validator" aria-describedby="complianceInfoTip"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button><span class="csms-info__tip" role="tooltip" id="complianceInfoTip">Rules come from the sources listed on each rule. Results show what the stored curriculum data does or does not show. They are not an official CHED, DepEd or institutional determination.</span></span></h1>
        <p class="page-subtitle">Check a curriculum version against the rules and sources recorded in this system.</p>
      </div>
      <?php if ($canManage): ?><button type="button" class="primary-action-button" id="ruleAdd" hidden><i class="fa-solid fa-plus" aria-hidden="true"></i> Add rule</button>
      <button type="button" class="primary-action-button" id="sourceAdd" hidden><i class="fa-solid fa-plus" aria-hidden="true"></i> Add source</button><?php endif; ?>
      <?php if (!$canRun && !$canManage): ?><span class="csms-chip csms-chip--draft">Read-only for your role</span><?php endif; ?>
    </div>

    <section class="mapping-workspace-shell">
      <nav class="csms-tabs" id="complianceViews" role="tablist" aria-label="Views of the Compliance Validator">
        <?php foreach ($views as $key => $label): ?><button type="button" data-view="<?= $e($key) ?>" role="tab" aria-selected="<?= $key === $view ? 'true' : 'false' ?>"<?= $key === $view ? '' : ' tabindex="-1"' ?>><?= $e($label) ?></button><?php endforeach; ?>
      </nav>
      <section class="mapping-context-bar" id="complianceContextBar" aria-label="Curriculum context">
        <?php foreach (['program' => 'Program / Strand', 'curriculum' => 'Curriculum', 'version' => 'Curriculum Version'] as $key => $label): ?>
        <div class="mapping-context-field"><label for="<?= $key ?>Search"><?= $label ?></label><div class="record-autocomplete">
          <input type="search" id="<?= $key ?>Search" placeholder="<?= $key === 'program' ? 'Search program code or name' : ($key === 'curriculum' ? 'Choose program first' : 'Choose curriculum first') ?>" <?= $key !== 'program' ? 'disabled' : '' ?> autocomplete="off"/>
          <input type="hidden" id="<?= $key ?>Id"/>
          <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear <?= $label ?>" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
          <div class="record-autocomplete-options" id="<?= $key ?>Options" role="listbox" aria-label="<?= $label ?> suggestions" hidden></div>
        </div></div>
        <?php if ($key !== 'version'): ?><span class="mapping-context-separator" aria-hidden="true"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></span><?php endif; ?>
        <?php endforeach; ?>
      </section>

      <section class="mapping-workspace-panel csms-panel" aria-label="Compliance Validator">
        <!-- Results -->
        <div id="viewResults" data-panel="results" hidden>
          <div class="csms-empty" id="resultsLoading" role="status"><i class="fa-solid fa-spinner" aria-hidden="true"></i><strong>Loading your working context…</strong></div>
          <div class="csms-empty" id="resultsNoVersion" role="status" hidden><i class="fa-solid fa-bullseye" aria-hidden="true"></i><strong>No curriculum version selected</strong><span>Use the context bar above to pick a curriculum version.</span></div>
          <div id="resultsPanel" hidden>
            <div class="csms-filter-row">
              <?php if ($canRun): ?><button type="button" class="primary-action-button" id="runValidation" disabled><i class="fa-solid fa-play" aria-hidden="true"></i> Run validation</button><?php endif; ?>
              <?php if ($canManage): ?><label class="compliance-check"><input type="checkbox" id="runIncludeDemo"/> Include demo rules</label><?php endif; ?>
              <span class="csms-filter-row__summary" id="resultsCaption" role="status"></span>
            </div>
            <div class="csms-empty" id="resultsBeforeRun" role="status" hidden><i class="fa-solid fa-list-check" aria-hidden="true"></i><strong>Run validation to see results for this version</strong><span>Results appear here and are stored in History.</span></div>
            <div class="csms-empty" id="resultsNoRules" role="status" hidden><i class="fa-solid fa-folder-open" aria-hidden="true"></i><strong>No rules apply to this program</strong><span>No CHED/DepEd source for it is recorded in this system yet.</span></div>
            <div id="resultsBody" hidden>
              <div class="compliance-counts" id="resultsCounts" aria-label="Counts"></div>
              <div class="csms-page-table table-scroll" tabindex="0" aria-label="Scrollable results">
                <table class="csms-ctable"><thead><tr><th>Rule</th><th>Source</th><th>Requirement</th><th>Actual</th><th>Result</th><th>Explanation</th></tr></thead><tbody id="resultsRows"></tbody></table>
              </div>
            </div>
          </div>
        </div>

        <!-- Rule library -->
        <div id="viewRules" data-panel="rules" hidden>
          <div class="csms-filter-row">
            <label class="csms-search"><span class="sr-only">Search rules</span><input type="search" id="ruleSearch" maxlength="100" placeholder="Search rule or source"/></label>
            <select class="csms-select" id="ruleType" aria-label="Rule type"><option value="">All types</option><option value="MIN_TOTAL_UNITS">Minimum total units</option><option value="MAX_TOTAL_UNITS">Maximum total units</option><option value="REQUIRED_SUBJECT">Required subject</option><option value="MIN_SUBJECT_UNITS">Minimum subject units</option><option value="MAX_SUBJECT_UNITS">Maximum subject units</option><option value="COMPONENT_UNITS">Units by component</option><option value="GE_UNITS">General education units</option><option value="PE_UNITS">Physical education</option><option value="NSTP_REQUIREMENT">NSTP requirement</option><option value="NSTP_PREREQUISITE">NSTP prerequisite</option><option value="NSTP_COMPONENTS">NSTP components</option><option value="SHS_HOURS">Senior High hours</option><option value="GRADING_WEIGHTS">Grading weights</option></select>
            <select class="csms-select" id="ruleOrigin" aria-label="Origin"><option value="">All origins</option><option value="library">Library</option><option value="custom">Custom</option></select>
            <select class="csms-select" id="ruleApplies" aria-label="Applies to"><option value="">Applies to: any</option><option value="all_college">All college programs</option><option value="senior_high">Senior High School</option><option value="program">One program or curriculum</option></select>
            <select class="csms-select" id="ruleStatus" aria-label="Rule status"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
            <label class="compliance-check"><input type="checkbox" id="ruleDemo"/> Include demo rules</label>
            <select class="csms-select" id="rulePerPage" aria-label="Rows per page"><option value="10">10 rows</option><option value="20" selected>20 rows</option><option value="50">50 rows</option><option value="100">100 rows</option></select>
            <span class="csms-filter-row__summary" id="ruleCount" role="status">0 records</span>
          </div>
          <div class="csms-empty" id="ruleEmpty" role="status" hidden><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i><strong>No rules recorded yet</strong><span>A rule is checked against a version only when its source has been confirmed.</span></div>
          <div class="csms-empty" id="ruleNoMatch" role="status" hidden><i class="fa-solid fa-filter" aria-hidden="true"></i><strong>No rules match these filters</strong><span>Change the search or a filter.</span></div>
          <div class="csms-empty" id="ruleError" role="alert" hidden><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><strong>Rules could not be loaded</strong><span id="ruleErrorText"></span></div>
          <div class="csms-page-table table-scroll" id="ruleWrap" tabindex="0" aria-label="Scrollable rule library" hidden>
            <table class="csms-ctable"><thead><tr><th>Rule</th><th>Origin</th><th>Source class</th><th>Source</th><th>Requirement</th><th>Applies to</th><th>Status</th></tr></thead><tbody id="ruleRows"></tbody></table>
          </div>
          <div class="pagination-bar" id="rulePagination"></div>
        </div>

        <!-- Sources -->
        <div id="viewSources" data-panel="sources" hidden>
          <div class="csms-filter-row">
            <label class="csms-search"><span class="sr-only">Search sources</span><input type="search" id="sourceSearch" maxlength="100" placeholder="Search code, title or issuing body"/></label>
            <select class="csms-select" id="sourceType" aria-label="Source category"><option value="">All categories</option><option value="CHED">CHED</option><option value="DEPED">DepEd</option><option value="INSTITUTIONAL">Institutional</option><option value="OTHER">Other</option></select>
            <select class="csms-select" id="sourceStatus" aria-label="Source status"><option value="">All statuses</option><option value="draft">Draft</option><option value="verified">Source confirmed</option><option value="inactive">Inactive</option></select>
            <label class="compliance-check"><input type="checkbox" id="sourceDemo"/> Include demo sources</label>
            <select class="csms-select" id="sourcePerPage" aria-label="Rows per page"><option value="10">10 rows</option><option value="20" selected>20 rows</option><option value="50">50 rows</option><option value="100">100 rows</option></select>
            <span class="csms-filter-row__summary" id="sourceCount" role="status">0 records</span>
          </div>
          <div class="csms-empty" id="sourceEmpty" role="status" hidden><i class="fa-solid fa-book" aria-hidden="true"></i><strong>No sources recorded yet</strong><span>Record a CHED or DepEd document here before adding rules that rely on it.</span></div>
          <div class="csms-empty" id="sourceNoMatch" role="status" hidden><i class="fa-solid fa-filter" aria-hidden="true"></i><strong>No sources match these filters</strong><span>Change the search or a filter.</span></div>
          <div class="csms-empty" id="sourceError" role="alert" hidden><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><strong>Sources could not be loaded</strong><span id="sourceErrorText"></span></div>
          <div class="csms-page-table table-scroll" id="sourceWrap" tabindex="0" aria-label="Scrollable sources" hidden>
            <table class="csms-ctable"><thead><tr><th>Source</th><th>Issuing body</th><th>Year</th><th>Kind</th><th>Source status</th><th class="csms-ctable__num">Rules</th></tr></thead><tbody id="sourceRows"></tbody></table>
          </div>
          <div class="pagination-bar" id="sourcePagination"></div>
        </div>

        <!-- History -->
        <div id="viewHistory" data-panel="history" hidden>
          <div class="csms-filter-row">
            <label class="csms-search"><span class="sr-only">Search validation history</span><input type="search" id="historySearch" maxlength="100" placeholder="Search program, curriculum or version"/></label>
            <label class="compliance-check"><input type="checkbox" id="historyOnlyVersion"/> Only the selected version</label>
            <label class="compliance-check"><input type="checkbox" id="historyDemo"/> Include demo runs</label>
            <select class="csms-select" id="historyPerPage" aria-label="Rows per page"><option value="10">10 rows</option><option value="20" selected>20 rows</option><option value="50">50 rows</option><option value="100">100 rows</option></select>
            <span class="csms-filter-row__summary" id="historyCount" role="status">0 records</span>
          </div>
          <div class="csms-empty" id="historyEmpty" role="status" hidden><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i><strong>No validation runs recorded yet</strong><span>Runs are stored when a version is validated.</span></div>
          <div class="csms-empty" id="historyNoMatch" role="status" hidden><i class="fa-solid fa-filter" aria-hidden="true"></i><strong>No runs match these filters</strong><span>Change the search or a filter.</span></div>
          <div class="csms-empty" id="historyError" role="alert" hidden><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><strong>History could not be loaded</strong><span id="historyErrorText"></span></div>
          <div class="csms-page-table table-scroll" id="historyWrap" tabindex="0" aria-label="Scrollable validation history" hidden>
            <table class="csms-ctable"><thead><tr><th>Run date</th><th>Program</th><th>Curriculum</th><th>Version</th><th class="csms-ctable__num">Rules</th><th class="csms-ctable__num">Met</th><th class="csms-ctable__num">Not met</th><th class="csms-ctable__num">Cannot determine</th><th>Run by</th></tr></thead><tbody id="historyRows"></tbody></table>
          </div>
          <div class="pagination-bar" id="historyPagination"></div>
        </div>
      </section>
    </section>
  </main>
  <div class="footer">Bestlink College of the Philippines &middot; CSMS-SMS2 &copy; 2026</div>
</div>

<div class="curriculum-modal" id="complianceDialog" hidden>
  <div class="modal-backdrop" id="complianceBackdrop"></div>
  <section class="modal-dialog compliance-dialog" role="dialog" aria-modal="true" aria-labelledby="complianceDialogTitle">
    <div class="modal-header"><div><h2 id="complianceDialogTitle">Details</h2><p class="modal-context" id="complianceDialogContext"></p></div><button type="button" class="modal-close-button" id="complianceDialogX" aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <form id="complianceForm" novalidate>
      <div class="confirmation-body" id="complianceDialogBody"></div>
      <div class="modal-actions modal-actions-padded" id="complianceDialogFooter"></div>
    </form>
  </section>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $v('../assets/curriculum-common.js') ?>"></script>
<script src="../assets/compliance-validator.js?v=<?= $v('../assets/compliance-validator.js') ?>"></script>
</body>
</html>
