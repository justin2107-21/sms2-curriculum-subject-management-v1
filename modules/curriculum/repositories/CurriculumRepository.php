<?php

require_once __DIR__ . '/RepositoryQuery.php';

class CurriculumRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM curricula WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function findDetailById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT curricula.*, academic_programs.program_code, academic_programs.program_name,
                    academic_programs.program_type, academic_programs.status AS program_status
             FROM curricula
             INNER JOIN academic_programs ON academic_programs.id = curricula.academic_program_id
             WHERE curricula.id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function codeExists(string $code, ?int $excludeId = null): bool
    {
        if ($excludeId === null) {
            $stmt = $this->db->prepare('SELECT 1 FROM curricula WHERE curriculum_code = ? LIMIT 1');
            $stmt->bind_param('s', $code);
        } else {
            $stmt = $this->db->prepare('SELECT 1 FROM curricula WHERE curriculum_code = ? AND id != ? LIMIT 1');
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
            $conditions[] = "(curricula.curriculum_code LIKE ? ESCAPE '='
                              OR curricula.curriculum_name LIKE ? ESCAPE '=')";
            $pattern = RepositoryQuery::likePattern($query['search']);
            $types .= 'ss';
            $params[] = $pattern;
            $params[] = $pattern;
        }
        if ($query['status'] !== null) {
            $conditions[] = 'curricula.status = ?';
            $types .= 's';
            $params[] = $query['status'];
        }
        if ($query['academic_program_id'] !== null) {
            $conditions[] = 'curricula.academic_program_id = ?';
            $types .= 'i';
            $params[] = $query['academic_program_id'];
        }

        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $countStmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS total FROM curricula' . $where,
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
            'SELECT curricula.id, curricula.curriculum_code, curricula.curriculum_name,
                    curricula.academic_program_id, curricula.status, curricula.created_at,
                    curricula.updated_at, academic_programs.program_code,
                    academic_programs.program_name
             FROM curricula
             INNER JOIN academic_programs ON academic_programs.id = curricula.academic_program_id' . $where . '
             ORDER BY curricula.curriculum_code ASC, curricula.id ASC LIMIT ? OFFSET ?',
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
            'INSERT INTO curricula
                (curriculum_code, curriculum_name, academic_program_id, status, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'ssisi',
            $data['curriculum_code'],
            $data['curriculum_name'],
            $data['academic_program_id'],
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
            'UPDATE curricula
             SET curriculum_code = ?, curriculum_name = ?, academic_program_id = ?, status = ?
             WHERE id = ?'
        );
        $stmt->bind_param(
            'ssisi',
            $data['curriculum_code'],
            $data['curriculum_name'],
            $data['academic_program_id'],
            $data['status'],
            $id
        );
        $stmt->execute();
        $stmt->close();
    }
}
