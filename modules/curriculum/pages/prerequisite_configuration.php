<?php
require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('prerequisite.view');

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
        data-can-manage-requirements="<?= $canManageRequirements ? '1' : '0' ?>">
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <h1 class="page-title"><i class="fa-solid fa-code-branch"></i> Pre-requisite Configuration</h1>
        <p class="page-subtitle">Configure and review prerequisite and corequisite relationships within curriculum versions.</p>
      </div>
      <span class="read-only-pill"><i class="fa-solid fa-shield-halved"></i> Curriculum configuration only</span>
    </div>

    <section class="domain-card mapping-filter-card" aria-labelledby="requirementFiltersTitle">
      <div class="domain-card-header">
        <div>
          <p class="eyebrow">Progressive filters</p>
          <h2 id="requirementFiltersTitle">Curriculum Version Context</h2>
        </div>
        <button type="button" class="button-muted" id="clearRequirementFilters">
          <i class="fa-solid fa-rotate-left"></i> Clear Filters
        </button>
      </div>
      <div class="mapping-filter-grid prerequisite-filter-grid">
        <label class="mapping-filter-field">
          <span>Academic Program</span>
          <select id="requirementProgramFilter">
            <option value="">All available programs</option>
          </select>
        </label>
        <label class="mapping-filter-field">
          <span>Curriculum</span>
          <select id="requirementCurriculumFilter">
            <option value="">All available curricula</option>
          </select>
        </label>
        <label class="mapping-filter-field">
          <span>Curriculum Version</span>
          <select id="requirementVersionFilter" disabled>
            <option value="">Select a curriculum first</option>
          </select>
        </label>
        <label class="mapping-filter-field mapping-search-field">
          <span>Target Subject Search</span>
          <span class="mapping-input-with-icon">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" id="requirementSubjectSearch" maxlength="100" placeholder="Subject code or title"/>
          </span>
        </label>
        <label class="mapping-filter-field">
          <span>Relationship Type</span>
          <select id="requirementTypeFilter">
            <option value="">All relationship types</option>
            <option value="prerequisite">Prerequisite</option>
            <option value="corequisite">Corequisite</option>
          </select>
        </label>
      </div>
    </section>

    <section class="domain-card mapping-results-card" aria-labelledby="requirementResultsTitle">
      <div class="domain-card-header">
        <div>
          <p class="eyebrow">Stored subject requirements</p>
          <h2 id="requirementResultsTitle">Prerequisite &amp; Corequisite Relationships</h2>
          <p class="mapping-context-message" id="requirementContextMessage">Showing all configured subject requirements.</p>
        </div>
        <div class="card-header-actions">
          <span class="record-count" id="requirementCount">0 records</span>
          <?php if ($canManageRequirements): ?>
          <button type="button" class="primary-action-button" id="addRequirementButton" disabled>
            <i class="fa-solid fa-plus"></i> Add Requirement
          </button>
          <?php endif; ?>
        </div>
      </div>
      <div class="mapping-results-toolbar">
        <span class="placement-note" id="requirementHelpText">Select a curriculum version before adding a relationship. These records do not enforce enrollment eligibility.</span>
        <label class="select-control compact-select">
          <span>Rows</span>
          <select id="requirementPerPage"><option>10</option><option selected>20</option><option>50</option></select>
        </label>
      </div>
      <div class="table-scroll">
        <table class="domain-table prerequisite-table">
          <thead>
            <tr>
              <th>Target Subject</th>
              <th>Relationship</th>
              <th>Required Subject</th>
              <th>Target Placement</th>
              <th>Required Placement</th>
              <th>Academic Program</th>
              <th>Curriculum</th>
              <th>Version</th>
              <?php if ($canManageRequirements): ?><th>Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody id="requirementRows">
            <tr><td colspan="<?= $columnCount ?>" class="state-cell">Loading subject requirements…</td></tr>
          </tbody>
        </table>
      </div>
      <div class="pagination-bar" id="requirementPagination"></div>
    </section>
  </main>

  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<?php if ($canManageRequirements): ?>
<div class="curriculum-modal" id="addRequirementModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="addRequirementTitle">
    <div class="modal-header">
      <div>
        <h2 id="addRequirementTitle">Add Subject Requirement</h2>
        <p class="modal-context" id="addRequirementContext"></p>
      </div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close add subject requirement form"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form class="domain-form" id="addRequirementForm">
      <input type="hidden" name="curriculum_version_id" id="requirementVersionId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid requirement-form-grid">
        <label class="form-field">
          <span>Find Target Subject</span>
          <input type="search" id="targetSubjectSearch" maxlength="100" placeholder="Subject code or title"/>
        </label>
        <label class="form-field">
          <span>Find Required Subject</span>
          <input type="search" id="requiredSubjectSearch" maxlength="100" placeholder="Subject code or title"/>
        </label>
        <label class="form-field">
          <span>Target Subject <strong>*</strong></span>
          <select name="subject_id" id="targetSubjectId" required><option value="">Select a mapped subject</option></select>
        </label>
        <label class="form-field">
          <span>Required Subject <strong>*</strong></span>
          <select name="required_subject_id" id="requiredSubjectId" required><option value="">Select a mapped subject</option></select>
        </label>
        <label class="form-field form-field-wide">
          <span>Relationship Type <strong>*</strong></span>
          <select name="requirement_type" id="requirementType" required>
            <option value="prerequisite">Prerequisite</option>
            <option value="corequisite">Corequisite</option>
          </select>
          <small>This configuration records curriculum relationships only; no enrollment consequence is applied.</small>
        </label>
      </div>
      <div class="modal-actions">
        <button type="button" class="button-muted" data-modal-close>Cancel</button>
        <button type="submit" class="button-primary">Add Requirement</button>
      </div>
    </form>
  </section>
</div>

<div class="curriculum-modal" id="removeRequirementModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-small" role="alertdialog" aria-modal="true" aria-labelledby="removeRequirementTitle">
    <div class="modal-header">
      <div><h2 id="removeRequirementTitle">Remove Subject Requirement?</h2></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close removal confirmation"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="confirmation-body">
      <p id="removeRequirementMessage">Only this prerequisite or corequisite relationship will be removed.</p>
      <div class="form-error" role="alert" hidden></div>
    </div>
    <div class="modal-actions modal-actions-padded">
      <button type="button" class="button-muted" data-modal-close>Cancel</button>
      <button type="button" class="button-danger" id="confirmRemoveRequirement">Remove Relationship</button>
    </div>
  </section>
</div>
<?php endif; ?>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $curriculumCommonVersion ?>"></script>
<script src="../assets/prerequisite-configuration.js?v=<?= $prerequisiteScriptVersion ?>"></script>
</body>
</html>
