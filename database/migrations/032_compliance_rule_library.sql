-- CHED/DepEd Compliance Validator, Slice 3: rule library from public sources.
-- Two NEW tables only. compliance_rules, compliance_validation_runs and compliance_validation_results (baseline-covered) are not altered.
--   compliance_library_rules:   one row per rule taken from a recorded source (section, page, requirement, how it is computed, whether it is
--                               evaluated, displayed only or pending BCP confirmation). Library values are not edited in the application;
--                               managers can only deactivate a row.
--   compliance_library_results: one row per library rule per validation run (Met / Not met / Cannot determine / Pending BCP confirmation).
-- Nothing here is a permission change and no existing row changes. The rows themselves are seeded by scripts/compliance-seed-references.php.
-- Idempotent: every statement is guarded, so applying it twice changes nothing.
-- 030 is reserved for Electives; the Grade Weighting reference layer that was planned as 032 becomes 033.
-- Apply only through the guarded, journaled migration process (database/deploy.php), after a backup. Rollback: database/rollbacks/032_rollback.sql.
USE sms_db;

CREATE TABLE IF NOT EXISTS compliance_library_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rule_code VARCHAR(80) NOT NULL,
  compliance_reference_id BIGINT UNSIGNED NULL,
  source_label VARCHAR(255) NULL,
  section_label VARCHAR(255) NOT NULL,
  source_page VARCHAR(20) NULL,
  title VARCHAR(255) NOT NULL,
  requirement_text TEXT NOT NULL,
  applies_to_kind VARCHAR(20) NOT NULL,
  applies_to_value VARCHAR(60) NULL,
  computation_key VARCHAR(40) NOT NULL,
  parameters_json LONGTEXT NULL,
  evaluation_mode VARCHAR(20) NOT NULL,
  source_class VARCHAR(20) NOT NULL,
  valid_from DATE NULL,
  valid_to DATE NULL,
  note TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_compliance_library_rule_code (rule_code),
  KEY idx_compliance_library_reference (compliance_reference_id),
  KEY idx_compliance_library_applies (applies_to_kind, applies_to_value, active),
  CONSTRAINT fk_compliance_library_reference FOREIGN KEY (compliance_reference_id) REFERENCES compliance_references (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_compliance_library_applies CHECK (applies_to_kind IN ('all_college', 'program_code', 'senior_high')),
  CONSTRAINT chk_compliance_library_mode CHECK (evaluation_mode IN ('evaluate', 'display_only', 'pending_bcp')),
  CONSTRAINT chk_compliance_library_class CHECK (source_class IN ('public_regulation', 'client_reply')),
  CONSTRAINT chk_compliance_library_params CHECK (parameters_json IS NULL OR JSON_VALID(parameters_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compliance_library_results (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  validation_run_id BIGINT UNSIGNED NOT NULL,
  library_rule_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL,
  actual_text VARCHAR(500) NULL,
  explanation VARCHAR(1000) NOT NULL,
  missing_data_text VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_compliance_library_results_run (validation_run_id),
  KEY idx_compliance_library_results_rule (library_rule_id),
  CONSTRAINT fk_compliance_library_results_run FOREIGN KEY (validation_run_id) REFERENCES compliance_validation_runs (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_compliance_library_results_rule FOREIGN KEY (library_rule_id) REFERENCES compliance_library_rules (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_compliance_library_results_status CHECK (status IN ('PASS', 'FAIL', 'NOT_EVALUATED', 'PENDING_BCP'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
