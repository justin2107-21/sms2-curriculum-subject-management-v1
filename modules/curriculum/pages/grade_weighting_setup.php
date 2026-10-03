<?php
require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('grade_weighting.view');

$userId = current_user_id();
$canManageWeighting = $userId !== null && user_can($userId, 'grade_weighting.manage');
$componentColumnCount = $canManageWeighting ? 5 : 4;
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
        data-can-manage="<?= $canManageWeighting ? '1' : '0' ?>"
        data-component-column-count="<?= $componentColumnCount ?>">
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <h1 class="page-title"><i class="fa-solid fa-chart-pie"></i> Grade Weighting Setup</h1>
        <p class="page-subtitle">Configure subject grading components and percentage weights for curriculum placements.</p>
      </div>
      <span class="read-only-pill"><i class="fa-solid fa-sliders"></i> Configuration only</span>
    </div>

    <p class="curriculum-notice grade-weighting-notice" role="note">
      <i class="fa-solid fa-circle-info"></i>
      This workspace stores weighting configuration only. It does not calculate student grades, apply transmutation, or define official grading policy.
    </p>

    <section class="domain-card mapping-filter-card" aria-labelledby="weightingContextTitle">
      <div class="domain-card-header">
        <div><p class="eyebrow">Placement context</p><h2 id="weightingContextTitle">Select a curriculum-mapped subject</h2></div>
        <button type="button" class="button-muted" id="clearWeightingContext"><i class="fa-solid fa-rotate-left"></i> Clear Context</button>
      </div>
      <div class="mapping-filter-grid grade-weighting-context-grid">
        <label class="mapping-filter-field"><span>Academic Program</span><select id="weightingProgram"><option value="">Loading programs...</option></select></label>
        <label class="mapping-filter-field"><span>Curriculum</span><select id="weightingCurriculum" disabled><option value="">Select a program first</option></select></label>
        <label class="mapping-filter-field"><span>Curriculum Version</span><select id="weightingVersion" disabled><option value="">Select a curriculum first</option></select></label>
        <div class="mapping-filter-field">
          <label for="weightingPlacementSearch">Mapped Subject</label>
          <div class="record-autocomplete">
            <input type="search" id="weightingPlacementSearch" placeholder="Search subject code or title" disabled/>
            <input type="hidden" id="weightingPlacement"/>
            <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear mapped subject" title="Clear selection" hidden><i class="fa-solid fa-xmark"></i></button>
            <div class="record-autocomplete-options" id="weightingPlacementOptions" role="listbox" aria-label="Mapped subject suggestions" hidden></div>
          </div>
        </div>
      </div>
    </section>

    <section class="domain-card mapping-results-card grade-weighting-scheme-card" aria-labelledby="weightingSchemeTitle">
      <div class="domain-card-header">
        <div><p class="eyebrow">Placement-scoped configuration</p><h2 id="weightingSchemeTitle">Weighting Scheme</h2><p class="mapping-context-message" id="weightingContextMessage">Select a curriculum version and subject to configure grade weighting.</p></div>
        <?php if ($canManageWeighting): ?>
        <div class="grade-weighting-header-actions">
          <button type="button" class="row-action-button" id="editWeightingScheme" hidden><i class="fa-solid fa-pen"></i> Edit Description</button>
          <button type="button" class="remove-action-button" id="removeWeightingScheme" hidden><i class="fa-solid fa-trash"></i> Remove Scheme</button>
          <button type="button" class="primary-action-button" id="createWeightingScheme" disabled><i class="fa-solid fa-plus"></i> Create Weighting Scheme</button>
        </div>
        <?php endif; ?>
      </div>
      <div class="grade-weighting-empty" id="weightingSchemeEmpty">
        <i class="fa-solid fa-chart-pie"></i>
        <p>No grade weighting scheme has been configured for this subject placement.</p>
      </div>
      <div class="grade-weighting-scheme-details" id="weightingSchemeDetails" hidden>
        <p class="grade-weighting-description" id="weightingSchemeDescription">No description provided.</p>
        <div class="grade-weighting-summary" aria-label="Weighting scheme summary">
          <div><span>Configured Components</span><strong id="weightingComponentTotal">0</strong></div>
          <div><span>Current Total</span><strong id="weightingCurrentTotal">0.00%</strong></div>
          <div><span>Remaining</span><strong id="weightingRemainingTotal">100.00%</strong></div>
          <div><span>Technical Status</span><strong><span class="weighting-status-badge is-incomplete" id="weightingCompletionStatus"><i class="fa-solid fa-circle-half-stroke"></i> Incomplete</span></strong></div>
        </div>
      </div>
    </section>

    <section class="domain-card mapping-results-card grade-weighting-component-card" aria-labelledby="weightingComponentsTitle">
      <div class="domain-card-header">
        <div><p class="eyebrow">Flexible configuration</p><h2 id="weightingComponentsTitle">Weighting Components</h2><p class="mapping-context-message">Component names are user-defined and do not represent a mandated institutional policy.</p></div>
        <?php if ($canManageWeighting): ?>
        <button type="button" class="primary-action-button" id="addWeightingComponent" disabled><i class="fa-solid fa-plus"></i> Add Component</button>
        <?php endif; ?>
      </div>
      <div class="list-toolbar grade-weighting-component-toolbar">
        <label class="search-control"><span class="sr-only">Search components</span><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="weightingComponentSearch" maxlength="100" placeholder="Component name" disabled/></label>
        <label class="select-control compact-select"><span>Rows</span><select id="weightingComponentPerPage" disabled><option value="10">10</option><option value="20" selected>20</option><option value="50">50</option></select></label>
      </div>
      <div class="table-scroll" tabindex="0" aria-label="Scrollable grade weighting components">
        <table class="domain-table grade-weighting-component-table">
          <thead><tr><th>Component Name</th><th>Weight</th><th>Sequence</th><th>Updated</th><?php if ($canManageWeighting): ?><th>Actions</th><?php endif; ?></tr></thead>
          <tbody id="weightingComponentRows"><tr><td colspan="<?= $componentColumnCount ?>" class="empty-cell">Select a configured subject placement to view components.</td></tr></tbody>
        </table>
      </div>
      <div class="pagination-bar" id="weightingComponentPagination"></div>
    </section>
  </main>
