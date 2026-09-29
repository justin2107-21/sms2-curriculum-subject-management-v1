<?php

require_once __DIR__ . '/RepositoryQuery.php';

class ComplianceRuleRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            $this->detailSelect() . ' WHERE rules.id = ? LIMIT 1',
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
            'SELECT * FROM compliance_rules WHERE id = ? LIMIT 1 FOR UPDATE',
            'i',
            [$id]
        );
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function nameExists(array $data, ?int $excludeId = null): bool
    {
        $conditions = [
            'compliance_reference_id = ?',
            'rule_name = ?',
        ];
        $types = 'is';
        $params = [$data['compliance_reference_id'], $data['rule_name']];
        if ($data['academic_program_id'] !== null) {
            $conditions[] = 'academic_program_id = ?';
            $types .= 'i';
            $params[] = $data['academic_program_id'];
        } else {
            $conditions[] = 'curriculum_id = ?';
            $types .= 'i';
            $params[] = $data['curriculum_id'];
        }
        if ($excludeId !== null) {
            $conditions[] = 'id != ?';
            $types .= 'i';
            $params[] = $excludeId;
        }
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT 1 FROM compliance_rules WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1',
            $types,
            $params
        );
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
            $conditions[] = "(rules.rule_name LIKE ? ESCAPE '='
                              OR references_table.reference_code LIKE ? ESCAPE '='
                              OR references_table.title LIKE ? ESCAPE '=')";
            $pattern = RepositoryQuery::likePattern($query['search']);
            $types .= 'sss';
            array_push($params, $pattern, $pattern, $pattern);
        }
        foreach ([
            'compliance_reference_id' => 'i',
            'academic_program_id' => 'i',
            'curriculum_id' => 'i',
            'rule_type' => 's',
            'technical_status' => 's',
        ] as $field => $type) {
            if ($query[$field] !== null) {
                $conditions[] = 'rules.' . $field . ' = ?';
                $types .= $type;
                $params[] = $query[$field];
            }
        }
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $countStmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS total' . $this->detailJoins() . $where,
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
            ' ORDER BY rules.updated_at DESC, rules.id DESC LIMIT ? OFFSET ?',
            $types . 'ii',
            $listParams
        );
        $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        unset($pagination['offset']);
        return ['records' => $records, 'pagination' => $pagination];
    }

    public function eligibleForContext(int $programId, int $curriculumId): array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            $this->detailSelect() .
            " WHERE rules.technical_status = 'active'
                AND references_table.verification_status = 'verified'
                AND (rules.academic_program_id = ? OR rules.curriculum_id = ?)
              ORDER BY rules.id ASC",
            'ii',
            [$programId, $curriculumId]
        );
        $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $records;
    }

    public function hasHistory(int $ruleId): bool
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT 1 FROM compliance_validation_results
             WHERE compliance_rule_id = ? LIMIT 1',
            'i',
            [$ruleId]
        );
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $exists;
    }

    public function create(array $data, int $createdBy): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO compliance_rules
                (compliance_reference_id, rule_name, rule_type, description,
                 numeric_value, subject_id, academic_program_id, curriculum_id,
                 technical_status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'issssiiisi',
            $data['compliance_reference_id'],
            $data['rule_name'],
            $data['rule_type'],
            $data['description'],
            $data['numeric_value'],
            $data['subject_id'],
            $data['academic_program_id'],
            $data['curriculum_id'],
            $data['technical_status'],
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
            'UPDATE compliance_rules
             SET compliance_reference_id = ?, rule_name = ?, rule_type = ?,
                 description = ?, numeric_value = ?, subject_id = ?,
                 academic_program_id = ?, curriculum_id = ?, technical_status = ?
             WHERE id = ?'
        );
        $stmt->bind_param(
            'issssiiisi',
            $data['compliance_reference_id'],
            $data['rule_name'],
            $data['rule_type'],
            $data['description'],
            $data['numeric_value'],
            $data['subject_id'],
            $data['academic_program_id'],
            $data['curriculum_id'],
            $data['technical_status'],
            $id
        );
        $stmt->execute();
        $stmt->close();
    }

    private function detailSelect(): string
    {
        return 'SELECT rules.*, references_table.reference_type,
                       references_table.reference_code, references_table.title AS reference_title,
                       references_table.issuing_body, references_table.source_url,
                       references_table.verification_status,
                       subjects.subject_code, subjects.subject_title, subjects.units AS subject_units,
                       programs.program_code, programs.program_name,
                       curricula.curriculum_code, curricula.curriculum_name,
                       creator.username AS creator_username,
                       EXISTS(SELECT 1 FROM compliance_validation_results AS history
                              WHERE history.compliance_rule_id = rules.id) AS has_history'
            . $this->detailJoins();
    }

    private function detailJoins(): string
    {
        return ' FROM compliance_rules AS rules
                 INNER JOIN compliance_references AS references_table
                    ON references_table.id = rules.compliance_reference_id
                 LEFT JOIN subjects ON subjects.id = rules.subject_id
                 LEFT JOIN academic_programs AS programs ON programs.id = rules.academic_program_id
                 LEFT JOIN curricula ON curricula.id = rules.curriculum_id
                 LEFT JOIN users AS creator ON creator.id = rules.created_by';
    }
}
