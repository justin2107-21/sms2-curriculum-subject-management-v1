<?php
require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('curriculum.view');

// Durable, allowlisted workspace state; never used for authorization.
$requestedTab = $_GET['tab'] ?? (($_GET['outcomes'] ?? '') === '1' ? 'outcomes' : 'map');
$activeTab = is_string($requestedTab) && in_array($requestedTab, ['map', 'trace', 'review', 'outcomes'], true) ? $requestedTab : 'map';
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
        <div><h1 class="page-title">Subject Mapping</h1><p class="page-subtitle">Organize subjects within curriculum versions.</p></div>
        <?php if ($canAddMappings): ?><button type="button" class="primary-action-button" id="addMappingButton" disabled><i class="fa-solid fa-plus" aria-hidden="true"></i> Place Subject</button><?php endif; ?>
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
      <nav class="mapping-workspace-tabs" id="mappingWorkspaceTabs" role="tablist" aria-label="Subject Mapping views">
        <button type="button" class="<?= $activeTab === 'map' ? 'is-active' : '' ?>" id="mappingTabMap" role="tab" tabindex="<?= $activeTab === 'map' ? '0' : '-1' ?>" aria-selected="<?= $activeTab === 'map' ? 'true' : 'false' ?>" aria-controls="mappingPanelMap">Curriculum Map</button>
        <button type="button" class="<?= $activeTab === 'trace' ? 'is-active' : '' ?>" id="mappingTabTrace" role="tab" tabindex="<?= $activeTab === 'trace' ? '0' : '-1' ?>" aria-selected="<?= $activeTab === 'trace' ? 'true' : 'false' ?>" aria-controls="mappingPanelTrace">Subject Trace</button>
        <button type="button" class="<?= $activeTab === 'review' ? 'is-active' : '' ?>" id="mappingTabReview" role="tab" tabindex="<?= $activeTab === 'review' ? '0' : '-1' ?>" aria-selected="<?= $activeTab === 'review' ? 'true' : 'false' ?>" aria-controls="mappingPanelReview">Mapping Review</button>
        <button type="button" class="<?= $activeTab === 'outcomes' ? 'is-active' : '' ?>" id="mappingTabOutcomes" role="tab" tabindex="<?= $activeTab === 'outcomes' ? '0' : '-1' ?>" aria-selected="<?= $activeTab === 'outcomes' ? 'true' : 'false' ?>" aria-controls="mappingPanelOutcomes">Graduate Outcomes</button>
      </nav>
      <section id="mappingPanelMap" class="mapping-workspace-panel" role="tabpanel" aria-labelledby="mappingTabMap" <?= $activeTab === 'map' ? '' : 'hidden' ?>>
        <div class="mapping-year-tabs" id="mappingYearTabs" aria-label="Year / Grade filter"></div>
        <div class="mapping-map-toolbar">
          <label class="search-control"><span class="sr-only">Search subjects</span><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input id="mappingSubjectSearch" type="search" maxlength="100" placeholder="Search subject code or title..."/></label>
          <label class="form-field csms-hidden-field"><span class="sr-only">Year / Grade</span><select id="mappingYearFilter" tabindex="-1" aria-hidden="true"><option value="">All Years</option></select></label>
          <label class="form-field"><span class="sr-only">Semester</span><select id="mappingSemesterFilter"><option value="">All Semesters</option></select></label>
          <label class="form-field"><span class="sr-only">Placement</span><select id="mappingPlacementFilter"><option value="">All Placements</option><option value="1">Required</option><option value="0">Optional</option></select></label>
          <span class="mapping-map-total" id="mappingCount">Select a version</span>
        </div>
        <?php if ($canViewPrerequisites): ?><label class="mapping-connections-toggle"><input type="checkbox" id="mappingShowConnections"/> Show prerequisite connections</label><?php endif; ?>
        <div id="mappingConnections" hidden></div>
        <div class="mapping-year-sections" id="mappingMap"><p class="mapping-state">Select a curriculum version to view its map.</p></div>
      </section>
      <section id="mappingPanelTrace" class="mapping-workspace-panel" role="tabpanel" aria-labelledby="mappingTabTrace" <?= $activeTab === 'trace' ? '' : 'hidden' ?>>
        <div class="mapping-trace-search"><p class="eyebrow">Find Subject Usage</p><h2>Where is this subject placed?</h2><p id="mappingTraceHelper">Search a Subject Master record to view all curriculum placements.</p><label class="mapping-context-field"><span>Subject</span><div class="record-autocomplete">
          <input type="search" id="mappingTraceSearch" placeholder="Search code or title..." <?= $canViewSubjects ? '' : 'disabled' ?>/><input type="hidden" id="mappingTraceSubjectId"/>
          <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear subject" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><div class="record-autocomplete-options" id="mappingTraceOptions" role="listbox" aria-label="Subject options" hidden></div>
        </div></label></div>
        <div id="mappingTraceResults"><p class="mapping-state"><?= $canViewSubjects ? 'Select a subject to view its curriculum placements.' : 'Subject Trace requires Subject Master view permission.' ?></p></div>
      </section>
      <section id="mappingPanelReview" class="mapping-workspace-panel" role="tabpanel" aria-labelledby="mappingTabReview" <?= $activeTab === 'review' ? '' : 'hidden' ?>>
        <div class="mapping-review-header"><div><h2>Mapping Review</h2><p>Checks the structure of placements in the selected curriculum version.</p></div><span id="mappingReviewScope">Select a version</span></div>
        <div class="mapping-review-cards" id="mappingReviewCards"><p class="mapping-state">Loading mapping checks…</p></div>
        <div id="mappingReviewResults"><p class="mapping-state">Select a check to inspect affected records.</p></div>
      </section>
      <section id="mappingPanelOutcomes" class="mapping-workspace-panel" role="tabpanel" aria-labelledby="mappingTabOutcomes" <?= $activeTab === 'outcomes' ? '' : 'hidden' ?>>
        <div class="mapping-review-header"><div><p class="eyebrow">CHED BSIT · Section 6.3</p><h2>Graduate Outcomes Mapping</h2><p>Manual curriculum-to-outcome associations for the selected BSIT version.</p></div><span id="outcomeVersionScope">Select a version</span></div>
        <aside class="curriculum-notice academic-notice" role="note" aria-labelledby="academicNoticeTitle">
          <i class="fa-solid fa-circle-info" aria-hidden="true"></i><div><strong id="academicNoticeTitle">Important Academic Notice</strong>
          <p><strong>Source:</strong> CHED CMO No. 25, s. 2015, Section 6.3. The supplied scan is fingerprinted; independent registry verification, current applicability, and institutional adoption/approval have not been established.</p>
          <p><strong>Limitation:</strong> Curriculum mapping, course outcome alignments, and assessment plans do not establish student outcome attainment, regulatory compliance, or institutional approval.</p></div>
        </aside>
        <div id="outcomeOverview" class="program-summary-grid" aria-label="Recorded coverage overview"></div>
        <div class="card-header-actions"><button type="button" class="secondary-action-button" id="viewSourceDetails" disabled><i class="fa-solid fa-file-lines" aria-hidden="true"></i> View Source Details</button></div>
        <div class="list-toolbar outcome-toolbar">
          <label class="search-control"><span class="sr-only">Search graduate outcomes</span><input type="search" id="outcomeSearch" placeholder="Search code or official description" maxlength="200"/></label>
          <label class="form-field"><span>Coverage</span><select id="outcomeCoverage"><option value="">All coverage states</option><option value="not_assessed">Not assessed</option><option value="incomplete">Incomplete</option><option value="mapped">Mapped</option><option value="unmapped">Unmapped</option></select></label>
          <span id="outcomeResultCount" role="status"></span>
        </div>
        <div id="outcomeMatrix" class="outcome-matrix"><p class="mapping-state">Select a BSIT curriculum version to view its recorded outcome mappings.</p></div>
        <section class="clo-assessment-workspace" aria-labelledby="cloAssessmentHeading">
          <h3 id="cloAssessmentHeading">Course Learning Outcomes &amp; Planned Assessment</h3>
          <div class="list-toolbar clo-selection-toolbar"><label class="form-field"><span>Subject placement</span><div class="record-autocomplete">
            <input type="search" id="cloPlacementSearch" placeholder="Search subject code or title" disabled/><input type="hidden" id="cloPlacementSelect"/>
            <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear subject placement" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            <div class="record-autocomplete-options" id="cloPlacementOptions" role="listbox" aria-label="Subject placements" hidden></div>
          </div></label></div>
          <div id="cloAssessmentContent"><p class="mapping-state">Select a curriculum version and subject placement.</p></div>
        </section>
      </section>
    </section>

  </main>

  <div class="footer">eLearning Commons &copy; 2026</div>
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
        <?php if ($canManageMappings): ?><button type="button" class="button-primary" id="mappingDetailsEdit"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit Placement</button><button type="button" class="button-danger" id="mappingDetailsRemove"><i class="fa-solid fa-link-slash" aria-hidden="true"></i> Remove Placement</button><?php endif; ?>
      </div>
    </section>
  </div>

