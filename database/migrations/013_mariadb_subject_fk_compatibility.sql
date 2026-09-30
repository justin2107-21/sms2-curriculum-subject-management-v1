-- Subject identity keys are immutable technical identifiers, not mutable labels.
-- Preserve CHECK/UNIQUE/composite integrity; do not cascade identity updates.
-- Run once after 012 in a backed-up maintenance window. Temporary guards retain
-- integrity while original FK names are replaced (MariaDB cannot DROP + ADD the
-- same name in one ALTER). DDL is not transactional: inspect a failed journal
-- entry before recovery. No CHECK, UNIQUE, records or attribution FKs change.
USE sms_db;

ALTER TABLE curriculum_subject_requirements
    ADD CONSTRAINT fk_013_requirement_target FOREIGN KEY (curriculum_version_id, subject_id)
        REFERENCES curriculum_version_subjects (curriculum_version_id, subject_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT fk_013_requirement_required FOREIGN KEY (curriculum_version_id, required_subject_id)
        REFERENCES curriculum_version_subjects (curriculum_version_id, subject_id) ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE curriculum_subject_requirements DROP FOREIGN KEY fk_curriculum_subject_requirements_target_placement, DROP FOREIGN KEY fk_curriculum_subject_requirements_required_placement;
ALTER TABLE curriculum_subject_requirements
    ADD CONSTRAINT fk_curriculum_subject_requirements_target_placement
        FOREIGN KEY (curriculum_version_id, subject_id)
        REFERENCES curriculum_version_subjects (curriculum_version_id, subject_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT fk_curriculum_subject_requirements_required_placement
        FOREIGN KEY (curriculum_version_id, required_subject_id)
        REFERENCES curriculum_version_subjects (curriculum_version_id, subject_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE curriculum_subject_requirements DROP FOREIGN KEY fk_013_requirement_target, DROP FOREIGN KEY fk_013_requirement_required;

-- Scope IDs in compliance CHECKs are also immutable identity references.
ALTER TABLE compliance_rules
    ADD CONSTRAINT fk_013_rule_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT fk_013_rule_program FOREIGN KEY (academic_program_id) REFERENCES academic_programs (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT fk_013_rule_curriculum FOREIGN KEY (curriculum_id) REFERENCES curricula (id) ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE compliance_rules DROP FOREIGN KEY fk_compliance_rules_subject, DROP FOREIGN KEY fk_compliance_rules_program, DROP FOREIGN KEY fk_compliance_rules_curriculum;
ALTER TABLE compliance_rules
    ADD CONSTRAINT fk_compliance_rules_subject
        FOREIGN KEY (subject_id) REFERENCES subjects (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT fk_compliance_rules_program
        FOREIGN KEY (academic_program_id) REFERENCES academic_programs (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT fk_compliance_rules_curriculum
        FOREIGN KEY (curriculum_id) REFERENCES curricula (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE compliance_rules DROP FOREIGN KEY fk_013_rule_subject, DROP FOREIGN KEY fk_013_rule_program, DROP FOREIGN KEY fk_013_rule_curriculum;

ALTER TABLE subject_equivalencies
    ADD CONSTRAINT fk_013_equivalency_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT fk_013_equivalency_equivalent FOREIGN KEY (equivalent_subject_id) REFERENCES subjects (id) ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE subject_equivalencies DROP FOREIGN KEY fk_subject_equivalencies_subject, DROP FOREIGN KEY fk_subject_equivalencies_equivalent_subject;
ALTER TABLE subject_equivalencies
    ADD CONSTRAINT fk_subject_equivalencies_subject
        FOREIGN KEY (subject_id) REFERENCES subjects (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT fk_subject_equivalencies_equivalent_subject
        FOREIGN KEY (equivalent_subject_id) REFERENCES subjects (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE subject_equivalencies DROP FOREIGN KEY fk_013_equivalency_subject, DROP FOREIGN KEY fk_013_equivalency_equivalent;
