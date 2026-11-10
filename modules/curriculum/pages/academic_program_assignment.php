<?php
// Academic Strand/Course Assignment: one program-first page. program_centric_assignment.php and
// subject_centric_assignment.php both render it, so old links and bookmarks keep working.
require_once __DIR__ . '/../../../shared/bootstrap.php';
if (!is_authenticated()) { header('Location: ../../../auth/signin.php'); exit; }
require_permission('program_assignment.view');
require_once __DIR__ . '/../services/CIMFConfig.php';
$canManageAssignments = current_user_id() !== null && user_can(current_user_id(), 'program_assignment.manage');
$activeNav = 'program-assignment-program';
$assignmentCssVersion = substr(hash_file('sha256', __DIR__ . '/../assets/program-assignment.css'), 0, 12);
$commonVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-common.js');
$scriptVersion = (int)filemtime(__DIR__ . '/../assets/program-assignment.js');
$cimfStatus = CIMFConfig::status(CIMFConfig::load());
?>
<!DOCTYPE html><html lang="en"><head>
<?php require __DIR__ . '/../../../shared/favicon.php'; ?>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
<title>Academic Strand/Course Assignment - BCP Student Management System</title>
<link rel="stylesheet" href="../../../css/dashboard.css"/><link rel="stylesheet" href="../assets/curriculum.css"/>
<link rel="stylesheet" href="../assets/subject-mapping.css"/><link rel="stylesheet" href="../assets/program-assignment.css?v=<?= $assignmentCssVersion ?>"/>
<link rel="stylesheet" href="../../../css/csms-typography.css"/><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head><body>
<?php $APP_ROOT = '../../../'; $ACTIVE_NAV = $activeNav; require __DIR__ . '/../../../shared/sidebar.php'; ?>
<div class="main"><?php require __DIR__ . '/../../../shared/topbar.php'; ?>
<main class="content curriculum-content mapping-content program-assignment-content" id="programAssignmentWorkspace"
 data-assignment-api="../http/program_assignment_actions.php" data-builder-page="curriculum_builder.php"
 data-can-manage="<?= $canManageAssignments ? '1' : '0' ?>" data-cimf-status="<?= htmlspecialchars($cimfStatus, ENT_QUOTES, 'UTF-8') ?>">
<div class="page-title-bar curriculum-title-bar"><div><h1 class="page-title"><i class="fa-solid fa-share-nodes" aria-hidden="true"></i> Academic Strand/Course Assignment</h1>
<p class="page-subtitle">Manage subject assignments across academic programs and strands.</p></div>
<div class="card-header-actions assignment-page-actions">
<?php if (!$canManageAssignments): ?><span class="csms-chip csms-chip--draft" id="readOnlyBadge">Read-only for your role</span><?php endif; ?>
<button type="button" class="button-muted" id="cimfReviewButton" disabled><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> CIMF Assignment Review</button>
<?php if ($canManageAssignments): ?><button type="button" class="primary-action-button" id="assignButton" disabled><i class="fa-solid fa-plus" aria-hidden="true"></i> Assign Subjects</button>
<span class="csms-info" id="assignReason"><button type="button" class="csms-info__btn" aria-label="Why Assign is unavailable" aria-describedby="assignReasonTip"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button><span class="csms-info__tip" role="tooltip" id="assignReasonTip"></span></span><?php endif; ?>
</div></div>
<section class="domain-card mapping-filter-card assignment-selector-card" aria-label="Program / Strand">
 <div class="program-assignment-context"><label class="form-field"><span>Program / Strand</span><div class="record-autocomplete">
 <input type="search" id="contextSearch" placeholder="Search program code or name"/><input type="hidden" id="contextId"/>
 <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear selection" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><div class="record-autocomplete-options" id="contextOptions" role="listbox" hidden></div></div></label>
 </div>
</section>
<section class="domain-card assignment-coverage" id="assignmentCoverage" aria-label="Coverage" hidden>
 <div class="assignment-coverage__program"><strong class="assignment-coverage__code" id="coverageCode"></strong><span class="assignment-coverage__name" id="coverageName"></span>
 <span class="assignment-coverage__meta"><span id="coverageType"></span> <span class="csms-chip" id="coverageStatus"></span></span></div>
 <div class="assignment-coverage__counts" role="group" aria-label="Filter the table by count">
  <button type="button" class="assignment-count" data-filter="assigned"><strong id="countAssigned">0</strong><span>Assigned</span></button>
  <button type="button" class="assignment-count" data-filter="placed"><strong id="countPlaced">0</strong><span>Placed in curriculum</span></button>
  <button type="button" class="assignment-count" data-filter="placed_not_assigned"><strong id="countPna">0</strong><span>Placed, not assigned</span></button>
  <button type="button" class="assignment-count" data-filter="assigned_not_placed"><strong id="countAnp">0</strong><span>Assigned, not placed</span></button>
 </div>
