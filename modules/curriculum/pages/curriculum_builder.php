<?php
require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}

// Shared renderer for the two Curriculum Builder child pages. Direct requests
// to the historical combined route redirect instead of exposing a duplicate UI.
$curriculumWorkspacePage = $curriculumWorkspacePage ?? null;
if ($curriculumWorkspacePage === null) {
    $legacyUserId = current_user_id();
    $hasCurriculumContext = isset($_GET['curriculum_id'])
        || isset($_GET['id'])
        || (($_GET['view'] ?? '') === 'curricula');

    if ($hasCurriculumContext && $legacyUserId !== null
        && user_can($legacyUserId, 'curriculum.view')) {
        $legacyTarget = 'curricula.php';
    } elseif ($legacyUserId !== null && user_can($legacyUserId, 'program.view')) {
        $legacyTarget = 'academic_programs.php';
    } elseif ($legacyUserId !== null && user_can($legacyUserId, 'curriculum.view')) {
        $legacyTarget = 'curricula.php';
    } else {
        require_permission('curriculum.view');
        exit;
    }

    $legacyQuery = http_build_query($_GET);
    header('Location: ' . $legacyTarget . ($legacyQuery === '' ? '' : '?' . $legacyQuery));
    exit;
}

$isProgramPage = $curriculumWorkspacePage === 'programs';
$isCurriculaPage = $curriculumWorkspacePage === 'curricula';
if (!$isProgramPage && !$isCurriculaPage) {
    http_response_code(404);
    exit('Page not found.');
}

require_permission($isProgramPage ? 'program.view' : 'curriculum.view');

$programCategory = $isProgramPage ? (string)($_GET['category'] ?? '') : '';
if ($isProgramPage && !in_array($programCategory, ['', 'college', 'shs'], true)) {
    http_response_code(400);
    exit('Invalid academic program category.');
}
$isProgramLanding = $isProgramPage && $programCategory === '';

