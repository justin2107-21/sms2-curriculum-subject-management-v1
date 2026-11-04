<?php

require_once __DIR__ . '/DashboardAnalyticsRepository.php';
require_once __DIR__ . '/DashboardShaper.php';
require_once __DIR__ . '/../modules/curriculum/validators/DomainExceptions.php';

/**
 * Builds the dashboard payload. Read-only. Each chart/list is an envelope from
 * DashboardShaper (status ok|empty|unavailable, reason, data) plus display meta.
 * Callers pass the user's permissions; anything the user may not see is omitted.
 */
final class DashboardAnalyticsService
{
    private const OUTCOME_SOURCE_KEY = 'CHED-CMO-25-2015-BSIT';
    private const OUTCOME_PROGRAM_CODE = 'BSIT';
    public const VERSION_RULE_NOTE = 'Overall charts count one version per curriculum: the most recently updated active version, otherwise the most recently updated non-archived version.';

    private DashboardAnalyticsRepository $repo;

    public function __construct(mysqli $db, private array $config = [])
    {
        $this->repo = new DashboardAnalyticsRepository($db);
    }

    /**
     * @param array<string,bool> $can permission => granted
     */
    public function build(int $userId, array $can, ?int $programId, ?int $versionId): array
    {
        $allow = static fn(string ...$permissions): bool => array_reduce($permissions, static fn($ok, $p) => $ok && !empty($can[$p]), true);
        if ($programId !== null && !$this->repo->programExists($programId)) {
            throw new DomainNotFoundException('Program or strand not found.');
        }

        $versions = $allow('curriculum.view') ? $this->repo->versions($programId) : [];
        $versions = array_values(array_filter($versions, static fn(array $v): bool => CurriculumReadScope::allows($v, $userId)));
        $representative = DashboardShaper::representativeVersions($versions);
        $repIds = array_map(static fn($v) => (int)$v['id'], array_values($representative));
        $selected = $programId === null ? null : $this->selectVersion($versions, $representative, $versionId);
        $since = (new DateTimeImmutable('first day of this month'))->modify('-11 months')->format('Y-m-d 00:00:00');
        $months = DashboardShaper::months(12);

        $charts = [];
        $lists = [];

        if ($allow('curriculum.view')) {
            $charts['version_status'] = $this->meta('Curriculum versions by status', 'doughnut', 'modules/curriculum/pages/curricula.php',
                $this->versionStatus($versions));
            $charts['units_by_category'] = $this->meta('Units by subject category', 'bar', 'modules/curriculum/pages/subjects.php',
                DashboardShaper::unavailable('Subjects do not have a category (GE, core, professional, elective, PE, NSTP) yet. This chart starts working when the subject category field is added.'));
            $charts['activity'] = $this->meta('Curriculum activity, last 12 months', 'line', 'modules/curriculum/pages/curricula.php',
                $this->activity($programId, $since, $months, $allow('compliance.view')));
            $charts['review_trend'] = $this->meta('Submitted and approved versions per month', 'line', 'modules/curriculum/pages/curricula.php',
                $this->reviewTrend($programId, $since, $months));
            if ($allow('program.view')) {
                $charts['units_per_program'] = $this->meta(
                    $programId === null ? 'Units and subjects per program' : 'Units and subjects per version',
                    'bar', 'modules/curriculum/pages/subject_mapping.php',
                    $this->unitsPerProgram($programId, $programId === null ? array_values($representative) : $versions));
            }
            $charts['term_load'] = $this->meta('Curriculum load by year and semester', 'bar', 'modules/curriculum/pages/subject_mapping.php',
                $selected === null
                    ? DashboardShaper::empty($programId === null ? 'Select a program or strand to see its term load.' : 'This program has no curriculum version yet.')
                    : DashboardShaper::termLoad($this->repo->placements((int)$selected['id'])));
            $charts['outcome_coverage'] = $this->meta('Graduate-outcome coverage', 'bar', 'modules/curriculum/pages/subject_mapping.php',
                $this->outcomeCoverage($selected, $programId));
            $charts['outcome_ipd'] = $this->meta('Outcome levels (Introduced / Practised / Demonstrated)', 'radar', 'modules/curriculum/pages/subject_mapping.php',
                DashboardShaper::unavailable('Outcome mappings record that a subject supports an outcome, but not the I/P/D level. This chart starts working when the mapping level field is added.'));
            $charts['required_optional'] = $this->meta('Required vs optional placements', 'doughnut', 'modules/curriculum/pages/subject_mapping.php',
                $selected === null ? DashboardShaper::empty('Select a program or strand to see this version breakdown.') : $this->requiredOptional((int)$selected['id']));
        }
        if ($allow('curriculum.view', 'prerequisite.view')) {
            $ids = $selected !== null ? [(int)$selected['id']] : $repIds;
            $charts['prerequisites'] = $this->meta('Prerequisites per subject', 'bar', 'modules/curriculum/pages/prerequisite_configuration.php',
                DashboardShaper::prerequisiteHistogram($this->repo->prerequisiteCounts($ids)));
        }
        if ($allow('curriculum.view', 'compliance.view')) {
            $charts['compliance'] = $this->meta('Compliance results (latest run)', 'bar', 'modules/curriculum/pages/validate_curriculum.php',
                $this->compliance($programId === null ? array_values($representative) : $versions, $programId === null));
            $lists['compliance_failures'] = $this->meta('Compliance failures', 'list', 'modules/curriculum/pages/validation_history.php',
                $this->listEnvelope($this->repo->complianceFailures($programId), 'No failing compliance runs.'));
        }
        if ($allow('curriculum.view', 'grade_weighting.view')) {
            $charts['grade_weighting'] = $this->meta('Grade weighting status', 'progress', 'modules/curriculum/pages/grade_weighting_setup.php',
                $selected === null ? DashboardShaper::empty('Select a program or strand to see grade weighting.') : $this->gradeWeighting((int)$selected['id']));
        }

        if ($allow('curriculum.view', 'curriculum.version.create')) {
            $lists['my_drafts'] = $this->meta('My draft versions', 'list', 'modules/curriculum/pages/curricula.php', $this->myDrafts($userId, $programId));
        }
        if ($allow('curriculum.view')) {
            $lists['review_queue'] = $this->meta('Awaiting my review', 'list', 'modules/curriculum/pages/curricula.php', $this->reviewQueue($userId, $programId));
            $lists['active_curricula'] = $this->meta('Active curricula', 'list', 'modules/curriculum/pages/curricula.php',
                $this->listEnvelope($this->repo->activeCurricula($programId), 'No active curriculum versions yet.'));
            if (!$allow('curriculum.update') && !$allow('curriculum.version.create')) {
                $lists['my_subjects'] = $this->meta('Approved curricula for my subjects', 'list', 'modules/curriculum/pages/subjects.php',
                    DashboardShaper::unavailable('Faculty members are not linked to the subjects they teach yet. This list starts working when faculty-subject assignment is added.'));
            }
        }
        if ($allow('audit.view')) {
            $lists['recent_activity'] = $this->meta('Recent activity', 'list', 'admin/audit_logs.php', $this->recentActivity());
        }

        if (empty($this->config['dashboard.show_pending_charts'] ?? true)) {
            $charts = array_filter($charts, static fn($c) => $c['status'] !== DashboardShaper::UNAVAILABLE);
            $lists = array_filter($lists, static fn($c) => $c['status'] !== DashboardShaper::UNAVAILABLE);
        }

        return [
            'filter' => [
                'program_id' => $programId,
                'version_id' => $selected !== null ? (int)$selected['id'] : null,
                'versions' => $programId === null ? [] : array_map(static fn($v) => [
                    'id' => (int)$v['id'], 'label' => $v['version_label'], 'status' => $v['status'], 'curriculum_code' => $v['curriculum_code'],
                ], $versions),
            ],
            'stats' => $this->stats($can, $programId, $versions, $selected),
            'charts' => $charts,
            'lists' => $lists,
            'version_rule' => self::VERSION_RULE_NOTE,
            'show_pending_charts' => (bool)($this->config['dashboard.show_pending_charts'] ?? true),
        ];
    }

