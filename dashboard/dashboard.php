<?php
require_once __DIR__ . '/../shared/bootstrap.php';
require_permission('dashboard.view');
require_once __DIR__ . '/DashboardAnalyticsService.php';
require_once __DIR__ . '/../modules/curriculum/validators/DomainValidator.php';

$userId = current_user_id();
$dashboardPermissions = DashboardAnalyticsService::PERMISSIONS;
$can = [];
foreach ($dashboardPermissions as $permission) {
    $can[$permission] = $userId !== null && user_can($userId, $permission);
}

// Filter from the URL: ?program=<id> (omitted = All programs) and optional &version=<id>.
$filterError = '';
$programId = null;
$versionId = null;
try {
    if (isset($_GET['program']) && $_GET['program'] !== '' && $_GET['program'] !== 'all') {
        $programId = DomainValidator::positiveId($_GET['program'], 'Program or strand');
    }
    if ($programId !== null && isset($_GET['version']) && $_GET['version'] !== '') {
        $versionId = DomainValidator::positiveId($_GET['version'], 'Curriculum version');
    }
} catch (Throwable $error) {
    $filterError = 'The filter in the address was not valid, so all programs are shown.';
    $programId = $versionId = null;
}

$dashboardConfig = require __DIR__ . '/dashboard_config.php';
$dashboardService = new DashboardAnalyticsService(authorization_connection(), $dashboardConfig);
$dashboardPayload = null;
try {
    $dashboardPayload = $dashboardService->build((int)$userId, $can, $programId, $versionId);
} catch (DomainValidationException | DomainNotFoundException $error) {
    $filterError = $error->getMessage() . ' All programs are shown instead.';
    $programId = $versionId = null;
    $dashboardPayload = $dashboardService->build((int)$userId, $can, null, null);
} catch (Throwable $error) {
    error_log('Dashboard analytics failed: ' . $error->getMessage());
    $dashboardPayload = null;
}
$dashboardPrograms = $can['program.view'] ? $dashboardService->programs() : [];
$programGroups = [];
foreach ($dashboardPrograms as $program) {
    $programGroups[$program['group']][] = $program;
}

$selectedProgram = null;
foreach ($dashboardPrograms as $program) {
    if ($program['id'] === $programId) $selectedProgram = $program;
}

$assetVersion = static fn(string $path): string => is_file(__DIR__ . '/../' . $path) ? (string)filemtime(__DIR__ . '/../' . $path) : '1';

function dashboard_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
// Filter placement (UI_Decisions D10): Super Admin has a single program-scoped card (the pipeline), so its filter lives in that
// card's header. Every other profile keeps the page-level filter.
$isCardScope = ($dashboardPayload['profile'] ?? '') === 'super_admin' && $can['program.view'] && $can['curriculum.view'];
ob_start();
?>
<label for="dashboardProgram" id="dashboardProgramLabel"<?= $isCardScope ? ' class="visually-hidden"' : '' ?>><?= $isCardScope ? 'Program or strand for the curriculum pipeline' : 'Program / Strand' ?></label>
<!-- Native select is the value holder and the no-JavaScript fallback; js/csms-program-picker.js
     replaces it on screen with the searchable picker. -->
<select id="dashboardProgram" name="program" data-program-picker>
  <option value="all"<?= $programId === null ? ' selected' : '' ?>>All programs</option>
  <?php foreach ($programGroups as $group => $programs): ?>
  <optgroup label="<?= dashboard_escape($group) ?>">
    <?php foreach ($programs as $program): ?>
    <option value="<?= (int)$program['id'] ?>" data-code="<?= dashboard_escape($program['code']) ?>" data-name="<?= dashboard_escape($program['name']) ?>"
            data-level="<?= dashboard_escape($program['level']) ?>"<?= $program['inactive'] ? ' data-inactive="1"' : '' ?><?= $program['id'] === $programId ? ' selected' : '' ?>><?= dashboard_escape($program['code'] . ' — ' . $program['name'] . ($program['inactive'] ? ' (Inactive)' : '')) ?></option>
    <?php endforeach; ?>
  </optgroup>
  <?php endforeach; ?>
