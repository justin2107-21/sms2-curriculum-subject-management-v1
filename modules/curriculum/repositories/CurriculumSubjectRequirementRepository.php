<?php

require_once __DIR__ . '/RepositoryQuery.php';

class CurriculumSubjectRequirementRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            $this->detailSelect() . ' WHERE requirements.id = ? LIMIT 1',
            'i',
            [$id]
        );
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function allPrerequisites(int $versionId): array
    {
        $stmt = RepositoryQuery::execute($this->db, $this->detailSelect() .
            " WHERE requirements.curriculum_version_id = ? AND requirements.requirement_type = 'prerequisite' ORDER BY requirements.id", 'i', [$versionId]);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function allPlacements(int $versionId): array
    {
        $stmt = RepositoryQuery::execute($this->db,
            'SELECT p.*, s.subject_code, s.subject_title FROM curriculum_version_subjects p
             INNER JOIN subjects s ON s.id = p.subject_id WHERE p.curriculum_version_id = ? ORDER BY s.subject_code', 'i', [$versionId]);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function relationshipExists(
        int $versionId,
        int $subjectId,
        int $requiredSubjectId,
        string $type
    ): bool {
        $stmt = $this->db->prepare(
            'SELECT 1
             FROM curriculum_subject_requirements
             WHERE curriculum_version_id = ?
               AND subject_id = ?
               AND required_subject_id = ?
               AND requirement_type = ?
             LIMIT 1'
        );
        $stmt->bind_param('iiis', $versionId, $subjectId, $requiredSubjectId, $type);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $exists;
    }

    public function paginate(array $query): array
    {
        [$conditions, $types, $params] = $this->conditions($query);
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $joins = $this->joins();

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
            $this->detailSelect() . $where .
            ' ORDER BY academic_programs.program_code ASC,
                       curricula.curriculum_code ASC,
                       curriculum_versions.version_label ASC,
                       target_subjects.subject_code ASC,
                       requirements.requirement_type ASC,
                       required_subjects.subject_code ASC,
                       requirements.id ASC
              LIMIT ? OFFSET ?',
            $types . 'ii',
            $listParams
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        unset($pagination['offset']);

        return ['records' => $rows, 'pagination' => $pagination];
    }

    public function paginatePlacementOptions(int $versionId, array $query): array
    {
        $conditions = ['placements.curriculum_version_id = ?'];
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
        $joins = ' FROM curriculum_version_subjects AS placements
                   INNER JOIN subjects ON subjects.id = placements.subject_id';
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
            'SELECT placements.id AS placement_id, placements.curriculum_version_id,
                    placements.subject_id, placements.year_level, placements.semester,
                    placements.sequence_order, subjects.subject_code, subjects.subject_title
             ' . $joins . $where . '
             ORDER BY placements.sequence_order IS NULL,
                      placements.sequence_order ASC,
                      subjects.subject_code ASC,
                      placements.id ASC
             LIMIT ? OFFSET ?',
            $types . 'ii',
            $listParams
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        unset($pagination['offset']);

        return ['records' => $rows, 'pagination' => $pagination];
    }

    public function prerequisiteEdges(int $versionId): array
    {
        $stmt = $this->db->prepare(
            "SELECT subject_id, required_subject_id
             FROM curriculum_subject_requirements
             WHERE curriculum_version_id = ? AND requirement_type = 'prerequisite'"
        );
        $stmt->bind_param('i', $versionId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function placementHasRelationships(int $versionId, int $subjectId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1
             FROM curriculum_subject_requirements
             WHERE curriculum_version_id = ?
               AND (subject_id = ? OR required_subject_id = ?)
             LIMIT 1'
        );
        $stmt->bind_param('iii', $versionId, $subjectId, $subjectId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $exists;
    }

    public function lockVersion(int $versionId): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM curriculum_versions WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $versionId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $exists;
    }

    public function versionExists(int $versionId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM curriculum_versions WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $versionId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $exists;
    }

    public function create(array $data, int $createdBy): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO curriculum_subject_requirements
                (curriculum_version_id, subject_id, required_subject_id,
                 requirement_type, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'iiisi',
            $data['curriculum_version_id'],
            $data['subject_id'],
            $data['required_subject_id'],
            $data['requirement_type'],
            $createdBy
        );
        $stmt->execute();
        $id = (int)$this->db->insert_id;
        $stmt->close();
        return $id;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM curriculum_subject_requirements WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $deleted = $stmt->affected_rows === 1;
        $stmt->close();
        return $deleted;
    }

    private function conditions(array $query): array
    {
        $conditions = [];
        $types = '';
        $params = [];

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
            $conditions[] = "(target_subjects.subject_code LIKE ? ESCAPE '='
                              OR target_subjects.subject_title LIKE ? ESCAPE '=')";
            $pattern = RepositoryQuery::likePattern($query['search']);
            $types .= 'ss';
            $params[] = $pattern;
            $params[] = $pattern;
        }
        if ($query['requirement_type'] !== null) {
            $conditions[] = 'requirements.requirement_type = ?';
            $types .= 's';
            $params[] = $query['requirement_type'];
        }
        if ($query['subject_id'] !== null) {
            $conditions[] = 'requirements.subject_id = ?';
            $types .= 'i';
            $params[] = $query['subject_id'];
        }

        return [$conditions, $types, $params];
    }

    private function joins(): string
    {
        return ' FROM curriculum_subject_requirements AS requirements
                 LEFT JOIN curriculum_version_subjects AS target_placements
                    ON target_placements.curriculum_version_id = requirements.curriculum_version_id
                   AND target_placements.subject_id = requirements.subject_id
                 LEFT JOIN curriculum_version_subjects AS required_placements
                    ON required_placements.curriculum_version_id = requirements.curriculum_version_id
                   AND required_placements.subject_id = requirements.required_subject_id
                 INNER JOIN subjects AS target_subjects ON target_subjects.id = requirements.subject_id
                 INNER JOIN subjects AS required_subjects ON required_subjects.id = requirements.required_subject_id
                 INNER JOIN curriculum_versions ON curriculum_versions.id = requirements.curriculum_version_id
                 INNER JOIN curricula ON curricula.id = curriculum_versions.curriculum_id
                 INNER JOIN academic_programs ON academic_programs.id = curricula.academic_program_id';
    }

    private function detailSelect(): string
    {
        return 'SELECT requirements.id, requirements.curriculum_version_id,
                       requirements.subject_id, requirements.required_subject_id,
                       requirements.requirement_type, requirements.created_at,
                       requirements.updated_at,
                       target_subjects.subject_code, target_subjects.subject_title,
                       target_placements.year_level AS subject_year_level,
                       target_placements.semester AS subject_semester,
                       required_subjects.subject_code AS required_subject_code,
                       required_subjects.subject_title AS required_subject_title,
                       required_placements.year_level AS required_year_level,
                       required_placements.semester AS required_semester,
                       curriculum_versions.version_label,
                       curriculum_versions.effective_academic_year,
                       curricula.id AS curriculum_id, curricula.curriculum_code,
                       curricula.curriculum_name,
                       academic_programs.id AS academic_program_id,
                       academic_programs.program_code, academic_programs.program_name'
               . $this->joins();
    }
}
