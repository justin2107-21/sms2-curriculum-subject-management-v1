<?php
require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('grade_weighting.view');

$userId = current_user_id();
$canManageWeighting = $userId !== null && user_can($userId, 'grade_weighting.manage');
$avatarInitial = strtoupper(substr((string)($_SESSION['first_name'] ?? 'U'), 0, 1));
$curriculumCssVersion = (int)filemtime(__DIR__ . '/../assets/curriculum.css');
$typographyCssVersion = (int)filemtime(__DIR__ . '/../../../css/csms-typography.css');
$mappingCssVersion = (int)filemtime(__DIR__ . '/../assets/subject-mapping.css');
$weightingCssVersion = (int)filemtime(__DIR__ . '/../assets/grade-weighting.css');
$commonJsVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-common.js');
$weightingJsVersion = (int)filemtime(__DIR__ . '/../assets/grade-weighting.js');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../../../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <title>Grade Weighting Setup - BCP Student Management System</title>
  <link rel="stylesheet" href="../../../css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/curriculum.css?v=<?= $curriculumCssVersion ?>"/>
  <link rel="stylesheet" href="../assets/subject-mapping.css?v=<?= $mappingCssVersion ?>"/>
  <link rel="stylesheet" href="../assets/grade-weighting.css?v=<?= $weightingCssVersion ?>"/>
  <link rel="stylesheet" href="../../../css/csms-typography.css?v=<?= $typographyCssVersion ?>"/>
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

  <main class="content curriculum-content mapping-content grade-weighting-content"
        id="gradeWeightingWorkspace"
        data-weighting-api="../http/grade_weighting_actions.php"
        data-cimf-api="../http/grade_weighting_cimf_actions.php"
        data-can-manage="<?= $canManageWeighting ? '1' : '0' ?>">
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <h1 class="page-title">Grade Weighting Setup</h1>
        <p class="page-subtitle">Configure assessment component weights for a subject.</p>
      </div>
      <button type="button" class="button-muted grade-weighting-cimf-button" id="reviewWeightingWithCimf" disabled>
        <i class="fa-solid fa-wand-magic-sparkles"></i> Review with CIMF
      </button>
    </div>

    <section class="domain-card mapping-filter-card grade-weighting-context-card" aria-label="Curriculum context">
      <div class="mapping-filter-grid grade-weighting-context-grid">
        <div class="mapping-filter-field"><label for="weightingProgramSearch">Program / Strand</label><div class="record-autocomplete"><input type="search" id="weightingProgramSearch" placeholder="Search code or program name"/><input type="hidden" id="weightingProgram"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear program" hidden><i class="fa-solid fa-xmark"></i></button><div class="record-autocomplete-options" id="weightingProgramOptions" role="listbox" aria-label="Program suggestions" hidden></div></div></div>
        <i class="fa-solid fa-chevron-right grade-weighting-context-arrow" aria-hidden="true"></i>
        <div class="mapping-filter-field"><label for="weightingCurriculumSearch">Curriculum</label><div class="record-autocomplete"><input type="search" id="weightingCurriculumSearch" placeholder="Select a program first" disabled/><input type="hidden" id="weightingCurriculum"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear curriculum" hidden><i class="fa-solid fa-xmark"></i></button><div class="record-autocomplete-options" id="weightingCurriculumOptions" role="listbox" aria-label="Curriculum suggestions" hidden></div></div></div>
        <i class="fa-solid fa-chevron-right grade-weighting-context-arrow" aria-hidden="true"></i>
        <div class="mapping-filter-field"><label for="weightingVersionSearch">Curriculum Version</label><div class="record-autocomplete"><input type="search" id="weightingVersionSearch" placeholder="Select a curriculum first" disabled/><input type="hidden" id="weightingVersion"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear curriculum version" hidden><i class="fa-solid fa-xmark"></i></button><div class="record-autocomplete-options" id="weightingVersionOptions" role="listbox" aria-label="Version suggestions" hidden></div></div></div>
      </div>
    </section>

    <section class="domain-card mapping-filter-card grade-weighting-subject-card" aria-label="Subject selection">
      <div class="mapping-filter-field"><label for="weightingPlacementSearch">Subject</label><div class="record-autocomplete"><input type="search" id="weightingPlacementSearch" placeholder="Select a curriculum version first" disabled/><input type="hidden" id="weightingPlacement"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear subject" hidden><i class="fa-solid fa-xmark"></i></button><div class="record-autocomplete-options" id="weightingPlacementOptions" role="listbox" aria-label="Mapped subject suggestions" hidden></div></div></div>
      <p class="grade-weighting-selection-hint" id="weightingSelectionHint">Select a curriculum version and subject to view its weighting configuration.</p>
    </section>

    <section class="domain-card mapping-results-card grade-weighting-component-card" id="weightingComponentsSection" aria-labelledby="weightingComponentsTitle" hidden>
      <div class="domain-card-header">
        <div><h2 id="weightingComponentsTitle">Assessment Components</h2><p class="mapping-context-message" id="weightingContextMessage">Select a curriculum version and subject to configure grade weighting.</p><p class="grade-weighting-demo-note" id="weightingDemoNote" hidden>Demo data is not an official BCP grading policy.</p></div>
        <?php if ($canManageWeighting): ?><button type="button" class="button-muted" id="addWeightingComponent" disabled><i class="fa-solid fa-plus"></i> Add Component</button><?php endif; ?>
      </div>

      <div class="grade-weighting-empty" id="weightingSchemeEmpty">
        <p>Select a curriculum version and subject to view its weighting configuration.</p>
      </div>
      <div class="table-scroll grade-weighting-table-scroll" id="weightingTableWrap" hidden tabindex="0" aria-label="Scrollable assessment components">
        <table class="domain-table grade-weighting-component-table">
          <thead><tr><th>Component</th><th>Weight</th><?php if ($canManageWeighting): ?><th>Actions</th><?php endif; ?></tr></thead>
          <tbody id="weightingComponentRows"></tbody>
        </table>
      </div>
      <div class="grade-weighting-workspace-footer" id="weightingFooter" hidden>
        <div class="grade-weighting-total-line"><span id="weightingTotalText">0% total</span><div class="grade-weighting-progress-track" role="progressbar" aria-label="Total configured weight" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span id="weightingProgress"></span></div><span class="status-badge status-draft" id="weightingCompletionStatus">Incomplete configuration</span><span class="grade-weighting-unsaved" id="weightingUnsaved" hidden>Unsaved changes</span></div>
        <p class="grade-weighting-total-message" id="weightingTotalMessage" role="status">Add components totaling exactly 100% to save.</p>
        <?php if ($canManageWeighting): ?><button type="button" class="button-primary" id="saveWeightingChanges" disabled><i class="fa-solid fa-floppy-disk"></i> Save Changes</button><?php endif; ?>
      </div>
    </section>
  </main>
