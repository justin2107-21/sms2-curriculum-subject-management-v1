<?php
// Run only against a disposable schema created with database/bootstrap.php.
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../modules/curriculum/services/ArchiveCenterService.php';
require_once __DIR__ . '/../modules/curriculum/services/AcademicProgramService.php';
require_once __DIR__ . '/../modules/curriculum/services/CurriculumService.php';
require_once __DIR__ . '/../modules/curriculum/services/SubjectService.php';
require_once __DIR__ . '/../modules/curriculum/services/ElectivesWorkspaceService.php';

if ($conn->query('SELECT DATABASE()')->fetch_row()[0] !== 'sms_db_archive_test') {
    fwrite(STDERR, "Set SMS_DB_NAME=sms_db_archive_test before running this test.\n");
    exit(2);
}
function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function sql(mysqli $db, string $statement): int
{
    $db->query($statement);
    return (int)$db->insert_id;
}

$service = new ArchiveCenterService($conn);
$suffix = bin2hex(random_bytes(3));
$actor = sql($conn, "INSERT INTO users (username,email,first_name,last_name,password_hash,role) VALUES ('archive_$suffix','archive_$suffix@example.invalid','Archive','Tester','unused','admin')");
$program = sql($conn, "INSERT INTO academic_programs (program_code,program_name,status) VALUES ('BSIT-$suffix','Archive Test Program','active')");
$curriculum = sql($conn, "INSERT INTO curricula (curriculum_code,curriculum_name,academic_program_id,status) VALUES ('CUR-$suffix','Archive Test Curriculum',$program,'active')");
$version = sql($conn, "INSERT INTO curriculum_versions (curriculum_id,version_label,status) VALUES ($curriculum,'V-$suffix','active')");
$subjectA = sql($conn, "INSERT INTO subjects (subject_code,subject_title,units,status) VALUES ('SUB-A-$suffix','Archive Test Subject A',3,'active')");
$subjectB = sql($conn, "INSERT INTO subjects (subject_code,subject_title,units,status) VALUES ('SUB-B-$suffix','Archive Test Subject B',3,'active')");
foreach ([$subjectA,$subjectB] as $subject) sql($conn, "INSERT INTO curriculum_version_subjects (curriculum_version_id,subject_id,year_level,semester) VALUES ($version,$subject,'1','1')");
$group = sql($conn, "INSERT INTO curriculum_elective_groups (curriculum_version_id,group_name,year_level,semester,required_selections,status) VALUES ($version,'GROUP-$suffix','1','1',1,'active')");
foreach ([$subjectA,$subjectB] as $subject) sql($conn, "INSERT INTO curriculum_elective_group_subjects (elective_group_id,curriculum_version_id,subject_id) VALUES ($group,$version,$subject)");

$service->archive('program', $program, $actor);
check((new AcademicProgramService($conn))->list(['search'=>"BSIT-$suffix",'status'=>'','page'=>1,'per_page'=>20])['programs']===[], 'Archived program visible in normal list');
$list = $service->list(['module'=>'program','search'=>"  BSIT-$suffix  ",'date'=>'today']);
check($list['total'] === 1 && $list['records'][0]['record_id'] == $program, 'Program search/module/date failed');
check($list['records'][0]['actor_name'] === 'Archive Tester', 'Archive actor missing');
check($service->details('program', $program)['can_restore'], 'Program details invalid');
$service->archive('curriculum', $curriculum, $actor);
check((new CurriculumService($conn))->list(['search'=>"CUR-$suffix",'status'=>'','page'=>1,'per_page'=>20])['curricula']===[], 'Archived curriculum visible in normal list');
$blocked = $service->details('curriculum', $curriculum);
check(!$blocked['can_restore'] && $blocked['context'][0]['state'] === 'inactive', 'Archived parent not detected');
try { $service->restore('curriculum', $curriculum); throw new RuntimeException('Child restore was not blocked'); }
catch (DomainException $expected) {}
$service->restore('program', $program);
check(count((new AcademicProgramService($conn))->list(['search'=>"BSIT-$suffix",'status'=>'','page'=>1,'per_page'=>20])['programs']) === 1, 'Restored program missing from normal list');
$service->restore('curriculum', $curriculum);
check($conn->query("SELECT status FROM curricula WHERE id=$curriculum")->fetch_row()[0] === 'active', 'Curriculum restore failed');
check(count((new CurriculumService($conn))->list(['search'=>"CUR-$suffix",'status'=>'','page'=>1,'per_page'=>20])['curricula']) === 1, 'Restored curriculum missing from normal list');

