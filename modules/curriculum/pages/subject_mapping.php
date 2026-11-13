<?php
require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('curriculum.view');

// Durable, allowlisted workspace state; never used for authorization.
$requestedTab = $_GET['tab'] ?? (($_GET['outcomes'] ?? '') === '1' ? 'outcomes' : 'map');
$activeTab = is_string($requestedTab) && in_array($requestedTab, ['map', 'review', 'outcomes', 'course'], true) ? $requestedTab : 'map';
$userId = current_user_id();
$canViewPrograms = $userId !== null && user_can($userId, 'program.view');
$canViewSubjects = $userId !== null && user_can($userId, 'subject.view');
$canViewPrerequisites = $userId !== null && user_can($userId, 'prerequisite.view');
$canManageMappings = $userId !== null && user_can($userId, 'curriculum.update');
$canManageOutcomeMappings = $userId !== null && user_can($userId, 'curriculum.outcome_mapping.manage');
$canManageClos = $userId !== null && user_can($userId, 'curriculum.clo.manage');
$canManageAssessmentPlans = $userId !== null && user_can($userId, 'curriculum.assessment_plan.manage');
$canAddMappings = $canManageMappings && $canViewSubjects;
$curriculumCssVersion = (int)filemtime(__DIR__ . '/../assets/curriculum.css');
$typographyCssVersion = (int)filemtime(__DIR__ . '/../../../css/csms-typography.css');
$mappingCssVersion = (int)filemtime(__DIR__ . '/../assets/subject-mapping.css');
$curriculumCommonVersion = substr(hash_file('sha256', __DIR__ . '/../assets/curriculum-common.js'), 0, 16);
$mappingWorkspaceVersion = (int)filemtime(__DIR__ . '/../assets/subject-mapping-final.js');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../../../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <title>Subject Mapping - BCP Student Management System</title>
  <link rel="stylesheet" href="../../../css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/curriculum.css?v=<?= $curriculumCssVersion ?>"/>
  <link rel="stylesheet" href="../assets/subject-mapping.css?v=<?= $mappingCssVersion ?>"/>
  <link rel="stylesheet" href="../../../css/csms-typography.css?v=<?= $typographyCssVersion ?>"/>
  <link rel="stylesheet" href="../assets/subject-mapping-final.css?v=<?= (int)filemtime(__DIR__ . '/../assets/subject-mapping-final.css') ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../../../';
$ACTIVE_NAV = 'subject-mapping';
require __DIR__ . '/../../../shared/sidebar.php';
?>

