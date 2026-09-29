<?php
require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}

// Shared renderer for the two Curriculum Builder child pages. Direct requests
// to the historical combined route redirect instead of exposing a duplicate UI.
$curriculumWorkspacePage = $curriculumWorkspacePage ?? null;
if ($curriculumWorkspacePage === null) {
    $legacyUserId = current_user_id();
    $hasCurriculumContext = isset($_GET['curriculum_id'])
        || isset($_GET['id'])
        || (($_GET['view'] ?? '') === 'curricula');

    if ($hasCurriculumContext && $legacyUserId !== null
        && user_can($legacyUserId, 'curriculum.view')) {
        $legacyTarget = 'curricula.php';
    } elseif ($legacyUserId !== null && user_can($legacyUserId, 'program.view')) {
        $legacyTarget = 'academic_programs.php';
    } elseif ($legacyUserId !== null && user_can($legacyUserId, 'curriculum.view')) {
        $legacyTarget = 'curricula.php';
    } else {
        require_permission('curriculum.view');
        exit;
    }

    $legacyQuery = http_build_query($_GET);
    header('Location: ' . $legacyTarget . ($legacyQuery === '' ? '' : '?' . $legacyQuery));
    exit;
}

$isProgramPage = $curriculumWorkspacePage === 'programs';
$isCurriculaPage = $curriculumWorkspacePage === 'curricula';
if (!$isProgramPage && !$isCurriculaPage) {
    http_response_code(404);
    exit('Page not found.');
}

require_permission($isProgramPage ? 'program.view' : 'curriculum.view');

