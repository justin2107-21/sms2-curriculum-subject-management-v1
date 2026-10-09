<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/version-protection-fixture.php';
$db = protectionDatabase();
$checks = 0;
function task3SchemaCheck(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; }
function task3SchemaReject(mysqli $db, string $sql, string $label): void {
    try { $db->query($sql); } catch (mysqli_sql_exception) { task3SchemaCheck(true, $label); return; }
    throw new RuntimeException('FAIL: accepted invalid schema value: '.$label);
}
$columns = [];
foreach ($db->query('SHOW COLUMNS FROM compliance_references')->fetch_all(MYSQLI_ASSOC) as $row) $columns[$row['Field']] = $row;
task3SchemaCheck(isset($columns['source_kind'], $columns['supersedes_reference_id']), 'source classification columns exist');
task3SchemaCheck(($columns['source_kind']['Default'] ?? null) === 'unknown', 'legacy source default is explicitly unknown');
foreach (['compliance_reference_evidence_events','curriculum_version_references','curriculum_version_subject_snapshots'] as $table) {
    $found = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'")->fetch_row()[0];
    task3SchemaCheck($found === 1, 'Task 3 table exists: '.$table);
}
$snapshotColumns = array_column($db->query('SHOW COLUMNS FROM curriculum_version_subject_snapshots')->fetch_all(MYSQLI_ASSOC), 'Field');
task3SchemaCheck(in_array('lecture_units_snapshot',$snapshotColumns,true) && in_array('laboratory_units_snapshot',$snapshotColumns,true), 'lecture and lab units can be preserved');
task3SchemaCheck(in_array('source_reference_id',$snapshotColumns,true) && in_array('snapshot_complete',$snapshotColumns,true), 'snapshot source and completeness are represented');
$grants = (int)$db->query("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.name IN ('curriculum.reference.attach','compliance.reference.verify','curriculum.reference.classify')")->fetch_row()[0];
task3SchemaCheck($grants === 0, 'new high-impact permissions have no automatic role grants');
$actor = protectionInsert($db, "INSERT INTO users(username,email,first_name,last_name,password_hash) VALUES ('task3-schema-actor','schema@example.invalid','Schema','Actor','x')");
$program = protectionInsert($db, "INSERT INTO academic_programs(program_code,program_name) VALUES ('T3-SCHEMA','Schema program')");
$curriculum = protectionInsert($db, "INSERT INTO curricula(curriculum_code,curriculum_name,academic_program_id) VALUES ('T3-SCHEMA','Schema curriculum',$program)");
$version = protectionInsert($db, "INSERT INTO curriculum_versions(curriculum_id,version_label) VALUES ($curriculum,'Legacy')");
$subject = protectionInsert($db, "INSERT INTO subjects(subject_code,subject_title,units) VALUES ('T3-SCHEMA-S','Schema subject',3)");
$placement = protectionInsert($db, "INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id) VALUES ($version,$subject)");
$source = protectionInsert($db, "INSERT INTO compliance_references(reference_type,title,issuing_body) VALUES ('CHED','Legacy reference','CHED')");
$legacy = $db->query("SELECT source_kind FROM compliance_references WHERE id=$source")->fetch_row();
task3SchemaCheck($legacy[0] === 'unknown', 'existing source rows retain neutral classification on additive default');
$db->query("INSERT INTO curriculum_version_references(curriculum_version_id,compliance_reference_id,reference_purpose,verification_status_snapshot) VALUES ($version,$source,'school_curriculum','draft')");
$db->query("INSERT INTO curriculum_version_subject_snapshots(curriculum_version_subject_id,subject_code_snapshot,subject_title_snapshot,units_snapshot,capture_method) VALUES ($placement,'Legacy snapshot','Legacy title',3,'mapping_capture')");
$legacySnapshot = $db->query("SELECT snapshot_complete,source_reference_id,lecture_units_snapshot,laboratory_units_snapshot FROM curriculum_version_subject_snapshots WHERE curriculum_version_subject_id=$placement")->fetch_assoc();
task3SchemaCheck((int)$legacySnapshot['snapshot_complete'] === 0 && $legacySnapshot['source_reference_id'] === null, 'legacy snapshot is not backfilled as complete or source-backed');
$event = $db->prepare("INSERT INTO compliance_reference_evidence_events(compliance_reference_id,verification_subject,result,source_kind_snapshot,issuing_body_snapshot,rationale,recorded_by) VALUES (?,'applicability','not_assessed','unknown','CHED','Fixture only',$actor)");
$event->bind_param('i',$source);$event->execute();$event->close();
task3SchemaCheck(true, 'separate applicability evidence can remain not assessed');
task3SchemaReject($db, "UPDATE compliance_references SET source_kind='invented' WHERE id=$source", 'source kind CHECK rejects unsupported taxonomy');
task3SchemaReject($db, "UPDATE curriculum_version_subject_snapshots SET snapshot_complete=2 WHERE curriculum_version_subject_id=$placement", 'snapshot completeness CHECK rejects invalid state');
task3SchemaReject($db, "INSERT INTO compliance_reference_evidence_events(compliance_reference_id,verification_subject,result,source_kind_snapshot,issuing_body_snapshot,rationale,recorded_by) VALUES ($source,'adoption','confirmed','unknown','CHED','invalid',$actor)", 'evidence subject CHECK separates adoption from source verification');