    public function programs(): array
    {
        $programs = array_map(static function (array $p): array {
            $type = strtolower(trim((string)($p['program_type'] ?? '')));
            $isShs = str_contains($type, 'senior high') || $type === 'shs' || str_contains($type, 'strand');
            return [
                'id' => (int)$p['id'], 'code' => (string)$p['program_code'], 'name' => (string)$p['program_name'],
                'group' => $isShs ? 'Senior High School strands' : 'College programs', 'curricula' => (int)$p['curricula'],
                'level' => $isShs ? 'shs' : 'college', 'inactive' => strtolower((string)($p['status'] ?? 'active')) !== 'active',
            ];
        }, $this->repo->programs());
        // Inactive programs and strands go last; code order is kept otherwise.
        usort($programs, static fn(array $a, array $b): int => [$a['inactive'], $a['code']] <=> [$b['inactive'], $b['code']]);
        return $programs;
    }

    private function meta(string $title, string $type, string $module, array $envelope): array
    {
        return ['title' => $title, 'type' => $type, 'module' => $module] + $envelope;
    }

    private function selectVersion(array $versions, array $representative, ?int $versionId): ?array
    {
        if ($versionId !== null) {
            foreach ($versions as $v) {
                if ((int)$v['id'] === $versionId) return $v;
            }
            throw new DomainValidationException('The selected version does not belong to this program or strand.');
        }
        if ($representative === []) return null;
        $byUpdate = array_values($representative);
        usort($byUpdate, static fn($a, $b) => [($b['status'] === 'active'), (string)$b['updated_at'], (int)$b['id']]
            <=> [($a['status'] === 'active'), (string)$a['updated_at'], (int)$a['id']]);
        return $byUpdate[0];
    }

