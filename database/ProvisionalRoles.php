<?php
// The final user types. Migration 027 seeds these grants; tests/rbac-final-roles.php keeps both in step.
// Class name kept: pages and tests already refer to it.
final class ProvisionalRoles
{
    public const SUPER_ADMIN = 'super_admin';
    /** Roles a user.manage holder without rbac.manage may touch. */
    public const LIMITED_ROLES = ['staff', 'acad_office', 'acad_faculty'];
    /** May be granted beyond a role's defaults by a Super Admin. */
    public const SENSITIVE = ['curriculum.reference.attach', 'compliance.reference.verify', 'curriculum.reference.classify',
        'curriculum.version.clone', 'curriculum.outcome_mapping.manage', 'curriculum.clo.manage', 'curriculum.assessment_plan.manage'];

    public static function definitions(): array
    {
        $base = ['dashboard.view', 'account.view', 'account.update'];
        $definitions = [
            'super_admin' => ['label' => 'Super Admin', 'user_type' => 'Super Admin', 'description' => 'Everything, including all accounts, permissions, settings and audit logs.',
                'permissions' => array_merge($base, ['rbac.manage', 'user.manage', 'audit.view', 'settings.view',
                    'program.view', 'program.create', 'program.update', 'program_assignment.view', 'program_assignment.manage',
                    'curriculum.view', 'curriculum.view_unapproved', 'curriculum.create', 'curriculum.update', 'curriculum.version.create', 'curriculum.version.clone',
                    'curriculum.review.submit', 'curriculum.review.decide', 'curriculum.reference.attach', 'curriculum.reference.classify', 'compliance.reference.verify',
                    'subject.view', 'subject.create', 'subject.update', 'subject_history.view', 'prerequisite.view', 'prerequisite.manage', 'elective.view', 'elective.manage',
                    'curriculum.outcome_mapping.manage', 'curriculum.clo.manage', 'curriculum.assessment_plan.manage', 'grade_weighting.view', 'grade_weighting.manage',
                    'equivalency.view', 'equivalency.manage', 'compliance.view', 'compliance.run', 'compliance.manage',
                    'curriculum_export.view', 'curriculum_export.draft', 'curriculum_export.official', 'archive.view', 'archive.restore',
                    'student.view', 'student.create', 'student.update', 'student.delete', 'student.status.update', 'api_client.manage'])],
            'admin' => ['label' => 'Admin', 'user_type' => 'Admin', 'description' => 'Manages Staff and Acad accounts, programs and curricula records; views audit logs.',
                'permissions' => array_merge($base, ['user.manage', 'audit.view', 'program.view', 'program.create', 'program.update', 'program_assignment.view', 'program_assignment.manage',
                    'curriculum.view', 'curriculum.view_unapproved', 'curriculum.create', 'curriculum.update', 'curriculum.version.create',
                    'subject.view', 'subject.create', 'subject.update', 'subject_history.view', 'prerequisite.view', 'elective.view', 'grade_weighting.view', 'equivalency.view',
                    'compliance.view', 'curriculum_export.view', 'curriculum_export.official', 'archive.view', 'archive.restore'])],
            'staff' => ['label' => 'Staff', 'user_type' => 'Staff', 'description' => 'Prepares curriculum drafts and submits them for review.',
                'permissions' => array_merge($base, ['program.view', 'program_assignment.view', 'curriculum.view', 'curriculum.view_unapproved', 'curriculum.update',
                    'curriculum.version.create', 'curriculum.version.clone', 'curriculum.review.submit', 'curriculum.reference.attach',
                    'subject.view', 'subject.create', 'subject.update', 'subject_history.view', 'prerequisite.view', 'prerequisite.manage', 'elective.view', 'elective.manage',
                    'curriculum.outcome_mapping.manage', 'curriculum.clo.manage', 'curriculum.assessment_plan.manage', 'grade_weighting.view', 'grade_weighting.manage',
                    'equivalency.view', 'compliance.view', 'compliance.run', 'curriculum_export.view', 'curriculum_export.draft', 'archive.view', 'archive.restore'])],
            'acad_office' => ['label' => 'Acad — Office', 'user_type' => 'Acad', 'description' => 'Reviews curricula, decides equivalencies, official export.',
                'permissions' => array_merge($base, ['program.view', 'program_assignment.view', 'curriculum.view', 'curriculum.view_unapproved', 'curriculum.review.decide',
                    'curriculum.reference.classify', 'compliance.reference.verify', 'subject.view', 'subject_history.view', 'prerequisite.view', 'elective.view', 'grade_weighting.view',
                    'equivalency.view', 'equivalency.manage', 'compliance.view', 'compliance.run', 'curriculum_export.view', 'curriculum_export.official'])],
            'acad_faculty' => ['label' => 'Acad — Faculty', 'user_type' => 'Acad', 'description' => 'Reads curricula, maps and prerequisites.',
                'permissions' => array_merge($base, ['program.view', 'curriculum.view', 'subject.view', 'prerequisite.view', 'elective.view'])],
        ];
        // rbac.manage and settings.view stay Super Admin only; user.manage goes no lower than Admin (it is in Admin's defaults).
        foreach ($definitions as &$definition) {
            $definition['grantable_permissions'] = array_values(array_unique(array_merge($definition['permissions'], self::SENSITIVE)));
        }
        unset($definition);
        return $definitions;
    }
}
