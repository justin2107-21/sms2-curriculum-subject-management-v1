-- Sprint 1 Phase 5I: generic, read-only curriculum export permission.
-- Run exactly once after 011_create_compliance_validator.sql.
-- No export template, regulatory rule, or curriculum data is seeded.

USE sms_db;

START TRANSACTION;

-- Temporary development capability only. Final responsibility for generating
-- generic or official exports requires client-confirmed access rules.
INSERT INTO permissions (name, module, description) VALUES
    ('curriculum_export.view', 'curriculum_export',
     'Preview, print, and download generic exports from stored curriculum data.');

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
INNER JOIN permissions ON permissions.name = 'curriculum_export.view'
WHERE roles.name = 'admin';

COMMIT;
