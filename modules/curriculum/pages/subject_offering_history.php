<?php
$subjectHistoryView = $subjectHistoryView ?? null;

if ($subjectHistoryView === null) {
    $legacyQuery = $_GET;
    $legacyView = strtolower(trim((string)($legacyQuery['view'] ?? $legacyQuery['history_view'] ?? 'subject')));
    unset($legacyQuery['view'], $legacyQuery['history_view']);
    $legacyTarget = $legacyView === 'curriculum'
        ? 'curriculum_history_view.php'
        : 'subject_history_view.php';
    $legacyQueryString = http_build_query($legacyQuery);
    header('Location: ' . $legacyTarget . ($legacyQueryString !== '' ? '?' . $legacyQueryString : ''), true, 302);
    exit;
}

if (!in_array($subjectHistoryView, ['subject', 'curriculum'], true)) {
    http_response_code(404);
    exit('Page not found.');
}

require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('subject_history.view');

$isSubjectHistoryView = $subjectHistoryView === 'subject';
$canViewSubjectMapping = current_user_id() !== null && user_can(current_user_id(), 'curriculum.view');
$viewLabel = $isSubjectHistoryView ? 'Subject History View' : 'Curriculum Version History';
$viewDescription = $isSubjectHistoryView
    ? 'Review where a subject appears across stored curricula and curriculum versions.'
    : 'Compare stored curriculum versions and review subject placement changes over time.';
$avatarInitial = strtoupper(substr((string)($_SESSION['first_name'] ?? 'U'), 0, 1));
$curriculumCssVersion = (int)filemtime(__DIR__ . '/../assets/curriculum.css');
$typographyCssVersion = (int)filemtime(__DIR__ . '/../../../css/csms-typography.css');
$mappingCssVersion = (int)filemtime(__DIR__ . '/../assets/subject-mapping.css');
$historyCssVersion = (int)filemtime(__DIR__ . '/../assets/subject-history.css');
$curriculumCommonVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-common.js');
$historyScriptVersion = (int)filemtime(__DIR__ . '/../assets/subject-history.js');
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
  <link rel="stylesheet" href="../assets/subject-history.css?v=<?= $historyCssVersion ?>"/>
  <link rel="stylesheet" href="../../../css/csms-typography.css?v=<?= $typographyCssVersion ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../../../';
$ACTIVE_NAV = $isSubjectHistoryView ? 'subject-history-subject' : 'subject-history-curriculum';
require __DIR__ . '/../../../shared/sidebar.php';
?>

