-- DEVELOPMENT / SYNTHETIC DATA — NOT OFFICIAL BESTLINK DATA
-- Run ONLY through: php database/seeds/development.php --action=seed --database=... --actor=... --confirm-development
-- The CLI wrapper supplies a connection-local TEMPORARY csms_demo_context,
-- checks collisions, holds a dataset lock, begins a transaction, records
-- provenance in the append-only audit trail and commits only on full success.
-- Without that temporary context every INSERT fails: no standalone import.
-- No USE, DDL, COMMIT, user/password creation or institutional policy.

INSERT INTO academic_programs (program_code,program_name,program_type,status,created_by)
SELECT code,name,'DEVELOPMENT SYNTHETIC','active',d.actor_id FROM (
 SELECT 'DEV-DEMO-PROG-A' code,'DEVELOPMENT DEMO PROGRAM A' name
 UNION ALL SELECT 'DEV-DEMO-PROG-B','DEVELOPMENT DEMO PROGRAM B'
) x CROSS JOIN csms_demo_context d;
SET @demo_pa=(SELECT id FROM academic_programs WHERE program_code='DEV-DEMO-PROG-A');
SET @demo_pb=(SELECT id FROM academic_programs WHERE program_code='DEV-DEMO-PROG-B');

INSERT INTO subjects (subject_code,subject_title,description,units,lecture_units,laboratory_units,status,created_by)
SELECT code,title,d.marker,units,units,NULL,'active',d.actor_id FROM (
 SELECT 'DEV-DEMO-101' code,'DEVELOPMENT DEMO SUBJECT A' title,3.00 units
 UNION ALL SELECT 'DEV-DEMO-102','DEVELOPMENT DEMO SUBJECT B',3.00
 UNION ALL SELECT 'DEV-DEMO-201','DEVELOPMENT DEMO SUBJECT C',3.00
 UNION ALL SELECT 'DEV-DEMO-202','DEVELOPMENT DEMO SUBJECT D',2.00
 UNION ALL SELECT 'DEV-DEMO-ELEC1','DEVELOPMENT DEMO OPTIONAL SUBJECT E',2.00
 UNION ALL SELECT 'DEV-DEMO-ELEC2','DEVELOPMENT DEMO OPTIONAL SUBJECT F',2.00
) x CROSS JOIN csms_demo_context d;
SET @demo_sa=(SELECT id FROM subjects WHERE subject_code='DEV-DEMO-101');
SET @demo_sb=(SELECT id FROM subjects WHERE subject_code='DEV-DEMO-102');
SET @demo_sc=(SELECT id FROM subjects WHERE subject_code='DEV-DEMO-201');
SET @demo_sd=(SELECT id FROM subjects WHERE subject_code='DEV-DEMO-202');
SET @demo_se=(SELECT id FROM subjects WHERE subject_code='DEV-DEMO-ELEC1');
SET @demo_sf=(SELECT id FROM subjects WHERE subject_code='DEV-DEMO-ELEC2');

INSERT INTO curricula (curriculum_code,curriculum_name,academic_program_id,status,created_by)
SELECT code,name,program,'active',d.actor_id FROM (
 SELECT 'DEV-DEMO-CURR-A' code,'DEVELOPMENT DEMO CURRICULUM A' name,@demo_pa program
 UNION ALL SELECT 'DEV-DEMO-CURR-B','DEVELOPMENT DEMO CURRICULUM B',@demo_pb
) x CROSS JOIN csms_demo_context d;
SET @demo_ca=(SELECT id FROM curricula WHERE curriculum_code='DEV-DEMO-CURR-A');
SET @demo_cb=(SELECT id FROM curricula WHERE curriculum_code='DEV-DEMO-CURR-B');

INSERT INTO curriculum_versions (curriculum_id,version_label,effective_academic_year,description,status,created_by)
SELECT curriculum,label,'DEV-DEMO-YEAR',d.marker,status,d.actor_id FROM (
 SELECT @demo_ca curriculum,'DEV-V1' label,'archived' status
 UNION ALL SELECT @demo_ca,'DEV-V2','draft'
 UNION ALL SELECT @demo_cb,'DEV-V1','active'
) x CROSS JOIN csms_demo_context d;
SET @demo_va=(SELECT id FROM curriculum_versions WHERE curriculum_id=@demo_ca AND version_label='DEV-V1');
SET @demo_vb=(SELECT id FROM curriculum_versions WHERE curriculum_id=@demo_ca AND version_label='DEV-V2');
SET @demo_vc=(SELECT id FROM curriculum_versions WHERE curriculum_id=@demo_cb AND version_label='DEV-V1');