$userId = current_user_id();
$canViewPrograms = $userId !== null && user_can($userId, 'program.view');
$canViewCurricula = $userId !== null && user_can($userId, 'curriculum.view');
$canCreatePrograms = $isProgramPage && $userId !== null && user_can($userId, 'program.create');
$canUpdatePrograms = $userId !== null && user_can($userId, 'program.update');
$canCreateCurricula = $isCurriculaPage && $userId !== null && user_can($userId, 'curriculum.create');
$canUpdateCurricula = $isCurriculaPage && $userId !== null && user_can($userId, 'curriculum.update');
$canCreateVersions = $isCurriculaPage && $userId !== null && user_can($userId, 'curriculum.version.create');
$canViewSubjects = $userId !== null && user_can($userId, 'subject.view');
$canAssignSubjects = $canUpdateCurricula && $canViewSubjects;
$canExportCurricula = $userId !== null && user_can($userId, 'curriculum_export.view');
$canAttachVersionReferences = $userId !== null && user_can($userId, 'curriculum.reference.attach');
$canClassifyReference = $userId !== null && user_can($userId, 'curriculum.reference.classify');
$canCloneReference = $userId !== null && user_can($userId, 'curriculum.version.clone');
$programColumnCount = ($programCategory === 'shs' ? 4 : 5) + ($canUpdatePrograms ? 1 : 0);
$curriculumColumnCount = $canUpdateCurricula ? 7 : 6;
$initialCurriculumId = $isCurriculaPage
    ? filter_var($_GET['curriculum_id'] ?? $_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$initialProgramId = $isCurriculaPage
    ? filter_var($_GET['program_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$initialVersionId = $isCurriculaPage
    ? filter_var($_GET['version_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$isCurriculaProgramView = $isCurriculaPage && $initialProgramId !== false && $initialCurriculumId === false;
$isCurriculumWorkspaceView = $isCurriculaPage && $initialCurriculumId !== false;
$curriculaView = $isCurriculaProgramView ? 'program-curricula' : ($isCurriculumWorkspaceView ? 'curriculum-workspace' : 'program-list');
$pageTitle = $isProgramPage
    ? ($programCategory === 'shs' ? 'Senior High School Strands' : ($programCategory === 'college' ? 'College Programs' : 'Academic Programs'))
    : 'Curricula';
$pageIcon = $isProgramPage
    ? ($programCategory === 'shs' ? 'fa-book-open' : 'fa-graduation-cap')
    : 'fa-table-list';
$pageSubtitle = $isProgramPage
    ? ($isProgramLanding ? 'Browse academic offerings by level.' : ($programCategory === 'shs' ? 'Grade 11–12 strands.' : 'Higher-education academic programs.'))
    : 'Browse curricula by academic program or strand.';
$avatarInitial = strtoupper(substr((string)($_SESSION['first_name'] ?? 'U'), 0, 1));
$curriculumCssVersion = substr(hash_file('sha256', __DIR__ . '/../assets/curriculum.css'), 0, 16);
$typographyCssVersion = (int)filemtime(__DIR__ . '/../../../css/csms-typography.css');
$curriculumCommonVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-common.js');
$curriculumBuilderVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-builder.js');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../../../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> - BCP Student Management System</title>
  <link rel="stylesheet" href="../../../css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/curriculum.css?v=<?= $curriculumCssVersion ?>"/>
  <link rel="stylesheet" href="../../../css/csms-typography.css?v=<?= $typographyCssVersion ?>"/>
  <?php if ($isProgramPage): ?>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@800&display=swap" rel="stylesheet"/>
  <?php endif; ?>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../../../';
$ACTIVE_NAV = $isProgramPage ? 'academic-programs' : 'curricula';
require __DIR__ . '/../../../shared/sidebar.php';
?>

<div class="main">
  <?php require __DIR__ . '/../../../shared/topbar.php'; ?>

  <main class="content curriculum-content" id="curriculumWorkspace"
        data-workspace-page="<?= $isProgramPage ? 'programs' : 'curricula' ?>"
        data-curricula-view="<?= htmlspecialchars($curriculaView, ENT_QUOTES, 'UTF-8') ?>"
        data-program-category="<?= htmlspecialchars($programCategory, ENT_QUOTES, 'UTF-8') ?>"
        data-initial-curriculum-id="<?= $initialCurriculumId === false ? '' : (int)$initialCurriculumId ?>"
        data-initial-program-id="<?= $initialProgramId === false ? '' : (int)$initialProgramId ?>"
        data-initial-version-id="<?= $initialVersionId === false ? '' : (int)$initialVersionId ?>"
        data-program-api="../http/program_actions.php"
        data-subject-api="../http/subject_actions.php"
        data-curriculum-api="../http/curriculum_actions.php"
        data-version-api="../http/version_actions.php"
        data-assignment-api="../http/assignment_actions.php"
        data-version-reference-api="../http/version_reference_actions.php"
        data-compliance-api="../http/compliance_actions.php"
        data-can-attach-references="<?= $canAttachVersionReferences ? '1' : '0' ?>"
        data-can-classify-reference="<?= $canClassifyReference ? '1' : '0' ?>"
        data-can-clone-reference="<?= $canCloneReference ? '1' : '0' ?>"
        data-can-view-programs="<?= $canViewPrograms ? '1' : '0' ?>"
        data-can-view-curricula="<?= $canViewCurricula ? '1' : '0' ?>"
        data-can-create-programs="<?= $canCreatePrograms ? '1' : '0' ?>"
        data-can-update-programs="<?= $canUpdatePrograms ? '1' : '0' ?>"
        data-can-create-curricula="<?= $canCreateCurricula ? '1' : '0' ?>"
        data-can-update-curricula="<?= $canUpdateCurricula ? '1' : '0' ?>"
        data-can-create-versions="<?= $canCreateVersions ? '1' : '0' ?>"
        data-can-assign-subjects="<?= $canAssignSubjects ? '1' : '0' ?>"
        data-can-remove-assignments="<?= $canUpdateCurricula ? '1' : '0' ?>">
    <?php if ($isProgramLanding): ?>
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <h1 class="page-title">Academic Programs</h1>
        <p class="page-subtitle">Browse academic offerings by level.</p>
      </div>
    </div>
    <?php elseif ($isCurriculaProgramView): ?>
    <div class="page-title-bar curriculum-title-bar">
      <div><a class="program-back-link" href="curricula.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to Curricula</a><h1 class="page-title"><span id="selectedProgramCode">Loading...</span></h1><p class="page-subtitle" id="selectedProgramName"></p><p class="page-subtitle" id="selectedProgramCurriculumCount">Loading curricula...</p></div>
      <?php if ($canCreateCurricula): ?><button type="button" class="primary-action-button manuscript-primary" id="newCurriculumButton" disabled><i class="fa-solid fa-plus"></i> New Curriculum</button><?php endif; ?>
    </div>
    <?php elseif ($isCurriculumWorkspaceView): ?>
    <div class="page-title-bar curriculum-title-bar curriculum-workspace-title-bar">
      <div><a class="program-back-link" id="backToProgramCurricula" href="curricula.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to <span id="backProgramCurriculaLabel">Program Curricula</span></a><h1 class="page-title" id="workspaceCurriculumName">Loading curriculum...</h1><p class="page-subtitle"><span id="curriculumHeaderCode"></span> <span id="workspaceCurriculumStatus"></span></p></div>
      <div class="curriculum-header-actions"><?php if ($canUpdateCurricula): ?><button type="button" class="secondary-action-button" id="editCurriculumButton"><i class="fa-solid fa-pen"></i> Edit Curriculum</button><button type="button" class="button-danger" id="archiveCurriculumButton" hidden><i class="fa-solid fa-box-archive"></i> Archive</button><?php endif; ?><?php if ($canCreateVersions): ?><button type="button" class="primary-action-button manuscript-primary" id="newVersionButton" disabled><i class="fa-solid fa-plus"></i> New Version</button><?php endif; ?></div>
    </div>
    <?php else: ?>
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <?php if ($isProgramPage && !$isProgramLanding): ?><a class="program-back-link" href="academic_programs.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to Academic Programs</a><?php endif; ?>
        <h1 class="page-title"><i class="fa-solid <?= $pageIcon ?>"></i> <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-subtitle"><?= htmlspecialchars($pageSubtitle, ENT_QUOTES, 'UTF-8') ?></p>
      </div>
      <?php if ($isProgramPage && !$isProgramLanding && $canCreatePrograms): ?><button type="button" class="primary-action-button manuscript-primary" id="newProgramButton"><i class="fa-solid fa-plus"></i> New Program</button><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($isProgramLanding): ?>
    <section class="program-category-grid" aria-label="Academic program categories">
      <a class="program-category-card" href="academic_programs.php?category=college">
        <span class="program-category-icon" aria-hidden="true"><i class="fa-solid fa-graduation-cap"></i></span>
        <span class="program-category-kicker">Higher Education</span>
        <span class="program-category-copy"><strong>College Programs</strong><span>Bachelor's and other higher-education programs</span></span>
        <span class="program-category-footer"><small id="collegeProgramCount" hidden></small><span class="program-category-arrow" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span></span>
      </a>
      <a class="program-category-card program-category-card-shs" href="academic_programs.php?category=shs">
        <span class="program-category-icon" aria-hidden="true"><i class="fa-solid fa-book-open"></i></span>
        <span class="program-category-kicker">Grades 11–12</span>
        <span class="program-category-copy"><strong>Senior High School Strands</strong><span>Academic and applied pathways for senior high learners</span></span>
        <span class="program-category-footer"><small id="shsProgramCount" hidden></small><span class="program-category-arrow" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span></span>
      </a>
    </section>
    <?php else: ?>
    <section class="workspace-grid workspace-grid-single" aria-label="<?= $isProgramPage ? 'Academic program records' : 'Curriculum records' ?>">
      <?php if ($isProgramPage): ?>
      <article class="domain-card" id="programPanel">
        <span class="sr-only" id="programCount" aria-live="polite"></span>
        <form class="list-toolbar" id="programSearchForm" role="search">
          <label class="search-control">
            <span class="sr-only">Search academic programs</span>
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" id="programSearch" placeholder="Search programs..." maxlength="100"/>
          </label>
          <?php if ($programCategory === 'college'): ?><label class="select-control"><span class="sr-only">Degree level</span><select id="programDegreeLevelFilter"><option value="">All degree levels</option><option value="Bachelor&#39;s Degree">Bachelor's Degree</option><option value="Associate Degree">Associate Degree</option><option value="Diploma / Certificate">Diploma / Certificate</option><option value="Other">Other</option></select></label><?php endif; ?>
          <label class="select-control">
            <span class="sr-only">Program status</span>
            <select id="programStatus">
              <option value="">All statuses</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </label>
        </form>
        <div class="table-scroll">
          <table class="domain-table">
            <thead><tr><th>Code</th><th><?= $programCategory === 'shs' ? 'Strand' : 'Program' ?></th><?php if ($programCategory !== 'shs'): ?><th>Degree Level</th><?php endif; ?><th>Curricula</th><th>Status</th><?php if ($canUpdatePrograms): ?><th>Actions</th><?php endif; ?></tr></thead>
            <tbody id="programRows"><tr><td colspan="<?= $programColumnCount ?>" class="state-cell">Loading academic programs…</td></tr></tbody>
          </table>
        </div>
        <div class="pagination-bar" id="programPagination"></div>
      </article>
      <?php else: ?>

      <?php if ($isCurriculaProgramView): ?>
      <section class="curriculum-program-card-panel" id="programCurriculaPanel" aria-label="Curricula for this program">
        <div class="curriculum-card-grid" id="programCurriculaCards"><p class="state-cell">Loading curricula...</p></div>
      </section>
      <?php elseif ($isCurriculumWorkspaceView): ?>
      <article class="domain-card curriculum-version-panel" id="curriculumWorkspacePanel">
        <div class="section-heading curriculum-versions-heading"><div><p class="eyebrow">Versions</p><h2>Curriculum Versions</h2></div><span class="record-count" id="versionCount">0 records</span></div>
        <div id="versionRows" class="version-card-list"><p class="state-cell">Loading curriculum versions...</p></div>
        <div class="pagination-bar" id="versionPagination"></div>
      </article>
      <?php else: ?>
      <article class="domain-card curriculum-list-card" id="curriculumProgramsPanel">
        <span class="sr-only" id="curriculumProgramCount" aria-live="polite"></span>
        <form class="list-toolbar" id="curriculumProgramSearchForm" role="search">
          <label class="search-control"><span class="sr-only">Search programs or strands</span><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="curriculumProgramSearch" placeholder="Search program or strand..." maxlength="100"/></label>
          <label class="select-control"><span class="sr-only">Academic level</span><select id="curriculumAcademicLevel"><option value="">All levels</option><option value="college">College</option><option value="shs">Senior High School</option></select></label>
          <label class="select-control"><span class="sr-only">Program status</span><select id="curriculumProgramStatus"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
        </form>
        <div class="table-scroll"><table class="domain-table curriculum-program-table">
          <thead><tr><th>Program Code</th><th>Program Name</th><th>No. of Curricula</th><th>Status</th><?php if ($canUpdatePrograms): ?><th>Actions</th><?php endif; ?></tr></thead>
          <tbody id="curriculumProgramRows"><tr><td colspan="<?= $canUpdatePrograms ? 5 : 4 ?>" class="state-cell">Loading programs...</td></tr></tbody>
        </table></div>
        <div class="pagination-bar" id="curriculumProgramPagination"></div>
      </article>
      <?php endif; ?>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($isCurriculaPage): ?>
    <section class="version-workspace" id="versionWorkspace" hidden aria-labelledby="workspaceVersionTitle">
      <a class="text-back-button" id="backToCurricula" href="curricula.php?program_id=<?= $initialProgramId === false ? '' : (int)$initialProgramId ?>&amp;curriculum_id=<?= $initialCurriculumId === false ? '' : (int)$initialCurriculumId ?>"><i class="fa-solid fa-arrow-left"></i> Back to <span id="versionBackCurriculumLabel">Curriculum</span></a>
      <div class="version-workspace-header"><div class="version-heading"><div class="version-title-line"><h2 id="workspaceVersionTitle"></h2><span id="workspaceVersionStatus"></span></div><nav class="subject-breadcrumb" aria-label="Curriculum context"><a id="workspaceProgramCode" href="curricula.php"></a><span class="breadcrumb-separator" aria-hidden="true">›</span><a id="workspaceCurriculumCode" href="curricula.php"></a><span class="breadcrumb-separator" aria-hidden="true">›</span><strong id="workspaceBreadcrumbVersion"></strong></nav></div><div class="version-workspace-actions"><?php if ($canExportCurricula): ?><a class="secondary-action-button" id="versionExportLink" href="curriculum_export.php"><i class="fa-solid fa-download"></i> Export</a><?php endif; ?><a class="button-muted" id="outcomeMappingLink" href="subject_mapping.php">Graduate Outcomes</a><button type="button" class="primary-action-button" id="assignSubjectButton" disabled><i class="fa-solid fa-diagram-project"></i> <?= $canAssignSubjects ? 'Manage Subject Mapping' : 'View Subject Mapping' ?></button></div></div>
      <section class="domain-card reference-baseline-panel" id="versionReferencesPanel" aria-labelledby="versionReferencesTitle">
        <div class="domain-card-header"><div><p class="eyebrow">Documentary provenance</p><h3 id="versionReferencesTitle">References &amp; Baseline</h3><p class="mapping-context-message" id="referenceVersionContext">Loading source and lifecycle details...</p></div><div class="card-header-actions"><span id="referenceProtectionBadge"></span><?php if ($userId !== null && user_can($userId, 'subject_history.view')): ?><a class="button-muted" id="compareBaselineLink" href="curriculum_history_view.php" hidden>Compare revision with reference</a><?php endif; ?></div></div>
        <dl class="record-detail-grid reference-baseline-details"><div><dt>Program</dt><dd id="referenceProgram"></dd></div><div><dt>Curriculum / version</dt><dd id="referenceCurriculumVersion"></dd></div><div><dt>Version role</dt><dd id="referenceVersionRole"></dd></div><div><dt>Review workflow</dt><dd id="referenceReviewStatus"></dd></div><div><dt>Reference basis</dt><dd id="referenceBasis"></dd></div><div><dt>Baseline</dt><dd id="referenceBaseline"></dd></div><div><dt>Snapshot capture</dt><dd id="referenceSnapshotStatus"></dd></div><div><dt>Institutional adoption</dt><dd>Institutional adoption/approval not verified</dd></div></dl>
        <div class="table-scroll"><table class="domain-table"><thead><tr><th>Attached source</th><th>Purpose</th><th>Origin and internal assessment</th><th>Attached by / date</th></tr></thead><tbody id="versionReferenceRows"><tr><td colspan="4" class="empty-cell">No documentary references are attached.</td></tr></tbody></table></div>
        <?php if ($canAttachVersionReferences): ?><details class="reference-action-details"><summary>Attach documentary source</summary><form class="domain-form" id="attachVersionReferenceForm"><input type="hidden" name="curriculum_version_id"/><div class="form-error" role="alert" hidden></div><div class="form-grid"><label class="form-field form-field-wide"><span>Source record <strong>*</strong></span><select name="compliance_reference_id" required><option value="">Loading source records...</option></select></label><label class="form-field"><span>Purpose <strong>*</strong></span><select name="reference_purpose" required><option value="school_curriculum">Curriculum source</option><option value="regulatory">Regulatory context</option><option value="institutional_policy">Institutional policy</option><option value="other">Other</option></select></label></div><div class="modal-actions"><button class="button-primary" type="submit">Attach Source</button></div></form></details><?php endif; ?>
        <?php if ($canClassifyReference): ?><details class="reference-action-details" id="classifyReferenceDetails"><summary>Classify as protected reference</summary><form class="domain-form" id="classifyReferenceForm"><input type="hidden" name="curriculum_version_id"/><div class="form-error" role="alert" hidden></div><div class="form-grid"><label class="form-field form-field-wide"><span>Primary curriculum source <strong>*</strong></span><select name="compliance_reference_id" required><option value="">Attach a curriculum source first</option></select></label><label class="form-field"><span>Classification basis <strong>*</strong></span><select name="classification_basis" required><option value="ched_sample">CHED sample / demonstration</option><option value="institutional_source">Institution-provided curriculum source</option><option value="other_document">Other documented source</option></select></label><label class="form-field form-field-wide"><span><input type="checkbox" name="confirm_reference_protection" value="1" required/> I confirm that this action makes the version an immutable reference and does not indicate BCP adoption or approval.</span></label></div><p class="curriculum-notice">Identity, origin, and applicability assessments remain separate. Institutional adoption/approval not verified.</p><div class="modal-actions"><button class="button-primary" type="submit">Capture evidence and classify</button></div></form></details><?php endif; ?>
      </section>
      <?php if ($canCloneReference): ?>
      <p class="curriculum-notice" id="cloneReferenceAvailability">No eligible protected reference selected. Open a protected reference to check revision creation eligibility.</p>
      <details class="domain-card reference-action-details" id="cloneReferenceDetails" hidden>
        <summary>Create Curriculum Revision</summary>
        <p id="cloneReferenceScope" class="curriculum-notice">Select a protected reference. Eligibility and copy scope will be checked before creation.</p>
        <form id="cloneReferenceForm" class="domain-form">
          <input type="hidden" name="source_version_id"/><input type="hidden" name="curriculum_id"/>
          <div class="form-error" role="alert" hidden></div>
          <div class="form-grid">
            <label class="form-field"><span>Revision label <strong>*</strong></span><input name="version_label" maxlength="100" required/></label>
            <label class="form-field"><span>Effective academic year (optional)</span><input name="effective_academic_year" maxlength="50"/></label>
            <label class="form-field form-field-wide"><span>Description (optional)</span><textarea name="description" maxlength="60000" rows="3"></textarea></label>
            <label class="form-field form-field-wide"><span><input type="checkbox" name="confirm_reference_unchanged" value="1" required/> Create an independent editable revision; preserve the reference and its historical evidence unchanged.</span></label>
          </div>
          <p class="curriculum-notice">New role: proposed. Review workflow: not_started. Technical state: draft. Source authenticity and applicability are separate assessments. Institutional adoption/approval not verified.</p>
          <div class="modal-actions"><button class="button-primary" type="submit" disabled>Create Curriculum Revision</button></div>
        </form>
      </details>
      <?php endif; ?>
      <div id="yearTabs" class="year-tabs" role="tablist" aria-label="Academic year"></div>
      <div id="placementRows" class="placement-groups"><p class="state-cell">Select a curriculum version to view assigned subjects.</p></div>
      <div class="pagination-bar" id="placementPagination"></div>
    </section>
    <?php endif; ?>
  </main>

  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<?php if (($isProgramPage && !$isProgramLanding) || ($isCurriculaPage && $canUpdatePrograms)): ?>
<div class="curriculum-modal record-details-modal" id="programDetailsModal" hidden aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-large modal-dialog-scroll" role="dialog" aria-modal="true" aria-labelledby="programDetailsName">
    <div class="modal-header">
      <div><p class="modal-kicker">Academic Program</p><h2 id="programDetailsName">Program Details</h2><p class="modal-context" id="programDetailsCode"></p></div>
      <span id="programDetailsStatusBadge"></span>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close academic program details"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <dl class="record-detail-grid program-detail-grid">
        <div class="program-degree-level"><div><dt>Degree Level</dt><dd id="programDetailsType"></dd></div><?php if ($canViewCurricula): ?><a class="secondary-action-button program-see-curricula" id="programSeeCurricula" href="curricula.php"><i class="fa-solid fa-list"></i> See Curriculas</a><?php endif; ?></div>
      </dl>
      <?php if ($canViewCurricula): ?>
      <p id="programSummaryNotice" class="curriculum-notice" role="status" hidden></p>
      <section class="nested-section program-related-section" aria-label="Related curricula">
        <div class="section-heading"><p class="eyebrow">Curricula for this program</p></div>
        <div class="program-summary-grid">
          <div><span>Curricula</span><strong id="programCurriculaTotal">—</strong></div>
          <div><span>Versions</span><strong id="programVersionsTotal">—</strong></div>
          <div><span>Subject Placements</span><strong id="programPlacementsTotal">—</strong></div>
        </div>
      </section>
      <?php else: ?>
      <p class="subject-placement-detail">Curriculum summary is not available with your current permissions.</p>
      <?php endif; ?>
    </div>
    <?php if ($canUpdatePrograms): ?><div class="modal-footer program-details-footer"><button type="button" class="button-muted" data-modal-close>Close</button><button type="button" class="button-primary" id="programDetailsEdit"><i class="fa-solid fa-pen"></i> Edit</button><button type="button" class="button-danger" id="programDetailsArchive"><i class="fa-solid fa-box-archive"></i> Archive</button></div><?php endif; ?>
  </section>
</div>
<?php if ($canUpdatePrograms): ?>
<div class="curriculum-modal" id="programArchiveModal" hidden aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog program-archive-dialog" role="dialog" aria-modal="true" aria-labelledby="programArchiveTitle">
    <div class="modal-header"><div><h2 id="programArchiveTitle">Archive Record</h2></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close archive confirmation"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="confirmation-body"><p id="programArchiveMessage">Are you sure you want to archive this academic program?</p><p class="program-archive-note">The record will be removed from active views but retained in Archives. Existing related curriculum records will be preserved.</p></div>
    <div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="button" class="button-danger" id="confirmProgramArchive">Archive</button></div>
  </section>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if (($isProgramPage && !$isProgramLanding && ($canCreatePrograms || $canUpdatePrograms)) || ($isCurriculaPage && $canUpdatePrograms)): ?>
<div class="curriculum-modal" id="programModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="programModalTitle">
    <div class="modal-header">
      <div><h2 id="programModalTitle">New Program</h2><p class="modal-context">Create an academic program.</p></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close academic program form"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="programForm" class="domain-form">
      <input type="hidden" name="id" id="programId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field"><span>Program Code <strong>*</strong></span><input name="program_code" id="programCode" maxlength="60" placeholder="e.g. BSIT" required/></label>
        <label class="form-field"><span>Degree Level <strong>*</strong></span>
          <select id="programTypeLevel" required>
            <option value="">Select degree level</option>
            <option value="Bachelor's Degree">Bachelor's Degree</option>
            <option value="Associate Degree">Associate Degree</option>
            <option value="Diploma / Certificate">Diploma / Certificate</option>
            <option value="Senior High School">Senior High School</option>
            <option value="Other">Other</option>
          </select>
          <input type="hidden" name="program_type" id="programType"/>
          <input type="text" id="programTypeOther" maxlength="100" placeholder="Specify degree level" aria-label="Specify degree level" hidden/>
        </label>
        <label class="form-field form-field-wide"><span>Program Name <strong>*</strong></span><input name="program_name" id="programName" maxlength="255" placeholder="Full program name" required/></label>
        <label class="form-field"><span>Status <strong>*</strong></span><select name="status" id="programFormStatus" required><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary" id="programSubmitButton">Save Program</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<?php if ($isCurriculaPage && ($canCreateCurricula || $canUpdateCurricula)): ?>
<?php if ($canUpdateCurricula): ?>
<div class="curriculum-modal" id="curriculumArchiveModal" hidden><div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-small" role="alertdialog" aria-modal="true" aria-labelledby="curriculumArchiveTitle">
    <div class="modal-header"><h2 id="curriculumArchiveTitle">Archive Record</h2><button type="button" class="modal-close-button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="confirmation-body"><p>Are you sure you want to archive this curriculum?</p><p>The record will be removed from active views but retained in Archives.</p><strong id="curriculumArchiveName"></strong><div class="form-error" role="alert" hidden></div></div>
    <div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="button" class="button-danger" id="confirmCurriculumArchive">Archive</button></div>
  </section>
</div>
<?php endif; ?>
<div class="curriculum-modal" id="curriculumModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="curriculumModalTitle">
    <div class="modal-header">
      <div><h2 id="curriculumModalTitle">New Curriculum</h2></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close curriculum form"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="curriculumForm" class="domain-form">
      <input type="hidden" name="id" id="curriculumId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="curriculum-fixed-program" id="curriculumProgramFixedContext" hidden><span>Current Program</span><strong id="curriculumProgramFixedLabel"></strong></div>
      <div class="form-grid">
        <label class="form-field"><span>Curriculum Code <strong>*</strong></span><input name="curriculum_code" id="curriculumCode" maxlength="80" placeholder="e.g. BSIT-DEMO-2026" required/></label>
        <div class="form-field program-combobox-field" id="curriculumProgramPicker">
          <label for="curriculumProgramInput">Academic Program <strong>*</strong></label>
          <div class="record-autocomplete" id="curriculumProgramCombobox">
            <input type="search" id="curriculumProgramInput" maxlength="100"
                   placeholder="Search by program code or name" autocomplete="off"
                    required/>
            <input type="hidden" name="academic_program_id" id="curriculumProgramId"/>
            <button type="button" class="record-autocomplete-clear" data-autocomplete-clear
                    aria-label="Clear academic program" title="Clear selection" hidden><i class="fa-solid fa-xmark"></i></button>
            <div class="record-autocomplete-options" id="curriculumProgramOptions"
                 role="listbox" aria-label="Academic program suggestions" hidden></div>
          </div>
        </div>
        <label class="form-field form-field-wide"><span>Curriculum Name <strong>*</strong></span><input name="curriculum_name" id="curriculumName" maxlength="255" placeholder="Curriculum name" required/></label>
        <label class="form-field"><span>Status <strong>*</strong></span><select name="status" id="curriculumFormStatus" required><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary" id="curriculumSubmitButton">Save Curriculum</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<?php if ($isCurriculaPage && $canCreateVersions): ?>
<div class="curriculum-modal" id="versionModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="versionModalTitle">
    <div class="modal-header">
      <div><h2 id="versionModalTitle">New Curriculum Version</h2><p class="modal-context" id="versionCurriculumContext"></p></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close curriculum version form"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="versionForm" class="domain-form">
      <input type="hidden" name="curriculum_id" id="versionCurriculumId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field"><span>Version Label <strong>*</strong></span><input name="version_label" id="versionLabel" maxlength="100" required/></label>
        <label class="form-field"><span>Effective Academic Year</span><input name="effective_academic_year" id="effectiveAcademicYear" maxlength="50" placeholder="Flexible label"/></label>
        <label class="form-field form-field-wide"><span>Description</span><textarea name="description" id="versionDescription" rows="3"></textarea></label>
        <label class="form-field"><span>Technical System State <strong>*</strong></span><select name="status" id="versionStatus" required><option value="draft">Draft</option><option value="active">Active</option><option value="archived">Archived</option></select><small>These are system record states, not institutional approval statuses.</small></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Create Version</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<?php if ($isCurriculaPage && $canUpdateCurricula): ?>
<div class="curriculum-modal" id="versionRenameModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-small" role="dialog" aria-modal="true" aria-labelledby="versionRenameTitle">
    <div class="modal-header">
      <div><h2 id="versionRenameTitle">Edit Version Name</h2><p class="modal-context">Rename this curriculum version.</p></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close version rename form"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="versionRenameForm" class="domain-form">
      <input type="hidden" name="id" id="versionRenameId"/>
      <div class="form-error" role="alert" hidden></div>
      <label class="form-field"><span>Version Name <strong>*</strong></span><input name="version_label" id="versionRenameLabel" maxlength="100" required/></label>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Save Name</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<?php if ($isCurriculaPage && $canAssignSubjects): ?>
<div class="curriculum-modal" id="assignmentModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="assignmentModalTitle">
    <div class="modal-header">
      <div><h2 id="assignmentModalTitle">Assign Subject</h2><p class="modal-context" id="assignmentVersionContext"></p></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close subject assignment form"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="assignmentForm" class="domain-form">
      <input type="hidden" name="curriculum_version_id" id="assignmentVersionId"/>
      <div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <div class="form-field form-field-wide">
          <label for="assignmentSubjectInput">Subject <strong>*</strong></label>
          <div class="record-autocomplete">
            <input type="search" id="assignmentSubjectInput" maxlength="100" placeholder="Search all subjects by code or title" required/>
            <input type="hidden" name="subject_id" id="assignmentSubjectId"/>
            <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear subject" title="Clear selection" hidden><i class="fa-solid fa-xmark"></i></button>
            <div class="record-autocomplete-options" id="assignmentSubjectOptions" role="listbox" aria-label="Subject suggestions" hidden></div>
          </div>
          <small id="assignmentSubjectHint" role="status">Type a subject code or title, then select a match.</small>
        </div>
        <label class="form-field"><span>Year Level <strong>*</strong></span><select name="year_level" required><option value="1st Year">1st Year</option><option value="2nd Year">2nd Year</option><option value="3rd Year">3rd Year</option><option value="4th Year">4th Year</option></select></label>
        <label class="form-field"><span>Semester <strong>*</strong></span><select name="semester" required><option value="1st Semester">1st Semester</option><option value="2nd Semester">2nd Semester</option></select></label>
        <label class="form-field"><span>Placement Indicator <strong>*</strong></span><select name="is_required" required><option value="1">Required placement</option><option value="0">Optional placement</option></select><small>This does not create an elective group.</small></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Save Placement</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<?php if ($isCurriculaPage && $canUpdateCurricula): ?>
<div class="curriculum-modal" id="removeAssignmentModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-small" role="alertdialog" aria-modal="true" aria-labelledby="removeAssignmentTitle">
    <div class="modal-header">
      <div><h2 id="removeAssignmentTitle">Remove Subject Assignment?</h2></div>
      <button type="button" class="modal-close-button" data-modal-close aria-label="Close removal confirmation"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="confirmation-body"><p id="removeAssignmentMessage">This removes only the subject placement from this curriculum version. The Subject Master record will remain.</p><div class="form-error" role="alert" hidden></div></div>
    <div class="modal-actions modal-actions-padded"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="button" class="button-danger" id="confirmRemoveAssignment">Remove Placement</button></div>
  </section>
</div>
<?php endif; ?>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $curriculumCommonVersion ?>"></script>
<script src="../assets/placement-groups.js?v=<?= (int)filemtime(__DIR__ . '/../assets/placement-groups.js') ?>"></script>
<script src="../assets/curriculum-builder.js?v=<?= $curriculumBuilderVersion ?>"></script>
</body>
</html>