</section>
<section class="domain-card mapping-results-card" aria-label="Subjects of the selected program">
<div class="csms-empty" id="assignEmpty" role="status"><i class="fa-solid fa-bullseye" aria-hidden="true"></i><strong>Select a Program / Strand</strong><span>Choose a program to see its assigned and placed subjects.</span></div>
<div class="csms-empty" id="assignNoSubjects" role="status" hidden><i class="fa-solid fa-book-open" aria-hidden="true"></i><strong>No subjects yet</strong><span>This program has no assigned or placed subjects.</span></div>
<div id="assignmentList" hidden>
<div class="csms-filter-row csms-page-bar" id="assignmentFilters">
<label class="csms-search"><span class="sr-only">Search subject code or title</span><input type="search" id="assignmentSearch" placeholder="Search subject code or title"/></label>
<select class="csms-select" id="coverageFilter" aria-label="Show"><option value="">All subjects</option><option value="assigned">Assigned</option><option value="placed">Placed in curriculum</option><option value="placed_not_assigned">Placed, not assigned</option><option value="assigned_not_placed">Assigned, not placed</option></select>
<select class="csms-select" id="perPage" aria-label="Rows per page"><option value="10">10 rows</option><option value="20" selected>20 rows</option><option value="50">50 rows</option></select>
<span class="csms-filter-row__summary" id="assignmentCount" role="status">0 records</span></div>
<div class="csms-page-table table-scroll"><table class="csms-ctable"><thead><tr><th class="csms-ctable__code">Code</th><th>Title</th><th class="csms-ctable__num">Units</th><th>Assignment</th><th>Placed in</th><th class="csms-ctable__action"><span class="sr-only">Actions</span></th></tr></thead><tbody id="assignmentRows"></tbody></table></div>
<div class="csms-empty" id="assignNoMatch" role="status" hidden><i class="fa-solid fa-filter" aria-hidden="true"></i><strong>No subjects match</strong><span>Change the search or the filter.</span></div>
<div id="assignmentRowMenu" class="csms-menu" role="menu" aria-label="Row actions" hidden></div>
<div class="pagination-bar" id="assignmentPagination" hidden></div>
</div></section>
</main><div class="footer">Bestlink College of the Philippines &middot; CSMS-SMS2 &copy; 2026</div></div>

<?php if ($canManageAssignments): ?><div class="curriculum-modal" id="assignModal" hidden><div class="modal-backdrop" data-modal-close></div><section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="assignModalTitle">
<div class="modal-header"><div><h2 id="assignModalTitle">Assign Subjects to Program</h2><p class="modal-context" id="assignModalContext"></p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
<form class="domain-form" id="assignForm"><div class="form-error" role="alert" hidden></div><label class="form-field"><span>Search subjects</span><div class="record-autocomplete"><input type="search" id="assignSearch" placeholder="Search by code or title"/><input type="hidden" id="assignId"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear selection" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><div class="record-autocomplete-options" id="assignOptions" role="listbox" hidden></div></div><small id="assignHelp">Inactive subjects and subjects already assigned to this program are not listed.</small></label>
<div class="selected-assignment"><h3 id="selectedTitle">Selected (0)</h3><ul id="selectedRecords"></ul></div><div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary" disabled>Assign</button></div></form></section></div>
<div class="curriculum-modal" id="removeModal" hidden><div class="modal-backdrop" data-modal-close></div><section class="modal-dialog modal-dialog-small" role="alertdialog" aria-modal="true" aria-labelledby="removeModalTitle"><div class="modal-header"><h2 id="removeModalTitle">Unassign Subject</h2><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><div class="confirmation-body"><p id="removeMessage"></p><div class="form-error" role="alert" hidden></div></div><div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="button" class="button-danger" id="confirmRemove">Unassign</button></div></section></div><?php endif; ?>
<div class="curriculum-modal" id="detailsModal" hidden><div class="modal-backdrop" data-modal-close></div><section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="detailsTitle"><div class="modal-header"><div><h2 id="detailsTitle">Where used</h2></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><div class="confirmation-body" id="detailsBody"></div><div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Close</button></div></section></div>

<div class="sidebar-overlay" id="sidebarOverlay"></div><script src="../assets/curriculum-common.js?v=<?= $commonVersion ?>"></script><script src="../../../js/csms-menu.js?v=<?= (int)filemtime(__DIR__ . '/../../../js/csms-menu.js') ?>"></script><script src="../assets/program-assignment.js?v=<?= $scriptVersion ?>"></script></body></html>