</div>

<?php if ($canManageWeighting): ?>
<div class="curriculum-modal" id="weightingComponentModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="weightingComponentModalTitle">
    <div class="modal-header"><div><h2 id="weightingComponentModalTitle">Add Grade Component</h2><p class="modal-context">Weights are configuration data for the selected curriculum version and subject.</p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close component form"><i class="fa-solid fa-xmark"></i></button></div>
    <form class="domain-form" id="weightingComponentForm">
      <input type="hidden" id="weightingComponentIndex" value="-1"/>
      <div class="weighting-component-subject-context"><span>Subject</span><strong id="weightingComponentSubject"></strong></div>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid"><label class="form-field"><span>Component Name <strong>*</strong></span><input type="text" id="weightingComponentName" maxlength="150" required placeholder="e.g. Quizzes"/></label><label class="form-field"><span>Weight (%) <strong>*</strong></span><input type="number" id="weightingComponentWeight" min="0.01" max="100" step="0.01" inputmode="decimal" required/><small id="weightingProjectedTotal">Current Total 0% · Available Weight 100%</small></label></div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary" id="saveWeightingComponent">Save Component</button></div>
    </form>
  </section>
</div>

<div class="curriculum-modal" id="removeWeightingComponentModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-compact" role="alertdialog" aria-modal="true" aria-labelledby="removeWeightingComponentTitle">
    <div class="modal-header"><div><h2 id="removeWeightingComponentTitle">Remove Grade Component</h2><p class="modal-context">The change will remain unsaved until you save a valid 100% configuration.</p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close removal confirmation"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="domain-form"><p class="confirmation-message" id="removeWeightingComponentMessage"></p><div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="button" class="remove-action-button" id="confirmRemoveWeightingComponent">Remove Component</button></div></div>
  </section>
</div>
<?php endif; ?>



<div class="curriculum-modal" id="discardWeightingModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-compact" role="alertdialog" aria-modal="true" aria-labelledby="discardWeightingTitle">
    <div class="modal-header"><div><h2 id="discardWeightingTitle">Discard unsaved changes?</h2><p class="modal-context">Changing the selected context will discard the local component edits.</p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close confirmation"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="domain-form"><p class="confirmation-message">Discard unsaved grade weighting changes?</p><div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Keep Editing</button><button type="button" class="remove-action-button" id="confirmDiscardWeighting">Discard Changes</button></div></div>
  </section>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $commonJsVersion ?>"></script>
<script src="../assets/grade-weighting.js?v=<?= $weightingJsVersion ?>"></script>
</body>
</html>
