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
$viewLabel = $isSubjectHistoryView ? 'Subject History View' : 'Curriculum History View';
$viewDescription = $isSubjectHistoryView
    ? 'Review stored subject usage across academic programs, curricula, and curriculum versions.'
    : 'Compare stored subject placements between two versions of the same curriculum.';
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
        data-history-api="../http/subject_history_actions.php">
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <h1 class="page-title"><i class="fa-solid fa-clock-rotate-left"></i> <?= htmlspecialchars($viewLabel, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-subtitle"><?= htmlspecialchars($viewDescription, ENT_QUOTES, 'UTF-8') ?></p>
      </div>
      <span class="read-only-pill"><i class="fa-solid fa-eye"></i> Read-only derived history</span>
    </div>

    <p class="curriculum-notice history-state-notice" role="note">
      <i class="fa-solid fa-circle-info"></i>
      Draft, active, and archived are system record states only—not official institutional approval statuses.
    </p>

    <?php if ($isSubjectHistoryView): ?>
    <section id="subjectHistoryPanel" class="subject-history-panel">
      <section class="domain-card mapping-filter-card" aria-labelledby="subjectHistoryFiltersTitle">
        <div class="domain-card-header">
          <div>
            <p class="eyebrow">Stored curriculum usage</p>
            <h2 id="subjectHistoryFiltersTitle">Where has this subject appeared?</h2>
          </div>
          <button type="button" class="button-muted" id="clearSubjectHistoryFilters">
            <i class="fa-solid fa-rotate-left"></i> Clear Filters
          </button>
        </div>
        <div class="mapping-filter-grid subject-history-filter-grid">
          <label class="mapping-filter-field mapping-search-field"><span>Subject Search</span>
            <span class="mapping-input-with-icon"><i class="fa-solid fa-magnifying-glass"></i>
              <input type="search" id="historySubjectSearch" maxlength="100" placeholder="Subject code or title"/>
            </span>
          </label>
          <label class="mapping-filter-field"><span>Academic Program</span>
            <select id="historyProgram"><option value="">All academic programs</option></select>
          </label>
          <label class="mapping-filter-field"><span>Curriculum</span>
            <select id="historyCurriculum" disabled><option value="">Select a program to narrow curricula</option></select>
          </label>
          <label class="mapping-filter-field"><span>Curriculum Version</span>
            <div class="record-autocomplete"><input type="search" id="historyVersionSearch" placeholder="Select a curriculum to narrow versions" disabled/><input type="hidden" id="historyVersion"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear curriculum version" title="Clear selection" hidden><i class="fa-solid fa-xmark"></i></button><div class="record-autocomplete-options" id="historyVersionOptions" role="listbox" aria-label="Curriculum version suggestions" hidden></div></div>
          </label>
          <label class="mapping-filter-field"><span>Year Level</span>
            <select id="historyYear"><option value="">All stored values</option></select>
          </label>
          <label class="mapping-filter-field"><span>Semester / Term</span>
            <select id="historySemester"><option value="">All stored values</option></select>
          </label>
          <label class="mapping-filter-field"><span>Technical Version Status</span>
            <select id="historyVersionStatus"><option value="">All stored states</option></select>
          </label>
          <label class="mapping-filter-field"><span>Records Per Page</span>
            <select id="historyPerPage"><option value="10">10</option><option value="20" selected>20</option><option value="50">50</option></select>
          </label>
        </div>
      </section>

      <section class="history-summary-grid" aria-label="Filtered history summary">
        <article class="history-summary-card"><span>Stored Placements</span><strong id="historyStoredPlacements">0</strong></article>
        <article class="history-summary-card"><span>Programs</span><strong id="historyPrograms">0</strong></article>
        <article class="history-summary-card"><span>Curricula</span><strong id="historyCurricula">0</strong></article>
        <article class="history-summary-card"><span>Curriculum Versions</span><strong id="historyVersions">0</strong></article>
      </section>
      <p class="history-date-context" id="historyDateContext">No stored curriculum-version dates are available for this result.</p>

      <section class="domain-card mapping-results-card" aria-labelledby="subjectHistoryResultsTitle">
        <div class="domain-card-header">
          <div>
            <p class="eyebrow">Authoritative stored placements</p>
            <h2 id="subjectHistoryResultsTitle">Subject Curriculum History</h2>
            <p class="mapping-context-message" id="subjectHistoryContext">Showing stored subject placements across all curriculum versions.</p>
          </div>
          <span class="record-count" id="subjectHistoryCount">0 records</span>
        </div>
        <div class="table-scroll" tabindex="0" aria-label="Scrollable subject history table">
          <table class="domain-table subject-history-table">
            <thead><tr><th>Subject</th><th>Academic Program</th><th>Program Type</th><th>Curriculum</th><th>Version</th><th>Version Status</th><th>Year Level</th><th>Semester / Term</th><th>Sequence</th><th>Placement</th><th>Version Record Dates</th></tr></thead>
            <tbody id="subjectHistoryRows"><tr><td colspan="11" class="empty-cell">Loading stored subject history…</td></tr></tbody>
          </table>
        </div>
        <div class="pagination-bar" id="subjectHistoryPagination"></div>
      </section>
    </section>

    <?php else: ?>
    <section id="curriculumHistoryPanel" class="subject-history-panel">
      <section class="domain-card mapping-filter-card" aria-labelledby="curriculumHistoryFiltersTitle">
        <div class="domain-card-header">
          <div>
            <p class="eyebrow">Same-curriculum comparison</p>
            <h2 id="curriculumHistoryFiltersTitle">How did subject placements change between versions?</h2>
          </div>
          <button type="button" class="button-muted" id="clearCurriculumHistoryFilters">
            <i class="fa-solid fa-rotate-left"></i> Clear Comparison
          </button>
        </div>
        <div class="mapping-filter-grid curriculum-history-filter-grid">
          <label class="mapping-filter-field"><span>Academic Program <strong>*</strong></span>
            <select id="compareProgram" required><option value="">Select an academic program</option></select>
          </label>
          <label class="mapping-filter-field"><span>Curriculum <strong>*</strong></span>
            <select id="compareCurriculum" disabled required><option value="">Select an academic program first</option></select>
          </label>
          <label class="mapping-filter-field"><span>Version A <strong>*</strong></span>
            <div class="record-autocomplete"><input type="search" id="compareVersionASearch" placeholder="Select a curriculum first" disabled required/><input type="hidden" id="compareVersionA"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear Version A" title="Clear selection" hidden><i class="fa-solid fa-xmark"></i></button><div class="record-autocomplete-options" id="compareVersionAOptions" role="listbox" aria-label="Version A suggestions" hidden></div></div>
          </label>
          <label class="mapping-filter-field"><span>Version B <strong>*</strong></span>
            <div class="record-autocomplete"><input type="search" id="compareVersionBSearch" placeholder="Select a curriculum first" disabled required/><input type="hidden" id="compareVersionB"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear Version B" title="Clear selection" hidden><i class="fa-solid fa-xmark"></i></button><div class="record-autocomplete-options" id="compareVersionBOptions" role="listbox" aria-label="Version B suggestions" hidden></div></div>
          </label>
          <label class="mapping-filter-field"><span>Records Per Page</span>
            <select id="comparePerPage"><option value="10">10</option><option value="20" selected>20</option><option value="50">50</option></select>
          </label>
          <div class="mapping-filter-action">
            <button type="button" class="button-primary" id="compareVersionsButton" disabled>
              <i class="fa-solid fa-code-compare"></i> Compare Versions
            </button>
          </div>
        </div>
      </section>

      <section class="history-summary-grid comparison-summary-grid" aria-label="Version comparison summary">
        <article class="history-summary-card history-summary-added"><span>Added</span><strong id="comparisonAdded">0</strong></article>
        <article class="history-summary-card history-summary-removed"><span>Removed</span><strong id="comparisonRemoved">0</strong></article>
        <article class="history-summary-card history-summary-changed"><span>Placement Changed</span><strong id="comparisonChanged">0</strong></article>
        <article class="history-summary-card"><span>Unchanged</span><strong id="comparisonUnchanged">0</strong></article>
      </section>

      <section class="domain-card mapping-results-card" aria-labelledby="comparisonResultsTitle">
        <div class="domain-card-header">
          <div>
            <p class="eyebrow">Deterministic placement comparison</p>
            <h2 id="comparisonResultsTitle">Curriculum Version Comparison</h2>
            <p class="mapping-context-message" id="comparisonContext">Select a program, curriculum, and two versions to compare their stored subject placements.</p>
          </div>
          <span class="record-count" id="comparisonCount">0 records</span>
        </div>
        <div class="table-scroll" tabindex="0" aria-label="Scrollable curriculum version comparison table">
          <table class="domain-table subject-history-comparison-table">
            <thead><tr><th>Subject</th><th>Change</th><th id="versionAHeader">Version A Placement</th><th id="versionBHeader">Version B Placement</th></tr></thead>
            <tbody id="comparisonRows"><tr><td colspan="4" class="empty-cell">Select two curriculum versions to compare their subject placements.</td></tr></tbody>
          </table>
        </div>
        <div class="pagination-bar" id="comparisonPagination"></div>
      </section>
    </section>
    <?php endif; ?>
  </main>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $curriculumCommonVersion ?>"></script>
<script src="../assets/subject-history.js?v=<?= $historyScriptVersion ?>"></script>
</body>
</html>
