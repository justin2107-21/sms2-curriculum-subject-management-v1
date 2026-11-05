<?php

require_once __DIR__ . '/DashboardAnalyticsRepository.php';
require_once __DIR__ . '/DashboardShaper.php';
require_once __DIR__ . '/../modules/curriculum/validators/DomainExceptions.php';

/**
 * Builds one role's dashboard. Read-only. The profile comes from the user's permissions; only that
 * profile's queries run and only its cards are returned, and each card also checks its own permission.
 */
final class DashboardAnalyticsService
{
    public const PERMISSIONS = ['rbac.manage', 'user.manage', 'audit.view', 'program.view', 'curriculum.view', 'subject.view',
        'prerequisite.view', 'compliance.view', 'grade_weighting.view', 'curriculum.update', 'curriculum.review.submit',
        'curriculum.review.decide', 'curriculum_export.official', 'equivalency.manage'];
    public const VERSION_RULE_NOTE = 'Each curriculum counts one version: approved, otherwise active, otherwise the latest draft with subjects.';
    private const OUTCOME_SOURCE_KEY = 'CHED-CMO-25-2015-BSIT';
    private const OUTCOME_PROGRAM_CODE = 'BSIT';
    private const CURRICULA = 'modules/curriculum/pages/curricula.php';
    private const MAPPING = 'modules/curriculum/pages/subject_mapping.php';
    private const PROGRAMS = 'modules/curriculum/pages/academic_programs.php';
    private const VALIDATION = 'modules/curriculum/pages/validate_curriculum.php';
    /** Long item lists show this many rows; the rest is behind the module's own page. */
    public const TOP = 10;

    private DashboardAnalyticsRepository $repo;
    private array $can = [];
    private int $userId = 0;
    private ?int $programId = null;
    /** Versions this user may read in the current filter. */
    private array $versions = [];
    private array $counted = [];

    public function __construct(mysqli $db, private array $config = [])
    {
        $this->repo = new DashboardAnalyticsRepository($db);
    }

    /** First match wins. */
    public static function profile(array $can): string
    {
        foreach (['rbac.manage' => 'super_admin', 'user.manage' => 'admin', 'curriculum.review.decide' => 'acad_office',
            'curriculum.review.submit' => 'staff', 'curriculum.view' => 'acad_faculty'] as $permission => $profile) {
            if (!empty($can[$permission])) return $profile;
        }
        return 'none';
    }

    /** @param array<string,bool> $can permission => granted */
    public function build(int $userId, array $can, ?int $programId, ?int $versionId): array
    {
        $this->can = $can;
        $this->userId = $userId;
        $this->programId = $programId;
        if ($programId !== null && !$this->repo->programExists($programId)) {
            throw new DomainNotFoundException('Program or strand not found.');
        }
        $profile = self::profile($can);
        // Super Admin has one program-scoped card (the pipeline), so the filter reaches only that card: every other
        // figure, including Awaiting review, counts all programs. Other profiles apply the filter to their whole page.
        $scopeProgram = $profile === 'super_admin' ? null : $programId;
        $this->versions = $this->allow('curriculum.view') ? array_values(array_filter($this->repo->versions($scopeProgram),
            static fn(array $v): bool => CurriculumReadScope::allows($v, $userId))) : [];
        $this->counted = array_values(DashboardShaper::countedVersions($this->versions));

        $usesVersion = in_array($profile, ['staff', 'acad_faculty'], true);
        $selected = $usesVersion ? $this->selectVersion($versionId) : null;
        $cards = match ($profile) {
            'super_admin' => $this->superAdmin(),
            'admin' => $this->admin(),
            'staff' => $this->staff($selected),
            'acad_office' => $this->acadOffice(),
            'acad_faculty' => $this->acadFaculty($selected),
            default => ['stats' => [], 'charts' => [], 'lists' => []],
        };
        if (empty($this->config['dashboard.show_pending_charts'] ?? true)) {
            foreach (['charts', 'lists'] as $group) {
                $cards[$group] = array_filter($cards[$group], static fn($c) => $c['status'] !== DashboardShaper::UNAVAILABLE);
            }
        }
        return [
            'profile' => $profile,
            'filter' => [
                'program_id' => $programId,
                'version_id' => $selected !== null ? (int)$selected['id'] : null,
                'versions' => $usesVersion && $programId !== null ? array_map(static fn($v) => [
                    'id' => (int)$v['id'], 'label' => $v['version_label'], 'status' => $v['status'], 'curriculum_code' => $v['curriculum_code'],
                ], $this->versions) : [],
            ],
            'stats' => array_slice($cards['stats'], 0, 4),
            'charts' => $cards['charts'],
            'lists' => $cards['lists'],
            'version_rule' => isset($cards['charts']['completeness']) || isset($cards['charts']['prerequisite_health']) ? self::VERSION_RULE_NOTE : '',
            'show_pending_charts' => (bool)($this->config['dashboard.show_pending_charts'] ?? true),
        ];
    }

