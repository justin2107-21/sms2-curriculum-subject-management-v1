-- Sprint 1 Phase 5E: Subject Offering History read permission.
-- Run exactly once after 007_add_program_assignment_permissions.sql.
--
-- Historical curriculum usage is derived from existing curriculum-version
-- placements, so this migration creates no domain table.

USE sms_db;

START TRANSACTION;

-- Temporary development capability only. Final BCP role assignments must be
-- based on validated client requirements.
INSERT INTO permissions (name, module, description) VALUES
    ('subject_history.view', 'subject_history',
     'View stored subject usage across academic programs, curricula, and curriculum versions.');

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
INNER JOIN permissions ON permissions.name = 'subject_history.view'
WHERE roles.name = 'admin';

COMMIT;
