<?php

/** Server-owned routes, permissions, actions and accepted context fields. */
final class CIMFProfileRegistry
{
    public static function profiles(): array
    {
        $academic = ['academic_program_id', 'curriculum_id', 'curriculum_version_id'];
        $profiles = [
            'dashboard' => ['Dashboard', 'dashboard.view', ['explain_csms' => 'Explain CSMS', 'explain_dashboard' => 'Explain Dashboard', 'navigate' => 'Help Navigate Modules'], []],
            'curriculum_builder' => ['Curriculum Builder', 'curriculum.view', ['analyze' => 'Analyze Curriculum', 'summarize' => 'Summarize Curriculum', 'compare_versions' => 'Compare Curriculum Versions', 'explain' => 'Explain Curriculum Structure'], [...$academic, 'version_a_id', 'version_b_id']],
            'subject_master' => ['Subject Master', 'subject.view', ['review' => 'Review Subject Information', 'explain' => 'Explain Subject Information', 'compare' => 'Compare Subjects', 'usage' => 'Summarize Subject Usage'], ['subject_id', 'other_subject_id']],
            'subject_mapping' => ['Subject Mapping', 'curriculum.view', ['review' => 'Review Mapping', 'explain' => 'Explain Placement', 'compare' => 'Compare Placements', 'distribution' => 'Review Distribution'], [...$academic, 'subject_id', 'other_subject_id', 'year_level', 'semester']],
            'prerequisite_configuration' => ['Pre-requisite Configuration', 'prerequisite.view', ['prerequisite_review' => 'Review Prerequisites', 'prerequisite_subject_review' => 'Review Selected Subject', 'compare_subjects' => 'Compare Subjects', 'explain' => 'Explain Relationship'], [...$academic, 'subject_id', 'other_subject_id']],
            'electives_manager' => ['Electives Manager', 'elective.view', ['review' => 'Review Elective Group', 'explain' => 'Explain Elective Structure', 'compare' => 'Compare Group Subjects', 'summarize' => 'Summarize Choices'], [...$academic, 'group_id', 'subject_id', 'other_subject_id']],
            'academic_assignment' => ['Academic Strand/Course Assignment', 'program_assignment.view', ['review' => 'Review Assignment', 'explain' => 'Explain Program Association', 'compare' => 'Compare Program/Subject Context', 'summarize' => 'Summarize Assignments'], ['academic_program_id', 'subject_id', 'assignment_id', 'view']],
            'subject_history' => ['Subject History', 'subject_history.view', ['summarize' => 'Summarize Subject History', 'explain' => 'Explain Stored Usage', 'compare' => 'Compare Historical Usage'], [...$academic, 'subject_id', 'year_level', 'semester', 'version_status', 'page']],
            'curriculum_version_history' => ['Curriculum Version History', 'subject_history.view', ['explain' => 'Explain Version Changes', 'summarize' => 'Summarize Version Changes', 'added' => 'Review Added Subjects', 'removed' => 'Review Removed Subjects', 'moved' => 'Review Moved Subjects'], [...$academic, 'version_a_id', 'version_b_id', 'page']],
            'subject_equivalency' => ['Subject Equivalency', 'equivalency.view', ['review' => 'Review Comparison', 'similarities' => 'Explain Similarities', 'differences' => 'Explain Differences', 'usage' => 'Summarize Usage Context'], ['subject_a_id', 'subject_b_id']],
            'grade_weighting' => ['Grade Weighting', 'grade_weighting.view', ['explain' => 'Explain Weighting', 'review' => 'Review Configuration', 'summarize' => 'Summarize Distribution'], [...$academic, 'curriculum_version_subject_id', 'components']],
            'compliance_validator' => ['Compliance Validator', 'compliance.view', ['explain' => 'Explain Validation Result', 'finding' => 'Explain Finding', 'rule' => 'Explain Rule', 'reference' => 'Explain Reference', 'summarize' => 'Summarize Review Items'], [...$academic, 'run_id', 'rule_id', 'reference_id', 'finding_id']],
            'curriculum_export' => ['Curriculum Export', 'curriculum_export.view', ['summarize' => 'Summarize Selected Curriculum', 'scope' => 'Explain Export Scope', 'notes' => 'Generate Review Notes', 'explain' => 'Explain Curriculum'], [...$academic, 'year_level', 'semester']],
            'admin_help' => ['Administrative Help', 'account.view', ['explain' => 'Explain this page', 'terminology' => 'Explain visible terminology', 'navigate' => 'Help Navigate Administrative Functions'], []],
        ];
        $out = [];
        foreach ($profiles as $key => [$title, $permission, $actions, $fields]) {
            $out[$key] = ['id' => $key, 'title' => $title, 'permission' => $permission,
                'actions' => $actions + ['ask' => 'Ask CIMF about this page'], 'fields' => $fields,
                'context_builder' => $key, 'response_schema' => 'cimf_global_advisory',
                'entity_links' => [], // Findings are plain text; no provider URL becomes a trusted link.
                'restricted_operations' => ['create', 'update', 'delete', 'archive', 'approve', 'save', 'assign', 'move']];
        }
        return $out;
    }