<div class="main">
  <?php require __DIR__ . '/../../../shared/topbar.php'; ?>

  <main class="content curriculum-content mapping-content" id="subjectMappingWorkspace"
        data-program-api="../http/program_actions.php"
        data-curriculum-api="../http/curriculum_actions.php"
        data-version-api="../http/version_actions.php"
        data-subject-api="../http/subject_actions.php"
        data-assignment-api="../http/assignment_actions.php"
        data-outcome-api="../http/outcome_mapping_actions.php"
        data-clo-api="../http/clo_assessment_actions.php"
        data-requirement-api="../http/requirement_actions.php"
        data-can-view-prerequisites="<?= $canViewPrerequisites ? '1' : '0' ?>"
        data-can-view-programs="<?= $canViewPrograms ? '1' : '0' ?>"
        data-can-view-subjects="<?= $canViewSubjects ? '1' : '0' ?>"
        data-can-manage-mappings="<?= $canManageMappings ? '1' : '0' ?>"
        data-can-manage-outcomes="<?= $canManageOutcomeMappings ? '1' : '0' ?>"
        data-can-manage-clos="<?= $canManageClos ? '1' : '0' ?>"
        data-can-manage-plans="<?= $canManageAssessmentPlans ? '1' : '0' ?>"
        data-can-add-mappings="<?= $canAddMappings ? '1' : '0' ?>">
    <header class="mapping-workspace-header">
        <div><h1 class="page-title">Subject Mapping</h1><p class="page-subtitle">Map subjects to program outcomes.</p></div>
    </header>
    <section class="mapping-workspace-shell">
      <section class="mapping-context-bar" aria-label="Curriculum mapping context">
        <label class="mapping-context-field"><span>Program / Strand</span><div class="record-autocomplete">
          <input type="search" id="mappingProgramInput" placeholder="Choose program" <?= $canViewPrograms ? '' : 'disabled' ?>/><input type="hidden" id="mappingProgramFilter"/>
          <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear program" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><div class="record-autocomplete-options" id="mappingProgramOptions" role="listbox" aria-label="Program options" hidden></div>
        </div></label>
        <span class="mapping-context-separator" aria-hidden="true"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></span>
        <label class="mapping-context-field"><span>Curriculum</span><div class="record-autocomplete">
          <input type="search" id="mappingCurriculumInput" placeholder="Choose curriculum"/><input type="hidden" id="mappingCurriculumFilter"/>
          <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear curriculum" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><div class="record-autocomplete-options" id="mappingCurriculumOptions" role="listbox" aria-label="Curriculum options" hidden></div>
        </div></label>
        <span class="mapping-context-separator" aria-hidden="true"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></span>
        <label class="mapping-context-field"><span>Curriculum Version</span><div class="record-autocomplete">
          <input type="search" id="mappingVersionInput" placeholder="Choose version" disabled/><input type="hidden" id="mappingVersionFilter"/>
          <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear version" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><div class="record-autocomplete-options" id="mappingVersionOptions" role="listbox" aria-label="Version options" hidden></div>
        </div></label>
      </section>
      <nav class="csms-tabs" id="mappingWorkspaceTabs" role="tablist" aria-label="Subject Mapping views">
        <button type="button" class="<?= $activeTab === 'map' ? 'is-active' : '' ?>" id="mappingTabMap" role="tab" tabindex="<?= $activeTab === 'map' ? '0' : '-1' ?>" aria-selected="<?= $activeTab === 'map' ? 'true' : 'false' ?>" aria-controls="mappingPanelMap">Curriculum Map</button>
        <button type="button" class="<?= $activeTab === 'review' ? 'is-active' : '' ?>" id="mappingTabReview" role="tab" tabindex="<?= $activeTab === 'review' ? '0' : '-1' ?>" aria-selected="<?= $activeTab === 'review' ? 'true' : 'false' ?>" aria-controls="mappingPanelReview">Mapping Review</button>
        <button type="button" class="<?= $activeTab === 'outcomes' ? 'is-active' : '' ?>" id="mappingTabOutcomes" role="tab" tabindex="<?= $activeTab === 'outcomes' ? '0' : '-1' ?>" aria-selected="<?= $activeTab === 'outcomes' ? 'true' : 'false' ?>" aria-controls="mappingPanelOutcomes">Graduate Outcomes</button>
        <button type="button" class="<?= $activeTab === 'course' ? 'is-active' : '' ?>" id="mappingTabCourse" role="tab" tabindex="<?= $activeTab === 'course' ? '0' : '-1' ?>" aria-selected="<?= $activeTab === 'course' ? 'true' : 'false' ?>" aria-controls="mappingPanelCourse">Course outcomes</button>
      </nav>
      <section id="mappingPanelMap" class="mapping-workspace-panel csms-panel" role="tabpanel" aria-labelledby="mappingTabMap" <?= $activeTab === 'map' ? '' : 'hidden' ?>>
        <div class="csms-panel__bar">
          <p class="csms-panel__note" id="mapAccessNote">Which program outcomes each subject supports. Placements are edited in the Curriculum Builder.</p>
          <a class="secondary-action-button" id="mappingBuilderLink" href="curriculum_builder.php" hidden><i class="fa-solid fa-table-list" aria-hidden="true"></i> Edit placements in Builder</a>
        </div>
        <div class="csms-filter-row" id="mappingMapToolbar">
          <label class="csms-search"><span class="sr-only">Search subjects</span><input id="mappingSubjectSearch" type="search" maxlength="100" placeholder="Search subject code or title"/></label>
          <select class="csms-select" id="mappingYearFilter" aria-label="Year / Grade"><option value="">All Years</option></select>
          <select class="csms-select" id="mappingSemesterFilter" aria-label="Semester"><option value="">All Semesters</option></select>
          <select class="csms-select" id="mappingStatusFilter" aria-label="Mapped status"><option value="">All subjects</option><option value="mapped">Mapped to an outcome</option><option value="unmapped">Not mapped to any</option></select>
          <span class="csms-filter-row__summary" id="mappingCount" role="status">Select a version</span>
        </div>
        <div class="csms-plan" id="mappingMap"><p class="mapping-state">Select a curriculum version to view its map.</p></div>
      </section>
      <section id="mappingPanelReview" class="mapping-workspace-panel csms-panel" role="tabpanel" aria-labelledby="mappingTabReview" <?= $activeTab === 'review' ? '' : 'hidden' ?>>
        <div class="csms-panel__bar"><p class="csms-panel__note">Checks the placements and recorded outcome mappings of the selected curriculum version. Select a check to list what it found.</p></div>
        <div class="mapping-review-cards" id="mappingReviewCards"><p class="mapping-state">Loading mapping checks…</p></div>
        <div id="mappingReviewResults"><p class="mapping-state">Select a check to inspect affected records.</p></div>
      </section>
      <section id="mappingPanelOutcomes" class="mapping-workspace-panel csms-panel" role="tabpanel" aria-labelledby="mappingTabOutcomes" <?= $activeTab === 'outcomes' ? '' : 'hidden' ?>>
        <p class="outcome-intro" id="outcomeIntro">Official outcomes from CHED CMO No. 25, s. 2015, Sec. 6.3. Links show curriculum design, not student attainment or compliance.
          <button type="button" class="csms-link" id="viewSourceDetails" disabled>Source details</button>
          <span class="csms-info"><button type="button" class="csms-info__btn" aria-label="What the statuses mean" aria-describedby="outcomeStatusTip"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button><span class="csms-info__tip csms-info__tip--wide" role="tooltip" id="outcomeStatusTip"><strong>Mapped</strong>: assessed complete and at least one subject is linked.<br/><strong>Unmapped</strong>: assessed complete and no subject is linked.<br/><strong>Incomplete</strong>: assessed as incomplete; a link added or removed after an assessment also sets this.<br/><strong>Not assessed</strong>: no coverage assessment recorded yet (the default).</span></span>
        </p>
        <div class="csms-filter-row outcome-toolbar" id="outcomeToolbar">
          <div class="outcome-chips" id="outcomeChips" role="group" aria-label="Filter outcomes by status"></div>
          <label class="csms-search"><span class="sr-only">Search graduate outcomes</span><input type="search" id="outcomeSearch" placeholder="Search code or description" maxlength="200"/></label>
        </div>
        <div class="outcome-zero" id="outcomeZero" role="status" hidden><span><i class="fa-solid fa-link-slash" aria-hidden="true"></i> <strong>No subjects linked yet</strong></span><button type="button" class="primary-action-button" id="outcomeOpenMap">Open Curriculum Map</button></div>
        <div id="outcomeMatrix" class="outcome-matrix"><p class="mapping-state">Select a BSIT curriculum version to view its recorded outcome mappings.</p></div>
      </section>
      <section id="mappingPanelCourse" class="mapping-workspace-panel csms-panel" role="tabpanel" aria-labelledby="mappingTabCourse" <?= $activeTab === 'course' ? '' : 'hidden' ?>>
        <div class="csms-filter-row course-toolbar">
          <div class="record-autocomplete course-picker"><label class="sr-only" for="cloPlacementSearch">Subject placement</label>
            <input type="search" id="cloPlacementSearch" role="combobox" aria-expanded="false" aria-controls="cloPlacementOptions" aria-autocomplete="list" placeholder="Search a subject by code or title" autocomplete="off" disabled/><input type="hidden" id="cloPlacementSelect"/>
            <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear subject placement" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            <div class="record-autocomplete-options" id="cloPlacementOptions" role="listbox" aria-label="Subject placements" hidden></div>
          </div>
        </div>
        <div id="cloAssessmentContent"><div class="csms-empty" role="status"><i class="fa-solid fa-list-check" aria-hidden="true"></i><strong>Select a subject to view or add its course learning outcomes</strong></div></div>
      </section>
    </section>

  </main>

  <div class="footer">Bestlink College of the Philippines &middot; CSMS-SMS2 &copy; 2026</div>
