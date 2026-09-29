<?php

require_once __DIR__ . '/RepositoryQuery.php';

/**
 * Joined read model for program-centric and subject-centric assignment views.
 *
 * curriculum_version_subjects remains the sole authoritative assignment
 * relationship. This repository adds no mutation path and no duplicate data.
 */
class AcademicProgramAssignmentRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function paginate(array $query, string $view): array
    {
        [$conditions, $types, $params] = $this->conditions($query);
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $joins = ' FROM curriculum_version_subjects AS cvs
                   INNER JOIN subjects ON subjects.id = cvs.subject_id
                   INNER JOIN curriculum_versions ON curriculum_versions.id = cvs.curriculum_version_id
                   INNER JOIN curricula ON curricula.id = curriculum_versions.curriculum_id
                   INNER JOIN academic_programs ON academic_programs.id = curricula.academic_program_id';

        $countStmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS total' . $joins . $where,
            $types,
            $params
        );
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        $pagination = RepositoryQuery::pagination($total, $query['page'], $query['per_page']);
        $listParams = $params;
        $listParams[] = $pagination['per_page'];
        $listParams[] = $pagination['offset'];
        $orderBy = $view === 'subject'
            ? 'subjects.subject_code ASC, academic_programs.program_code ASC,
               curricula.curriculum_code ASC, curriculum_versions.version_label ASC, cvs.id ASC'
            : "academic_programs.program_code ASC, curricula.curriculum_code ASC,
               curriculum_versions.version_label ASC, COALESCE(cvs.year_level, '') ASC,
               COALESCE(cvs.semester, '') ASC, cvs.sequence_order IS NULL,
               cvs.sequence_order ASC, subjects.subject_code ASC, cvs.id ASC";

        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT cvs.id, cvs.curriculum_version_id, cvs.subject_id,
                    cvs.year_level, cvs.semester, cvs.sequence_order, cvs.is_required,
                    cvs.created_at, cvs.updated_at,
                    subjects.subject_code, subjects.subject_title, subjects.units,
                    curriculum_versions.version_label,
                    curriculum_versions.effective_academic_year,
                    curricula.id AS curriculum_id, curricula.curriculum_code,
                    curricula.curriculum_name,
                    academic_programs.id AS academic_program_id,
                    academic_programs.program_code, academic_programs.program_name,
                    academic_programs.program_type,
                    (SELECT COUNT(DISTINCT usage_curricula.academic_program_id)
                     FROM curriculum_version_subjects AS usage_cvs
                     INNER JOIN curriculum_versions AS usage_versions
                        ON usage_versions.id = usage_cvs.curriculum_version_id
                     INNER JOIN curricula AS usage_curricula
                        ON usage_curricula.id = usage_versions.curriculum_id
                     WHERE usage_cvs.subject_id = cvs.subject_id) AS program_usage_count,
                    EXISTS(
                        SELECT 1 FROM curriculum_subject_requirements AS requirement_usage
                        WHERE requirement_usage.curriculum_version_id = cvs.curriculum_version_id
                          AND (requirement_usage.subject_id = cvs.subject_id
                               OR requirement_usage.required_subject_id = cvs.subject_id)
                    ) AS has_requirement_dependencies,
                    EXISTS(
                        SELECT 1 FROM curriculum_elective_group_subjects AS elective_usage
                        WHERE elective_usage.curriculum_version_id = cvs.curriculum_version_id
                          AND elective_usage.subject_id = cvs.subject_id
                    ) AS has_elective_dependencies'
            . $joins . $where .
            ' ORDER BY ' . $orderBy . ' LIMIT ? OFFSET ?',
            $types . 'ii',
            $listParams
        );
        $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        unset($pagination['offset']);

        return ['records' => $records, 'pagination' => $pagination];
    }

    public function filterValues(array $query): array
    {
        $contextQuery = $query;
        $contextQuery['search'] = null;
        [$conditions, $types, $params] = $this->conditions($contextQuery, false);
        $joins = ' FROM curriculum_version_subjects AS cvs
                   INNER JOIN curriculum_versions ON curriculum_versions.id = cvs.curriculum_version_id
                   INNER JOIN curricula ON curricula.id = curriculum_versions.curriculum_id
                   INNER JOIN academic_programs ON academic_programs.id = curricula.academic_program_id';

        return [
            'year_levels' => $this->distinctValues('cvs.year_level', $joins, $conditions, $types, $params),
            'semesters' => $this->distinctValues('cvs.semester', $joins, $conditions, $types, $params),
        ];
    }

    public function paginateAvailableSubjects(int $versionId, array $query): array
    {
        $conditions = [
            'NOT EXISTS (
                SELECT 1 FROM curriculum_version_subjects AS assigned
                WHERE assigned.curriculum_version_id = ?
                  AND assigned.subject_id = subjects.id
            )',
        ];
        $types = 'i';
        $params = [$versionId];

        if ($query['search'] !== null) {
            $conditions[] = "(subjects.subject_code LIKE ? ESCAPE '='
                              OR subjects.subject_title LIKE ? ESCAPE '=')";
            $pattern = RepositoryQuery::likePattern($query['search']);
            $types .= 'ss';
            $params[] = $pattern;
            $params[] = $pattern;
        }

        $where = ' WHERE ' . implode(' AND ', $conditions);
        $countStmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS total FROM subjects' . $where,
            $types,
            $params
        );
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();
        $pagination = RepositoryQuery::pagination($total, $query['page'], $query['per_page']);

        $listParams = $params;
        $listParams[] = $pagination['per_page'];
        $listParams[] = $pagination['offset'];
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT subjects.id, subjects.subject_code, subjects.subject_title,
                    subjects.units, subjects.status
             FROM subjects' . $where . '
             ORDER BY subjects.subject_code ASC, subjects.id ASC
             LIMIT ? OFFSET ?',
            $types . 'ii',
            $listParams
        );
        $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        unset($pagination['offset']);

        return ['records' => $records, 'pagination' => $pagination];
    }

    private function conditions(array $query, bool $includePlacementFilters = true): array
    {
        $conditions = [];
        $types = '';
        $params = [];

        foreach ([
            'academic_program_id' => ['academic_programs.id', 'Academic program'],
            'curriculum_id' => ['curricula.id', 'Curriculum'],
            'curriculum_version_id' => ['curriculum_versions.id', 'Curriculum version'],
        ] as $key => [$column]) {
            if ($query[$key] !== null) {
                $conditions[] = $column . ' = ?';
                $types .= 'i';
                $params[] = $query[$key];
            }
        }
        if ($query['search'] !== null) {
            $conditions[] = "(subjects.subject_code LIKE ? ESCAPE '='
                              OR subjects.subject_title LIKE ? ESCAPE '=')";
            $pattern = RepositoryQuery::likePattern($query['search']);
            $types .= 'ss';
            $params[] = $pattern;
            $params[] = $pattern;
        }
        if ($includePlacementFilters && $query['year_level'] !== null) {
            $conditions[] = 'cvs.year_level = ?';
            $types .= 's';
            $params[] = $query['year_level'];
        }
        if ($includePlacementFilters && $query['semester'] !== null) {
            $conditions[] = 'cvs.semester = ?';
            $types .= 's';
            $params[] = $query['semester'];
        }

        return [$conditions, $types, $params];
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
