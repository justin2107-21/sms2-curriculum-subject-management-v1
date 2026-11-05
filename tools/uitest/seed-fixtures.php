<?php
// Creates the UI-scenario fixtures in sms_db_uitest only (run by seed-users.php during setup-db.ps1).
// The scenario no longer depends on any real program, curriculum or version name, so those can be renamed on screen.
//   Program UITEST-PA: curriculum UITEST-CA with drafts UITEST-A and UITEST-B; curriculum UITEST-CC with active UITEST-C.
//   Program UITEST-PB: curriculum UITEST-CB with active UITEST-D.
// The ids go to .env.uitest (git-ignored) as UITEST_FX_* lines. They are not secrets.
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../../shared/database_config.php';

$config = csms_database_config();
if (csms_uitest_database($config['database']) !== CSMS_UITEST_DATABASE || !in_array($config['host'], ['localhost', '127.0.0.1'], true)) {
    fwrite(STDERR, "Refusing: DB_NAME must be exactly sms_db_uitest on localhost.\n");
    exit(1);
}
$db = csms_database_connect($config);
if ($db->query('SELECT DATABASE()')->fetch_row()[0] !== CSMS_UITEST_DATABASE) {
    fwrite(STDERR, "Refusing: connected database is not sms_db_uitest.\n");
    exit(1);
}
if ((int)$db->query("SELECT COUNT(*) FROM academic_programs WHERE program_code IN ('UITEST-PA','UITEST-PB')")->fetch_row()[0] > 0) {
    fwrite(STDERR, "Fixtures already exist; rebuild with setup-db.ps1.\n");
    exit(1);
}
$subjects = array_column($db->query('SELECT id FROM subjects ORDER BY id LIMIT 4')->fetch_all(MYSQLI_ASSOC), 'id');
if (count($subjects) < 4) { fwrite(STDERR, "Need at least 4 subjects to build fixtures.\n"); exit(1); }

$program = static function (string $code) use ($db): int {
    $stmt = $db->prepare("INSERT INTO academic_programs (program_code, program_name, program_type, status) VALUES (?, ?, 'Bachelor''s Degree', 'active')");
    $name = 'UI test program ' . $code;
    $stmt->bind_param('ss', $code, $name);
    $stmt->execute();
    return (int)$db->insert_id;
};
$curriculum = static function (string $code, int $program) use ($db): int {
    $stmt = $db->prepare("INSERT INTO curricula (curriculum_code, curriculum_name, academic_program_id, status) VALUES (?, ?, ?, 'active')");
    $name = 'UI test curriculum ' . $code;
    $stmt->bind_param('ssi', $code, $name, $program);
    $stmt->execute();
    return (int)$db->insert_id;
};
$version = static function (string $label, int $curriculum, string $status) use ($db, $subjects): int {
    $stmt = $db->prepare('INSERT INTO curriculum_versions (curriculum_id, version_label, status) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $curriculum, $label, $status);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt = $db->prepare("INSERT INTO curriculum_version_subjects (curriculum_version_id, subject_id, year_level, semester) VALUES (?, ?, '1st Year', '1st Semester')");
    foreach ($subjects as $subject) { $stmt->bind_param('ii', $id, $subject); $stmt->execute(); }
    return $id;
};

$db->begin_transaction();
$pa = $program('UITEST-PA');
$pb = $program('UITEST-PB');
$ca = $curriculum('UITEST-CA', $pa);
$cc = $curriculum('UITEST-CC', $pa);
$cb = $curriculum('UITEST-CB', $pb);
$ids = ['PA' => $pa, 'CA' => $ca, 'VA' => $version('UITEST-A', $ca, 'draft'), 'VB' => $version('UITEST-B', $ca, 'draft'),
    'VC' => $version('UITEST-C', $cc, 'active'), 'PB' => $pb, 'CB' => $cb, 'VD' => $version('UITEST-D', $cb, 'active')];
$db->commit();

$lines = [];
foreach ($ids as $key => $id) $lines[] = "UITEST_FX_$key=$id";
file_put_contents(__DIR__ . '/../../.env.uitest', implode("\n", $lines) . "\n", FILE_APPEND);
echo "scenario fixtures created (UITEST-A, UITEST-B, UITEST-C, UITEST-D)\n";