</div>

<div class="curriculum-modal record-details-modal" id="sourceDetailsModal" hidden aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-large modal-dialog-scroll" role="dialog" aria-modal="true" aria-labelledby="sourceDetailsModalTitle">
    <div class="modal-header"><div><h2 id="sourceDetailsModalTitle">Documentary Source Details</h2><p class="modal-context" id="sourceDetailsModalContext">CHED CMO No. 25, s. 2015</p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close Documentary Source Details"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="modal-body academic-modal-body" id="outcomeSources"></div>
    <div class="modal-footer"><button type="button" class="button-muted" data-modal-close>Close</button></div>
  </section>
</div>
<div class="curriculum-modal record-details-modal" id="outcomeViewModal" hidden aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-large modal-dialog-scroll" role="dialog" aria-modal="true" aria-labelledby="outcomeViewModalTitle">
    <div class="modal-header"><div><h2 id="outcomeViewModalTitle">Graduate Outcome Details</h2><p class="modal-context" id="outcomeViewModalContext"></p></div><span id="outcomeDetailsStatus"></span><button type="button" class="modal-close-button" data-modal-close aria-label="Close Graduate Outcome Details"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="modal-body academic-modal-body" id="outcomeDetail"></div>
    <div class="modal-footer"><button type="button" class="button-muted" data-modal-close>Close</button></div>
  </section>
