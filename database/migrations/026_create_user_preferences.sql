-- Per-user interface preferences. First use: the shared working context (Program / Curriculum / Version).
USE sms_db;

CREATE TABLE user_preferences (
  user_id INT UNSIGNED NOT NULL,
  pref_key VARCHAR(64) NOT NULL,
  pref_value LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, pref_key),
  CONSTRAINT fk_user_preferences_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT ck_user_preferences_json CHECK (JSON_VALID(pref_value))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
