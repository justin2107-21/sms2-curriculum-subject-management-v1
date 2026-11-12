<?php
// Grade Weighting Setup: one page under the shared Program / Curriculum / Version bar. The main view is a server-side paged table of the
// version's subjects; one dialog edits (or shows, read-only) a subject's assessment components. Weights are institution-configured:
// no CHED or DepEd weights are applied automatically and nothing here is a default.
require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('grade_weighting.view');

$userId = current_user_id();
$canManage = $userId !== null && user_can($userId, 'grade_weighting.manage');
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
  <title>Grade Weighting Setup - BCP Student Management System</title>
  <link rel="stylesheet" href="../../../css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/curriculum.css?v=<?= $v('../assets/curriculum.css') ?>"/>
  <link rel="stylesheet" href="../assets/subject-mapping.css?v=<?= $v('../assets/subject-mapping.css') ?>"/>
  <link rel="stylesheet" href="../../../css/csms-typography.css?v=<?= $v('../../../css/csms-typography.css') ?>"/>
  <link rel="stylesheet" href="../assets/subject-mapping-final.css?v=<?= $v('../assets/subject-mapping-final.css') ?>"/>
  <link rel="stylesheet" href="../assets/grade-weighting.css?v=<?= $v('../assets/grade-weighting.css') ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../../../';
$ACTIVE_NAV = 'grade-weighting';
require __DIR__ . '/../../../shared/sidebar.php';
?>
<div class="main">
  <?php require __DIR__ . '/../../../shared/topbar.php'; ?>
  <main class="content curriculum-content mapping-content grade-weighting-content" id="gradeWeightingWorkspace"
        data-weighting-api="../http/grade_weighting_actions.php" data-can-manage="<?= $canManage ? '1' : '0' ?>">
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <h1 class="page-title"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i> Grade Weighting Setup <span class="csms-info"><button type="button" class="csms-info__btn" aria-label="About grade weighting" aria-describedby="weightingInfoTip"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button><span class="csms-info__tip" role="tooltip" id="weightingInfoTip">Weights are configured by the institution. No CHED or DepEd weights are applied automatically. Totals must equal 100%.</span></span></h1>
        <p class="page-subtitle">Configure assessment component weights per subject in a curriculum version.</p>
      </div>
      <div class="card-header-actions">
        <?php if (!$canManage): ?><span class="csms-chip csms-chip--draft">Read-only for your role</span><?php endif; ?>
      </div>
    </div>

    <section class="mapping-workspace-shell">
      <section class="mapping-context-bar" aria-label="Curriculum context">
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

      <section class="mapping-workspace-panel csms-panel" aria-label="Subjects of the selected curriculum version">
        <div class="csms-empty" id="weightingLoading" role="status"><i class="fa-solid fa-spinner" aria-hidden="true"></i><strong>Loading your working context…</strong></div>
        <div class="csms-empty" id="weightingNoPrograms" role="status" hidden><i class="fa-solid fa-folder-open" aria-hidden="true"></i><strong>No programs exist yet</strong><span>Add a Program / Strand in Academic Programs first.</span></div>
        <div class="csms-empty" id="weightingEmpty" role="status" hidden><i class="fa-solid fa-bullseye" aria-hidden="true"></i><strong>No curriculum version selected</strong><span>Use the context bar above to pick a curriculum version.</span></div>
        <div id="weightingPanel" hidden>
          <p class="weighting-notice" id="weightingVersionNotice" role="status" hidden></p>
          <div class="csms-filter-row">
            <label class="csms-search"><span class="sr-only">Search subject code or title</span><input type="search" id="weightingSearch" maxlength="100" placeholder="Search subject code or title"/></label>
            <select class="csms-select" id="weightingStatus" aria-label="Status"><option value="">All statuses</option><option value="configured">Configured</option><option value="not_configured">Not configured</option><option value="incomplete">Incomplete</option></select>
            <select class="csms-select" id="weightingYear" aria-label="Year level"><option value="">All year levels</option></select>
            <select class="csms-select" id="weightingSemester" aria-label="Semester"><option value="">All semesters</option></select>
            <select class="csms-select" id="weightingPerPage" aria-label="Rows per page"><option value="10">10 rows</option><option value="20" selected>20 rows</option><option value="50">50 rows</option><option value="100">100 rows</option></select>
            <span class="csms-filter-row__summary" id="weightingCount" role="status">0 records</span>
          </div>
          <div class="csms-filter-row weighting-chips" id="weightingChips" aria-label="Active filters" hidden></div>
          <div class="csms-empty" id="weightingNoMatch" role="status" hidden><i class="fa-solid fa-filter" aria-hidden="true"></i><strong>No subjects match these filters</strong><span>Change or remove a filter.</span><button type="button" class="button-muted" id="weightingClearFilters">Clear filters</button></div>
          <div class="csms-empty" id="weightingNoSubjects" role="status" hidden><i class="fa-solid fa-book-open" aria-hidden="true"></i><strong>No subjects in this version</strong><span>Place subjects in this version in Curriculum Builder first.</span></div>
          <div class="csms-empty" id="weightingError" role="alert" hidden><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><strong>Subjects could not be loaded</strong><span id="weightingErrorText"></span><button type="button" class="button-muted" id="weightingRetry">Try again</button></div>
          <div class="csms-page-table table-scroll" id="weightingTableWrap" tabindex="0" aria-label="Scrollable subjects with their grade weighting">
            <table class="csms-ctable">
              <thead><tr>
                <th aria-sort="none"><button type="button" class="weighting-sort" data-sort="code">Code</button></th>
                <th aria-sort="none"><button type="button" class="weighting-sort" data-sort="title">Title</button></th>
                <th aria-sort="ascending"><button type="button" class="weighting-sort" data-sort="term">Year &amp; Semester</button></th>
                <th class="csms-ctable__num" aria-sort="none"><button type="button" class="weighting-sort" data-sort="components">Components</button></th>
                <th class="csms-ctable__num" aria-sort="none"><button type="button" class="weighting-sort" data-sort="total">Total %</button></th>
                <th aria-sort="none"><button type="button" class="weighting-sort" data-sort="status">Status</button></th>
                <th class="csms-ctable__action"><span class="sr-only">Actions</span></th></tr></thead>
              <tbody id="weightingRows"></tbody>
            </table>
          </div>
          <div class="pagination-bar" id="weightingPagination"></div>
        </div>
        <div id="weightingRowMenu" class="csms-menu" role="menu" aria-label="Row actions" hidden></div>
      </section>
    </section>
  </main>
  <div class="footer">Bestlink College of the Philippines &middot; CSMS-SMS2 &copy; 2026</div>
