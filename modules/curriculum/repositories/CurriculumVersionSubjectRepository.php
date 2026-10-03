<?php

require_once __DIR__ . '/RepositoryQuery.php';

class CurriculumVersionSubjectRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM curriculum_version_subjects WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function assignmentExists(int $versionId, int $subjectId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM curriculum_version_subjects
             WHERE curriculum_version_id = ? AND subject_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $versionId, $subjectId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $exists;
    }

    public function paginateByVersion(int $versionId, array $query): array
    {
        $conditions = ['curriculum_version_subjects.curriculum_version_id = ?'];
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
            'SELECT COUNT(*) AS total
             FROM curriculum_version_subjects
             INNER JOIN subjects ON subjects.id = curriculum_version_subjects.subject_id' . $where,
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
            'SELECT curriculum_version_subjects.*, subjects.subject_code, subjects.subject_title,
                    subjects.units, subjects.lecture_units, subjects.laboratory_units
             FROM curriculum_version_subjects
             INNER JOIN subjects ON subjects.id = curriculum_version_subjects.subject_id
             ' . $where . '
             ORDER BY curriculum_version_subjects.sequence_order IS NULL,
                      curriculum_version_subjects.sequence_order,
                      subjects.subject_code, curriculum_version_subjects.id
             LIMIT ? OFFSET ?',
            $types . 'ii',
            $listParams
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        unset($pagination['offset']);

        return ['records' => $rows, 'pagination' => $pagination];
    }

    public function findDetailedById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT cvs.id, cvs.curriculum_version_id, cvs.subject_id,
                    cvs.year_level, cvs.semester, cvs.sequence_order, cvs.is_required,
                    cvs.created_at, cvs.updated_at,
                    subjects.subject_code, subjects.subject_title, subjects.units,
                    curriculum_versions.version_label,
                    curriculum_versions.effective_academic_year,
                    curricula.id AS curriculum_id, curricula.curriculum_code,
                    curricula.curriculum_name,
                    academic_programs.id AS academic_program_id,
                    academic_programs.program_code, academic_programs.program_name
             FROM curriculum_version_subjects AS cvs
             INNER JOIN subjects ON subjects.id = cvs.subject_id
             INNER JOIN curriculum_versions ON curriculum_versions.id = cvs.curriculum_version_id
             INNER JOIN curricula ON curricula.id = curriculum_versions.curriculum_id
             INNER JOIN academic_programs ON academic_programs.id = curricula.academic_program_id
             WHERE cvs.id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function paginateMappings(array $query): array
    {
        [$conditions, $types, $params] = $this->mappingConditions($query);
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
                    academic_programs.program_code, academic_programs.program_name'
            . $joins . $where .
            ' ORDER BY academic_programs.program_code ASC,
                       curricula.curriculum_code ASC,
                       curriculum_versions.version_label ASC,
                       COALESCE(cvs.year_level, \'\') ASC,
                       COALESCE(cvs.semester, \'\') ASC,
                       cvs.sequence_order IS NULL,
                       cvs.sequence_order ASC,
                       subjects.subject_code ASC,
                       cvs.id ASC
              LIMIT ? OFFSET ?',
            $types . 'ii',
            $listParams
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        unset($pagination['offset']);

        return ['records' => $rows, 'pagination' => $pagination];
    }

    public function mappingFilterValues(array $query): array
    {
        $contextQuery = $query;
        $contextQuery['search'] = null;
        [$conditions, $types, $params] = $this->mappingConditions($contextQuery, false);
        $joins = ' FROM curriculum_version_subjects AS cvs
                   INNER JOIN curriculum_versions ON curriculum_versions.id = cvs.curriculum_version_id
                   INNER JOIN curricula ON curricula.id = curriculum_versions.curriculum_id
                   INNER JOIN academic_programs ON academic_programs.id = curricula.academic_program_id';

        return [
            'year_levels' => $this->distinctMappingValues(
                'cvs.year_level',
                $joins,
                $conditions,
                $types,
                $params
            ),
            'semesters' => $this->distinctMappingValues(
                'cvs.semester',
                $joins,
                $conditions,
                $types,
                $params
            ),
        ];
    }

    public function create(array $data, int $createdBy): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO curriculum_version_subjects
                (curriculum_version_id, subject_id, year_level, semester,
                 sequence_order, is_required, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'iissiii',
            $data['curriculum_version_id'],
            $data['subject_id'],
            $data['year_level'],
            $data['semester'],
            $data['sequence_order'],
            $data['is_required'],
            $createdBy
        );
        $stmt->execute();
        $id = (int)$this->db->insert_id;
        $stmt->close();
        return $id;
    }

    public function updatePlacement(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE curriculum_version_subjects
             SET year_level = ?, semester = ?, sequence_order = ?, is_required = ?
             WHERE id = ?'
        );
        $stmt->bind_param(
            'ssiii',
            $data['year_level'],
            $data['semester'],
            $data['sequence_order'],
            $data['is_required'],
            $id
        );
        $stmt->execute();
        $stmt->close();
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM curriculum_version_subjects WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    private function mappingConditions(array $query, bool $includePlacementFilters = true): array
    {
        $conditions = [];
        $types = '';
        $params = [];

        if (($query['subject_id'] ?? null) !== null) {
            $conditions[] = 'cvs.subject_id = ?';
            $types .= 'i';
            $params[] = $query['subject_id'];
        }

        if ($query['academic_program_id'] !== null) {
            $conditions[] = 'academic_programs.id = ?';
            $types .= 'i';
            $params[] = $query['academic_program_id'];
        }
        if ($query['curriculum_id'] !== null) {
            $conditions[] = 'curricula.id = ?';
            $types .= 'i';
            $params[] = $query['curriculum_id'];
        }
        if ($query['curriculum_version_id'] !== null) {
            $conditions[] = 'curriculum_versions.id = ?';
            $types .= 'i';
            $params[] = $query['curriculum_version_id'];
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

    private function distinctMappingValues(
        string $column,
        string $joins,
        array $conditions,
        string $types,
        array $params
    ): array {
        $valueConditions = $conditions;
        $valueConditions[] = $column . ' IS NOT NULL';
        $valueConditions[] = $column . " <> ''";
        $where = ' WHERE ' . implode(' AND ', $valueConditions);
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT DISTINCT ' . $column . ' AS value' . $joins . $where .
            ' ORDER BY value ASC LIMIT 100',
            $types,
            $params
        );
        $values = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'value');
        $stmt->close();
        return $values;
    }
}
