-- Extend the existing version-scoped groups; preserve memberships and placement FKs.
-- Legacy placement is deliberately left unset for review rather than guessed.
USE sms_db;

ALTER TABLE curriculum_elective_groups
    ADD COLUMN year_level VARCHAR(50) NULL AFTER description,
    ADD COLUMN semester VARCHAR(50) NULL AFTER year_level,
    ADD COLUMN required_selections INT NOT NULL DEFAULT 1 AFTER semester,
    ADD COLUMN status ENUM('active', 'inactive', 'archived') NOT NULL DEFAULT 'active' AFTER required_selections,
    ADD INDEX idx_elective_version_status (curriculum_version_id, status);