    public function programs(): array
    {
        $programs = array_map(static function (array $p): array {
            $isShs = DashboardShaper::isShs($p['program_type'] ?? null);
            return [
                'id' => (int)$p['id'], 'code' => (string)$p['program_code'], 'name' => (string)$p['program_name'],
                'group' => $isShs ? 'Senior High School strands' : 'College programs', 'curricula' => (int)$p['curricula'],
                'level' => $isShs ? 'shs' : 'college', 'inactive' => strtolower((string)($p['status'] ?? 'active')) !== 'active',
            ];
        }, $this->repo->programs());
        usort($programs, static fn(array $a, array $b): int => [$a['inactive'], $a['code']] <=> [$b['inactive'], $b['code']]);
        return $programs;
    }

    private function allow(string ...$permissions): bool
    {
        foreach ($permissions as $permission) if (empty($this->can[$permission])) return false;
        return true;
    }

    // ---- Role card sets ----

    private function superAdmin(): array
    {
        $accounts = $this->allow('user.manage') ? $this->repo->accountCounts() : ['active' => 0, 'must_change' => 0];
        $stats = [
            $this->stat('active_accounts', 'Active accounts', $accounts['active'], 'admin/users.php'),
            $this->stat('password_change', 'Need password change', $accounts['must_change'], 'admin/users.php'),
            $this->stat('audit_7d', 'Audit events, 7 days', $this->allow('audit.view') ? $this->repo->auditCountSince($this->daysAgo(7)) : 0, 'admin/audit_logs.php'),
            $this->stat('awaiting', 'Awaiting review', $this->countReview('under_review'), self::CURRICULA),
        ];
        $roles = $this->allow('user.manage') ? array_map(static fn($r) => ['label' => $r['display_name'], 'value' => (int)$r['accounts']], $this->repo->accountsByRole()) : [];
        $charts = [
            'accounts_by_role' => $this->meta('Accounts by role', 'donut', 'admin/users.php', 'Active accounts in each current role.',
                $this->donut($roles, 'No active accounts.')),
            'audit_weekly' => $this->meta('Audit events per week', 'bar', 'admin/audit_logs.php', 'All logged events, last 12 weeks.',
                $this->allow('audit.view') ? DashboardShaper::buckets(['Events' => $this->repo->auditPerDay($this->daysAgo(90))], 'week') : DashboardShaper::empty()),
            'pipeline' => $this->pipeline(true),
        ];
        $lists = [
            'rbac_changes' => $this->meta('Recent role and permission changes', 'list', 'admin/audit_logs.php', '', $this->listOf(array_map(fn($r) => [
                'primary' => $r['action'] === 'RBAC_ROLE_ASSIGNMENT' ? 'Role assigned' : 'Permissions changed',
                'secondary' => $r['description'] . ' · ' . $this->person($r), 'meta' => $r['created_at'],
            ], $this->repo->rbacChanges(5)), 'No recent changes.')),
            'password_accounts' => $this->meta('Accounts needing password change', 'list', 'admin/users.php', '', $this->listOf(array_map(fn($r) => [
                'primary' => $r['username'], 'secondary' => $this->person($r), 'meta' => '',
            ], $this->repo->accountsNeedingPassword(5)), 'None.')),
        ];
        return compact('stats', 'charts', 'lists');
    }

