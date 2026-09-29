-- Sprint 1 Phase 4A: curriculum domain database foundation.
-- Run exactly once after 003_create_audit_logs.sql.
-- No legacy student data, final BCP roles, or institutional approval workflow
-- is introduced by this migration.

USE sms_db;

CREATE TABLE academic_programs (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    program_code VARCHAR(60) NOT NULL,
    program_name VARCHAR(255) NOT NULL,
    program_type VARCHAR(100) NULL,
    status       VARCHAR(20) NOT NULL DEFAULT 'active',
    created_by   INT UNSIGNED NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_academic_programs_code UNIQUE (program_code),
    INDEX idx_academic_programs_status (status),
    INDEX idx_academic_programs_created_by (created_by),
    CONSTRAINT chk_academic_programs_status CHECK (status IN ('active', 'inactive')),
    CONSTRAINT fk_academic_programs_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subjects (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_code     VARCHAR(60) NOT NULL,
    subject_title    VARCHAR(255) NOT NULL,
    description      TEXT NULL,
    units            DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    lecture_units    DECIMAL(6,2) NULL,
    laboratory_units DECIMAL(6,2) NULL,
    status           VARCHAR(20) NOT NULL DEFAULT 'active',
    created_by       INT UNSIGNED NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_subjects_code UNIQUE (subject_code),
    INDEX idx_subjects_status (status),
    INDEX idx_subjects_created_by (created_by),
    CONSTRAINT chk_subjects_units CHECK (units >= 0),
    CONSTRAINT chk_subjects_lecture_units CHECK (lecture_units IS NULL OR lecture_units >= 0),
    CONSTRAINT chk_subjects_laboratory_units CHECK (laboratory_units IS NULL OR laboratory_units >= 0),
    CONSTRAINT chk_subjects_status CHECK (status IN ('active', 'inactive')),
    CONSTRAINT fk_subjects_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curricula (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    curriculum_code     VARCHAR(80) NOT NULL,
    curriculum_name     VARCHAR(255) NOT NULL,
    academic_program_id BIGINT UNSIGNED NOT NULL,
    status              VARCHAR(20) NOT NULL DEFAULT 'active',
    created_by          INT UNSIGNED NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_curricula_code UNIQUE (curriculum_code),
    INDEX idx_curricula_academic_program_id (academic_program_id),
    INDEX idx_curricula_status (status),
    INDEX idx_curricula_created_by (created_by),
    CONSTRAINT chk_curricula_status CHECK (status IN ('active', 'inactive')),
    CONSTRAINT fk_curricula_academic_program
        FOREIGN KEY (academic_program_id) REFERENCES academic_programs (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_curricula_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curriculum_versions (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    curriculum_id           BIGINT UNSIGNED NOT NULL,
    version_label           VARCHAR(100) NOT NULL,
    effective_academic_year VARCHAR(50) NULL,
    description             TEXT NULL,
    -- These are technical record states, not official BCP approval states.
    status                  VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_by              INT UNSIGNED NULL,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_curriculum_versions_label UNIQUE (curriculum_id, version_label),
    INDEX idx_curriculum_versions_curriculum_id (curriculum_id),
    INDEX idx_curriculum_versions_status (status),
    INDEX idx_curriculum_versions_created_by (created_by),
    CONSTRAINT chk_curriculum_versions_status CHECK (status IN ('draft', 'active', 'archived')),
    CONSTRAINT fk_curriculum_versions_curriculum
        FOREIGN KEY (curriculum_id) REFERENCES curricula (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_curriculum_versions_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curriculum_version_subjects (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    curriculum_version_id BIGINT UNSIGNED NOT NULL,
    subject_id            BIGINT UNSIGNED NOT NULL,
    year_level            VARCHAR(50) NULL,
    semester              VARCHAR(50) NULL,
    sequence_order        INT UNSIGNED NULL,
    is_required           TINYINT(1) NOT NULL DEFAULT 1,
    created_by            INT UNSIGNED NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_curriculum_version_subject UNIQUE (curriculum_version_id, subject_id),
    INDEX idx_curriculum_version_subjects_version_id (curriculum_version_id),
    INDEX idx_curriculum_version_subjects_subject_id (subject_id),
    INDEX idx_curriculum_version_subjects_created_by (created_by),
    INDEX idx_curriculum_version_subjects_ordering (curriculum_version_id, year_level, semester, sequence_order),
    CONSTRAINT chk_curriculum_version_subjects_required CHECK (is_required IN (0, 1)),
    CONSTRAINT fk_curriculum_version_subjects_version
        FOREIGN KEY (curriculum_version_id) REFERENCES curriculum_versions (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_curriculum_version_subjects_subject
        FOREIGN KEY (subject_id) REFERENCES subjects (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_curriculum_version_subjects_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

-- Temporary development capabilities only. Final BCP roles and assignments
-- must be based on validated client requirements.
INSERT INTO permissions (name, module, description) VALUES
    ('program.view', 'program', 'View academic program records.'),
    ('program.create', 'program', 'Create academic program records.'),
    ('program.update', 'program', 'Update academic program records.'),
    ('subject.view', 'subject', 'View subject master records.'),
    ('subject.create', 'subject', 'Create subject master records.'),
    ('subject.update', 'subject', 'Update subject master records.'),
    ('curriculum.view', 'curriculum', 'View curricula, versions, and subject assignments.'),
    ('curriculum.create', 'curriculum', 'Create curriculum master records.'),
    ('curriculum.update', 'curriculum', 'Update curricula and version subject assignments.'),
    ('curriculum.version.create', 'curriculum', 'Create curriculum version records.');

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
INNER JOIN permissions ON permissions.name IN (
    'program.view',
    'program.create',
    'program.update',
    'subject.view',
    'subject.create',
    'subject.update',
    'curriculum.view',
    'curriculum.create',
    'curriculum.update',
    'curriculum.version.create'
)
WHERE roles.name = 'admin';

COMMIT;
