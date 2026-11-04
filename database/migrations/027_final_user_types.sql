-- Final user types: Super Admin, Admin, Staff, Acad (Office, Faculty). See docs/ui-reference/Roles_and_Permissions.md.
-- Additive: no row is deleted. Old roles, their grants and assignments stay in place but inactive, and
-- authorization ignores inactive roles. rbac_snapshot_027_* and database/rollbacks/027_rollback.sql reverse it.
USE sms_db;

-- Fail before any change if accounts exist but none would become an active Super Admin.
CREATE TEMPORARY TABLE tmp_027_guard (super_admins INT NOT NULL, CONSTRAINT chk_027_super_admin CHECK (super_admins >= 1));
INSERT INTO tmp_027_guard (super_admins)
SELECT IF((SELECT COUNT(*) FROM user_roles) = 0, 1, COUNT(*))
FROM user_roles ur
JOIN roles r ON r.id = ur.role_id AND r.name = 'system_administrator'
JOIN users u ON u.id = ur.user_id AND u.is_active = 1;

CREATE TABLE rbac_snapshot_027_roles AS SELECT id, name, display_name, description FROM roles;
CREATE TABLE rbac_snapshot_027_user_roles AS SELECT user_id, role_id, assigned_at, assigned_by FROM user_roles;
CREATE TABLE rbac_snapshot_027_role_permissions AS SELECT role_id, permission_id FROM role_permissions;
CREATE TABLE rbac_snapshot_027_deactivated_users (user_id INT UNSIGNED PRIMARY KEY);

ALTER TABLE roles
  ADD COLUMN user_type VARCHAR(30) NULL AFTER display_name,
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_system;

START TRANSACTION;

INSERT INTO permissions (name, module, description) VALUES
('user.manage', 'rbac', 'Create and edit user accounts, within the role ceiling.'),
('curriculum.view_unapproved', 'curriculum', 'Read versions outside the restricted read scope.'),
('curriculum.review.submit', 'curriculum', 'Submit a curriculum revision for review.'),
('curriculum.review.decide', 'curriculum', 'Approve or return a curriculum revision, with a reason.'),
('curriculum_export.draft', 'curriculum_export', 'Export versions that are not approved.'),
('curriculum_export.official', 'curriculum_export', 'Official export of approved versions.');

-- roles.name is unique: the legacy admin row becomes legacy_admin, keeping its id, grants and assignments.
UPDATE roles SET name = 'legacy_admin' WHERE name = 'admin';
UPDATE roles SET is_active = 0
WHERE name IN ('legacy_admin', 'system_administrator', 'academic_curriculum_staff',
  'department_head_curriculum_reviewer', 'faculty_academic_viewer', 'student');

INSERT INTO roles (name, display_name, user_type, description, is_system, is_active) VALUES
('admin', 'Admin', 'Admin', 'Manages Staff and Acad accounts, programs and curricula records; views audit logs.', 1, 1),
('super_admin', 'Super Admin', 'Super Admin', 'Everything, including all accounts, permissions, settings and audit logs.', 1, 1),
('staff', 'Staff', 'Staff', 'Prepares curriculum drafts and submits them for review.', 1, 1),
('acad_office', 'Acad — Office', 'Acad', 'Reviews curricula, decides equivalencies, official export.', 1, 1),
('acad_faculty', 'Acad — Faculty', 'Acad', 'Reads curricula, maps and prerequisites.', 1, 1);

