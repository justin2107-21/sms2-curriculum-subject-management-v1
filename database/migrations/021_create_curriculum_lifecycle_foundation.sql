-- Phase 1 / Task 1: additive curriculum lifecycle data foundation.
-- Schema only: no curriculum reclassification, reference seeding, permission grants,
-- account changes, snapshots or review events are created by this migration.
-- Apply only through the existing guarded, journaled migration process.
USE sms_db;

ALTER TABLE curriculum_versions
  ADD COLUMN baseline_version_id BIGINT UNSIGNED NULL,
  ADD COLUMN version_role VARCHAR(20) NOT NULL DEFAULT 'unclassified',
  ADD COLUMN review_status VARCHAR(24) NOT NULL DEFAULT 'not_started',
  ADD UNIQUE KEY uq_cv_id_curriculum (id, curriculum_id),
  ADD INDEX idx_cv_baseline_curriculum (baseline_version_id, curriculum_id),
  ADD CONSTRAINT chk_cv_version_role
    CHECK (version_role IN ('unclassified', 'reference', 'proposed')),
  ADD CONSTRAINT chk_cv_review_status
    CHECK (review_status IN ('not_started', 'draft', 'under_review', 'returned', 'approved')),
  ADD CONSTRAINT chk_cv_role_baseline
    CHECK (
      (version_role = 'proposed' AND baseline_version_id IS NOT NULL)
      OR (version_role IN ('unclassified', 'reference') AND baseline_version_id IS NULL)
    );

-- MariaDB 10.4 needs the referenced composite index to exist before adding
-- a self-referencing FK; create it in the preceding ALTER statement.
ALTER TABLE curriculum_versions
  ADD CONSTRAINT fk_cv_baseline_same_curriculum
    FOREIGN KEY (baseline_version_id, curriculum_id)
    REFERENCES curriculum_versions (id, curriculum_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT;

-- Deliberately no CHECK (baseline_version_id <> id): MySQL and MariaDB
-- prohibit CHECK expressions that reference AUTO_INCREMENT columns (id).
-- Task 2 must reject self-baselines in the transactional application service.
-- The composite FK above independently enforces baseline existence and
-- same-curriculum scope at the database level.

CREATE TABLE curriculum_version_references (
  curriculum_version_id BIGINT UNSIGNED NOT NULL,
  compliance_reference_id BIGINT UNSIGNED NOT NULL,
  reference_purpose VARCHAR(30) NOT NULL,
  reference_code_snapshot VARCHAR(120) NULL,
  reference_title_snapshot VARCHAR(255) NULL,
  reference_url_snapshot VARCHAR(1000) NULL,
  verification_status_snapshot VARCHAR(20) NOT NULL,
  linked_by INT UNSIGNED NULL,
  linked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (curriculum_version_id, compliance_reference_id, reference_purpose),
  INDEX idx_cvr_reference_id (compliance_reference_id),
  INDEX idx_cvr_linked_by (linked_by),
  CONSTRAINT chk_cvr_purpose CHECK (
    reference_purpose IN ('school_curriculum', 'regulatory', 'institutional_policy', 'other')
  ),
  CONSTRAINT chk_cvr_verification CHECK (
    verification_status_snapshot IN ('draft', 'verified', 'inactive')
  ),
  CONSTRAINT fk_cvr_version FOREIGN KEY (curriculum_version_id)
    REFERENCES curriculum_versions (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvr_reference FOREIGN KEY (compliance_reference_id)
    REFERENCES compliance_references (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvr_linked_by FOREIGN KEY (linked_by)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curriculum_version_personnel (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  curriculum_version_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  responsibility VARCHAR(30) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  assigned_by INT UNSIGNED NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cvp_version_user_responsibility
    (curriculum_version_id, user_id, responsibility),
  INDEX idx_cvp_user_id (user_id),
  INDEX idx_cvp_assigned_by (assigned_by),
  CONSTRAINT chk_cvp_responsibility CHECK (
    responsibility IN ('preparer', 'reviewer', 'approver')
  ),
  CONSTRAINT chk_cvp_active CHECK (is_active IN (0, 1)),
  CONSTRAINT fk_cvp_version FOREIGN KEY (curriculum_version_id)
    REFERENCES curriculum_versions (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvp_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvp_assigned_by FOREIGN KEY (assigned_by)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curriculum_version_review_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  curriculum_version_id BIGINT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  action VARCHAR(30) NOT NULL,
  previous_status VARCHAR(24) NOT NULL,
  new_status VARCHAR(24) NOT NULL,
  comments TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cvre_version_date (curriculum_version_id, created_at),
  INDEX idx_cvre_actor_user (actor_user_id),
  CONSTRAINT chk_cvre_action
    CHECK (action IN ('submit', 'return', 'approve', 'comment')),
  CONSTRAINT chk_cvre_previous CHECK (
    previous_status IN ('not_started', 'draft', 'under_review', 'returned', 'approved')
  ),
  CONSTRAINT chk_cvre_new CHECK (
    new_status IN ('not_started', 'draft', 'under_review', 'returned', 'approved')
  ),
  CONSTRAINT fk_cvre_version FOREIGN KEY (curriculum_version_id)
    REFERENCES curriculum_versions (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cvre_actor FOREIGN KEY (actor_user_id)
    REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curriculum_version_subject_snapshots (
  curriculum_version_subject_id BIGINT UNSIGNED PRIMARY KEY,
  subject_code_snapshot VARCHAR(60) NOT NULL,
  subject_title_snapshot VARCHAR(255) NOT NULL,
  units_snapshot DECIMAL(6,2) NOT NULL,
  description_snapshot TEXT NULL,
  captured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  capture_method VARCHAR(24) NOT NULL,
  CONSTRAINT chk_cvss_units CHECK (units_snapshot >= 0),
  CONSTRAINT chk_cvss_method CHECK (
    capture_method IN ('reference_capture', 'clone', 'mapping_capture')
  ),
  CONSTRAINT fk_cvss_placement FOREIGN KEY (curriculum_version_subject_id)
    REFERENCES curriculum_version_subjects (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
