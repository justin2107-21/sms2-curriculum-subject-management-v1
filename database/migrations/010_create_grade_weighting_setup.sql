-- Sprint 1 Phase 5G: placement-scoped Grade Weighting Setup foundation.
-- Run exactly once after 009_create_subject_equivalencies.sql.
--
-- These tables store configuration only. They do not store scores, compute
-- grades, implement transmutation, or define institutional grading policy.

USE sms_db;

CREATE TABLE grade_weighting_schemes (
    id                            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    curriculum_version_subject_id BIGINT UNSIGNED NOT NULL,
    description                   TEXT NULL,
    created_by                    INT UNSIGNED NULL,
    created_at                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_grade_weighting_scheme_placement
        UNIQUE (curriculum_version_subject_id),
    INDEX idx_grade_weighting_schemes_created_by (created_by),
    CONSTRAINT fk_grade_weighting_schemes_placement
        FOREIGN KEY (curriculum_version_subject_id)
        REFERENCES curriculum_version_subjects (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_grade_weighting_schemes_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE grade_weighting_components (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scheme_id         BIGINT UNSIGNED NOT NULL,
    component_name    VARCHAR(150) NOT NULL,
    weight_percentage DECIMAL(5,2) NOT NULL,
    sequence_order    INT UNSIGNED NULL,
    created_by        INT UNSIGNED NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_grade_weighting_component_name
        UNIQUE (scheme_id, component_name),
    INDEX idx_grade_weighting_components_scheme_order
        (scheme_id, sequence_order),
    INDEX idx_grade_weighting_components_created_by (created_by),
    CONSTRAINT chk_grade_weighting_component_percentage
        CHECK (weight_percentage > 0.00 AND weight_percentage <= 100.00),
    CONSTRAINT fk_grade_weighting_components_scheme
        FOREIGN KEY (scheme_id) REFERENCES grade_weighting_schemes (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_grade_weighting_components_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

-- Temporary development capabilities only. Final BCP role assignments must
-- be based on validated client requirements.
INSERT INTO permissions (name, module, description) VALUES
    ('grade_weighting.view', 'grade_weighting',
     'View placement-scoped grade weighting configuration.'),
    ('grade_weighting.manage', 'grade_weighting',
     'Create and manage placement-scoped grade weighting configuration.');

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
INNER JOIN permissions ON permissions.name IN (
    'grade_weighting.view',
    'grade_weighting.manage'
)
WHERE roles.name = 'admin';

COMMIT;
