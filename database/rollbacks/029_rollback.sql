-- Reverses migration 029. Take a backup first. Reported offerings, API clients and the rejected log are deleted with their tables.
-- Run against one chosen database: mysql --default-character-set=utf8mb4 -u root <database> < 029_rollback.sql
-- Kept outside database/migrations so deploy.php never runs it. After it, remove the 029 row from schema_migrations
-- (DELETE FROM schema_migrations WHERE migration = '029_offering_api.sql';) and revert the code that reads these tables.
START TRANSACTION;

DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE p.name = 'api_client.manage';
DELETE FROM permissions WHERE name = 'api_client.manage';

COMMIT;

-- DDL commits implicitly in MySQL/MariaDB; drop in dependency order.
DROP TABLE IF EXISTS api_rejected_items;
DROP TABLE IF EXISTS reported_offerings;
DROP TABLE IF EXISTS api_clients;
