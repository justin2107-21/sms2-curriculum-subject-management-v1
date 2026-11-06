<?php
// Curriculum Builder workspace: one page for a version's plan, details and review. Included by curriculum_builder.php.
require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('curriculum.view');

$userId = (int)current_user_id();
$can = static fn(string $permission): bool => user_can($userId, $permission);
$assetVersion = static fn(string $file): int => (int)filemtime(__DIR__ . '/../assets/' . $file);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../../../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <title>Curriculum Builder - BCP Student Management System</title>
  <link rel="stylesheet" href="../../../css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/curriculum.css?v=<?= $assetVersion('curriculum.css') ?>"/>
  <link rel="stylesheet" href="../assets/subject-mapping.css?v=<?= $assetVersion('subject-mapping.css') ?>"/>
  <link rel="stylesheet" href="../../../css/csms-typography.css?v=<?= (int)filemtime(__DIR__ . '/../../../css/csms-typography.css') ?>"/>
  <link rel="stylesheet" href="../assets/subject-mapping-final.css?v=<?= $assetVersion('subject-mapping-final.css') ?>"/>
  <link rel="stylesheet" href="../assets/builder-workspace.css?v=<?= $assetVersion('builder-workspace.css') ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../../../';
$ACTIVE_NAV = 'curriculum-builder';
require __DIR__ . '/../../../shared/sidebar.php';
?>

