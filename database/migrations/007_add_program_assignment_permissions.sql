-- Sprint 1 Phase 5D: Academic Strand/Course Assignment permissions.
-- Run exactly once after 006_create_curriculum_elective_groups.sql.
--
-- The module reuses curriculum_version_subjects as its authoritative domain
-- relationship. No duplicate subject-to-program assignment table is created.

USE sms_db;

START TRANSACTION;

-- Temporary development capabilities only. Final BCP roles and assignments
-- must be based on validated client requirements.
INSERT INTO permissions (name, module, description) VALUES
    ('program_assignment.view', 'program_assignment',
     'View subject use across academic programs and curriculum versions.'),
    ('program_assignment.manage', 'program_assignment',
     'Create, update, and safely remove curriculum subject assignments from the program-assignment workspace.');

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
INNER JOIN permissions ON permissions.name IN (
    'program_assignment.view',
    'program_assignment.manage'
)
WHERE roles.name = 'admin';

COMMIT;