</div>
<div class="curriculum-modal record-details-modal" id="cloViewModal" hidden aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-large modal-dialog-scroll" role="dialog" aria-modal="true" aria-labelledby="cloViewModalTitle">
    <div class="modal-header"><div><h2 id="cloViewModalTitle">Course Learning Outcome Details</h2><p class="modal-context" id="cloViewModalContext"></p></div><span id="cloDetailsStatus"></span><button type="button" class="modal-close-button" data-modal-close aria-label="Close Course Learning Outcome Details"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="modal-body academic-modal-body" id="cloDetail"></div>
    <div class="modal-footer"><button type="button" class="button-muted" data-modal-close>Close</button></div>
  </section>
</div>

  <div class="curriculum-modal mapping-details-modal" id="mappingDetailsModal" hidden aria-hidden="true">
    <div class="modal-backdrop" data-modal-close></div>
    <section class="modal-dialog modal-dialog-medium modal-dialog-scroll" role="dialog" aria-modal="true" aria-labelledby="mappingDetailsTitle">
      <div class="modal-header">
        <div><p class="modal-kicker">Placement Details</p><h2 id="mappingDetailsCode">Subject Code</h2><p class="modal-context" id="mappingDetailsTitle">Subject title</p></div>
        <button type="button" class="modal-close-button" data-modal-close aria-label="Close mapping details"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
      </div>
      <div class="modal-body">
        <div class="mapping-detail-chips"><span id="mappingDetailsUnits"></span><span id="mappingDetailsPlacement"></span></div>
        <h3 class="mapping-detail-section">Placement</h3>
        <dl class="mapping-detail-facts">
          <div><dt>Program Code</dt><dd id="mappingDetailsProgram"></dd></div>
          <div><dt>Curriculum</dt><dd id="mappingDetailsCurriculum"></dd></div>
          <div><dt>Version</dt><dd id="mappingDetailsVersion"></dd></div>
          <div><dt>Year / Grade Level</dt><dd id="mappingDetailsYear"></dd></div>
          <div><dt>Semester</dt><dd id="mappingDetailsSemester"></dd></div>
        </dl>
