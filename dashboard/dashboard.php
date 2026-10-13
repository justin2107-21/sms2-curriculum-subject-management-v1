<?php
require_once __DIR__ . '/../shared/bootstrap.php';
require_permission('dashboard.view');
require_once __DIR__ . '/CurriculumDashboardService.php';

$userId = current_user_id();
$canViewAudit = $userId !== null && user_can($userId, 'audit.view');
$canViewCurriculum = $userId !== null && user_can($userId, 'curriculum.view');

$dashboardService = new CurriculumDashboardService(authorization_connection());
$dashboard = $dashboardService->overview($canViewAudit);
$globalPrograms = ($userId !== null && user_can($userId, 'program.view')) ? $dashboardService->programs() : [];

$summaryDefinitions = [
  ['key' => 'academic_programs', 'label' => 'Academic Programs', 'icon' => 'fa-graduation-cap', 'permission' => 'program.view', 'href' => '../modules/curriculum/pages/academic_programs.php'],
  ['key' => 'subjects', 'label' => 'Subjects', 'icon' => 'fa-book', 'permission' => 'subject.view', 'href' => '../modules/curriculum/pages/subjects.php'],
  ['key' => 'curricula', 'label' => 'Curricula', 'icon' => 'fa-table-list', 'permission' => 'curriculum.view', 'href' => '../modules/curriculum/pages/curricula.php'],
  ['key' => 'curriculum_versions', 'label' => 'Curriculum Versions', 'icon' => 'fa-code-branch', 'permission' => 'curriculum.view', 'href' => '../modules/curriculum/pages/curricula.php'],
  ['key' => 'subject_mappings', 'label' => 'Subject Placements', 'icon' => 'fa-diagram-project', 'permission' => 'curriculum.view', 'href' => '../modules/curriculum/pages/subject_mapping.php'],
  ['key' => 'subject_requirements', 'label' => 'Prerequisite / Corequisite', 'icon' => 'fa-link', 'permission' => 'prerequisite.view', 'href' => '../modules/curriculum/pages/prerequisite_configuration.php'],
  ['key' => 'elective_groups', 'label' => 'Elective Groups', 'icon' => 'fa-layer-group', 'permission' => 'elective.view', 'href' => '../modules/curriculum/pages/electives_manager.php'],
  ['key' => 'subject_equivalencies', 'label' => 'Equivalency Records', 'icon' => 'fa-right-left', 'permission' => 'equivalency.view', 'href' => '../modules/curriculum/pages/subject_equivalency.php'],
];
$visibleSummaries = array_values(array_filter(
    $summaryDefinitions,
    static fn(array $definition): bool => $userId !== null && user_can($userId, $definition['permission'])
));
$dashboardCards = array_values(array_filter(
    $visibleSummaries,
    static fn(array $definition): bool => $definition['key'] !== 'subjects'
));

$quickActionDefinitions = [
    ['label' => 'Create Curriculum', 'icon' => 'fa-plus', 'permission' => 'curriculum.create', 'href' => '../modules/curriculum/pages/curricula.php'],
    ['label' => 'Add Subject', 'icon' => 'fa-book-medical', 'permission' => 'subject.create', 'href' => '../modules/curriculum/pages/subjects.php'],
    ['label' => 'Open Subject Mapping', 'icon' => 'fa-diagram-project', 'permission' => 'curriculum.view', 'href' => '../modules/curriculum/pages/subject_mapping.php'],
    ['label' => 'Configure Pre-requisites', 'icon' => 'fa-code-branch', 'permission' => 'prerequisite.manage', 'href' => '../modules/curriculum/pages/prerequisite_configuration.php'],
    ['label' => 'Manage Electives', 'icon' => 'fa-layer-group', 'permission' => 'elective.manage', 'href' => '../modules/curriculum/pages/electives_manager.php'],
    ['label' => 'Run Compliance Validation', 'icon' => 'fa-shield-halved', 'permission' => 'compliance.view', 'href' => '../modules/curriculum/pages/validate_curriculum.php'],
    ['label' => 'Export Curriculum', 'icon' => 'fa-file-export', 'permission' => 'curriculum_export.view', 'href' => '../modules/curriculum/pages/curriculum_export.php'],
];
$quickActions = array_values(array_filter(
    $quickActionDefinitions,
    static fn(array $action): bool => $userId !== null && user_can($userId, $action['permission'])
));

