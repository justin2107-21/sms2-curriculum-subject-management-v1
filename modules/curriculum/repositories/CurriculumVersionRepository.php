<?php

require_once __DIR__ . '/RepositoryQuery.php';

class CurriculumVersionRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM curriculum_versions WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function labelExists(int $curriculumId, string $label): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM curriculum_versions WHERE curriculum_id = ? AND version_label = ? LIMIT 1'
        );
        $stmt->bind_param('is', $curriculumId, $label);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $exists;
    }

    public function paginateByCurriculum(int $curriculumId, array $query): array
    {
        $conditions = ['curriculum_id = ?'];
        $types = 'i';
        $params = [$curriculumId];

        if ($query['search'] !== null) {
            $conditions[] = "(" . RepositoryQuery::searchColumn('version_label') . " LIKE ? ESCAPE '='
                              OR " . RepositoryQuery::searchColumn('effective_academic_year') . " LIKE ? ESCAPE '=')";
            $pattern = RepositoryQuery::likePattern($query['search']);
            $types .= 'ss';
            $params[] = $pattern;
            $params[] = $pattern;
        }
        if ($query['status'] !== null) {
            $conditions[] = 'status = ?';
            $types .= 's';
            $params[] = $query['status'];
        }

        $where = ' WHERE ' . implode(' AND ', $conditions);
        $countStmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS total FROM curriculum_versions' . $where,
            $types,
            $params
        );
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        $pagination = RepositoryQuery::pagination($total, $query['page'], $query['per_page']);
        $rankParams = [];
        $searchOrder = RepositoryQuery::searchOrder(['version_label', 'effective_academic_year'], $query['search'] ?? null, $rankParams);
        $listParams = array_merge($params, $rankParams);
        $listParams[] = $pagination['per_page'];
        $listParams[] = $pagination['offset'];
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT id, curriculum_id, version_label, effective_academic_year,
                    description, status, created_at, updated_at
             FROM curriculum_versions' . $where . '
             ORDER BY ' . $searchOrder . 'created_at DESC, id DESC LIMIT ? OFFSET ?',
            $types . str_repeat('s', count($rankParams)) . 'ii',
            $listParams
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        unset($pagination['offset']);

        return ['records' => $rows, 'pagination' => $pagination];
    }

    public function create(array $data, int $createdBy): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO curriculum_versions
                (curriculum_id, version_label, effective_academic_year,
                 description, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'issssi',
            $data['curriculum_id'],
            $data['version_label'],
            $data['effective_academic_year'],
            $data['description'],
            $data['status'],
            $createdBy
        );
        $stmt->execute();
        $id = (int)$this->db->insert_id;
        $stmt->close();
        return $id;
    }
}