    private function versionStatus(array $versions): array
    {
        if ($versions === []) return DashboardShaper::empty();
        $status = ['draft' => 0, 'active' => 0, 'archived' => 0];
        $review = ['not_started' => 0, 'draft' => 0, 'under_review' => 0, 'returned' => 0, 'approved' => 0];
        foreach ($versions as $v) {
            $s = (string)$v['status'];
            $status[$s] = ($status[$s] ?? 0) + 1;
            $r = (string)$v['review_status'];
            $review[$r] = ($review[$r] ?? 0) + 1;
        }
        $reviewInUse = count($versions) - $review['not_started'] > 0;
        return DashboardShaper::ok([
            'segments' => array_map(static fn($k, $n) => ['key' => $k, 'label' => ucfirst($k), 'value' => $n], array_keys($status), $status),
            'review' => $reviewInUse
                ? array_map(static fn($k, $n) => ['key' => $k, 'label' => ucfirst(str_replace('_', ' ', $k)), 'value' => $n], array_keys($review), $review)
                : null,
            'total' => count($versions),
        ]);
    }

    private function unitsPerProgram(?int $programId, array $versions): array
    {
        if ($versions === []) return DashboardShaper::empty();
        $totals = [];
        foreach ($this->repo->placementTotals(array_map(static fn($v) => (int)$v['id'], $versions)) as $row) {
            $totals[(int)$row['version_id']] = $row;
        }
        $bars = [];
        foreach ($versions as $v) {
            $t = $totals[(int)$v['id']] ?? null;
            if ($t === null) continue;
            $key = $programId === null ? (string)$v['program_code'] : (string)$v['version_label'] . ' (' . $v['curriculum_code'] . ')';
            $bars[$key] ??= ['label' => $key, 'required_units' => 0.0, 'optional_units' => 0.0, 'subjects' => 0];
            $bars[$key]['required_units'] += (float)$t['required_units'];
            $bars[$key]['optional_units'] += (float)$t['optional_units'];
            $bars[$key]['subjects'] += (int)$t['subjects'];
        }
        if ($bars === []) return DashboardShaper::empty('No subject placements yet for this selection.');
        return DashboardShaper::ok(['bars' => array_values(array_map(static function ($b) {
            $b['required_units'] = round($b['required_units'], 2);
            $b['optional_units'] = round($b['optional_units'], 2);
            return $b;
        }, $bars))]);
    }

    private function outcomeCoverage(?array $selected, ?int $programId): array
    {
        if ($programId === null) return DashboardShaper::empty('Select a program or strand: graduate outcomes differ by program.');
        if ($selected === null) return DashboardShaper::empty('This program has no curriculum version yet.');
        if (strtoupper((string)$selected['program_code']) !== self::OUTCOME_PROGRAM_CODE) {
            return DashboardShaper::unavailable('Graduate outcomes are loaded only for BSIT (CHED CMO 25, s. 2015). Outcomes for this program have not been added yet.');
        }
        $rows = $this->repo->outcomeCoverage((int)$selected['id'], self::OUTCOME_SOURCE_KEY);
        if ($rows === []) return DashboardShaper::unavailable('The BSIT graduate-outcome catalog has not been loaded yet.');
        $mapped = array_sum(array_map(static fn($r) => (int)$r['mapped_subjects'], $rows));
        if ($mapped === 0) return DashboardShaper::empty('No subjects are mapped to graduate outcomes for this version yet.');
        return DashboardShaper::ok(['outcomes' => array_map(static fn($r) => [
            'code' => (string)$r['outcome_code'], 'text' => (string)$r['outcome_text'],
            'mapped_subjects' => (int)$r['mapped_subjects'], 'assessment_state' => $r['assessment_state'] ?: null,
        ], $rows)]);
    }

