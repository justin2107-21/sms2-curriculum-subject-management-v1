<?php
require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('elective.view');

$userId = current_user_id();
$canManageElectives = $userId !== null && user_can($userId, 'elective.manage');
$groupColumnCount = $canManageElectives ? 7 : 6;
$membershipColumnCount = $canManageElectives ? 6 : 5;
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

  <main class="content curriculum-content mapping-content electives-content"
        id="electivesWorkspace"
        data-program-api="../http/program_actions.php"
        data-curriculum-api="../http/curriculum_actions.php"
        data-version-api="../http/version_actions.php"
        data-group-api="../http/elective_group_actions.php"
        data-membership-api="../http/elective_membership_actions.php"
        data-can-manage-electives="<?= $canManageElectives ? '1' : '0' ?>">
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <h1 class="page-title"><i class="fa-solid fa-layer-group"></i> Electives Manager</h1>
        <p class="page-subtitle">Configure elective groups and their curriculum-mapped subjects within curriculum versions.</p>
      </div>
      <span class="read-only-pill"><i class="fa-solid fa-sitemap"></i> Curriculum structure only</span>
    </div>

    <section class="domain-card mapping-filter-card" aria-labelledby="electiveContextTitle">
      <div class="domain-card-header">
        <div>
          <p class="eyebrow">Progressive context</p>
          <h2 id="electiveContextTitle">Curriculum Version</h2>
        </div>
        <button type="button" class="button-muted" id="clearElectiveFilters">
          <i class="fa-solid fa-rotate-left"></i> Clear Filters
        </button>
      </div>
      <div class="mapping-filter-grid elective-filter-grid">
        <label class="mapping-filter-field">
          <span>Academic Program</span>
          <select id="electiveProgramFilter">
            <option value="">All available programs</option>
          </select>
        </label>
        <label class="mapping-filter-field">
          <span>Curriculum</span>
          <select id="electiveCurriculumFilter">
            <option value="">Select a program or curriculum</option>
          </select>
        </label>
        <label class="mapping-filter-field">
          <span>Curriculum Version</span>
          <select id="electiveVersionFilter" disabled>
            <option value="">Select a curriculum first</option>
          </select>
        </label>
        <label class="mapping-filter-field mapping-search-field">
          <span>Group Search</span>
          <span class="mapping-input-with-icon">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" id="electiveGroupSearch" maxlength="100" placeholder="Elective group name"/>
          </span>
        </label>
      </div>
    </section>

    <section class="domain-card mapping-results-card" aria-labelledby="electiveGroupsTitle">
      <div class="domain-card-header">
        <div>
          <p class="eyebrow">Version-scoped containers</p>
          <h2 id="electiveGroupsTitle">Elective Groups</h2>
          <p class="mapping-context-message" id="electiveContextMessage">Select a curriculum version to manage elective groups.</p>
        </div>
        <div class="card-header-actions">
          <span class="record-count" id="electiveGroupCount">0 records</span>
          <?php if ($canManageElectives): ?>
          <button type="button" class="primary-action-button" id="createElectiveGroupButton" disabled>
            <i class="fa-solid fa-plus"></i> Create Elective Group
          </button>
          <?php endif; ?>
        </div>
      </div>
      <div class="mapping-results-toolbar">
        <span class="placement-note">Group membership does not change the existing required/optional curriculum placement indicator.</span>
        <label class="select-control compact-select">
          <span>Rows</span>
          <select id="electiveGroupPerPage"><option>10</option><option selected>20</option><option>50</option></select>
        </label>
      </div>
      <div class="table-scroll">
        <table class="domain-table elective-group-table">
          <thead>
            <tr>
              <th>Group Name</th>
              <th>Description</th>
              <th>Subjects</th>
              <th>Curriculum</th>
              <th>Version</th>
              <th>Updated</th>
              <?php if ($canManageElectives): ?><th>Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody id="electiveGroupRows">
            <tr><td colspan="<?= $groupColumnCount ?>" class="state-cell">Select a curriculum version to manage elective groups.</td></tr>
          </tbody>
        </table>
      </div>
      <div class="pagination-bar" id="electiveGroupPagination"></div>
    </section>

    <section class="domain-card mapping-results-card elective-membership-card" aria-labelledby="electiveMembershipTitle">
      <div class="domain-card-header">
        <div>
          <p class="eyebrow">Selected group membership</p>
          <h2 id="electiveMembershipTitle">Group Subjects</h2>
          <p class="mapping-context-message" id="selectedElectiveGroupMessage">Select an elective group to view its subjects.</p>
        </div>
        <div class="card-header-actions">
          <span class="record-count" id="electiveMembershipCount">0 records</span>
          <?php if ($canManageElectives): ?>
          <button type="button" class="primary-action-button" id="addElectiveSubjectButton" disabled>
            <i class="fa-solid fa-plus"></i> Add Subject
          </button>
          <?php endif; ?>
        </div>
      </div>
      <div class="mapping-results-toolbar elective-membership-toolbar">
        <label class="mapping-filter-field mapping-search-field elective-subject-search">
          <span>Subject Search</span>
          <span class="mapping-input-with-icon">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" id="electiveSubjectSearch" maxlength="100"
                   placeholder="Select an elective group first"
                   aria-describedby="selectedElectiveGroupMessage" disabled/>
          </span>
        </label>
        <label class="select-control compact-select">
          <span>Rows</span>
          <select id="electiveMembershipPerPage" disabled><option>10</option><option selected>20</option><option>50</option></select>
        </label>
      </div>
      <div class="table-scroll">
        <table class="domain-table elective-membership-table">
          <thead>
            <tr>
              <th>Subject Code</th>
              <th>Subject Title</th>
              <th>Year Level</th>
              <th>Semester / Term</th>
              <th>Units</th>
              <?php if ($canManageElectives): ?><th>Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody id="electiveMembershipRows">
            <tr><td colspan="<?= $membershipColumnCount ?>" class="state-cell">Select an elective group to view its subjects.</td></tr>
          </tbody>
        </table>
      </div>
      <div class="pagination-bar" id="electiveMembershipPagination"></div>
    </section>
  </main>

  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<?php if ($canManageElectives): ?>