<?php if ($canAddMappings): ?>
<div class="curriculum-modal" id="addMappingModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-scroll" role="dialog" aria-modal="true" aria-labelledby="addMappingTitle">
    <div class="modal-header">
      <div>
        <h2 id="addMappingTitle">Place Subject</h2>
        <p class="modal-context" id="addMappingContext"></p>
      </div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close add subject mapping form"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>
    <form class="domain-form" id="addMappingForm">
      <input type="hidden" name="curriculum_version_id" id="addMappingVersionId"/>
      <input type="hidden" name="academic_program_id" id="addMappingProgramId"/>
      <input type="hidden" name="curriculum_id" id="addMappingCurriculumId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <div class="form-field form-field-wide">
          <label for="mappingSubjectSelectorSearch">Subject <strong>*</strong></label>
          <div class="record-autocomplete">
            <input type="search" id="mappingSubjectSelectorSearch" maxlength="100" placeholder="Search all subjects by code or title" required/>
            <input type="hidden" name="subject_id" id="mappingSubjectId"/>
            <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear subject" title="Clear selection" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            <div class="record-autocomplete-options" id="mappingSubjectOptions" role="listbox" aria-label="Subject suggestions" hidden></div>
          </div>
          <small id="mappingSubjectHint" role="status">Type a code or title and select a Subject Master record.</small><a href="subjects.php">Go to Subject Master</a>
        </div>
        <label class="form-field"><span>Year / Grade Level <strong>*</strong></span><select name="year_level" id="addMappingYear" required><option value="">Select year / grade level</option></select></label>
        <label class="form-field"><span>Semester <strong>*</strong></span><select name="semester" id="addMappingSemester" required><option value="">Select semester</option></select></label>
        <label class="form-field"><span>Placement <strong>*</strong></span><select name="is_required" required><option value="">Choose placement</option><option value="1">Required</option><option value="0">Optional</option></select></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Save Placement</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<?php if ($canManageMappings): ?>