    private function activity(?int $programId, string $since, array $months, bool $withRuns): array
    {
        $series = [
            'Versions created' => $this->repo->monthly('versions', $programId, $since),
            'Placements added' => $this->repo->monthly('placements', $programId, $since),
        ];
        if ($withRuns) $series['Compliance runs'] = $this->repo->monthly('runs', $programId, $since);
        return DashboardShaper::monthlySeries($series, $months);
    }

    private function reviewWorkflowMissing(): bool
    {
        $usage = $this->repo->reviewWorkflowUsage();
        return ($usage['events'] ?? 0) === 0 && ($usage['versions_in_workflow'] ?? 0) === 0 && ($usage['personnel'] ?? 0) === 0;
    }

    private function reviewTrend(?int $programId, string $since, array $months): array
    {
        if ($this->reviewWorkflowMissing()) {
            return DashboardShaper::unavailable('The curriculum review workflow (submit, return, approve) is not in use yet, so there are no review events to chart.');
        }
        $byAction = ['submit' => [], 'approve' => [], 'return' => []];
        foreach ($this->repo->reviewEventsMonthly($programId, $since) as $row) {
            $byAction[(string)$row['action']][] = $row;
        }
        return DashboardShaper::monthlySeries([
            'Submitted' => $byAction['submit'], 'Approved' => $byAction['approve'], 'Returned' => $byAction['return'],
        ], $months);
    }

    private function reviewQueue(int $userId, ?int $programId): array
    {
        if ($this->reviewWorkflowMissing()) {
            return DashboardShaper::unavailable('Reviewers are not assigned to curriculum versions yet and the review workflow is not in use, so there is no review queue.');
        }
        return $this->listEnvelope(array_map(static fn($r) => [
            'id' => (int)$r['id'], 'label' => $r['version_label'], 'context' => $r['program_code'] . ' · ' . $r['curriculum_code'],
            'responsibility' => $r['responsibility'], 'updated_at' => $r['updated_at'],
        ], $this->repo->reviewQueue($userId, $programId)), 'Nothing is waiting for your review.');
    }

    private function compliance(array $versions, bool $perProgram): array
    {
        if ($versions === []) return DashboardShaper::empty();
        $runs = [];
        foreach ($this->repo->latestComplianceRuns(array_map(static fn($v) => (int)$v['id'], $versions)) as $r) {
            $runs[(int)$r['version_id']] = $r;
        }
        if ($runs === []) return DashboardShaper::empty('No compliance validation has been run for this selection yet.');
        $bars = [];
        foreach ($versions as $v) {
            $label = $perProgram ? $v['program_code'] . ' · ' . $v['curriculum_code'] : $v['version_label'] . ' (' . $v['curriculum_code'] . ')';
            $r = $runs[(int)$v['id']] ?? null;
            $bars[] = $r === null
                ? ['label' => $label, 'validated' => false]
                : ['label' => $label, 'validated' => true, 'pass' => (int)$r['passed_count'], 'fail' => (int)$r['failed_count'],
                   'not_evaluated' => (int)$r['not_evaluated_count'], 'run_at' => $r['created_at']];
        }
        return DashboardShaper::ok(['bars' => $bars]);
    }

    private function requiredOptional(int $versionId): array
    {
        $totals = $this->repo->placementTotals([$versionId])[0] ?? null;
        if ($totals === null || (int)$totals['subjects'] === 0) return DashboardShaper::empty('This version has no subject placements yet.');
        $required = (int)$totals['required_subjects'];
        return DashboardShaper::ok(['required' => $required, 'optional' => (int)$totals['subjects'] - $required, 'total' => (int)$totals['subjects']]);
    }

    private function gradeWeighting(int $versionId): array
    {
        $w = array_map('intval', $this->repo->gradeWeighting($versionId));
        if ($w['placements'] === 0) return DashboardShaper::empty('This version has no subject placements yet.');
        return DashboardShaper::ok(['placements' => $w['placements'], 'configured' => $w['configured'], 'complete' => $w['complete']]);
    }

