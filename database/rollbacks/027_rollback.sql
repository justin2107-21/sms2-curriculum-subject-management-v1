-- Reverses migration 027. Take a backup first, and revert the code too: current code reads roles.is_active.
-- Run against one chosen database: mysql --default-character-set=utf8mb4 -u root <database> < 027_rollback.sql
-- Kept outside database/migrations so deploy.php never runs it.
START TRANSACTION;

-- Accounts created after 027 have no snapshot row: map them back first.
INSERT IGNORE INTO user_roles (user_id, role_id, assigned_at, assigned_by)
SELECT ur.user_id, o.id, ur.assigned_at, ur.assigned_by
FROM user_roles ur
JOIN roles n ON n.id = ur.role_id
JOIN roles o ON o.name = CASE n.name
  WHEN 'super_admin' THEN 'system_administrator'
  WHEN 'admin' THEN 'legacy_admin'
  WHEN 'staff' THEN 'academic_curriculum_staff'
  WHEN 'acad_office' THEN 'department_head_curriculum_reviewer'
  WHEN 'acad_faculty' THEN 'faculty_academic_viewer' END
WHERE n.is_active = 1;

-- Pre-027 accounts return to exactly their snapshot assignments.
DELETE ur FROM user_roles ur
WHERE EXISTS (SELECT 1 FROM rbac_snapshot_027_user_roles s WHERE s.user_id = ur.user_id);
INSERT INTO user_roles (user_id, role_id, assigned_at, assigned_by)
SELECT s.user_id, s.role_id, s.assigned_at, s.assigned_by
FROM rbac_snapshot_027_user_roles s JOIN users u ON u.id = s.user_id;

UPDATE users u JOIN rbac_snapshot_027_deactivated_users d ON d.user_id = u.id SET u.is_active = 1;

-- The new roles' FKs cascade their user_roles and role_permissions rows.
DELETE FROM roles WHERE name IN ('super_admin', 'admin', 'staff', 'acad_office', 'acad_faculty');
DELETE FROM permissions WHERE name IN ('user.manage', 'curriculum.view_unapproved', 'curriculum.review.submit',
  'curriculum.review.decide', 'curriculum_export.draft', 'curriculum_export.official');

UPDATE roles SET name = 'admin' WHERE name = 'legacy_admin';

DELETE FROM role_permissions;
INSERT INTO role_permissions (role_id, permission_id)
SELECT role_id, permission_id FROM rbac_snapshot_027_role_permissions;

UPDATE roles r JOIN rbac_snapshot_027_roles s ON s.id = r.id
SET r.display_name = s.display_name, r.description = s.description;

DELETE FROM schema_migrations WHERE migration = '027_final_user_types.sql';

COMMIT;

ALTER TABLE roles DROP COLUMN user_type, DROP COLUMN is_active;
DROP TABLE rbac_snapshot_027_roles, rbac_snapshot_027_user_roles,
  rbac_snapshot_027_role_permissions, rbac_snapshot_027_deactivated_users;
