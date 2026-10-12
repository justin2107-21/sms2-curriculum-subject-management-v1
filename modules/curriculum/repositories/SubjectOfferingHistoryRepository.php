<?php

require_once __DIR__ . '/RepositoryQuery.php';

/**
 * Read model for stored curriculum-version subject history.
 *
 * Complete captured snapshots are historical evidence. Legacy rows without
 * snapshots disclose missing historical details; current master values are
 * exposed separately and never substituted into historical fields.
 */
class SubjectOfferingHistoryRepository
{
    public function __construct(private mysqli $db)
    {
    }

    /** Plain consistent reads only: the History service owns the report snapshot. */
    public function comparisonState(int $version): array
    {
        $rows = function (string $sql) use ($version): array {
            $stmt = RepositoryQuery::execute($this->db, $sql, 'i', [$version]);
            $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return $result;
        };
        return [
            'placements' => $rows('SELECT p.*, s.subject_code, s.subject_title, s.units, s.lecture_units,
                s.laboratory_units, s.description, h.subject_code_snapshot, h.subject_title_snapshot,
                h.units_snapshot, h.lecture_units_snapshot, h.laboratory_units_snapshot, h.description_snapshot,
                h.snapshot_complete, h.source_reference_id, h.capture_method, h.captured_at
                FROM curriculum_version_subjects p JOIN subjects s ON s.id=p.subject_id
                LEFT JOIN curriculum_version_subject_snapshots h ON h.curriculum_version_subject_id=p.id
                WHERE p.curriculum_version_id=? ORDER BY p.subject_id,p.id'),
            'requirements' => $rows('SELECT subject_id,required_subject_id,requirement_type FROM curriculum_subject_requirements WHERE curriculum_version_id=? ORDER BY subject_id,required_subject_id,requirement_type'),
            'groups' => $rows('SELECT id,group_name,description,year_level,semester,required_selections,status FROM curriculum_elective_groups WHERE curriculum_version_id=? ORDER BY id'),
            'memberships' => $rows('SELECT elective_group_id,subject_id FROM curriculum_elective_group_subjects WHERE curriculum_version_id=? ORDER BY elective_group_id,subject_id'),
            'schemes' => $rows('SELECT w.id,p.subject_id,w.description FROM grade_weighting_schemes w JOIN curriculum_version_subjects p ON p.id=w.curriculum_version_subject_id WHERE p.curriculum_version_id=? ORDER BY p.subject_id'),
            'components' => $rows('SELECT p.subject_id,c.component_name,c.weight_percentage,c.sequence_order FROM grade_weighting_components c JOIN grade_weighting_schemes w ON w.id=c.scheme_id JOIN curriculum_version_subjects p ON p.id=w.curriculum_version_subject_id WHERE p.curriculum_version_id=? ORDER BY p.subject_id,c.component_name'),
            'clone_events' => $rows("SELECT metadata_json FROM audit_logs WHERE action='CURRICULUM_REVISION_CREATE' AND entity_type='curriculum_version' AND entity_id=? ORDER BY id"),
        ];
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
                    CASE WHEN subject_snapshots.snapshot_complete=1 THEN subject_snapshots.subject_code_snapshot ELSE NULL END AS subject_code,
                    CASE WHEN subject_snapshots.snapshot_complete=1 THEN subject_snapshots.subject_title_snapshot ELSE NULL END AS subject_title,
                    CASE WHEN subject_snapshots.snapshot_complete=1 THEN subject_snapshots.units_snapshot ELSE NULL END AS units,
                    subjects.subject_code AS current_subject_code,
                    subjects.subject_title AS current_subject_title,
                    subjects.units AS current_units,
                    subject_snapshots.lecture_units_snapshot AS lecture_units,
                    subject_snapshots.laboratory_units_snapshot AS laboratory_units,
                    subject_snapshots.description_snapshot AS description,
                    subject_snapshots.captured_at AS snapshot_captured_at,
                    subject_snapshots.capture_method,
                    COALESCE(subject_snapshots.snapshot_complete, 0) AS snapshot_complete,
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
                       academic_programs.program_code ASC,
                       curricula.curriculum_code ASC,
                       curriculum_versions.created_at ASC,
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

    public function findPlacement(int $placementId): ?array
    {
        $stmt = RepositoryQuery::execute($this->db,
            'SELECT cvs.id AS placement_id, cvs.subject_id, cvs.curriculum_version_id,
                    cvs.year_level, cvs.semester, cvs.sequence_order, cvs.is_required,
                    cvs.created_at AS placement_created_at, cvs.updated_at AS placement_updated_at,
                    CASE WHEN subject_snapshots.snapshot_complete=1 THEN subject_snapshots.subject_code_snapshot ELSE NULL END AS subject_code,
                    CASE WHEN subject_snapshots.snapshot_complete=1 THEN subject_snapshots.subject_title_snapshot ELSE NULL END AS subject_title,
                    CASE WHEN subject_snapshots.snapshot_complete=1 THEN subject_snapshots.units_snapshot ELSE NULL END AS units,
                    subjects.subject_code AS current_subject_code,
                    subjects.subject_title AS current_subject_title,
                    subjects.units AS current_units,
                    subject_snapshots.lecture_units_snapshot AS lecture_units,
                    subject_snapshots.laboratory_units_snapshot AS laboratory_units,
                    subject_snapshots.description_snapshot AS description,
                    subject_snapshots.captured_at AS snapshot_captured_at,
                    subject_snapshots.capture_method,
                    COALESCE(subject_snapshots.snapshot_complete, 0) AS snapshot_complete,
                    curriculum_versions.version_label, curriculum_versions.status AS version_status,
                    curriculum_versions.created_at AS version_created_at, curriculum_versions.updated_at AS version_updated_at,
                    curricula.curriculum_code, curricula.curriculum_name,
                    academic_programs.program_code, academic_programs.program_name, academic_programs.program_type
             FROM curriculum_version_subjects cvs
             JOIN subjects ON subjects.id = cvs.subject_id
             JOIN curriculum_versions ON curriculum_versions.id = cvs.curriculum_version_id
             JOIN curricula ON curricula.id = curriculum_versions.curriculum_id
             JOIN academic_programs ON academic_programs.id = curricula.academic_program_id
             LEFT JOIN curriculum_version_subject_snapshots AS subject_snapshots
                ON subject_snapshots.curriculum_version_subject_id=cvs.id
             WHERE cvs.id = ? LIMIT 1', 'i', [$placementId]);
        $record = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $record;
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
            "SELECT subjects.id AS subject_id,
                    CASE WHEN snapshot_a.snapshot_complete=1 THEN snapshot_a.subject_code_snapshot
                         WHEN snapshot_b.snapshot_complete=1 THEN snapshot_b.subject_code_snapshot ELSE NULL END AS subject_code,
                    CASE WHEN snapshot_a.snapshot_complete=1 THEN snapshot_a.subject_title_snapshot
                         WHEN snapshot_b.snapshot_complete=1 THEN snapshot_b.subject_title_snapshot ELSE NULL END AS subject_title,
                    snapshot_a.subject_code_snapshot AS version_a_subject_code,
                    snapshot_a.subject_title_snapshot AS version_a_subject_title,
                    snapshot_a.units_snapshot AS version_a_units,
                    snapshot_a.lecture_units_snapshot AS version_a_lecture_units,
                    snapshot_a.laboratory_units_snapshot AS version_a_laboratory_units,
                    snapshot_a.description_snapshot AS version_a_description,
                    snapshot_a.captured_at AS version_a_snapshot_captured_at,
                    snapshot_a.snapshot_complete AS version_a_snapshot_complete,
                    snapshot_b.subject_code_snapshot AS version_b_subject_code,
                    snapshot_b.subject_title_snapshot AS version_b_subject_title,
                    snapshot_b.units_snapshot AS version_b_units,
                    snapshot_b.lecture_units_snapshot AS version_b_lecture_units,
                    snapshot_b.laboratory_units_snapshot AS version_b_laboratory_units,
                    snapshot_b.description_snapshot AS version_b_description,
                    snapshot_b.captured_at AS version_b_snapshot_captured_at,
                    snapshot_b.snapshot_complete AS version_b_snapshot_complete,
                    placement_a.id AS version_a_placement_id,
                    placement_a.year_level AS version_a_year_level,
                    placement_a.semester AS version_a_semester,
                    placement_a.sequence_order AS version_a_sequence_order,
                    placement_a.is_required AS version_a_is_required,
                    placement_a.created_at AS version_a_stored_date,
                    placement_b.id AS version_b_placement_id,
                    placement_b.year_level AS version_b_year_level,
                    placement_b.semester AS version_b_semester,
                    placement_b.sequence_order AS version_b_sequence_order,
                    placement_b.is_required AS version_b_is_required,
                    placement_b.created_at AS version_b_stored_date,
                    CASE
                        WHEN placement_a.subject_id IS NULL THEN 'added'
                        WHEN placement_b.subject_id IS NULL THEN 'removed'
                        WHEN NOT (placement_a.year_level <=> placement_b.year_level
                                  AND placement_a.semester <=> placement_b.semester) THEN 'moved'
                        WHEN NOT (placement_a.is_required <=> placement_b.is_required) THEN 'placement_type_changed'
                        WHEN NOT (placement_a.sequence_order <=> placement_b.sequence_order) THEN 'moved'
                        ELSE 'unchanged'
                    END AS change_type"
            . $from .
            ' ORDER BY subject_code ASC, subjects.id ASC LIMIT ? OFFSET ?',
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
                    ON academic_programs.id = curricula.academic_program_id
                 LEFT JOIN curriculum_version_subject_snapshots AS subject_snapshots
                    ON subject_snapshots.curriculum_version_subject_id=cvs.id';
    }

    private function historyConditions(array $query): array
    {
        $conditions = [];
        $types = '';
        $params = [];
        foreach ([
            'subject_id' => 'subjects.id',
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
                   AND placement_b.curriculum_version_id = ?
                 LEFT JOIN curriculum_version_subject_snapshots AS snapshot_a
                    ON snapshot_a.curriculum_version_subject_id=placement_a.id
                 LEFT JOIN curriculum_version_subject_snapshots AS snapshot_b
                    ON snapshot_b.curriculum_version_subject_id=placement_b.id';
    }

    private function comparisonSummary(int $versionAId, int $versionBId): array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            "SELECT
                COUNT(DISTINCT CASE WHEN placement_a.subject_id IS NOT NULL THEN subjects.id END) AS version_a_subjects,
                COUNT(DISTINCT CASE WHEN placement_b.subject_id IS NOT NULL THEN subjects.id END) AS version_b_subjects,
                CASE WHEN SUM(CASE WHEN placement_a.subject_id IS NOT NULL THEN 1 ELSE 0 END)=0 THEN 0
                     WHEN SUM(CASE WHEN placement_a.subject_id IS NOT NULL AND snapshot_a.snapshot_complete=1 THEN 1 ELSE 0 END)=SUM(CASE WHEN placement_a.subject_id IS NOT NULL THEN 1 ELSE 0 END)
                     THEN SUM(CASE WHEN placement_a.subject_id IS NOT NULL THEN snapshot_a.units_snapshot ELSE 0 END) ELSE NULL END AS version_a_units,
                CASE WHEN SUM(CASE WHEN placement_b.subject_id IS NOT NULL THEN 1 ELSE 0 END)=0 THEN 0
                     WHEN SUM(CASE WHEN placement_b.subject_id IS NOT NULL AND snapshot_b.snapshot_complete=1 THEN 1 ELSE 0 END)=SUM(CASE WHEN placement_b.subject_id IS NOT NULL THEN 1 ELSE 0 END)
                     THEN SUM(CASE WHEN placement_b.subject_id IS NOT NULL THEN snapshot_b.units_snapshot ELSE 0 END) ELSE NULL END AS version_b_units,
                SUM(CASE WHEN placement_a.subject_id IS NOT NULL AND snapshot_a.snapshot_complete=1 THEN 1 ELSE 0 END) AS version_a_captured_subjects,
                SUM(CASE WHEN placement_b.subject_id IS NOT NULL AND snapshot_b.snapshot_complete=1 THEN 1 ELSE 0 END) AS version_b_captured_subjects,
                SUM(CASE WHEN placement_a.subject_id IS NULL THEN 1 ELSE 0 END) AS added,
                SUM(CASE WHEN placement_b.subject_id IS NULL THEN 1 ELSE 0 END) AS removed,
                SUM(CASE WHEN placement_a.subject_id IS NOT NULL
                              AND placement_b.subject_id IS NOT NULL
                              AND NOT (placement_a.year_level <=> placement_b.year_level
                                       AND placement_a.semester <=> placement_b.semester) THEN 1 ELSE 0 END) AS moved,
                SUM(CASE WHEN placement_a.subject_id IS NOT NULL
                              AND placement_b.subject_id IS NOT NULL
                              AND placement_a.is_required <> placement_b.is_required THEN 1 ELSE 0 END) AS placement_type_changed,
                SUM(CASE WHEN placement_a.subject_id IS NOT NULL
                              AND placement_b.subject_id IS NOT NULL
                              AND (NOT (placement_a.year_level <=> placement_b.year_level
                                        AND placement_a.semester <=> placement_b.semester)
                                   OR NOT (placement_a.sequence_order <=> placement_b.sequence_order)
                                   OR placement_a.is_required <> placement_b.is_required) THEN 1 ELSE 0 END) AS changed,
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