<h3 class="mapping-detail-section">Prerequisite Information (read-only)</h3><p id="mappingDetailsRequires"></p>
        <nav class="mapping-detail-links"><?php if ($canViewSubjects): ?><a id="mappingDetailsSubjectLink">View Subject Record</a><?php endif; ?><a id="mappingDetailsVersionLink">View Curriculum Version</a><?php if ($canViewPrerequisites): ?><a id="mappingDetailsPrerequisiteLink">Manage in Pre-requisite Configuration</a><?php endif; ?></nav>
      </div>
      <div class="modal-footer">
        <button type="button" class="button-muted" data-modal-close>Close</button>
      </div>
    </section>
  </div>

<div class="curriculum-modal" id="mapAddModal" hidden aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-small" role="dialog" aria-modal="true" aria-labelledby="mapAddTitle">
    <div class="modal-header"><div><h2 id="mapAddTitle">Record outcome mapping</h2><p class="modal-context" id="mapAddContext"></p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <form class="domain-form" id="mapAddForm" novalidate>
      <div class="form-error" role="alert" hidden></div>
      <label class="form-field form-field-wide"><span>Why does this subject support this outcome? <strong>*</strong></span><textarea id="mapAddRationale" name="rationale" rows="4" maxlength="3000" required aria-describedby="mapAddHelp"></textarea></label>
      <p class="mapping-edit-note" id="mapAddHelp">One short sentence. Example: Introduces computing concepts used in <span id="mapAddExample">IT01</span>.</p>
      <p class="mapping-edit-note mapping-edit-note--muted">Enter saves; Shift+Enter adds a line.</p>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Save mapping</button></div>
    </form>
  </section>
</div>
<div class="curriculum-modal" id="mapRemoveModal" hidden aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-small" role="alertdialog" aria-modal="true" aria-labelledby="mapRemoveTitle">
    <div class="modal-header"><div><h2 id="mapRemoveTitle">Remove outcome mapping?</h2></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="confirmation-body"><div id="mapRemoveMessage"></div><div class="form-error" role="alert" hidden></div></div>
    <div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="button" class="button-danger" id="mapRemoveConfirm">Remove mapping</button></div>
  </section>
</div>
<div class="curriculum-modal" id="cloCreateModal" hidden aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-small" role="dialog" aria-modal="true" aria-labelledby="cloCreateTitle">
    <div class="modal-header"><div><h2 id="cloCreateTitle">Create course learning outcome</h2><p class="modal-context" id="cloCreateContext"></p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <form class="domain-form" id="cloCreateForm" novalidate>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field"><span>Sequence <strong>*</strong></span><input id="cloCreateSequence" name="sequence_order" type="number" min="1" max="65535" required/></label>
        <label class="form-field"><span>CLO code <strong>*</strong></span><input id="cloCreateCode" name="clo_code" type="text" maxlength="40" placeholder="e.g. CLO 1" required/></label>
        <label class="form-field form-field-wide"><span>Course learning outcome <strong>*</strong></span><textarea id="cloCreateStatement" name="clo_statement" rows="4" maxlength="12000" required></textarea></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Create CLO</button></div>
    </form>
  </section>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $curriculumCommonVersion ?>"></script>
<script src="../../../js/csms-review.js?v=<?= (int)filemtime(__DIR__ . '/../../../js/csms-review.js') ?>"></script>
<script src="../assets/placement-groups.js?v=<?= (int)filemtime(__DIR__ . '/../assets/placement-groups.js') ?>"></script>
<script src="../assets/subject-mapping-data.js?v=<?= (int)filemtime(__DIR__ . '/../assets/subject-mapping-data.js') ?>"></script>
<script src="../assets/outcomes-presentation.js?v=<?= (int)filemtime(__DIR__ . '/../assets/outcomes-presentation.js') ?>"></script>
<script src="../assets/subject-mapping-matrix.js?v=<?= (int)filemtime(__DIR__ . '/../assets/subject-mapping-matrix.js') ?>"></script>
<script src="../assets/subject-mapping-final.js?v=<?= $mappingWorkspaceVersion ?>"></script>
</body>
</html>
