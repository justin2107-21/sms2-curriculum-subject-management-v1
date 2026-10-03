<?php
$programAssignmentView = $programAssignmentView ?? null;

if ($programAssignmentView === null) {
    $legacyQuery = $_GET;
    $legacyView = isset($legacyQuery['view']) ? strtolower(trim((string)$legacyQuery['view'])) : 'program';
    unset($legacyQuery['view']);
    $legacyTarget = $legacyView === 'subject'
        ? 'subject_centric_assignment.php'
        : 'program_centric_assignment.php';
    $legacyQueryString = http_build_query($legacyQuery);
    header('Location: ' . $legacyTarget . ($legacyQueryString !== '' ? '?' . $legacyQueryString : ''), true, 302);
    exit;
}

if (!in_array($programAssignmentView, ['program', 'subject'], true)) {
    http_response_code(404);
    exit('Page not found.');
}

require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('program_assignment.view');

$userId = current_user_id();
$canManageAssignments = $userId !== null && user_can($userId, 'program_assignment.manage');
$isProgramView = $programAssignmentView === 'program';
$viewLabel = $isProgramView ? 'Program-Centric View' : 'Subject-Centric View';
$viewDescription = $isProgramView
    ? 'View and manage subjects assigned to a selected academic program and curriculum version.'
    : 'Review where a Subject Master record is used across academic programs and curriculum versions.';
$programColumnCount = $canManageAssignments ? 12 : 11;
$subjectColumnCount = $canManageAssignments ? 10 : 9;
$avatarInitial = strtoupper(substr((string)($_SESSION['first_name'] ?? 'U'), 0, 1));
$curriculumCssVersion = (int)filemtime(__DIR__ . '/../assets/curriculum.css');
$typographyCssVersion = (int)filemtime(__DIR__ . '/../../../css/csms-typography.css');
$mappingCssVersion = (int)filemtime(__DIR__ . '/../assets/subject-mapping.css');
$assignmentCssVersion = (int)filemtime(__DIR__ . '/../assets/program-assignment.css');
$curriculumCommonVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-common.js');
$assignmentScriptVersion = (int)filemtime(__DIR__ . '/../assets/program-assignment.js');
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
  <link rel="stylesheet" href="../assets/program-assignment.css?v=<?= $assignmentCssVersion ?>"/>
  <link rel="stylesheet" href="../../../css/csms-typography.css?v=<?= $typographyCssVersion ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../../../';
$ACTIVE_NAV = $isProgramView ? 'program-assignment-program' : 'program-assignment-subject';
require __DIR__ . '/../../../shared/sidebar.php';
?>

