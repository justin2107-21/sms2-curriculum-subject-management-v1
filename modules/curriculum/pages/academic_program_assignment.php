<?php
$programAssignmentView = $programAssignmentView ?? null;
if ($programAssignmentView === null) {
    $view = strtolower(trim((string)($_GET['view'] ?? 'program')));
    header('Location: ' . ($view === 'subject' ? 'subject_centric_assignment.php' : 'program_centric_assignment.php'), true, 302);
    exit;
}
if (!in_array($programAssignmentView, ['program', 'subject'], true)) { http_response_code(404); exit('Page not found.'); }
require_once __DIR__ . '/../../../shared/bootstrap.php';
if (!is_authenticated()) { header('Location: ../../../auth/signin.php'); exit; }
require_permission('program_assignment.view');
require_once __DIR__ . '/../services/CIMFConfig.php';
$canManageAssignments = current_user_id() !== null && user_can(current_user_id(), 'program_assignment.manage');
$programView = $programAssignmentView === 'program';
$activeNav = $programView ? 'program-assignment-program' : 'program-assignment-subject';
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
 data-view="<?= $programView ? 'program' : 'subject' ?>" data-assignment-api="../http/program_assignment_actions.php"
 data-program-api="../http/program_actions.php" data-subject-api="../http/subject_actions.php"
 data-can-manage="<?= $canManageAssignments ? '1' : '0' ?>" data-cimf-status="<?= htmlspecialchars($cimfStatus, ENT_QUOTES, 'UTF-8') ?>">
<div class="page-title-bar curriculum-title-bar"><div><h1 class="page-title"><i class="fa-solid fa-share-nodes" aria-hidden="true"></i> Academic Strand/Course Assignment</h1>
<p class="page-subtitle"><?= $programView ? 'Manage subject assignments across academic programs and strands.' : 'Review and manage program or strand assignments for each subject.' ?></p><?php $moduleViews = 'assignment'; include __DIR__ . '/_module_views.php'; ?></div>
<div class="card-header-actions assignment-page-actions"><button type="button" class="button-muted" id="cimfReviewButton" disabled><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> CIMF Assignment Review</button>
<button type="button" class="primary-action-button" id="assignButton" <?= $canManageAssignments ? '' : 'hidden' ?> disabled><i class="fa-solid fa-plus" aria-hidden="true"></i> <?= $programView ? 'Assign Subjects' : 'Assign to Program / Strand' ?></button></div></div>
<div class="assignment-context-row"><section class="domain-card mapping-filter-card assignment-selector-card">
 <div class="program-assignment-context">
 <?php if ($programView): ?><label class="form-field"><span>Program / Strand</span><div class="record-autocomplete">
 <input type="search" id="contextSearch" placeholder="Search program code or name"/><input type="hidden" id="contextId"/>
 <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear selection" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><div class="record-autocomplete-options" id="contextOptions" role="listbox" hidden></div></div></label>
 <?php else: ?><label class="form-field"><span>Subject</span><div class="record-autocomplete">
 <input type="search" id="contextSearch" placeholder="Search subject code or title"/><input type="hidden" id="contextId"/>
 <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear selection" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><div class="record-autocomplete-options" id="contextOptions" role="listbox" hidden></div></div></label><?php endif; ?>
 <p class="subtle-text" id="contextStatus" role="status"><?= $programView ? 'Select a Program / Strand to view its assigned subjects.' : 'Select a subject to view its Program / Strand assignments.' ?></p>
 </div>
</section><section class="domain-card assignment-summary-card assignment-summary-empty" id="assignmentSummary" aria-label="Selected record summary"></section></div>
<section class="domain-card mapping-results-card assignment-context-empty" aria-label="<?= $programView ? 'Assigned subjects' : 'Program and strand assignments' ?>">
<div class="csms-filter-row csms-page-bar assignment-filter-row" id="assignmentFilters" hidden>
<label class="csms-search"><span class="sr-only">Search <?= $programView ? 'subject code or title' : 'program code or name' ?></span><input type="search" id="assignmentSearch" placeholder="<?= $programView ? 'Search subject code or title' : 'Search program code or name' ?>" disabled/></label>
<select class="csms-select" id="statusFilter" aria-label="Status" disabled><option value="">All statuses</option><option value="active">Active</option><option value="archived">Archived</option></select>
<select class="csms-select" id="perPage" aria-label="Rows per page"><option value="10">10 rows</option><option value="20" selected>20 rows</option><option value="50">50 rows</option></select>
<span class="csms-filter-row__summary" id="assignmentCount" role="status">0 records</span></div>
<div class="csms-page-table table-scroll"><table class="csms-ctable"><thead id="assignmentHead"></thead><tbody id="assignmentRows"><tr><td class="state-cell" id="initialState"><?= $programView ? 'Select a Program / Strand to view its assigned subjects.' : 'Select a subject to view its Program / Strand assignments.' ?></td></tr></tbody></table></div>
<div id="assignmentRowMenu" class="csms-menu" role="menu" aria-label="Row actions" hidden></div>
<div class="pagination-bar" id="assignmentPagination" hidden></div></section>
</main><div class="footer">Bestlink College of the Philippines &middot; CSMS-SMS2 &copy; 2026</div></div>

<?php if ($canManageAssignments): ?><div class="curriculum-modal" id="assignModal" hidden><div class="modal-backdrop" data-modal-close></div><section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="assignModalTitle">
<div class="modal-header"><div><h2 id="assignModalTitle"><?= $programView ? 'Assign Subjects to Program' : 'Assign Subject to Program / Strand' ?></h2><p class="modal-context" id="assignModalContext"></p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
<form class="domain-form" id="assignForm"><div class="form-error" role="alert" hidden></div><label class="form-field"><span><?= $programView ? 'Search Subjects' : 'Search program or strand' ?></span><div class="record-autocomplete"><input type="search" id="assignSearch" placeholder="Search by code or name"/><input type="hidden" id="assignId"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear selection" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><div class="record-autocomplete-options" id="assignOptions" role="listbox" hidden></div></div><small id="assignHelp">Already assigned records are not available to select.</small></label>
<div class="selected-assignment"><h3 id="selectedTitle">Selected (0)</h3><ul id="selectedRecords"></ul></div><div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary" disabled>Assign</button></div></form></section></div>
<div class="curriculum-modal" id="removeModal" hidden><div class="modal-backdrop" data-modal-close></div><section class="modal-dialog modal-dialog-small" role="alertdialog" aria-modal="true" aria-labelledby="removeModalTitle"><div class="modal-header"><h2 id="removeModalTitle">Remove Subject Assignment</h2><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><div class="confirmation-body"><p id="removeMessage"></p><div class="form-error" role="alert" hidden></div></div><div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="button" class="button-danger" id="confirmRemove">Remove Assignment</button></div></section></div><?php endif; ?>
<div class="curriculum-modal" id="detailsModal" hidden><div class="modal-backdrop" data-modal-close></div><section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="detailsTitle"><div class="modal-header"><div><h2 id="detailsTitle">Assignment Details</h2></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><div class="confirmation-body" id="detailsBody"></div><div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Close</button></div></section></div>

<div class="sidebar-overlay" id="sidebarOverlay"></div><script src="../assets/curriculum-common.js?v=<?= $commonVersion ?>"></script><script src="../../../js/csms-menu.js?v=<?= (int)filemtime(__DIR__ . '/../../../js/csms-menu.js') ?>"></script><script src="../assets/program-assignment.js?v=<?= $scriptVersion ?>"></script></body></html>
