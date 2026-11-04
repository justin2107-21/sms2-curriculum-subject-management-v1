<?php
// Creates a protected reference and a proposed revision with placements in sms_db_uitest only, ready for the review flow.
// Prints: program_id curriculum_id version_id. The review itself is done through the browser.
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../../shared/database_config.php';
$config = csms_database_config();
if (csms_uitest_database($config['database']) !== CSMS_UITEST_DATABASE) { fwrite(STDERR, "Refusing: not sms_db_uitest.\n"); exit(1); }
$db = csms_database_connect($config);
if ($db->query('SELECT DATABASE()')->fetch_row()[0] !== CSMS_UITEST_DATABASE) { fwrite(STDERR, "Refusing: wrong database.\n"); exit(1); }
$source = $db->query('SELECT v.id, v.curriculum_id, c.academic_program_id FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id
    JOIN curriculum_version_subjects s ON s.curriculum_version_id=v.id GROUP BY v.id ORDER BY COUNT(s.id) DESC, v.id LIMIT 1')->fetch_assoc();
if (!$source) { fwrite(STDERR, "No version with placements.\n"); exit(1); }
$curriculum = (int)$source['curriculum_id'];
$suffix = date('His');
$db->query("INSERT INTO curriculum_versions(curriculum_id,version_label,status,version_role,review_status) VALUES($curriculum,'UITEST-REF-$suffix','active','reference','not_started')");
$reference = (int)$db->insert_id;
$db->query("INSERT INTO curriculum_versions(curriculum_id,version_label,status,version_role,review_status,baseline_version_id) VALUES($curriculum,'UITEST-REV-$suffix','draft','proposed','not_started',$reference)");
$proposed = (int)$db->insert_id;
$db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester)
    SELECT $proposed,subject_id,year_level,semester FROM curriculum_version_subjects WHERE curriculum_version_id=" . (int)$source['id']);
echo (int)$source['academic_program_id'] . ' ' . $curriculum . ' ' . $proposed . "\n";
