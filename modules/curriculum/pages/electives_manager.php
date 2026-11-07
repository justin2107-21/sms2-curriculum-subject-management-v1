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
      <?php if ($canManageElectives): ?><button class="primary-action-button" id="createElectiveGroupButton" disabled><i class="fa-solid fa-plus" aria-hidden="true"></i> Create Elective Group</button><?php endif; ?>
      <?php if (!$canManageElectives): ?><span class="csms-chip csms-chip--draft" id="readOnlyBadge">Read-only for your role</span><?php endif; ?>
    </div>
    <section class="mapping-workspace-shell">
    <section class="mapping-context-bar elective-context" aria-label="Curriculum context">
      <?php foreach (['program'=>'Program / Strand','curriculum'=>'Curriculum','version'=>'Curriculum Version'] as $key=>$label): ?>
      <div class="mapping-context-field"><label for="<?= $key ?>Search"><?= $label ?></label><div class="record-autocomplete">
        <input type="search" id="<?= $key ?>Search" placeholder="<?= $key === 'program' ? 'Search program code or name' : ($key === 'curriculum' ? 'Choose program first' : 'Choose curriculum first') ?>" <?= $key !== 'program' ? 'disabled' : '' ?> autocomplete="off"/>
        <input type="hidden" id="<?= $key ?>Id"/>
        <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear <?= $label ?>" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        <div class="record-autocomplete-options" id="<?= $key ?>Options" role="listbox" aria-label="<?= $label ?> suggestions" hidden></div>
      </div></div>
      <?php if ($key !== 'version'): ?><span class="mapping-context-separator" aria-hidden="true"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></span><?php endif; ?>
      <?php endforeach; ?>
    </section>
    <nav class="csms-tabs" role="tablist" aria-label="Electives Manager">
      <button type="button" id="groupsTab" role="tab" aria-selected="true" aria-controls="groupsPanel">Elective Groups</button>
      <button type="button" id="reviewTab" role="tab" aria-selected="false" aria-controls="reviewPanel" tabindex="-1">Validation Review</button>
    </nav>
    <div id="electiveState" class="csms-empty" role="status"><i class="fa-solid fa-bullseye" aria-hidden="true"></i><strong>Select a Program, Curriculum, and Version</strong><span>Select context to view elective groups.</span></div>
    <section id="groupsPanel" class="csms-panel" role="tabpanel" aria-labelledby="groupsTab" hidden>
      <div class="csms-filter-row">
        <label class="csms-search"><span class="sr-only">Search elective groups</span><input type="search" id="groupSearch" maxlength="100" placeholder="Search group name or subject"/></label>
        <select class="csms-select" id="yearFilter" aria-label="Year or grade"><option value="">All Years</option></select>
        <select class="csms-select" id="termFilter" aria-label="Semester"><option value="">All Semesters</option></select>
        <select class="csms-select" id="statusFilter" aria-label="Status"><option value="">Active and inactive</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="archived">Archived</option></select>
        <span class="csms-filter-row__summary" id="groupSummary" role="status"></span>
        <span class="csms-info"><button type="button" class="csms-info__btn" aria-label="About this page" aria-describedby="electiveInfoTip"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button><span class="csms-info__tip" role="tooltip" id="electiveInfoTip">College curricula only: Senior High School elective categories are not supported here. Unit targets for electives are checked by the Compliance Validator, not on this page.</span></span>
      </div>
      <div id="groupCards"></div>
      <div id="electiveRowMenu" class="csms-menu" role="menu" aria-label="Row actions" hidden></div>
    </section>
    <section id="reviewPanel" class="csms-panel" role="tabpanel" aria-labelledby="reviewTab" hidden><div id="reviewCounters" class="elective-review-grid"></div><div id="reviewIssues"></div></section>
    </section>
  </main>
  <div class="footer">Bestlink College of the Philippines &middot; CSMS-SMS2 &copy; 2026</div>
