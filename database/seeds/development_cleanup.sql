-- DEVELOPMENT / SYNTHETIC DATA — NOT OFFICIAL BESTLINK DATA
-- Run ONLY through development.php --action=cleanup with an explicit development target.
-- The wrapper verifies audit provenance and unchanged row fingerprints, then
-- populates the connection-local TEMPORARY csms_demo_owned with exact IDs.
-- Added dependencies stop cleanup: FK checks stay enabled and all DML rolls back.
-- Only validation snapshots wholly owned by these synthetic versions/rules may
-- be added to the cleanup set. Audit records, users and permissions are untouched.
-- No temporary ownership table => all DELETE statements fail, deleting nothing.

DELETE t FROM compliance_validation_results t JOIN csms_demo_owned o ON o.table_name='compliance_validation_results' AND o.record_id=t.id;
DELETE t FROM compliance_validation_runs t JOIN csms_demo_owned o ON o.table_name='compliance_validation_runs' AND o.record_id=t.id;
DELETE t FROM compliance_rules t JOIN csms_demo_owned o ON o.table_name='compliance_rules' AND o.record_id=t.id;
DELETE t FROM compliance_references t JOIN csms_demo_owned o ON o.table_name='compliance_references' AND o.record_id=t.id;
DELETE t FROM grade_weighting_components t JOIN csms_demo_owned o ON o.table_name='grade_weighting_components' AND o.record_id=t.id;
DELETE t FROM grade_weighting_schemes t JOIN csms_demo_owned o ON o.table_name='grade_weighting_schemes' AND o.record_id=t.id;
DELETE t FROM subject_equivalencies t JOIN csms_demo_owned o ON o.table_name='subject_equivalencies' AND o.record_id=t.id;
DELETE t FROM curriculum_elective_group_subjects t JOIN csms_demo_owned o ON o.table_name='curriculum_elective_group_subjects' AND o.record_id=t.id;
DELETE t FROM curriculum_elective_groups t JOIN csms_demo_owned o ON o.table_name='curriculum_elective_groups' AND o.record_id=t.id;
DELETE t FROM curriculum_subject_requirements t JOIN csms_demo_owned o ON o.table_name='curriculum_subject_requirements' AND o.record_id=t.id;
DELETE t FROM curriculum_version_subjects t JOIN csms_demo_owned o ON o.table_name='curriculum_version_subjects' AND o.record_id=t.id;
DELETE t FROM curriculum_versions t JOIN csms_demo_owned o ON o.table_name='curriculum_versions' AND o.record_id=t.id;
DELETE t FROM curricula t JOIN csms_demo_owned o ON o.table_name='curricula' AND o.record_id=t.id;
DELETE t FROM subjects t JOIN csms_demo_owned o ON o.table_name='subjects' AND o.record_id=t.id;
DELETE t FROM academic_programs t JOIN csms_demo_owned o ON o.table_name='academic_programs' AND o.record_id=t.id;
