<?php

require_once __DIR__ . '/../modules/curriculum/services/AcademicSequenceValidator.php';

/**
 * Pure shaping helpers for the dashboard (no database access, unit-tested in
 * tests/dashboard-shaping.php). Every chart is returned in one envelope:
 *   ['status' => 'ok'|'empty'|'unavailable', 'reason' => string, 'data' => array]
 * 'unavailable' means the backend cannot supply the data yet; 'empty' means the
 * data source exists but has no rows for the selection. Neither carries numbers.
 */
final class DashboardShaper
{
    public const OK = 'ok';
    public const EMPTY = 'empty';
    public const UNAVAILABLE = 'unavailable';

    public static function ok(array $data): array
    {
        return ['status' => self::OK, 'reason' => '', 'data' => $data];
    }

    public static function empty(string $reason = 'No data yet for this selection.'): array
    {
        return ['status' => self::EMPTY, 'reason' => $reason, 'data' => []];
    }

    public static function unavailable(string $reason): array
    {
        return ['status' => self::UNAVAILABLE, 'reason' => $reason, 'data' => []];
    }

    /**
     * Representative version per curriculum (approved rule): the most recently
     * updated 'active' version; otherwise the most recently updated non-archived
     * version; curricula with only archived versions are skipped.
     * @param array<int,array<string,mixed>> $versions rows with id, curriculum_id, status, updated_at
     * @return array<int,array<string,mixed>> keyed by curriculum_id
     */
    public static function representativeVersions(array $versions): array
    {
        $picked = [];
        foreach ($versions as $version) {
            $status = (string)($version['status'] ?? '');
            if ($status === 'archived') continue;
            $curriculumId = (int)$version['curriculum_id'];
            $current = $picked[$curriculumId] ?? null;
            if ($current === null || self::preferVersion($version, $current)) {
                $picked[$curriculumId] = $version;
            }
        }
        ksort($picked);
        return $picked;
    }

    private static function preferVersion(array $candidate, array $current): bool
    {
        $candidateActive = ($candidate['status'] ?? '') === 'active';
        $currentActive = ($current['status'] ?? '') === 'active';
        if ($candidateActive !== $currentActive) return $candidateActive;
        $byTime = strcmp((string)($candidate['updated_at'] ?? ''), (string)($current['updated_at'] ?? ''));
        return $byTime !== 0 ? $byTime > 0 : (int)$candidate['id'] > (int)$current['id'];
    }

    /** Canonical year label; raw value kept when it cannot be parsed. */
    public static function yearLabel(?string $raw): array
    {
        $value = trim((string)$raw);
        if ($value === '') return ['key' => 'zz-unspecified', 'label' => 'Unspecified', 'order' => PHP_INT_MAX];
        if (preg_match('/^grade\s*(11|12)$/i', $value, $m)) {
            return ['key' => 'g' . $m[1], 'label' => 'Grade ' . $m[1], 'order' => (int)$m[1] - 10];
        }
        $position = AcademicSequenceValidator::position(['year_level' => $value, 'semester' => '1']);
        if ($position !== null) {
            $year = intdiv($position - 1, 2) + 1;
            return ['key' => 'y' . $year, 'label' => self::ordinal($year) . ' Year', 'order' => $year];
        }
        return ['key' => 'raw:' . mb_strtolower($value), 'label' => $value, 'order' => PHP_INT_MAX - 1];
    }

    /** Canonical semester label; raw value kept when it cannot be parsed. */
    public static function semesterLabel(?string $raw): array
    {
        $value = trim((string)$raw);
        if ($value === '') return ['key' => 'zz-unspecified', 'label' => 'Unspecified', 'order' => PHP_INT_MAX];
        $position = AcademicSequenceValidator::position(['year_level' => '1', 'semester' => $value]);
        if ($position !== null) {
            return ['key' => 's' . $position, 'label' => self::ordinal($position) . ' Semester', 'order' => $position];
        }
        return ['key' => 'raw:' . mb_strtolower($value), 'label' => $value, 'order' => PHP_INT_MAX - 1];
    }