    private function myDrafts(int $userId, ?int $programId): array
    {
        return $this->listEnvelope(array_map(static fn($r) => [
            'id' => (int)$r['id'], 'label' => $r['version_label'], 'context' => $r['program_code'] . ' · ' . $r['curriculum_code'],
            'placements' => (int)$r['placements'], 'runs' => (int)$r['runs'], 'updated_at' => $r['updated_at'],
            'next_step' => DashboardShaper::draftNextStep((int)$r['placements'], (int)$r['runs']),
        ], $this->repo->myDrafts($userId, $programId)), 'You have no draft versions.');
    }

    private function recentActivity(): array
    {
        return $this->listEnvelope(array_map(static function ($r) {
            $name = trim(trim((string)($r['first_name'] ?? '')) . ' ' . trim((string)($r['last_name'] ?? '')));
            $label = DashboardShaper::activityLabel((string)$r['action'], (string)$r['entity_type']);
            return [
                'label' => $label['label'], 'description' => (string)$r['description'], 'link' => $label['link'],
                'user' => $name !== '' ? $name : ((string)($r['username'] ?? '') ?: 'System'), 'created_at' => $r['created_at'],
            ];
        }, $this->repo->recentActivity(5)), 'No recent curriculum activity.');
    }

    private function listEnvelope(array $rows, string $emptyReason): array
    {
        return $rows === [] ? DashboardShaper::empty($emptyReason) : DashboardShaper::ok(['items' => $rows]);
    }

    private function stats(array $can, ?int $programId, array $versions, ?array $selected): array
    {
        $counts = $this->repo->overallCounts($programId);
        $stats = [];
        if ($programId === null) {
            if (!empty($can['program.view'])) $stats[] = ['key' => 'programs', 'label' => 'Programs / strands', 'value' => $counts['programs'], 'module' => 'modules/curriculum/pages/academic_programs.php'];
            if (!empty($can['curriculum.view'])) $stats[] = ['key' => 'versions', 'label' => 'Curriculum versions', 'value' => $counts['versions'], 'detail' => $counts['active_versions'] . ' active', 'module' => 'modules/curriculum/pages/curricula.php'];
            if (!empty($can['subject.view'])) $stats[] = ['key' => 'subjects', 'label' => 'Subjects', 'value' => $counts['subjects'], 'module' => 'modules/curriculum/pages/subjects.php'];
        } else {
            if (!empty($can['curriculum.view'])) {
                $stats[] = ['key' => 'versions', 'label' => 'Curricula / versions', 'value' => $counts['curricula'], 'detail' => $counts['versions'] . ' versions · ' . $counts['active_versions'] . ' active', 'module' => 'modules/curriculum/pages/curricula.php'];
                $totals = $selected === null ? null : ($this->repo->placementTotals([(int)$selected['id']])[0] ?? null);
                $stats[] = ['key' => 'placements', 'label' => 'Placed subjects', 'value' => $totals === null ? 0 : (int)$totals['subjects'], 'detail' => $selected === null ? 'No version yet' : 'Version ' . $selected['version_label'] . ' (' . $selected['curriculum_code'] . ')', 'module' => 'modules/curriculum/pages/subject_mapping.php'];
                $stats[] = ['key' => 'units', 'label' => 'Total units', 'value' => $totals === null ? 0 : round((float)$totals['required_units'] + (float)$totals['optional_units'], 2), 'detail' => $selected === null ? '' : 'Version ' . $selected['version_label'] . ' (' . $selected['curriculum_code'] . ')', 'module' => 'modules/curriculum/pages/subject_mapping.php'];
            }
        }
        if (!empty($can['curriculum.view'])) {
            $failing = !empty($can['compliance.view']) ? $this->repo->complianceFailureCount($programId) : 0;
            $byCurriculum = [];
            foreach ($versions as $v) $byCurriculum[(int)$v['curriculum_id']][] = (string)$v['status'];
            $noActive = count(array_filter($byCurriculum, static fn($statuses) => !in_array('active', $statuses, true)));
            $parts = [];
            if (!empty($can['compliance.view'])) $parts[] = $failing . ' failing compliance';
            $parts[] = $noActive . ' curricula without an active version';
            $stats[] = ['key' => 'attention', 'label' => 'Needs attention', 'value' => $failing + $noActive, 'detail' => implode(' · ', $parts), 'module' => 'modules/curriculum/pages/curricula.php'];
        }
        return array_slice($stats, 0, 4);
    }
}
