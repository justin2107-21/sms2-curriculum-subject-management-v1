-- Rollback for 032_compliance_rule_library.sql: drops the two library tables (library rules and the results stored for them).
-- Kept outside migrations/ so the journaled runner never applies it by accident. After it run: DELETE FROM schema_migrations WHERE migration = '032_compliance_rule_library.sql';
-- Validation runs and their counts stay; only the per-rule library rows are lost.
USE sms_db;

DROP TABLE IF EXISTS compliance_library_results;
DROP TABLE IF EXISTS compliance_library_rules;
