<?php
require_once __DIR__ . '/../shared/bootstrap.php';
require_permission('dashboard.view');
require_once __DIR__ . '/CurriculumDashboardService.php';

$userId = current_user_id();
$canViewAudit = $userId !== null && user_can($userId, 'audit.view');
$canViewCurriculum = $userId !== null && user_can($userId, 'curriculum.view');

$dashboardService = new CurriculumDashboardService(authorization_connection());
$dashboard = $dashboardService->overview($canViewAudit);

$summaryDefinitions = [
    ['key' => 'academic_programs', 'label' => 'Academic Programs', 'icon' => 'fa-graduation-cap', 'permission' => 'program.view'],
    ['key' => 'subjects', 'label' => 'Subject Master', 'icon' => 'fa-book', 'permission' => 'subject.view'],
    ['key' => 'curricula', 'label' => 'Curricula', 'icon' => 'fa-table-list', 'permission' => 'curriculum.view'],
    ['key' => 'curriculum_versions', 'label' => 'Curriculum Versions', 'icon' => 'fa-code-branch', 'permission' => 'curriculum.view'],
    ['key' => 'subject_mappings', 'label' => 'Subject Mappings', 'icon' => 'fa-diagram-project', 'permission' => 'curriculum.view'],
    ['key' => 'subject_requirements', 'label' => 'Prerequisite / Corequisite', 'icon' => 'fa-link', 'permission' => 'prerequisite.view'],
    ['key' => 'elective_groups', 'label' => 'Elective Groups', 'icon' => 'fa-layer-group', 'permission' => 'elective.view'],
    ['key' => 'subject_equivalencies', 'label' => 'Equivalency Records', 'icon' => 'fa-right-left', 'permission' => 'equivalency.view'],
];
$visibleSummaries = array_values(array_filter(
    $summaryDefinitions,
    static fn(array $definition): bool => $userId !== null && user_can($userId, $definition['permission'])
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

  <main class="content curriculum-dashboard">
    <div class="page-title-bar dashboard-title-bar">
      <div>
        <h1 class="page-title"><i class="fa-solid fa-gauge"></i> Dashboard</h1>
        <p class="dashboard-subtitle">Overview of curriculum, subject, configuration, and recent system activity.</p>
      </div>
    </div>

    <?php if ($visibleSummaries !== []): ?>
    <section class="dashboard-section" aria-labelledby="summaryHeading">
      <div class="dashboard-section-heading">
        <div>
          <h2 id="summaryHeading">Curriculum Records</h2>
          <p>Current totals from the authoritative curriculum database.</p>
        </div>
      </div>
      <div class="summary-grid">
        <?php foreach ($visibleSummaries as $definition): ?>
        <article class="summary-card">
          <span class="summary-card-icon"><i class="fa-solid <?= dashboard_escape($definition['icon']) ?>" aria-hidden="true"></i></span>
          <div>
            <span class="summary-card-label"><?= dashboard_escape($definition['label']) ?></span>
            <strong class="summary-card-value"><?= number_format((int)($dashboard['counts'][$definition['key']] ?? 0)) ?></strong>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    </section>
    <?php else: ?>
    <section class="dashboard-section dashboard-card" aria-label="Curriculum summary">
      <div class="dashboard-empty">
        <i class="fa-solid fa-lock" aria-hidden="true"></i>
        Curriculum summary data is shown only when your assigned permissions allow it.
      </div>
    </section>
    <?php endif; ?>

    <div class="dashboard-grid">
      <?php if ($canViewCurriculum): ?>
      <section class="dashboard-card" aria-labelledby="versionStatusHeading">
        <div class="dashboard-card-header">
          <div>
            <h2 id="versionStatusHeading">Technical Version Status</h2>
            <p>Counts by the currently stored curriculum-version state.</p>
          </div>
        </div>
        <div class="status-summary">
          <?php foreach (['draft', 'active', 'archived'] as $status): ?>
          <div>
            <span><?= dashboard_escape($status) ?></span>
            <strong><?= number_format((int)($dashboard['version_statuses'][$status] ?? 0)) ?></strong>
          </div>
          <?php endforeach; ?>
        </div>
        <p class="dashboard-notice" role="note">
          <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
          <span>These are system record states only and do not represent official institutional approval statuses.</span>
        </p>
      </section>
      <?php endif; ?>

      <section class="dashboard-card" aria-labelledby="quickActionsHeading">
        <div class="dashboard-card-header">
          <div>
            <h2 id="quickActionsHeading">Quick Actions</h2>
            <p>Open the curriculum tools permitted by your current RBAC assignments.</p>
          </div>
        </div>
        <?php if ($quickActions !== []): ?>
        <div class="quick-actions">
          <?php foreach ($quickActions as $action): ?>
          <a class="quick-action" href="<?= dashboard_escape($action['href']) ?>">
            <i class="fa-solid <?= dashboard_escape($action['icon']) ?>" aria-hidden="true"></i>
            <span><?= dashboard_escape($action['label']) ?></span>
          </a>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="dashboard-empty">
          <i class="fa-solid fa-compass" aria-hidden="true"></i>
          No curriculum quick actions are available for your current permissions.
        </div>
        <?php endif; ?>
      </section>
    </div>

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
<script src="../js/dashboard.js?v=<?= $dashboardScriptVersion ?>"></script>
</body>
</html>
