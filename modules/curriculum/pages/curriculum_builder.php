<?php
require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}

// Renderer for the Academic Programs page. The classic Curricula list is retired: curricula.php redirects to the Builder.
// A direct request to this file is the Curriculum Builder workspace.
$curriculumWorkspacePage = $curriculumWorkspacePage ?? null;
if ($curriculumWorkspacePage === null) {
    require __DIR__ . '/builder_workspace.php';
    exit;
}

$isProgramPage = $curriculumWorkspacePage === 'programs';
if (!$isProgramPage) {
    http_response_code(404);
    exit('Page not found.');
}

require_permission('program.view');

$programCategory = $isProgramPage ? (string)($_GET['category'] ?? '') : '';
if ($isProgramPage && !in_array($programCategory, ['', 'college', 'shs'], true)) {
    http_response_code(400);
    exit('Invalid academic program category.');
}

$userId = current_user_id();
$canViewPrograms = $userId !== null && user_can($userId, 'program.view');
$canViewCurricula = $userId !== null && user_can($userId, 'curriculum.view');
$canCreatePrograms = $isProgramPage && $userId !== null && user_can($userId, 'program.create');
$canUpdatePrograms = $userId !== null && user_can($userId, 'program.update');
$canViewSubjects = $userId !== null && user_can($userId, 'subject.view');
$canExportCurricula = $userId !== null && user_can($userId, 'curriculum_export.view');
$canAttachVersionReferences = $userId !== null && user_can($userId, 'curriculum.reference.attach');
$canClassifyReference = $userId !== null && user_can($userId, 'curriculum.reference.classify');
$canCloneReference = $userId !== null && user_can($userId, 'curriculum.version.clone');
$canSubmitReview = $userId !== null && user_can($userId, 'curriculum.review.submit');
$canDecideReview = $userId !== null && user_can($userId, 'curriculum.review.decide');
$programColumnCount = 5 + ($canUpdatePrograms ? 1 : 0);
$pageTitle = 'Academic Programs';
$pageIcon = 'fa-graduation-cap';
$pageSubtitle = 'College programs and Senior High School strands.';
$avatarInitial = strtoupper(substr((string)($_SESSION['first_name'] ?? 'U'), 0, 1));
$curriculumCssVersion = substr(hash_file('sha256', __DIR__ . '/../assets/curriculum.css'), 0, 16);
$typographyCssVersion = (int)filemtime(__DIR__ . '/../../../css/csms-typography.css');
$curriculumCommonVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-common.js');
$curriculumBuilderVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-builder.js');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../../../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> - BCP Student Management System</title>
  <link rel="stylesheet" href="../../../css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/curriculum.css?v=<?= $curriculumCssVersion ?>"/>
  <link rel="stylesheet" href="../../../css/csms-typography.css?v=<?= $typographyCssVersion ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../../../';
$ACTIVE_NAV = 'academic-programs';
require __DIR__ . '/../../../shared/sidebar.php';
?>

