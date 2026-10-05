-- Additive account lifecycle support; role/account data is seeded separately.
USE sms_db;
ALTER TABLE users
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0;
