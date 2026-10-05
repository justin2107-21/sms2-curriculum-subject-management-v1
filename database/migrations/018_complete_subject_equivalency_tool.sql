-- Complete the human-reviewed equivalency workflow without changing prior migrations.
USE sms_db;

ALTER TABLE subject_equivalencies
    ADD COLUMN archived_at TIMESTAMP NULL AFTER updated_at,
    ADD COLUMN archived_by INT UNSIGNED NULL AFTER archived_at,
    DROP CONSTRAINT chk_subject_equivalencies_decision,
    ADD CONSTRAINT chk_subject_equivalencies_decision
        CHECK (decision_status IN ('pending', 'equivalent', 'not_equivalent', 'needs_review')),
    ADD INDEX idx_subject_equivalencies_active_decision (archived_at, decision_status),
    ADD CONSTRAINT fk_subject_equivalencies_archived_by
        FOREIGN KEY (archived_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL;

INSERT INTO permissions (name, module, description) VALUES
    ('equivalency.view', 'equivalency', 'View subject comparisons and equivalency records.'),
    ('equivalency.manage', 'equivalency', 'Create, edit, and archive human equivalency decisions.')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles CROSS JOIN permissions
WHERE roles.name = 'admin' AND permissions.name IN ('equivalency.view', 'equivalency.manage');