$dashboardCssVersion = (string)filemtime(__DIR__ . '/../css/curriculum-dashboard.css');
$typographyCssVersion = (string)filemtime(__DIR__ . '/../css/csms-typography.css');
$dashboardScriptVersion = (string)filemtime(__DIR__ . '/../js/dashboard.js');
$curriculumDashboardScriptVersion = (string)filemtime(__DIR__ . '/../js/curriculum-dashboard.js');

function dashboard_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function dashboard_activity_time(string $value): string
{
    $timestamp = strtotime($value);
    return $timestamp === false ? $value : date('M j, Y g:i A', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Dashboard - BCP Curriculum &amp; Subject Management</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="../modules/curriculum/assets/curriculum.css"/>
  <link rel="stylesheet" href="../css/curriculum-dashboard.css?v=<?= $dashboardCssVersion ?>"/>
  <link rel="stylesheet" href="../css/csms-typography.css?v=<?= $typographyCssVersion ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../';
$ACTIVE_NAV = 'dashboard';
require __DIR__ . '/../shared/sidebar.php';
?>

<div class="main">
  <?php require __DIR__ . '/../shared/topbar.php'; ?>

    <main class="content curriculum-dashboard" id="curriculumDashboard"
      data-curriculum-api="../modules/curriculum/http/curriculum_actions.php"
      data-version-api="../modules/curriculum/http/version_actions.php"
      data-assignment-api="../modules/curriculum/http/assignment_actions.php" data-program-api="../modules/curriculum/http/program_actions.php" data-analytics-api="analytics.php"
      data-user-id="<?= (int)$userId ?>" data-can-view-programs="<?= $userId !== null && user_can($userId, 'program.view') ? '1' : '0' ?>" data-can-view-prerequisite="<?= $userId !== null && user_can($userId, 'prerequisite.view') ? '1' : '0' ?>"
      data-can-view-elective="<?= $userId !== null && user_can($userId, 'elective.view') ? '1' : '0' ?>" data-can-view-weighting="<?= $userId !== null && user_can($userId, 'grade_weighting.view') ? '1' : '0' ?>"
      data-can-view-curriculum="<?= $canViewCurriculum ? '1' : '0' ?>">
    <div class="page-title-bar dashboard-title-bar">
      <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="dashboard-subtitle">Monitor curriculum structure, academic configuration, and recent system activity.</p>
      </div>
    </div>

    <section class="dashboard-section" aria-labelledby="globalHeading"><div class="dashboard-section-heading"><div><h2 id="globalHeading">GLOBAL ACADEMIC PROGRAMS / STRANDS</h2><p>Global catalog overview &middot; click a record to begin context selection.</p></div></div><?php if ($dashboardCards !== []): ?><div class="summary-grid global-summary-grid"><?php foreach ($dashboardCards as $definition): ?><a class="summary-card summary-card-link" href="<?= dashboard_escape($definition['href']) ?>"><span class="summary-card-icon"><i class="fa-solid <?= dashboard_escape($definition['icon']) ?>" aria-hidden="true"></i></span><div><span class="summary-card-label"><?= dashboard_escape($definition['label']) ?></span><strong class="summary-card-value"><?= number_format((int)($dashboard['counts'][$definition['key']] ?? 0)) ?></strong></div></a><?php endforeach; ?></div><?php endif; ?><div class="global-grid"><div class="dashboard-card program-card"><div class="dashboard-card-header"><div><h2>Academic Programs / Strands</h2><p>Stored program records</p></div><span class="record-count"><?= count($globalPrograms) ?> records</span></div><div class="program-list"><?php foreach($globalPrograms as $p): ?><button type="button" class="program-row" data-program-id="<?= (int)$p['id'] ?>" data-program-label="<?= dashboard_escape($p['program_code'].' &middot; '.$p['program_name']) ?>"><strong><?= dashboard_escape($p['program_code']) ?></strong><span><b><?= dashboard_escape($p['program_name']) ?></b><small><?= dashboard_escape(ucfirst((string)$p['status'])) ?> &middot; <?= (int)$p['curricula'] ?> Curricula</small></span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button><?php endforeach; ?></div></div></div></section>
    <?php if ($canViewCurriculum): ?>
    <section class="dashboard-section dashboard-card context-card"><div class="dashboard-card-header"><div><h2>SELECTED CURRICULUM CONTEXT</h2><p>Program / Strand &rarr; Curriculum &rarr; Curriculum Version</p></div><span id="contextStatus" class="status-badge" hidden></span></div><div class="context-fields">
    <?php foreach([['Program / Strand','program'],['Curriculum','curriculum'],['Curriculum Version','version']] as [$label,$key]): ?><label class="mapping-filter-field"><span><?= $label ?></span><div class="record-autocomplete"><input type="search" id="dash<?= ucfirst($key) ?>Search" placeholder="<?= $key==='program'?'Search program or strand':'Select '.($key==='curriculum'?'a program':'a curriculum').' first' ?>" <?= $key==='program'?'':'disabled' ?>><input type="hidden" id="dash<?= ucfirst($key) ?>"><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear <?= strtolower($label) ?>" hidden><i class="fa-solid fa-xmark"></i></button><div class="record-autocomplete-options" id="dash<?= ucfirst($key) ?>Options" role="listbox" hidden></div></div></label><?php endforeach; ?></div></section>
    <section class="dashboard-card current-snapshot" id="currentSnapshot" hidden><div class="dashboard-card-header"><div><p class="eyebrow">CURRENT CURRICULUM</p><h2 id="snapshotProgram"></h2><p id="snapshotCurriculum"></p><small id="snapshotVersion"></small></div><a class="dashboard-link" id="openSnapshotCurriculum">&rarr; Open Curriculum</a></div><div class="snapshot-chips" id="snapshotChips"></div></section>
    <p id="dashboardError" class="dashboard-error" role="alert" hidden></p><section id="dashboardEmpty" class="dashboard-empty">Select a Program / Strand, Curriculum, and Curriculum Version to view curriculum analytics.</section><section id="dashboardResults" hidden><div class="metric-grid" id="metricGrid"></div><div class="dashboard-section-heading analytics-heading"><div><h2>CURRICULUM ANALYTICS</h2><p id="analyticsSubtitle"></p></div></div><div class="analytics-grid" id="analyticsGrid"></div></section>
    <?php endif; ?>

    <?php if ($canViewAudit): ?>
    <section class="dashboard-section dashboard-card" aria-labelledby="recentActivityHeading">
      <div class="dashboard-card-header">
        <div>
          <h2 id="recentActivityHeading">Recent Activity</h2>
          <p>Latest successful curriculum and configuration mutations from the append-only audit trail.</p>
        </div>
        <a class="dashboard-link" href="../admin/audit_logs.php">View Audit Logs</a>
      </div>
      <?php if ($dashboard['recent_activity'] !== []): ?>
      <div class="activity-table-wrap">
        <table class="activity-table">
          <thead>
            <tr>
              <th>Action</th>
              <th>Entity / Context</th>
              <th>User</th>
              <th>Date / Time</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($dashboard['recent_activity'] as $activity): ?>
            <tr>
              <td><span class="activity-action"><?= dashboard_escape($activity['action_label']) ?></span></td>
              <td class="activity-context">
                <?= dashboard_escape($activity['description']) ?>
                <small>
                  <?= dashboard_escape($activity['entity_type']) ?>
                  <?= $activity['entity_id'] === null ? '' : '#' . (int)$activity['entity_id'] ?>
                </small>
              </td>
              <td><?= dashboard_escape($activity['user_label']) ?></td>
              <td><?= dashboard_escape(dashboard_activity_time($activity['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="dashboard-empty">
        <i class="fa-regular fa-clock" aria-hidden="true"></i>
        No recent curriculum activity found.
      </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </main>

  <footer class="footer">eLearning Commons &copy; 2026</footer>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../modules/curriculum/assets/curriculum-common.js?v=<?= substr(hash_file('sha256', __DIR__.'/../modules/curriculum/assets/curriculum-common.js'),0,16) ?>"></script>
<?php if ($canViewCurriculum): ?><script src="../js/curriculum-dashboard.js?v=<?= $curriculumDashboardScriptVersion ?>"></script><?php endif; ?>
</body>
</html>
