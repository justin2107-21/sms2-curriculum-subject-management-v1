-- Usage-only CIMF diagnostics. The deployment runner removes this schema selector.
USE sms_db;

CREATE TABLE cimf_ai_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    curriculum_version_id BIGINT UNSIGNED NULL,
    action_name VARCHAR(50) NOT NULL,
    status_name VARCHAR(40) NOT NULL,
    http_status SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cimf_ai_logs_created (created_at),
    INDEX idx_cimf_ai_logs_version (curriculum_version_id),
    CONSTRAINT fk_cimf_ai_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_cimf_ai_logs_version FOREIGN KEY (curriculum_version_id) REFERENCES curriculum_versions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
