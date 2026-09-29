-- Sprint 1 Phase 2: temporary compatibility roles and permissions.
-- These are development mappings for validating RBAC. They are not final
-- Bestlink College roles, curriculum permissions, or approval workflows.

USE sms_db;

START TRANSACTION;

INSERT INTO roles (name, display_name, description, is_system) VALUES
    ('admin', 'Administrator', 'Temporary SMS2 compatibility administrator role.', 1),
    ('student', 'Student', 'Temporary SMS2 compatibility student role.', 1)
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name),
    description = VALUES(description),
    is_system = VALUES(is_system);

INSERT INTO permissions (name, module, description) VALUES
    ('dashboard.view', 'dashboard', 'View shared dashboard pages.'),
    ('account.view', 'account', 'View the authenticated user account page.'),
    ('account.update', 'account', 'Update the authenticated user profile or password.'),
    ('student.view', 'student', 'View legacy shared-template student records.'),
    ('student.create', 'student', 'Create legacy shared-template student records.'),
    ('student.update', 'student', 'Update legacy shared-template student records.'),
    ('student.delete', 'student', 'Delete legacy shared-template student records.'),
    ('student.status.update', 'student', 'Update legacy shared-template student statuses.'),
    ('rbac.manage', 'rbac', 'Manage RBAC mappings in a future administration interface.')
ON DUPLICATE KEY UPDATE
    module = VALUES(module),
    description = VALUES(description);

INSERT INTO user_roles (user_id, role_id, assigned_by)
SELECT users.id, roles.id, NULL
FROM users
INNER JOIN roles ON roles.name = users.role
WHERE users.role IN ('admin', 'student')
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id);

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
INNER JOIN permissions ON permissions.name IN (
    'dashboard.view',
    'account.view',
    'account.update',
    'student.view',
    'student.create',
    'student.update',
    'student.delete',
    'student.status.update',
    'rbac.manage'
)
WHERE roles.name = 'admin'
ON DUPLICATE KEY UPDATE permission_id = VALUES(permission_id);

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
INNER JOIN permissions ON permissions.name IN (
    'dashboard.view',
    'account.view',
    'account.update'
)
WHERE roles.name = 'student'
ON DUPLICATE KEY UPDATE permission_id = VALUES(permission_id);

COMMIT;