$userId = current_user_id();
$canViewPrograms = $userId !== null && user_can($userId, 'program.view');
$canCreatePrograms = $isProgramPage && $userId !== null && user_can($userId, 'program.create');
$canUpdatePrograms = $isProgramPage && $userId !== null && user_can($userId, 'program.update');
$canCreateCurricula = $isCurriculaPage && $userId !== null && user_can($userId, 'curriculum.create');
$canUpdateCurricula = $isCurriculaPage && $userId !== null && user_can($userId, 'curriculum.update');
$canCreateVersions = $isCurriculaPage && $userId !== null && user_can($userId, 'curriculum.version.create');
$canViewSubjects = $userId !== null && user_can($userId, 'subject.view');
$canAssignSubjects = $canUpdateCurricula && $canViewSubjects;
$programColumnCount = $canUpdatePrograms ? 5 : 4;
$placementColumnCount = $canUpdateCurricula ? 7 : 6;
$initialCurriculumId = $isCurriculaPage
    ? filter_var($_GET['curriculum_id'] ?? $_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$pageTitle = $isProgramPage ? 'Academic Programs' : 'Curricula';
$pageIcon = $isProgramPage ? 'fa-graduation-cap' : 'fa-table-list';
$pageSubtitle = $isProgramPage
    ? 'Manage academic program records used by curricula and subject assignments.'
    : 'Manage curriculum records, versions, and their subject placements.';
$avatarInitial = strtoupper(substr((string)($_SESSION['first_name'] ?? 'U'), 0, 1));
$curriculumCssVersion = (int)filemtime(__DIR__ . '/../assets/curriculum.css');
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
$ACTIVE_NAV = $isProgramPage ? 'academic-programs' : 'curricula';
require __DIR__ . '/../../../shared/sidebar.php';
?>

<div class="main">
  <?php require __DIR__ . '/../../../shared/topbar.php'; ?>

  <main class="content curriculum-content" id="curriculumWorkspace"
        data-workspace-page="<?= $isProgramPage ? 'programs' : 'curricula' ?>"
        data-initial-curriculum-id="<?= $initialCurriculumId === false ? '' : (int)$initialCurriculumId ?>"
        data-program-api="../http/program_actions.php"
        data-subject-api="../http/subject_actions.php"
        data-curriculum-api="../http/curriculum_actions.php"
        data-version-api="../http/version_actions.php"
        data-assignment-api="../http/assignment_actions.php"
        data-can-view-programs="<?= $canViewPrograms ? '1' : '0' ?>"
        data-can-create-programs="<?= $canCreatePrograms ? '1' : '0' ?>"
        data-can-update-programs="<?= $canUpdatePrograms ? '1' : '0' ?>"
        data-can-create-curricula="<?= $canCreateCurricula ? '1' : '0' ?>"
        data-can-update-curricula="<?= $canUpdateCurricula ? '1' : '0' ?>"
        data-can-create-versions="<?= $canCreateVersions ? '1' : '0' ?>"
        data-can-assign-subjects="<?= $canAssignSubjects ? '1' : '0' ?>"
        data-can-remove-assignments="<?= $canUpdateCurricula ? '1' : '0' ?>">
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <h1 class="page-title"><i class="fa-solid <?= $pageIcon ?>"></i> <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-subtitle"><?= htmlspecialchars($pageSubtitle, ENT_QUOTES, 'UTF-8') ?></p>
      </div>
      <span class="read-only-pill"><i class="fa-solid fa-pen-to-square"></i> Administrative workspace</span>
    </div>

    <?php if ($isCurriculaPage): ?>
    <div class="technical-note" role="note">
      <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
      <span>Draft, active, and archived values shown here are system record states only—not official Bestlink College approval statuses.</span>
    </div>
    <?php endif; ?>

    <section class="workspace-grid workspace-grid-single" aria-label="<?= $isProgramPage ? 'Academic program records' : 'Curriculum records' ?>">
      <?php if ($isProgramPage): ?>
      <article class="domain-card" id="programPanel">
        <div class="domain-card-header">
          <div>
            <p class="eyebrow">Reference records</p>
            <h2>Academic Programs</h2>
          </div>
          <div class="card-header-actions">
            <span class="record-count" id="programCount">0 records</span>
            <?php if ($canCreatePrograms): ?>
            <button type="button" class="primary-action-button" id="newProgramButton">
              <i class="fa-solid fa-plus"></i> Add New
            </button>
            <?php endif; ?>
          </div>
        </div>
        <form class="list-toolbar" id="programSearchForm" role="search">
          <label class="search-control">
            <span class="sr-only">Search academic programs</span>
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" id="programSearch" placeholder="Code or program name" maxlength="100"/>
          </label>
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
            <thead><tr><th>Code</th><th>Program</th><th>Type</th><th>Status</th><?php if ($canUpdatePrograms): ?><th>Actions</th><?php endif; ?></tr></thead>
            <tbody id="programRows"><tr><td colspan="<?= $programColumnCount ?>" class="state-cell">Loading academic programs…</td></tr></tbody>
          </table>
        </div>
        <div class="pagination-bar" id="programPagination"></div>
      </article>
      <?php else: ?>

      <article class="domain-card curriculum-list-card" id="curriculumPanel">
        <div class="domain-card-header">
          <div>
            <p class="eyebrow">Master records</p>
            <h2>Curricula</h2>
          </div>
          <div class="card-header-actions">
            <span class="record-count" id="curriculumCount">0 records</span>
            <?php if ($canCreateCurricula): ?>
            <button type="button" class="primary-action-button" id="newCurriculumButton" disabled>
              <i class="fa-solid fa-plus"></i> Add New Curriculum
            </button>
            <?php endif; ?>
          </div>
        </div>
        <form class="list-toolbar" id="curriculumSearchForm" role="search">
          <label class="search-control">
            <span class="sr-only">Search curricula</span>
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" id="curriculumSearch" placeholder="Code or curriculum name" maxlength="100"/>
          </label>
          <label class="select-control">
            <span class="sr-only">Curriculum status</span>
            <select id="curriculumStatus">
              <option value="">All statuses</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </label>
          <label class="select-control compact-select">
            <span>Rows</span>
            <select id="curriculumPerPage"><option>10</option><option selected>20</option><option>50</option></select>
          </label>
        </form>
        <div class="table-scroll">
          <table class="domain-table curriculum-table">
            <thead><tr><th>Code</th><th>Curriculum</th><th>Academic Program</th><th>Status</th><th>Updated</th><th></th></tr></thead>
            <tbody id="curriculumRows"><tr><td colspan="6" class="state-cell">Loading curricula…</td></tr></tbody>
          </table>
        </div>
        <div class="pagination-bar" id="curriculumPagination"></div>
      </article>
      <?php endif; ?>
    </section>

    <?php if ($isCurriculaPage): ?>
    <section class="detail-shell" id="curriculumDetail" aria-live="polite">
      <div class="selection-state" id="curriculumDetailEmpty">
        <i class="fa-regular fa-folder-open"></i>
        <h2>Select a curriculum</h2>
        <p>Use View in the curriculum list to inspect its program, versions, and subject placements.</p>
      </div>

      <div id="curriculumDetailContent" hidden>
        <div class="detail-header">
          <div>
            <p class="eyebrow">Selected curriculum</p>
            <h2 id="detailCurriculumName"></h2>
            <p class="detail-code" id="detailCurriculumCode"></p>
          </div>
          <span id="detailCurriculumStatus"></span>
        </div>
        <dl class="fact-grid">
          <div><dt>Academic Program</dt><dd id="detailProgramName"></dd></div>
          <div><dt>Program Code</dt><dd id="detailProgramCode"></dd></div>
          <div><dt>Program Type</dt><dd id="detailProgramType"></dd></div>
          <div><dt>Last Updated</dt><dd id="detailUpdatedAt"></dd></div>
        </dl>

        <div class="nested-section">
          <div class="section-heading">
            <div><p class="eyebrow">System records</p><h3>Curriculum Versions</h3></div>
            <div class="card-header-actions">
              <span class="record-count" id="versionCount">0 records</span>
              <?php if ($canCreateVersions): ?>
              <button type="button" class="secondary-action-button" id="newVersionButton" disabled>
                <i class="fa-solid fa-plus"></i> New Version
              </button>
              <?php endif; ?>
            </div>
          </div>
          <div class="table-scroll">
            <table class="domain-table">
              <thead><tr><th>Version</th><th>Effective Academic Year</th><th>Description</th><th>System State</th><th></th></tr></thead>
              <tbody id="versionRows"><tr><td colspan="5" class="state-cell">No curriculum selected.</td></tr></tbody>
            </table>
          </div>
          <div class="pagination-bar" id="versionPagination"></div>
        </div>

        <div class="nested-section placement-section" id="placementSection">
          <div class="section-heading">
            <div>
              <p class="eyebrow">Selected version</p>
              <h3 id="placementHeading">Subject Placements</h3>
              <p class="placement-note">Required/optional describes this stored placement only; it is not an Electives Manager.</p>
            </div>
            <div class="card-header-actions">
              <span class="record-count" id="placementCount">0 records</span>
              <?php if ($canAssignSubjects): ?>
              <button type="button" class="secondary-action-button" id="assignSubjectButton" disabled>
                <i class="fa-solid fa-plus"></i> Assign Subject
              </button>
              <?php endif; ?>
            </div>
          </div>
          <div class="table-scroll">
            <table class="domain-table placement-table">
              <thead><tr><th>Order</th><th>Subject</th><th>Units</th><th>Year Level</th><th>Semester</th><th>Placement</th><?php if ($canUpdateCurricula): ?><th>Actions</th><?php endif; ?></tr></thead>
              <tbody id="placementRows"><tr><td colspan="<?= $placementColumnCount ?>" class="state-cell">Select a curriculum version to view assigned subjects.</td></tr></tbody>
            </table>
          </div>
          <div class="pagination-bar" id="placementPagination"></div>
        </div>
      </div>
    </section>
    <?php endif; ?>
  </main>

  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<?php if ($isProgramPage && ($canCreatePrograms || $canUpdatePrograms)): ?>
<div class="curriculum-modal" id="programModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="programModalTitle">
    <div class="modal-header">
      <div><h2 id="programModalTitle">New Academic Program</h2></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close academic program form"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="programForm" class="domain-form">
      <input type="hidden" name="id" id="programId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field"><span>Program Code <strong>*</strong></span><input name="program_code" id="programCode" maxlength="60" required/></label>
        <label class="form-field"><span>Program Name <strong>*</strong></span><input name="program_name" id="programName" maxlength="255" required/></label>
        <label class="form-field"><span>Program Type</span><input name="program_type" id="programType" maxlength="100" placeholder="Flexible academic classification"/></label>
        <label class="form-field"><span>Technical Status <strong>*</strong></span><select name="status" id="programFormStatus" required><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary" id="programSubmitButton">Create Program</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<?php if ($isCurriculaPage && ($canCreateCurricula || $canUpdateCurricula)): ?>
<div class="curriculum-modal" id="curriculumModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="curriculumModalTitle">
    <div class="modal-header">
      <div><h2 id="curriculumModalTitle">New Curriculum</h2></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close curriculum form"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="curriculumForm" class="domain-form">
      <input type="hidden" name="id" id="curriculumId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field"><span>Curriculum Code <strong>*</strong></span><input name="curriculum_code" id="curriculumCode" maxlength="80" required/></label>
        <label class="form-field"><span>Curriculum Name <strong>*</strong></span><input name="curriculum_name" id="curriculumName" maxlength="255" required/></label>
        <div class="form-field program-combobox-field">
          <label for="curriculumProgramInput">Academic Program <strong>*</strong></label>
          <div class="program-combobox" id="curriculumProgramCombobox">
            <input type="search" id="curriculumProgramInput" maxlength="100"
                   placeholder="Search by program code or name" autocomplete="off"
                   role="combobox" aria-autocomplete="list" aria-expanded="false"
                   aria-controls="curriculumProgramOptions"
                   aria-describedby="programRequirementMessage" required/>
            <input type="hidden" name="academic_program_id" id="curriculumProgramId"/>
            <div class="program-combobox-options" id="curriculumProgramOptions"
                 role="listbox" aria-label="Academic program suggestions" hidden></div>
          </div>
          <small id="programRequirementMessage">Select an existing academic program from the suggestions.</small>
        </div>
        <label class="form-field"><span>Technical Status <strong>*</strong></span><select name="status" id="curriculumFormStatus" required><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary" id="curriculumSubmitButton">Create Curriculum</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<?php if ($isCurriculaPage && $canCreateVersions): ?>
<div class="curriculum-modal" id="versionModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="versionModalTitle">
    <div class="modal-header">
      <div><h2 id="versionModalTitle">New Curriculum Version</h2><p class="modal-context" id="versionCurriculumContext"></p></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close curriculum version form"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="versionForm" class="domain-form">
      <input type="hidden" name="curriculum_id" id="versionCurriculumId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field"><span>Version Label <strong>*</strong></span><input name="version_label" id="versionLabel" maxlength="100" required/></label>
        <label class="form-field"><span>Effective Academic Year</span><input name="effective_academic_year" id="effectiveAcademicYear" maxlength="50" placeholder="Flexible label"/></label>
        <label class="form-field form-field-wide"><span>Description</span><textarea name="description" id="versionDescription" rows="3"></textarea></label>
        <label class="form-field"><span>Technical System State <strong>*</strong></span><select name="status" id="versionStatus" required><option value="draft">Draft</option><option value="active">Active</option><option value="archived">Archived</option></select><small>These are system record states, not institutional approval statuses.</small></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Create Version</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<?php if ($isCurriculaPage && $canAssignSubjects): ?>
<div class="curriculum-modal" id="assignmentModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="assignmentModalTitle">
    <div class="modal-header">
      <div><h2 id="assignmentModalTitle">Assign Subject</h2><p class="modal-context" id="assignmentVersionContext"></p></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close subject assignment form"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="assignmentForm" class="domain-form">
      <input type="hidden" name="curriculum_version_id" id="assignmentVersionId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field form-field-wide"><span>Find Subject</span><input type="search" id="assignmentSubjectSearch" maxlength="100" placeholder="Search by subject code or title"/></label>
        <label class="form-field form-field-wide"><span>Subject <strong>*</strong></span><select name="subject_id" id="assignmentSubjectId" required><option value="">Select a subject</option></select></label>
        <label class="form-field"><span>Year Level</span><input name="year_level" maxlength="50" placeholder="Flexible label"/></label>
        <label class="form-field"><span>Semester</span><input name="semester" maxlength="50" placeholder="Flexible label"/></label>
        <label class="form-field"><span>Sequence Order</span><input type="number" name="sequence_order" min="0" step="1"/></label>
        <label class="form-field"><span>Placement Indicator <strong>*</strong></span><select name="is_required" required><option value="1">Required placement</option><option value="0">Optional placement</option></select><small>This does not create an elective group.</small></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Assign Subject</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<?php if ($isCurriculaPage && $canUpdateCurricula): ?>
<div class="curriculum-modal" id="removeAssignmentModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-small" role="alertdialog" aria-modal="true" aria-labelledby="removeAssignmentTitle">
    <div class="modal-header">
      <div><h2 id="removeAssignmentTitle">Remove Subject Assignment?</h2></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close removal confirmation"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="confirmation-body"><p id="removeAssignmentMessage">This removes only the subject placement from this curriculum version. The Subject Master record will remain.</p><div class="form-error" role="alert" hidden></div></div>
    <div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="button" class="button-danger" id="confirmRemoveAssignment">Remove Placement</button></div>
  </section>
</div>
<?php endif; ?>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $curriculumCommonVersion ?>"></script>
<script src="../assets/curriculum-builder.js?v=<?= $curriculumBuilderVersion ?>"></script>
</body>
</html>
