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

// Counted-version rule.
$versions = [
    ['id' => 1, 'curriculum_id' => 10, 'status' => 'draft', 'review_status' => 'not_started', 'placements' => 5, 'updated_at' => '2026-05-01 00:00:00'],
    ['id' => 2, 'curriculum_id' => 10, 'status' => 'active', 'review_status' => 'not_started', 'placements' => 1, 'updated_at' => '2026-01-01 00:00:00'],
    ['id' => 3, 'curriculum_id' => 10, 'status' => 'active', 'review_status' => 'not_started', 'placements' => 1, 'updated_at' => '2026-03-01 00:00:00'],
    ['id' => 4, 'curriculum_id' => 20, 'status' => 'draft', 'review_status' => 'not_started', 'placements' => 52, 'updated_at' => '2026-02-01 00:00:00'],
    ['id' => 7, 'curriculum_id' => 20, 'status' => 'draft', 'review_status' => 'not_started', 'placements' => 0, 'updated_at' => '2026-04-01 00:00:00'],
    ['id' => 5, 'curriculum_id' => 20, 'status' => 'archived', 'review_status' => 'approved', 'placements' => 9, 'updated_at' => '2026-09-01 00:00:00'],
    ['id' => 6, 'curriculum_id' => 30, 'status' => 'archived', 'review_status' => 'not_started', 'placements' => 9, 'updated_at' => '2026-09-01 00:00:00'],
    ['id' => 8, 'curriculum_id' => 40, 'status' => 'active', 'review_status' => 'not_started', 'placements' => 9, 'updated_at' => '2026-09-01 00:00:00'],
    ['id' => 9, 'curriculum_id' => 40, 'status' => 'draft', 'review_status' => 'approved', 'placements' => 2, 'updated_at' => '2026-01-01 00:00:00'],
];
$picked = DashboardShaper::countedVersions($versions);
shapeCheck(array_keys($picked) === [10, 20, 40], 'Archived-only curriculum skipped');
shapeCheck($picked[10]['id'] === 3, 'Latest active beats newer draft');
shapeCheck($picked[20]['id'] === 4, 'Draft with placements beats newer empty draft, archived approved ignored');
shapeCheck($picked[40]['id'] === 9, 'Approved beats active');

// Pipeline: six buckets, every version once.
$pipeline = DashboardShaper::pipeline([['id' => 1, 'program_code' => 'A'], ['id' => 2, 'program_code' => 'B']], [
    ['program_id' => 1, 'status' => 'active', 'review_status' => 'not_started'], ['program_id' => 1, 'status' => 'draft', 'review_status' => 'not_started'],
    ['program_id' => 1, 'status' => 'draft', 'review_status' => 'under_review'], ['program_id' => 1, 'status' => 'draft', 'review_status' => 'returned'],
    ['program_id' => 1, 'status' => 'draft', 'review_status' => 'approved'], ['program_id' => 1, 'status' => 'archived', 'review_status' => 'approved'],
]);
shapeCheck(array_values($pipeline['data']['rows'][0]['counts']) === [1, 1, 1, 1, 1, 1] && $pipeline['data']['no_curriculum'] === ['B'], 'Pipeline buckets and programs without a curriculum');

// Targets come only from rules.
$rules = [['rule_name' => 'P', 'numeric_value' => 100, 'academic_program_id' => 1, 'curriculum_id' => null], ['rule_name' => 'C', 'numeric_value' => 120, 'academic_program_id' => null, 'curriculum_id' => 5]];
shapeCheck(DashboardShaper::targetFor($rules, 5, 1)['value'] === 120.0 && DashboardShaper::targetFor($rules, 6, 1)['value'] === 100.0 && DashboardShaper::targetFor($rules, 6, 2) === null, 'Curriculum rule beats program rule; no rule means no target');

// Prerequisite health.
$health = DashboardShaper::prerequisiteHealthRow('X', [
    ['subject_id' => 1, 'year_level' => '1st Year', 'semester' => '1st Semester'], ['subject_id' => 2, 'year_level' => '2nd Year', 'semester' => '1st Semester'],
    ['subject_id' => 3, 'year_level' => '2nd Year', 'semester' => '2nd Semester'], ['subject_id' => 4, 'year_level' => '3rd Year', 'semester' => '1st Semester'],
], [['subject_id' => 2, 'required_subject_id' => 3], ['subject_id' => 4, 'required_subject_id' => 1]]);
shapeCheck($health['later_term'] === 1 && $health['circular'] === 0 && $health['upper_subjects'] === 3 && $health['upper_with_prerequisite'] === 2 && $health['percent'] === 67, 'Prerequisite health counts');

// Average days and discrete buckets.
shapeCheck(DashboardShaper::averageDaysInReview([['version_id' => 1, 'action' => 'submit', 'created_at' => '2026-01-01 00:00:00'], ['version_id' => 1, 'action' => 'return', 'created_at' => '2026-01-03 00:00:00']]) === 2.0, 'Average days');
shapeCheck(DashboardShaper::averageDaysInReview([['version_id' => 1, 'action' => 'submit', 'created_at' => '2026-01-01 00:00:00']]) === null, 'No decision means no average');
$now = new DateTimeImmutable('2026-11-05');
$weekly = DashboardShaper::buckets(['E' => [['day' => '2026-10-20', 'total' => 2], ['day' => '2026-11-04', 'total' => 1]]], null, $now);
shapeCheck($weekly['data']['size'] === 'week' && array_sum($weekly['data']['series'][0]['values']) === 3 && in_array(0, $weekly['data']['series'][0]['values'], true), 'Under 3 months uses weeks, empty week is a real zero');
shapeCheck(DashboardShaper::buckets(['E' => [['day' => '2026-01-20', 'total' => 2]]], null, $now)['data']['size'] === 'month', 'Longer history uses months');

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