<div class="main">
  <?php require __DIR__ . '/../../../shared/topbar.php'; ?>

  <main class="content curriculum-content mapping-content subject-history-content"
        id="subjectHistoryWorkspace"
        data-history-api="../http/subject_history_actions.php"
        data-cimf-api="../http/history_cimf_actions.php">
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <h1 class="page-title"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> <?= htmlspecialchars($viewLabel, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-subtitle"><?= htmlspecialchars($viewDescription, ENT_QUOTES, 'UTF-8') ?></p>
        <?php $moduleViews = 'history'; include __DIR__ . '/_module_views.php'; ?>
      </div>
      <div class="history-title-actions">
        <button type="button" class="button-muted" id="historyCimfButton"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> <?= $isSubjectHistoryView ? 'Summarize History with CIMF' : 'Explain History with CIMF' ?></button>
        <span class="read-only-pill">Read-only derived history</span>
      </div>
    </div><?php if ($isSubjectHistoryView): ?>
    <section id="subjectHistoryPanel" class="subject-history-panel">
      <section class="domain-card mapping-filter-card" aria-labelledby="subjectHistoryFiltersTitle">
        <div class="history-filter-grid">
          <label class="mapping-filter-field"><span>Subject Search</span>
            <div class="record-autocomplete"><input type="search" id="historySubjectSearch" placeholder="Search subject code or title"/>
              <input type="hidden" id="historySubjectId"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear subject" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
              <div class="record-autocomplete-options" id="historySubjectOptions" role="listbox" hidden></div></div>
          </label>
          <label class="mapping-filter-field"><span>Program</span><select id="historyProgram"><option value="">All Programs</option></select></label>
          <label class="mapping-filter-field"><span>Curriculum</span><select id="historyCurriculum"><option value="">All Curricula</option></select></label>
          <button type="button" class="button-muted" id="moreHistoryFilters" aria-expanded="false" aria-controls="historyMoreFilters">More Filters <i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>
        </div>
        <div class="history-more-filters" id="historyMoreFilters" hidden>
          <label class="mapping-filter-field"><span>Curriculum Version</span><div class="record-autocomplete"><input type="search" id="historyVersionSearch" placeholder="Select a curriculum first" disabled/><input type="hidden" id="historyVersion"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear curriculum version" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><div class="record-autocomplete-options" id="historyVersionOptions" role="listbox" hidden></div></div></label>
          <label class="mapping-filter-field"><span>Year Level</span><select id="historyYear"><option value="">All Year Levels</option></select></label>
          <label class="mapping-filter-field"><span>Semester / Term</span><select id="historySemester"><option value="">All Semesters / Terms</option></select></label>
          <label class="mapping-filter-field"><span>Version Status</span><select id="historyVersionStatus"><option value="">All Version Status</option></select></label>
        </div>
      </section>
      <p class="curriculum-notice history-state-notice" role="note">History shows placements currently stored in curriculum versions. Removing a placement deletes its stored row, so previously removed placements cannot be reconstructed. System states and record dates are technical history only, not official institutional approval or offering dates.</p>
      <p class="history-summary-line" id="historySummary">Search for a subject to view its stored history.</p>

      <section class="domain-card mapping-results-card" aria-labelledby="subjectHistoryResultsTitle">
        <div class="domain-card-header">
          <div>
            <h2 id="subjectHistoryResultsTitle">Stored Subject History</h2>
            <p class="mapping-context-message">Record dates are available in View Details and are not official offering dates.</p>
          </div>
          <span class="record-count" id="subjectHistoryCount">0 records</span>
        </div>
        <div class="table-scroll" tabindex="0" aria-label="Scrollable subject history table">
          <table class="domain-table subject-history-table">
            <thead><tr><th>Subject</th><th>Program</th><th>Curriculum / Version</th><th>Year &amp; Semester</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody id="subjectHistoryRows"><tr><td colspan="6" class="empty-cell">Search for a subject to view its stored history.</td></tr></tbody>
          </table>
        </div>
        <div class="pagination-bar" id="subjectHistoryPagination"></div>
      </section>
    </section>

    <?php else: ?>
    <section id="curriculumHistoryPanel" class="subject-history-panel">
      <section class="domain-card mapping-filter-card" aria-label="Curriculum version comparison context">
        <div class="mapping-filter-grid curriculum-history-filter-grid">
          <label class="mapping-filter-field"><span>Program / Strand <strong>*</strong></span>
            <div class="record-autocomplete"><input type="search" id="compareProgramSearch" placeholder="Search program or strand"/><input type="hidden" id="compareProgram"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear program" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><div class="record-autocomplete-options" id="compareProgramOptions" role="listbox" hidden></div></div>
          </label>
          <label class="mapping-filter-field"><span>Curriculum <strong>*</strong></span>
            <div class="record-autocomplete"><input type="search" id="compareCurriculumSearch" placeholder="Select a program first" disabled/><input type="hidden" id="compareCurriculum"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear curriculum" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><div class="record-autocomplete-options" id="compareCurriculumOptions" role="listbox" hidden></div></div>
          </label>
          <label class="mapping-filter-field"><span>Protected reference (defaults to recorded baseline)</span>
            <div class="record-autocomplete"><input type="search" id="compareVersionASearch" placeholder="Select a curriculum first" disabled required/><input type="hidden" id="compareVersionA"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear Version A" title="Clear selection" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><div class="record-autocomplete-options" id="compareVersionAOptions" role="listbox" aria-label="Version A suggestions" hidden></div></div>
          </label>
          <label class="mapping-filter-field"><span>Curriculum revision <strong>*</strong></span>
            <div class="record-autocomplete"><input type="search" id="compareVersionBSearch" placeholder="Select a curriculum first" disabled required/><input type="hidden" id="compareVersionB"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear Version B" title="Clear selection" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><div class="record-autocomplete-options" id="compareVersionBOptions" role="listbox" aria-label="Version B suggestions" hidden></div></div>
          </label>
        </div>
        <p class="form-error" id="compareValidation" role="alert" hidden></p>
      </section>

      <p class="curriculum-notice history-state-notice" role="note">System states and record dates are technical history only, not official institutional approval or offering dates.</p>
      <p class="history-summary-line comparison-summary-line" id="comparisonSummary">Select two stored curriculum versions to compare.</p>
      <section class="domain-card" id="comparisonEvidence" aria-label="Reference provenance and comparison coverage" hidden></section>

      <section class="domain-card mapping-results-card" aria-labelledby="comparisonResultsTitle">
        <div class="domain-card-header">
          <div>
            <h2 id="comparisonResultsTitle">Curriculum Changes</h2>
            <p class="mapping-context-message">Change types are calculated from stored placement records.</p>
          </div>
          <span class="record-count" id="comparisonCount">0 records</span>
        </div>
        <p class="mapping-context-message" id="comparisonEmpty">Select two curriculum versions to compare their subject placements.</p>
        <div class="table-scroll" id="comparisonTableWrap" tabindex="0" aria-label="Scrollable curriculum version comparison table" hidden>
          <table class="domain-table subject-history-comparison-table">
            <thead><tr><th>Subject</th><th>Change</th><th id="versionAHeader">Version A</th><th id="versionBHeader">Version B</th><th>Actions</th></tr></thead>
            <tbody id="comparisonRows"><tr><td colspan="5" class="empty-cell">Select two curriculum versions to compare their subject placements.</td></tr></tbody>
          </table>
        </div>
        <div class="pagination-bar" id="comparisonPagination" hidden></div>
      </section>
      <section class="domain-card" id="comparisonStructures" aria-label="Requirement, elective and grade weighting differences" hidden></section>
    </section>
    <?php endif; ?>
  </main>
</div>

<div class="curriculum-modal" id="historyDetailsModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="historyDetailsTitle">
    <div class="modal-header"><div><h2 id="historyDetailsTitle">Subject History Details</h2><p class="modal-context" id="historyDetailsContext"></p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="confirmation-body"><dl class="history-details-grid" id="historyDetailsBody"></dl><div class="history-source"><strong>SOURCE</strong><p>Derived from stored Subject Mapping / Curriculum Version placement records.</p></div></div>
    <div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Close</button><a class="button-primary" id="historyMappingLink" href="subject_mapping.php" <?= $canViewSubjectMapping ? '' : 'hidden' ?>>View in Subject Mapping</a></div>
  </section>
</div>

<div class="curriculum-modal" id="comparisonDetailsModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="comparisonDetailsTitle">
    <div class="modal-header"><div><h2 id="comparisonDetailsTitle">Curriculum Version Change Details</h2><p class="modal-context" id="comparisonDetailsContext"></p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="confirmation-body"><div class="history-version-pair" id="comparisonDetailsVersions"></div><p id="comparisonDetailsChange"></p><p id="comparisonDetailsExplanation"></p></div>
    <div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Close</button></div>
  </section>
</div>



<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $curriculumCommonVersion ?>"></script>
<script src="../assets/curriculum-history-comparison.js?v=<?= (int)filemtime(__DIR__ . '/../assets/curriculum-history-comparison.js') ?>"></script>
<script src="../assets/subject-history.js?v=<?= $historyScriptVersion ?>"></script>
</body>
</html>