<div class="main">
  <?php require __DIR__ . '/../../../shared/topbar.php'; ?>

  <main class="content curriculum-content mapping-content builder-content" id="builderRoot"
        data-program-api="../http/program_actions.php"
        data-curriculum-api="../http/curriculum_actions.php"
        data-version-api="../http/version_actions.php"
        data-assignment-api="../http/assignment_actions.php"
        data-requirement-api="../http/requirement_actions.php"
        data-review-api="../http/review_actions.php"
        data-reference-api="../http/version_reference_actions.php"
        data-compliance-api="../http/compliance_actions.php"
        data-user-id="<?= $userId ?>"
        data-can-submit="<?= $can('curriculum.review.submit') ? '1' : '0' ?>"
        data-can-decide="<?= $can('curriculum.review.decide') ? '1' : '0' ?>"
        data-can-classify="<?= $can('curriculum.reference.classify') ? '1' : '0' ?>"
        data-can-attach="<?= $can('curriculum.reference.attach') ? '1' : '0' ?>"
        data-can-clone="<?= $can('curriculum.version.clone') ? '1' : '0' ?>"
        data-can-edit-plan="<?= $can('curriculum.update') ? '1' : '0' ?>"
        data-can-create-curriculum="<?= $can('curriculum.create') ? '1' : '0' ?>"
        data-can-create-version="<?= $can('curriculum.version.create') ? '1' : '0' ?>"
        data-can-pick-subject="<?= $can('subject.view') ? '1' : '0' ?>"
        data-can-view-prerequisites="<?= $can('prerequisite.view') ? '1' : '0' ?>"
        data-can-export="<?= $can('curriculum_export.view') ? '1' : '0' ?>"
        data-can-export-draft="<?= $can('curriculum_export.draft') ? '1' : '0' ?>"
        data-can-export-official="<?= $can('curriculum_export.official') ? '1' : '0' ?>"
        data-can-history="<?= $can('subject_history.view') ? '1' : '0' ?>">
    <div class="mapping-workspace-header">
      <div><h1 class="page-title">Curriculum Builder</h1><p class="page-subtitle">Plan, review and approve a curriculum version.</p></div>
      <span id="builderAccessBadge" hidden></span>
    </div>
    <section class="mapping-workspace-shell">
      <div class="builder-context-row">
      <section class="mapping-context-bar" aria-label="Curriculum context" data-ctx-chip="off">
        <?php foreach (['program' => 'Program / Strand', 'curriculum' => 'Curriculum', 'version' => 'Curriculum Version'] as $key => $label): ?>
        <div class="mapping-context-field"><label for="<?= $key ?>Search"><?= $label ?></label><div class="record-autocomplete">
          <input type="search" id="<?= $key ?>Search" placeholder="<?= $key === 'program' ? 'Search program code or name' : ($key === 'curriculum' ? 'Choose program first' : 'Choose curriculum first') ?>" <?= $key !== 'program' ? 'disabled' : '' ?> autocomplete="off" role="combobox" aria-expanded="false" aria-controls="<?= $key ?>Options"/>
          <input type="hidden" id="<?= $key ?>Id"/>
          <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear <?= $label ?>" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
          <div class="record-autocomplete-options" id="<?= $key ?>Options" role="listbox" aria-label="<?= $label ?> suggestions" hidden></div>
        </div></div>
        <?php if ($key !== 'version'): ?><span class="mapping-context-separator" aria-hidden="true"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></span><?php endif; ?>
        <?php endforeach; ?>
      </section>
      <div class="builder-header__actions"><div class="builder-actions" id="builderActions"></div><div class="builder-actions" id="builderLinks"></div></div>
      </div>

      <div id="builderState" class="builder-state" role="status"><i class="fa-solid fa-table-list" aria-hidden="true"></i><h2>Choose a curriculum version to begin</h2><p>Pick a program, curriculum and version above. Your last choice is remembered.</p></div>

      <section id="builderVersion" class="builder-version" hidden aria-labelledby="builderVersionTitle">
        <header class="builder-header">
          <div class="builder-head__left">
            <p class="builder-eyebrow">VERSION WORKSPACE</p>
            <div class="builder-title-row"><h2 id="builderVersionTitle"></h2>
              <div class="builder-more" id="builderMore" hidden><button type="button" class="builder-more__btn" id="builderMoreBtn" aria-haspopup="menu" aria-expanded="false" aria-controls="builderMoreMenu" aria-label="More actions" title="More actions"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button>
                <div class="builder-more__menu" id="builderMoreMenu" role="menu" aria-label="More actions" hidden></div></div></div>
            <p class="builder-head__sub" id="builderVersionSub"></p>
          </div>
          <div class="builder-head__mid">
            <div class="builder-chips" id="builderVersionBadges"></div>
            <p class="builder-head__edit" id="builderLastEdit"></p>
          </div>
          <div class="builder-head__right">
            <p class="builder-head__label">Total curriculum units</p>
            <p class="builder-head__units" id="builderUnits">—</p>
            <p class="builder-head__target" id="builderTarget"></p>
            <p class="builder-head__conflicts" id="builderConflicts"></p>
          </div>
        </header>
        <p class="builder-next" id="builderNext" hidden></p>

        <div class="builder-tabs" role="tablist" aria-label="Version sections">
          <button type="button" id="tabPlan" role="tab" aria-selected="true" aria-controls="panelPlan" data-tab="plan">Plan</button>
          <button type="button" id="tabDetails" role="tab" aria-selected="false" aria-controls="panelDetails" tabindex="-1" data-tab="details">Details and references</button>
          <button type="button" id="tabReview" role="tab" aria-selected="false" aria-controls="panelReview" tabindex="-1" data-tab="review">Review history</button>
        </div>

        <section id="panelPlan" role="tabpanel" aria-labelledby="tabPlan" class="builder-panel">
          <div class="builder-plan-bar"><div class="builder-plan-search" id="builderPlanSearchBox"><label for="builderPlanSearch">Find a subject</label><div class="builder-plan-search__row"><input type="search" id="builderPlanSearch" placeholder="Code or title" autocomplete="off" maxlength="100" aria-describedby="builderPlanSearchStatus"/><span class="builder-plan-search__count" id="builderPlanSearchStatus" role="status"></span></div></div>
            <p class="builder-plan-note" id="builderPlanNote"></p><button type="button" class="primary-action-button" id="builderAddOpen" hidden><i class="fa-solid fa-plus" aria-hidden="true"></i> Add subject</button></div>
          <div id="builderPlan" class="builder-plan" aria-live="polite"></div>
        </section>

        <section id="panelDetails" role="tabpanel" aria-labelledby="tabDetails" class="builder-panel" hidden>
          <section class="builder-card"><h3>Version details</h3><dl class="builder-dl" id="builderDetails"></dl></section>
          <section class="builder-card">
            <div class="builder-panel__bar"><h3>Attached sources</h3><?php if ($can('curriculum.reference.attach')): ?><button type="button" class="secondary-action-button" id="builderAttachOpen" hidden><i class="fa-solid fa-paperclip" aria-hidden="true"></i> Attach source</button><?php endif; ?></div>
            <div class="table-scroll" id="builderReferencesTable"><table class="domain-table"><thead><tr><th>Attached source</th><th>Purpose</th><th>Origin and assessment</th><th>Attached by</th></tr></thead><tbody id="builderReferences"></tbody></table></div>
            <div class="builder-empty" id="builderReferencesEmpty" hidden><i class="fa-solid fa-paperclip" aria-hidden="true"></i><strong>No sources attached</strong><span>Documentary sources for this version appear here.</span></div>
          </section>
        </section>

        <section id="panelReview" role="tabpanel" aria-labelledby="tabReview" class="builder-panel" hidden>
          <section class="builder-card"><h3>Review history</h3>
            <div class="table-scroll" id="builderHistoryTable"><table class="domain-table"><thead><tr><th>Date</th><th>By</th><th>Action</th><th>Status</th><th>Reason</th></tr></thead><tbody id="builderHistory"></tbody></table></div>
            <div class="builder-empty" id="builderHistoryEmpty" hidden><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i><strong>No review activity yet</strong><span>Submissions and decisions appear here.</span></div>
          </section>
        </section>
      </section>
    </section>
  </main>

  <div class="footer">Bestlink College of the Philippines &middot; CSMS-SMS2 &copy; 2026</div>
