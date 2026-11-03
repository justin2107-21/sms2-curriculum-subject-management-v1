<?php
require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('prerequisite.view');
require_once __DIR__ . '/../services/CIMFConfig.php';
$cimfStatus = CIMFConfig::status(CIMFConfig::load());

$userId = current_user_id();
$canManageRequirements = $userId !== null && user_can($userId, 'prerequisite.manage');
$columnCount = $canManageRequirements ? 9 : 8;
$avatarInitial = strtoupper(substr((string)($_SESSION['first_name'] ?? 'U'), 0, 1));
$curriculumCssVersion = (int)filemtime(__DIR__ . '/../assets/curriculum.css');
$typographyCssVersion = (int)filemtime(__DIR__ . '/../../../css/csms-typography.css');
$mappingCssVersion = (int)filemtime(__DIR__ . '/../assets/subject-mapping.css');
$prerequisiteCssVersion = (int)filemtime(__DIR__ . '/../assets/prerequisite-configuration.css');
$curriculumCommonVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-common.js');
$prerequisiteScriptVersion = (int)filemtime(__DIR__ . '/../assets/prerequisite-configuration.js');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../../../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <title>Pre-requisite Configuration - BCP Student Management System</title>
  <link rel="stylesheet" href="../../../css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/curriculum.css?v=<?= $curriculumCssVersion ?>"/>
  <link rel="stylesheet" href="../assets/subject-mapping.css?v=<?= $mappingCssVersion ?>"/>
  <link rel="stylesheet" href="../assets/prerequisite-configuration.css?v=<?= $prerequisiteCssVersion ?>"/>
  <link rel="stylesheet" href="../../../css/csms-typography.css?v=<?= $typographyCssVersion ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../../../';
$ACTIVE_NAV = 'prerequisite-configuration';
require __DIR__ . '/../../../shared/sidebar.php';
?>

<div class="main">
  <?php require __DIR__ . '/../../../shared/topbar.php'; ?>

  <main class="content curriculum-content mapping-content prerequisite-content"
        id="prerequisiteWorkspace"
        data-program-api="../http/program_actions.php"
        data-curriculum-api="../http/curriculum_actions.php"
        data-version-api="../http/version_actions.php"
        data-requirement-api="../http/requirement_actions.php"
        data-cimf-api="../http/cimf_actions.php"
        data-cimf-status="<?= htmlspecialchars($cimfStatus, ENT_QUOTES, 'UTF-8') ?>"
        data-can-manage-requirements="<?= $canManageRequirements ? '1' : '0' ?>">
    <div class="page-title-bar curriculum-title-bar">
<div>
<h1 class="page-title">Pre-requisite Configuration</h1>
<p class="page-subtitle">Manage subject prerequisite rules and progression requirements.</p>
</div><div class="pr-title-actions"><?php if ($canManageRequirements): ?>
<button class="primary-action-button" id="addRequirementButton" disabled>+ Add Prerequisite</button><?php endif; ?>
</div>
</div>
    <section class="pr-panel pr-selectors" aria-label="Curriculum prerequisite context">
<label class="form-field"><span>Program / Strand</span>
<div class="record-autocomplete">
<input type="search" id="programSearch" placeholder="Search program / strand" />
<input type="hidden" id="programId"/>
<button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear Program / Strand" hidden>&times;</button>
<div class="record-autocomplete-options" id="programOptions" role="listbox" hidden>
</div>
</div>
</label>
<span class="pr-context-separator" aria-hidden="true"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></span>
<label class="form-field"><span>Curriculum</span>
<div class="record-autocomplete">
<input type="search" id="curriculumSearch" placeholder="Search curriculum" disabled/>
<input type="hidden" id="curriculumId"/>
<button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear Curriculum" hidden>&times;</button>
<div class="record-autocomplete-options" id="curriculumOptions" role="listbox" hidden>
</div>
</div>
</label>
<span class="pr-context-separator" aria-hidden="true"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></span>
<label class="form-field"><span>Curriculum Version</span>
<div class="record-autocomplete">
<input type="search" id="versionSearch" placeholder="Search curriculum version" disabled/>
<input type="hidden" id="versionId"/>
<button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear Curriculum Version" hidden>&times;</button>
<div class="record-autocomplete-options" id="versionOptions" role="listbox" hidden>
</div>
</div>
</label>
</section>
    <nav class="pr-tabs" role="tablist" aria-label="Prerequisite workspace">
<button role="tab" id="tab-rules" aria-controls="panel-rules" aria-selected="true" data-tab="rules">Prerequisite Rules</button>
<button role="tab" id="tab-chain" aria-controls="panel-chain" aria-selected="false" tabindex="-1" data-tab="chain">Chain Viewer</button>
<button role="tab" id="tab-review" aria-controls="panel-review" aria-selected="false" tabindex="-1" data-tab="review">Validation Review</button>
</nav>
    <p id="workspaceStatus" role="status"></p>
    <section id="panel-rules" role="tabpanel" aria-labelledby="tab-rules">
<div class="pr-toolbar">
<input type="search" id="ruleSearch" aria-label="Search subjects" placeholder="Search subject code or title..."/>
<select id="yearFilter" aria-label="Year or grade"><option value="">All Years</option></select>
<select id="semesterFilter" aria-label="Semester"><option value="">All Semesters</option></select>
<select id="stateFilter" aria-label="Configured state"><option value="">All Subjects</option><option value="with">With Prerequisites</option><option value="without">Without Prerequisites</option></select>
</div>
<div id="ruleCards" class="pr-rules">
</div>
</section>
    <section id="panel-chain" role="tabpanel" aria-labelledby="tab-chain" hidden>
