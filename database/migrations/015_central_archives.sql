-- Archive metadata stays with each authoritative record. Inactive records remain distinct.
USE sms_db;

ALTER TABLE academic_programs
    ADD COLUMN archived_at DATETIME NULL,
    ADD COLUMN archived_by INT UNSIGNED NULL,
    ADD COLUMN archive_previous_status VARCHAR(20) NULL,
    ADD INDEX idx_program_archive (archived_at, archived_by);
ALTER TABLE curricula
    ADD COLUMN archived_at DATETIME NULL,
    ADD COLUMN archived_by INT UNSIGNED NULL,
    ADD COLUMN archive_previous_status VARCHAR(20) NULL,
    ADD INDEX idx_curriculum_archive (archived_at, archived_by);
ALTER TABLE subjects
    ADD COLUMN archived_at DATETIME NULL,
    ADD COLUMN archived_by INT UNSIGNED NULL,
    ADD COLUMN archive_previous_status VARCHAR(20) NULL,
    ADD INDEX idx_subject_archive (archived_at, archived_by);
ALTER TABLE curriculum_elective_groups
    ADD COLUMN archived_at DATETIME NULL,
    ADD COLUMN archived_by INT UNSIGNED NULL,
    ADD COLUMN archive_previous_status VARCHAR(20) NULL,
    ADD INDEX idx_elective_archive (status, archived_at, archived_by);

INSERT INTO permissions (name, module, description) VALUES
    ('archive.view', 'archive', 'View archived curriculum records.'),
    ('archive.restore', 'archive', 'Restore archived curriculum records.')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN ('archive.view', 'archive.restore')
WHERE r.name = 'admin'
ON DUPLICATE KEY UPDATE permission_id = VALUES(permission_id);