</div>

<?php if ($can('curriculum.create') || $can('curriculum.update')): ?>
<!-- New or renamed curriculum -->
<div class="curriculum-modal" id="builderCurriculumModal" hidden aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-medium" role="dialog" aria-modal="true" aria-labelledby="builderCurriculumTitle">
    <div class="modal-header"><div><h2 id="builderCurriculumTitle">New curriculum</h2><p class="modal-context" id="builderCurriculumContext"></p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <form id="builderCurriculumForm" class="domain-form" novalidate>
      <input type="hidden" name="id"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field form-field-wide" id="builderCurriculumProgramField"><span>Program / Strand <strong>*</strong></span><select name="academic_program_id" required></select></label>
        <label class="form-field"><span>Curriculum code <strong>*</strong></span><input name="curriculum_code" maxlength="80" required/></label>
        <label class="form-field"><span>Curriculum name <strong>*</strong></span><input name="curriculum_name" maxlength="255" required/></label>
        <label class="form-field form-field-wide" id="builderFirstVersionField"><span>First version label <strong>*</strong></span><input name="first_version_label" maxlength="100" value="v1"/><small>A curriculum is opened through a version, so it starts with an editable one.</small></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary" id="builderCurriculumSubmit">Create curriculum</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<?php if ($can('curriculum.version.create') || $can('curriculum.update')): ?>
<!-- New or renamed version -->
<div class="curriculum-modal" id="builderVersionModal" hidden aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-medium" role="dialog" aria-modal="true" aria-labelledby="builderVersionFormTitle">
    <div class="modal-header"><div><h2 id="builderVersionFormTitle">New version</h2><p class="modal-context" id="builderVersionFormContext"></p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <form id="builderVersionForm" class="domain-form" novalidate>
      <input type="hidden" name="id"/><input type="hidden" name="curriculum_id"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field"><span>Version label <strong>*</strong></span><input name="version_label" maxlength="100" required/></label>
        <label class="form-field" id="builderVersionYearField"><span>Effective academic year (optional)</span><input name="effective_academic_year" maxlength="50"/></label>
        <label class="form-field form-field-wide" id="builderVersionDescField"><span>Description (optional)</span><textarea name="description" maxlength="60000" rows="3"></textarea></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary" id="builderVersionSubmit">Create version</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<!-- Prerequisite conflict details -->
