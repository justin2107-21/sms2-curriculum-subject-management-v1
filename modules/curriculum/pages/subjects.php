<?php
require_once __DIR__ . '/../../../shared/bootstrap.php';
if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('subject.view');

$userId = current_user_id();
$canCreateSubjects = $userId !== null && user_can($userId, 'subject.create');
$canUpdateSubjects = $userId !== null && user_can($userId, 'subject.update');
$canViewPlacementContext = $userId !== null && user_can($userId, 'curriculum.view');
$subjectColumnCount = $canUpdateSubjects ? 7 : 6;
$avatarInitial = strtoupper(substr((string)($_SESSION['first_name'] ?? 'U'), 0, 1));
$curriculumCssVersion = (int)filemtime(__DIR__ . '/../assets/curriculum.css');
$typographyCssVersion = (int)filemtime(__DIR__ . '/../../../css/csms-typography.css');
$curriculumCommonVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-common.js');
$subjectsScriptVersion = (int)filemtime(__DIR__ . '/../assets/subjects.js');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../../../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <title>Subject Master - BCP Student Management System</title>
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

  <main class="content curriculum-content" id="subjectWorkspace"
        data-subject-api="../http/subject_actions.php"
        data-can-create-subjects="<?= $canCreateSubjects ? '1' : '0' ?>"
        data-can-update-subjects="<?= $canUpdateSubjects ? '1' : '0' ?>">
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <h1 class="page-title"><i class="fa-solid fa-book"></i> Subject Master</h1>
        <p class="page-subtitle">Browse authoritative subject master records available to curriculum versions.</p>
      </div>
      <span class="read-only-pill"><i class="fa-solid fa-pen-to-square"></i> Administrative workspace</span>
    </div>

    <article class="domain-card subject-master-card">
      <div class="domain-card-header">
        <div><p class="eyebrow">Master records</p><h2>Subjects</h2></div>
        <div class="card-header-actions">
          <span class="record-count" id="subjectCount">0 records</span>
          <?php if ($canCreateSubjects): ?>
          <button type="button" class="primary-action-button" id="newSubjectButton"><i class="fa-solid fa-plus"></i> New Subject</button>
          <?php endif; ?>
        </div>
      </div>
      <form class="list-toolbar" id="subjectSearchForm" role="search">
        <label class="search-control">
          <span class="sr-only">Search subjects</span>
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="search" id="subjectSearch" placeholder="Subject code or title" maxlength="100"/>
        </label>
        <label class="select-control">
          <span class="sr-only">Subject status</span>
          <select id="subjectStatus">
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </label>
        <label class="select-control compact-select">
          <span>Rows</span>
          <select id="subjectPerPage"><option>10</option><option selected>20</option><option>50</option></select>
        </label>
      </form>
      <?php if ($canViewPlacementContext): ?>
      <div class="subject-context-toolbar" id="subjectPlacementContext">
        <label class="form-field"><span>Curriculum Version</span><select id="subjectVersionFilter"><option value="">All curriculum versions</option></select></label>
        <button type="button" class="button-muted" id="subjectMoreVersions" hidden>More versions</button>
        <label class="form-field"><span>Year Level</span><select id="subjectYearFilter"><option value="">All year levels</option></select></label>
        <label class="form-field"><span>Semester</span><select id="subjectSemesterFilter"><option value="">All semesters</option></select></label>
        <p class="subtle-text" id="subjectContextNote" role="status">Filters use curriculum placements. Each Subject Master record appears only once.</p>
      </div>
      <?php endif; ?>
      <div class="table-scroll">
        <table class="domain-table subject-table">
          <thead><tr><th>Subject Code</th><th>Subject Title</th><th>Units</th><th>Lecture Units</th><th>Laboratory Units</th><th>Technical Status</th><?php if ($canUpdateSubjects): ?><th>Actions</th><?php endif; ?></tr></thead>
          <tbody id="subjectRows"><tr><td colspan="<?= $subjectColumnCount ?>" class="state-cell">Loading subjects…</td></tr></tbody>
        </table>
      </div>
      <div class="pagination-bar" id="subjectPagination"></div>
    </article>
  </main>

  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<?php if ($canCreateSubjects || $canUpdateSubjects): ?>
<div class="curriculum-modal" id="subjectModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-wide" role="dialog" aria-modal="true" aria-labelledby="subjectModalTitle">
    <div class="modal-header">
      <div><h2 id="subjectModalTitle">New Subject</h2></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close subject form"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="subjectForm" class="domain-form">
      <input type="hidden" name="id" id="subjectId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field"><span>Subject Code <strong>*</strong></span><input name="subject_code" id="subjectCode" maxlength="60" required/></label>
        <label class="form-field"><span>Subject Title <strong>*</strong></span><input name="subject_title" id="subjectTitle" maxlength="255" required/></label>
        <label class="form-field form-field-wide"><span>Description</span><textarea name="description" id="subjectDescription" rows="3"></textarea></label>
        <label class="form-field"><span>Total Units <strong>*</strong></span><input type="number" name="units" id="subjectUnits" min="0" max="9999.99" step="0.01" required/></label>
        <label class="form-field"><span>Lecture Units</span><input type="number" name="lecture_units" id="subjectLectureUnits" min="0" max="9999.99" step="0.01"/></label>
        <label class="form-field"><span>Laboratory Units</span><input type="number" name="laboratory_units" id="subjectLaboratoryUnits" min="0" max="9999.99" step="0.01"/></label>
        <label class="form-field"><span>Technical Status <strong>*</strong></span><select name="status" id="subjectFormStatus" required><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary" id="subjectSubmitButton">Create Subject</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $curriculumCommonVersion ?>"></script>
<script src="../assets/subjects.js?v=<?= $subjectsScriptVersion ?>"></script>
</body>
</html>
