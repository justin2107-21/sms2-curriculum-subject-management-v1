-- Subject Offering History: REST API for offerings reported by other subsystems (Class Scheduling, Enrollment, Faculty).
-- Three new tables and one permission (api_client.manage, Super Admin only). Nothing here touches curriculum, subject or placement tables.
-- No personal data columns exist. API keys are never stored: only an HMAC-SHA256 hash (the pepper is an environment value).
-- Apply only through the guarded, journaled migration process (database/deploy.php). Rollback: database/rollbacks/029_rollback.sql.
USE sms_db;

CREATE TABLE api_clients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  source_system VARCHAR(40) NOT NULL,
  key_prefix CHAR(8) NOT NULL,
  key_hash CHAR(64) NOT NULL,
  scope VARCHAR(12) NOT NULL DEFAULT 'write',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  rotated_at TIMESTAMP NULL DEFAULT NULL,
  revoked_at TIMESTAMP NULL DEFAULT NULL,
  last_used_at TIMESTAMP NULL DEFAULT NULL,
  window_start TIMESTAMP NULL DEFAULT NULL,
  window_count INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_clients_name (name),
  UNIQUE KEY uq_api_clients_source (source_system),
  UNIQUE KEY uq_api_clients_prefix (key_prefix),
  CONSTRAINT ck_api_clients_scope CHECK (scope IN ('write', 'read', 'read_write')),
  CONSTRAINT ck_api_clients_active CHECK (is_active IN (0, 1)),
  CONSTRAINT fk_api_clients_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reported_offerings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_system VARCHAR(40) NOT NULL,
  external_id VARCHAR(64) NOT NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  subject_code VARCHAR(60) NOT NULL,
  program_id BIGINT UNSIGNED NOT NULL,
  program_code VARCHAR(60) NOT NULL,
  academic_year CHAR(9) NOT NULL,
  term VARCHAR(20) NOT NULL,
  status VARCHAR(12) NOT NULL,
  section_count SMALLINT UNSIGNED NOT NULL,
  enrolled_count INT UNSIGNED NULL,
  flags VARCHAR(120) NULL,
  source_reported_at DATETIME NULL,
  received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  payload_hash CHAR(64) NOT NULL,
  api_client_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reported_offerings_external (source_system, external_id),
  KEY idx_reported_offerings_subject (subject_id, program_id, academic_year, term),
  KEY idx_reported_offerings_program (program_id),
  KEY idx_reported_offerings_client (api_client_id),
  CONSTRAINT ck_reported_offerings_status CHECK (status IN ('offered', 'cancelled')),
  CONSTRAINT fk_reported_offerings_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_reported_offerings_program FOREIGN KEY (program_id) REFERENCES academic_programs (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_reported_offerings_client FOREIGN KEY (api_client_id) REFERENCES api_clients (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rejected items: a code and the field name only. Never the payload body and never a rejected value, so a rejected personal field is not kept.
-- reported_code holds only an unknown subject or program code (a catalogue code, not personal data).
CREATE TABLE api_rejected_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  api_client_id BIGINT UNSIGNED NOT NULL,
  request_id CHAR(32) NOT NULL,
  item_index SMALLINT UNSIGNED NOT NULL,
  external_id VARCHAR(64) NULL,
  reason_code VARCHAR(40) NOT NULL,
  field_name VARCHAR(60) NULL,
  reported_code VARCHAR(60) NULL,
  payload_hash CHAR(64) NOT NULL,
  received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_api_rejected_client (api_client_id, received_at),
  KEY idx_api_rejected_request (request_id),
  CONSTRAINT fk_api_rejected_client FOREIGN KEY (api_client_id) REFERENCES api_clients (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (name, module, description) VALUES
('api_client.manage', 'api_client', 'Create, rotate and revoke API clients that report offerings to CSMS.');

-- Super Admin only. No other role receives this permission.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name = 'api_client.manage' WHERE r.name = 'super_admin';