<div class="curriculum-modal" id="builderConflictModal" hidden aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-medium" role="dialog" aria-modal="true" aria-labelledby="builderConflictTitle">
    <div class="modal-header"><div><h2 id="builderConflictTitle">Prerequisite conflict</h2><p class="modal-context" id="builderConflictSubject"></p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="builder-conflict-body" id="builderConflictBody"></div>
  </section>
</div>

<?php if ($can('curriculum.update')): ?>
<!-- Plan editing: move menu, add and remove dialogs -->
<div class="builder-move-menu" id="builderMoveMenu" role="menu" aria-label="Move to term" hidden></div>
<?php if ($can('subject.view')): ?>
<div class="curriculum-modal" id="builderAddModal" hidden aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-medium" role="dialog" aria-modal="true" aria-labelledby="builderAddTitle">
    <div class="modal-header"><div><h2 id="builderAddTitle">Add subject</h2><p class="modal-context" id="builderAddContext"></p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <form id="builderAddForm" class="domain-form" novalidate>
      <input type="hidden" name="curriculum_version_id"/><input type="hidden" name="academic_program_id"/><input type="hidden" name="curriculum_id"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <div class="form-field form-field-wide"><label for="builderAddSubject">Subject <strong>*</strong></label><div class="record-autocomplete">
          <input type="search" id="builderAddSubject" placeholder="Search subject code or title" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="builderAddOptions" aria-describedby="builderAddHint"/>
          <input type="hidden" name="subject_id" id="builderAddSubjectId"/>
          <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear subject" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
          <div class="record-autocomplete-options" id="builderAddOptions" role="listbox" aria-label="Subject suggestions" hidden></div>
        </div><small id="builderAddHint"></small></div>
        <label class="form-field"><span>Year / Grade level <strong>*</strong></span><select name="year_level" id="builderAddYear" required></select></label>
        <label class="form-field"><span>Semester <strong>*</strong></span><select name="semester" id="builderAddSemester" required></select></label>
        <label class="form-field"><span>Placement <strong>*</strong></span><select name="is_required" required><option value="1">Required</option><option value="0">Optional</option></select></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Add subject</button></div>
    </form>
  </section>
</div>
<?php endif; ?>
<div class="curriculum-modal" id="builderRemoveModal" hidden aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-medium" role="dialog" aria-modal="true" aria-labelledby="builderRemoveTitle">
    <div class="modal-header"><div><h2 id="builderRemoveTitle">Remove subject</h2><p class="modal-context" id="builderRemoveContext"></p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <form id="builderRemoveForm" class="domain-form" novalidate>
      <div class="form-error" role="alert" hidden></div>
      <p id="builderRemoveText"></p>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-danger">Remove</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<!-- Review action: submit, approve or return -->
<div class="curriculum-modal" id="builderReviewModal" hidden aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-medium" role="dialog" aria-modal="true" aria-labelledby="builderReviewTitle">
    <div class="modal-header"><div><h2 id="builderReviewTitle">Review</h2><p class="modal-context" id="builderReviewContext"></p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <form id="builderReviewForm" class="domain-form" novalidate>
      <div class="form-error" role="alert" hidden></div>
      <label class="form-field form-field-wide"><span id="builderReviewLabel">Comment</span><textarea name="reason" rows="3" maxlength="2000"></textarea></label>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary" id="builderReviewSubmit">Confirm</button></div>
    </form>
  </section>
</div>

