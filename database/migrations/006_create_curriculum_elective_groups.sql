-- Sprint 1 Phase 5C: curriculum-version elective-group foundation.
-- Run exactly once after 005_create_curriculum_subject_requirements.sql.
-- These tables configure curriculum structure only. They do not define
-- selection quotas, enrollment behavior, class capacity, or institutional
-- elective policy.

USE sms_db;

CREATE TABLE curriculum_elective_groups (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    curriculum_version_id BIGINT UNSIGNED NOT NULL,
    group_name            VARCHAR(150) NOT NULL,
    description           TEXT NULL,
    created_by            INT UNSIGNED NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_curriculum_elective_group_name
        UNIQUE (curriculum_version_id, group_name),
    CONSTRAINT uq_curriculum_elective_group_version
        UNIQUE (id, curriculum_version_id),
    INDEX idx_curriculum_elective_groups_created_by (created_by),
    CONSTRAINT fk_curriculum_elective_groups_version
        FOREIGN KEY (curriculum_version_id) REFERENCES curriculum_versions (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_curriculum_elective_groups_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curriculum_elective_group_subjects (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    elective_group_id     BIGINT UNSIGNED NOT NULL,
    curriculum_version_id BIGINT UNSIGNED NOT NULL,
    subject_id            BIGINT UNSIGNED NOT NULL,
    created_by            INT UNSIGNED NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_curriculum_elective_group_subject
        UNIQUE (elective_group_id, subject_id),
    INDEX idx_curriculum_elective_group_subjects_group
        (elective_group_id),
    INDEX idx_curriculum_elective_group_subjects_placement
        (curriculum_version_id, subject_id),
    INDEX idx_curriculum_elective_group_subjects_created_by
        (created_by),
    CONSTRAINT fk_curriculum_elective_group_subjects_group_version
        FOREIGN KEY (elective_group_id, curriculum_version_id)
        REFERENCES curriculum_elective_groups (id, curriculum_version_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_curriculum_elective_group_subjects_placement
        FOREIGN KEY (curriculum_version_id, subject_id)
        REFERENCES curriculum_version_subjects (curriculum_version_id, subject_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_curriculum_elective_group_subjects_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

-- Temporary development capabilities only. Final BCP roles and assignments
-- must be based on validated client requirements.
INSERT INTO permissions (name, module, description) VALUES
    ('elective.view', 'elective', 'View curriculum-version elective groups and memberships.'),
    ('elective.manage', 'elective', 'Create, update, and safely remove elective groups and memberships.');

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
INNER JOIN permissions ON permissions.name IN (
    'elective.view',
    'elective.manage'
)
WHERE roles.name = 'admin';

COMMIT;
