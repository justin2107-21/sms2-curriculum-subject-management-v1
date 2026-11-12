<?php
// Shared, permission-aware navigation: Main, Records, Curriculum (the ten official modules, plain links), Setup.
// $APP_ROOT is the relative path from the current page to the application root.
// $ACTIVE_NAV identifies the active nav key below. Sub-pages of a module keep the module's link active and are
// reachable from the module page itself (see modules/curriculum/pages/_module_views.php).

$APP_ROOT = $APP_ROOT ?? '../';
$ACTIVE_NAV = $ACTIVE_NAV ?? '';
$sidebarUserId = current_user_id();
$sidebarCan = static fn(string $permission): bool => $sidebarUserId !== null && user_can($sidebarUserId, $permission);
$sidebarPages = $APP_ROOT . 'modules/curriculum/pages/';

// [label, href, icon, nav keys that make it active, permission, long label?]
$sidebarMain = [
    ['Dashboard', $APP_ROOT . 'dashboard/dashboard.php', 'fa-gauge', ['dashboard'], 'dashboard.view', false],
];
$sidebarRecords = [
    ['Academic Programs', $sidebarPages . 'academic_programs.php', 'fa-graduation-cap', ['academic-programs'], 'program.view', false],
    ['Subjects', $sidebarPages . 'subjects.php', 'fa-book', ['subjects'], 'subject.view', false],
];
$sidebarCurriculum = [
    ['Curriculum Builder', $sidebarPages . 'curriculum_builder.php', 'fa-table-list', ['curriculum-builder'], 'curriculum.view', true],
    ['Subject Mapping', $sidebarPages . 'subject_mapping.php', 'fa-diagram-project', ['subject-mapping'], 'curriculum.view', false],
    ['Pre-requisite Configuration', $sidebarPages . 'prerequisite_configuration.php', 'fa-code-branch', ['prerequisite-configuration'], 'prerequisite.view', true],
    ['Electives Manager', $sidebarPages . 'electives_manager.php', 'fa-layer-group', ['electives-manager'], 'elective.view', true],
    ['Academic Strand/<wbr>Course Assignment', $sidebarPages . 'program_centric_assignment.php', 'fa-share-nodes', ['program-assignment-program', 'program-assignment-subject'], 'program_assignment.view', true],
    ['Subject Offering History', $sidebarPages . 'subject_history_view.php', 'fa-clock-rotate-left', ['subject-history-subject', 'subject-history-curriculum'], 'subject_history.view', true],
    ['Subject Equivalency Tool', $sidebarPages . 'subject_equivalency.php', 'fa-right-left', ['subject-equivalency'], 'equivalency.view', true],
    ['Grade Weighting Setup', $sidebarPages . 'grade_weighting_setup.php', 'fa-chart-pie', ['grade-weighting'], 'grade_weighting.view', true],
    ['CHED / DepEd Compliance Validator', $sidebarPages . 'compliance_validator.php', 'fa-shield-halved', ['compliance-validator'], 'compliance.view', true],
    ['Curriculum Export Tool', $sidebarPages . 'curriculum_export.php', 'fa-file-export', ['curriculum-export'], 'curriculum_export.view', true],
];
$sidebarSetup = [
    ['Users', $APP_ROOT . 'admin/users.php', 'fa-users', ['users'], 'user.manage', false],
    ['Permissions', $APP_ROOT . 'admin/permissions.php', 'fa-user-shield', ['permissions'], 'rbac.manage', false],
    ['Audit Logs', $APP_ROOT . 'admin/audit_logs.php', 'fa-clipboard-list', ['audit'], 'audit.view', false],
    ['System Settings', $APP_ROOT . 'admin/settings.php', 'fa-sliders', ['system-settings'], 'settings.view', false],
    ['Account Settings', $APP_ROOT . 'dashboard/account.php', 'fa-gear', ['settings'], 'account.view', false],
];
$sidebarSections = [
    ['MAIN', $sidebarMain, false],
    ['RECORDS', $sidebarRecords, true],
    ['CURRICULUM', $sidebarCurriculum, true],
    ['SETUP', $sidebarSetup, true],
];
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-logo">
      <a href="<?= $APP_ROOT ?>dashboard/dashboard.php" title="Go to Dashboard">
        <img src="<?= $APP_ROOT ?>images/BCP_LOGO.png" alt="BCP Logo" class="sidebar-logo-img"/>
      </a>
    </div>
  </div>

  <nav class="sidebar-nav" aria-label="Primary navigation">
    <?php foreach ($sidebarSections as [$sectionTitle, $sectionItems, $sectionDivider]):
        $visible = array_values(array_filter($sectionItems, static fn(array $item): bool => $sidebarCan($item[4])));
        if (!$visible) continue;
        if ($sectionDivider): ?>
    <div class="sidebar-divider"></div>
        <?php endif; ?>
    <div class="sidebar-brand sidebar-brand-2"><div class="brand-title"><?= $sectionTitle ?></div></div>
        <?php foreach ($visible as [$label, $href, $icon, $keys, , $long]):
            $isActive = in_array($ACTIVE_NAV, $keys, true); ?>
    <div class="nav-group">
      <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" class="sidebar-item <?= $isActive ? 'active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
        <i class="fa-solid <?= $icon ?>" aria-hidden="true"></i><span<?= $long ? ' class="sidebar-long-label"' : '' ?>><?= $label ?></span>
      </a>
    </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
</aside>
<?php
$sidebarStateScriptPath = __DIR__ . '/../js/sidebar-state.js';
$sidebarStateScriptVersion = is_file($sidebarStateScriptPath) ? (string)filemtime($sidebarStateScriptPath) : '1';
?>
<script src="<?= $APP_ROOT ?>js/sidebar-state.js?v=<?= $sidebarStateScriptVersion ?>"></script>