    private function admin(): array
    {
        $programs = $this->repo->programs();
        $active = count(array_filter($programs, static fn($p) => strtolower((string)$p['status']) === 'active'));
        $counts = $this->repo->overallCounts($this->programId);
        $stats = [
            $this->stat('programs', 'Active programs', $active, self::PROGRAMS),
            $this->stat('curricula', 'Curricula', $counts['curricula'], self::CURRICULA),
            $this->stat('approved', 'Approved versions', $this->countReview('approved'), self::CURRICULA),
            $this->stat('awaiting', 'Awaiting review', $this->countReview('under_review'), self::CURRICULA),
        ];
        // "No curriculum yet" is stated once on this dashboard: in the Programs with no curriculum list.
        $charts = ['pipeline' => $this->pipeline(false), 'completeness' => $this->completeness()];
        $charts['programs_by_level'] = $this->meta('Programs by level', 'donut', self::PROGRAMS, 'Active programs and strands.', $this->programsByLevel($programs));
        $lists = [
            'recently_approved' => $this->approvedList('Recently approved curricula'),
            'export_log' => $this->meta('Official exports', 'list', 'modules/curriculum/pages/curriculum_export.php', '',
                $this->allow('audit.view', 'curriculum_export.official') ? $this->listOf(array_map(fn($r) => [
                    'primary' => $r['program_code'] . ' · ' . $r['curriculum_code'] . ' · ' . ($r['stored_label'] ?: $r['version_label']),
                    'secondary' => 'Exported by ' . $this->person($r), 'meta' => $r['created_at'],
                ], $this->repo->officialExports($this->programId, 5)), 'No official exports yet.') : DashboardShaper::empty()),
            'no_curriculum' => $this->meta('Programs with no curriculum', 'list', self::PROGRAMS, '', $this->listOf(array_map(static fn($p) => [
                'primary' => $p['program_code'], 'secondary' => $p['program_name'], 'meta' => strtolower((string)$p['status']) === 'active' ? '' : 'Inactive',
            ], array_values(array_filter($programs, fn($p) => (int)$p['curricula'] === 0 && ($this->programId === null || (int)$p['id'] === $this->programId)))), 'All programs have a curriculum.')),
        ];
        return compact('stats', 'charts', 'lists');
    }