<?php if ($can('curriculum.reference.attach')): ?>
<div class="curriculum-modal" id="builderAttachModal" hidden aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-medium" role="dialog" aria-modal="true" aria-labelledby="builderAttachTitle">
    <div class="modal-header"><div><h2 id="builderAttachTitle">Attach documentary source</h2></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <form id="builderAttachForm" class="domain-form">
      <input type="hidden" name="curriculum_version_id"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field form-field-wide"><span>Source record <strong>*</strong></span><select name="compliance_reference_id" required><option value="">Loading source records...</option></select></label>
        <label class="form-field"><span>Purpose <strong>*</strong></span><select name="reference_purpose" required><option value="school_curriculum">Curriculum source</option><option value="regulatory">Regulatory context</option><option value="institutional_policy">Institutional policy</option><option value="other">Other</option></select></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button class="button-primary" type="submit">Attach source</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<?php if ($can('curriculum.reference.classify')): ?>
<div class="curriculum-modal" id="builderClassifyModal" hidden aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-medium" role="dialog" aria-modal="true" aria-labelledby="builderClassifyTitle">
    <div class="modal-header"><div><h2 id="builderClassifyTitle">Classify as protected reference</h2></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <form id="builderClassifyForm" class="domain-form">
      <input type="hidden" name="curriculum_version_id"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field form-field-wide" id="builderClassifySource"><span>Primary curriculum source <strong>*</strong></span><select name="compliance_reference_id" required></select></label>
        <?php if ($can('curriculum.reference.attach')): ?><div class="form-field-wide builder-classify-attach" id="builderClassifyAttach" hidden><button type="button" class="secondary-action-button" id="builderClassifyAttachOpen"><i class="fa-solid fa-paperclip" aria-hidden="true"></i> Attach a source</button></div><?php endif; ?>
        <label class="form-field"><span>Classification basis <strong>*</strong></span><select name="classification_basis" required><option value="ched_sample">CHED sample / demonstration</option><option value="institutional_source">Institution-provided curriculum source</option><option value="other_document">Other documented source</option></select></label>
        <label class="form-field form-field-wide"><span><input type="checkbox" name="confirm_reference_protection" value="1" required/> I confirm that this action makes the version an immutable reference and does not indicate BCP adoption or approval.</span></label>
      </div>
      <p class="curriculum-notice">Identity, origin, and applicability assessments remain separate. Institutional adoption/approval not verified.</p>
      <p class="builder-disabled-reason" id="builderClassifyReason" hidden></p>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button class="button-primary" type="submit">Capture evidence and classify</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<?php if ($can('curriculum.version.clone')): ?>
<div class="curriculum-modal" id="builderCloneModal" hidden aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-medium" role="dialog" aria-modal="true" aria-labelledby="builderCloneTitle">
    <div class="modal-header"><div><h2 id="builderCloneTitle">Create revision from this reference</h2></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <form id="builderCloneForm" class="domain-form">
      <input type="hidden" name="source_version_id"/><input type="hidden" name="curriculum_id"/>
      <p id="builderCloneScope" class="curriculum-notice">Checking the protected reference and copy scope…</p>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field"><span>Revision label <strong>*</strong></span><input name="version_label" maxlength="100" required/></label>
        <label class="form-field"><span>Effective academic year (optional)</span><input name="effective_academic_year" maxlength="50"/></label>
        <label class="form-field form-field-wide"><span>Description (optional)</span><textarea name="description" maxlength="60000" rows="3"></textarea></label>
        <label class="form-field form-field-wide"><span><input type="checkbox" name="confirm_reference_unchanged" value="1" required/> Create an independent editable revision; preserve the reference and its historical evidence unchanged.</span></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button class="button-primary" type="submit" disabled>Create revision</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $assetVersion('curriculum-common.js') ?>"></script>
<script src="../assets/placement-groups.js?v=<?= $assetVersion('placement-groups.js') ?>"></script>
<script src="../assets/builder-workspace.js?v=<?= $assetVersion('builder-workspace.js') ?>"></script>
</body>
</html>
