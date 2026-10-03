<?php

require_once __DIR__ . '/RepositoryQuery.php';

class SubjectEquivalencyRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            $this->detailSelect() . ' WHERE equivalencies.id = ? LIMIT 1',
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
            'SELECT * FROM subject_equivalencies WHERE id = ? LIMIT 1 FOR UPDATE',
            'i',
            [$id]
        );
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function findByPair(int $subjectId, int $equivalentSubjectId): ?array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT id FROM subject_equivalencies
             WHERE subject_id = ? AND equivalent_subject_id = ? LIMIT 1',
            'ii',
            [$subjectId, $equivalentSubjectId]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? $this->findById((int)$row['id']) : null;
    }

    public function paginate(array $query): array
    {
        $conditions = [];
        $types = '';
        $params = [];
        if ($query['search'] !== null) {
            $conditions[] = "(" . RepositoryQuery::searchColumn('subject_a.subject_code') . " LIKE ? ESCAPE '='
                              OR " . RepositoryQuery::searchColumn('subject_a.subject_title') . " LIKE ? ESCAPE '='
                              OR " . RepositoryQuery::searchColumn('subject_b.subject_code') . " LIKE ? ESCAPE '='
                              OR " . RepositoryQuery::searchColumn('subject_b.subject_title') . " LIKE ? ESCAPE '=')";
            $pattern = RepositoryQuery::likePattern($query['search']);
            $types .= 'ssss';
            array_push($params, $pattern, $pattern, $pattern, $pattern);
        }
        if ($query['decision_status'] !== null) {
            $conditions[] = 'equivalencies.decision_status = ?';
            $types .= 's';
            $params[] = $query['decision_status'];
        }
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $joins = $this->detailJoins();
        $countStmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS total' . $joins . $where,
            $types,
            $params
        );
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();
        $pagination = RepositoryQuery::pagination($total, $query['page'], $query['per_page']);

        $rankParams = [];
        $searchOrder = RepositoryQuery::searchOrder(['subject_a.subject_code', 'subject_a.subject_title', 'subject_b.subject_code', 'subject_b.subject_title'], $query['search'] ?? null, $rankParams);
        $listParams = array_merge($params, $rankParams);
        $listParams[] = $pagination['per_page'];
        $listParams[] = $pagination['offset'];
        $stmt = RepositoryQuery::execute(
            $this->db,
            $this->detailSelect() . $where .
            ' ORDER BY ' . $searchOrder . 'equivalencies.updated_at DESC, equivalencies.id DESC LIMIT ? OFFSET ?',
            $types . str_repeat('s', count($rankParams)) . 'ii',
            $listParams
        );
        $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        unset($pagination['offset']);
        return ['records' => $records, 'pagination' => $pagination];
    }

    public function create(
        array $data,
        int $createdBy,
        ?int $reviewedBy,
        bool $isFinalDecision
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO subject_equivalencies
                (subject_id, equivalent_subject_id, decision_status, remarks,
                 reviewed_by, reviewed_at, created_by)
             VALUES (?, ?, ?, ?, ?, IF(? = 1, CURRENT_TIMESTAMP, NULL), ?)'
        );
        $isFinal = $isFinalDecision ? 1 : 0;
        $stmt->bind_param(
            'iissiii',
            $data['subject_id'],
            $data['equivalent_subject_id'],
            $data['decision_status'],
            $data['remarks'],
            $reviewedBy,
            $isFinal,
            $createdBy
        );
        $stmt->execute();
        $id = (int)$this->db->insert_id;
        $stmt->close();
        return $id;
    }

    public function update(
        int $id,
        array $data,
        ?int $reviewedBy,
        bool $isFinalDecision
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE subject_equivalencies
             SET decision_status = ?, remarks = ?, reviewed_by = ?,
                 reviewed_at = IF(? = 1, CURRENT_TIMESTAMP, NULL)
             WHERE id = ?'
        );
        $isFinal = $isFinalDecision ? 1 : 0;
        $stmt->bind_param(
            'ssiii',
            $data['decision_status'],
            $data['remarks'],
            $reviewedBy,
            $isFinal,
            $id
        );
        $stmt->execute();
        $stmt->close();
    }

    public function usageContext(int $subjectId): array
    {
        $joins = ' FROM curriculum_version_subjects AS placements
                   INNER JOIN curriculum_versions AS versions
                      ON versions.id = placements.curriculum_version_id
                   INNER JOIN curricula ON curricula.id = versions.curriculum_id
                   INNER JOIN academic_programs AS programs
                      ON programs.id = curricula.academic_program_id
                   WHERE placements.subject_id = ?';
        $summaryStmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS placements,
                    COUNT(DISTINCT programs.id) AS programs,
                    COUNT(DISTINCT curricula.id) AS curricula,
                    COUNT(DISTINCT versions.id) AS curriculum_versions' . $joins,
            'i',
            [$subjectId]
        );
        $summary = $summaryStmt->get_result()->fetch_assoc();
        $summaryStmt->close();

        $contextStmt = RepositoryQuery::execute(
            $this->db,
            'SELECT programs.program_code, programs.program_name,
                    curricula.curriculum_code, curricula.curriculum_name,
                    versions.version_label, versions.status AS version_status'
            . $joins .
            ' ORDER BY programs.program_code ASC, curricula.curriculum_code ASC,
                       versions.version_label ASC LIMIT 10',
            'i',
            [$subjectId]
        );
        $records = $contextStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $contextStmt->close();
        foreach (['placements', 'programs', 'curricula', 'curriculum_versions'] as $key) {
            $summary[$key] = (int)$summary[$key];
        }
        return ['summary' => $summary, 'records' => $records];
    }

    private function detailSelect(): string
    {
        return 'SELECT equivalencies.id, equivalencies.subject_id,
                       equivalencies.equivalent_subject_id,
                       equivalencies.decision_status, equivalencies.remarks,
                       equivalencies.reviewed_by, equivalencies.reviewed_at,
                       equivalencies.created_by, equivalencies.created_at,
                       equivalencies.updated_at,
                       subject_a.subject_code AS subject_a_code,
                       subject_a.subject_title AS subject_a_title,
                       subject_b.subject_code AS subject_b_code,
                       subject_b.subject_title AS subject_b_title,
                       reviewer.username AS reviewer_username,
                       reviewer.first_name AS reviewer_first_name,
                       reviewer.last_name AS reviewer_last_name,
                       creator.username AS creator_username'
            . $this->detailJoins();
    }

    private function detailJoins(): string
    {
        return ' FROM subject_equivalencies AS equivalencies
                 INNER JOIN subjects AS subject_a ON subject_a.id = equivalencies.subject_id
                 INNER JOIN subjects AS subject_b
                    ON subject_b.id = equivalencies.equivalent_subject_id
                 LEFT JOIN users AS reviewer ON reviewer.id = equivalencies.reviewed_by
                 LEFT JOIN users AS creator ON creator.id = equivalencies.created_by';
    }
}