<p class="eyebrow">FOCUSED DEPENDENCY INSPECTION</p>
<h2>Prerequisite Chain</h2>
<p class="pr-muted">View the dependency path leading to one selected subject.</p>
<div class="pr-chain-layout">
<aside class="pr-panel">
<label class="form-field"><span>Select Subject</span>
<div class="record-autocomplete">
<input type="search" id="focusSearch" placeholder="Search select subject" />
<input type="hidden" id="focusId"/>
<button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear Select Subject" hidden>&times;</button>
<div class="record-autocomplete-options" id="focusOptions" role="listbox" hidden>
</div>
</div>
</label>
<p class="pr-muted">Only stored prerequisite relationships are shown. No rules are inferred from subject codes.</p>
</aside>
<div class="pr-panel">
<header class="pr-chain-header"><span class="eyebrow">PREREQUISITE CHAIN</span>
<div>
<button type="button" class="button-muted" id="fitChain">Fit to View</button>
<button type="button" class="button-muted" id="resetChain">Reset</button>
</div>
</header>
<div id="chainViewport" tabindex="0" aria-label="Prerequisite chain. Scroll to inspect branches.">
<div id="chainCanvas">
</div>
</div>
<div id="dependents">
</div>
</div>
</div>
</section>
    <section id="panel-review" role="tabpanel" aria-labelledby="tab-review" hidden>
<div class="pr-review-grid" id="reviewCounts">
</div>
<div id="reviewIssues">
</div>
</section>
  </main>

  <div class="footer">eLearning Commons &copy; 2026</div>
</div>



<div class="curriculum-modal" id="detailsModal" hidden>
<div class="modal-backdrop" data-modal-close>
</div>
<section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="detailsModalTitle">
<div class="modal-header">
<h2 id="detailsModalTitle">Prerequisite Details</h2>
<button type="button" class="modal-close-button" data-modal-close aria-label="Close">&times;</button>
</div>
<div class="pr-modal-body">
<div id="detailsBody">
</div>
</div>
<div class="modal-actions modal-actions-padded">
<button type="button" class="button-muted" data-modal-close>Close</button><?php if ($canManageRequirements): ?>
<button class="button-muted" id="detailsEdit">Edit Rule</button>
<button class="button-danger" id="detailsRemove">Remove Rule</button><?php endif; ?>
</div>
</section>
</div><?php if ($canManageRequirements): ?>
<div class="curriculum-modal" id="editorModal" hidden>
<div class="modal-backdrop" data-modal-close>
</div>
<section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="editorModalTitle">
<div class="modal-header">
<h2 id="editorModalTitle">Add Prerequisite</h2>
<button type="button" class="modal-close-button" data-modal-close aria-label="Close">&times;</button>
</div>
<div class="pr-modal-body">
<form id="ruleForm" class="domain-form">
<div class="form-error" role="alert" hidden>
</div>
<div id="targetField">
<label class="form-field"><span>Target Subject <span aria-hidden="true">*</span><span class="sr-only">(required)</span></span>
<div class="record-autocomplete">
<input type="search" id="targetSearch" placeholder="Search target subject" aria-required="true" />
<input type="hidden" id="targetId"/>
<button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear target subject" hidden>&times;</button>
<div class="record-autocomplete-options" id="targetOptions" role="listbox" hidden>
</div>
</div>
</label>
</div>
<div id="fixedTarget" hidden>
</div>
<div id="existingRequired">
</div>
<div id="requiredField">
<label class="form-field"><span>Required Subject <span aria-hidden="true">*</span><span class="sr-only">(required)</span></span>
<div class="record-autocomplete">
<input type="search" id="requiredSearch" placeholder="Search required subject" aria-required="true" />
<input type="hidden" id="requiredId"/>
<button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear required subject" hidden>&times;</button>
<div class="record-autocomplete-options" id="requiredOptions" role="listbox" hidden>
</div>
</div>
</label>
</div>
<button type="button" class="button-muted" id="appendRequired" hidden>+ Add another prerequisite</button>
<div id="relationshipPreview">
</div>
<div id="sequenceWarning" class="form-error" role="alert" hidden></div><a id="editorMapping" href="subject_mapping.php" hidden>Review Subject Mapping</a>
</form>
</div>
<div class="modal-actions modal-actions-padded">
<button type="button" class="button-muted" data-modal-close>Cancel</button>
<button type="submit" form="ruleForm" class="button-primary" id="saveRule">Save Prerequisite</button>
</div>
</section>
</div>
<div class="curriculum-modal" id="removeModal" hidden>
<div class="modal-backdrop" data-modal-close>
</div>
<section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="removeModalTitle">
<div class="modal-header">
<h2 id="removeModalTitle">Remove Prerequisite Rule?</h2>
<button type="button" class="modal-close-button" data-modal-close aria-label="Close">&times;</button>
</div>
<div class="pr-modal-body">
<div class="form-error" role="alert" hidden>
</div>
<div id="removeMessage">
</div>
<p>This removes only the prerequisite relationship. It does not delete either subject or curriculum placement.</p>
</div>
<div class="modal-actions modal-actions-padded">
<button type="button" class="button-muted" data-modal-close>Cancel</button>
<button type="button" class="button-danger" id="confirmRemove">Remove Rule</button>
</div>
</section>
</div><?php endif; ?>



<div class="sidebar-overlay" id="sidebarOverlay">
</div>
<script src="../assets/curriculum-common.js?v=<?= $curriculumCommonVersion ?>"></script>
<script src="../assets/prerequisite-configuration.js?v=<?= $prerequisiteScriptVersion ?>"></script>
<script src="../assets/cimf-prerequisite.js?v=<?= $cimfScriptVersion ?>"></script>
</body>
</html>
