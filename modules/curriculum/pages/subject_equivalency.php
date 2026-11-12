<?php
// Subject Equivalency Tool: one page. subject_comparison.php and equivalency_records.php redirect here (302)
// with their query string, so old links and bookmarks keep working.
require_once __DIR__ . '/../../../shared/bootstrap.php';
require_once __DIR__ . '/../validators/DomainValidator.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('equivalency.view');

$userId = current_user_id();
$canManage = $userId !== null && user_can($userId, 'equivalency.manage');
$reasonMin = DomainValidator::EQUIVALENCY_REASON_MIN;
$reasonMax = DomainValidator::EQUIVALENCY_REASON_MAX;
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
  <title>Subject Equivalency Tool - BCP Student Management System</title>
  <link rel="stylesheet" href="../../../css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/curriculum.css?v=<?= $v('../assets/curriculum.css') ?>"/>
  <link rel="stylesheet" href="../assets/subject-mapping.css?v=<?= $v('../assets/subject-mapping.css') ?>"/>
  <link rel="stylesheet" href="../../../css/csms-typography.css?v=<?= $v('../../../css/csms-typography.css') ?>"/>
  <link rel="stylesheet" href="../assets/subject-mapping-final.css?v=<?= $v('../assets/subject-mapping-final.css') ?>"/>
  <link rel="stylesheet" href="../assets/subject-equivalency.css?v=<?= $v('../assets/subject-equivalency.css') ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../../../';
$ACTIVE_NAV = 'subject-equivalency';
require __DIR__ . '/../../../shared/sidebar.php';
?>
<div class="main">
  <?php require __DIR__ . '/../../../shared/topbar.php'; ?>
  <main class="content curriculum-content mapping-content subject-equivalency-content" id="subjectEquivalencyWorkspace"
        data-equivalency-api="../http/equivalency_actions.php" data-can-manage="<?= $canManage ? '1' : '0' ?>"
        data-reason-min="<?= $reasonMin ?>" data-reason-max="<?= $reasonMax ?>">
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <h1 class="page-title"><i class="fa-solid fa-right-left" aria-hidden="true"></i> Subject Equivalency Tool <span class="csms-info"><button type="button" class="csms-info__btn" aria-label="About the Subject Equivalency Tool" aria-describedby="equivalencyInfoTip"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button><span class="csms-info__tip" role="tooltip" id="equivalencyInfoTip">Advisory record. A decision never changes placements or credits automatically.</span></span></h1>
        <p class="page-subtitle">Record and review a mutual equivalency decision between two Subject Master records.</p>
      </div>
      <div class="card-header-actions">
        <?php if (!$canManage): ?><span class="csms-chip csms-chip--draft">Read-only for your role</span><?php endif; ?>
        <?php if ($canManage): ?><button type="button" class="primary-action-button" id="recordDecisionButton"><i class="fa-solid fa-plus" aria-hidden="true"></i> Record decision</button><?php endif; ?>
      </div>
    </div>

    <section class="mapping-workspace-shell">
      <section class="domain-card mapping-results-card" aria-label="Equivalency records">
        <div class="csms-empty" id="equivalencyLoading" role="status"><i class="fa-solid fa-spinner" aria-hidden="true"></i><strong>Loading equivalency records…</strong></div>
        <div class="csms-empty" id="equivalencyEmpty" role="status" hidden><i class="fa-solid fa-right-left" aria-hidden="true"></i><strong>No equivalency decisions recorded yet</strong><span><?= $canManage ? 'Record the first decision between two Subject Master records.' : 'Decisions appear here once an authorized reviewer records them.' ?></span><?php if ($canManage): ?><button type="button" class="primary-action-button" data-record-decision><i class="fa-solid fa-plus" aria-hidden="true"></i> Record decision</button><?php endif; ?></div>
        <div class="csms-empty" id="equivalencyError" role="alert" hidden><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><strong>Records could not be loaded</strong><span id="equivalencyErrorText"></span></div>
        <div id="equivalencyList" hidden>
          <div class="csms-filter-row csms-page-bar">
            <label class="csms-search"><span class="sr-only">Search equivalency records</span><input type="search" id="equivalencySearch" maxlength="100" placeholder="Search subject code or title"/></label>
            <select class="csms-select" id="equivalencyStatus" aria-label="Decision"><option value="">All active decisions</option><option value="equivalent">Equivalent</option><option value="not_equivalent">Not equivalent</option><option value="needs_review">Needs review</option><option value="archived">Archived</option></select>
            <select class="csms-select" id="equivalencyPerPage" aria-label="Rows per page"><option value="10">10 rows</option><option value="20" selected>20 rows</option><option value="50">50 rows</option></select>
            <span class="csms-filter-row__summary" id="equivalencyCount" role="status">0 records</span>
          </div>
          <div class="csms-empty" id="equivalencyNoMatch" role="status" hidden><i class="fa-solid fa-filter" aria-hidden="true"></i><strong>No records match</strong><span>Change the search or the Decision filter.</span></div>
          <div class="csms-page-table table-scroll" id="equivalencyTableWrap" tabindex="0" aria-label="Scrollable equivalency records">
            <table class="csms-ctable">
              <thead><tr><th>Subject A</th><th>Subject B</th><th>Decision</th><th>Reason</th><th>Reviewed by</th><th>Reviewed at</th><th class="csms-ctable__action"><span class="sr-only">Actions</span></th></tr></thead>
              <tbody id="equivalencyRows"></tbody>
            </table>
          </div>
          <div class="pagination-bar" id="equivalencyPagination"></div>
        </div>
        <div id="equivalencyRowMenu" class="csms-menu" role="menu" aria-label="Row actions" hidden></div>
      </section>
    </section>
  </main>
  <div class="footer">Bestlink College of the Philippines &middot; CSMS-SMS2 &copy; 2026</div>
