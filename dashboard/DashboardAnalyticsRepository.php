<?php

require_once __DIR__ . '/../modules/curriculum/repositories/RepositoryQuery.php';

/**
 * Read-only queries for the dashboard analytics. Every statement is prepared;
 * the only dynamic SQL is a generated list of '?' placeholders for id lists.
 * Program scope: $programId === null means all programs.
 */
final class DashboardAnalyticsRepository
{
    public function __construct(private mysqli $db)
    {
    }

    private function rows(string $sql, string $types = '', array $params = []): array
    {
        $stmt = RepositoryQuery::execute($this->db, $sql, $types, $params);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /** @param int[] $ids */
    private static function idList(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        return [implode(',', array_fill(0, count($ids), '?')), str_repeat('i', count($ids)), $ids];
    }

    private static function programClause(?int $programId, string $column): array
    {
        return $programId === null ? ['1=1', '', []] : ["$column = ?", 'i', [$programId]];
    }

    public function programs(): array
    {
        return $this->rows(
            'SELECT p.id, p.program_code, p.program_name, p.program_type, p.status,
                    COUNT(DISTINCT c.id) AS curricula
             FROM academic_programs AS p
             LEFT JOIN curricula AS c ON c.academic_program_id = p.id
             GROUP BY p.id, p.program_code, p.program_name, p.program_type, p.status
             ORDER BY p.program_code, p.id'
        );
    }

    public function programExists(int $programId): bool
    {
        return $this->rows('SELECT id FROM academic_programs WHERE id = ?', 'i', [$programId]) !== [];
    }

    /** All versions in scope with their curriculum/program context. */
    public function versions(?int $programId): array
    {
        [$where, $types, $params] = self::programClause($programId, 'c.academic_program_id');
        return $this->rows(
            "SELECT v.id, v.curriculum_id, v.version_label, v.effective_academic_year, v.status,
                    v.review_status, v.version_role, v.created_by, v.created_at, v.updated_at,
                    c.curriculum_code, c.curriculum_name, c.academic_program_id AS program_id,
                    p.program_code, p.program_name
             FROM curriculum_versions AS v
             JOIN curricula AS c ON c.id = v.curriculum_id
             JOIN academic_programs AS p ON p.id = c.academic_program_id
             WHERE $where
             ORDER BY p.program_code, c.curriculum_code, v.updated_at DESC, v.id DESC",
            $types,
            $params
        );
    }

    public function overallCounts(?int $programId): array
    {
        [$where, $types, $params] = self::programClause($programId, 'c.academic_program_id');
        $row = $this->rows(
            "SELECT COUNT(DISTINCT c.id) AS curricula, COUNT(DISTINCT v.id) AS versions,
                    COUNT(DISTINCT CASE WHEN v.status = 'active' THEN v.id END) AS active_versions
             FROM curricula AS c
             LEFT JOIN curriculum_versions AS v ON v.curriculum_id = c.id
             WHERE $where",
            $types,
            $params
        )[0] ?? [];
        $other = $this->rows(
            'SELECT (SELECT COUNT(*) FROM academic_programs) AS programs,
                    (SELECT COUNT(*) FROM subjects) AS subjects,
                    (SELECT COUNT(*) FROM curriculum_elective_groups) AS elective_groups,
                    (SELECT COUNT(*) FROM subject_equivalencies WHERE archived_at IS NULL) AS equivalencies'
        )[0] ?? [];
        return array_map('intval', $row + $other);
    }

    /** Units and subjects per version, split by required flag. @param int[] $versionIds */
    public function placementTotals(array $versionIds): array
    {
        if ($versionIds === []) return [];
        [$in, $types, $ids] = self::idList($versionIds);
        return $this->rows(
            "SELECT m.curriculum_version_id AS version_id,
                    COUNT(*) AS subjects,
                    SUM(CASE WHEN m.is_required = 1 THEN 1 ELSE 0 END) AS required_subjects,
                    COALESCE(SUM(CASE WHEN m.is_required = 1 THEN s.units ELSE 0 END), 0) AS required_units,
                    COALESCE(SUM(CASE WHEN m.is_required = 1 THEN 0 ELSE s.units END), 0) AS optional_units
             FROM curriculum_version_subjects AS m
             JOIN subjects AS s ON s.id = m.subject_id
             WHERE m.curriculum_version_id IN ($in)
             GROUP BY m.curriculum_version_id",
            $types,
            $ids
        );
    }

    public function placements(int $versionId): array
    {
        return $this->rows(
            'SELECT m.year_level, m.semester, m.is_required, s.units
             FROM curriculum_version_subjects AS m
             JOIN subjects AS s ON s.id = m.subject_id
             WHERE m.curriculum_version_id = ?
             ORDER BY m.year_level, m.semester, m.sequence_order, m.id',
            'i',
            [$versionId]
        );
    }

    /** Placed subjects with their stored prerequisite count. @param int[] $versionIds */
    public function prerequisiteCounts(array $versionIds): array
    {
        if ($versionIds === []) return [];
        [$in, $types, $ids] = self::idList($versionIds);
        return $this->rows(
            "SELECT m.curriculum_version_id AS version_id, s.subject_code, s.subject_title,
                    COUNT(r.id) AS prerequisite_count
             FROM curriculum_version_subjects AS m
             JOIN subjects AS s ON s.id = m.subject_id
             LEFT JOIN curriculum_subject_requirements AS r
                    ON r.curriculum_version_id = m.curriculum_version_id
                   AND r.subject_id = m.subject_id
                   AND r.requirement_type = 'prerequisite'
             WHERE m.curriculum_version_id IN ($in)
             GROUP BY m.id, m.curriculum_version_id, s.subject_code, s.subject_title",
            $types,
            $ids
        );
    }

    /** Graduate outcomes of one catalog source with mapped-subject counts for a version. */
    public function outcomeCoverage(int $versionId, string $sourceKey): array
    {
        return $this->rows(
            'SELECT o.outcome_code, o.outcome_text, o.source_order,
                    COALESCE(mc.mapped_subjects, 0) AS mapped_subjects, c.assessment_state
             FROM curriculum_outcome_catalog AS o
             LEFT JOIN curriculum_version_outcome_coverage AS c
                    ON c.outcome_id = o.id AND c.curriculum_version_id = ?
             LEFT JOIN (SELECT m.outcome_id, COUNT(DISTINCT p.subject_id) AS mapped_subjects
                        FROM curriculum_version_subject_outcomes AS m
                        JOIN curriculum_version_subjects AS p ON p.id = m.curriculum_version_subject_id
                        WHERE p.curriculum_version_id = ?
                        GROUP BY m.outcome_id) AS mc ON mc.outcome_id = o.id
             WHERE o.source_key = ?
             ORDER BY o.source_order',
            'iis',
            [$versionId, $versionId, $sourceKey]
        );
    }

    /** Monthly counts since $since. The FROM clause comes only from this fixed map. */
    public function monthly(string $series, ?int $programId, string $since): array
    {
        $map = [
            'versions' => 'curriculum_versions AS x JOIN curricula AS c ON c.id = x.curriculum_id',
            'placements' => 'curriculum_version_subjects AS x JOIN curriculum_versions AS v ON v.id = x.curriculum_version_id JOIN curricula AS c ON c.id = v.curriculum_id',
            'runs' => 'compliance_validation_runs AS x JOIN curriculum_versions AS v ON v.id = x.curriculum_version_id JOIN curricula AS c ON c.id = v.curriculum_id',
        ];
        if (!isset($map[$series])) throw new InvalidArgumentException('Unknown activity series.');
        $from = $map[$series];
        [$where, $types, $params] = self::programClause($programId, 'c.academic_program_id');
        return $this->rows(
            "SELECT DATE_FORMAT(x.created_at, '%Y-%m') AS month, COUNT(*) AS total
             FROM $from
             WHERE $where AND x.created_at >= ?
             GROUP BY DATE_FORMAT(x.created_at, '%Y-%m')",
            $types . 's',
            [...$params, $since]
        );
    }

    /** Latest validation run per version. @param int[] $versionIds */
    public function latestComplianceRuns(array $versionIds): array
    {
        if ($versionIds === []) return [];
        [$in, $types, $ids] = self::idList($versionIds);
        return $this->rows(
            "SELECT r.curriculum_version_id AS version_id, r.rules_evaluated, r.passed_count,
                    r.failed_count, r.not_evaluated_count, r.created_at
             FROM compliance_validation_runs AS r
             JOIN (SELECT curriculum_version_id, MAX(id) AS id
                   FROM compliance_validation_runs
                   WHERE curriculum_version_id IN ($in)
                   GROUP BY curriculum_version_id) AS latest ON latest.id = r.id",
            $types,
            $ids
        );
    }

    public function runCounts(array $versionIds): array
    {
        if ($versionIds === []) return [];
        [$in, $types, $ids] = self::idList($versionIds);
        $rows = $this->rows(
            "SELECT curriculum_version_id AS version_id, COUNT(*) AS runs
             FROM compliance_validation_runs WHERE curriculum_version_id IN ($in)
             GROUP BY curriculum_version_id",
            $types,
            $ids
        );
        return array_column($rows, 'runs', 'version_id');
    }

    /** Grade-weighting configured/complete counts per version (same logic as analytics.php). */
    public function gradeWeighting(int $versionId): array
    {
        return $this->rows(
            'SELECT COUNT(m.id) AS placements, COUNT(DISTINCT g.id) AS configured,
                    COALESCE(SUM(CASE WHEN (SELECT COALESCE(SUM(c.weight_percentage), 0)
                                            FROM grade_weighting_components AS c WHERE c.scheme_id = g.id) = 100
                                      THEN 1 ELSE 0 END), 0) AS complete
             FROM curriculum_version_subjects AS m
             LEFT JOIN grade_weighting_schemes AS g ON g.curriculum_version_subject_id = m.id
             WHERE m.curriculum_version_id = ?',
            'i',
            [$versionId]
        )[0] ?? ['placements' => 0, 'configured' => 0, 'complete' => 0];
    }

    public function electiveGroupCount(int $versionId): int
    {
        return (int)($this->rows('SELECT COUNT(*) AS total FROM curriculum_elective_groups WHERE curriculum_version_id = ?', 'i', [$versionId])[0]['total'] ?? 0);
    }

    /** Review workflow evidence: events written and versions past 'not_started'. */
    public function reviewWorkflowUsage(): array
    {
        return array_map('intval', $this->rows(
            "SELECT (SELECT COUNT(*) FROM curriculum_version_review_events) AS events,
                    (SELECT COUNT(*) FROM curriculum_versions WHERE review_status <> 'not_started') AS versions_in_workflow,
                    (SELECT COUNT(*) FROM curriculum_version_personnel WHERE is_active = 1) AS personnel"
        )[0] ?? []);
    }

    public function reviewEventsMonthly(?int $programId, string $since): array
    {
        [$where, $types, $params] = self::programClause($programId, 'c.academic_program_id');
        return $this->rows(
            "SELECT DATE_FORMAT(e.created_at, '%Y-%m') AS month, e.action, COUNT(*) AS total
             FROM curriculum_version_review_events AS e
             JOIN curriculum_versions AS v ON v.id = e.curriculum_version_id
             JOIN curricula AS c ON c.id = v.curriculum_id
             WHERE $where AND e.created_at >= ? AND e.action IN ('submit', 'approve', 'return')
             GROUP BY DATE_FORMAT(e.created_at, '%Y-%m'), e.action",
            $types . 's',
            [...$params, $since]
        );
    }

    /** Versions under review where the user is an active reviewer or approver. */
    public function reviewQueue(int $userId, ?int $programId): array
    {
        [$where, $types, $params] = self::programClause($programId, 'c.academic_program_id');
        return $this->rows(
            "SELECT DISTINCT v.id, v.version_label, v.updated_at, c.curriculum_code, p.program_code, pr.responsibility
             FROM curriculum_version_personnel AS pr
             JOIN curriculum_versions AS v ON v.id = pr.curriculum_version_id
             JOIN curricula AS c ON c.id = v.curriculum_id
             JOIN academic_programs AS p ON p.id = c.academic_program_id
             WHERE pr.user_id = ? AND pr.is_active = 1 AND pr.responsibility IN ('reviewer', 'approver')
               AND v.review_status = 'under_review' AND $where
             ORDER BY v.updated_at ASC
             LIMIT 5",
            'i' . $types,
            [$userId, ...$params]
        );
    }

    public function myDrafts(int $userId, ?int $programId): array
    {
        [$where, $types, $params] = self::programClause($programId, 'c.academic_program_id');
        return $this->rows(
            "SELECT v.id, v.version_label, v.updated_at, c.curriculum_code, c.curriculum_name, p.program_code,
                    (SELECT COUNT(*) FROM curriculum_version_subjects AS m WHERE m.curriculum_version_id = v.id) AS placements,
                    (SELECT COUNT(*) FROM compliance_validation_runs AS r WHERE r.curriculum_version_id = v.id) AS runs
             FROM curriculum_versions AS v
             JOIN curricula AS c ON c.id = v.curriculum_id
             JOIN academic_programs AS p ON p.id = c.academic_program_id
             WHERE v.created_by = ? AND v.status = 'draft' AND $where
             ORDER BY v.updated_at DESC
             LIMIT 5",
            'i' . $types,
            [$userId, ...$params]
        );
    }

    public function complianceFailures(?int $programId): array
    {
        [$where, $types, $params] = self::programClause($programId, 'c.academic_program_id');
        return $this->rows(
            "SELECT v.id, v.version_label, c.curriculum_code, p.program_code,
                    r.failed_count, r.passed_count, r.not_evaluated_count, r.created_at
             FROM compliance_validation_runs AS r
             JOIN (SELECT curriculum_version_id, MAX(id) AS id FROM compliance_validation_runs
                   GROUP BY curriculum_version_id) AS latest ON latest.id = r.id
             JOIN curriculum_versions AS v ON v.id = r.curriculum_version_id
             JOIN curricula AS c ON c.id = v.curriculum_id
             JOIN academic_programs AS p ON p.id = c.academic_program_id
             WHERE r.failed_count > 0 AND v.status <> 'archived' AND $where
             ORDER BY r.failed_count DESC, r.created_at DESC
             LIMIT 5",
            $types,
            $params
        );
    }

    public function complianceFailureCount(?int $programId): int
    {
        [$where, $types, $params] = self::programClause($programId, 'c.academic_program_id');
        return (int)($this->rows(
            "SELECT COUNT(*) AS total
             FROM compliance_validation_runs AS r
             JOIN (SELECT curriculum_version_id, MAX(id) AS id FROM compliance_validation_runs
                   GROUP BY curriculum_version_id) AS latest ON latest.id = r.id
             JOIN curriculum_versions AS v ON v.id = r.curriculum_version_id
             JOIN curricula AS c ON c.id = v.curriculum_id
             WHERE r.failed_count > 0 AND v.status <> 'archived' AND $where",
            $types,
            $params
        )[0]['total'] ?? 0);
    }

    public function activeCurricula(?int $programId): array
    {
        [$where, $types, $params] = self::programClause($programId, 'c.academic_program_id');
        return $this->rows(
            "SELECT v.id, v.version_label, v.effective_academic_year, v.updated_at,
                    c.curriculum_code, c.curriculum_name, p.program_code
             FROM curriculum_versions AS v
             JOIN curricula AS c ON c.id = v.curriculum_id
             JOIN academic_programs AS p ON p.id = c.academic_program_id
             WHERE v.status = 'active' AND $where
             ORDER BY v.updated_at DESC
             LIMIT 5",
            $types,
            $params
        );
    }

    public function recentActivity(int $limit = 5): array
    {
        $safeLimit = max(1, min(10, $limit));
        return $this->rows(
            "SELECT a.action, a.entity_type, a.description, a.created_at,
                    u.username, u.first_name, u.last_name
             FROM audit_logs AS a
             LEFT JOIN users AS u ON u.id = a.user_id
             WHERE a.action LIKE 'PROGRAM\\_%' OR a.action LIKE 'SUBJECT\\_%' OR a.action LIKE 'CURRICULUM\\_%'
                OR a.action LIKE 'ELECTIVE\\_%' OR a.action LIKE 'GRADE\\_WEIGHTING\\_%' OR a.action LIKE 'COMPLIANCE\\_%'
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT ?",
            'i',
            [$safeLimit]
        );
    }
}
