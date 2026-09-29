<?php

require_once __DIR__ . '/RepositoryQuery.php';

class SubjectRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM subjects WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function codeExists(string $code, ?int $excludeId = null): bool
    {
        if ($excludeId === null) {
            $stmt = $this->db->prepare('SELECT 1 FROM subjects WHERE subject_code = ? LIMIT 1');
            $stmt->bind_param('s', $code);
        } else {
            $stmt = $this->db->prepare('SELECT 1 FROM subjects WHERE subject_code = ? AND id != ? LIMIT 1');
            $stmt->bind_param('si', $code, $excludeId);
        }
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $exists;
    }

    public function paginate(array $query): array
    {
        $conditions = [];
        $types = '';
        $params = [];

        if ($query['search'] !== null) {
            $conditions[] = "(subject_code LIKE ? ESCAPE '=' OR subject_title LIKE ? ESCAPE '=')";
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

        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
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
            'SELECT id, subject_code, subject_title, units, lecture_units,
                    laboratory_units, status, created_at, updated_at
             FROM subjects' . $where . '
             ORDER BY subject_code ASC, id ASC LIMIT ? OFFSET ?',
            $types . 'ii',
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
            'INSERT INTO subjects
                (subject_code, subject_title, description, units, lecture_units,
                 laboratory_units, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'sssdddsi',
            $data['subject_code'],
            $data['subject_title'],
            $data['description'],
            $data['units'],
            $data['lecture_units'],
            $data['laboratory_units'],
            $data['status'],
            $createdBy
        );
        $stmt->execute();
        $id = (int)$this->db->insert_id;
        $stmt->close();
        return $id;
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE subjects
             SET subject_code = ?, subject_title = ?, description = ?, units = ?,
                 lecture_units = ?, laboratory_units = ?, status = ?
             WHERE id = ?'
        );
        $stmt->bind_param(
            'sssdddsi',
            $data['subject_code'],
            $data['subject_title'],
            $data['description'],
            $data['units'],
            $data['lecture_units'],
            $data['laboratory_units'],
            $data['status'],
            $id
        );
        $stmt->execute();
        $stmt->close();
    }
}