</div>

<div class="curriculum-modal" id="equivalencyDialog" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-large" role="dialog" aria-modal="true" aria-labelledby="equivalencyDialogTitle">
    <div class="modal-header"><div><h2 id="equivalencyDialogTitle">Record decision</h2><p class="modal-context" id="equivalencyDialogContext">Mutual equivalency between two Subject Master records.</p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <form class="domain-form" id="equivalencyForm" novalidate>
      <div class="confirmation-body">
        <div class="form-error" role="alert" hidden></div>
        <p class="equivalency-notice" id="equivalencyNotice" role="status" hidden></p>
        <input type="hidden" name="id" id="equivalencyRecordId"/>
        <div class="form-grid equivalency-pickers">
          <?php foreach (['A' => 'Subject A', 'B' => 'Subject B'] as $side => $label): ?>
          <label class="form-field" for="subject<?= $side ?>Search"><span><?= $label ?></span>
            <span class="record-autocomplete"><input type="search" id="subject<?= $side ?>Search" placeholder="Search subject code or title" autocomplete="off"/><input type="hidden" name="subject_<?= strtolower($side) ?>_id" id="subject<?= $side ?>Select"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear <?= $label ?>" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><span class="record-autocomplete-options" id="subject<?= $side ?>Options" role="listbox" aria-label="<?= $label ?> suggestions" hidden></span></span>
          </label>
          <?php endforeach; ?>
        </div>

        <div class="csms-empty" id="comparisonEmpty" role="status"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i><strong>Choose two different subjects</strong><span>The stored evidence for both appears here side by side.</span></div>
        <div class="csms-empty" id="comparisonLoading" role="status" hidden><i class="fa-solid fa-spinner" aria-hidden="true"></i><strong>Loading stored evidence…</strong></div>
        <div id="comparisonResult" hidden>
          <h3 class="equivalency-section-title">Stored evidence <span class="csms-info"><button type="button" class="csms-info__btn" aria-label="About the evidence" aria-describedby="evidenceInfoTip"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button><span class="csms-info__tip" role="tooltip" id="evidenceInfoTip">Values come from the Subject Master and from curriculum versions. "Differs" and "Same" compare stored values only; there is no similarity score, and similar usage is not proof of equivalency.</span></span></h3>
          <div class="csms-page-table table-scroll" tabindex="0" aria-label="Scrollable evidence comparison">
            <table class="csms-ctable equivalency-evidence">
              <thead><tr><th>Evidence</th><th id="evidenceHeadA">Subject A</th><th id="evidenceHeadB">Subject B</th><th>Compared</th></tr></thead>
              <tbody id="comparisonRows"></tbody>
            </table>
          </div>
          <p class="modal-context" id="comparisonScopeNote" hidden></p>
        </div>

        <dl class="csms-detail-list" id="equivalencyViewDetails" hidden></dl>
        <div class="form-grid" id="equivalencyDecisionFields" hidden>
          <label class="form-field" for="equivalencyDecision"><span>Decision <strong>*</strong></span>
            <select class="csms-select" name="decision_status" id="equivalencyDecision"><option value="">Select a decision</option><option value="equivalent">Equivalent</option><option value="not_equivalent">Not equivalent</option><option value="needs_review">Needs review</option></select></label>
          <label class="form-field form-field-wide" for="equivalencyReason"><span>Reason <strong>*</strong></span>
            <textarea name="remarks" id="equivalencyReason" rows="4" maxlength="<?= $reasonMax ?>" aria-describedby="equivalencyReasonHint equivalencyReasonError"></textarea>
            <small id="equivalencyReasonHint"><span id="equivalencyReasonCount">0</span> / <?= $reasonMax ?> characters, at least <?= $reasonMin ?>. Written by you; CIMF advice is never copied here.</small>
            <small class="equivalency-field-error" id="equivalencyReasonError" role="alert" hidden></small></label>
        </div>
      </div>
      <div class="modal-actions modal-actions-padded">
        <button type="button" class="button-muted" data-modal-close id="equivalencyCancel">Cancel</button>
        <button type="button" class="button-muted" id="equivalencyCimf" disabled><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Review with CIMF</button>
        <?php if ($canManage): ?>
        <button type="button" class="button-muted" id="equivalencyEdit" hidden><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit decision</button>
        <button type="button" class="button-muted" id="equivalencyRestore" hidden><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Restore</button>
        <button type="submit" class="primary-action-button" id="equivalencySave" hidden disabled>Save decision</button>
        <?php endif; ?>
      </div>
    </form>
  </section>
</div>

<?php if ($canManage): ?>
<div class="curriculum-modal" id="equivalencyArchiveDialog" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-small" role="alertdialog" aria-modal="true" aria-labelledby="equivalencyArchiveTitle" aria-describedby="equivalencyArchiveText">
    <div class="modal-header"><div><h2 id="equivalencyArchiveTitle">Archive decision</h2></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="confirmation-body"><p id="equivalencyArchiveText">The record leaves the active list and stays in the audit history. You can restore it later from the Archived filter.</p></div>
    <div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="button" class="button-danger" id="equivalencyArchiveConfirm">Archive</button></div>
  </section>
</div>
<?php endif; ?>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $v('../assets/curriculum-common.js') ?>"></script>
<script src="../../../js/csms-menu.js?v=<?= $v('../../../js/csms-menu.js') ?>"></script>
<script src="../assets/subject-equivalency.js?v=<?= $v('../assets/subject-equivalency.js') ?>"></script>
</body>
</html>
