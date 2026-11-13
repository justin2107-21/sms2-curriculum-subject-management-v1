-- Reverses migration 033. Take a backup first. Pending sign-in codes are deleted with the table (nobody is locked out: the password step still works once OTP_ENABLED is 0).
-- Run against one chosen database: mysql --default-character-set=utf8mb4 -u root <database> < 033_rollback.sql
-- Kept outside database/migrations so deploy.php never runs it. After it, remove the 033 row from schema_migrations
-- (DELETE FROM schema_migrations WHERE migration = '033_login_otp_challenges.sql';) and set OTP_ENABLED=0 or revert the code.
DROP TABLE IF EXISTS login_otp_challenges;
