<?php
// Bootstrap-profile sidebar (Bootstrap Icons + Bootstrap collapse). Separate from
// shared/sidebar.php, which stays untouched for the legacy profile.
// Same permissions and ACTIVE_NAV keys as the legacy sidebar. Groups per D5:
// Workspace, Curriculum, Setup. Requires shared/bootstrap.php (current_user_id, user_can).
require_once __DIR__ . '/profile.php';

if (!function_exists('ui_sidebar')) {
    function ui_sidebar(string $appRoot = '../', string $activeNav = ''): void
    {
        $uid = current_user_id();
        $can = static fn(string $p): bool => $uid !== null && user_can($uid, $p);
        $page = static fn(string $f): string => $appRoot . 'modules/curriculum/pages/' . $f;

        // item: [label, href, icon, nav-key, permission]; group: has 'children'.
        $workspace = [
            ['Dashboard', $appRoot . 'dashboard/dashboard.php', 'speedometer2', 'dashboard', 'dashboard.view'],
        ];
        $curriculum = [
            ['id' => 'builder', 'label' => 'Curriculum Builder', 'icon' => 'table', 'children' => [
                ['Academic Programs', $page('academic_programs.php'), 'academic-programs', 'program.view'],
                ['Curricula', $page('curricula.php'), 'curricula', 'curriculum.view'],
            ]],
            ['Subjects', $page('subjects.php'), 'book', 'subjects', 'subject.view'],
            ['Subject Mapping', $page('subject_mapping.php'), 'diagram-3', 'subject-mapping', 'curriculum.view'],
            ['Pre-requisite Configuration', $page('prerequisite_configuration.php'), 'diagram-2', 'prerequisite-configuration', 'prerequisite.view'],
            ['Electives Manager', $page('electives_manager.php'), 'collection', 'electives-manager', 'elective.view'],
            ['id' => 'assignment', 'label' => 'Academic Strand/Course Assignment', 'icon' => 'share', 'children' => [
                ['Program-Centric View', $page('program_centric_assignment.php'), 'program-assignment-program', 'program_assignment.view'],
                ['Subject-Centric View', $page('subject_centric_assignment.php'), 'program-assignment-subject', 'program_assignment.view'],
            ]],
            ['id' => 'history', 'label' => 'Subject Offering History', 'icon' => 'clock-history', 'children' => [
                ['Subject History View', $page('subject_history_view.php'), 'subject-history-subject', 'subject_history.view'],
                ['Curriculum Version History', $page('curriculum_history_view.php'), 'subject-history-curriculum', 'subject_history.view'],
            ]],
            ['id' => 'equivalency', 'label' => 'Subject Equivalency Tool', 'icon' => 'arrow-left-right', 'children' => [
                ['Subject Comparison', $page('subject_comparison.php'), 'subject-equivalency-comparison', 'equivalency.view'],
                ['Equivalency Records', $page('equivalency_records.php'), 'subject-equivalency-records', 'equivalency.view'],
            ]],
            ['Grade Weighting Setup', $page('grade_weighting_setup.php'), 'pie-chart', 'grade-weighting', 'grade_weighting.view'],
            ['id' => 'compliance', 'label' => 'CHED / DepEd Compliance Validator', 'icon' => 'shield-check', 'children' => [
                ['Validate Curriculum', $page('validate_curriculum.php'), 'compliance-validate', 'compliance.view'],
                ['Compliance Rules', $page('compliance_rules.php'), 'compliance-rules', 'compliance.view'],
                ['References', $page('compliance_references.php'), 'compliance-references', 'compliance.view'],
                ['Validation History', $page('validation_history.php'), 'compliance-history', 'compliance.view'],
            ]],
            ['Curriculum Export Tool', $page('curriculum_export.php'), 'file-earmark-arrow-down', 'curriculum-export', 'curriculum_export.view'],
        ];
        $setup = [
            ['Users', $appRoot . 'admin/users.php', 'people', 'users', 'rbac.manage'],
            ['Permissions', $appRoot . 'admin/permissions.php', 'person-lock', 'permissions', 'rbac.manage'],
            ['Audit Logs', $appRoot . 'admin/audit_logs.php', 'journal-text', 'audit', 'audit.view'],
            ['System Settings', $appRoot . 'admin/settings.php', 'sliders', 'system-settings', 'settings.view'],
            ['Account Settings', $appRoot . 'dashboard/account.php', 'gear', 'settings', 'account.view'],
        ];

        $renderItem = static function (array $i) use ($activeNav, $can): string {
            [$label, $href, $icon, $key, $perm] = $i;
            if (!$can($perm)) return '';
            $active = $activeNav === $key;
            return '<a class="csms-sidebar__item' . ($active ? ' is-active' : '') . '" href="' . ui_e($href) . '"'
                . ($active ? ' aria-current="page"' : '') . '><i class="bi bi-' . ui_e($icon) . '" aria-hidden="true"></i>'
                . '<span class="csms-sidebar__label">' . ui_e($label) . '</span></a>';
        };
        $renderGroup = static function (array $g) use ($activeNav, $can): string {
            $links = '';
            $open = false;
            foreach ($g['children'] as [$label, $href, $key, $perm]) {
                if (!$can($perm)) continue;
                $active = $activeNav === $key;
                $open = $open || $active;
                $links .= '<a class="csms-sidebar__subitem' . ($active ? ' is-active' : '') . '" href="' . ui_e($href) . '"'
                    . ($active ? ' aria-current="page"' : '') . '><span class="csms-sidebar__label">' . ui_e($label) . '</span></a>';
            }
            if ($links === '') return '';
            $id = 'csmsNav_' . $g['id'];
            return '<button type="button" class="csms-sidebar__item" data-bs-toggle="collapse" data-bs-target="#' . $id . '"'
                . ' aria-expanded="' . ($open ? 'true' : 'false') . '" aria-controls="' . $id . '">'
                . '<i class="bi bi-' . ui_e($g['icon']) . '" aria-hidden="true"></i>'
                . '<span class="csms-sidebar__label">' . ui_e($g['label']) . '</span>'
                . '<i class="bi bi-chevron-down csms-sidebar__chevron" aria-hidden="true"></i></button>'
                . '<div class="collapse csms-sidebar__sub' . ($open ? ' show' : '') . '" id="' . $id . '">' . $links . '</div>';
        };
        $renderSection = static function (string $title, array $entries) use ($renderItem, $renderGroup): string {
            $html = '';
            foreach ($entries as $e) {
                $html .= isset($e['children']) ? $renderGroup($e) : $renderItem($e);
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
    <?= $renderSection('Workspace', $workspace) ?>
    <?= $renderSection('Curriculum', $curriculum) ?>
    <?= $renderSection('Setup', $setup) ?>
  </nav>
</aside>
        <?php
    }
}