<div class="curriculum-modal" id="editMappingModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-scroll" role="dialog" aria-modal="true" aria-labelledby="editMappingTitle">
    <div class="modal-header">
      <div><h2 id="editMappingTitle">Edit Placement</h2><p class="modal-context" id="editMappingContext"></p></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close edit subject mapping form"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>
    <form class="domain-form" id="editMappingForm">
      <input type="hidden" name="id" id="editMappingId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="mapping-fixed-context" role="note">
        <div><span>Subject</span><strong id="editMappingSubject"></strong></div>
        <div><span>Curriculum Version</span><strong id="editMappingVersion"></strong></div>
      </div>
      <div class="form-grid">
        <label class="form-field"><span>Year / Grade Level <strong>*</strong></span><select name="year_level" id="editMappingYear" required><option value="">Select year / grade level</option></select></label>
        <label class="form-field"><span>Semester <strong>*</strong></span><select name="semester" id="editMappingSemester" required><option value="">Select semester</option></select></label>
        <input type="hidden" name="sequence_order" id="editMappingSequence"/>
        <label class="form-field"><span>Placement <strong>*</strong></span><select name="is_required" id="editMappingRequired" required><option value="">Choose placement</option><option value="1">Required</option><option value="0">Optional</option></select></label>
      </div>
      <p class="mapping-edit-note">Subject and curriculum version stay fixed. Only placement details can be edited.</p>
      <div id="mappingSequenceResult" class="mapping-fixed-context" role="status" aria-live="polite"></div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Save Changes</button></div>
    </form>
  </section>
</div>

<div class="curriculum-modal" id="removeMappingModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-small" role="alertdialog" aria-modal="true" aria-labelledby="removeMappingTitle">
    <div class="modal-header">
      <div><h2 id="removeMappingTitle">Remove Subject Placement?</h2></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close removal confirmation"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>
    <div class="confirmation-body">
      <div id="removeMappingMessage"></div><p>This removes only the placement. The Subject Master record will remain.</p>
      <div class="form-error" role="alert" hidden></div>
    </div>
    <div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="button" class="button-danger" id="confirmRemoveMapping">Remove Placement</button></div>
  </section>
</div>
<?php endif; ?>

<div class="curriculum-modal mapping-details-modal" id="mappingConnectionModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="mappingConnectionTitle">
    <div class="modal-header"><div><p class="modal-kicker">Prerequisite Connection</p><h2 id="mappingConnectionTitle"></h2></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close connection">&times;</button></div>
    <div class="modal-body"><p id="mappingConnectionSubjects"></p><p id="mappingConnectionText"></p><p>Read-only connection from Pre-requisite Configuration.</p></div>
    <div class="modal-footer"><button type="button" class="button-muted" data-modal-close>Close</button><a class="button-primary" id="mappingConnectionManage">Manage in Pre-requisite Configuration</a></div>
  </section>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $curriculumCommonVersion ?>"></script>
<script src="../assets/subject-mapping-data.js?v=<?= (int)filemtime(__DIR__ . '/../assets/subject-mapping-data.js') ?>"></script>
<script src="../assets/outcomes-presentation.js?v=<?= (int)filemtime(__DIR__ . '/../assets/outcomes-presentation.js') ?>"></script>
<script src="../assets/subject-mapping-final.js?v=<?= $mappingWorkspaceVersion ?>"></script>
</body>
</html>
