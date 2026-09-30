-- Sprint 1 Phase 5B: version-scoped prerequisite/corequisite foundation.
-- Run exactly once after 004_create_curriculum_foundation.sql.
-- These relationships configure curriculum records only; they do not enforce
-- enrollment eligibility, waiver policies, or institutional approval rules.

USE sms_db;

CREATE TABLE curriculum_subject_requirements (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    curriculum_version_id BIGINT UNSIGNED NOT NULL,
    subject_id            BIGINT UNSIGNED NOT NULL,
    required_subject_id   BIGINT UNSIGNED NOT NULL,
    requirement_type      VARCHAR(20) NOT NULL,
    created_by            INT UNSIGNED NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_curriculum_subject_requirement
        UNIQUE (curriculum_version_id, subject_id, required_subject_id, requirement_type),
    INDEX idx_curriculum_subject_requirements_target
        (curriculum_version_id, subject_id),
    INDEX idx_curriculum_subject_requirements_required
        (curriculum_version_id, required_subject_id),
    INDEX idx_curriculum_subject_requirements_type
        (curriculum_version_id, requirement_type),
    INDEX idx_curriculum_subject_requirements_created_by (created_by),
    CONSTRAINT chk_curriculum_subject_requirements_type
        CHECK (requirement_type IN ('prerequisite', 'corequisite')),
    CONSTRAINT chk_curriculum_subject_requirements_self
        CHECK (subject_id <> required_subject_id),
    CONSTRAINT fk_curriculum_subject_requirements_target_placement
        FOREIGN KEY (curriculum_version_id, subject_id)
        REFERENCES curriculum_version_subjects (curriculum_version_id, subject_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_curriculum_subject_requirements_required_placement
        FOREIGN KEY (curriculum_version_id, required_subject_id)
        REFERENCES curriculum_version_subjects (curriculum_version_id, subject_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_curriculum_subject_requirements_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

-- Temporary development capabilities only. Final BCP roles and assignments
-- must be based on validated client requirements.
INSERT INTO permissions (name, module, description) VALUES
    ('prerequisite.view', 'prerequisite', 'View version-scoped prerequisite and corequisite relationships.'),
    ('prerequisite.manage', 'prerequisite', 'Create and remove version-scoped prerequisite and corequisite relationships.');

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
INNER JOIN permissions ON permissions.name IN (
    'prerequisite.view',
    'prerequisite.manage'
)
WHERE roles.name = 'admin';

COMMIT;