    private static function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
        return $n . $suffix;
    }

    /**
     * Term load for one version: years x semesters with subjects and units, using
     * normalized labels. 'merged' lists raw stored spellings that were combined.
     * @param array<int,array<string,mixed>> $placements rows with year_level, semester, units, is_required
     */
    public static function termLoad(array $placements): array
    {
        if ($placements === []) return self::empty('This version has no subject placements yet.');
        $years = [];
        $semesters = [];
        $cells = [];
        $merged = ['years' => [], 'semesters' => []];
        $required = 0;
        foreach ($placements as $row) {
            $year = self::yearLabel($row['year_level'] ?? null);
            $semester = self::semesterLabel($row['semester'] ?? null);
            $years[$year['key']] = $year;
            $semesters[$semester['key']] = $semester;
            $merged['years'][$year['label']][trim((string)($row['year_level'] ?? ''))] = true;
            $merged['semesters'][$semester['label']][trim((string)($row['semester'] ?? ''))] = true;
            $cell = &$cells[$year['key']][$semester['key']];
            $cell ??= ['subjects' => 0, 'units' => 0.0];
            $cell['subjects']++;
            $cell['units'] += (float)($row['units'] ?? 0);
            unset($cell);
            $required += (int)($row['is_required'] ?? 0);
        }
        $sort = static fn(array $a, array $b): int => [$a['order'], $a['label']] <=> [$b['order'], $b['label']];
        uasort($years, $sort);
        uasort($semesters, $sort);
        $series = [];
        foreach ($semesters as $sKey => $semester) {
            $points = [];
            foreach ($years as $yKey => $year) {
                $points[] = [
                    'subjects' => $cells[$yKey][$sKey]['subjects'] ?? 0,
                    'units' => round($cells[$yKey][$sKey]['units'] ?? 0, 2),
                ];
            }
            $series[] = ['label' => $semester['label'], 'points' => $points];
        }
        $mergedOut = [];
        foreach (['years', 'semesters'] as $axis) {
            foreach ($merged[$axis] as $label => $raw) {
                $spellings = array_keys($raw);
                if (count($spellings) > 1) $mergedOut[] = ['label' => $label, 'stored_values' => $spellings];
            }
        }
        return self::ok([
            'years' => array_values(array_map(static fn($y) => $y['label'], $years)),
            'series' => $series,
            'totals' => [
                'subjects' => count($placements),
                'units' => round(array_sum(array_map(static fn($r) => (float)($r['units'] ?? 0), $placements)), 2),
                'required' => $required,
                'optional' => count($placements) - $required,
            ],
            'merged_labels' => $mergedOut,
        ]);
    }

    /**
     * Histogram of prerequisite counts per placed subject (bins 0,1,2,3+) and the
     * five subjects with the most prerequisites.
     * @param array<int,array<string,mixed>> $rows subject_code, subject_title, prerequisite_count
     */
    public static function prerequisiteHistogram(array $rows): array
    {
        if ($rows === []) return self::empty('No subject placements for this selection.');
        $bins = ['0' => 0, '1' => 0, '2' => 0, '3+' => 0];
        foreach ($rows as $row) {
            $count = (int)$row['prerequisite_count'];
            $bins[$count >= 3 ? '3+' : (string)$count]++;
        }
        $withRules = array_values(array_filter($rows, static fn($r) => (int)$r['prerequisite_count'] > 0));
        usort($withRules, static fn($a, $b) => [(int)$b['prerequisite_count'], (string)$a['subject_code']]
            <=> [(int)$a['prerequisite_count'], (string)$b['subject_code']]);
        return self::ok([
            'bins' => array_map(static fn($label, $value) => ['label' => $label, 'subjects' => $value], array_keys($bins), $bins),
            'top' => array_map(static fn($r) => [
                'subject_code' => (string)$r['subject_code'],
                'subject_title' => (string)$r['subject_title'],
                'prerequisites' => (int)$r['prerequisite_count'],
            ], array_slice($withRules, 0, 5)),
            'subjects' => count($rows),
            'with_prerequisites' => count($withRules),
        ]);
    }

    /** Last N calendar months (oldest first) as 'YYYY-MM', ending with $now's month. */
    public static function months(int $count, ?DateTimeImmutable $now = null): array
    {
        $cursor = ($now ?? new DateTimeImmutable('now'))->modify('first day of this month');
        $months = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $months[] = $cursor->modify("-{$i} months")->format('Y-m');
        }
        return $months;
    }

    /**
     * Fill monthly series. $seriesRows: ['Versions created' => [['month'=>'2026-01','total'=>3], ...], ...]
     */
    public static function monthlySeries(array $seriesRows, array $months): array
    {
        $series = [];
        $grand = 0;
        foreach ($seriesRows as $label => $rows) {
            $byMonth = [];
            foreach ($rows as $row) $byMonth[(string)$row['month']] = (int)$row['total'];
            $values = array_map(static fn($m) => $byMonth[$m] ?? 0, $months);
            $grand += array_sum($values);
            $series[] = ['label' => $label, 'values' => $values];
        }
        if ($grand === 0) return self::empty('No activity recorded in the last ' . count($months) . ' months.');
        return self::ok(['months' => $months, 'series' => $series]);
    }

    private const ACTION_VERBS = [
        'CREATE' => 'Created', 'UPDATE' => 'Updated', 'ARCHIVE' => 'Archived', 'RESTORE' => 'Restored',
        'DELETE' => 'Deleted', 'ASSIGN' => 'Assigned', 'REMOVE' => 'Removed', 'CLONE' => 'Cloned',
        'RUN' => 'Ran', 'VALIDATE' => 'Validated', 'ACTIVATE' => 'Activated', 'EXPORT' => 'Exported',
    ];
    private const ENTITY_LINKS = [
        'academic_program' => 'modules/curriculum/pages/academic_programs.php',
        'curriculum' => 'modules/curriculum/pages/curricula.php',
        'curriculum_version' => 'modules/curriculum/pages/curricula.php',
        'curriculum_version_subject' => 'modules/curriculum/pages/subject_mapping.php',
        'subject' => 'modules/curriculum/pages/subjects.php',
        'curriculum_subject_requirement' => 'modules/curriculum/pages/prerequisite_configuration.php',
        'curriculum_elective_group' => 'modules/curriculum/pages/electives_manager.php',
        'grade_weighting_scheme' => 'modules/curriculum/pages/grade_weighting_setup.php',
        'compliance_validation_run' => 'modules/curriculum/pages/validation_history.php',
        'compliance_rule' => 'modules/curriculum/pages/compliance_rules.php',
        'compliance_reference' => 'modules/curriculum/pages/compliance_references.php',
        'subject_equivalency' => 'modules/curriculum/pages/equivalency_records.php',
    ];

    /**
     * Human wording for an audit row: "Created curriculum version" + stored description.
     * Never exposes "#47"-style internal ids.
     */
    public static function activityLabel(string $action, string $entityType): array
    {
        $parts = explode('_', strtoupper(trim($action)));
        $verb = null;
        foreach (array_reverse($parts) as $part) {
            if (isset(self::ACTION_VERBS[$part])) { $verb = self::ACTION_VERBS[$part]; break; }
        }
        $entity = trim(str_replace('_', ' ', strtolower($entityType)));
        $sentence = $verb !== null
            ? $verb . ($entity !== '' ? ' ' . $entity : '')
            : ucfirst(strtolower(str_replace('_', ' ', $action)));
        return ['label' => $sentence, 'link' => self::ENTITY_LINKS[strtolower($entityType)] ?? null];
    }

    /** "Next step" for a draft version, derived from real counts only. */
    public static function draftNextStep(int $placements, int $validationRuns): string
    {
        if ($placements === 0) return 'Add subject placements';
        if ($validationRuns === 0) return 'Run compliance validation';
        return 'Review and update';
    }
}