-- V1 -> V2: D added, F removed, C placement changed, A/B/E unchanged.
-- Labels and sequence values are artificial display data, not academic policy.
INSERT INTO curriculum_version_subjects (curriculum_version_id,subject_id,year_level,semester,sequence_order,is_required,created_by)
SELECT version,subject,year,term,sequence,required,d.actor_id FROM (
 SELECT @demo_va version,@demo_sa subject,'DEV-Year-A' year,'DEV-Term-A' term,1 sequence,1 required
 UNION ALL SELECT @demo_va,@demo_sb,'DEV-Year-A','DEV-Term-A',2,1
 UNION ALL SELECT @demo_va,@demo_sc,'DEV-Year-A','DEV-Term-B',3,1
 UNION ALL SELECT @demo_va,@demo_se,'DEV-Year-B','DEV-Term-C',4,0
 UNION ALL SELECT @demo_va,@demo_sf,'DEV-Year-B','DEV-Term-C',5,0
 UNION ALL SELECT @demo_vb,@demo_sa,'DEV-Year-A','DEV-Term-A',1,1
 UNION ALL SELECT @demo_vb,@demo_sb,'DEV-Year-A','DEV-Term-A',2,1
 UNION ALL SELECT @demo_vb,@demo_sc,'DEV-Year-B','DEV-Term-C',3,1
 UNION ALL SELECT @demo_vb,@demo_sd,'DEV-Year-B','DEV-Term-C',5,1
 UNION ALL SELECT @demo_vb,@demo_se,'DEV-Year-B','DEV-Term-C',4,0
 UNION ALL SELECT @demo_vc,@demo_sa,'DEV-Year-X','DEV-Term-X',1,1
) x CROSS JOIN csms_demo_context d;

INSERT INTO curriculum_subject_requirements (curriculum_version_id,subject_id,required_subject_id,requirement_type,created_by)
SELECT @demo_va,subject,required,type,d.actor_id FROM (
 SELECT @demo_sc subject,@demo_sb required,'prerequisite' type
 UNION ALL SELECT @demo_sb,@demo_sa,'prerequisite'
 UNION ALL SELECT @demo_se,@demo_sf,'corequisite'
) x CROSS JOIN csms_demo_context d;

INSERT INTO curriculum_elective_groups (curriculum_version_id,group_name,description,created_by)
SELECT @demo_va,'DEV-DEMO OPTIONAL GROUP',d.marker,d.actor_id FROM csms_demo_context d;
SET @demo_group=LAST_INSERT_ID();
INSERT INTO curriculum_elective_group_subjects (elective_group_id,curriculum_version_id,subject_id,created_by)
SELECT @demo_group,@demo_va,x.subject,d.actor_id FROM (
 SELECT @demo_se subject UNION ALL SELECT @demo_sf
) x CROSS JOIN csms_demo_context d;

-- Explicitly synthetic demonstration decisions, never automatic equivalency.
INSERT INTO subject_equivalencies (subject_id,equivalent_subject_id,decision_status,remarks,reviewed_by,reviewed_at,created_by)
SELECT LEAST(a,b),GREATEST(a,b),decision,d.marker,IF(decision='pending',NULL,d.actor_id),
 IF(decision='pending',NULL,CURRENT_TIMESTAMP),d.actor_id FROM (
 SELECT @demo_sa a,@demo_sb b,'pending' decision
 UNION ALL SELECT @demo_sa,@demo_sc,'equivalent'
 UNION ALL SELECT @demo_sb,@demo_sd,'not_equivalent'
) x CROSS JOIN csms_demo_context d;

INSERT INTO grade_weighting_schemes (curriculum_version_subject_id,description,created_by)
SELECT p.id,d.marker,d.actor_id FROM curriculum_version_subjects p CROSS JOIN csms_demo_context d
WHERE p.curriculum_version_id=@demo_va AND p.subject_id=@demo_sa;
SET @demo_scheme=LAST_INSERT_ID();
INSERT INTO grade_weighting_components (scheme_id,component_name,weight_percentage,sequence_order,created_by)
SELECT @demo_scheme,x.name,x.weight,x.sequence,d.actor_id FROM (
 SELECT 'DEV-DEMO COMPONENT A' name,40.00 weight,1 sequence
 UNION ALL SELECT 'DEV-DEMO COMPONENT B',60.00,2
) x CROSS JOIN csms_demo_context d;

INSERT INTO compliance_references (reference_type,reference_code,title,issuing_body,notes,verification_status,created_by)
SELECT 'OTHER','DEV-DEMO-REFERENCE','DEVELOPMENT SYNTHETIC COMPLIANCE REFERENCE','DEVELOPMENT TEST SOURCE',
 CONCAT(d.marker,' — NOT A REAL CHED, DEPED, OR BESTLINK POLICY.'),'verified',d.actor_id FROM csms_demo_context d;
SET @demo_reference=LAST_INSERT_ID();
-- Artificial thresholds solely exercise deterministic PASS / FAIL / NOT_EVALUATED.
INSERT INTO compliance_rules (compliance_reference_id,rule_name,rule_type,description,numeric_value,subject_id,curriculum_id,technical_status,created_by)
SELECT @demo_reference,x.name,x.type,d.marker,x.threshold,x.subject,@demo_ca,'active',d.actor_id FROM (
 SELECT 'DEV-DEMO MIN TOTAL' name,'MIN_TOTAL_UNITS' type,1.00 threshold,NULL subject
 UNION ALL SELECT 'DEV-DEMO MAX TOTAL','MAX_TOTAL_UNITS',1.00,NULL
 UNION ALL SELECT 'DEV-DEMO REQUIRED SUBJECT','REQUIRED_SUBJECT',NULL,@demo_sa
 UNION ALL SELECT 'DEV-DEMO MIN SUBJECT','MIN_SUBJECT_UNITS',1.00,@demo_sa
 UNION ALL SELECT 'DEV-DEMO ABSENT SUBJECT','MAX_SUBJECT_UNITS',9.00,@demo_sd
) x CROSS JOIN csms_demo_context d;
