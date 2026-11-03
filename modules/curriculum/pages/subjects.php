<?php
require_once __DIR__ . '/../../../shared/bootstrap.php';
if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('subject.view');

$userId = current_user_id();
$requestedProgramId = trim((string)($_GET['program_id'] ?? ''));
$selectedProgramId = null;
if ($requestedProgramId !== '') {
    $selectedProgramId = filter_var($requestedProgramId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($selectedProgramId === false) {
        http_response_code(400);
        exit('Invalid academic program.');
    }
    $selectedProgramId = (int)$selectedProgramId;
    require_permission('program.view');
    require_permission('curriculum.view');
}
$canBrowsePrograms = $userId !== null && user_can($userId, 'program.view') && user_can($userId, 'curriculum.view');
$canCreateSubjects = $userId !== null && user_can($userId, 'subject.create');
$canUpdateSubjects = $userId !== null && user_can($userId, 'subject.update');
$canViewPlacementContext = $userId !== null && user_can($userId, 'curriculum.view');
$canAssignCreatedSubject = $canCreateSubjects
  && $canViewPlacementContext
  && $userId !== null
  && user_can($userId, 'program.view')
  && user_can($userId, 'curriculum.update');
$canExportCurricula = $userId !== null && user_can($userId, 'curriculum_export.view');
$isProgramView = $selectedProgramId !== null;
$avatarInitial = strtoupper(substr((string)($_SESSION['first_name'] ?? 'U'), 0, 1));
$curriculumCssVersion = (int)filemtime(__DIR__ . '/../assets/curriculum.css');
$typographyCssVersion = (int)filemtime(__DIR__ . '/../../../css/csms-typography.css');
$curriculumCommonVersion = substr(hash_file('sha256', __DIR__ . '/../assets/curriculum-common.js'), 0, 16);
$subjectsRedesignVersion = substr(hash_file('sha256', __DIR__ . '/../assets/subjects-redesign.js'), 0, 16);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../../../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <title><?= $isProgramView ? 'Program Subjects' : 'Subject Master' ?> - BCP Student Management System</title>
  <link rel="stylesheet" href="../../../css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/curriculum.css?v=<?= $curriculumCssVersion ?>"/>
  <link rel="stylesheet" href="../../../css/csms-typography.css?v=<?= $typographyCssVersion ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../../../';
$ACTIVE_NAV = 'subjects';
require __DIR__ . '/../../../shared/sidebar.php';
?>

<div class="main">
  <?php require __DIR__ . '/../../../shared/topbar.php'; ?>

  <main class="content curriculum-content subject-page" id="subjectWorkspace"
        data-subject-api="../http/subject_actions.php"
      data-assignment-api="../http/assignment_actions.php"
      data-program-api="../http/program_actions.php"
      data-curriculum-api="../http/curriculum_actions.php"
      data-version-api="../http/version_actions.php"
      data-selected-program-id="<?= $selectedProgramId ?? '' ?>"
      data-is-program-view="<?= $isProgramView ? '1' : '0' ?>"
      data-can-view-placement-context="<?= $canViewPlacementContext ? '1' : '0' ?>"
      data-can-assign-created-subject="<?= $canAssignCreatedSubject ? '1' : '0' ?>"
        data-can-create-subjects="<?= $canCreateSubjects ? '1' : '0' ?>"
        data-can-update-subjects="<?= $canUpdateSubjects ? '1' : '0' ?>">
    <?php if (!$isProgramView): ?>
    <section class="subject-landing" id="subjectLanding">
      <header class="subject-landing-header"><div><h1 class="page-title">Subject Master</h1><p class="page-subtitle">Browse subjects by program or strand.</p></div></header>
      <div class="subject-landing-controls">
        <label class="search-control subject-card-search"><span class="sr-only">Search programs or strands</span><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" id="subjectCardSearch" placeholder="Search program code or name..." maxlength="100"/></label>
        <nav class="subject-category-tabs" aria-label="Program categories" role="tablist">
          <button type="button" class="is-active" data-category="all" role="tab" aria-selected="true">All</button>
          <button type="button" data-category="college" role="tab" aria-selected="false">College Programs</button>
          <button type="button" data-category="shs" role="tab" aria-selected="false">SHS Strands</button>
        </nav>
      </div>
      <?php if ($canBrowsePrograms): ?><div class="subject-card-sections" id="subjectProgramSections" aria-live="polite"><p class="subtle-text">Loading programs…</p></div>
      <?php else: ?><p class="subject-access-note">Program and placement browsing is not available with your current permissions.</p><?php endif; ?>
    </section>
    <?php else: ?>
    <section class="subject-program-view" id="subjectProgramView">
      <a class="subject-back-link" href="subjects.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to Subject Master</a>
      <div class="subject-program-header"><div><nav class="subject-breadcrumb" aria-label="Program context"><a href="subjects.php">Subject Master</a><span class="breadcrumb-separator" aria-hidden="true">›</span><strong id="subjectProgramBreadcrumb">Program</strong></nav>
        <h1 class="page-title" id="subjectProgramTitle">Loading…</h1><p class="page-subtitle" id="subjectProgramName"></p><p class="subject-program-summary" id="subjectProgramSummary"></p></div>
        <div class="subject-page-actions"><?php if ($canExportCurricula): ?><a class="button-muted subject-export-link" id="subjectProgramExport" href="curriculum_export.php"><i class="fa-solid fa-download" aria-hidden="true"></i> Export</a><?php endif; ?>
          <?php if ($canAssignCreatedSubject): ?><button type="button" class="primary-action-button" id="newSubjectButton"><i class="fa-solid fa-plus" aria-hidden="true"></i> New Subject</button><?php endif; ?></div>
      </div>
      <section class="subject-context-panel" aria-label="Curriculum context">
        <label class="form-field"><span>Curriculum</span><select id="subjectCurriculumSelect"><option value="">Select curriculum</option></select></label>
        <label class="form-field"><span>Version</span><select id="subjectVersionSelect" disabled><option value="">Select version</option></select></label>
      </section>
      <nav class="subject-year-tabs" id="subjectYearTabs" aria-label="Filter by year level"><button type="button" class="is-active" data-year="" aria-pressed="true">All Years</button></nav>
      <section class="subject-list-controls" aria-label="Filter subjects">
        <label class="search-control"><span class="sr-only">Search subject code or title</span><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input id="subjectSearch" type="search" placeholder="Search subject code or title..." maxlength="100"/></label>
        <label class="form-field"><span class="sr-only">Year Level</span><select id="subjectYearFilter"><option value="">All year levels</option></select></label>
        <label class="form-field"><span class="sr-only">Semester</span><select id="subjectSemesterFilter"><option value="">All semesters</option></select></label>
        <label class="form-field"><span class="sr-only">Status</span><select id="subjectStatus"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
      </section>
      <div class="subject-group-list" id="subjectGroupedList" aria-live="polite"><p class="subtle-text">Select a curriculum version to view its subject placements.</p></div>
    </section>
    <?php endif; ?>

    <?php if (false): ?>
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <h1 class="page-title"> Subject Master</h1>
        <p class="page-subtitle">Manage and browse subject records.</p>
      </div>
      <?php if ($canAssignCreatedSubject): ?>
      <button type="button" class="primary-action-button" id="newSubjectButton"> New Subject</button>
      <?php endif; ?>
    </div>

    <section class="subject-filter-panel" aria-label="Search and filters">
      <form class="subject-filter-form" id="subjectSearchForm" role="search">
        <label class="search-control">
          <span class="sr-only">Search subjects</span>

          <input type="search" id="subjectSearch" placeholder="Search subject code or title" maxlength="100"/>
        </label>
        <div class="subject-filter-grid">
          <?php if ($canViewPlacementContext): ?>
          <label class="form-field"><span>Academic Program</span><select id="subjectProgramFilter"><option value="">All programs</option></select></label>
          <label class="form-field"><span>Curriculum</span><select id="subjectCurriculumFilter" disabled><option value="">All curricula</option></select></label>
          <label class="form-field"><span>Version</span><select id="subjectVersionFilter" disabled><option value="">All versions</option></select></label>
          <label class="form-field"><span>Year Level</span><select id="subjectYearFilter" disabled><option value="">All year levels</option></select></label>
          <label class="form-field"><span>Semester</span><select id="subjectSemesterFilter" disabled><option value="">All semesters</option></select></label>
          <?php endif; ?>
          <label class="form-field"><span>Status</span><select id="subjectStatus"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
        </div>
        <?php if ($canViewPlacementContext): ?><button type="button" class="subject-more-versions" id="subjectMoreVersions" hidden>More versions</button><?php endif; ?>
        <label class="select-control compact-select subject-rows-select"><span>Rows</span><select id="subjectPerPage"><option>10</option><option selected>20</option><option>50</option></select></label>
      </form>
      <?php if ($canViewPlacementContext): ?>
      <div class="subject-programs" aria-label="Quick filter by academic program">
        <div class="subject-program-cards" id="subjectProgramCards" role="group" aria-label="Academic programs"><p class="subtle-text">Loading programs…</p></div>
      </div>
      <?php endif; ?>
    </section>

    <article class="domain-card subject-master-card subject-results-card">
      <div class="domain-card-header"><div><h2>Subject Results</h2><p class="subtle-text" id="subjectContextNote" role="status">Each Subject Master record appears once.</p></div><span class="record-count" id="subjectCount">0 records</span></div>
      <div class="table-scroll">
        <table class="domain-table subject-table">
          <thead><tr><th>Subject Code</th><th>Subject Title</th><th>Units</th><th>Program</th><th>Year / Semester</th><th>Technical Status</th><th>Actions</th></tr></thead>
          <tbody id="subjectRows"><tr><td colspan="<?= $subjectColumnCount ?>" class="state-cell">Loading subjects…</td></tr></tbody>
        </table>
      </div>
      <div class="pagination-bar" id="subjectPagination"></div>
    </article>
    <?php endif; ?>
  </main>

  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<div class="curriculum-modal record-details-modal" id="subjectDetailsModal" hidden aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-medium modal-dialog-scroll" role="dialog" aria-modal="true" aria-labelledby="subjectDetailsTitle">
    <div class="modal-header">
      <div><h2 id="subjectDetailsTitle">Subject Code</h2><p class="modal-context" id="subjectDetailsCode"></p></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close subject details"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="subject-detail-chips"><span class="subject-unit-chip" id="subjectDetailsUnits"></span><span id="subjectDetailsStatus"></span></div>
      <section class="subject-usage-section" aria-labelledby="subjectUsageHeading">
        <div class="subject-usage-heading"><h3 id="subjectUsageHeading">Curriculum Usage</h3><span class="record-count" id="subjectUsageCount">0 placements</span></div>
        <p class="subject-placement-detail" id="subjectPlacementDetail" role="status">Loading curriculum usage…</p>
        <ul class="subject-usage-list" id="subjectUsageRows" hidden></ul>
      </section>
      <section class="subject-description-section"><h3>Description</h3><p id="subjectDetailsName"></p></section>
    </div>
    <div class="modal-footer">
      <button type="button" class="button-muted" data-modal-close>Close</button>
      <?php if ($canUpdateSubjects): ?><button type="button" class="button-primary" id="subjectDetailsEdit"><i class="fa-solid fa-pen"></i> Edit Subject</button><button type="button" class="button-danger" id="subjectDetailsArchive" hidden><i class="fa-solid fa-box-archive"></i> Archive</button><?php endif; ?>
    </div>
  </section>
</div>

<?php if ($canAssignCreatedSubject || $canUpdateSubjects): ?>
<?php if ($canUpdateSubjects): ?>
<div class="curriculum-modal" id="subjectArchiveModal" hidden><div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-small" role="alertdialog" aria-modal="true" aria-labelledby="subjectArchiveTitle">
    <div class="modal-header"><h2 id="subjectArchiveTitle">Archive Record</h2><button class="modal-close-button" type="button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="confirmation-body"><p>Are you sure you want to archive this subject?</p><p>The record will be removed from active views but retained in Archives.</p><strong id="subjectArchiveName"></strong><div class="form-error" role="alert" hidden></div></div>
    <div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="button" class="button-danger" id="confirmSubjectArchive">Archive</button></div>
  </section>
</div>
<?php endif; ?>
<div class="curriculum-modal" id="subjectModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-wide" role="dialog" aria-modal="true" aria-labelledby="subjectModalTitle">
    <div class="modal-header">
      <div><h2 id="subjectModalTitle">New Subject</h2><p class="modal-context" id="subjectModalSubtitle">Create the subject record and its curriculum placement together.</p></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close subject form"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="subjectForm" class="domain-form">
      <input type="hidden" name="id" id="subjectId"/>
      <div class="form-error" role="alert" hidden></div>
      <section class="subject-form-section" aria-labelledby="subjectInformationHeading">
      <div class="section-heading subject-form-section-heading"><div><p class="eyebrow">Master record</p><h3 id="subjectInformationHeading">Subject Information</h3></div></div>
      <div class="form-grid">
        <label class="form-field"><span>Subject Code <strong>*</strong></span><input name="subject_code" id="subjectCode" maxlength="60" required/></label>
        <label class="form-field"><span>Subject Title <strong>*</strong></span><input name="subject_title" id="subjectTitle" maxlength="255" required/></label>
        <label class="form-field form-field-wide"><span>Description</span><textarea name="description" id="subjectDescription" rows="3"></textarea></label>
          <label class="form-field"><span>Units <strong>*</strong></span><input type="number" name="units" id="subjectUnits" min="0" max="9999.99" step="0.01" required/></label>
        <label class="form-field"><span>Status <strong>*</strong></span><select name="status" id="subjectFormStatus" required><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
      </div>
      </section>
      <?php if ($canAssignCreatedSubject): ?>
      <section class="subject-assignment-section" id="subjectAssignmentSection" aria-labelledby="subjectAssignmentHeading">
        <fieldset id="subjectAssignmentFields">
          <legend id="subjectAssignmentHeading">Curriculum Placement</legend>
          <div class="form-grid">
            <div class="form-field"><label for="subjectAssignmentProgramInput">Program / Strand <strong>*</strong></label><div class="record-autocomplete">
              <input type="search" id="subjectAssignmentProgramInput" placeholder="Search program code or name" required/>
              <input type="hidden" id="subjectAssignmentProgramId"/>
              <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear academic program" title="Clear selection" hidden><i class="fa-solid fa-xmark"></i></button>
              <div class="record-autocomplete-options" id="subjectAssignmentProgramOptions" role="listbox" aria-label="Academic program suggestions" hidden></div>
            </div></div>
            <div class="form-field"><label for="subjectAssignmentCurriculumInput">Curriculum <strong>*</strong></label><div class="record-autocomplete">
              <input type="search" id="subjectAssignmentCurriculumInput" placeholder="Select a program first" required disabled/>
              <input type="hidden" id="subjectAssignmentCurriculumId"/>
              <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear curriculum" title="Clear selection" hidden><i class="fa-solid fa-xmark"></i></button>
              <div class="record-autocomplete-options" id="subjectAssignmentCurriculumOptions" role="listbox" aria-label="Curriculum suggestions" hidden></div>
            </div></div>
            <div class="form-field"><label for="subjectAssignmentVersionInput">Curriculum Version <strong>*</strong></label><div class="record-autocomplete">
              <input type="search" id="subjectAssignmentVersionInput" placeholder="Select a curriculum first" required disabled/>
              <input type="hidden" name="curriculum_version_id" id="subjectAssignmentVersionId"/>
              <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear curriculum version" title="Clear selection" hidden><i class="fa-solid fa-xmark"></i></button>
              <div class="record-autocomplete-options" id="subjectAssignmentVersionOptions" role="listbox" aria-label="Curriculum version suggestions" hidden></div>
            </div></div>
            <label class="form-field"><span id="subjectAssignmentYearLabel">Year Level <strong>*</strong></span><select id="subjectAssignmentYear" required><option value="">Select year level</option></select></label>
            <label class="form-field"><span>Semester <strong>*</strong></span><select id="subjectAssignmentSemester" required><option value="">Select semester</option></select></label>
            <label class="form-field"><span>Placement <strong>*</strong></span><select name="is_required" id="subjectAssignmentRequired" required><option value="1">Required</option><option value="0">Optional</option></select></label>
          </div>
        </fieldset>
      </section>
      <?php endif; ?>
      <div class="modal-actions">
        <button type="button" class="button-muted" data-modal-close>Cancel</button>
        <button type="submit" class="button-primary" id="subjectSubmitButton">Save Changes</button>
      </div>
    </form>
  </section>
</div>
<?php endif; ?>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $curriculumCommonVersion ?>"></script>
<script src="../assets/subjects-redesign.js?v=<?= $subjectsRedesignVersion ?>"></script>
</body>
</html>
