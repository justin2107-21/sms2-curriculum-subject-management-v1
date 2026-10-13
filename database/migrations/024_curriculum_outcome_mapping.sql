-- Task 7: source-traceable program outcomes and version-owned manual mappings.
USE sms_db;

CREATE TABLE curriculum_outcome_catalog (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_key VARCHAR(80) NOT NULL,
  outcome_code VARCHAR(20) NOT NULL,
  outcome_text TEXT NOT NULL,
  source_order SMALLINT UNSIGNED NOT NULL,
  document_code VARCHAR(100) NOT NULL,
  document_title VARCHAR(500) NOT NULL,
  issuing_authority VARCHAR(180) NOT NULL,
  source_section VARCHAR(160) NOT NULL,
  printed_page SMALLINT UNSIGNED NOT NULL,
  pdf_page_index SMALLINT UNSIGNED NOT NULL,
  source_sha256 CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_outcome_source_code UNIQUE (source_key, outcome_code),
  CONSTRAINT uq_outcome_source_order UNIQUE (source_key, source_order),
  CONSTRAINT chk_outcome_sha256 CHECK (source_sha256 REGEXP '^[A-Fa-f0-9]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curriculum_version_subject_outcomes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  curriculum_version_subject_id BIGINT UNSIGNED NOT NULL,
  outcome_id BIGINT UNSIGNED NOT NULL,
  rationale TEXT NOT NULL,
  mapping_origin ENUM('manual','inherited') NOT NULL DEFAULT 'manual',
  inherited_from_mapping_id BIGINT UNSIGNED NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT uq_placement_outcome UNIQUE (curriculum_version_subject_id, outcome_id),
  INDEX idx_cvso_outcome (outcome_id),
  CONSTRAINT fk_cvso_placement FOREIGN KEY (curriculum_version_subject_id)
    REFERENCES curriculum_version_subjects (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvso_outcome FOREIGN KEY (outcome_id)
    REFERENCES curriculum_outcome_catalog (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvso_origin FOREIGN KEY (inherited_from_mapping_id)
    REFERENCES curriculum_version_subject_outcomes (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvso_created_by FOREIGN KEY (created_by)
    REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvso_updated_by FOREIGN KEY (updated_by)
    REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curriculum_version_outcome_coverage (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  curriculum_version_id BIGINT UNSIGNED NOT NULL,
  outcome_id BIGINT UNSIGNED NOT NULL,
  assessment_state ENUM('incomplete','complete') NOT NULL,
  rationale TEXT NULL,
  recorded_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT uq_version_outcome_coverage UNIQUE (curriculum_version_id, outcome_id),
  CONSTRAINT fk_cvoc_version FOREIGN KEY (curriculum_version_id)
    REFERENCES curriculum_versions (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvoc_outcome FOREIGN KEY (outcome_id)
    REFERENCES curriculum_outcome_catalog (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvoc_recorded_by FOREIGN KEY (recorded_by)
    REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvoc_updated_by FOREIGN KEY (updated_by)
    REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registered for explicit least-privilege assignment; deliberately no role grants.
INSERT INTO permissions (name, module, description) VALUES
  ('curriculum.outcome_mapping.manage', 'curriculum', 'Record or remove manual curriculum-version outcome mappings and coverage assessments.');
