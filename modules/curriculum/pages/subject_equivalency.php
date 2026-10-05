<?php
$subjectEquivalencyView = $subjectEquivalencyView ?? null;

if ($subjectEquivalencyView === null) {
    $legacyQuery = $_GET;
    $legacyView = strtolower(trim((string)($legacyQuery['view'] ?? $legacyQuery['equivalency_view'] ?? 'comparison')));
    unset($legacyQuery['view'], $legacyQuery['equivalency_view']);
    $legacyTarget = $legacyView === 'records'
        ? 'equivalency_records.php'
        : 'subject_comparison.php';
    $legacyQueryString = http_build_query($legacyQuery);
    header('Location: ' . $legacyTarget . ($legacyQueryString !== '' ? '?' . $legacyQueryString : ''), true, 302);
    exit;
}

if (!in_array($subjectEquivalencyView, ['comparison', 'records'], true)) {
    http_response_code(404);
    exit('Page not found.');
}

require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('equivalency.view');

$isComparisonView = $subjectEquivalencyView === 'comparison';
$viewLabel = $isComparisonView ? 'Subject Comparison' : 'Equivalency Records';
$viewDescription = $isComparisonView
    ? 'Compare stored subject information and prepare a human-reviewed equivalency decision.'
    : 'Review and maintain recorded human equivalency decisions.';
$userId = current_user_id();
$canManageEquivalencies = $userId !== null && user_can($userId, 'equivalency.manage');
$recordColumnCount = 6;
$avatarInitial = strtoupper(substr((string)($_SESSION['first_name'] ?? 'U'), 0, 1));
$curriculumCssVersion = (int)filemtime(__DIR__ . '/../assets/curriculum.css');
$typographyCssVersion = (int)filemtime(__DIR__ . '/../../../css/csms-typography.css');
$mappingCssVersion = (int)filemtime(__DIR__ . '/../assets/subject-mapping.css');
$equivalencyCssVersion = (int)filemtime(__DIR__ . '/../assets/subject-equivalency.css');
$curriculumCommonVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-common.js');
$equivalencyScriptVersion = (int)filemtime(__DIR__ . '/../assets/subject-equivalency.js');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../../../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <title><?= htmlspecialchars($viewLabel, ENT_QUOTES, 'UTF-8') ?> - BCP Student Management System</title>
  <link rel="stylesheet" href="../../../css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/curriculum.css?v=<?= $curriculumCssVersion ?>"/>
  <link rel="stylesheet" href="../assets/subject-mapping.css?v=<?= $mappingCssVersion ?>"/>
  <link rel="stylesheet" href="../assets/subject-equivalency.css?v=<?= $equivalencyCssVersion ?>"/>
  <link rel="stylesheet" href="../../../css/csms-typography.css?v=<?= $typographyCssVersion ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../../../';
$ACTIVE_NAV = $isComparisonView ? 'subject-equivalency-comparison' : 'subject-equivalency-records';
require __DIR__ . '/../../../shared/sidebar.php';
?>