</div>
<div class="curriculum-modal" id="warnGroupModal" hidden><div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="warnGroupTitle">
    <div class="modal-header"><h2 id="warnGroupTitle">Issues</h2><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div id="warnGroupBody" class="elective-modal-body"></div>
    <div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Close</button><?php if ($canManageElectives): ?><button type="button" class="button-primary" id="warnGroupEdit">Edit group</button><?php endif; ?></div>
  </section>
</div>
<div class="curriculum-modal" id="viewGroupModal" hidden><div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="viewGroupTitle">
    <div class="modal-header"><h2 id="viewGroupTitle">Elective Group</h2><button class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div id="viewGroupBody" class="elective-modal-body"></div>
    <div class="modal-actions modal-actions-padded"><button class="button-muted" data-modal-close>Close</button><?php if ($canManageElectives): ?><button class="button-muted" id="viewEdit">Edit</button><button class="button-danger" id="viewArchive">Archive</button><?php endif; ?></div>
  </section>
</div>
<?php if ($canManageElectives): ?>
<div class="curriculum-modal" id="groupFormModal" hidden><div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="groupFormTitle">
    <div class="modal-header"><div><h2 id="groupFormTitle">Create Elective Group</h2><p class="modal-context">Define alternative subject choices for this curriculum version.</p></div><button class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <form id="groupForm" class="domain-form">
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field form-field-wide"><span>Group Name *</span><input name="group_name" maxlength="150" placeholder="Professional Elective 1" required/></label>
        <label class="form-field"><span>Year / Grade Level *</span><select name="year_level" required></select></label>
        <label class="form-field"><span>Semester / Term *</span><select name="semester" required></select></label>
        <label class="form-field"><span>Number of Subjects to Choose *</span><input type="number" name="required_selections" min="1" step="1" value="1" required/><small>How many subjects must be selected from this elective group?</small></label>
        <label class="form-field"><span>Status *</span><select name="status" required><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
        <div class="form-field form-field-wide"><label for="subjectSearch">Available Subjects for This Placement *</label><small>Only subjects mapped to the selected curriculum version and academic placement are shown.</small>
          <div class="record-autocomplete"><input type="search" id="subjectSearch" placeholder="Search mapped subject code or title" autocomplete="off"/><input type="hidden" id="subjectId"/><div class="record-autocomplete-options" id="subjectOptions" role="listbox" aria-label="Eligible subject suggestions" hidden></div></div>
          <div id="selectedSubjects"></div>
        </div>
      </div>
      <div id="placementWarning" class="elective-warning" hidden><strong>Placement mismatch</strong><p id="placementWarningText"></p><button type="button" class="button-muted" id="cancelPlacement">Cancel</button> <a id="mappingLink" class="button-muted">Open Subject Mapping</a></div>
      <div id="allRequiredWarning" class="elective-warning" role="status" hidden>All available subjects are currently required. This group does not provide an alternative choice.</div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Save Elective Group</button></div>
    </form>
  </section>
</div>
<div class="curriculum-modal" id="archiveGroupModal" hidden><div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-small" role="alertdialog" aria-modal="true" aria-labelledby="archiveGroupTitle">
    <div class="modal-header"><h2 id="archiveGroupTitle">Archive Record</h2><button class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="confirmation-body"><p>Are you sure you want to archive this elective group?</p><p>The record will be removed from active views but retained in Archives.</p><strong id="archiveGroupName"></strong><div class="form-error" role="alert" hidden></div></div>
    <div class="modal-actions modal-actions-padded"><button class="button-muted" data-modal-close>Cancel</button><button class="button-danger" id="confirmArchive">Archive</button></div>
  </section>
</div>
<?php endif; ?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $curriculumCommonVersion ?>"></script>
<script src="../../../js/csms-menu.js?v=<?= (int)filemtime(__DIR__ . '/../../../js/csms-menu.js') ?>"></script>
<script src="../assets/placement-groups.js?v=<?= (int)filemtime(__DIR__ . '/../assets/placement-groups.js') ?>"></script>
<script src="../assets/electives-manager.js?v=<?= $electiveScriptVersion ?>"></script>
</body></html>
