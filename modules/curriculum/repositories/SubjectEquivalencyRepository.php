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

    /** Sortable columns (whitelist). Value: [SQL expression, needs the subject joins]. */
    private const SORTS = [
        'subject_a' => ['subject_a.subject_code', true],
        'subject_b' => ['subject_b.subject_code', true],
        'decision' => ['equivalencies.decision_status', false],
        'reviewed_at' => ['equivalencies.reviewed_at', false],
    ];

    /**
     * Filters, sort, total and one page, all in SQL. Step 1 selects only the ids of the page (filtered, sorted, LIMIT);
     * step 2 loads those rows with their subjects and reviewer. $scopeSql is CurriculumReadScope::sql('versions').
     */
    public function paginate(array $query, string $scopeSql = ''): array
    {
        $conditions = [];
        $types = '';
        $params = [];
        $status = $query['status'] ?? 'active';
        if ($status === 'active') $conditions[] = 'equivalencies.archived_at IS NULL';
        elseif ($status === 'archived') $conditions[] = 'equivalencies.archived_at IS NOT NULL';
        $conditions[] = "equivalencies.decision_status <> 'pending'";
        if ($query['search'] !== null) {
            // Matching subjects are found first (small table); the pair table is then probed through its subject indexes.
            $pattern = RepositoryQuery::likePattern($query['search']);
            $match = 'SELECT id FROM subjects WHERE ' . RepositoryQuery::searchColumn('subject_code') . " LIKE ? ESCAPE '=' OR "
                . RepositoryQuery::searchColumn('subject_title') . " LIKE ? ESCAPE '='";
            $conditions[] = "(equivalencies.subject_id IN ($match) OR equivalencies.equivalent_subject_id IN ($match))";
            $types .= 'ssss';
            array_push($params, $pattern, $pattern, $pattern, $pattern);
        }
        if ($query['decision_status'] !== null) {
            $conditions[] = 'equivalencies.decision_status = ?';
            $types .= 's';
            $params[] = $query['decision_status'];
        }
        if ($query['reviewed_by'] !== null) {
            $conditions[] = 'equivalencies.reviewed_by = ?';
            $types .= 'i';
            $params[] = $query['reviewed_by'];
        }
        if ($query['reviewed_from'] !== null) {
            $conditions[] = 'equivalencies.reviewed_at >= ?';
            $types .= 's';
            $params[] = $query['reviewed_from'] . ' 00:00:00';
        }
        if ($query['reviewed_to'] !== null) {
            $conditions[] = 'equivalencies.reviewed_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $types .= 's';
            $params[] = $query['reviewed_to'];
        }
        if ($query['reason'] === 'has') $conditions[] = "(equivalencies.remarks IS NOT NULL AND TRIM(equivalencies.remarks) <> '')";
        elseif ($query['reason'] === 'missing') $conditions[] = "(equivalencies.remarks IS NULL OR TRIM(equivalencies.remarks) = '')";
        $where = ' WHERE ' . implode(' AND ', $conditions);

        [$sortExpression, $needsSubjects] = self::SORTS[$query['sort']];
        $direction = $query['direction'] === 'asc' ? 'ASC' : 'DESC';
        $source = 'subject_equivalencies AS equivalencies';
        $sourceTypes = $types;
        $sourceParams = $params;
        $alias = 'equivalencies';
        $filteredWhere = $where;
        if ($query['academic_program_id'] !== null) {
            // Either subject placed in the program (read scope applies to the versions) or actively assigned to it. The program's
            // subject ids are one derived table joined once per side and the two result sets are merged; a per-row IN subquery
            // over the pair table measured about 10 times slower at 50,000 records.
            $programSubjects = 'SELECT placements.subject_id FROM curriculum_version_subjects placements
                    INNER JOIN curriculum_versions versions ON versions.id = placements.curriculum_version_id
                    INNER JOIN curricula ON curricula.id = versions.curriculum_id
                    WHERE curricula.academic_program_id = ?' . ($scopeSql !== '' ? ' AND ' . $scopeSql : '') . '
                    UNION SELECT assignments.subject_id FROM program_subject_assignments assignments
                    WHERE assignments.academic_program_id = ? AND assignments.status = \'active\'';
            $side = static fn(string $column): string => 'SELECT equivalencies.id, equivalencies.subject_id, equivalencies.equivalent_subject_id, equivalencies.decision_status, equivalencies.reviewed_at
                FROM subject_equivalencies AS equivalencies INNER JOIN (' . $programSubjects . ') AS program_subjects ON program_subjects.subject_id = equivalencies.' . $column . $where;
            $source = '(' . $side('subject_id') . ' UNION ' . $side('equivalent_subject_id') . ') AS picked';
            $programId = $query['academic_program_id'];
            $sourceTypes = 'ii' . $types . 'ii' . $types;
            $sourceParams = array_merge([$programId, $programId], $params, [$programId, $programId], $params);
            $alias = 'picked';
            $filteredWhere = '';
        }
        $sortExpression = str_replace('equivalencies.', $alias . '.', $sortExpression);
        $subjectJoins = $needsSubjects
            ? " INNER JOIN subjects AS subject_a ON subject_a.id = $alias.subject_id
                INNER JOIN subjects AS subject_b ON subject_b.id = $alias.equivalent_subject_id" : '';

        $countStmt = RepositoryQuery::execute($this->db, 'SELECT COUNT(*) AS total FROM ' . $source . $filteredWhere, $sourceTypes, $sourceParams);
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();
        $pagination = RepositoryQuery::pagination($total, $query['page'], $query['per_page']);

        $idStmt = RepositoryQuery::execute(
            $this->db,
            "SELECT $alias.id FROM $source" . $subjectJoins . $filteredWhere .
            " ORDER BY $sortExpression $direction, $alias.id $direction LIMIT ? OFFSET ?",
            $sourceTypes . 'ii',
            array_merge($sourceParams, [$pagination['per_page'], $pagination['offset']])
        );
        $ids = array_map('intval', array_column($idStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
        $idStmt->close();
        $records = [];
        if ($ids) {
            $stmt = RepositoryQuery::execute(
                $this->db,
                $this->detailSelect() . ' WHERE equivalencies.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                str_repeat('i', count($ids)),
                $ids
            );
            $byId = [];
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $byId[(int)$row['id']] = $row;
            $stmt->close();
            foreach ($ids as $id) if (isset($byId[$id])) $records[] = $byId[$id];
        }
        unset($pagination['offset']);
        $pagination['sort'] = $query['sort'];
        $pagination['direction'] = strtolower($direction);
        return ['records' => $records, 'pagination' => $pagination];
    }
    /** Users who reviewed at least one record (for the "Reviewed by" filter), limited. */
    public function reviewers(int $limit = 100): array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT users.id, users.username, users.first_name, users.last_name FROM users
             WHERE EXISTS (SELECT 1 FROM subject_equivalencies AS e WHERE e.reviewed_by = users.id)
             ORDER BY users.last_name, users.first_name, users.username LIMIT ?',
            'i',
            [$limit]
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /** Programs for the Program filter (small table), limited. */
    public function programOptions(int $limit = 200): array
    {
        $stmt = RepositoryQuery::execute($this->db, 'SELECT id, program_code, program_name FROM academic_programs ORDER BY program_code LIMIT ?', 'i', [$limit]);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
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
                 reviewed_at = CURRENT_TIMESTAMP
             WHERE id = ? AND archived_at IS NULL'
        );
        $stmt->bind_param(
            'ssii',
            $data['decision_status'],
            $data['remarks'],
            $reviewedBy,
            $id
        );
        $stmt->execute();
        $stmt->close();
    }

    public function archive(int $id, int $actorId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE subject_equivalencies SET archived_at = UTC_TIMESTAMP(), archived_by = ?
             WHERE id = ? AND archived_at IS NULL'
        );
        $stmt->bind_param('ii', $actorId, $id);
        $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();
        if (!$changed) throw new DomainConflictException('This equivalency record is already archived.');
    }

    public function restore(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE subject_equivalencies SET archived_at = NULL, archived_by = NULL
             WHERE id = ? AND archived_at IS NOT NULL'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();
        if (!$changed) throw new DomainConflictException('This equivalency record is not archived.');
    }

    /**
     * Placement usage of one subject. $scopeSql is CurriculumReadScope::sql('versions'): constant SQL, never user input.
     * When it is not empty, placements in versions outside the reader's scope are left out and counted in hidden_placements.
     */
    public function usageContext(int $subjectId, string $scopeSql = ''): array
    {
        $joins = ' FROM curriculum_version_subjects AS placements
                   INNER JOIN curriculum_versions AS versions
                      ON versions.id = placements.curriculum_version_id
                   INNER JOIN curricula ON curricula.id = versions.curriculum_id
                   INNER JOIN academic_programs AS programs
                      ON programs.id = curricula.academic_program_id
                   WHERE placements.subject_id = ?' . ($scopeSql !== '' ? ' AND ' . $scopeSql : '');
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
                    versions.version_label, versions.status AS version_status,
                    placements.year_level, placements.semester'
            . $joins .
            ' ORDER BY programs.program_code ASC, curricula.curriculum_code ASC,
                       versions.version_label ASC LIMIT 10',
            'i',
            [$subjectId]
        );
        $records = $contextStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $contextStmt->close();
        $assignmentStmt = RepositoryQuery::execute(
            $this->db,
            'SELECT DISTINCT programs.program_code, programs.program_name
             FROM program_subject_assignments AS assignments
             INNER JOIN academic_programs AS programs
                ON programs.id = assignments.academic_program_id
             WHERE assignments.subject_id = ? AND assignments.status = \'active\'
             ORDER BY programs.program_code ASC LIMIT 20',
            'i',
            [$subjectId]
        );
        $programAssignments = $assignmentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $assignmentStmt->close();
        $programCountStmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(DISTINCT program_code) AS total FROM (
                SELECT programs.program_code
                FROM curriculum_version_subjects placements
                INNER JOIN curriculum_versions versions ON versions.id = placements.curriculum_version_id
                INNER JOIN curricula ON curricula.id = versions.curriculum_id
                INNER JOIN academic_programs programs ON programs.id = curricula.academic_program_id
                WHERE placements.subject_id = ?' . ($scopeSql !== '' ? ' AND ' . $scopeSql : '') . '
                UNION
                SELECT programs.program_code
                FROM program_subject_assignments assignments
                INNER JOIN academic_programs programs ON programs.id = assignments.academic_program_id
                WHERE assignments.subject_id = ? AND assignments.status = \'active\'
             ) AS program_contexts',
            'ii',
            [$subjectId, $subjectId]
        );
        $programCount = (int)$programCountStmt->get_result()->fetch_assoc()['total'];
        $programCountStmt->close();
        foreach (['placements', 'programs', 'curricula', 'curriculum_versions'] as $key) {
            $summary[$key] = (int)$summary[$key];
        }
        $summary['programs'] = $programCount;
        $summary['hidden_placements'] = 0;
        if ($scopeSql !== '') {
            $allStmt = RepositoryQuery::execute($this->db, 'SELECT COUNT(*) AS total FROM curriculum_version_subjects WHERE subject_id = ?', 'i', [$subjectId]);
            $summary['hidden_placements'] = max(0, (int)$allStmt->get_result()->fetch_assoc()['total'] - $summary['placements']);
            $allStmt->close();
        }
        return ['summary' => $summary, 'program_assignments' => $programAssignments, 'records' => $records];
    }

    /**
     * Prerequisites / corequisites of a subject as placed in curriculum versions (union across versions, same scope as usage):
     * the first $limit rows (one per required subject and type, with the version labels) and the total count.
     */
    public function prerequisites(int $subjectId, string $scopeSql = '', int $limit = 3): array
    {
        $from = ' FROM curriculum_subject_requirements AS requirements
                  INNER JOIN subjects AS required ON required.id = requirements.required_subject_id
                  INNER JOIN curriculum_versions AS versions ON versions.id = requirements.curriculum_version_id
                  INNER JOIN curricula ON curricula.id = versions.curriculum_id
                  WHERE requirements.subject_id = ?' . ($scopeSql !== '' ? ' AND ' . $scopeSql : '');
        $countStmt = RepositoryQuery::execute($this->db, 'SELECT COUNT(DISTINCT requirements.requirement_type, requirements.required_subject_id) AS total' . $from, 'i', [$subjectId]);
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT requirements.requirement_type, required.subject_code, required.subject_title,
                    GROUP_CONCAT(DISTINCT CONCAT(curricula.curriculum_code, \' \', versions.version_label)
                                 ORDER BY curricula.curriculum_code, versions.version_label SEPARATOR \', \') AS versions' . $from . '
             GROUP BY requirements.requirement_type, required.id, required.subject_code, required.subject_title
             ORDER BY requirements.requirement_type, required.subject_code LIMIT ?',
            'ii',
            [$subjectId, $limit]
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return ['rows' => $rows, 'total' => $total];
    }

    /** Where one subject is a prerequisite or corequisite of the other (either direction), in versions the reader may see. */
    public function requirementBetween(int $firstId, int $secondId, string $scopeSql = ''): array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT requirements.subject_id, requirements.required_subject_id, requirements.requirement_type,
                    GROUP_CONCAT(DISTINCT CONCAT(curricula.curriculum_code, \' \', versions.version_label) ORDER BY curricula.curriculum_code, versions.version_label SEPARATOR \', \') AS versions
             FROM curriculum_subject_requirements AS requirements
             INNER JOIN curriculum_versions AS versions ON versions.id = requirements.curriculum_version_id
             INNER JOIN curricula ON curricula.id = versions.curriculum_id
             WHERE ((requirements.subject_id = ? AND requirements.required_subject_id = ?) OR (requirements.subject_id = ? AND requirements.required_subject_id = ?))'
            . ($scopeSql !== '' ? ' AND ' . $scopeSql : '') . '
             GROUP BY requirements.subject_id, requirements.required_subject_id, requirements.requirement_type
             ORDER BY requirements.subject_id LIMIT 4',
            'iiii',
            [$firstId, $secondId, $secondId, $firstId]
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
    private function detailSelect(): string
    {
        return 'SELECT equivalencies.id, equivalencies.subject_id,
                       equivalencies.equivalent_subject_id,
                       equivalencies.decision_status, equivalencies.remarks,
                       equivalencies.reviewed_by, equivalencies.reviewed_at,
                       equivalencies.created_by, equivalencies.created_at,
                       equivalencies.updated_at, equivalencies.archived_at, equivalencies.archived_by,
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
