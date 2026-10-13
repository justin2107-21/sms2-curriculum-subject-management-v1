<?php

require_once __DIR__ . '/RepositoryQuery.php';

class AcademicProgramRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM academic_programs WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    /** One authoritative aggregate, without pagination or lifecycle-column dependencies. */
    public function curriculumSummary(int $programId): array
    {
        $stmt=RepositoryQuery::execute($this->db,
            'SELECT COUNT(DISTINCT c.id) curricula,COUNT(DISTINCT v.id) versions,COUNT(p.id) placements
             FROM curricula c LEFT JOIN curriculum_versions v ON v.curriculum_id=c.id
             LEFT JOIN curriculum_version_subjects p ON p.curriculum_version_id=v.id
             WHERE c.academic_program_id=?','i',[$programId]);
        $row=$stmt->get_result()->fetch_assoc();$stmt->close();
        return array_map('intval',$row)+['scope'=>'All stored curricula and versions, including archived records; subjects are counted as version placements.'];
    }

    public function codeExists(string $code, ?int $excludeId = null): bool
    {
        if ($excludeId === null) {
            $stmt = $this->db->prepare('SELECT 1 FROM academic_programs WHERE program_code = ? LIMIT 1');
            $stmt->bind_param('s', $code);
        } else {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM academic_programs WHERE program_code = ? AND id != ? LIMIT 1'
            );
            $stmt->bind_param('si', $code, $excludeId);
        }
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $exists;
    }

    public function paginate(array $query): array
    {
        $conditions = ['archived_at IS NULL'];
        $types = '';
        $params = [];

        if ($query['search'] !== null) {
            $conditions[] = "(" . RepositoryQuery::searchColumn('program_code') . " LIKE ? ESCAPE '=' OR " . RepositoryQuery::searchColumn('program_name') . " LIKE ? ESCAPE '=')";
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
        // Match the existing Subject Master category normalization: Senior High,
        // SHS, and strand labels are SHS; other explicitly stored types are college.
        $isShsClassification = "(LOWER(TRIM(program_type)) LIKE '%senior high%' OR LOWER(TRIM(program_type)) = 'shs' OR LOWER(TRIM(program_type)) LIKE '%strand%')";
        if (($query['program_category'] ?? null) === 'shs') {
            $conditions[] = $isShsClassification;
        } elseif (($query['program_category'] ?? null) === 'college') {
            $conditions[] = "program_type IS NOT NULL AND TRIM(program_type) <> '' AND NOT " . $isShsClassification;
        }
        if (!empty($query['program_type'])) {
            if ($query['program_type'] === 'Other') {
                $conditions[] = "program_type IS NOT NULL AND program_type NOT IN (?, ?, ?, ?)";
                $types .= 'ssss';
                array_push($params, "Bachelor's Degree", 'Associate Degree', 'Diploma / Certificate', 'Senior High School');
            } else {
                $conditions[] = 'program_type = ?';
                $types .= 's';
                $params[] = $query['program_type'];
            }
        }

        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $countStmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS total FROM academic_programs' . $where,
            $types,
            $params
        );
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        $pagination = RepositoryQuery::pagination($total, $query['page'], $query['per_page']);
        $rankParams = [];
        $searchOrder = RepositoryQuery::searchOrder(['program_code', 'program_name'], $query['search'] ?? null, $rankParams);
        $listParams = array_merge($params, $rankParams);
        $listParams[] = $pagination['per_page'];
        $listParams[] = $pagination['offset'];
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT id, program_code, program_name, program_type, status, created_at, updated_at,
                    (SELECT COUNT(*) FROM curricula c WHERE c.academic_program_id = academic_programs.id) AS curriculum_count
             FROM academic_programs' . $where . '
             ORDER BY ' . $searchOrder . 'program_code ASC, id ASC LIMIT ? OFFSET ?',
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
            'INSERT INTO academic_programs
                (program_code, program_name, program_type, status, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'ssssi',
            $data['program_code'],
            $data['program_name'],
            $data['program_type'],
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
            'UPDATE academic_programs
             SET program_code = ?, program_name = ?, program_type = ?, status = ?
             WHERE id = ?'
        );
        $stmt->bind_param(
            'ssssi',
            $data['program_code'],
            $data['program_name'],
            $data['program_type'],
            $data['status'],
            $id
        );
        $stmt->execute();
        $stmt->close();
    }
}
