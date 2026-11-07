<?php
// Elective group scenarios for logged-in checks and tests. sms_db_uitest only; idempotent; removable.
//   DB_NAME=sms_db_uitest php tools/uitest/elective-fixtures.php            create (does nothing if already present)
//   DB_NAME=sms_db_uitest php tools/uitest/elective-fixtures.php --remove   delete every fixture row it created
//   Optional: a version label (default PREORAL-2026, the draft BSIT version).
//   --small creates only two groups from whatever is placed (for tiny versions such as the active UITEST-REF, which Acad Faculty can read).
// Everything is named "UITEST-ELEC ..." so it can be found and removed. One subject, UITEST-ELEC-INACT, is created
// (status inactive) and placed in the version so the "inactive subject in a pool" case exists; --remove deletes it again.
// Groups: two terms, a shared subject (two groups), a pool smaller than its requirement, an inactive subject, a pool subject
// placed in another term, a group with no term, an empty group, an inactive group, an archived group and one long name.
// Elective type does not exist in the schema yet, so every group is "Type not set" until the type slice.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('DB_NAME') !== 'sms_db_uitest') { fwrite(STDERR, "Refusing: set DB_NAME=sms_db_uitest\n"); exit(1); }
require_once __DIR__ . '/../../shared/database_config.php';
$db = csms_database_connect(csms_database_config());
if ($db->query('SELECT DATABASE()')->fetch_row()[0] !== 'sms_db_uitest') { fwrite(STDERR, "Refusing: wrong database.\n"); exit(1); }
$remove = in_array('--remove', $argv, true);
$small = in_array('--small', $argv, true);
$label = 'PREORAL-2026';
foreach (array_slice($argv, 1) as $arg) if (!in_array($arg, ['--remove', '--small'], true)) $label = $arg;
$stmt = $db->prepare('SELECT id FROM curriculum_versions WHERE version_label=?');
$stmt->bind_param('s', $label); $stmt->execute();
$version = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
if (!$version) { fwrite(STDERR, "Version $label not found.\n"); exit(1); }

if ($remove) {
    $db->query("DELETE m FROM curriculum_elective_group_subjects m JOIN curriculum_elective_groups g ON g.id=m.elective_group_id WHERE g.curriculum_version_id=$version AND g.group_name LIKE 'UITEST-ELEC %'");
    $db->query("DELETE FROM curriculum_elective_groups WHERE curriculum_version_id=$version AND group_name LIKE 'UITEST-ELEC %'");
    $db->query("DELETE FROM curriculum_version_subjects WHERE curriculum_version_id=$version AND subject_id IN (SELECT id FROM subjects WHERE subject_code='UITEST-ELEC-INACT')");
    $db->query("DELETE FROM subjects WHERE subject_code='UITEST-ELEC-INACT'");
    echo "Removed elective fixtures from version $version.\n";
    exit(0);
}
if ((int)$db->query("SELECT COUNT(*) FROM curriculum_elective_groups WHERE curriculum_version_id=$version AND group_name LIKE 'UITEST-ELEC %'")->fetch_row()[0] > 0) {
    echo "Elective fixtures already present in version $version.\n"; exit(0);
}
$staff = $db->query("SELECT id FROM users WHERE username='uitest.staff'")->fetch_row()[0] ?? null;
$by = $staff === null ? 'NULL' : (int)$staff;

// Placed subjects by term, in a stable order.
$terms = [];
foreach ($db->query("SELECT subject_id, year_level, semester FROM curriculum_version_subjects WHERE curriculum_version_id=$version AND year_level IS NOT NULL AND semester IS NOT NULL ORDER BY year_level, semester, id") as $r) {
    $terms[$r['year_level'] . '|' . $r['semester']][] = (int)$r['subject_id'];
}
$pick = static function (string $year, string $semester, int $from, int $count) use ($terms): array {
    $list = $terms["$year|$semester"] ?? [];
    if (count($list) < $from + $count) { fwrite(STDERR, "Version needs at least " . ($from + $count) . " subjects in $year / $semester.\n"); exit(1); }
    return array_slice($list, $from, $count);
};

if ($small) {
    $term = null;
    foreach ($terms as $key => $list) if (count($list) >= 2) { $term = $key; break; }
    if ($term === null) { fwrite(STDERR, "No term with two placed subjects.
"); exit(1); }
    [$year, $semester] = explode('|', $term);
    $groups = [['Read-only check', $year, $semester, 1, 'active', array_slice($terms[$term], 0, 2)], ['Read-only empty', $year, $semester, 1, 'active', []]];
} else {
$db->query("INSERT INTO subjects(subject_code,subject_title,units,status,created_by) VALUES('UITEST-ELEC-INACT','Fixture subject, not active',3,'inactive',$by)");
$inactive = (int)$db->insert_id;
$db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester,is_required,created_by) VALUES($version,$inactive,'1st Year','1st Semester',0,$by)");

$groups = [
    ['Professional 1', '1st Year', '1st Semester', 1, 'active', $pick('1st Year', '1st Semester', 0, 3)],
    ['Shared pool', '1st Year', '1st Semester', 1, 'active', array_merge($pick('1st Year', '1st Semester', 0, 1), $pick('1st Year', '1st Semester', 3, 1))],
    ['Inactive subject', '1st Year', '1st Semester', 1, 'active', array_merge($pick('1st Year', '1st Semester', 4, 1), [$inactive])],
    ['General Education Elective with a deliberately long name to check wrapping on small screens', '2nd Year', '1st Semester', 1, 'active', $pick('2nd Year', '1st Semester', 0, 2)],
    ['Small pool', '3rd Year', '1st Semester', 3, 'active', $pick('3rd Year', '1st Semester', 0, 2)],
    ['Mismatch', '3rd Year', '2nd Semester', 1, 'active', array_merge($pick('3rd Year', '2nd Semester', 0, 1), $pick('4th Year', '1st Semester', 0, 1))],
    ['Empty', '2nd Year', '2nd Semester', 1, 'active', []],
    ['Inactive group', '4th Year', '1st Semester', 1, 'inactive', $pick('4th Year', '1st Semester', 1, 2)],
    ['Archived', '4th Year', '2nd Semester', 1, 'archived', $pick('4th Year', '2nd Semester', 0, 2)],
    ['No term', null, null, 1, 'active', $pick('1st Year', '2nd Semester', 0, 1)],
];
}
$insertGroup = $db->prepare('INSERT INTO curriculum_elective_groups(curriculum_version_id,group_name,year_level,semester,required_selections,status,created_by) VALUES(?,?,?,?,?,?,?)');
$insertMember = $db->prepare('INSERT INTO curriculum_elective_group_subjects(elective_group_id,curriculum_version_id,subject_id,created_by) VALUES(?,?,?,?)');
foreach ($groups as [$name, $year, $semester, $required, $status, $members]) {
    $full = 'UITEST-ELEC ' . $name;
    $creator = $staff === null ? null : (int)$staff;
    $insertGroup->bind_param('isssisi', $version, $full, $year, $semester, $required, $status, $creator);
    $insertGroup->execute();
    $groupId = (int)$db->insert_id;
    foreach ($members as $subject) { $insertMember->bind_param('iiii', $groupId, $version, $subject, $creator); $insertMember->execute(); }
}
echo 'Created ' . count($groups) . " elective fixture groups in version $version ($label).\n";
