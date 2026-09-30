-- Sprint 1 Phase 5F: human-reviewed Subject Equivalency foundation.
-- Run exactly once after 008_add_subject_history_permission.sql.
--
-- This table stores explicit human review decisions only. It does not infer
-- equivalence, award credit, alter curricula, or implement approval workflow.

USE sms_db;

CREATE TABLE subject_equivalencies (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_id            BIGINT UNSIGNED NOT NULL,
    equivalent_subject_id BIGINT UNSIGNED NOT NULL,
    decision_status       VARCHAR(20) NOT NULL DEFAULT 'pending',
    remarks               TEXT NULL,
    reviewed_by           INT UNSIGNED NULL,
    reviewed_at           TIMESTAMP NULL,
    created_by            INT UNSIGNED NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_subject_equivalencies_pair
        UNIQUE (subject_id, equivalent_subject_id),
    INDEX idx_subject_equivalencies_equivalent_subject (equivalent_subject_id),
    INDEX idx_subject_equivalencies_decision (decision_status),
    INDEX idx_subject_equivalencies_reviewed_by (reviewed_by),
    INDEX idx_subject_equivalencies_created_by (created_by),
    CONSTRAINT chk_subject_equivalencies_canonical_pair
        CHECK (subject_id < equivalent_subject_id),
    CONSTRAINT chk_subject_equivalencies_decision
        CHECK (decision_status IN ('pending', 'equivalent', 'not_equivalent')),
    CONSTRAINT fk_subject_equivalencies_subject
        FOREIGN KEY (subject_id) REFERENCES subjects (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_subject_equivalencies_equivalent_subject
        FOREIGN KEY (equivalent_subject_id) REFERENCES subjects (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_subject_equivalencies_reviewed_by
        FOREIGN KEY (reviewed_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_subject_equivalencies_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

-- Temporary development capabilities only. Final BCP role assignments must
-- be based on validated client requirements.
INSERT INTO permissions (name, module, description) VALUES
    ('equivalency.view', 'equivalency',
     'View subject comparisons and human-reviewed equivalency records.'),
    ('equivalency.manage', 'equivalency',
     'Create and update human-reviewed subject equivalency decisions.');

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
INNER JOIN permissions ON permissions.name IN (
    'equivalency.view',
    'equivalency.manage'
)
WHERE roles.name = 'admin';

COMMIT;