</div>

<?php if ($canManageWeighting): ?>
<div class="curriculum-modal" id="weightingSchemeModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="weightingSchemeModalTitle">
    <div class="modal-header"><div><h2 id="weightingSchemeModalTitle">Create Weighting Scheme</h2><p class="modal-context" id="weightingSchemeModalContext"></p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close weighting scheme form"><i class="fa-solid fa-xmark"></i></button></div>
    <form class="domain-form" id="weightingSchemeForm">
      <input type="hidden" name="id" id="weightingSchemeId"/>
      <input type="hidden" name="curriculum_version_subject_id" id="weightingSchemePlacementId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid"><label class="form-field form-field-wide"><span>Optional Description</span><textarea name="description" id="weightingSchemeFormDescription" maxlength="4000" rows="4" placeholder="Technical notes about this weighting configuration"></textarea><small>No institutional grading policy is implied.</small></label></div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary" id="saveWeightingScheme">Create Scheme</button></div>
    </form>
  </section>
</div>

<div class="curriculum-modal" id="weightingComponentModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="weightingComponentModalTitle">
    <div class="modal-header"><div><h2 id="weightingComponentModalTitle">Add Weighting Component</h2><p class="modal-context" id="weightingComponentModalContext"></p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close component form"><i class="fa-solid fa-xmark"></i></button></div>
    <form class="domain-form" id="weightingComponentForm">
      <input type="hidden" name="id" id="weightingComponentId"/><input type="hidden" name="scheme_id" id="weightingComponentSchemeId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field"><span>Component Name <strong>*</strong></span><input type="text" name="component_name" id="weightingComponentName" maxlength="150" required placeholder="Enter a flexible component name"/></label>
        <label class="form-field"><span>Weight Percentage <strong>*</strong></span><input type="number" name="weight_percentage" id="weightingComponentWeight" min="0.01" max="100" step="0.01" inputmode="decimal" required aria-describedby="weightingProjectedTotal"/><small id="weightingProjectedTotal">Current and projected totals will appear here.</small></label>
        <label class="form-field"><span>Sequence Order</span><input type="number" name="sequence_order" id="weightingComponentSequence" min="0" step="1" inputmode="numeric" placeholder="Optional"/></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary" id="saveWeightingComponent">Add Component</button></div>
    </form>
  </section>
</div>

<div class="curriculum-modal" id="removeWeightingComponentModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-compact" role="alertdialog" aria-modal="true" aria-labelledby="removeWeightingComponentTitle">
    <div class="modal-header"><div><h2 id="removeWeightingComponentTitle">Remove Weighting Component</h2><p class="modal-context">Only this configuration component will be removed.</p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close removal confirmation"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="domain-form"><p class="confirmation-message" id="removeWeightingComponentMessage"></p><div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="button" class="remove-action-button" id="confirmRemoveWeightingComponent">Remove Component</button></div></div>
  </section>
</div>

<div class="curriculum-modal" id="removeWeightingSchemeModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-compact" role="alertdialog" aria-modal="true" aria-labelledby="removeWeightingSchemeTitle">
    <div class="modal-header"><div><h2 id="removeWeightingSchemeTitle">Remove Weighting Scheme</h2><p class="modal-context">A scheme can be removed only when it has no components.</p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close scheme removal confirmation"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="domain-form"><p class="confirmation-message">Remove this empty weighting scheme from the selected curriculum placement?</p><div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="button" class="remove-action-button" id="confirmRemoveWeightingScheme">Remove Scheme</button></div></div>
  </section>
</div>
<?php endif; ?>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $commonJsVersion ?>"></script>
<script src="../assets/grade-weighting.js?v=<?= $weightingJsVersion ?>"></script>
</body>
</html>