<div class="main">
  <?php require __DIR__ . '/../../../shared/topbar.php'; ?>

  <main class="content curriculum-content mapping-content program-assignment-content"
        id="programAssignmentWorkspace"
        data-assignment-api="../http/program_assignment_actions.php"
        data-can-manage-assignments="<?= $canManageAssignments ? '1' : '0' ?>">
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <h1 class="page-title"><i class="fa-solid fa-building-columns"></i> <?= htmlspecialchars($viewLabel, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-subtitle"><?= htmlspecialchars($viewDescription, ENT_QUOTES, 'UTF-8') ?></p>
      </div>
      <span class="read-only-pill"><i class="fa-solid fa-link"></i> Existing curriculum placements</span>
    </div>

    <?php if ($isProgramView): ?>
    <section id="programCentricPanel" class="program-assignment-panel">
      <section class="domain-card mapping-filter-card" aria-labelledby="programAssignmentFiltersTitle">
        <div class="domain-card-header">
          <div>
            <p class="eyebrow">Program-centric context</p>
            <h2 id="programAssignmentFiltersTitle">What subjects are used by this academic program?</h2>
          </div>
          <button type="button" class="button-muted" id="clearProgramAssignmentFilters">
            <i class="fa-solid fa-rotate-left"></i> Clear Filters
          </button>
        </div>
        <div class="mapping-filter-grid program-assignment-filter-grid">
          <label class="mapping-filter-field"><span>Academic Program <strong>*</strong></span>
            <select id="programAssignmentProgram" disabled><option value="">Loading academic programs…</option></select>
          </label>
          <label class="mapping-filter-field"><span>Curriculum</span>
            <select id="programAssignmentCurriculum" disabled><option value="">Select an academic program first</option></select>
          </label>
          <label class="mapping-filter-field"><span>Curriculum Version</span>
            <select id="programAssignmentVersion" disabled><option value="">Select a curriculum first</option></select>
          </label>
          <label class="mapping-filter-field"><span>Year Level</span>
            <select id="programAssignmentYear" disabled><option value="">All stored values</option></select>
          </label>
          <label class="mapping-filter-field"><span>Semester / Term</span>
            <select id="programAssignmentSemester" disabled><option value="">All stored values</option></select>
          </label>
          <label class="mapping-filter-field mapping-search-field"><span>Subject Search</span>
            <span class="mapping-input-with-icon"><i class="fa-solid fa-magnifying-glass"></i>
              <input type="search" id="programAssignmentSearch" maxlength="100"
                     placeholder="Select an academic program first" disabled/>
            </span>
          </label>
        </div>
      </section>

      <section class="domain-card mapping-results-card" aria-labelledby="programAssignmentsTitle">
        <div class="domain-card-header">
          <div>
            <p class="eyebrow">Authoritative curriculum placements</p>
            <h2 id="programAssignmentsTitle">Program Subject Assignments</h2>
            <p class="mapping-context-message" id="programAssignmentContext">Select an academic program to view its assigned subjects.</p>
          </div>
          <div class="card-header-actions">
            <span class="record-count" id="programAssignmentCount">0 records</span>
            <?php if ($canManageAssignments): ?>
            <button type="button" class="primary-action-button" id="addProgramAssignmentButton" disabled>
              <i class="fa-solid fa-plus"></i> Add Subject Assignment
            </button>
            <?php endif; ?>
          </div>
        </div>
        <div class="mapping-results-toolbar">
          <span class="placement-note">Required/optional remains a curriculum placement indicator only.</span>
          <label class="select-control compact-select"><span>Rows</span>
            <select id="programAssignmentPerPage"><option>10</option><option selected>20</option><option>50</option></select>
          </label>
        </div>
        <div class="table-scroll">
          <table class="domain-table program-assignment-table">
            <thead><tr>
              <th>Subject Code</th><th>Subject Title</th><th>Academic Program</th><th>Program Type</th>
              <th>Curriculum</th><th>Version</th><th>Year Level</th><th>Semester / Term</th>
              <th>Sequence</th><th>Placement</th><th>Program Usage</th>
              <?php if ($canManageAssignments): ?><th>Actions</th><?php endif; ?>
            </tr></thead>
            <tbody id="programAssignmentRows"><tr><td colspan="<?= $programColumnCount ?>" class="state-cell">Select an academic program to view its assigned subjects.</td></tr></tbody>
          </table>
        </div>
        <div class="pagination-bar" id="programAssignmentPagination"></div>
      </section>
    </section>

    <?php else: ?>
    <section id="subjectCentricPanel" class="program-assignment-panel">
      <section class="domain-card mapping-filter-card" aria-labelledby="subjectUsageFiltersTitle">
        <div class="domain-card-header">
          <div>
            <p class="eyebrow">Subject-centric lookup</p>
            <h2 id="subjectUsageFiltersTitle">Which academic programs use this subject?</h2>
          </div>
          <button type="button" class="button-muted" id="clearSubjectUsageFilters"><i class="fa-solid fa-rotate-left"></i> Clear Filters</button>
        </div>
        <div class="mapping-filter-grid subject-usage-filter-grid">
          <label class="mapping-filter-field mapping-search-field"><span>Subject Search</span>
            <span class="mapping-input-with-icon"><i class="fa-solid fa-magnifying-glass"></i>
              <input type="search" id="subjectUsageSearch" maxlength="100" placeholder="Subject code or title"/>
            </span>
          </label>
          <label class="mapping-filter-field"><span>Academic Program</span>
            <select id="subjectUsageProgram" disabled><option value="">Loading academic programs…</option></select>
          </label>
          <label class="mapping-filter-field"><span>Curriculum</span>
            <select id="subjectUsageCurriculum" disabled><option value="">Select an academic program first</option></select>
          </label>
          <label class="mapping-filter-field"><span>Curriculum Version</span>
            <select id="subjectUsageVersion" disabled><option value="">Select a curriculum first</option></select>
          </label>
        </div>
      </section>

      <section class="domain-card mapping-results-card" aria-labelledby="subjectUsageTitle">
        <div class="domain-card-header">
          <div><p class="eyebrow">Derived academic-program usage</p><h2 id="subjectUsageTitle">Subject Program Usage</h2>
            <p class="mapping-context-message" id="subjectUsageContext">Showing bounded subject assignments across all academic programs.</p>
          </div>
          <span class="record-count" id="subjectUsageCount">0 records</span>
        </div>
        <div class="mapping-results-toolbar">
          <span class="placement-note">Usage counts are derived from stored curriculum placements; they are not subject classifications.</span>
          <label class="select-control compact-select"><span>Rows</span>
            <select id="subjectUsagePerPage"><option>10</option><option selected>20</option><option>50</option></select>
          </label>
        </div>
        <div class="table-scroll">
          <table class="domain-table subject-usage-table">
            <thead><tr>
              <th>Subject Code</th><th>Subject Title</th><th>Academic Program</th><th>Program Type</th>
              <th>Curriculum</th><th>Version</th><th>Year Level</th><th>Semester / Term</th><th>Program Usage</th>
              <?php if ($canManageAssignments): ?><th>Actions</th><?php endif; ?>
            </tr></thead>
            <tbody id="subjectUsageRows"><tr><td colspan="<?= $subjectColumnCount ?>" class="state-cell">Loading subject program usage…</td></tr></tbody>
          </table>
        </div>
        <div class="pagination-bar" id="subjectUsagePagination"></div>
      </section>
    </section>
    <?php endif; ?>
  </main>

  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<?php if ($canManageAssignments): ?>
<?php if ($isProgramView): ?>
<div class="curriculum-modal" id="addProgramAssignmentModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="addProgramAssignmentTitle">
    <div class="modal-header"><div><h2 id="addProgramAssignmentTitle">Add Subject Assignment</h2><p class="modal-context" id="addProgramAssignmentContext"></p></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close add subject assignment form"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form class="domain-form" id="addProgramAssignmentForm">
      <input type="hidden" name="curriculum_version_id" id="addProgramAssignmentVersionId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <div class="form-field form-field-wide">
          <label for="programAssignmentSubjectSearch">Subject <strong>*</strong></label>
          <div class="record-autocomplete">
            <input type="search" id="programAssignmentSubjectSearch" maxlength="100" placeholder="Search all subjects by code or title" required/>
            <input type="hidden" name="subject_id" id="programAssignmentSubjectId"/>
            <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear subject" title="Clear selection" hidden><i class="fa-solid fa-xmark"></i></button>
            <div class="record-autocomplete-options" id="programAssignmentSubjectOptions" role="listbox" aria-label="Available subject suggestions" hidden></div>
          </div>
          <small id="programAssignmentSubjectHint" role="status">Only subjects available for this curriculum version are shown.</small>
        </div>
        <label class="form-field"><span>Year Level</span><select name="year_level" id="addProgramAssignmentYear"><option value="">Not set</option></select></label>
        <label class="form-field"><span>Semester / Term</span><select name="semester" id="addProgramAssignmentSemester"><option value="">Not set</option></select></label>
        <label class="form-field"><span>Sequence Order</span><input type="number" name="sequence_order" min="0" step="1"/></label>
        <label class="form-field"><span>Placement Indicator <strong>*</strong></span><select name="is_required" required><option value="1">Required Placement</option><option value="0">Optional Placement</option></select><small>This does not add the subject to an elective group.</small></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Add Assignment</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<div class="curriculum-modal" id="editProgramAssignmentModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="editProgramAssignmentTitle">
    <div class="modal-header"><div><h2 id="editProgramAssignmentTitle">Edit Subject Assignment</h2><p class="modal-context" id="editProgramAssignmentContext"></p></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close edit subject assignment form"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form class="domain-form" id="editProgramAssignmentForm">
      <input type="hidden" name="id" id="editProgramAssignmentId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="mapping-fixed-context" role="note"><div><span>Subject</span><strong id="editProgramAssignmentSubject"></strong></div><div><span>Curriculum Version</span><strong id="editProgramAssignmentVersion"></strong></div></div>
      <div class="form-grid">
        <label class="form-field"><span>Year Level</span><select name="year_level" id="editProgramAssignmentYear"><option value="">Not set</option></select></label>
        <label class="form-field"><span>Semester / Term</span><select name="semester" id="editProgramAssignmentSemester"><option value="">Not set</option></select></label>
        <label class="form-field"><span>Sequence Order</span><input type="number" name="sequence_order" id="editProgramAssignmentSequence" min="0" step="1"/></label>
        <label class="form-field"><span>Placement Indicator <strong>*</strong></span><select name="is_required" id="editProgramAssignmentRequired" required><option value="1">Required Placement</option><option value="0">Optional Placement</option></select></label>
      </div>
      <p class="mapping-edit-note">Subject and curriculum version remain fixed. Moving an assignment requires an explicit remove and add operation.</p>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Save Changes</button></div>
    </form>
  </section>
</div>

<div class="curriculum-modal" id="removeProgramAssignmentModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-small" role="alertdialog" aria-modal="true" aria-labelledby="removeProgramAssignmentTitle">
    <div class="modal-header"><div><h2 id="removeProgramAssignmentTitle">Remove Subject Assignment?</h2></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close removal confirmation"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="confirmation-body"><p id="removeProgramAssignmentMessage"></p><p class="assignment-dependency-warning" id="programAssignmentDependencyWarning" hidden></p><div class="form-error" role="alert" hidden></div></div>
    <div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="button" class="button-danger" id="confirmRemoveProgramAssignment">Remove Assignment</button></div>
  </section>
</div>
<?php endif; ?>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $curriculumCommonVersion ?>"></script>
<script src="../assets/program-assignment.js?v=<?= $assignmentScriptVersion ?>"></script>
</body>
</html>
