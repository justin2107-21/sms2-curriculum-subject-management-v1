-- Task 8: placement-owned CLOs and planned assessment records.
USE sms_db;

-- Composite parent key lets the database enforce that a CLO alignment and its
-- Task 7 parent mapping belong to the same version placement.
ALTER TABLE curriculum_version_subject_outcomes
  ADD UNIQUE KEY uq_cvso_id_placement (id, curriculum_version_subject_id);

CREATE TABLE curriculum_version_course_learning_outcomes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  curriculum_version_subject_id BIGINT UNSIGNED NOT NULL,
  clo_code VARCHAR(40) NOT NULL,
  clo_statement TEXT NOT NULL,
  sequence_order SMALLINT UNSIGNED NOT NULL,
  origin ENUM('manual','inherited') NOT NULL DEFAULT 'manual',
  source_clo_id BIGINT UNSIGNED NULL,
  record_state ENUM('active','withdrawn') NOT NULL DEFAULT 'active',
  review_state ENUM('not_reviewed','awaiting_review') NOT NULL DEFAULT 'not_reviewed',
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  withdrawn_by INT UNSIGNED NULL,
  withdrawn_at TIMESTAMP NULL,
  CONSTRAINT uq_cvclo_placement_code UNIQUE (curriculum_version_subject_id, clo_code),
  CONSTRAINT uq_cvclo_id_placement UNIQUE (id, curriculum_version_subject_id),
  INDEX idx_cvclo_placement_state_order (curriculum_version_subject_id, record_state, sequence_order),
  CONSTRAINT fk_cvclo_placement FOREIGN KEY (curriculum_version_subject_id)
    REFERENCES curriculum_version_subjects (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvclo_source FOREIGN KEY (source_clo_id)
    REFERENCES curriculum_version_course_learning_outcomes (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvclo_created_by FOREIGN KEY (created_by)
    REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvclo_updated_by FOREIGN KEY (updated_by)
    REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvclo_withdrawn_by FOREIGN KEY (withdrawn_by)
    REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_cvclo_withdrawal CHECK (
    (record_state = 'active' AND withdrawn_by IS NULL AND withdrawn_at IS NULL)
    OR (record_state = 'withdrawn' AND withdrawn_by IS NOT NULL AND withdrawn_at IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curriculum_version_clo_outcome_alignments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clo_id BIGINT UNSIGNED NOT NULL,
  curriculum_version_subject_id BIGINT UNSIGNED NOT NULL,
  parent_mapping_id BIGINT UNSIGNED NULL,
  source_mapping_id_snapshot BIGINT UNSIGNED NOT NULL,
  outcome_id BIGINT UNSIGNED NOT NULL,
  rationale TEXT NOT NULL,
  origin ENUM('manual','inherited') NOT NULL DEFAULT 'manual',
  source_alignment_id BIGINT UNSIGNED NULL,
  record_state ENUM('active','withdrawn') NOT NULL DEFAULT 'active',
  review_state ENUM('not_reviewed','awaiting_review') NOT NULL DEFAULT 'not_reviewed',
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  withdrawn_by INT UNSIGNED NULL,
  withdrawn_at TIMESTAMP NULL,
  INDEX idx_cvcloa_parent_state (parent_mapping_id, record_state),
  INDEX idx_cvcloa_outcome (outcome_id),
  CONSTRAINT fk_cvcloa_clo_placement FOREIGN KEY (clo_id, curriculum_version_subject_id)
    REFERENCES curriculum_version_course_learning_outcomes (id, curriculum_version_subject_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvcloa_parent_placement FOREIGN KEY (parent_mapping_id, curriculum_version_subject_id)
    REFERENCES curriculum_version_subject_outcomes (id, curriculum_version_subject_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvcloa_outcome FOREIGN KEY (outcome_id)
    REFERENCES curriculum_outcome_catalog (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvcloa_source FOREIGN KEY (source_alignment_id)
    REFERENCES curriculum_version_clo_outcome_alignments (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvcloa_created_by FOREIGN KEY (created_by)
    REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvcloa_updated_by FOREIGN KEY (updated_by)
    REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvcloa_withdrawn_by FOREIGN KEY (withdrawn_by)
    REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_cvcloa_parent_state CHECK (
    (record_state = 'active' AND parent_mapping_id IS NOT NULL AND withdrawn_by IS NULL AND withdrawn_at IS NULL)
    OR (record_state = 'withdrawn' AND parent_mapping_id IS NULL AND withdrawn_by IS NOT NULL AND withdrawn_at IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curriculum_version_clo_assessment_plans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clo_id BIGINT UNSIGNED NOT NULL,
  curriculum_version_subject_id BIGINT UNSIGNED NOT NULL,
  assessment_method VARCHAR(180) NULL,
  planned_task TEXT NULL,
  evidence_description TEXT NULL,
  performance_indicator TEXT NULL,
  rubric_or_standard TEXT NULL,
  origin ENUM('manual','inherited') NOT NULL DEFAULT 'manual',
  source_plan_id BIGINT UNSIGNED NULL,
  record_state ENUM('active','withdrawn') NOT NULL DEFAULT 'active',
  review_state ENUM('not_reviewed','awaiting_review') NOT NULL DEFAULT 'not_reviewed',
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  withdrawn_by INT UNSIGNED NULL,
  withdrawn_at TIMESTAMP NULL,
  INDEX idx_cvclop_clo_state (clo_id, record_state),
  CONSTRAINT fk_cvclop_clo_placement FOREIGN KEY (clo_id, curriculum_version_subject_id)
    REFERENCES curriculum_version_course_learning_outcomes (id, curriculum_version_subject_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvclop_source FOREIGN KEY (source_plan_id)
    REFERENCES curriculum_version_clo_assessment_plans (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvclop_created_by FOREIGN KEY (created_by)
    REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvclop_updated_by FOREIGN KEY (updated_by)
    REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvclop_withdrawn_by FOREIGN KEY (withdrawn_by)
    REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_cvclop_withdrawal CHECK (
    (record_state = 'active' AND withdrawn_by IS NULL AND withdrawn_at IS NULL)
    OR (record_state = 'withdrawn' AND withdrawn_by IS NOT NULL AND withdrawn_at IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Explicit least-privilege capabilities. No existing role receives these by default.
INSERT INTO permissions (name, module, description) VALUES
  ('curriculum.clo.manage', 'curriculum', 'Create, edit, and withdraw version-scoped course learning outcomes and alignments.'),
  ('curriculum.assessment_plan.manage', 'curriculum', 'Create, edit, and withdraw planned assessments for course learning outcomes.');
