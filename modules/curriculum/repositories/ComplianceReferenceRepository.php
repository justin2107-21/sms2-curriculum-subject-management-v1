<?php

require_once __DIR__ . '/RepositoryQuery.php';

class ComplianceReferenceRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            $this->detailSelect() . ' WHERE references_table.id = ? LIMIT 1',
            'i',
            [$id]
        );
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function findByIdForUpdate(int $id): ?array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT * FROM compliance_references WHERE id = ? LIMIT 1 FOR UPDATE',
            'i',
            [$id]
        );
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function codeExists(string $type, ?string $code, ?int $excludeId = null): bool
    {
        if ($code === null) {
            return false;
        }
        $sql = 'SELECT 1 FROM compliance_references
                WHERE reference_type = ? AND reference_code = ?';
        $types = 'ss';
        $params = [$type, $code];
        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $types .= 'i';
            $params[] = $excludeId;
        }
        $stmt = RepositoryQuery::execute($this->db, $sql . ' LIMIT 1', $types, $params);
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
            $conditions[] = "(" . RepositoryQuery::searchColumn('references_table.reference_code') . " LIKE ? ESCAPE '='
                              OR " . RepositoryQuery::searchColumn('references_table.title') . " LIKE ? ESCAPE '='
                              OR " . RepositoryQuery::searchColumn('references_table.issuing_body') . " LIKE ? ESCAPE '=')";
            $pattern = RepositoryQuery::likePattern($query['search']);
            $types .= 'sss';
            array_push($params, $pattern, $pattern, $pattern);
        }
        if ($query['reference_type'] !== null) {
            $conditions[] = 'references_table.reference_type = ?';
            $types .= 's';
            $params[] = $query['reference_type'];
        }
        if ($query['verification_status'] !== null) {
            $conditions[] = 'references_table.verification_status = ?';
            $types .= 's';
            $params[] = $query['verification_status'];
        }
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $countStmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS total FROM compliance_references AS references_table' . $where,
            $types,
            $params
        );
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();
        $pagination = RepositoryQuery::pagination($total, $query['page'], $query['per_page']);
        $rankParams = [];
        $searchOrder = RepositoryQuery::searchOrder(['references_table.reference_code', 'references_table.title', 'references_table.issuing_body'], $query['search'] ?? null, $rankParams);
        $listParams = array_merge($params, $rankParams);
        $listParams[] = $pagination['per_page'];
        $listParams[] = $pagination['offset'];
        $stmt = RepositoryQuery::execute(
            $this->db,
            $this->detailSelect() . $where .
            ' ORDER BY ' . $searchOrder . 'references_table.updated_at DESC, references_table.id DESC LIMIT ? OFFSET ?',
            $types . str_repeat('s', count($rankParams)) . 'ii',
            $listParams
        );
        $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        unset($pagination['offset']);
        return ['records' => $records, 'pagination' => $pagination];
    }

    public function create(array $data, int $createdBy): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO compliance_references
                (reference_type, reference_code, title, issuing_body, source_url,
                 effective_date, notes, verification_status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'ssssssssi',
            $data['reference_type'],
            $data['reference_code'],
            $data['title'],
            $data['issuing_body'],
            $data['source_url'],
            $data['effective_date'],
            $data['notes'],
            $data['verification_status'],
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
            'UPDATE compliance_references
             SET reference_type = ?, reference_code = ?, title = ?, issuing_body = ?,
                 source_url = ?, effective_date = ?, notes = ?, verification_status = ?
             WHERE id = ?'
        );
        $stmt->bind_param(
            'ssssssssi',
            $data['reference_type'],
            $data['reference_code'],
            $data['title'],
            $data['issuing_body'],
            $data['source_url'],
            $data['effective_date'],
            $data['notes'],
            $data['verification_status'],
            $id
        );
        $stmt->execute();
        $stmt->close();
    }

    private function detailSelect(): string
    {
        return 'SELECT references_table.*, creator.username AS creator_username,
                       (SELECT COUNT(*) FROM compliance_rules AS linked_rules
                        WHERE linked_rules.compliance_reference_id = references_table.id) AS rule_count
                FROM compliance_references AS references_table
                LEFT JOIN users AS creator ON creator.id = references_table.created_by';
    }
}