-- Grants: the approved matrix. database/ProvisionalRoles.php must match (tests/rbac-final-roles.php).
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.name = 'super_admin';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON (
  (r.name = 'admin' AND p.name IN ('dashboard.view','account.view','account.update','user.manage','audit.view',
    'program.view','program.create','program.update','program_assignment.view','program_assignment.manage',
    'curriculum.view','curriculum.view_unapproved','curriculum.create','curriculum.update','curriculum.version.create',
    'subject.view','subject.create','subject.update','subject_history.view','prerequisite.view','elective.view',
    'grade_weighting.view','equivalency.view','compliance.view','curriculum_export.view','curriculum_export.official',
    'archive.view','archive.restore'))
  OR (r.name = 'staff' AND p.name IN ('dashboard.view','account.view','account.update',
    'program.view','program_assignment.view','curriculum.view','curriculum.view_unapproved','curriculum.update',
    'curriculum.version.create','curriculum.version.clone','curriculum.review.submit','curriculum.reference.attach',
    'subject.view','subject.create','subject.update','subject_history.view','prerequisite.view','prerequisite.manage',
    'elective.view','elective.manage','curriculum.outcome_mapping.manage','curriculum.clo.manage',
    'curriculum.assessment_plan.manage','grade_weighting.view','grade_weighting.manage','equivalency.view',
    'compliance.view','compliance.run','curriculum_export.view','curriculum_export.draft','archive.view','archive.restore'))
  OR (r.name = 'acad_office' AND p.name IN ('dashboard.view','account.view','account.update',
    'program.view','program_assignment.view','curriculum.view','curriculum.view_unapproved','curriculum.review.decide',
    'curriculum.reference.classify','compliance.reference.verify','subject.view','subject_history.view','prerequisite.view',
    'elective.view','grade_weighting.view','equivalency.view','equivalency.manage','compliance.view','compliance.run',
    'curriculum_export.view','curriculum_export.official'))
  OR (r.name = 'acad_faculty' AND p.name IN ('dashboard.view','account.view','account.update',
    'program.view','curriculum.view','subject.view','prerequisite.view','elective.view'))
);

-- Map accounts. A user holding several gets only the highest.
INSERT INTO user_roles (user_id, role_id, assigned_at, assigned_by)
SELECT ranked.user_id, n.id, ranked.assigned_at, ranked.assigned_by
FROM (
  SELECT ur.user_id, ur.assigned_at, ur.assigned_by,
    CASE o.name
      WHEN 'system_administrator' THEN 'super_admin'
      WHEN 'legacy_admin' THEN 'admin'
      WHEN 'academic_curriculum_staff' THEN 'staff'
      WHEN 'department_head_curriculum_reviewer' THEN 'acad_office'
      WHEN 'faculty_academic_viewer' THEN 'acad_faculty' END AS new_role,
    ROW_NUMBER() OVER (PARTITION BY ur.user_id ORDER BY FIELD(o.name, 'system_administrator', 'legacy_admin',
      'academic_curriculum_staff', 'department_head_curriculum_reviewer', 'faculty_academic_viewer')) AS position
  FROM user_roles ur JOIN roles o ON o.id = ur.role_id
  WHERE o.name IN ('system_administrator', 'legacy_admin', 'academic_curriculum_staff',
    'department_head_curriculum_reviewer', 'faculty_academic_viewer')
) ranked
JOIN roles n ON n.name = ranked.new_role
WHERE ranked.position = 1;

-- Accounts that only held the retired student role are deactivated; their data stays.
INSERT INTO rbac_snapshot_027_deactivated_users (user_id)
SELECT u.id FROM users u
WHERE u.is_active = 1
  AND EXISTS (SELECT 1 FROM rbac_snapshot_027_user_roles s WHERE s.user_id = u.id)
  AND NOT EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.is_active = 1);
UPDATE users u JOIN rbac_snapshot_027_deactivated_users d ON d.user_id = u.id SET u.is_active = 0;

INSERT INTO audit_logs (user_id, action, entity_type, entity_id, description, metadata_json)
VALUES (NULL, 'RBAC_PERMISSION_CHANGE', 'role', NULL, 'Migration 027 installed the final user types.',
  JSON_OBJECT('migration', '027_final_user_types.sql', 'source', 'migration'));

COMMIT;
-- tmp_027_guard disappears with the connection (deploy.php refuses DROP statements).
