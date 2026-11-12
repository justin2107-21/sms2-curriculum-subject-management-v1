-- Subject Equivalency Tool: one index for the server-side list (default sort by Reviewed at, date filter, reason filter).
-- Measured on a disposable clone with 50,000 equivalency records: default list 111 ms -> 50 ms, date filter 176 ms -> 39 ms,
-- "missing reason" 194 ms -> 95 ms, Reviewed at ascending 114 ms -> 34 ms. The list works without it (all measured requests
-- were already under about 450 ms), so this migration is optional and can wait until the table is large.
-- Nothing else changes: no column, no data, no permission. 030 is reserved for Electives.
-- Apply only through the guarded, journaled migration process (database/deploy.php), after a backup.
-- Rollback: database/rollbacks/031_rollback.sql (drops the index only).
USE sms_db;

ALTER TABLE subject_equivalencies
    ADD INDEX idx_subject_equivalencies_list (archived_at, reviewed_at, id);
