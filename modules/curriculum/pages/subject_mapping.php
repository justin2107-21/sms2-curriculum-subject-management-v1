<?php
require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('curriculum.view');

$userId = current_user_id();
$canViewPrograms = $userId !== null && user_can($userId, 'program.view');
$canViewSubjects = $userId !== null && user_can($userId, 'subject.view');
$canManageMappings = $userId !== null && user_can($userId, 'curriculum.update');
$canAddMappings = $canManageMappings && $canViewSubjects;
$mappingColumnCount = $canManageMappings ? 10 : 9;
$avatarInitial = strtoupper(substr((string)($_SESSION['first_name'] ?? 'U'), 0, 1));
$curriculumCssVersion = (int)filemtime(__DIR__ . '/../assets/curriculum.css');
$typographyCssVersion = (int)filemtime(__DIR__ . '/../../../css/csms-typography.css');
$mappingCssVersion = (int)filemtime(__DIR__ . '/../assets/subject-mapping.css');
$curriculumCommonVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-common.js');
$mappingScriptVersion = (int)filemtime(__DIR__ . '/../assets/subject-mapping.js');
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
        data-can-view-programs="<?= $canViewPrograms ? '1' : '0' ?>"
        data-can-view-subjects="<?= $canViewSubjects ? '1' : '0' ?>"
        data-can-manage-mappings="<?= $canManageMappings ? '1' : '0' ?>"
        data-can-add-mappings="<?= $canAddMappings ? '1' : '0' ?>">
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <h1 class="page-title"><i class="fa-solid fa-diagram-project"></i> Subject Mapping</h1>
        <p class="page-subtitle">View and manage subject placements across curricula and curriculum versions.</p>
      </div>
      <span class="read-only-pill"><i class="fa-solid fa-link"></i> Curriculum placements</span>
    </div>

    <section class="domain-card mapping-filter-card" aria-labelledby="mappingFiltersTitle">
      <div class="domain-card-header">
        <div>
          <p class="eyebrow">Progressive filters</p>
          <h2 id="mappingFiltersTitle">Mapping Context</h2>
        </div>
        <button type="button" class="button-muted" id="clearMappingFilters">
          <i class="fa-solid fa-rotate-left"></i> Clear Filters
        </button>
      </div>
      <div class="mapping-filter-grid">
        <label class="mapping-filter-field">
          <span>Academic Program</span>
          <select id="mappingProgramFilter" <?= $canViewPrograms ? '' : 'disabled' ?>>
            <option value="">All available programs</option>
          </select>
        </label>
        <label class="mapping-filter-field">
          <span>Curriculum</span>
          <select id="mappingCurriculumFilter">
            <option value="">All available curricula</option>
          </select>
        </label>
        <label class="mapping-filter-field">
          <span>Curriculum Version</span>
          <select id="mappingVersionFilter" disabled>
            <option value="">Select a curriculum first</option>
          </select>
        </label>
        <label class="mapping-filter-field">
          <span>Year Level</span>
          <select id="mappingYearFilter">
            <option value="">All stored values</option>
          </select>
        </label>
        <label class="mapping-filter-field">
          <span>Semester / Term</span>
          <select id="mappingSemesterFilter">
            <option value="">All stored values</option>
          </select>
        </label>
        <label class="mapping-filter-field mapping-search-field">
          <span>Subject Search</span>
          <span class="mapping-input-with-icon">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" id="mappingSubjectSearch" maxlength="100" placeholder="Subject code or title"/>
          </span>
        </label>
      </div>
    </section>

    <section class="domain-card mapping-results-card" aria-labelledby="mappingResultsTitle">
      <div class="domain-card-header">
        <div>
          <p class="eyebrow">Stored curriculum relationships</p>
          <h2 id="mappingResultsTitle">Subject Mappings</h2>
          <p class="mapping-context-message" id="mappingContextMessage">Showing all available subject placements.</p>
        </div>
        <div class="card-header-actions">
          <span class="record-count" id="mappingCount">0 records</span>
          <?php if ($canAddMappings): ?>
          <button type="button" class="primary-action-button" id="addMappingButton" disabled>
            <i class="fa-solid fa-plus"></i> Add Subject Mapping
          </button>
          <?php endif; ?>
        </div>
      </div>
      <div class="mapping-results-toolbar">
        <span class="placement-note">Required/optional is a placement indicator only; it is not an elective-group classification.</span>
        <label class="select-control compact-select">
          <span>Rows</span>
          <select id="mappingPerPage"><option>10</option><option selected>20</option><option>50</option></select>
        </label>
      </div>
      <div class="table-scroll">
        <table class="domain-table mapping-table">
          <thead>
            <tr>
              <th>Subject Code</th>
              <th>Subject Title</th>
              <th>Academic Program</th>
              <th>Curriculum</th>
              <th>Version</th>
              <th>Year Level</th>
              <th>Semester / Term</th>
              <th>Sequence</th>
              <th>Placement</th>
              <?php if ($canManageMappings): ?><th>Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody id="mappingRows">
            <tr><td colspan="<?= $mappingColumnCount ?>" class="state-cell">Loading subject mappings…</td></tr>
          </tbody>
        </table>
      </div>
      <div class="pagination-bar" id="mappingPagination"></div>
    </section>
  </main>

  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<?php if ($canAddMappings): ?>