<div class="main">
  <?php require __DIR__ . '/../../../shared/topbar.php'; ?>

  <main class="content curriculum-content mapping-content subject-equivalency-content"
        id="subjectEquivalencyWorkspace"
        data-equivalency-api="../http/equivalency_actions.php"
        data-can-manage-equivalency="<?= $canManageEquivalencies ? '1' : '0' ?>"
        data-record-column-count="<?= $recordColumnCount ?>">
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <h1 class="page-title"><i class="fa-solid fa-right-left"></i> <?= htmlspecialchars($viewLabel, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-subtitle"><?= htmlspecialchars($viewDescription, ENT_QUOTES, 'UTF-8') ?></p>
      </div>
      <?php if ($isComparisonView): ?><div class="equivalency-header-actions"><button type="button" class="button-muted" id="reviewWithCimf" disabled><i class="fa-solid fa-sparkles"></i> Review with CIMF</button><?php if ($canManageEquivalencies): ?><button type="button" class="button-primary" id="headerRecordDecision" disabled><i class="fa-solid fa-plus"></i> Record Decision</button><?php endif; ?></div><?php else: ?><span class="read-only-pill"><i class="fa-solid fa-user-check"></i> Human authority required</span><?php endif; ?>
    </div>

    <p class="curriculum-notice equivalency-authority-notice" role="note">
      <i class="fa-solid fa-circle-info"></i>
      <?php if ($isComparisonView): ?>Objective differences support review only. The system never automatically declares subjects equivalent.<?php else: ?>Human-reviewed records only. Subject pairs are treated as non-directional.<?php endif; ?>
    </p>

    <?php if ($isComparisonView): ?>
    <section id="comparisonPanel" class="subject-equivalency-panel">
      <section class="domain-card equivalency-selector-card" aria-labelledby="subjectSelectorTitle">
        <div class="domain-card-header">
          <div><p class="eyebrow">Read-only comparison</p><h2 id="subjectSelectorTitle">Select two Subject Master records</h2></div>
          <button type="button" class="button-muted" id="clearSubjectComparison"><i class="fa-solid fa-rotate-left"></i> Clear Comparison</button>
        </div>
        <div class="equivalency-selector-grid">
          <div class="equivalency-subject-selector">
            <div class="mapping-filter-field">
              <label for="subjectASearch">Subject A <strong>*</strong></label>
              <div class="record-autocomplete">
                <input type="search" id="subjectASearch" maxlength="100" placeholder="Search code or title" required/>
                <input type="hidden" id="subjectASelect"/>
                <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear Subject A" title="Clear selection" hidden><i class="fa-solid fa-xmark"></i></button>
                <div class="record-autocomplete-options" id="subjectAOptions" role="listbox" aria-label="Subject A suggestions" hidden></div>
              </div>
            </div>
          </div>
          <div class="equivalency-subject-selector">
            <div class="mapping-filter-field">
              <label for="subjectBSearch">Subject B <strong>*</strong></label>
              <div class="record-autocomplete">
                <input type="search" id="subjectBSearch" maxlength="100" placeholder="Search code or title" required/>
                <input type="hidden" id="subjectBSelect"/>
                <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear Subject B" title="Clear selection" hidden><i class="fa-solid fa-xmark"></i></button>
                <div class="record-autocomplete-options" id="subjectBOptions" role="listbox" aria-label="Subject B suggestions" hidden></div>
              </div>
            </div>
          </div>
          <div class="equivalency-compare-action">
            <button type="button" class="button-primary" id="compareSubjectsButton" disabled><i class="fa-solid fa-scale-balanced"></i> Compare Subjects</button>
          </div>
        </div>
      </section>

      <section class="equivalency-summary-grid" id="equivalencySummary" hidden aria-label="Compared subject summaries">
        <article class="domain-card equivalency-summary-card"><p class="eyebrow">Subject A</p><h2 id="summaryACode"></h2><p id="summaryATitle"></p><div><span id="summaryAUnits"></span><span id="summaryAStatus"></span></div></article>
        <article class="domain-card equivalency-summary-card"><p class="eyebrow">Subject B</p><h2 id="summaryBCode"></h2><p id="summaryBTitle"></p><div><span id="summaryBUnits"></span><span id="summaryBStatus"></span></div></article>
      </section>
      <section class="domain-card equivalency-comparison-card" aria-labelledby="comparisonResultsTitle">
        <div class="domain-card-header">
          <div><p class="eyebrow">Objective stored fields</p><h2 id="comparisonResultsTitle">Objective Comparison</h2><p class="mapping-context-message">Factual comparison from stored subject and placement records.</p><p class="mapping-context-message" id="comparisonMessage">Select two different subjects to compare their stored information.</p></div>
        </div>
        <div class="equivalency-existing-record" id="existingPairMessage" hidden><span>An equivalency record already exists for this subject pair.</span><div><button type="button" class="row-action-button" id="viewExistingEquivalency">View Record</button><?php if ($canManageEquivalencies): ?><button type="button" class="row-action-button" id="editExistingEquivalency">Edit Decision</button><?php endif; ?></div></div>
        <div class="table-scroll" tabindex="0" aria-label="Scrollable objective subject comparison">
          <table class="domain-table equivalency-comparison-table">
            <thead><tr><th>Stored Field</th><th id="subjectAHeader">Subject A</th><th id="subjectBHeader">Subject B</th><th>Objective Result</th></tr></thead>
            <tbody id="comparisonRows"><tr><td colspan="4" class="empty-cell">No subjects selected for comparison.</td></tr></tbody>
          </table>
        </div>
      </section>

      <section class="equivalency-usage-grid" aria-label="Subject curriculum usage context">
        <article class="domain-card equivalency-usage-card"><div class="domain-card-header"><div><p class="eyebrow">Context only</p><h2 id="subjectAUsageTitle">Subject A Usage</h2></div></div><div id="subjectAUsage" class="equivalency-usage-content"><p class="equivalency-empty-context">Compare subjects to view bounded curriculum usage.</p></div></article>
        <article class="domain-card equivalency-usage-card"><div class="domain-card-header"><div><p class="eyebrow">Context only</p><h2 id="subjectBUsageTitle">Subject B Usage</h2></div></div><div id="subjectBUsage" class="equivalency-usage-content"><p class="equivalency-empty-context">Compare subjects to view bounded curriculum usage.</p></div></article>
      </section>
      <p class="equivalency-usage-disclaimer">Similar usage is supporting context only and is not proof of equivalency.</p>
    </section>

    <?php else: ?>
    <section id="recordsPanel" class="subject-equivalency-panel">
      <section class="domain-card mapping-filter-card" aria-labelledby="equivalencyFiltersTitle">
        <div class="domain-card-header"><div><p class="eyebrow">Explicit human decisions</p><h2 id="equivalencyFiltersTitle">Find equivalency records</h2></div><button type="button" class="button-muted" id="clearEquivalencyFilters"><i class="fa-solid fa-rotate-left"></i> Clear Filters</button></div>
        <div class="mapping-filter-grid equivalency-filter-grid">
          <label class="mapping-filter-field"><span>Subject Search</span><input type="search" id="equivalencySearch" maxlength="100" placeholder="Subject code or title"/></label>
          <label class="mapping-filter-field"><span>Decision Status</span><select id="equivalencyStatus"><option value="">All Decisions</option><option value="equivalent">Equivalent</option><option value="not_equivalent">Not Equivalent</option><option value="needs_review">Needs Further Review</option></select></label>
        </div>
      </section>

      <section class="domain-card mapping-results-card" aria-labelledby="equivalencyRecordsTitle">
        <div class="domain-card-header"><div><p class="eyebrow">Stored review decisions</p><h2 id="equivalencyRecordsTitle">Recorded Decisions</h2><p class="mapping-context-message">Human-reviewed records only. Subject pairs are treated as non-directional.</p></div><span class="record-count" id="equivalencyCount">0 records</span></div>
        <div class="table-scroll" tabindex="0" aria-label="Scrollable subject equivalency records">
          <table class="domain-table equivalency-records-table"><thead><tr><th>Subject A</th><th>Subject B</th><th>Decision</th><th>Reviewed By</th><th>Reviewed At</th><th>Actions</th></tr></thead><tbody id="equivalencyRows"><tr><td colspan="<?= $recordColumnCount ?>" class="empty-cell">Loading equivalency records…</td></tr></tbody></table>
        </div>
        <div class="pagination-bar" id="equivalencyPagination"></div>
      </section>
    </section>
    <?php endif; ?>
  </main>
</div>

<?php if ($canManageEquivalencies): ?>
<div class="curriculum-modal" id="equivalencyReviewModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="equivalencyReviewTitle">
    <div class="modal-header"><div><h2 id="equivalencyReviewTitle">Record Equivalency Decision</h2><p class="modal-context" id="equivalencyReviewContext"></p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close equivalency review form"><i class="fa-solid fa-xmark"></i></button></div>
    <form class="domain-form" id="equivalencyReviewForm">
      <input type="hidden" name="id" id="equivalencyRecordId"/>
      <input type="hidden" name="subject_a_id" id="equivalencySubjectAId"/>
      <input type="hidden" name="subject_b_id" id="equivalencySubjectBId"/>
      <?= csrf_field() ?><div class="form-error" role="alert" hidden></div>
      <div class="mapping-fixed-context" role="note"><div><span>Subject A</span><strong id="equivalencySubjectALabel"></strong></div><div><span>Subject B</span><strong id="equivalencySubjectBLabel"></strong></div></div>
      <div class="form-grid">
        <label class="form-field"><span>Decision <strong>*</strong></span><select name="decision_status" id="equivalencyDecision" required><option value="" selected disabled>Select a human decision</option><option value="equivalent">Equivalent</option><option value="not_equivalent">Not Equivalent</option><option value="needs_review">Needs Further Review</option></select></label>
        <label class="form-field form-field-wide"><span>Review Notes</span><textarea name="remarks" id="equivalencyRemarks" maxlength="4000" rows="5"></textarea><small>Up to 4,000 characters.</small></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary" id="saveEquivalencyButton">Create Review Record</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<div class="curriculum-modal" id="equivalencyDetailsModal" hidden><div class="modal-backdrop" data-modal-close></div><section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="equivalencyDetailsTitle"><div class="modal-header"><div><h2 id="equivalencyDetailsTitle">Equivalency Details</h2></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close details"><i class="fa-solid fa-xmark"></i></button></div><div class="equivalency-details" id="equivalencyDetails"></div><div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Close</button></div></section></div>

<?php if ($canManageEquivalencies): ?><div class="curriculum-modal" id="equivalencyArchiveModal" hidden><div class="modal-backdrop" data-modal-close></div><section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="equivalencyArchiveTitle"><div class="modal-header"><div><h2 id="equivalencyArchiveTitle">Archive Equivalency Record</h2></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close archive confirmation"><i class="fa-solid fa-xmark"></i></button></div><p class="equivalency-archive-copy">This record will no longer appear as active/current. Historical data will be retained.</p><div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="button" class="button-danger" id="confirmArchiveEquivalency">Archive Record</button></div></section></div><?php endif; ?>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $curriculumCommonVersion ?>"></script>
<script src="../assets/subject-equivalency.js?v=<?= $equivalencyScriptVersion ?>"></script>
</body>
</html>