    private function staff(?array $selected): array
    {
        $mine = $this->repo->myVersions($this->userId, $this->programId);
        $drafts = array_values(array_filter($mine, static fn($v) => $v['status'] === 'draft' && !in_array($v['review_status'], ['under_review', 'approved', 'returned'], true)));
        $returned = array_values(array_filter($mine, static fn($v) => $v['review_status'] === 'returned'));
        $waiting = array_filter($mine, static fn($v) => $v['review_status'] === 'under_review');
        $failing = $this->allow('compliance.view') ? array_values(array_filter($mine, static fn($v) => (int)$v['failed_count'] > 0)) : [];
        $stats = [
            $this->stat('my_drafts', 'My drafts', count($drafts), self::CURRICULA),
            $this->stat('returned', 'Returned to me', count($returned), self::CURRICULA),
            $this->stat('waiting', 'Awaiting decision', count($waiting), self::CURRICULA),
            $this->stat('failing', 'Failing checks', count($failing), 'modules/curriculum/pages/validation_history.php'),
        ];
        $charts = ['term_load' => $this->termLoad($selected), 'prerequisite_health' => $this->prerequisiteHealth(),
            'compliance_working' => $this->complianceWorking($selected)];
        $charts['units_by_category'] = $this->unitsByCategory();

        $attention = [];
        foreach ($failing as $v) {
            $attention[] = ['primary' => $this->versionName($v), 'secondary' => (int)$v['failed_count'] . ' failed validator checks', 'meta' => ''];
        }
        if ($this->allow('grade_weighting.view')) {
            $ids = [];
            foreach ($mine as $v) if ($v['status'] !== 'archived' && (int)$v['placements'] > 0) $ids[(int)$v['id']] = $v;
            $weights = $this->repo->gradeWeightingForVersions(array_keys($ids));
            foreach ($ids as $id => $v) {
                $w = $weights[$id] ?? ['placements' => 0, 'complete' => 0];
                $incomplete = $w['placements'] - $w['complete'];
                if ($w['placements'] > 0 && $incomplete > 0) {
                    $attention[] = ['primary' => $this->versionName($v), 'secondary' => $incomplete . ' of ' . $w['placements'] . ' placements with incomplete grade weighting', 'meta' => ''];
                }
            }
        }
        $lists = [
            'returned_list' => $this->meta('Returned for revision', 'list', self::CURRICULA, '', $this->listOf(array_map(fn($v) => [
                'primary' => $this->versionName($v), 'secondary' => 'Reason: ' . ($v['last_reason'] ?: 'none recorded'), 'meta' => $v['updated_at'],
            ], array_slice($returned, 0, 5)), 'Nothing returned.')),
            'my_drafts' => $this->meta('My drafts', 'list', self::CURRICULA, '', $this->listOf(array_map(fn($v) => [
                'primary' => $this->versionName($v),
                'secondary' => (int)$v['placements'] . ' placements · Next: ' . DashboardShaper::draftNextStep((int)$v['placements'], (int)$v['runs']),
                'meta' => $v['updated_at'],
            ], array_slice($drafts, 0, 5)), 'No drafts.')),
            'needs_attention' => $this->meta('Needs attention', 'list', self::CURRICULA, 'Validator failures and incomplete grade weighting in your versions.',
                $this->listOf(array_slice($attention, 0, 8), 'Nothing needs attention.')),
        ];
        return compact('stats', 'charts', 'lists');
    }

    private function acadOffice(): array
    {
        $awaiting = $this->repo->awaitingReview($this->userId, $this->programId);
        $events = $this->repo->reviewEvents($this->programId, $this->daysAgo(365));
        $thisMonth = date('Y-m');
        $since90 = $this->daysAgo(90);
        $inMonth = static fn(string $action): int => count(array_filter($events, static fn($e) => $e['action'] === $action && str_starts_with((string)$e['created_at'], $thisMonth)));
        $average = DashboardShaper::averageDaysInReview(array_filter($events, static fn($e) => (string)$e['created_at'] >= $since90 || $e['action'] === 'submit'));
        $stats = [
            $this->stat('awaiting_mine', 'Awaiting my review', count($awaiting), self::CURRICULA),
            $this->stat('avg_days', 'Average days in review', $average, self::CURRICULA, $average === null ? 'No decisions in the last 90 days' : 'Last 90 days'),
            $this->stat('approved_month', 'Approved this month', $inMonth('approve'), self::CURRICULA),
            $this->stat('returned_month', 'Returned this month', $inMonth('return'), self::CURRICULA),
        ];
        $series = ['Submitted' => [], 'Approved' => [], 'Returned' => []];
        $names = ['submit' => 'Submitted', 'approve' => 'Approved', 'return' => 'Returned'];
        $split = ['Approved' => 0, 'Returned' => 0];
        foreach ($events as $e) {
            if (isset($names[$e['action']])) $series[$names[$e['action']]][] = ['day' => substr((string)$e['created_at'], 0, 10), 'total' => 1];
            if (in_array($e['action'], ['approve', 'return'], true) && (string)$e['created_at'] >= $since90) $split[$names[$e['action']]]++;
        }
        $underReview = array_values(array_filter($this->versions, static fn($v) => $v['review_status'] === 'under_review' && $v['status'] !== 'archived'));
        $charts = [
            'review_outcomes' => $this->meta('Review outcomes per month', 'bar', self::CURRICULA, 'Versions submitted, approved and returned.',
                $events === [] ? DashboardShaper::empty('No reviews yet.') : DashboardShaper::buckets($series, 'month')),
            'decision_split' => $this->meta('Decisions, last 90 days', 'donut', self::CURRICULA, 'Approved against returned.',
                $this->donut(array_map(static fn($k, $v) => ['label' => $k, 'value' => $v], array_keys($split), $split), 'No decisions in the last 90 days.')),
            'pipeline' => $this->pipeline(true),
            'compliance_under_review' => $this->meta('Compliance of versions under review', 'bar', self::VALIDATION, 'Latest validation run of each version under review.',
                $this->allow('compliance.view') ? $this->compliance($underReview) : DashboardShaper::empty()),
        ];
        $lists = [
            'awaiting_review' => $this->meta('Awaiting my review', 'list', self::CURRICULA, '', $this->listOf(array_map(fn($r) => [
                'primary' => $this->versionName($r), 'secondary' => 'Submitted by ' . $this->person($r), 'meta' => $r['submitted_at'],
            ], array_slice($awaiting, 0, 5)), 'Nothing is waiting for your review.')),
            'pending_equivalency' => $this->meta('Pending equivalency decisions', 'list', 'modules/curriculum/pages/equivalency_records.php', '',
                $this->allow('equivalency.manage') ? $this->listOf(array_map(static fn($r) => [
                    'primary' => $r['subject_code'] . ' and ' . $r['equivalent_code'], 'secondary' => 'Pending decision', 'meta' => $r['created_at'],
                ], $this->repo->pendingEquivalencies(5)), 'None pending.') : DashboardShaper::empty()),
            'my_decisions' => $this->meta('My recent decisions', 'list', self::CURRICULA, '', $this->listOf(array_map(fn($r) => [
                'primary' => $this->versionName($r), 'secondary' => ($r['action'] === 'approve' ? 'Approved' : 'Returned') . ': ' . ($r['comments'] ?: 'no reason recorded'), 'meta' => $r['created_at'],
            ], $this->repo->myDecisions($this->userId, $this->programId, 5)), 'No decisions yet.')),
        ];
        return compact('stats', 'charts', 'lists');
    }