<div class="curriculum-modal" id="addMappingModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="addMappingTitle">
    <div class="modal-header">
      <div>
        <h2 id="addMappingTitle">Add Subject Mapping</h2>
        <p class="modal-context" id="addMappingContext"></p>
      </div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close add subject mapping form"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form class="domain-form" id="addMappingForm">
      <input type="hidden" name="curriculum_version_id" id="addMappingVersionId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field form-field-wide"><span>Find Subject</span><input type="search" id="mappingSubjectSelectorSearch" maxlength="100" placeholder="Search by subject code or title"/></label>
        <label class="form-field form-field-wide"><span>Subject <strong>*</strong></span><select name="subject_id" id="mappingSubjectId" required><option value="">Select a Subject Master record</option></select></label>
        <label class="form-field"><span>Year Level</span><input name="year_level" maxlength="50" placeholder="Stored as a flexible label"/></label>
        <label class="form-field"><span>Semester / Term</span><input name="semester" maxlength="50" placeholder="Stored as a flexible label"/></label>
        <label class="form-field"><span>Sequence Order</span><input type="number" name="sequence_order" min="0" step="1"/></label>
        <label class="form-field"><span>Placement Indicator <strong>*</strong></span><select name="is_required" required><option value="1">Required Placement</option><option value="0">Optional Placement</option></select><small>This does not create an elective group.</small></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Add Mapping</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<?php if ($canManageMappings): ?>
<div class="curriculum-modal" id="editMappingModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="editMappingTitle">
    <div class="modal-header">
      <div><h2 id="editMappingTitle">Edit Subject Mapping</h2><p class="modal-context" id="editMappingContext"></p></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close edit subject mapping form"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form class="domain-form" id="editMappingForm">
      <input type="hidden" name="id" id="editMappingId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="mapping-fixed-context" role="note">
        <div><span>Subject</span><strong id="editMappingSubject"></strong></div>
        <div><span>Curriculum Version</span><strong id="editMappingVersion"></strong></div>
      </div>
      <div class="form-grid">
        <label class="form-field"><span>Year Level</span><input name="year_level" id="editMappingYear" maxlength="50"/></label>
        <label class="form-field"><span>Semester / Term</span><input name="semester" id="editMappingSemester" maxlength="50"/></label>
        <label class="form-field"><span>Sequence Order</span><input type="number" name="sequence_order" id="editMappingSequence" min="0" step="1"/></label>
        <label class="form-field"><span>Placement Indicator <strong>*</strong></span><select name="is_required" id="editMappingRequired" required><option value="1">Required Placement</option><option value="0">Optional Placement</option></select></label>
      </div>
      <p class="mapping-edit-note">Subject and curriculum version stay fixed. Moving a subject requires removing this placement and creating a new one.</p>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Save Changes</button></div>
    </form>
  </section>
</div>

<div class="curriculum-modal" id="removeMappingModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-small" role="alertdialog" aria-modal="true" aria-labelledby="removeMappingTitle">
    <div class="modal-header">
      <div><h2 id="removeMappingTitle">Remove Subject Mapping?</h2></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close removal confirmation"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="confirmation-body">
      <p id="removeMappingMessage">This removes only the curriculum placement. The Subject Master record and curriculum records remain.</p>
      <div class="form-error" role="alert" hidden></div>
    </div>
    <div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="button" class="button-danger" id="confirmRemoveMapping">Remove Mapping</button></div>
  </section>
</div>
<?php endif; ?>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $curriculumCommonVersion ?>"></script>
<script src="../assets/subject-mapping.js?v=<?= $mappingScriptVersion ?>"></script>
</body>
</html>
