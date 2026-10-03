<?php
require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('elective.view');

$userId = current_user_id();
$canManageElectives = $userId !== null && user_can($userId, 'elective.manage');
$avatarInitial = strtoupper(substr((string)($_SESSION['first_name'] ?? 'U'), 0, 1));
$curriculumCssVersion = (int)filemtime(__DIR__ . '/../assets/curriculum.css');
$typographyCssVersion = (int)filemtime(__DIR__ . '/../../../css/csms-typography.css');
$mappingCssVersion = (int)filemtime(__DIR__ . '/../assets/subject-mapping.css');
$electiveCssVersion = (int)filemtime(__DIR__ . '/../assets/electives-manager.css');
$curriculumCommonVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-common.js');
$electiveScriptVersion = (int)filemtime(__DIR__ . '/../assets/electives-manager.js');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../../../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <title>Electives Manager - BCP Student Management System</title>
  <link rel="stylesheet" href="../../../css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/curriculum.css?v=<?= $curriculumCssVersion ?>"/>
  <link rel="stylesheet" href="../assets/subject-mapping.css?v=<?= $mappingCssVersion ?>"/>
  <link rel="stylesheet" href="../assets/electives-manager.css?v=<?= $electiveCssVersion ?>"/>
  <link rel="stylesheet" href="../../../css/csms-typography.css?v=<?= $typographyCssVersion ?>"/>
  <link rel="stylesheet" href="../assets/subject-mapping-final.css?v=<?= (int)filemtime(__DIR__ . '/../assets/subject-mapping-final.css') ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../../../';
$ACTIVE_NAV = 'electives-manager';
require __DIR__ . '/../../../shared/sidebar.php';
?>

<div class="main">
  <?php require __DIR__ . '/../../../shared/topbar.php'; ?>

  <main class="content curriculum-content mapping-content electives-content" id="electivesWorkspace" data-group-api="../http/elective_group_actions.php" data-can-manage-electives="<?= $canManageElectives ? '1' : '0' ?>">
    <div class="mapping-workspace-header">
      <div><h1 class="page-title">Electives Manager</h1><p class="page-subtitle">Manage elective subject groups within curriculum versions.</p></div>
      <?php if ($canManageElectives): ?><button class="primary-action-button" id="createElectiveGroupButton" disabled><i class="fa-solid fa-plus"></i> Create Elective Group</button><?php endif; ?>
    </div>
    <section class="mapping-workspace-shell">
    <section class="mapping-context-bar elective-context" aria-label="Curriculum context">
      <?php foreach (['program'=>'Program / Strand','curriculum'=>'Curriculum','version'=>'Curriculum Version'] as $key=>$label): ?>
      <div class="mapping-context-field"><label for="<?= $key ?>Search"><?= $label ?></label><div class="record-autocomplete">
        <input type="search" id="<?= $key ?>Search" placeholder="<?= $key === 'program' ? 'Search program code or name' : ($key === 'curriculum' ? 'Choose program first' : 'Choose curriculum first') ?>" <?= $key !== 'program' ? 'disabled' : '' ?> autocomplete="off"/>
        <input type="hidden" id="<?= $key ?>Id"/>
        <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear <?= $label ?>" hidden><i class="fa-solid fa-xmark"></i></button>
        <div class="record-autocomplete-options" id="<?= $key ?>Options" role="listbox" aria-label="<?= $label ?> suggestions" hidden></div>
      </div></div>
      <?php if ($key !== 'version'): ?><span class="mapping-context-separator" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
      <?php endforeach; ?>
    </section>
    <div class="elective-tabs" role="tablist" aria-label="Electives Manager">
      <button id="groupsTab" role="tab" aria-selected="true" aria-controls="groupsPanel">Elective Groups</button>
      <button id="reviewTab" role="tab" aria-selected="false" aria-controls="reviewPanel" tabindex="-1">Validation Review</button>
    </div>
    <div id="electiveState" class="elective-empty" role="status"><i class="fa-solid fa-bullseye"></i><h2>Select a Program, Curriculum, and Version</h2><p>Select context to view elective groups.</p></div>
    <section id="groupsPanel" role="tabpanel" aria-labelledby="groupsTab" hidden>
      <div class="elective-filters">
        <input type="search" id="groupSearch" placeholder="Search elective group…" aria-label="Search elective group"/>
        <select id="yearFilter" aria-label="Year / Grade"><option value="">Year / Grade</option></select>
        <select id="termFilter" aria-label="Semester / Term"><option value="">Semester / Term</option></select>
        <select id="statusFilter" aria-label="Status"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="archived">Archived</option></select>
      </div>
      <div id="groupCards" class="elective-grid"></div>
    </section>
    <section id="reviewPanel" role="tabpanel" aria-labelledby="reviewTab" hidden><div id="reviewCounters" class="elective-review-grid"></div><div id="reviewIssues"></div></section>
    </section>
  </main>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>
