<?php
// Subjects (Subject Master): the master list of subjects. Two views of one page: All subjects (server-side, any number of subjects) and
// By program (the placements of one curriculum version). Old addresses keep working: ?program_id= opens By program, ?subject_id= opens the details.
require_once __DIR__ . '/../../../shared/bootstrap.php';
if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('subject.view');

$userId = current_user_id();
$can = static fn(string $permission): bool => $userId !== null && user_can($userId, $permission);
$requestedProgramId = trim((string)($_GET['program_id'] ?? ''));
if ($requestedProgramId !== '') {
    if (filter_var($requestedProgramId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
        http_response_code(400);
        exit('Invalid academic program.');
    }
    require_permission('program.view');
    require_permission('curriculum.view');
}
$canBrowse = $can('program.view') && $can('curriculum.view');
$canCreate = $can('subject.create');
$canUpdate = $can('subject.update');
$canPlace = $canCreate && $canBrowse && $can('curriculum.update');
$canExport = $can('curriculum_export.view');
$view = (string)($_GET['view'] ?? ($requestedProgramId !== '' ? 'program' : 'all'));
if (!in_array($view, ['all', 'program'], true) || ($view === 'program' && !$canBrowse)) $view = 'all';
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$v = static fn(string $file): int => (int)filemtime(__DIR__ . '/' . $file);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../../../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= $e(csrf_token()) ?>"/>
  <title>Subjects - BCP Student Management System</title>
  <link rel="stylesheet" href="../../../css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/curriculum.css?v=<?= $v('../assets/curriculum.css') ?>"/>
  <link rel="stylesheet" href="../assets/subject-mapping.css?v=<?= $v('../assets/subject-mapping.css') ?>"/>
  <link rel="stylesheet" href="../../../css/csms-typography.css?v=<?= $v('../../../css/csms-typography.css') ?>"/>
  <link rel="stylesheet" href="../assets/subject-mapping-final.css?v=<?= $v('../assets/subject-mapping-final.css') ?>"/>
  <link rel="stylesheet" href="../assets/subject-master.css?v=<?= $v('../assets/subject-master.css') ?>"/>
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
  <main class="content curriculum-content mapping-content subject-master-content" id="subjectWorkspace" data-view="<?= $e($view) ?>"
        data-subject-api="../http/subject_actions.php" data-export-url="curriculum_export.php"
        data-can-create="<?= $canCreate ? '1' : '0' ?>" data-can-update="<?= $canUpdate ? '1' : '0' ?>" data-can-place="<?= $canPlace ? '1' : '0' ?>"
        data-can-browse="<?= $canBrowse ? '1' : '0' ?>" data-can-export="<?= $canExport ? '1' : '0' ?>" data-open-subject="<?= $e(filter_var($_GET['subject_id'] ?? '', FILTER_VALIDATE_INT) ?: '') ?>">
    <div class="mapping-workspace-header">
      <div><h1 class="page-title">Subjects</h1><p class="page-subtitle">Master list of subjects used across curricula.</p></div>
      <?php if ($canCreate): ?><button type="button" class="primary-action-button" id="newSubjectButton"><i class="fa-solid fa-plus" aria-hidden="true"></i> New subject</button><?php endif; ?>
      <?php if (!$canCreate && !$canUpdate): ?><span class="csms-chip csms-chip--draft">View only</span><?php endif; ?>
    </div>

    <section class="mapping-workspace-shell">
      <nav class="csms-tabs" id="subjectViews" role="tablist" aria-label="Subject views">
        <button type="button" role="tab" data-view="all" aria-selected="<?= $view === 'all' ? 'true' : 'false' ?>"<?= $view === 'all' ? '' : ' tabindex="-1"' ?>>All subjects</button>
        <?php if ($canBrowse): ?><button type="button" role="tab" data-view="program" aria-selected="<?= $view === 'program' ? 'true' : 'false' ?>"<?= $view === 'program' ? '' : ' tabindex="-1"' ?>>By program</button><?php endif; ?>
      </nav>

      <?php if ($canBrowse): ?>
      <section class="mapping-context-bar" id="subjectContextBar" aria-label="Curriculum context" hidden>
        <?php foreach (['program' => 'Program / Strand', 'curriculum' => 'Curriculum', 'version' => 'Curriculum Version'] as $key => $label): ?>
        <div class="mapping-context-field"><label for="<?= $key ?>Search"><?= $label ?></label><div class="record-autocomplete">
          <input type="search" id="<?= $key ?>Search" placeholder="<?= $key === 'program' ? 'Search program code or name' : ($key === 'curriculum' ? 'Choose program first' : 'Choose curriculum first') ?>" <?= $key !== 'program' ? 'disabled' : '' ?> autocomplete="off"/>
          <input type="hidden" id="<?= $key ?>Id"/>
          <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear <?= $label ?>" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
          <div class="record-autocomplete-options" id="<?= $key ?>Options" role="listbox" aria-label="<?= $label ?> suggestions" hidden></div>
        </div></div>
        <?php if ($key !== 'version'): ?><span class="mapping-context-separator" aria-hidden="true"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></span><?php endif; ?>
        <?php endforeach; ?>
      </section>
      <?php endif; ?>

      <section class="mapping-workspace-panel csms-panel" aria-label="Subjects">
        <!-- All subjects -->
        <div id="viewAll" data-panel="all" hidden>
          <div class="csms-filter-row">
            <label class="csms-search"><span class="sr-only">Search subject code or title</span><input type="search" id="subjectSearch" maxlength="100" placeholder="Search code or title"/></label>
            <select class="csms-select" id="subjectStatus" aria-label="Status"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
            <?php if ($canBrowse): ?>
            <label class="sr-only" for="subjectProgram">Program or strand</label>
            <select id="subjectProgram" class="subject-program-select"><option value="all">All programs</option></select>
            <select class="csms-select" id="subjectPlaced" aria-label="Placement"><option value="">All placements</option><option value="placed">Placed</option><option value="unplaced">Not placed anywhere</option></select>
            <?php endif; ?>
            <select class="csms-select" id="subjectPerPage" aria-label="Rows per page"><option value="10">10 rows</option><option value="20" selected>20 rows</option><option value="50">50 rows</option><option value="100">100 rows</option></select>
            <span class="csms-filter-row__summary" id="subjectCount" role="status">0 records</span>
          </div>
          <div class="csms-filter-row subject-chips" id="subjectChips" aria-label="Active filters" hidden></div>
          <div class="csms-empty" id="subjectLoading" role="status"><i class="fa-solid fa-spinner" aria-hidden="true"></i><strong>Loading subjects…</strong></div>
          <div class="csms-empty" id="subjectEmpty" role="status" hidden><i class="fa-solid fa-book" aria-hidden="true"></i><strong>No subjects yet</strong><span><?= $canCreate ? 'Create the first subject with New subject.' : 'Subjects appear here when they are created.' ?></span></div>
          <div class="csms-empty" id="subjectNoMatch" role="status" hidden><i class="fa-solid fa-filter" aria-hidden="true"></i><strong>No subjects match these filters</strong><span>Change the search or a filter.</span><button type="button" class="button-muted" id="subjectClearFilters">Clear filters</button></div>
          <div class="csms-empty" id="subjectError" role="alert" hidden><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><strong>Subjects could not be loaded</strong><span id="subjectErrorText"></span><button type="button" class="button-muted" id="subjectRetry">Retry</button></div>
          <div class="csms-page-table table-scroll" id="subjectTableWrap" tabindex="0" aria-label="Scrollable subjects" hidden>
            <table class="csms-ctable">
              <thead><tr>
                <th aria-sort="none"><button type="button" class="subject-sort" data-sort="code">Code</button></th>
                <th aria-sort="none"><button type="button" class="subject-sort" data-sort="title">Title</button></th>
                <th class="csms-ctable__num" aria-sort="none"><button type="button" class="subject-sort" data-sort="units">Units</button></th>
                <th aria-sort="none"><button type="button" class="subject-sort" data-sort="status">Status</button></th>
                <?php if ($canBrowse): ?><th class="csms-ctable__num" aria-sort="none"><button type="button" class="subject-sort" data-sort="usage">Used in</button></th><?php endif; ?>
                <th class="csms-ctable__action"><span class="sr-only">Actions</span></th></tr></thead>
              <tbody id="subjectRows"></tbody>
            </table>
          </div>
          <div class="pagination-bar" id="subjectPagination" hidden></div>
        </div>

        <!-- By program -->
        <div id="viewProgram" data-panel="program" hidden>
          <div class="csms-empty" id="programNoVersion" role="status"><i class="fa-solid fa-bullseye" aria-hidden="true"></i><strong>No curriculum version selected</strong><span>Use the context bar above to pick a curriculum version.</span></div>
          <div class="csms-empty" id="programLoading" role="status" hidden><i class="fa-solid fa-spinner" aria-hidden="true"></i><strong>Loading placements…</strong></div>
          <div class="csms-empty" id="programError" role="alert" hidden><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><strong>Placements could not be loaded</strong><span id="programErrorText"></span><button type="button" class="button-muted" id="programRetry">Retry</button></div>
          <div id="programBody" hidden>
            <p class="csms-panel__note" id="programSummary" role="status"></p>
            <div class="csms-filter-row">
              <label class="csms-search"><span class="sr-only">Search subject code or title</span><input type="search" id="programSearch2" maxlength="100" placeholder="Search code or title"/></label>
              <select class="csms-select" id="programYear" aria-label="Year level"><option value="">All year levels</option></select>
              <select class="csms-select" id="programSemester" aria-label="Semester"><option value="">All semesters</option></select>
              <select class="csms-select" id="programStatus" aria-label="Status"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
              <?php if ($canExport): ?><a class="button-muted" id="programExport" href="curriculum_export.php"><i class="fa-solid fa-download" aria-hidden="true"></i> Export</a><?php endif; ?>
            </div>
            <div class="csms-empty" id="programNoMatch" role="status" hidden><i class="fa-solid fa-filter" aria-hidden="true"></i><strong>No placements match these filters</strong><span>Change the search or a filter.</span></div>
            <div class="csms-empty" id="programEmpty" role="status" hidden><i class="fa-solid fa-book" aria-hidden="true"></i><strong>No subjects placed in this version</strong><span>Place subjects from Curriculum Builder or Subject Mapping.</span></div>
            <div class="csms-page-table table-scroll" id="programTableWrap" tabindex="0" aria-label="Scrollable placements" hidden>
              <table class="csms-ctable">
                <thead><tr><th>Year</th><th>Semester</th><th>Code</th><th>Title</th><th class="csms-ctable__num">Units</th><th>Required</th><th>Status</th></tr></thead>
                <tbody id="programRows"></tbody>
              </table>
            </div>
          </div>
        </div>
      </section>
    </section>
    <div id="subjectRowMenu" class="csms-menu" role="menu" aria-label="Row actions" hidden></div>
  </main>
  <div class="footer">Bestlink College of the Philippines &middot; CSMS-SMS2 &copy; 2026</div>
</div>

<div class="curriculum-modal" id="subjectDialog" hidden>
  <div class="modal-backdrop" id="subjectBackdrop"></div>
  <section class="modal-dialog subject-dialog" role="dialog" aria-modal="true" aria-labelledby="subjectDialogTitle">
    <div class="modal-header"><div><h2 id="subjectDialogTitle">Subject</h2><p class="modal-context" id="subjectDialogContext"></p></div><button type="button" class="modal-close-button" id="subjectDialogX" aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <form id="subjectForm" novalidate>
      <div class="confirmation-body" id="subjectDialogBody"></div>
      <div class="modal-actions modal-actions-padded" id="subjectDialogFooter"></div>
    </form>
  </section>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $v('../assets/curriculum-common.js') ?>"></script>
<script src="../../../js/csms-menu.js?v=<?= $v('../../../js/csms-menu.js') ?>"></script>
<script src="../../../js/csms-program-picker.js?v=<?= $v('../../../js/csms-program-picker.js') ?>"></script>
<script src="../assets/subject-master.js?v=<?= $v('../assets/subject-master.js') ?>"></script>
</body>
</html>
