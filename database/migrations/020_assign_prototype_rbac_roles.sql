-- Idempotently install provisional CSMS roles, permission grants and prototype account links.
-- Normalized user_roles is authoritative; users.role remains legacy compatibility data.
USE sms_db;
START TRANSACTION;

INSERT INTO roles (name, display_name, description, is_system) VALUES
('system_administrator', 'System Administrator', 'Manages accounts, roles, permissions, security, audit logs, and technical system settings. Provisional; subject to client validation.', 1),
('academic_curriculum_staff', 'Academic / Curriculum Staff', 'Operational academic user for curriculum and subject management. Provisional; subject to client validation.', 1),
('department_head_curriculum_reviewer', 'Department Head / Curriculum Reviewer', 'Reviews curriculum structures, equivalencies, compliance findings, and CIMF recommendations. Provisional; subject to client validation.', 1),
('faculty_academic_viewer', 'Faculty / Academic Viewer', 'Read-only academic curriculum and subject viewer. Provisional; subject to client validation.', 1)
ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), description=VALUES(description), is_system=VALUES(is_system);

INSERT INTO permissions (name, module, description) VALUES
('dashboard.view', 'dashboard', 'Provisional CSMS capability used for prototype development and evaluation.'),
('account.view', 'account', 'Provisional CSMS capability used for prototype development and evaluation.'),
('account.update', 'account', 'Provisional CSMS capability used for prototype development and evaluation.'),
('program.view', 'program', 'Provisional CSMS capability used for prototype development and evaluation.'),
('curriculum.view', 'curriculum', 'Provisional CSMS capability used for prototype development and evaluation.'),
('subject.view', 'subject', 'Provisional CSMS capability used for prototype development and evaluation.'),
('prerequisite.view', 'prerequisite', 'Provisional CSMS capability used for prototype development and evaluation.'),
('elective.view', 'elective', 'Provisional CSMS capability used for prototype development and evaluation.'),
('program_assignment.view', 'program_assignment', 'Provisional CSMS capability used for prototype development and evaluation.'),
('subject_history.view', 'subject_history', 'Provisional CSMS capability used for prototype development and evaluation.'),
('equivalency.view', 'equivalency', 'Provisional CSMS capability used for prototype development and evaluation.'),
('grade_weighting.view', 'grade_weighting', 'Provisional CSMS capability used for prototype development and evaluation.'),
('compliance.view', 'compliance', 'Provisional CSMS capability used for prototype development and evaluation.'),
('curriculum_export.view', 'curriculum_export', 'Provisional CSMS capability used for prototype development and evaluation.'),
('program.create', 'program', 'Provisional CSMS capability used for prototype development and evaluation.'),
('program.update', 'program', 'Provisional CSMS capability used for prototype development and evaluation.'),
('curriculum.create', 'curriculum', 'Provisional CSMS capability used for prototype development and evaluation.'),
('curriculum.update', 'curriculum', 'Provisional CSMS capability used for prototype development and evaluation.'),
('curriculum.version.create', 'curriculum', 'Provisional CSMS capability used for prototype development and evaluation.'),
('subject.create', 'subject', 'Provisional CSMS capability used for prototype development and evaluation.'),
('subject.update', 'subject', 'Provisional CSMS capability used for prototype development and evaluation.'),
('prerequisite.manage', 'prerequisite', 'Provisional CSMS capability used for prototype development and evaluation.'),
('elective.manage', 'elective', 'Provisional CSMS capability used for prototype development and evaluation.'),
('program_assignment.manage', 'program_assignment', 'Provisional CSMS capability used for prototype development and evaluation.'),
('grade_weighting.manage', 'grade_weighting', 'Provisional CSMS capability used for prototype development and evaluation.'),
('compliance.run', 'compliance', 'Provisional CSMS capability used for prototype development and evaluation.'),
('compliance.manage', 'compliance', 'Provisional CSMS capability used for prototype development and evaluation.'),
('equivalency.manage', 'equivalency', 'Provisional CSMS capability used for prototype development and evaluation.'),
('archive.view', 'archive', 'Provisional CSMS capability used for prototype development and evaluation.'),
('archive.restore', 'archive', 'Provisional CSMS capability used for prototype development and evaluation.'),
('rbac.manage', 'rbac', 'Provisional CSMS capability used for prototype development and evaluation.'),
('audit.view', 'audit', 'Provisional CSMS capability used for prototype development and evaluation.'),
('settings.view', 'settings', 'Provisional CSMS capability used for prototype development and evaluation.')
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON (
    (r.name = 'system_administrator' AND p.name IN ('dashboard.view', 'account.view', 'account.update', 'program.view', 'curriculum.view', 'subject.view', 'prerequisite.view', 'elective.view', 'program_assignment.view', 'subject_history.view', 'equivalency.view', 'grade_weighting.view', 'compliance.view', 'curriculum_export.view', 'program.create', 'program.update', 'curriculum.create', 'curriculum.update', 'curriculum.version.create', 'subject.create', 'subject.update', 'prerequisite.manage', 'elective.manage', 'program_assignment.manage', 'grade_weighting.manage', 'compliance.run', 'compliance.manage', 'equivalency.manage', 'archive.view', 'archive.restore', 'rbac.manage', 'audit.view', 'settings.view'))
    OR     (r.name = 'academic_curriculum_staff' AND p.name IN ('dashboard.view', 'account.view', 'account.update', 'program.view', 'curriculum.view', 'subject.view', 'prerequisite.view', 'elective.view', 'program_assignment.view', 'subject_history.view', 'equivalency.view', 'grade_weighting.view', 'compliance.view', 'curriculum_export.view', 'program.create', 'program.update', 'curriculum.create', 'curriculum.update', 'curriculum.version.create', 'subject.create', 'subject.update', 'prerequisite.manage', 'elective.manage', 'program_assignment.manage', 'grade_weighting.manage', 'compliance.run', 'archive.view', 'archive.restore'))
    OR     (r.name = 'department_head_curriculum_reviewer' AND p.name IN ('dashboard.view', 'account.view', 'account.update', 'program.view', 'curriculum.view', 'subject.view', 'prerequisite.view', 'elective.view', 'program_assignment.view', 'subject_history.view', 'equivalency.view', 'grade_weighting.view', 'compliance.view', 'curriculum_export.view', 'equivalency.manage', 'compliance.run'))
    OR     (r.name = 'faculty_academic_viewer' AND p.name IN ('dashboard.view', 'account.view', 'account.update', 'program.view', 'curriculum.view', 'subject.view', 'prerequisite.view', 'elective.view', 'program_assignment.view', 'subject_history.view', 'equivalency.view', 'grade_weighting.view', 'compliance.view', 'curriculum_export.view'))
);

INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT u.id, r.id FROM users u JOIN roles r ON CASE u.username
    WHEN 'csms_admin' THEN 'system_administrator'
    WHEN 'academic_staff' THEN 'academic_curriculum_staff'
    WHEN 'curriculum_reviewer' THEN 'department_head_curriculum_reviewer'
    WHEN 'faculty_viewer' THEN 'faculty_academic_viewer'
    ELSE NULL END = r.name
WHERE u.username IN ('csms_admin', 'academic_staff', 'curriculum_reviewer', 'faculty_viewer');

INSERT INTO audit_logs (user_id, action, entity_type, entity_id, description, metadata_json)
SELECT NULL, 'RBAC_PERMISSION_CHANGE', 'role', NULL,
       'Migration 020 ensured provisional CSMS role grants and prototype assignments.',
       JSON_OBJECT('migration', '020_assign_prototype_rbac_roles.sql', 'source', 'migration')
WHERE NOT EXISTS (
    SELECT 1 FROM audit_logs
    WHERE action = 'RBAC_PERMISSION_CHANGE'
      AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.migration')) = '020_assign_prototype_rbac_roles.sql'
);

COMMIT;
