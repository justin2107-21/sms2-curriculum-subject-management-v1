<?php
// Bootstrap-profile sidebar (Bootstrap Icons + Bootstrap collapse). Separate from
// shared/sidebar.php, which stays untouched for the legacy profile.
// Same permissions and ACTIVE_NAV keys as the legacy sidebar. Sections: Main, Records,
// Curriculum, Setup. Requires shared/bootstrap.php (current_user_id, user_can).
require_once __DIR__ . '/profile.php';

if (!function_exists('ui_sidebar')) {
    function ui_sidebar(string $appRoot = '../', string $activeNav = ''): void
    {
        $uid = current_user_id();
        $can = static fn(string $p): bool => $uid !== null && user_can($uid, $p);
        $page = static fn(string $f): string => $appRoot . 'modules/curriculum/pages/' . $f;

        // item: [label, href, icon, nav-key(s), permission]. Plain links only; sub-pages are reached from their module page.
        $workspace = [
            ['Dashboard', $appRoot . 'dashboard/dashboard.php', 'speedometer2', 'dashboard', 'dashboard.view'],
        ];
        $records = [
            ['Academic Programs', $page('academic_programs.php'), 'mortarboard', 'academic-programs', 'program.view'],
            ['Subjects', $page('subjects.php'), 'book', 'subjects', 'subject.view'],
        ];
        $curriculum = [
            ['Curriculum Builder', $page('curriculum_builder.php'), 'table', 'curriculum-builder', 'curriculum.view'],
            ['Subject Mapping', $page('subject_mapping.php'), 'diagram-3', 'subject-mapping', 'curriculum.view'],
            ['Pre-requisite Configuration', $page('prerequisite_configuration.php'), 'diagram-2', 'prerequisite-configuration', 'prerequisite.view'],
            ['Electives Manager', $page('electives_manager.php'), 'collection', 'electives-manager', 'elective.view'],
            ['Academic Strand/Course Assignment', $page('program_centric_assignment.php'), 'share', 'program-assignment-program,program-assignment-subject', 'program_assignment.view'],
            ['Subject Offering History', $page('subject_history_view.php'), 'clock-history', 'subject-history-subject,subject-history-curriculum', 'subject_history.view'],
            ['Subject Equivalency Tool', $page('subject_comparison.php'), 'arrow-left-right', 'subject-equivalency-comparison,subject-equivalency-records', 'equivalency.view'],
            ['Grade Weighting Setup', $page('grade_weighting_setup.php'), 'pie-chart', 'grade-weighting', 'grade_weighting.view'],
            ['CHED / DepEd Compliance Validator', $page('validate_curriculum.php'), 'shield-check', 'compliance-validate,compliance-rules,compliance-references,compliance-history', 'compliance.view'],
            ['Curriculum Export Tool', $page('curriculum_export.php'), 'file-earmark-arrow-down', 'curriculum-export', 'curriculum_export.view'],
        ];
        $setup = [
            ['Users', $appRoot . 'admin/users.php', 'people', 'users', 'user.manage'],
            ['Permissions', $appRoot . 'admin/permissions.php', 'person-lock', 'permissions', 'rbac.manage'],
            ['Audit Logs', $appRoot . 'admin/audit_logs.php', 'journal-text', 'audit', 'audit.view'],
            ['System Settings', $appRoot . 'admin/settings.php', 'sliders', 'system-settings', 'settings.view'],
            ['Account Settings', $appRoot . 'dashboard/account.php', 'gear', 'settings', 'account.view'],
        ];

        $renderItem = static function (array $i) use ($activeNav, $can): string {
            [$label, $href, $icon, $key, $perm] = $i;
            if (!$can($perm)) return '';
            $active = in_array($activeNav, explode(',', $key), true);
            return '<a class="csms-sidebar__item' . ($active ? ' is-active' : '') . '" href="' . ui_e($href) . '"'
                . ($active ? ' aria-current="page"' : '') . '><i class="bi bi-' . ui_e($icon) . '" aria-hidden="true"></i>'
                . '<span class="csms-sidebar__label">' . ui_e($label) . '</span></a>';
        };
        $renderSection = static function (string $title, array $entries) use ($renderItem): string {
            $html = '';
            foreach ($entries as $e) {
                $html .= $renderItem($e);
            }
            return $html === '' ? '' : '<div class="csms-sidebar__heading">' . ui_e($title) . '</div>' . $html;
        };
        ?>
<aside class="csms-sidebar" id="csmsSidebar" aria-label="Primary navigation">
  <div class="csms-sidebar__brand">
    <a href="<?= ui_e($appRoot . 'dashboard/dashboard.php') ?>" title="Go to Dashboard">
      <img src="<?= ui_e($appRoot . 'images/BCP_LOGO.png') ?>" alt="BCP Logo">
    </a>
  </div>
  <nav class="csms-sidebar__nav">
    <?= $renderSection('Main', $workspace) ?>
    <?= $renderSection('Records', $records) ?>
    <?= $renderSection('Curriculum', $curriculum) ?>
    <?= $renderSection('Setup', $setup) ?>
  </nav>
</aside>
        <?php
    }
}
