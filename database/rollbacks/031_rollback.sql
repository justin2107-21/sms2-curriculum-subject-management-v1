-- Rollback for 031_equivalency_list_index.sql: drops the list index. No data is affected.
-- Kept outside migrations/ so the journaled runner never applies it by accident. After it run: DELETE FROM schema_migrations WHERE migration = '031_equivalency_list_index.sql';
USE sms_db;

ALTER TABLE subject_equivalencies DROP INDEX idx_subject_equivalencies_list;