    private function acadFaculty(?array $selected): array
    {
        $outcomes = $this->outcomeCoverage($selected);
        $stats = [
            $this->stat('approved', 'Approved curricula', $this->countReview('approved'), self::CURRICULA),
        ];
        $stats[] = $outcomes['status'] === DashboardShaper::OK
            ? $this->stat('outcomes', 'Outcomes covered', count(array_filter($outcomes['data']['outcomes'], static fn($o) => $o['mapped_subjects'] > 0)), self::MAPPING, 'of ' . count($outcomes['data']['outcomes']) . ' · ' . $selected['version_label'])
            : ['key' => 'outcomes', 'label' => 'Outcomes covered', 'value' => null, 'detail' => 'Not available yet', 'module' => self::MAPPING];
        $stats[] = ['key' => 'my_subjects', 'label' => 'My subjects', 'value' => null, 'detail' => 'Pending: no faculty-subject link', 'module' => null];
        $charts = [
            'outcome_coverage' => $this->meta('Outcome coverage', 'radar', self::MAPPING,
                $selected === null ? 'Subjects mapped to each graduate outcome.' : 'Subjects mapped to each graduate outcome in ' . $this->versionName($selected) . '.', $outcomes),
            'term_load' => $this->termLoad($selected),
            'units_by_category' => $this->unitsByCategory(),
        ];
        $approved = array_values(array_filter($this->versions, static fn($v) => $v['review_status'] === 'approved' && $v['status'] !== 'archived'));
        $lists = [
            'approved_curricula' => $this->meta('Approved curricula', 'list', self::CURRICULA, '', $this->listOf(array_map(fn($v) => [
                'primary' => $this->labelledRow($v), 'secondary' => $v['curriculum_name'], 'meta' => '',
            ], array_slice($approved, 0, 5)), 'None approved yet.')),
            'recently_approved' => $this->approvedList('Recently approved'),
        ];
        return compact('stats', 'charts', 'lists');
    }

    // ---- Cards ----

