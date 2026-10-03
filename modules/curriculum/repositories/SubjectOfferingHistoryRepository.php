<?php

require_once __DIR__ . '/RepositoryQuery.php';

/**
 * Read model for stored curriculum-version subject history.
 *
 * No historical copy is maintained here. curriculum_version_subjects remains
 * authoritative, joined to existing version, curriculum, program, and subject
 * master records.
 */
class SubjectOfferingHistoryRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function paginate(array $query): array
    {
        [$conditions, $types, $params] = $this->historyConditions($query);
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $joins = $this->historyJoins();

        $countStmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS total' . $joins . $where,
            $types,
            $params
        );
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();
        $pagination = RepositoryQuery::pagination($total, $query['page'], $query['per_page']);

        $rankParams = [];
        $searchOrder = RepositoryQuery::searchOrder(['subjects.subject_code', 'subjects.subject_title'], $query['search'] ?? null, $rankParams);
        $listParams = array_merge($params, $rankParams);
        $listParams[] = $pagination['per_page'];
        $listParams[] = $pagination['offset'];
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT cvs.id AS placement_id, cvs.subject_id,
                    cvs.curriculum_version_id, cvs.year_level, cvs.semester,
                    cvs.sequence_order, cvs.is_required,
                    cvs.created_at AS placement_created_at,
                    cvs.updated_at AS placement_updated_at,
                    subjects.subject_code, subjects.subject_title, subjects.units,
                    curriculum_versions.version_label,
                    curriculum_versions.effective_academic_year,
                    curriculum_versions.status AS version_status,
                    curriculum_versions.created_at AS version_created_at,
                    curriculum_versions.updated_at AS version_updated_at,
                    curricula.id AS curriculum_id, curricula.curriculum_code,
                    curricula.curriculum_name,
                    academic_programs.id AS academic_program_id,
                    academic_programs.program_code, academic_programs.program_name,
                    academic_programs.program_type'
            . $joins . $where .
            " ORDER BY " . $searchOrder . "subjects.subject_code ASC,
                       curriculum_versions.created_at ASC,
                       academic_programs.program_code ASC,
                       curricula.curriculum_code ASC,
                       curriculum_versions.version_label ASC,
                       cvs.id ASC LIMIT ? OFFSET ?",
            $types . str_repeat('s', count($rankParams)) . 'ii',
            $listParams
        );
        $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        unset($pagination['offset']);

        return [
            'records' => $records,
            'pagination' => $pagination,
            'summary' => $this->summary($where, $types, $params),
        ];
    }

    public function filterValues(array $query): array
    {
        $context = $query;
        $context['year_level'] = null;
        $context['semester'] = null;
        $context['version_status'] = null;
        [$conditions, $types, $params] = $this->historyConditions($context);
        $joins = $this->historyJoins();

        return [
            'year_levels' => $this->distinctValues(
                'cvs.year_level',
                $joins,
                $conditions,
                $types,
                $params
            ),
            'semesters' => $this->distinctValues(
                'cvs.semester',
                $joins,
                $conditions,
                $types,
                $params
            ),
            'version_statuses' => $this->distinctValues(
                'curriculum_versions.status',
                $joins,
                $conditions,
                $types,
                $params
            ),
        ];
    }

    public function paginateComparison(int $versionAId, int $versionBId, array $query): array
    {
        $from = $this->comparisonFrom();
        $baseParams = [$versionAId, $versionBId, $versionAId, $versionBId];
        $countStmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS total' . $from,
            'iiii',
            $baseParams
        );
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();
        $pagination = RepositoryQuery::pagination($total, $query['page'], $query['per_page']);

        $params = $baseParams;
        $params[] = $pagination['per_page'];
        $params[] = $pagination['offset'];
        $stmt = RepositoryQuery::execute(
            $this->db,
            "SELECT subjects.id AS subject_id, subjects.subject_code,
                    subjects.subject_title, subjects.units,
                    placement_a.id AS version_a_placement_id,
                    placement_a.year_level AS version_a_year_level,
                    placement_a.semester AS version_a_semester,
                    placement_a.sequence_order AS version_a_sequence_order,
                    placement_a.is_required AS version_a_is_required,
                    placement_b.id AS version_b_placement_id,
                    placement_b.year_level AS version_b_year_level,
                    placement_b.semester AS version_b_semester,
                    placement_b.sequence_order AS version_b_sequence_order,
                    placement_b.is_required AS version_b_is_required,
                    CASE
                        WHEN placement_a.subject_id IS NULL THEN 'added'
                        WHEN placement_b.subject_id IS NULL THEN 'removed'
                        WHEN NOT (
                            placement_a.year_level <=> placement_b.year_level
                            AND placement_a.semester <=> placement_b.semester
                            AND placement_a.sequence_order <=> placement_b.sequence_order
                            AND placement_a.is_required <=> placement_b.is_required
                        ) THEN 'changed'
                        ELSE 'unchanged'
                    END AS change_type"
            . $from .
            ' ORDER BY subjects.subject_code ASC, subjects.id ASC LIMIT ? OFFSET ?',
            'iiiiii',
            $params
        );
        $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        unset($pagination['offset']);

        return [
            'records' => $records,
            'pagination' => $pagination,
            'summary' => $this->comparisonSummary($versionAId, $versionBId),
        ];
    }

    private function historyJoins(): string
    {
        return ' FROM curriculum_version_subjects AS cvs
                 INNER JOIN subjects ON subjects.id = cvs.subject_id
                 INNER JOIN curriculum_versions
                    ON curriculum_versions.id = cvs.curriculum_version_id
                 INNER JOIN curricula ON curricula.id = curriculum_versions.curriculum_id
                 INNER JOIN academic_programs
                    ON academic_programs.id = curricula.academic_program_id';
    }

    private function historyConditions(array $query): array
    {
        $conditions = [];
        $types = '';
        $params = [];
        foreach ([
            'academic_program_id' => 'academic_programs.id',
            'curriculum_id' => 'curricula.id',
            'curriculum_version_id' => 'curriculum_versions.id',
        ] as $key => $column) {
            if ($query[$key] !== null) {
                $conditions[] = $column . ' = ?';
                $types .= 'i';
                $params[] = $query[$key];
            }
        }
        if ($query['search'] !== null) {
            $conditions[] = "(" . RepositoryQuery::searchColumn('subjects.subject_code') . " LIKE ? ESCAPE '='
                              OR " . RepositoryQuery::searchColumn('subjects.subject_title') . " LIKE ? ESCAPE '=')";
            $pattern = RepositoryQuery::likePattern($query['search']);
            $types .= 'ss';
            $params[] = $pattern;
            $params[] = $pattern;
        }
        foreach ([
            'year_level' => 'cvs.year_level',
            'semester' => 'cvs.semester',
            'version_status' => 'curriculum_versions.status',
        ] as $key => $column) {
            if ($query[$key] !== null) {
                $conditions[] = $column . ' = ?';
                $types .= 's';
                $params[] = $query[$key];
            }
        }
        return [$conditions, $types, $params];
    }

    private function summary(string $where, string $types, array $params): array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS stored_placements,
                    COUNT(DISTINCT academic_programs.id) AS programs,
                    COUNT(DISTINCT curricula.id) AS curricula,
                    COUNT(DISTINCT curriculum_versions.id) AS curriculum_versions,
                    MIN(curriculum_versions.created_at) AS earliest_record,
                    MAX(curriculum_versions.updated_at) AS latest_record'
            . $this->historyJoins() . $where,
            $types,
            $params
        );
        $summary = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $summary;
    }

    private function comparisonFrom(): string
    {
        return ' FROM (
                    SELECT subject_id FROM curriculum_version_subjects
                    WHERE curriculum_version_id = ?
                    UNION
                    SELECT subject_id FROM curriculum_version_subjects
                    WHERE curriculum_version_id = ?
                 ) AS compared_subjects
                 INNER JOIN subjects ON subjects.id = compared_subjects.subject_id
                 LEFT JOIN curriculum_version_subjects AS placement_a
                    ON placement_a.subject_id = compared_subjects.subject_id
                   AND placement_a.curriculum_version_id = ?
                 LEFT JOIN curriculum_version_subjects AS placement_b
                    ON placement_b.subject_id = compared_subjects.subject_id
                   AND placement_b.curriculum_version_id = ?';
    }

    private function comparisonSummary(int $versionAId, int $versionBId): array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            "SELECT
                SUM(CASE WHEN placement_a.subject_id IS NULL THEN 1 ELSE 0 END) AS added,
                SUM(CASE WHEN placement_b.subject_id IS NULL THEN 1 ELSE 0 END) AS removed,
                SUM(CASE WHEN placement_a.subject_id IS NOT NULL
                              AND placement_b.subject_id IS NOT NULL
                              AND NOT (
                                  placement_a.year_level <=> placement_b.year_level
                                  AND placement_a.semester <=> placement_b.semester
                                  AND placement_a.sequence_order <=> placement_b.sequence_order
                                  AND placement_a.is_required <=> placement_b.is_required
                              ) THEN 1 ELSE 0 END) AS changed,
                SUM(CASE WHEN placement_a.subject_id IS NOT NULL
                              AND placement_b.subject_id IS NOT NULL
                              AND placement_a.year_level <=> placement_b.year_level
                              AND placement_a.semester <=> placement_b.semester
                              AND placement_a.sequence_order <=> placement_b.sequence_order
                              AND placement_a.is_required <=> placement_b.is_required
                         THEN 1 ELSE 0 END) AS unchanged"
            . $this->comparisonFrom(),
            'iiii',
            [$versionAId, $versionBId, $versionAId, $versionBId]
        );
        $summary = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $summary;
    }

    private function distinctValues(
        string $column,
        string $joins,
        array $conditions,
        string $types,
        array $params
    ): array {
        $valueConditions = $conditions;
        $valueConditions[] = $column . ' IS NOT NULL';
        $valueConditions[] = $column . " <> ''";
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT DISTINCT ' . $column . ' AS value' . $joins .
            ' WHERE ' . implode(' AND ', $valueConditions) .
            ' ORDER BY value ASC LIMIT 100',
            $types,
            $params
        );
        $values = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'value');
        $stmt->close();
        return $values;
    }
}