</select>
<?php
$programFilterMarkup = ob_get_clean();
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
  <link rel="stylesheet" href="../css/curriculum-dashboard.css?v=<?= $assetVersion('css/curriculum-dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/csms-typography.css?v=<?= $assetVersion('css/csms-typography.css') ?>"/>
  <link rel="stylesheet" href="../css/csms-dashboard.css?v=<?= $assetVersion('css/csms-dashboard.css') ?>"/>
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

  <main class="content curriculum-dashboard csms-dash" id="curriculumDashboard"
        data-dashboard-api="dashboard_data.php" data-app-root="../" data-filter-scope="<?= $isCardScope ? 'card' : 'page' ?>"
        data-user-id="<?= (int)$userId ?>"
        data-can-view-programs="<?= $can['program.view'] ? '1' : '0' ?>"
        data-can-view-curriculum="<?= $can['curriculum.view'] ? '1' : '0' ?>">
    <div class="page-title-bar dashboard-title-bar csms-dash-header">
      <div>
        <h1 class="page-title csms-dash-title">Dashboard</h1>
        <p class="dashboard-subtitle" id="dashboardSubtitle">
          <?= $selectedProgram === null
              ? 'Curriculum overview across all programs and strands.'
              : dashboard_escape('Curriculum overview for ' . $selectedProgram['code'] . ' — ' . $selectedProgram['name'] . '.') ?>
        </p>
      </div>
      <?php if ($can['program.view'] && $can['curriculum.view'] && !$isCardScope): ?>
      <form class="csms-dash-filter" id="dashboardFilter" method="get" action="dashboard.php" role="search" aria-label="Dashboard filter">
        <?= $programFilterMarkup ?>
        <span class="csms-dash-version" id="dashboardVersionWrap"<?= $programId === null ? ' hidden' : '' ?>>
          <label for="dashboardVersion">Version</label>
          <select id="dashboardVersion" name="version">
            <?php foreach (($dashboardPayload['filter']['versions'] ?? []) as $version): ?>
            <option value="<?= (int)$version['id'] ?>"<?= $version['id'] === ($dashboardPayload['filter']['version_id'] ?? null) ? ' selected' : '' ?>><?= dashboard_escape($version['label'] . ' · ' . ucfirst($version['status']) . ' (' . $version['curriculum_code'] . ')') ?></option>
            <?php endforeach; ?>
          </select>
        </span>
        <noscript><button type="submit" class="button-primary">Apply</button></noscript>
      </form>
      <?php endif; ?>
    </div>

    <p id="dashboardError" class="dashboard-error csms-dash-alert" role="alert"<?= $filterError === '' && $dashboardPayload !== null ? ' hidden' : '' ?>>
      <?= dashboard_escape($dashboardPayload === null ? 'Dashboard analytics could not be loaded. Reload the page; if it continues, ask the administrator to check the application log.' : $filterError) ?>
    </p>
    <p id="dashboardStatus" class="visually-hidden" role="status" aria-live="polite"></p>

    <section id="dashboardResults" aria-label="Dashboard analytics"<?= $dashboardPayload === null ? ' hidden' : '' ?>>
      <div class="csms-stat-row" id="metricGrid"></div>
      <div class="csms-chart-grid" id="analyticsGrid"></div>
      <p class="csms-dash-note" id="dashboardVersionRule" hidden></p>
      <div class="csms-list-grid" id="dashboardLists"></div>
    </section>
    <section id="dashboardEmpty" class="dashboard-empty" hidden></section>
    <?php if ($isCardScope): ?>
    <!-- Home of the pipeline card's filter; js/csms-dashboard.js moves it into that card's header. -->
    <div id="dashboardFilterHome" hidden><div class="csms-card__filter" id="dashboardCardFilter"><?= $programFilterMarkup ?></div></div>
    <?php endif; ?>
  </main>

  <footer class="footer">Bestlink College of the Philippines &middot; CSMS-SMS2 &copy; 2026</footer>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<!-- Shared curriculum-page script: binds the topbar burger, sidebar overlay and sidebar dropdown groups. -->
<script src="../modules/curriculum/assets/curriculum-common.js?v=<?= substr(hash_file('sha256', __DIR__.'/../modules/curriculum/assets/curriculum-common.js'),0,16) ?>"></script>
<script id="dashboardInitialData" type="application/json"><?= json_encode($dashboardPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="../assets/ui/dist/js/chart.umd.min.js?v=<?= $assetVersion('assets/ui/dist/js/chart.umd.min.js') ?>"></script>
<script src="../js/csms-program-picker.js?v=<?= $assetVersion('js/csms-program-picker.js') ?>"></script>
<script src="../js/csms-dashboard.js?v=<?= $assetVersion('js/csms-dashboard.js') ?>"></script>
</body>
</html>
