<?php
require_once __DIR__ . '/../modules/curriculum/services/AcademicSequenceValidator.php';
require_once __DIR__ . '/../modules/curriculum/services/PrerequisiteGraph.php';

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

    /** Counted version per curriculum: approved, then active, then draft with placements, then other draft; newest wins. */
    public static function countedVersions(array $versions): array
    {
        $picked = [];
        foreach ($versions as $version) {
            if (($version['status'] ?? '') === 'archived') continue;
            $curriculumId = (int)$version['curriculum_id'];
            $current = $picked[$curriculumId] ?? null;
            if ($current === null || self::versionRank($version) > self::versionRank($current)) $picked[$curriculumId] = $version;
        }
        ksort($picked);
        return $picked;
    }

    /** The strongest of several counted versions. */
    public static function bestVersion(array $versions): ?array
    {
        $best = null;
        foreach ($versions as $version) {
            if ($best === null || self::versionRank($version) > self::versionRank($best)) $best = $version;
        }
        return $best;
    }

    private static function versionRank(array $v): array
    {
        $state = match (true) {
            ($v['review_status'] ?? '') === 'approved' => 3,
            ($v['status'] ?? '') === 'active' => 2,
            (int)($v['placements'] ?? 0) > 0 => 1,
            default => 0,
        };
        return [$state, (string)($v['updated_at'] ?? ''), (int)$v['id']];
    }

    public static function isShs(?string $programType): bool
    {
        $type = strtolower(trim((string)$programType));
        return str_contains($type, 'senior high') || $type === 'shs' || str_contains($type, 'strand');
    }

    public const PIPELINE = ['draft' => 'Draft', 'active' => 'Active (not reviewed)', 'in_review' => 'In review', 'returned' => 'Returned', 'approved' => 'Approved', 'archived' => 'Archived'];

    public static function pipelineBucket(array $v): string
    {
        if (($v['status'] ?? '') === 'archived') return 'archived';
        return match ($v['review_status'] ?? '') {
            'approved' => 'approved',
            'under_review' => 'in_review',
            'returned' => 'returned',
            default => ($v['status'] ?? '') === 'active' ? 'active' : 'draft',
        };
    }

    public static function pipeline(array $programs, array $versions): array
    {
        $rows = []; $none = [];
        $byProgram = [];
        foreach ($versions as $v) $byProgram[(int)$v['program_id']][] = $v;
        foreach ($programs as $p) {
            $list = $byProgram[(int)$p['id']] ?? [];
            if ($list === []) { $none[] = (string)$p['program_code']; continue; }
            $counts = array_fill_keys(array_keys(self::PIPELINE), 0);
            foreach ($list as $v) $counts[self::pipelineBucket($v)]++;
            $rows[] = ['label' => (string)$p['program_code'], 'counts' => $counts];
        }
        if ($rows === [] && $none === []) return self::empty();
        return self::ok(['buckets' => self::PIPELINE, 'rows' => $rows, 'no_curriculum' => $none]);
    }

    /** A curriculum's own rule beats its program's. */
    public static function targetFor(array $rules, int $curriculumId, int $programId): ?array
    {
        $found = null;
        foreach ($rules as $rule) {
            if ((int)($rule['curriculum_id'] ?? 0) === $curriculumId) return ['value' => (float)$rule['numeric_value'], 'name' => (string)$rule['rule_name']];
            if ($found === null && (int)($rule['academic_program_id'] ?? 0) === $programId) $found = ['value' => (float)$rule['numeric_value'], 'name' => (string)$rule['rule_name']];
        }
        return $found;
    }

    public static function prerequisiteHealthRow(string $label, array $placements, array $edges): array
    {
        $review = PrerequisiteGraph::review($placements, $edges);
        $withPrerequisite = [];
        foreach ($edges as $e) $withPrerequisite[(int)$e['subject_id']] = true;
        $upper = 0; $covered = 0;
        foreach ($placements as $p) {
            $position = AcademicSequenceValidator::position($p);
            if ($position === null || $position <= 2) continue;
            $upper++;
            if (isset($withPrerequisite[(int)$p['subject_id']])) $covered++;
        }
        return ['label' => $label, 'placements' => count($placements), 'later_term' => count($review['sequencing']), 'circular' => count($review['cycles']),
            'upper_subjects' => $upper, 'upper_with_prerequisite' => $covered, 'percent' => $upper > 0 ? (int)round($covered / $upper * 100) : null];
    }

    /** Average days from each submit to its decision, or null when there are no decisions. */
    public static function averageDaysInReview(array $events): ?float
    {
        $open = []; $durations = [];
        foreach ($events as $e) {
            $id = (int)$e['version_id'];
            if ($e['action'] === 'submit') { $open[$id] = strtotime((string)$e['created_at']); continue; }
            if (isset($open[$id])) { $durations[] = (strtotime((string)$e['created_at']) - $open[$id]) / 86400; unset($open[$id]); }
        }
        return $durations === [] ? null : round(array_sum($durations) / count($durations), 1);
    }

    /**
     * Discrete counts per week or month. Series rows are {day, total}. Without a forced size, history under
     * 3 months uses weeks. Empty periods stay as real zeros between the first event and now.
     * @param array<string,array> $seriesRows label => rows
     */
    public static function buckets(array $seriesRows, ?string $force = null, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('today');
        $days = [];
        foreach ($seriesRows as $rows) foreach ($rows as $r) $days[] = (string)$r['day'];
        if ($days === []) return self::empty();
        $first = new DateTimeImmutable(min($days));
        $size = $force ?? ($first > $now->modify('-3 months') ? 'week' : 'month');
        $key = static fn(DateTimeImmutable $d): string => $size === 'week' ? $d->modify('monday this week')->format('Y-m-d') : $d->format('Y-m');
        $keys = [];
        $cursor = $size === 'week' ? $first->modify('monday this week') : $first->modify('first day of this month');
        while ($cursor <= $now) { $keys[] = $key($cursor); $cursor = $cursor->modify($size === 'week' ? '+7 days' : '+1 month'); }
        $keys = array_slice($keys, -12);
        $index = array_flip($keys);
        $series = [];
        foreach ($seriesRows as $label => $rows) {
            $values = array_fill(0, count($keys), 0);
            foreach ($rows as $r) {
                $k = $key(new DateTimeImmutable((string)$r['day']));
                if (isset($index[$k])) $values[$index[$k]] += (int)$r['total'];
            }
            $series[] = ['label' => $label, 'values' => $values];
        }
        return self::ok(['size' => $size, 'periods' => $keys, 'series' => $series]);
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