    private function pipeline(bool $listNone): array
    {
        $programs = $this->programId === null ? $this->repo->programs() : array_values(array_filter($this->repo->programs(), fn($p) => (int)$p['id'] === $this->programId));
        $versions = $this->programId === null ? $this->versions : array_values(array_filter($this->versions, fn($v) => (int)$v['program_id'] === $this->programId));
        return $this->meta('Curriculum pipeline by program', 'bar', self::CURRICULA, 'Every version of each program, by lifecycle stage.',
            $this->allow('curriculum.view') ? DashboardShaper::pipeline($programs, $versions, self::TOP, $listNone) : DashboardShaper::empty());
    }

    private function completeness(): array
    {
        $totals = [];
        foreach ($this->repo->placementTotals(array_map(static fn($v) => (int)$v['id'], $this->counted)) as $row) {
            $totals[(int)$row['version_id']] = round((float)$row['required_units'] + (float)$row['optional_units'], 2);
        }
        $rows = [];
        $rules = $this->repo->minTotalUnitRules();
        foreach ($this->counted as $v) {
            $target = DashboardShaper::targetFor($rules, (int)$v['curriculum_id'], (int)$v['program_id']);
            $rows[] = ['label' => $v['program_code'] . ' · ' . $v['curriculum_code'] . ' · ' . $v['version_label'],
                'units' => $totals[(int)$v['id']] ?? 0.0, 'target' => $target['value'] ?? null, 'target_name' => $target['name'] ?? null];
        }
        usort($rows, static fn($a, $b) => [$b['units'], $a['label']] <=> [$a['units'], $b['label']]);
        $total = count($rows);
        return $this->meta('Curriculum completeness', 'bar', self::CURRICULA, 'Placed units of each counted version against its required units, largest first.',
            $rows === [] ? DashboardShaper::empty('No curriculum with subjects yet.') : DashboardShaper::ok(['rows' => array_slice($rows, 0, self::TOP), 'total_rows' => $total]));
    }

    private function prerequisiteHealth(): array
    {
        $title = 'Prerequisite health';
        $module = 'modules/curriculum/pages/prerequisite_configuration.php';
        if (!$this->allow('curriculum.view', 'prerequisite.view')) return $this->meta($title, 'bar', $module, '', DashboardShaper::empty());
        $ids = array_map(static fn($v) => (int)$v['id'], $this->counted);
        $byVersionPlacements = [];
        foreach ($this->repo->placementsForVersions($ids) as $p) $byVersionPlacements[(int)$p['version_id']][] = $p;
        $byVersionEdges = [];
        foreach ($this->repo->prerequisiteEdges($ids) as $e) $byVersionEdges[(int)$e['version_id']][] = $e;
        $rows = [];
        foreach ($this->counted as $v) {
            $id = (int)$v['id'];
            if (empty($byVersionPlacements[$id])) continue;
            $rows[] = DashboardShaper::prerequisiteHealthRow($v['program_code'] . ' · ' . $v['curriculum_code'] . ' · ' . $v['version_label'],
                $byVersionPlacements[$id], $byVersionEdges[$id] ?? []);
        }
        if ($rows === []) return $this->meta($title, 'bar', $module, '', DashboardShaper::empty('No placed subjects to check yet.'));
        // Curricula with the most conflicts first, then the lowest prerequisite coverage.
        usort($rows, static fn($a, $b) => [-($a['later_term'] + $a['circular']), $a['percent'] ?? 101, $a['label']] <=> [-($b['later_term'] + $b['circular']), $b['percent'] ?? 101, $b['label']]);
        $data = ['total_rows' => count($rows), 'rows' => array_slice($rows, 0, self::TOP)];
        if ($this->programId !== null) {
            $histogram = DashboardShaper::prerequisiteHistogram($this->repo->prerequisiteCounts($ids));
            if ($histogram['status'] === DashboardShaper::OK) $data['histogram'] = $histogram['data'];
        }
        return $this->meta($title, 'bar', $module, 'Share of Year 2–4 subjects that have a prerequisite, and sequence conflicts.', DashboardShaper::ok($data));
    }

