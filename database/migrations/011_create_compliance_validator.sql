-- Sprint 1 Phase 5H: deterministic, configurable compliance validation foundation.
-- Run exactly once after 010_create_grade_weighting_setup.sql.
--
-- No regulatory reference or threshold is seeded here. "Verified" records
-- represent internal confirmation of an intended source, not official approval.

USE sms_db;

CREATE TABLE compliance_references (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference_type      VARCHAR(30) NOT NULL,
    reference_code      VARCHAR(120) NULL,
    title               VARCHAR(255) NULL,
    issuing_body        VARCHAR(180) NOT NULL,
    source_url          VARCHAR(1000) NULL,
    effective_date      DATE NULL,
    notes               TEXT NULL,
    verification_status VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_by          INT UNSIGNED NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_compliance_reference_code (reference_type, reference_code),
    INDEX idx_compliance_references_status (verification_status),
    INDEX idx_compliance_references_type (reference_type),
    INDEX idx_compliance_references_created_by (created_by),
    CONSTRAINT chk_compliance_reference_type
        CHECK (reference_type IN ('CHED', 'DEPED', 'INSTITUTIONAL', 'OTHER')),
    CONSTRAINT chk_compliance_reference_identifier
        CHECK (NULLIF(TRIM(reference_code), '') IS NOT NULL
               OR NULLIF(TRIM(title), '') IS NOT NULL),
    CONSTRAINT chk_compliance_reference_status
        CHECK (verification_status IN ('draft', 'verified', 'inactive')),
    CONSTRAINT fk_compliance_references_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE compliance_rules (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    compliance_reference_id BIGINT UNSIGNED NOT NULL,
    rule_name               VARCHAR(180) NOT NULL,
    rule_type               VARCHAR(40) NOT NULL,
    description             TEXT NULL,
    numeric_value           DECIMAL(10,2) NULL,
    subject_id              BIGINT UNSIGNED NULL,
    academic_program_id     BIGINT UNSIGNED NULL,
    curriculum_id           BIGINT UNSIGNED NULL,
    technical_status        VARCHAR(20) NOT NULL DEFAULT 'inactive',
    created_by              INT UNSIGNED NULL,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_compliance_rules_reference (compliance_reference_id),
    INDEX idx_compliance_rules_program_status (academic_program_id, technical_status),
    INDEX idx_compliance_rules_curriculum_status (curriculum_id, technical_status),
    INDEX idx_compliance_rules_subject (subject_id),
    INDEX idx_compliance_rules_created_by (created_by),
    CONSTRAINT chk_compliance_rule_type
        CHECK (rule_type IN (
            'MIN_TOTAL_UNITS', 'MAX_TOTAL_UNITS', 'REQUIRED_SUBJECT',
            'MIN_SUBJECT_UNITS', 'MAX_SUBJECT_UNITS'
        )),
    CONSTRAINT chk_compliance_rule_status
        CHECK (technical_status IN ('active', 'inactive')),
    CONSTRAINT chk_compliance_rule_scope
        CHECK ((academic_program_id IS NOT NULL AND curriculum_id IS NULL)
               OR (academic_program_id IS NULL AND curriculum_id IS NOT NULL)),
    CONSTRAINT chk_compliance_rule_parameters
        CHECK (
            (rule_type IN ('MIN_TOTAL_UNITS', 'MAX_TOTAL_UNITS')
             AND numeric_value IS NOT NULL AND numeric_value >= 0 AND subject_id IS NULL)
            OR
            (rule_type = 'REQUIRED_SUBJECT'
             AND numeric_value IS NULL AND subject_id IS NOT NULL)
            OR
            (rule_type IN ('MIN_SUBJECT_UNITS', 'MAX_SUBJECT_UNITS')
             AND numeric_value IS NOT NULL AND numeric_value >= 0 AND subject_id IS NOT NULL)
        ),
    CONSTRAINT fk_compliance_rules_reference
        FOREIGN KEY (compliance_reference_id) REFERENCES compliance_references (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_compliance_rules_subject
        FOREIGN KEY (subject_id) REFERENCES subjects (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_compliance_rules_program
        FOREIGN KEY (academic_program_id) REFERENCES academic_programs (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_compliance_rules_curriculum
        FOREIGN KEY (curriculum_id) REFERENCES curricula (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_compliance_rules_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE compliance_validation_runs (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    curriculum_version_id BIGINT UNSIGNED NOT NULL,
    rules_evaluated       INT UNSIGNED NOT NULL DEFAULT 0,
    passed_count          INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count          INT UNSIGNED NOT NULL DEFAULT 0,
    not_evaluated_count   INT UNSIGNED NOT NULL DEFAULT 0,
    run_by                INT UNSIGNED NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_compliance_runs_version_date (curriculum_version_id, created_at),
    INDEX idx_compliance_runs_run_by (run_by),
    INDEX idx_compliance_runs_created_at (created_at),
    CONSTRAINT fk_compliance_runs_version
        FOREIGN KEY (curriculum_version_id) REFERENCES curriculum_versions (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_compliance_runs_run_by
        FOREIGN KEY (run_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE compliance_validation_results (
    id                            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    validation_run_id             BIGINT UNSIGNED NOT NULL,
    compliance_rule_id            BIGINT UNSIGNED NOT NULL,
    rule_name_snapshot            VARCHAR(180) NOT NULL,
    rule_type_snapshot            VARCHAR(40) NOT NULL,
    reference_identifier_snapshot VARCHAR(600) NOT NULL,
    reference_url_snapshot        VARCHAR(1000) NULL,
    requirement_snapshot          VARCHAR(500) NOT NULL,
    actual_value_snapshot         VARCHAR(255) NULL,
    result_status                 VARCHAR(20) NOT NULL,
    explanation                   TEXT NOT NULL,
    created_at                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_compliance_run_rule (validation_run_id, compliance_rule_id),
    INDEX idx_compliance_results_rule (compliance_rule_id),
    INDEX idx_compliance_results_status (result_status),
    CONSTRAINT chk_compliance_result_status
        CHECK (result_status IN ('PASS', 'FAIL', 'NOT_EVALUATED')),
    CONSTRAINT fk_compliance_results_run
        FOREIGN KEY (validation_run_id) REFERENCES compliance_validation_runs (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_compliance_results_rule
        FOREIGN KEY (compliance_rule_id) REFERENCES compliance_rules (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

-- Temporary development capabilities only. Final BCP responsibility and
-- reference-verification roles require client confirmation.
INSERT INTO permissions (name, module, description) VALUES
    ('compliance.view', 'compliance',
     'View deterministic compliance configuration, run validation, and view history.'),
    ('compliance.manage', 'compliance',
     'Create and maintain compliance references and deterministic rules.');

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
INNER JOIN permissions ON permissions.name IN (
    'compliance.view',
    'compliance.manage'
)
WHERE roles.name = 'admin';

COMMIT;
