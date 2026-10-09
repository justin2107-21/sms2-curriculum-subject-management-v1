-- Phase 1 / Task 3: source provenance and complete reference-capture support.
-- Existing values are retained. Legacy source/snapshot rows stay explicitly unknown/incomplete.
-- No source is verified and no curriculum is classified by this migration.
USE sms_db;

ALTER TABLE compliance_references
  ADD COLUMN source_kind VARCHAR(40) NOT NULL DEFAULT 'unknown' AFTER reference_type,
  ADD COLUMN supersedes_reference_id BIGINT UNSIGNED NULL AFTER source_kind,
  ADD INDEX idx_compliance_reference_supersedes (supersedes_reference_id),
  ADD CONSTRAINT chk_compliance_reference_source_kind CHECK (
    source_kind IN ('regulatory_document', 'ched_sample_curriculum',
      'institutional_curriculum', 'institutional_policy', 'supporting_document',
      'other_document', 'unknown')
  ),
  ADD CONSTRAINT fk_compliance_reference_supersedes
    FOREIGN KEY (supersedes_reference_id) REFERENCES compliance_references (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT;

CREATE TABLE compliance_reference_evidence_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  compliance_reference_id BIGINT UNSIGNED NOT NULL,
  verification_subject VARCHAR(30) NOT NULL,
  result VARCHAR(24) NOT NULL,
  reference_code_snapshot VARCHAR(120) NULL,
  title_snapshot VARCHAR(255) NULL,
  source_kind_snapshot VARCHAR(40) NOT NULL,
  issuing_body_snapshot VARCHAR(180) NOT NULL,
  source_url_snapshot VARCHAR(1000) NULL,
  effective_date_snapshot DATE NULL,
  evidence_locator VARCHAR(1000) NULL,
  evidence_sha256 CHAR(64) NULL,
  rationale TEXT NOT NULL,
  recorded_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cree_reference_subject (compliance_reference_id, verification_subject, id),
  INDEX idx_cree_recorded_by (recorded_by),
  CONSTRAINT chk_cree_subject CHECK (
    verification_subject IN ('document_identity', 'declared_origin', 'applicability')
  ),
  CONSTRAINT chk_cree_result CHECK (
    result IN ('confirmed', 'not_confirmed', 'not_assessed')
  ),
  CONSTRAINT chk_cree_source_kind CHECK (
    source_kind_snapshot IN ('regulatory_document', 'ched_sample_curriculum',
      'institutional_curriculum', 'institutional_policy', 'supporting_document',
      'other_document', 'unknown')
  ),
  CONSTRAINT fk_cree_reference FOREIGN KEY (compliance_reference_id)
    REFERENCES compliance_references (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_cree_recorder FOREIGN KEY (recorded_by)
    REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE curriculum_version_references
  ADD COLUMN source_kind_snapshot VARCHAR(40) NOT NULL DEFAULT 'unknown' AFTER reference_title_snapshot,
  ADD COLUMN issuing_body_snapshot VARCHAR(180) NULL AFTER source_kind_snapshot,
  ADD COLUMN effective_date_snapshot DATE NULL AFTER issuing_body_snapshot,
  ADD CONSTRAINT chk_cvr_source_kind_snapshot CHECK (
    source_kind_snapshot IN ('regulatory_document', 'ched_sample_curriculum',
      'institutional_curriculum', 'institutional_policy', 'supporting_document',
      'other_document', 'unknown')
  );

ALTER TABLE curriculum_version_subject_snapshots
  ADD COLUMN lecture_units_snapshot DECIMAL(6,2) NULL AFTER units_snapshot,
  ADD COLUMN laboratory_units_snapshot DECIMAL(6,2) NULL AFTER lecture_units_snapshot,
  ADD COLUMN source_reference_id BIGINT UNSIGNED NULL AFTER capture_method,
  ADD COLUMN snapshot_complete TINYINT(1) NOT NULL DEFAULT 0 AFTER source_reference_id,
  ADD INDEX idx_cvss_source_reference (source_reference_id),
  ADD CONSTRAINT chk_cvss_lecture_units CHECK (lecture_units_snapshot IS NULL OR lecture_units_snapshot >= 0),
  ADD CONSTRAINT chk_cvss_laboratory_units CHECK (laboratory_units_snapshot IS NULL OR laboratory_units_snapshot >= 0),
  ADD CONSTRAINT chk_cvss_complete CHECK (snapshot_complete IN (0, 1)),
  ADD CONSTRAINT fk_cvss_source_reference FOREIGN KEY (source_reference_id)
    REFERENCES compliance_references (id) ON UPDATE RESTRICT ON DELETE RESTRICT;

-- New capabilities are registered but intentionally have no role grants.
INSERT INTO permissions (name, module, description) VALUES
  ('curriculum.reference.attach', 'curriculum', 'Attach documentary provenance to an eligible curriculum version.'),
  ('compliance.reference.verify', 'compliance', 'Record evidence-based internal source verification.'),
  ('curriculum.reference.classify', 'curriculum', 'Classify an eligible curriculum version as a protected reference.');