    private function termLoad(?array $selected): array
    {
        $title = 'Term load by year and semester';
        return $this->meta($title, 'bar', self::MAPPING, $selected === null ? 'Subjects or units per term.' : 'Version: ' . $this->versionName($selected) . '.',
            $selected === null ? DashboardShaper::empty('Choose a version in the picker or the filter above.') : DashboardShaper::termLoad($this->repo->placements((int)$selected['id'])));
    }

    private function outcomeCoverage(?array $selected): array
    {
        if ($selected === null) return DashboardShaper::empty('No version available yet.');
        if (strtoupper((string)$selected['program_code']) !== self::OUTCOME_PROGRAM_CODE) {
            return DashboardShaper::unavailable('Graduate outcomes are loaded only for BSIT (CHED CMO 25, s. 2015).');
        }
        $rows = $this->repo->outcomeCoverage((int)$selected['id'], self::OUTCOME_SOURCE_KEY);
        if ($rows === []) return DashboardShaper::unavailable('The BSIT graduate-outcome catalog has not been loaded yet.');
        if (array_sum(array_map(static fn($r) => (int)$r['mapped_subjects'], $rows)) === 0) {
            return DashboardShaper::empty('No subjects are mapped to graduate outcomes for this version yet.');
        }
        return DashboardShaper::ok(['outcomes' => array_map(static fn($r) => [
            'code' => (string)$r['outcome_code'], 'text' => (string)$r['outcome_text'],
            'mapped_subjects' => (int)$r['mapped_subjects'], 'assessment_state' => $r['assessment_state'] ?: null,
        ], $rows)]);
    }

    private function compliance(array $versions): array
    {
        if ($versions === []) return DashboardShaper::empty('No versions are under review.');
        $runs = [];
        foreach ($this->repo->latestComplianceRuns(array_map(static fn($v) => (int)$v['id'], $versions)) as $r) $runs[(int)$r['version_id']] = $r;
        if ($runs === []) return DashboardShaper::empty('No validation results for versions under review.');
        $bars = [];
        foreach ($versions as $v) {
            $r = $runs[(int)$v['id']] ?? null;
            $label = $this->versionName($v);
            $bars[] = $r === null ? ['label' => $label, 'validated' => false]
                : ['label' => $label, 'validated' => true, 'pass' => (int)$r['passed_count'], 'fail' => (int)$r['failed_count'], 'not_evaluated' => (int)$r['not_evaluated_count']];
        }
        $validated = array_values(array_filter($bars, static fn($b) => $b['validated']));
        usort($validated, static fn($a, $b) => [$b['fail'], $a['label']] <=> [$a['fail'], $b['label']]);
        $pending = array_values(array_filter($bars, static fn($b) => !$b['validated']));
        return DashboardShaper::ok(['bars' => array_slice($validated, 0, self::TOP), 'total_rows' => count($validated), 'pending' => array_column($pending, 'label')]);
    }

    private function complianceWorking(?array $selected): array
    {
        $title = 'Compliance of working version';
        $module = self::VALIDATION;
        if (!$this->allow('compliance.view')) return $this->meta($title, 'donut', $module, '', DashboardShaper::empty());
        if ($selected === null) return $this->meta($title, 'donut', $module, 'Latest validation run.', DashboardShaper::empty('Choose a version in the picker or the filter above.'));
        $run = $this->repo->latestComplianceRuns([(int)$selected['id']])[0] ?? null;
        $description = 'Latest validation run of ' . $this->versionName($selected) . '.';
        if ($run === null) return $this->meta($title, 'donut', $module, $description, DashboardShaper::empty('This version has not been validated yet.'));
        return $this->meta($title, 'donut', $module, $description, $this->donut([
            ['label' => 'Pass', 'value' => (int)$run['passed_count']],
            ['label' => 'Fail', 'value' => (int)$run['failed_count']],
            ['label' => 'Not evaluated', 'value' => (int)$run['not_evaluated_count']],
        ], 'The latest run evaluated no rules.'));
    }

