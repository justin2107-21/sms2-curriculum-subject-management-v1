<?php
// Read-only checks against the configured local database; never modifies academic records.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/../modules/curriculum/services/CurriculumVersionSubjectService.php';
require_once __DIR__ . '/../modules/curriculum/services/CurriculumSubjectRequirementService.php';
$config = csms_database_config();
if (!in_array($config['host'], ['localhost', '127.0.0.1'], true) || getenv('APP_ENV') === 'production') {
    throw new RuntimeException('Local non-production database required.');
}
$db = csms_database_connect($config);
$db->query('SET TRANSACTION READ ONLY');
$db->begin_transaction();
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
try {
    $auditBefore = (int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0];
    $mapping = new CurriculumVersionSubjectService($db);
    $requirements = new CurriculumSubjectRequirementService($db);
    $versions = $db->query('SELECT id FROM curriculum_versions ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $total = 0;
    $totalRelationships = 0;
    foreach ($versions as $version) {
        $id = (int)$version['id'];
        $expected = $db->query("SELECT COUNT(*) FROM curriculum_version_subjects WHERE curriculum_version_id = $id")->fetch_row()[0];
        $rows = [];
        $page = 1;
        do {
            $result = $mapping->listMappings(['curriculum_version_id' => $id, 'page' => $page, 'per_page' => 50]);
            $assert((int)$result['pagination']['total_records'] === (int)$expected, 'Version count must match stored placement count.');
            $rows = array_merge($rows, $result['mappings']);
            $page++;
        } while ($page <= $result['pagination']['total_pages']);
        $assert(count($rows) === (int)$expected, 'All version placements must load.');
        $total += count($rows);
        $slots = $db->query("SELECT COALESCE(year_level, '') AS year_level, COALESCE(semester, '') AS semester,
            COUNT(*) AS count, COALESCE(SUM(subjects.units), 0) AS units
            FROM curriculum_version_subjects AS cvs INNER JOIN subjects ON subjects.id = cvs.subject_id
            WHERE cvs.curriculum_version_id = $id GROUP BY cvs.year_level, cvs.semester")->fetch_all(MYSQLI_ASSOC);
        foreach ($slots as $slot) {
            $group = array_values(array_filter($rows, static fn($row) => (string)($row['year_level'] ?? '') === $slot['year_level'] && (string)($row['semester'] ?? '') === $slot['semester']));
            $assert(count($group) === (int)$slot['count'], 'Year/semester group count must match SQL.');
            $assert(abs(array_sum(array_column($group, 'units')) - (float)$slot['units']) < 0.001, 'Year/semester units must match SQL.');
        }
        $expectedRelationships = (int)$db->query("SELECT COUNT(*) FROM curriculum_subject_requirements WHERE curriculum_version_id = $id AND requirement_type = 'prerequisite'")->fetch_row()[0];
        $relationships = [];
        $page = 1;
        do {
            $result = $requirements->list(['curriculum_version_id' => $id, 'requirement_type' => 'prerequisite', 'page' => $page, 'per_page' => 50]);
            $assert((int)$result['pagination']['total_records'] === $expectedRelationships, 'Prerequisite count must match stored rules.');
            $relationships = array_merge($relationships, $result['relationships']);
            $page++;
        } while ($page <= $result['pagination']['total_pages']);
        $assert(count($relationships) === $expectedRelationships, 'All prerequisite pages must load without inferred rules.');
        $totalRelationships += count($relationships);
        foreach ($relationships as $relationship) {
            $assert($relationship['requirement_type'] === 'prerequisite' && (int)$relationship['curriculum_version_id'] === $id, 'Only selected-version prerequisites are returned.');
            $subjectIds = array_column($rows, 'subject_id');
            $assert(in_array($relationship['subject_id'], $subjectIds) && in_array($relationship['required_subject_id'], $subjectIds), 'Relationship endpoints must be mapped subjects.');
        }
    }
    $subjects = $db->query('SELECT DISTINCT subject_id FROM curriculum_version_subjects ORDER BY subject_id')->fetch_all(MYSQLI_ASSOC);
    foreach ($subjects as $subject) {
        $id = (int)$subject['subject_id'];
        $expected = (int)$db->query("SELECT COUNT(*) FROM curriculum_version_subjects WHERE subject_id = $id")->fetch_row()[0];
        $result = $mapping->listMappings(['subject_id' => $id, 'per_page' => 50]);
        $assert((int)$result['pagination']['total_records'] === $expected, 'Exact Subject Master usage count must match SQL.');
        foreach ($result['mappings'] as $row) {
            $assert((int)$row['subject_id'] === $id, 'Trace cannot contain a different Subject Master ID.');
            foreach (['program_code', 'curriculum_code', 'version_label', 'academic_program_id', 'curriculum_version_id'] as $field) {
                $assert(isset($row[$field]) && $row[$field] !== '', 'Trace and modal context must include ' . $field);
            }
        }
    }
    $assert($total > 0, 'At least one existing mapping is required for database verification.');
    $assert((int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0] === $auditBefore, 'Read checks must not write audit records.');
    echo "PASS: $checks read-only mapping checks across " . count($versions) . " versions, $total placements, and $totalRelationships stored prerequisites; no seed assumptions or academic writes.\n";
} finally {
    $db->rollback();
    $db->close();
}
