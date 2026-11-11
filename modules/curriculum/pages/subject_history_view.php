<?php
// Subject Offering History: one page under the shared Program / Curriculum / Version context bar.
// Read-only and derived from stored curriculum placements. The version comparison lives in curriculum_history_view.php
// (the same screen Curriculum Builder links to), reached from the version timeline.
require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('subject_history.view');

$canViewSubjectMapping = current_user_id() !== null && user_can(current_user_id(), 'curriculum.view');
$canCompare = true; // subject_history.view is the same permission that opens the comparison screen
$historyCssVersion = (int)filemtime(__DIR__ . '/../assets/subject-history.css');
$commonVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-common.js');
$scriptVersion = (int)filemtime(__DIR__ . '/../assets/offering-history.js');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../../../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <title>Subject Offering History - BCP Student Management System</title>
  <link rel="stylesheet" href="../../../css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/curriculum.css"/>
  <link rel="stylesheet" href="../assets/subject-mapping.css"/>
  <link rel="stylesheet" href="../assets/subject-history.css?v=<?= $historyCssVersion ?>"/>
  <link rel="stylesheet" href="../../../css/csms-typography.css"/>
  <link rel="stylesheet" href="../assets/subject-mapping-final.css?v=<?= (int)filemtime(__DIR__ . '/../assets/subject-mapping-final.css') ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../../../';
$ACTIVE_NAV = 'subject-history-subject';
require __DIR__ . '/../../../shared/sidebar.php';
?>
<div class="main">
  <?php require __DIR__ . '/../../../shared/topbar.php'; ?>
  <main class="content curriculum-content mapping-content subject-history-content" id="offeringHistoryWorkspace"
        data-history-api="../http/subject_history_actions.php"
        data-can-mapping="<?= $canViewSubjectMapping ? '1' : '0' ?>">
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <h1 class="page-title"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Subject Offering History</h1>
        <p class="page-subtitle">Curriculum placement history: where each subject appears across curriculum versions. Derived from stored curricula and read-only.</p>
      </div>
      <div class="card-header-actions">
        <span class="csms-chip csms-chip--draft">Read-only: placement history plus reported offerings.</span>
        <span class="csms-info"><button type="button" class="csms-info__btn" aria-label="About this page" aria-describedby="historyInfoTip"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button><span class="csms-info__tip" role="tooltip" id="historyInfoTip">This page shows the history of subject placements in stored curriculum versions, plus offerings reported by other subsystems, which are shown separately and are not verified by CSMS. It does not show instructors, and it is not an official approval or offering date. A placement that was removed from a version is not kept, so it cannot be shown. Rows without a stored snapshot show current Subject Master values; open a row for details.</span></span>
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
        <div class="csms-empty" id="historyLoading" role="status"><i class="fa-solid fa-spinner" aria-hidden="true"></i><strong>Loading your working context…</strong></div>
        <div class="csms-empty" id="historyNoPrograms" role="status" hidden><i class="fa-solid fa-folder-open" aria-hidden="true"></i><strong>No programs exist yet</strong><span>Add a Program / Strand in Academic Programs first.</span></div>
        <div class="csms-empty" id="historyEmpty" role="status" hidden><i class="fa-solid fa-bullseye" aria-hidden="true"></i><strong>No curriculum version selected</strong><span>Use the context bar above to pick a curriculum version.</span></div>
        <div id="historyPanel" hidden>
          <div class="csms-filter-row">
            <label class="csms-search"><span class="sr-only">Search subject code or title</span><input type="search" id="historySearch" maxlength="100" placeholder="Search subject code or title"/></label>
            <select class="csms-select" id="historyPerPage" aria-label="Rows per page"><option value="10">10 rows</option><option value="20" selected>20 rows</option><option value="50">50 rows</option></select>
            <span class="csms-filter-row__summary" id="historyCount" role="status">0 records</span>
          </div>
          <div class="csms-page-table table-scroll" tabindex="0" aria-label="Scrollable subject history table">
            <table class="csms-ctable subject-history-table" id="historyTable">
              <thead><tr><th class="csms-ctable__code">Code</th><th>Title</th><th class="csms-ctable__num">Units</th><th>Year &amp; Semester</th><th>Type</th><th>Appears in <span class="csms-info"><button type="button" class="csms-info__btn" aria-label="About the Appears in column" aria-describedby="appearsInfoTip"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button><span class="csms-info__tip" role="tooltip" id="appearsInfoTip">How many versions of this program contain the subject, counting every curriculum and every status you may view.</span></span></th><th class="csms-ctable__num">Reported <span class="csms-info"><button type="button" class="csms-info__btn" aria-label="About the Reported column" aria-describedby="reportedColInfoTip"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button><span class="csms-info__tip" role="tooltip" id="reportedColInfoTip">Offerings other subsystems reported for this subject in this program. Not verified by this system.</span></span></th></tr></thead>
              <tbody id="historyRows"></tbody>
            </table>
          </div>
          <div class="csms-empty" id="historyNoMatch" role="status" hidden><i class="fa-solid fa-filter" aria-hidden="true"></i><strong>No subjects match</strong><span>Change the search.</span></div>
          <div class="pagination-bar" id="historyPagination"></div>
        </div>
      </section>
    </section>
  </main>
  <div class="footer">Bestlink College of the Philippines &middot; CSMS-SMS2 &copy; 2026</div>
