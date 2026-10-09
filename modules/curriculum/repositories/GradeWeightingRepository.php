<?php

require_once __DIR__ . '/RepositoryQuery.php';

class GradeWeightingRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function findSchemeById(int $id, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM grade_weighting_schemes WHERE id = ? LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = RepositoryQuery::execute($this->db, $sql, 'i', [$id]);
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function findSchemeByPlacement(int $placementId, bool $forUpdate = false): ?array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            $this->schemeDetailSelect() .
            ' WHERE schemes.curriculum_version_subject_id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''),
            'i',
            [$placementId]
        );
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function findDetailedSchemeById(int $id): ?array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            $this->schemeDetailSelect() . ' WHERE schemes.id = ? LIMIT 1',
            'i',
            [$id]
        );
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function placementHasScheme(int $placementId, bool $forUpdate = false): bool
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT 1 FROM grade_weighting_schemes
              WHERE curriculum_version_subject_id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''),
            'i',
            [$placementId]
        );
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $exists;
    }

    public function createScheme(array $data, int $createdBy): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO grade_weighting_schemes
                (curriculum_version_subject_id, description, created_by)
             VALUES (?, ?, ?)'
        );
        $stmt->bind_param(
            'isi',
            $data['curriculum_version_subject_id'],
            $data['description'],
            $createdBy
        );
        $stmt->execute();
        $id = (int)$this->db->insert_id;
        $stmt->close();
        return $id;
    }

    public function updateScheme(int $id, ?string $description): void
    {
        $stmt = $this->db->prepare(
            'UPDATE grade_weighting_schemes SET description = ? WHERE id = ?'
        );
        $stmt->bind_param('si', $description, $id);
        $stmt->execute();
        $stmt->close();
    }

    public function deleteScheme(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM grade_weighting_schemes WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    public function paginateComponents(int $schemeId, array $query): array
    {
        $conditions = ['scheme_id = ?'];
        $types = 'i';
        $params = [$schemeId];
        if ($query['search'] !== null) {
            $conditions[] = RepositoryQuery::searchColumn('component_name') . " LIKE ? ESCAPE '='";
            $types .= 's';
            $params[] = RepositoryQuery::likePattern($query['search']);
        }
        $where = ' WHERE ' . implode(' AND ', $conditions);
        $countStmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS total FROM grade_weighting_components' . $where,
            $types,
            $params
        );
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();
        $pagination = RepositoryQuery::pagination($total, $query['page'], $query['per_page']);
        $rankParams = [];
        $searchOrder = RepositoryQuery::searchOrder(['component_name'], $query['search'] ?? null, $rankParams);
        $listParams = array_merge($params, $rankParams);
        $listParams[] = $pagination['per_page'];
        $listParams[] = $pagination['offset'];
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT id, scheme_id, component_name, weight_percentage,
                    sequence_order, created_at, updated_at
             FROM grade_weighting_components' . $where . '
             ORDER BY ' . $searchOrder . 'sequence_order IS NULL, sequence_order ASC,
                      component_name ASC, id ASC LIMIT ? OFFSET ?',
            $types . str_repeat('s', count($rankParams)) . 'ii',
            $listParams
        );
        $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        unset($pagination['offset']);
        return ['records' => $records, 'pagination' => $pagination];
    }

    public function allComponents(int $schemeId, bool $forUpdate = false): array
    {
        $sql = 'SELECT id, scheme_id, component_name, weight_percentage, sequence_order,
                       created_by, created_at, updated_at
                FROM grade_weighting_components WHERE scheme_id = ?
                ORDER BY sequence_order IS NULL, sequence_order ASC, id ASC';
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $stmt = RepositoryQuery::execute($this->db, $sql, 'i', [$schemeId]);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function findComponentById(int $id, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM grade_weighting_components WHERE id = ? LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = RepositoryQuery::execute($this->db, $sql, 'i', [$id]);
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function componentNameExists(string $name, int $schemeId, ?int $excludeId = null): bool
    {
        if ($excludeId === null) {
            $sql = 'SELECT 1 FROM grade_weighting_components
                    WHERE scheme_id = ? AND component_name = ? LIMIT 1';
            $types = 'is';
            $params = [$schemeId, $name];
        } else {
            $sql = 'SELECT 1 FROM grade_weighting_components
                    WHERE scheme_id = ? AND component_name = ? AND id != ? LIMIT 1';
            $types = 'isi';
            $params = [$schemeId, $name, $excludeId];
        }
        $stmt = RepositoryQuery::execute($this->db, $sql, $types, $params);
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $exists;
    }

    public function sumComponentWeights(int $schemeId, ?int $excludeId = null): string
    {
        $sql = 'SELECT CAST(COALESCE(SUM(weight_percentage), 0) AS DECIMAL(7,2)) AS total
                FROM grade_weighting_components WHERE scheme_id = ?';
        $types = 'i';
        $params = [$schemeId];
        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $types .= 'i';
            $params[] = $excludeId;
        }
        $stmt = RepositoryQuery::execute($this->db, $sql, $types, $params);
        $total = (string)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        return $total;
    }

    public function componentCount(int $schemeId): int
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS total FROM grade_weighting_components WHERE scheme_id = ?',
            'i',
            [$schemeId]
        );
        $count = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        return $count;
    }

    public function createComponent(array $data, int $createdBy): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO grade_weighting_components
                (scheme_id, component_name, weight_percentage, sequence_order, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'issii',
            $data['scheme_id'],
            $data['component_name'],
            $data['weight_percentage'],
            $data['sequence_order'],
            $createdBy
        );
        $stmt->execute();
        $id = (int)$this->db->insert_id;
        $stmt->close();
        return $id;
    }

    public function updateComponent(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE grade_weighting_components
             SET component_name = ?, weight_percentage = ?, sequence_order = ?
             WHERE id = ?'
        );
        $stmt->bind_param(
            'ssii',
            $data['component_name'],
            $data['weight_percentage'],
            $data['sequence_order'],
            $id
        );
        $stmt->execute();
        $stmt->close();
    }

    public function deleteComponent(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM grade_weighting_components WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    private function schemeDetailSelect(): string
    {
        return 'SELECT schemes.id, schemes.curriculum_version_subject_id,
                       schemes.description, schemes.created_by, schemes.created_at,
                       schemes.updated_at, placements.curriculum_version_id,
                       placements.subject_id, placements.year_level, placements.semester,
                       placements.sequence_order AS placement_sequence_order,
                       subjects.subject_code, subjects.subject_title, subjects.units,
                       versions.version_label, versions.effective_academic_year,
                       curricula.id AS curriculum_id, curricula.curriculum_code,
                       curricula.curriculum_name, programs.id AS academic_program_id,
                       programs.program_code, programs.program_name,
                       (SELECT COUNT(*) FROM grade_weighting_components AS counts
                        WHERE counts.scheme_id = schemes.id) AS component_count,
                       (SELECT CAST(COALESCE(SUM(totals.weight_percentage), 0) AS DECIMAL(7,2))
                        FROM grade_weighting_components AS totals
                        WHERE totals.scheme_id = schemes.id) AS total_weight
                FROM grade_weighting_schemes AS schemes
                INNER JOIN curriculum_version_subjects AS placements
                    ON placements.id = schemes.curriculum_version_subject_id
                INNER JOIN subjects ON subjects.id = placements.subject_id
                INNER JOIN curriculum_versions AS versions
                    ON versions.id = placements.curriculum_version_id
                INNER JOIN curricula ON curricula.id = versions.curriculum_id
                INNER JOIN academic_programs AS programs
                    ON programs.id = curricula.academic_program_id';
    }
}