<div class="curriculum-modal" id="createElectiveGroupModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="createElectiveGroupTitle">
    <div class="modal-header">
      <div><h2 id="createElectiveGroupTitle">Create Elective Group</h2><p class="modal-context" id="createElectiveGroupContext"></p></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close create elective group form"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form class="domain-form" id="createElectiveGroupForm">
      <input type="hidden" name="curriculum_version_id" id="createElectiveGroupVersionId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field form-field-wide"><span>Group Name <strong>*</strong></span><input name="group_name" maxlength="150" required placeholder="Enter a flexible elective group name"/></label>
        <label class="form-field form-field-wide"><span>Description</span><textarea name="description" rows="4" placeholder="Optional group description"></textarea></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Create Elective Group</button></div>
    </form>
  </section>
</div>

<div class="curriculum-modal" id="editElectiveGroupModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="editElectiveGroupTitle">
    <div class="modal-header">
      <div><h2 id="editElectiveGroupTitle">Edit Elective Group</h2><p class="modal-context" id="editElectiveGroupContext"></p></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close edit elective group form"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form class="domain-form" id="editElectiveGroupForm">
      <input type="hidden" name="id" id="editElectiveGroupId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field form-field-wide"><span>Group Name <strong>*</strong></span><input name="group_name" id="editElectiveGroupName" maxlength="150" required/></label>
        <label class="form-field form-field-wide"><span>Description</span><textarea name="description" id="editElectiveGroupDescription" rows="4"></textarea></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Save Changes</button></div>
    </form>
  </section>
</div>

<div class="curriculum-modal" id="removeElectiveGroupModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-small" role="alertdialog" aria-modal="true" aria-labelledby="removeElectiveGroupTitle">
    <div class="modal-header">
      <div><h2 id="removeElectiveGroupTitle">Remove Empty Elective Group?</h2></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close elective group removal confirmation"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="confirmation-body"><p id="removeElectiveGroupMessage"></p><div class="form-error" role="alert" hidden></div></div>
    <div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="button" class="button-danger" id="confirmRemoveElectiveGroup">Remove Group</button></div>
  </section>
</div>

<div class="curriculum-modal" id="addElectiveSubjectModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="addElectiveSubjectTitle">
    <div class="modal-header">
      <div><h2 id="addElectiveSubjectTitle">Add Subject to Elective Group</h2><p class="modal-context" id="addElectiveSubjectContext"></p></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close add elective subject form"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form class="domain-form" id="addElectiveSubjectForm">
      <input type="hidden" name="elective_group_id" id="addElectiveSubjectGroupId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field form-field-wide"><span>Find Mapped Subject</span><input type="search" id="electiveSubjectSelectorSearch" maxlength="100" placeholder="Search by subject code or title"/></label>
        <label class="form-field form-field-wide"><span>Subject <strong>*</strong></span><select name="subject_id" id="addElectiveSubjectId" required><option value="">Select a curriculum-mapped subject</option></select><small>Already included subjects are omitted from this list.</small></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Add Subject</button></div>
    </form>
  </section>
</div>

<div class="curriculum-modal" id="removeElectiveSubjectModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-small" role="alertdialog" aria-modal="true" aria-labelledby="removeElectiveSubjectTitle">
    <div class="modal-header">
      <div><h2 id="removeElectiveSubjectTitle">Remove Subject from Group?</h2></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close membership removal confirmation"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="confirmation-body"><p id="removeElectiveSubjectMessage"></p><div class="form-error" role="alert" hidden></div></div>
    <div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="button" class="button-danger" id="confirmRemoveElectiveSubject">Remove Subject</button></div>
  </section>
</div>
<?php endif; ?>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $curriculumCommonVersion ?>"></script>
<script src="../assets/electives-manager.js?v=<?= $electiveScriptVersion ?>"></script>
</body>
</html>