    private function unitsByCategory(): array
    {
        return $this->meta('Units by subject category', 'donut', 'modules/curriculum/pages/subjects.php', 'Units per subject category.',
            DashboardShaper::unavailable('Subjects do not have a category yet. This chart starts working when that field is added.'));
    }

    private function programsByLevel(array $programs): array
    {
        $counts = ['College' => 0, 'Senior High School' => 0];
        foreach ($programs as $p) {
            if (strtolower((string)$p['status']) !== 'active') continue;
            $counts[DashboardShaper::isShs($p['program_type'] ?? null) ? 'Senior High School' : 'College']++;
        }
        return $this->donut(array_map(static fn($k, $v) => ['label' => $k, 'value' => $v], array_keys($counts), $counts), 'No active programs.');
    }

    /** Part of a whole, at most six fixed categories. Zero categories stay in the legend. */
    private function donut(array $segments, string $emptyReason): array
    {
        $total = array_sum(array_column($segments, 'value'));
        return $total > 0 ? DashboardShaper::ok(['segments' => array_values($segments), 'total' => $total]) : DashboardShaper::empty($emptyReason);
    }

    private function approvedList(string $title): array
    {
        $readable = array_flip(array_map(static fn($v) => (int)$v['id'], $this->versions));
        $rows = array_filter($this->repo->recentlyApproved($this->programId, 10), static fn($r) => isset($readable[(int)$r['id']]));
        return $this->meta($title, 'list', self::CURRICULA, '', $this->listOf(array_map(fn($r) => [
            'primary' => $this->labelledRow($r), 'secondary' => 'Approved by ' . $this->person($r), 'meta' => $r['created_at'],
        ], array_slice(array_values($rows), 0, 5)), 'None approved yet.'));
    }

    // ---- Helpers ----

    /** Explicit filter, else the working context when in reach, else the best counted version. */
    private function selectVersion(?int $versionId): ?array
    {
        $byId = [];
        foreach ($this->versions as $v) $byId[(int)$v['id']] = $v;
        if ($versionId !== null) {
            if (isset($byId[$versionId])) return $byId[$versionId];
            throw new DomainValidationException('The selected version does not belong to this program or strand.');
        }
        $working = $this->repo->workingVersionId($this->userId);
        if ($working !== null && isset($byId[$working])) return $byId[$working];
        return DashboardShaper::bestVersion($this->counted);
    }

    private function countReview(string $state): int
    {
        return count(array_filter($this->versions, static fn($v) => $v['review_status'] === $state && $v['status'] !== 'archived'));
    }

    private function versionName(array $v): string
    {
        return $v['program_code'] . ' · ' . $v['curriculum_code'] . ' · ' . $v['version_label'];
    }

    private function labelledRow(array $v): string
    {
        return 'Curriculum ' . $v['curriculum_code'] . ' · Version ' . $v['version_label'] . ' · '
            . (($v['effective_academic_year'] ?? '') !== '' && $v['effective_academic_year'] !== null ? 'Effective ' . $v['effective_academic_year'] : 'Effective date not set');
    }

    private function person(array $r): string
    {
        $name = trim(trim((string)($r['first_name'] ?? '')) . ' ' . trim((string)($r['last_name'] ?? '')));
        return $name !== '' ? $name : ((string)($r['username'] ?? '') ?: 'System');
    }

    private function daysAgo(int $days): string
    {
        return (new DateTimeImmutable("-$days days"))->format('Y-m-d 00:00:00');
    }

    private function stat(string $key, string $label, int|float|null $value, ?string $module, string $detail = ''): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'detail' => $detail, 'module' => $module];
    }

    private function meta(string $title, string $type, ?string $module, string $description, array $envelope): array
    {
        return ['title' => $title, 'type' => $type, 'module' => $module, 'description' => $description] + $envelope;
    }

    private function listOf(array $items, string $emptyReason): array
    {
        return $items === [] ? DashboardShaper::empty($emptyReason) : DashboardShaper::ok(['items' => $items]);
    }
}
