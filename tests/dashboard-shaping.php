<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Database-free checks for dashboard/DashboardShaper.php (envelopes, label
// normalization, representative-version rule, histograms, monthly series).
require_once __DIR__ . '/../dashboard/DashboardShaper.php';
$checks = 0;
function shapeCheck(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $label); $checks++; }

// Envelopes never carry numbers unless ok.
foreach ([DashboardShaper::empty(), DashboardShaper::unavailable('reason')] as $envelope) {
    shapeCheck(array_keys($envelope) === ['status', 'reason', 'data'] && $envelope['data'] === [], 'Envelope shape and no data');
}
shapeCheck(DashboardShaper::unavailable('x')['status'] === 'unavailable' && DashboardShaper::empty()['reason'] === 'No data yet for this selection.', 'Envelope statuses');

// Label bug: '1st' and '1st Semester' (and '1st' / '1st Year') merge.
foreach (['1st', '1st Year', 'Year 1', '1', ' 1ST YEAR '] as $raw) shapeCheck(DashboardShaper::yearLabel($raw)['label'] === '1st Year', "Year label $raw");
foreach (['1st', '1st Semester', 'Semester 1', '1'] as $raw) shapeCheck(DashboardShaper::semesterLabel($raw)['label'] === '1st Semester', "Semester label $raw");
shapeCheck(DashboardShaper::yearLabel('2nd')['label'] === '2nd Year' && DashboardShaper::yearLabel('3rd Year')['label'] === '3rd Year', '2nd/3rd year');
shapeCheck(DashboardShaper::yearLabel('Grade 11')['label'] === 'Grade 11', 'SHS grade kept');
shapeCheck(DashboardShaper::yearLabel('Summer Bridge')['label'] === 'Summer Bridge', 'Unparseable raw value kept, not hidden');
shapeCheck(DashboardShaper::semesterLabel('')['label'] === 'Unspecified' && DashboardShaper::semesterLabel('Summer')['label'] === 'Summer', 'Blank and raw semester');

$load = DashboardShaper::termLoad([
    ['year_level' => '1st', 'semester' => '1st', 'units' => 3, 'is_required' => 1],
    ['year_level' => '1st Year', 'semester' => '1st Semester', 'units' => 3, 'is_required' => 1],
    ['year_level' => '1st Year', 'semester' => '2nd Semester', 'units' => 2, 'is_required' => 0],
    ['year_level' => '2nd Year', 'semester' => '1st Semester', 'units' => 3.5, 'is_required' => 1],
]);
shapeCheck($load['status'] === 'ok' && $load['data']['years'] === ['1st Year', '2nd Year'], 'Years merged and ordered');
shapeCheck(array_column($load['data']['series'], 'label') === ['1st Semester', '2nd Semester'], 'Semesters merged and ordered');
shapeCheck($load['data']['series'][0]['points'][0] === ['subjects' => 2, 'units' => 6.0], 'Merged cell counts both rows');
shapeCheck($load['data']['series'][1]['points'][1] === ['subjects' => 0, 'units' => 0.0], 'Missing cell is a real zero inside an ok chart');
shapeCheck($load['data']['totals'] === ['subjects' => 4, 'units' => 11.5, 'required' => 3, 'optional' => 1], 'Totals');
shapeCheck(count($load['data']['merged_labels']) === 2, 'Merged spellings reported for year and semester');
shapeCheck(DashboardShaper::termLoad([])['status'] === 'empty', 'No placements is empty, not zeros');

// Representative version rule.
$versions = [
    ['id' => 1, 'curriculum_id' => 10, 'status' => 'draft', 'updated_at' => '2026-05-01 00:00:00'],
    ['id' => 2, 'curriculum_id' => 10, 'status' => 'active', 'updated_at' => '2026-01-01 00:00:00'],
    ['id' => 3, 'curriculum_id' => 10, 'status' => 'active', 'updated_at' => '2026-03-01 00:00:00'],
    ['id' => 4, 'curriculum_id' => 20, 'status' => 'draft', 'updated_at' => '2026-02-01 00:00:00'],
    ['id' => 5, 'curriculum_id' => 20, 'status' => 'archived', 'updated_at' => '2026-09-01 00:00:00'],
    ['id' => 6, 'curriculum_id' => 30, 'status' => 'archived', 'updated_at' => '2026-09-01 00:00:00'],
];
$picked = DashboardShaper::representativeVersions($versions);
shapeCheck(array_keys($picked) === [10, 20], 'Archived-only curriculum skipped');
shapeCheck($picked[10]['id'] === 3, 'Latest active beats newer draft');
shapeCheck($picked[20]['id'] === 4, 'Non-archived fallback');

// Prerequisite histogram.
$hist = DashboardShaper::prerequisiteHistogram([
    ['subject_code' => 'A', 'subject_title' => 'A', 'prerequisite_count' => 0],
    ['subject_code' => 'B', 'subject_title' => 'B', 'prerequisite_count' => 1],
    ['subject_code' => 'C', 'subject_title' => 'C', 'prerequisite_count' => 4],
    ['subject_code' => 'D', 'subject_title' => 'D', 'prerequisite_count' => 3],
]);
shapeCheck(array_column($hist['data']['bins'], 'subjects') === [1, 1, 0, 2], 'Histogram bins 0/1/2/3+');
shapeCheck(array_column($hist['data']['top'], 'subject_code') === ['C', 'D', 'B'], 'Top list excludes zero and sorts by count');
shapeCheck(DashboardShaper::prerequisiteHistogram([])['status'] === 'empty', 'Histogram empty');

// Monthly series.
$months = DashboardShaper::months(3, new DateTimeImmutable('2026-11-15'));
shapeCheck($months === ['2026-09', '2026-10', '2026-11'], 'Month window');
$series = DashboardShaper::monthlySeries(['Versions created' => [['month' => '2026-10', 'total' => 2]]], $months);
shapeCheck($series['status'] === 'ok' && $series['data']['series'][0]['values'] === [0, 2, 0], 'Monthly fill');
shapeCheck(DashboardShaper::monthlySeries(['Versions created' => []], $months)['status'] === 'empty', 'No activity is empty');

// Activity wording and next steps.
$label = DashboardShaper::activityLabel('CURRICULUM_VERSION_CREATE', 'curriculum_version');
shapeCheck($label['label'] === 'Created curriculum version' && $label['link'] === 'modules/curriculum/pages/curricula.php', 'Human activity wording');
shapeCheck(!str_contains(DashboardShaper::activityLabel('SUBJECT_UPDATE', 'subject')['label'], '#'), 'No internal ids');
shapeCheck(DashboardShaper::activityLabel('ODD_THING', 'mystery')['label'] === 'Odd thing' && DashboardShaper::activityLabel('ODD_THING', 'mystery')['link'] === null, 'Unknown action fallback');
shapeCheck(DashboardShaper::draftNextStep(0, 0) === 'Add subject placements' && DashboardShaper::draftNextStep(5, 0) === 'Run compliance validation' && DashboardShaper::draftNextStep(5, 1) === 'Review and update', 'Draft next step');

// No hard-coded chart values in the dashboard data layer (no sample/demo arrays).
foreach (['DashboardShaper.php', 'DashboardAnalyticsService.php', 'DashboardAnalyticsRepository.php'] as $file) {
    $source = file_get_contents(__DIR__ . '/../dashboard/' . $file);
    shapeCheck(!preg_match('/\b(sample|demo|fake|mock|lorem)\b/i', $source), "No sample data markers in $file");
}

echo "PASS: $checks dashboard shaping checks (envelopes, label normalization, representative version, histogram, monthly series, activity wording); no database opened.\n";