<div class="curriculum-modal" id="viewGroupModal" hidden><div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="viewGroupTitle">
    <div class="modal-header"><h2 id="viewGroupTitle">Elective Group</h2><button class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
    <div id="viewGroupBody" class="elective-modal-body"></div>
    <div class="modal-actions modal-actions-padded"><button class="button-muted" data-modal-close>Close</button><?php if ($canManageElectives): ?><button class="button-muted" id="viewEdit">Edit</button><button class="button-danger" id="viewArchive">Archive</button><?php endif; ?></div>
  </section>
</div>
<?php if ($canManageElectives): ?>
<div class="curriculum-modal" id="groupFormModal" hidden><div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="groupFormTitle">
    <div class="modal-header"><div><h2 id="groupFormTitle">Create Elective Group</h2><p class="modal-context">Define alternative subject choices for this curriculum version.</p></div><button class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
    <form id="groupForm" class="domain-form">
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field form-field-wide"><span>Group Name *</span><input name="group_name" maxlength="150" placeholder="Professional Elective 1" required/></label>
        <label class="form-field"><span>Year / Grade Level *</span><select name="year_level" required></select></label>
        <label class="form-field"><span>Semester / Term *</span><select name="semester" required></select></label>
        <label class="form-field"><span>Required Selections *</span><input type="number" name="required_selections" min="1" step="1" value="1" required/></label>
        <label class="form-field"><span>Status *</span><select name="status" required><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
        <div class="form-field form-field-wide"><label for="subjectSearch">Subjects Available for This Elective Group *</label><small>Only compatible subjects from the selected curriculum version and placement are shown.</small>
          <div class="record-autocomplete"><input type="search" id="subjectSearch" placeholder="Search mapped subject code or title" autocomplete="off"/><input type="hidden" id="subjectId"/><div class="record-autocomplete-options" id="subjectOptions" role="listbox" aria-label="Eligible subject suggestions" hidden></div></div>
          <div id="selectedSubjects"></div>
        </div>
      </div>
      <div id="placementWarning" class="elective-warning" hidden><strong>Placement mismatch</strong><p id="placementWarningText"></p><button type="button" class="button-muted" id="cancelPlacement">Cancel</button> <a id="mappingLink" class="button-muted">Open Subject Mapping</a></div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Save Elective Group</button></div>
    </form>
  </section>
</div>
<div class="curriculum-modal" id="archiveGroupModal" hidden><div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-small" role="alertdialog" aria-modal="true" aria-labelledby="archiveGroupTitle">
    <div class="modal-header"><h2 id="archiveGroupTitle">Archive Elective Group?</h2><button class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="confirmation-body"><p>This elective group will no longer be active for regular use. Existing records will be preserved.</p><strong id="archiveGroupName"></strong><div class="form-error" role="alert" hidden></div></div>
    <div class="modal-actions modal-actions-padded"><button class="button-muted" data-modal-close>Cancel</button><button class="button-danger" id="confirmArchive">Archive Group</button></div>
  </section>
</div>
<?php endif; ?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $curriculumCommonVersion ?>"></script>
<script src="../assets/electives-manager.js?v=<?= $electiveScriptVersion ?>"></script>
</body></html>