<div class="main">
  <?php require __DIR__ . '/../../../shared/topbar.php'; ?>

  <main class="content curriculum-content" id="curriculumWorkspace"
        data-workspace-page="programs"
        data-program-category="<?= htmlspecialchars($programCategory, ENT_QUOTES, 'UTF-8') ?>"
        data-program-api="../http/program_actions.php"
        data-subject-api="../http/subject_actions.php"
        data-curriculum-api="../http/curriculum_actions.php"
        data-version-api="../http/version_actions.php"
        data-assignment-api="../http/assignment_actions.php"
        data-version-reference-api="../http/version_reference_actions.php"
        data-compliance-api="../http/compliance_actions.php"
        data-can-attach-references="<?= $canAttachVersionReferences ? '1' : '0' ?>"
        data-can-classify-reference="<?= $canClassifyReference ? '1' : '0' ?>"
        data-can-clone-reference="<?= $canCloneReference ? '1' : '0' ?>"
        data-can-view-programs="<?= $canViewPrograms ? '1' : '0' ?>"
        data-can-view-curricula="<?= $canViewCurricula ? '1' : '0' ?>"
        data-can-create-programs="<?= $canCreatePrograms ? '1' : '0' ?>"
        data-can-update-programs="<?= $canUpdatePrograms ? '1' : '0' ?>"
        data-can-create-versions="<?= $canCreateVersions ? '1' : '0' ?>">
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <h1 class="page-title"><i class="fa-solid <?= $pageIcon ?>" aria-hidden="true"></i> <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-subtitle"><?= htmlspecialchars($pageSubtitle, ENT_QUOTES, 'UTF-8') ?></p>
      </div>
      <?php if ($isProgramPage && $canCreatePrograms): ?><button type="button" class="primary-action-button manuscript-primary" id="newProgramButton"><i class="fa-solid fa-plus" aria-hidden="true"></i> New Program</button><?php endif; ?>
    </div>

    <section class="workspace-grid workspace-grid-single" aria-label="<?= $isProgramPage ? 'Academic program records' : 'Curriculum records' ?>">
      <?php if ($isProgramPage): ?>
      <article class="domain-card" id="programPanel">
        <span class="sr-only" id="programCount" aria-live="polite"></span>
        <div class="program-tabs" role="group" aria-label="Level">
          <?php foreach (['' => 'All', 'college' => 'College', 'shs' => 'Senior High'] as $tabValue => $tabLabel): ?>
          <button type="button" class="program-tab" data-program-tab="<?= $tabValue ?>" aria-pressed="<?= $programCategory === $tabValue ? 'true' : 'false' ?>"><?= $tabLabel ?> <span class="program-tab__count" data-program-count="<?= $tabValue ?>" hidden></span></button>
          <?php endforeach; ?>
        </div>
        <form class="list-toolbar" id="programSearchForm" role="search">
          <label class="search-control">
            <span class="sr-only">Search academic programs</span>
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input type="search" id="programSearch" placeholder="Search programs..." maxlength="100"/>
          </label>
          <label class="select-control" id="programDegreeLevelControl"<?= $programCategory === 'shs' ? ' hidden' : '' ?>><span class="sr-only">Degree level</span><select id="programDegreeLevelFilter"><option value="">All degree levels</option><option value="Bachelor&#39;s Degree">Bachelor's Degree</option><option value="Associate Degree">Associate Degree</option><option value="Diploma / Certificate">Diploma / Certificate</option><option value="Other">Other</option></select></label>
          <label class="select-control">
            <span class="sr-only">Program status</span>
            <select id="programStatus">
              <option value="">All statuses</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </label>
        </form>
        <div class="table-scroll">
          <table class="domain-table">
            <thead><tr><th>Code</th><th>Program / Strand</th><th>Degree Level</th><th class="num">Curricula</th><th>Status</th><?php if ($canUpdatePrograms): ?><th><span class="th-long">Actions</span><span class="th-short">More</span></th><?php endif; ?></tr></thead>
            <tbody id="programRows"><tr><td colspan="<?= $programColumnCount ?>" class="state-cell">Loading academic programs…</td></tr></tbody>
          </table>
        </div>
        <div class="pagination-bar" id="programPagination"></div>
      </article>
      <?php endif; ?>
    </section>

  </main>

  <div class="footer">Bestlink College of the Philippines &middot; CSMS-SMS2 &copy; 2026</div>
</div>