// Build a second schema containing the actual 021 table shapes and rows, then
// apply only 022 there to verify additive upgrade preservation independently.
$identity=$db->query('SELECT @@port AS port,@@datadir AS datadir,@@foreign_key_checks AS fk')->fetch_assoc();
$tempRoot=realpath((string)getenv('CSMS_TASK2_TEMP_ROOT'));
$dataRoot=realpath((string)getenv('CSMS_TASK2_TEMP_ROOT').DIRECTORY_SEPARATOR.'data');
task3SchemaCheck($tempRoot!==false && $dataRoot!==false && str_starts_with(strtolower((string)realpath($identity['datadir'])),strtolower($dataRoot)) && (int)$identity['port']===(int)getenv('DB_PORT') && (int)$identity['fk']===1, 'upgrade fixture is confined to the verified disposable MariaDB instance');
$adminDb=new mysqli(getenv('DB_HOST'),getenv('DB_USER'),getenv('DB_PASS'),null,(int)getenv('DB_PORT'));
$upgradeName='csms_docker_test_task3_upgrade_'.bin2hex(random_bytes(5));
$upgradeDb=null;
try {
    $adminDb->query("CREATE DATABASE `$upgradeName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $upgradeDb=new mysqli(getenv('DB_HOST'),getenv('DB_USER'),getenv('DB_PASS'),$upgradeName,(int)getenv('DB_PORT'));
    $legacySql=<<<'SQL'
CREATE TABLE users(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,username VARCHAR(100) NOT NULL) ENGINE=InnoDB;
CREATE TABLE permissions(name VARCHAR(120) PRIMARY KEY,module VARCHAR(80) NOT NULL,description VARCHAR(255) NOT NULL) ENGINE=InnoDB;
CREATE TABLE compliance_references (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reference_type VARCHAR(30) NOT NULL,
 reference_code VARCHAR(120) NULL, title VARCHAR(255) NULL, issuing_body VARCHAR(180) NOT NULL,
 source_url VARCHAR(1000) NULL, effective_date DATE NULL, notes TEXT NULL,
 verification_status VARCHAR(20) NOT NULL DEFAULT 'draft', created_by INT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_compliance_reference_code(reference_type,reference_code),
 CONSTRAINT fk_test_reference_creator FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE curriculum_versions(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE curriculum_version_subjects(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE curriculum_version_references (
 curriculum_version_id BIGINT UNSIGNED NOT NULL, compliance_reference_id BIGINT UNSIGNED NOT NULL,
 reference_purpose VARCHAR(30) NOT NULL, reference_code_snapshot VARCHAR(120) NULL,
 reference_title_snapshot VARCHAR(255) NULL, reference_url_snapshot VARCHAR(1000) NULL,
 verification_status_snapshot VARCHAR(20) NOT NULL, linked_by INT UNSIGNED NULL,
 linked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(curriculum_version_id,compliance_reference_id,reference_purpose),
 INDEX idx_cvr_reference_id(compliance_reference_id), INDEX idx_cvr_linked_by(linked_by),
 CONSTRAINT fk_test_cvr_version FOREIGN KEY(curriculum_version_id) REFERENCES curriculum_versions(id),
 CONSTRAINT fk_test_cvr_reference FOREIGN KEY(compliance_reference_id) REFERENCES compliance_references(id),
 CONSTRAINT fk_test_cvr_user FOREIGN KEY(linked_by) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE curriculum_version_subject_snapshots (
 curriculum_version_subject_id BIGINT UNSIGNED PRIMARY KEY,
 subject_code_snapshot VARCHAR(60) NOT NULL, subject_title_snapshot VARCHAR(255) NOT NULL,
 units_snapshot DECIMAL(6,2) NOT NULL, description_snapshot TEXT NULL,
 captured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, capture_method VARCHAR(24) NOT NULL,
 CONSTRAINT fk_test_snapshot_placement FOREIGN KEY(curriculum_version_subject_id) REFERENCES curriculum_version_subjects(id)
) ENGINE=InnoDB;
SQL;
    foreach(explode(";",$legacySql) as $statement){if(trim($statement)!=='')$upgradeDb->query($statement);}
    $upgradeDb->query("INSERT INTO users(username) VALUES ('preserved-actor')");
    $upgradeDb->query("INSERT INTO compliance_references(reference_type,reference_code,title,issuing_body,verification_status) VALUES ('CHED','OLD-022','Existing source','CHED','verified')");
    $upgradeDb->query("INSERT INTO curriculum_versions VALUES (1)");$upgradeDb->query("INSERT INTO curriculum_version_subjects VALUES (1)");
    $upgradeDb->query("INSERT INTO curriculum_version_references(curriculum_version_id,compliance_reference_id,reference_purpose,reference_code_snapshot,reference_title_snapshot,verification_status_snapshot,linked_by) VALUES (1,1,'school_curriculum','OLD-022','Existing source','verified',1)");
    $upgradeDb->query("INSERT INTO curriculum_version_subject_snapshots(curriculum_version_subject_id,subject_code_snapshot,subject_title_snapshot,units_snapshot,capture_method) VALUES (1,'OLD-S','Old subject',3,'reference_capture')");
    $migration=preg_replace('/^USE sms_db;\s*$/m','',file_get_contents(__DIR__.'/../database/migrations/022_curriculum_reference_provenance.sql'));
    if(!is_string($migration)||$migration==='')throw new RuntimeException('Could not load migration 022 for upgrade fixture.');
    if(!$upgradeDb->multi_query($migration))throw new RuntimeException('Migration 022 upgrade application failed.');
    do { if($result=$upgradeDb->store_result())$result->free(); } while($upgradeDb->more_results() && $upgradeDb->next_result());
    $sourceRow=$upgradeDb->query('SELECT reference_code,verification_status,source_kind FROM compliance_references WHERE id=1')->fetch_assoc();
    $linkRow=$upgradeDb->query('SELECT reference_code_snapshot,verification_status_snapshot,source_kind_snapshot FROM curriculum_version_references WHERE curriculum_version_id=1')->fetch_assoc();
    $snapshotRow=$upgradeDb->query('SELECT subject_code_snapshot,units_snapshot,snapshot_complete,source_reference_id FROM curriculum_version_subject_snapshots WHERE curriculum_version_subject_id=1')->fetch_assoc();
    task3SchemaCheck($sourceRow['reference_code']==='OLD-022' && $sourceRow['verification_status']==='verified' && $sourceRow['source_kind']==='unknown', '021 source rows retain values and receive only neutral source-kind default');
    task3SchemaCheck($linkRow['reference_code_snapshot']==='OLD-022' && $linkRow['verification_status_snapshot']==='verified' && $linkRow['source_kind_snapshot']==='unknown', '021 source-link snapshots retain prior identity and get unknown classification');
    task3SchemaCheck($snapshotRow['subject_code_snapshot']==='OLD-S' && (float)$snapshotRow['units_snapshot']===3.0 && (int)$snapshotRow['snapshot_complete']===0 && $snapshotRow['source_reference_id']===null, '021 subject snapshots retain original fields and remain honestly incomplete');
} finally {
    if($upgradeDb instanceof mysqli)$upgradeDb->close();
    $adminDb->query("DROP DATABASE IF EXISTS `$upgradeName`");
    $adminDb->close();
}
echo json_encode(['passed'=>true,'checks'=>$checks,'suite'=>'Task 3 migration/schema'], JSON_THROW_ON_ERROR).PHP_EOL;
