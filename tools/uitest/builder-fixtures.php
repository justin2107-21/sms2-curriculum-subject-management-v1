<?php
// Curriculum Builder scenario rows for logged-in checks and tests. sms_db_uitest only; rebuild the database afterwards.
//   UITEST-REF     reference (copy of UITEST-A's placements)        -> "Create revision" for Staff / Super Admin
//   UITEST-REV     proposed revision of UITEST-REF, not started     -> "Submit for review"; has one prerequisite conflict
//   UITEST-REVIEW  proposed revision, under review (submitted by uitest.staff) -> Approve / Return for Acad Office
//   MIN_TOTAL_UNITS rule of 20 units on UITEST-PA                    -> a target in the header
// UITEST-B stays unclassified (no source attached).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('DB_NAME') !== 'sms_db_uitest') { fwrite(STDERR, "Refusing: set DB_NAME=sms_db_uitest\n"); exit(1); }
require_once __DIR__ . '/../../shared/database_config.php';
$db = csms_database_connect(csms_database_config());
if ($db->query('SELECT DATABASE()')->fetch_row()[0] !== 'sms_db_uitest') exit(1);
$one = static fn(string $sql) => $db->query($sql)->fetch_row()[0];
$staff = (int)$one("SELECT id FROM users WHERE username='uitest.staff'");
$db->query("INSERT INTO compliance_references(reference_type,source_kind,reference_code,title,issuing_body,verification_status,created_by) VALUES('INSTITUTIONAL','institutional_policy','UITEST-SRC','UITEST source record','UITEST','verified',$staff)");
$source = (int)$db->insert_id;
$a = (int)$one("SELECT id FROM curriculum_versions WHERE version_label='UITEST-A'");
$curriculum = (int)$one("SELECT curriculum_id FROM curriculum_versions WHERE id=$a");
$program = (int)$one("SELECT academic_program_id FROM curricula WHERE id=$curriculum");
if ($one("SELECT COUNT(*) FROM curriculum_versions WHERE version_label='UITEST-REF'") > 0) { fwrite(STDERR, "Fixtures already present.\n"); exit(1); }

$make = static function (string $label, string $role, ?int $baseline, string $review, string $status) use ($db, $curriculum, $staff, $a): int {
    $b = $baseline ?? 'NULL';
    $db->query("INSERT INTO curriculum_versions(curriculum_id,version_label,status,version_role,review_status,baseline_version_id,created_by) VALUES($curriculum,'$label','$status','$role','$review',$b,$staff)");
    $id = (int)$db->insert_id;
    $db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester,sequence_order,is_required,created_by)
        SELECT $id, subject_id, year_level, semester, sequence_order, is_required, $staff FROM curriculum_version_subjects WHERE curriculum_version_id=$a");
    return $id;
};
$ref = $make('UITEST-REF', 'reference', null, 'not_started', 'active');
$rev = $make('UITEST-REV', 'proposed', $ref, 'not_started', 'draft');
$review = $make('UITEST-REVIEW', 'proposed', $ref, 'under_review', 'draft');
$db->query("INSERT INTO curriculum_version_review_events(curriculum_version_id,actor_user_id,action,previous_status,new_status,comments) VALUES($review,$staff,'submit','not_started','under_review','Ready for review')");
// Prerequisite conflict in UITEST-REV: a subject whose prerequisite sits in a later term.
$rows = $db->query("SELECT subject_id, year_level, semester FROM curriculum_version_subjects WHERE curriculum_version_id=$rev ORDER BY year_level, semester, id")->fetch_all(MYSQLI_ASSOC);
$first = $rows[0]['subject_id']; $last = end($rows)['subject_id'];
if ($first !== $last) $db->query("INSERT INTO curriculum_subject_requirements(curriculum_version_id,subject_id,required_subject_id,requirement_type,created_by) VALUES($rev,$first,$last,'prerequisite',$staff)");
$db->query("INSERT INTO compliance_rules(compliance_reference_id,rule_name,rule_type,numeric_value,academic_program_id,curriculum_id,technical_status,created_by) VALUES($source,'UITEST minimum units','MIN_TOTAL_UNITS',20,$program,NULL,'active',$staff)");
echo json_encode(['reference' => $ref, 'revision' => $rev, 'under_review' => $review, 'program' => $program, 'curriculum' => $curriculum]) . PHP_EOL;