<?php if ($isProgramPage): ?>
<div class="curriculum-modal record-details-modal program-record-modal" id="programDetailsModal" hidden aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-large modal-dialog-scroll" role="dialog" aria-modal="true" aria-labelledby="programDetailsName">
    <div class="modal-header">
      <div class="program-record__identity">
        <p class="program-record__code" id="programDetailsCode"></p>
        <h2 id="programDetailsName">Program Details</h2>
        <p class="program-record__meta"><span id="programDetailsType"></span> <span id="programDetailsStatusBadge"></span></p>
      </div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close academic program details"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>
    <div class="modal-body program-record__body">
      <?php if ($canViewCurricula): ?>
      <dl class="program-record__summary">
        <div><dt>Curricula</dt><dd id="programCurriculaTotal">—</dd></div>
        <div><dt>Versions</dt><dd id="programVersionsTotal">—</dd></div>
        <div><dt>Subject placements</dt><dd id="programPlacementsTotal">—</dd></div>
      </dl>
      <p id="programSummaryNotice" class="curriculum-notice" role="status" hidden></p>
      <p class="program-record__label program-record__label--section">Curricula</p>
      <div id="programRecordCurricula" aria-live="polite"></div>
      <?php else: ?>
      <p class="subject-placement-detail">Curricula are not available with your current permissions.</p>
      <?php endif; ?>
    </div>
    <div class="modal-footer program-details-footer">
      <?php if ($canUpdatePrograms): ?><button type="button" class="button-primary" id="programDetailsEdit"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit</button><button type="button" class="button-danger" id="programDetailsArchive"><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Archive</button><?php endif; ?>
      <button type="button" class="button-muted" data-modal-close>Close</button>
    </div>
  </section>
</div>
<?php if ($canUpdatePrograms): ?>
<div class="curriculum-modal" id="programArchiveModal" hidden aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog program-archive-dialog" role="dialog" aria-modal="true" aria-labelledby="programArchiveTitle">
    <div class="modal-header"><div><h2 id="programArchiveTitle">Archive Record</h2></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close archive confirmation"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="confirmation-body"><p id="programArchiveMessage">Are you sure you want to archive this academic program?</p><p class="program-archive-note">The record will be removed from active views but retained in Archives. Existing related curriculum records will be preserved.</p></div>
    <div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="button" class="button-danger" id="confirmProgramArchive">Archive</button></div>
  </section>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($isProgramPage && ($canCreatePrograms || $canUpdatePrograms)): ?>
<div class="curriculum-modal" id="programModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="programModalTitle">
    <div class="modal-header">
      <div><h2 id="programModalTitle">New Program</h2><p class="modal-context" id="programModalContext">Create an academic program.</p></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close academic program form"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>
    <form id="programForm" class="domain-form">
      <input type="hidden" name="id" id="programId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field"><span>Program Code <strong>*</strong></span><input name="program_code" id="programCode" maxlength="60" placeholder="e.g. BSIT" required/></label>
        <label class="form-field"><span>Degree Level <strong>*</strong></span>
          <select id="programTypeLevel" required>
            <option value="">Select degree level</option>
            <option value="Bachelor's Degree">Bachelor's Degree</option>
            <option value="Associate Degree">Associate Degree</option>
            <option value="Diploma / Certificate">Diploma / Certificate</option>
            <option value="Senior High School">Senior High School</option>
            <option value="Other">Other</option>
          </select>
          <input type="hidden" name="program_type" id="programType"/>
          <input type="text" id="programTypeOther" maxlength="100" placeholder="Specify degree level" aria-label="Specify degree level" hidden/>
        </label>
        <label class="form-field form-field-wide"><span>Program Name <strong>*</strong></span><input name="program_name" id="programName" maxlength="255" placeholder="Full program name" required/></label>
        <label class="form-field"><span>Status <strong>*</strong></span><select name="status" id="programFormStatus" required><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary" id="programSubmitButton">Save Program</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $curriculumCommonVersion ?>"></script>
<script src="../assets/placement-groups.js?v=<?= (int)filemtime(__DIR__ . '/../assets/placement-groups.js') ?>"></script>
<script src="../assets/curriculum-builder.js?v=<?= $curriculumBuilderVersion ?>"></script>
<script src="../assets/curriculum-review.js?v=<?= (int)filemtime(__DIR__ . '/../assets/curriculum-review.js') ?>"></script>
</body>
</html>