    public static function routes(): array
    {
        $base = 'modules/curriculum/pages/';
        $out = ['dashboard/dashboard.php' => ['dashboard', 'dashboard.view'],
            'dashboard/account.php' => ['admin_help', 'account.view'],
            'admin/settings.php' => ['admin_help', 'settings.view'], 'admin/users.php' => ['admin_help', 'rbac.manage'], 'admin/permissions.php' => ['admin_help', 'rbac.manage'],
            'admin/audit_logs.php' => ['admin_help', 'audit.view'], 'archives.php' => ['admin_help', 'archive.view']];
        foreach (['curriculum_builder' => ['curriculum_builder', 'curricula', 'academic_programs'],
            'subject_master' => ['subjects'], 'subject_mapping' => ['subject_mapping'],
            'prerequisite_configuration' => ['prerequisite_configuration'], 'electives_manager' => ['electives_manager'],
            'academic_assignment' => ['academic_program_assignment', 'program_centric_assignment', 'subject_centric_assignment'],
            'subject_history' => ['subject_offering_history', 'subject_history_view'],
            'curriculum_version_history' => ['curriculum_history_view'],
            'subject_equivalency' => ['subject_equivalency', 'subject_comparison', 'equivalency_records'],
            'grade_weighting' => ['grade_weighting_setup'], 'compliance_validator' => ['compliance_validator', 'validate_curriculum', 'validation_history', 'compliance_rules', 'compliance_references'],
            'curriculum_export' => ['curriculum_export']] as $profile => $pages) {
            foreach ($pages as $page) $out[$base . $page . '.php'] = [$profile, self::profiles()[$profile]['permission']];
        }
        $out[$base . 'academic_programs.php'][1] = 'program.view';
        return $out;
    }

    public static function forRoute(string $route): ?array
    {
        $entry = self::routes()[$route] ?? null;
        if (!$entry) return null;
        $profile = self::profiles()[$entry[0]] + ['route' => $route, 'route_permission' => $entry[1]];
        if ($entry[0] === 'admin_help') $profile['permission'] = $entry[1];
        if (str_ends_with($route, '/academic_programs.php')) $profile['permission'] = 'program.view';
        return $profile;
    }

    public static function issueToken(string $route): string
    {
        if (!isset($_SESSION['_cimf_route_secret'])) $_SESSION['_cimf_route_secret'] = bin2hex(random_bytes(32));
        return hash_hmac('sha256', $route, $_SESSION['_cimf_route_secret']);
    }

    public static function validate(string $route, string $profile, string $action, string $token): array
    {
        $definition = self::forRoute($route);
        if (!$definition || $definition['id'] !== $profile) throw new InvalidArgumentException('Unknown or mismatched CIMF page profile.');
        if (!isset($definition['actions'][$action])) throw new InvalidArgumentException('This CIMF action is not available for the current module.');
        if (!isset($_SESSION['_cimf_route_secret']) || !hash_equals(self::issueToken($route), $token))
            throw new InvalidArgumentException('Open CIMF from an authorized CSMS page.');
        return $definition;
    }

    public static function contextInput(array $definition, array $input): array
    {
        foreach (array_keys($input) as $key) if (!in_array($key, $definition['fields'], true))
            throw new InvalidArgumentException('This context field is not allowed for the current CIMF module.');
        foreach ($input as $key => $value) {
            if ($key === 'view' && !in_array($value, ['program', 'subject'], true)) throw new InvalidArgumentException('Invalid assignment view.');
            if ($key === 'components') {
                if (!is_array($value) || count($value) > 100) throw new InvalidArgumentException('Invalid weighting components.');
            } elseif (!is_scalar($value) && $value !== null) throw new InvalidArgumentException('Invalid CIMF context.');
            elseif (str_ends_with($key, '_id') && $value !== '' && $value !== null
                && !filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) throw new InvalidArgumentException('Invalid selected record ID.');
            elseif (is_string($value) && mb_strlen($value) > 150) throw new InvalidArgumentException('Invalid context value.');
        }
        return array_filter($input, static fn($v) => $v !== '' && $v !== null);
    }

    public static function usageAction(string $profile, string $action): string
    {
        // Existing action_name is VARCHAR(50); preserve meaningful profile/action names without DDL.
        return ($profile === 'prerequisite_configuration' ? 'prerequisite' : $profile) . ':' . $action;
    }
}