</div>

<div class="curriculum-modal" id="weightingDialog" hidden>
  <div class="modal-backdrop" id="weightingBackdrop"></div>
  <section class="modal-dialog weighting-dialog" role="dialog" aria-modal="true" aria-labelledby="weightingDialogTitle">
    <div class="modal-header"><div><h2 id="weightingDialogTitle">Grade weighting</h2><p class="modal-context" id="weightingDialogContext"></p></div><button type="button" class="modal-close-button" id="weightingX" aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="confirmation-body">
      <p class="weighting-notice" id="weightingDialogNotice" role="status" hidden></p>
      <div class="csms-empty" id="weightingDialogLoading" role="status"><i class="fa-solid fa-spinner" aria-hidden="true"></i><strong>Loading the configuration…</strong></div>
      <div id="weightingEditor" hidden>
        <div class="csms-page-table table-scroll weighting-components" tabindex="0" aria-label="Assessment components">
          <table class="csms-ctable">
            <thead><tr><th>Component</th><th class="csms-ctable__num">Weight (%)</th><th class="csms-ctable__action"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody id="weightingComponentRows"></tbody>
          </table>
        </div>
        <?php if ($canManage): ?><p class="weighting-add"><button type="button" class="button-muted" id="weightingAddComponent"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add component</button></p><?php endif; ?>
        <div class="weighting-total" aria-live="polite">
          <span class="weighting-total__text" id="weightingTotalText">0.00% total</span>
          <div class="weighting-track" role="progressbar" aria-label="Total configured weight" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span id="weightingProgress"></span></div>
          <span class="csms-chip csms-chip--draft" id="weightingTotalChip">Incomplete</span>
          <span class="csms-chip csms-chip--type" id="weightingUnsaved" hidden>Unsaved changes</span>
        </div>
        <p class="weighting-total-message" id="weightingTotalMessage" role="status"></p>
        <div class="form-error" id="weightingFormError" role="alert" hidden></div>
      </div>
      <div class="weighting-confirm" id="weightingRemoveConfirm" role="alertdialog" aria-labelledby="weightingRemoveText" hidden>
        <p id="weightingRemoveText"></p>
        <div><button type="button" class="button-muted" id="weightingRemoveCancel">Keep it</button> <button type="button" class="button-danger" id="weightingRemoveConfirmButton">Remove configuration</button></div>
      </div>
    </div>
    <div class="modal-actions modal-actions-padded">
      <button type="button" class="button-muted" id="weightingClose">Close</button>
      <button type="button" class="button-muted" id="weightingCimf" disabled><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Review with CIMF</button>
      <?php if ($canManage): ?>
      <button type="button" class="button-muted" id="weightingRemove" hidden><i class="fa-solid fa-trash" aria-hidden="true"></i> Remove configuration</button>
      <button type="button" class="primary-action-button" id="weightingSave" hidden disabled>Save changes</button>
      <?php endif; ?>
    </div>
  </section>
</div>

<div class="curriculum-modal" id="discardWeightingModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-small" role="alertdialog" aria-modal="true" aria-labelledby="discardWeightingTitle">
    <div class="modal-header"><div><h2 id="discardWeightingTitle">Discard unsaved changes?</h2></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="confirmation-body"><p>Your changes to these weights have not been saved.</p></div>
    <div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Keep editing</button><button type="button" class="button-danger" id="confirmDiscardWeighting">Discard changes</button></div>
  </section>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $v('../assets/curriculum-common.js') ?>"></script>
<script src="../../../js/csms-menu.js?v=<?= $v('../../../js/csms-menu.js') ?>"></script>
<script src="../assets/grade-weighting.js?v=<?= $v('../assets/grade-weighting.js') ?>"></script>
</body>
</html>
