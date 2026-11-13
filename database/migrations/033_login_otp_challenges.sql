-- Optional Email OTP at sign-in: one row per emailed code (challenge).
-- Only a salted hash of the 6-digit code is stored, never the code. Times are UTC (written by the application).
-- A new code marks the older ones used. attempts counts wrong entries (5 allowed). The table is empty unless OTP_ENABLED=1.
-- Nothing here touches curriculum, subject, placement, role or permission tables.
-- Apply only through the guarded, journaled migration process (database/deploy.php). Rollback: database/rollbacks/033_rollback.sql.
USE sms_db;

CREATE TABLE IF NOT EXISTS login_otp_challenges (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  code_hash VARCHAR(128) NOT NULL,
  expires_at DATETIME NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  used_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_login_otp_user_created (user_id, created_at),
  CONSTRAINT fk_login_otp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
