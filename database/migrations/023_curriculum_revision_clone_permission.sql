-- Dedicated capability; deliberate RBAC assignment only, no default grants.
USE sms_db;
INSERT INTO permissions (name, module, description) VALUES
 ('curriculum.version.clone', 'curriculum', 'Create an editable revision from an eligible protected reference.');