</div>

<div class="curriculum-modal" id="historyDetailsModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-large" role="dialog" aria-modal="true" aria-labelledby="historyDetailsTitle">
    <div class="modal-header"><div><h2 id="historyDetailsTitle">Subject History Details</h2><p class="modal-context" id="historyDetailsContext"></p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="confirmation-body"><dl class="csms-detail-list" id="historyDetailsBody"></dl>
      <h3 class="history-timeline-title">Versions of this program containing the subject <span class="csms-info"><button type="button" class="csms-info__btn" aria-label="About the version timeline" aria-describedby="timelineInfoTip"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button><span class="csms-info__tip" role="tooltip" id="timelineInfoTip">Oldest first. Record dates are technical (when the placement was stored), not approval or offering dates. Code, title and units appear as captured only where a snapshot exists; otherwise only the current Subject Master values are known and they are not shown as history.</span></span></h3>
      <div class="csms-page-table table-scroll" tabindex="0" aria-label="Scrollable version timeline"><table class="csms-ctable"><thead><tr><th>Version</th><th>Status</th><th>Year &amp; Semester</th><th>Type</th><th>Stored date</th></tr></thead><tbody id="historyTimelineRows"></tbody></table></div>
      <h3 class="history-timeline-title">Reported offerings <span class="csms-info"><button type="button" class="csms-info__btn" aria-label="About reported offerings" aria-describedby="reportedInfoTip"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button><span class="csms-info__tip" role="tooltip" id="reportedInfoTip">These rows are reported by other subsystems through the API. They are not verified by this system and are separate from curriculum placement history.</span></span></h3>
      <div class="csms-page-table table-scroll" id="historyReportedWrap" tabindex="0" aria-label="Scrollable reported offerings" hidden><table class="csms-ctable"><thead><tr><th>Academic year</th><th>Term</th><th>Status</th><th class="csms-ctable__num">Sections</th><th class="csms-ctable__num">Enrolled</th><th>Flags</th><th>Source system</th><th>Received</th></tr></thead><tbody id="historyReportedRows"></tbody></table></div>
      <div class="csms-empty" id="historyReportedEmpty" role="status" hidden><i class="fa-solid fa-inbox" aria-hidden="true"></i><strong>No offering data received yet.</strong></div>
      <p class="modal-context" id="historyReportedNote" hidden></p></div>
    <div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Close</button><button type="button" class="button-muted" id="historySummarizeButton"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Summarize History with CIMF</button><span id="historyCompareWrap" hidden><a class="button-muted" id="historyCompareLink" href="curriculum_history_view.php">Compare versions</a></span><a class="button-primary" id="historyMappingLink" href="subject_mapping.php" <?= $canViewSubjectMapping ? '' : 'hidden' ?>>View in Subject Mapping</a></div>
  </section>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $commonVersion ?>"></script>
<script src="../assets/offering-history.js?v=<?= $scriptVersion ?>"></script>
</body>
</html>