$service->archive('subject', $subjectA, $actor);
check((new SubjectService($conn))->list(['search'=>"SUB-A-$suffix",'status'=>'','page'=>1,'per_page'=>20])['subjects']===[], 'Archived subject visible in normal list');
check($conn->query("SELECT COUNT(*) FROM curriculum_version_subjects WHERE subject_id=$subjectA")->fetch_row()[0] == 1, 'Subject placement lost');
check($service->list(['module'=>'subject','search'=>"SUB-A-$suffix"])['total'] === 1, 'Subject search failed');
check($service->list(['module'=>'subject','search'=>"BSIT-$suffix"])['total'] === 0, 'Context-only archive search returned an unrelated subject');
try { sql($conn,"INSERT INTO subjects (subject_code,subject_title,status) VALUES ('SUB-A-$suffix','Duplicate','active')"); throw new RuntimeException('Duplicate subject code was accepted'); }
catch (mysqli_sql_exception $expected) { check((int)$expected->getCode()===1062, 'Unexpected duplicate failure'); }
$service->restore('subject', $subjectA);
check(count((new SubjectService($conn))->list(['search'=>"SUB-A-$suffix",'status'=>'','page'=>1,'per_page'=>20])['subjects']) === 1, 'Restored subject missing from normal list');
$electives = new ElectivesWorkspaceService($conn);
$electives->archive(['id'=>$group,'program_id'=>$program,'curriculum_id'=>$curriculum,'curriculum_version_id'=>$version]);
check($service->list(['module'=>'elective_group','search'=>"GROUP-$suffix"])['total'] === 1, 'Existing elective archive action missing from center');
check($service->details('elective_group', $group)['can_restore'], 'Elective dependency validation failed');
$service->archive('program', $program, $actor);
check(!$service->details('elective_group', $group)['can_restore'], 'Elective restore ignored archived parent');
$service->restore('program', $program);
$conn->query("UPDATE curriculum_versions SET status='archived' WHERE id=$version");
check(!$service->details('elective_group', $group)['can_restore'], 'Elective restore ignored archived version');
$conn->query("UPDATE curriculum_versions SET status='active' WHERE id=$version");
$service->restore('elective_group', $group);
check($conn->query("SELECT COUNT(*) FROM curriculum_elective_group_subjects WHERE elective_group_id=$group")->fetch_row()[0] == 2, 'Elective memberships lost');

try { $service->restore('program', $program); throw new RuntimeException('Already restored record accepted'); }
catch (DomainException $expected) {}
try { $service->details('unlisted_table', 1); throw new RuntimeException('Arbitrary module accepted'); }
catch (InvalidArgumentException $expected) {}
check((int)$conn->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('PROGRAM_ARCHIVE','PROGRAM_RESTORE','CURRICULUM_ARCHIVE','CURRICULUM_RESTORE','SUBJECT_ARCHIVE','SUBJECT_RESTORE','ELECTIVE_GROUP_ARCHIVE','ELECTIVE_GROUP_RESTORE') AND entity_id IN ($program,$curriculum,$subjectA,$group)")->fetch_row()[0] >= 8, 'Audit events missing');

for ($i=0;$i<21;$i++) {
    $id=sql($conn,"INSERT INTO academic_programs (program_code,program_name,status) VALUES ('PAGE-$suffix-$i','Pagination Test','active')");
    $service->archive('program',$id,$actor);
}
check($service->list(['module'=>'program','search'=>"PAGE-$suffix",'per_page'=>20,'page'=>1])['total'] === 21, 'Pagination total failed');
check(count($service->list(['module'=>'program','search'=>"PAGE-$suffix",'per_page'=>20,'page'=>2])['records']) === 1, 'Pagination second page failed');
check($service->list(['module'=>'program','search'=>"PAGE-$suffix-1"])['records'][0]['identifier'] === "PAGE-$suffix-1", 'Exact search ranking failed');
check($service->list(['module'=>'program','search'=>'%'])['total'] === 0, 'Literal wildcard search failed');
$today=(new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
check($service->list(['module'=>'program','date'=>'custom','from'=>$today,'to'=>$today])['total'] >= 21, 'Custom date range failed');
$permissions=$conn->query("SELECT r.name role_name,p.name permission_name FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE p.name IN ('archive.view','archive.restore')")->fetch_all(MYSQLI_ASSOC);
check(count($permissions) === 2 && count(array_filter($permissions, fn($row) => $row['role_name'] !== 'admin')) === 0, 'Archive RBAC mappings incorrect');
echo "Archive service tests passed.\n";
