<?php
// Compare Curriculum Versions: the selected version in the shared Program / Curriculum / Version bar is the REVISED version;
// the original is its recorded original (read from the revision itself). Read-only; same compare_revision endpoint as always.
require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('subject_history.view');

// Older links (Curriculum Builder "Compare versions") carry curriculum_id and version_b_id only. Turn them into the
// address the shared context bar understands (program, curriculum, version) so the revision is preselected.
if (isset($_GET['version_b_id']) && !isset($_GET['version_id'])) {
    $versionId = filter_var($_GET['version_b_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($versionId !== false) {
        $stmt = authorization_connection()->prepare('SELECT c.id AS curriculum_id, c.academic_program_id FROM curriculum_versions v INNER JOIN curricula c ON c.id = v.curriculum_id WHERE v.id = ?');
        $stmt->bind_param('i', $versionId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($found) {
            $query = $_GET;
            unset($query['version_b_id'], $query['version_a_id']);
            $query = ['program_id' => (int)$found['academic_program_id'], 'curriculum_id' => (int)$found['curriculum_id'], 'version_id' => $versionId] + $query;
            header('Location: curriculum_history_view.php?' . http_build_query($query), true, 302);
            exit;
        }
    }
}

$historyCssVersion = (int)filemtime(__DIR__ . '/../assets/subject-history.css');
$commonVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-common.js');
$comparisonUiVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-history-comparison.js');
$scriptVersion = (int)filemtime(__DIR__ . '/../assets/version-comparison.js');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../../../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <title>Compare Curriculum Versions - BCP Student Management System</title>
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
$ACTIVE_NAV = 'subject-history-curriculum';
require __DIR__ . '/../../../shared/sidebar.php';
?>
<div class="main">
  <?php require __DIR__ . '/../../../shared/topbar.php'; ?>
  <main class="content curriculum-content mapping-content subject-history-content" id="versionComparisonWorkspace"
        data-history-api="../http/subject_history_actions.php">
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <a class="subject-back-link" href="subject_history_view.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to Subject Offering History</a>
        <h1 class="page-title"><i class="fa-solid fa-code-compare" aria-hidden="true"></i> Compare Curriculum Versions</h1>
        <p class="page-subtitle">What changed between the selected revised version and its recorded original.</p>
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

      <section class="mapping-workspace-panel csms-panel" aria-label="Version comparison">
        <div class="csms-empty" id="compareLoading" role="status"><i class="fa-solid fa-spinner" aria-hidden="true"></i><strong>Loading your working context…</strong></div>
        <div class="csms-empty" id="compareNoPrograms" role="status" hidden><i class="fa-solid fa-folder-open" aria-hidden="true"></i><strong>No programs exist yet</strong><span>Add a Program / Strand in Academic Programs first.</span></div>
        <div class="csms-empty" id="compareNoVersion" role="status" hidden><i class="fa-solid fa-bullseye" aria-hidden="true"></i><strong>No curriculum version selected</strong><span>Use the context bar above to pick the revised version.</span></div>
        <div class="csms-empty" id="compareNoOriginal" role="status" hidden><i class="fa-solid fa-code-branch" aria-hidden="true"></i><strong>No recorded original</strong><span>This version was not created as a revision of another version, so there is nothing to compare it with. Pick a revised version in the context bar.</span></div>
        <div class="csms-empty" id="compareError" role="alert" hidden><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><strong>Comparison unavailable</strong><span id="compareErrorText"></span></div>
        <div id="compareResult" hidden>
          <div class="csms-plan">
          <div class="csms-panel__bar">
            <p class="csms-panel__note" id="compareOriginal"></p>
            <span class="csms-info"><button type="button" class="csms-info__btn" aria-label="About this comparison" aria-describedby="compareInfoTip"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button><span class="csms-info__tip" role="tooltip" id="compareInfoTip">All stored subjects are shown, including unchanged ones. Category counts overlap, so a subject can be both moved and changed. Differences in requirements, electives, grade weighting and learning outcomes are listed below the table. Record dates and states are technical history, not official approval dates.</span></span>
          </div>
          <div class="csms-filter-row" id="compareChips" role="group" aria-label="Change counts"></div>
          <div class="csms-page-table table-scroll" id="comparisonTableWrap" tabindex="0" hidden aria-label="Scrollable curriculum version comparison table">
            <table class="csms-ctable subject-history-comparison-table">
              <thead><tr><th>Subject</th><th>Change</th><th>Original</th><th>Revised</th></tr></thead>
              <tbody id="comparisonRows"></tbody>
            </table>
          </div>
          <div class="pagination-bar" id="comparisonPagination" hidden></div>
          <section class="domain-card" id="comparisonEvidence" aria-label="Source documents and comparison coverage" hidden></section>
          <section class="domain-card" id="comparisonStructures" aria-label="Requirement, elective and grade weighting differences" hidden></section>
          </div>
        </div>
      </section>
    </section>
  </main>
  <div class="footer">Bestlink College of the Philippines &middot; CSMS-SMS2 &copy; 2026</div>
</div>

<div class="curriculum-modal" id="comparisonDetailsModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-large" role="dialog" aria-modal="true" aria-labelledby="comparisonDetailsTitle">
    <div class="modal-header"><div><h2 id="comparisonDetailsTitle">Version Change Details</h2><p class="modal-context" id="comparisonDetailsContext"></p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="confirmation-body"><div class="history-version-pair" id="comparisonDetailsVersions"></div><p id="comparisonDetailsChange"></p><p id="comparisonDetailsExplanation"></p></div>
    <div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Close</button></div>
  </section>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $commonVersion ?>"></script>
<script src="../assets/curriculum-history-comparison.js?v=<?= $comparisonUiVersion ?>"></script>
<script src="../assets/version-comparison.js?v=<?= $scriptVersion ?>"></script>
</body>
</html>
