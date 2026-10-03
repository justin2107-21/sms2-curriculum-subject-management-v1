<?php

require_once __DIR__ . '/RepositoryQuery.php';

class ComplianceValidationRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function versionContext(int $versionId): ?array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT versions.id AS curriculum_version_id, versions.version_label,
                    versions.effective_academic_year, versions.status AS version_status,
                    curricula.id AS curriculum_id, curricula.curriculum_code,
                    curricula.curriculum_name, curricula.status AS curriculum_status,
                    programs.id AS academic_program_id, programs.program_code,
                    programs.program_name
             FROM curriculum_versions AS versions
             INNER JOIN curricula ON curricula.id = versions.curriculum_id
             INNER JOIN academic_programs AS programs
                ON programs.id = curricula.academic_program_id
             WHERE versions.id = ? LIMIT 1',
            'i',
            [$versionId]
        );
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function curriculumTotals(int $versionId): array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS mapped_subject_count,
                    CAST(COALESCE(SUM(subjects.units), 0) AS DECIMAL(12,2)) AS total_units
             FROM curriculum_version_subjects AS placements
             INNER JOIN subjects ON subjects.id = placements.subject_id
             WHERE placements.curriculum_version_id = ?',
            'i',
            [$versionId]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return [
            'mapped_subject_count' => (int)$row['mapped_subject_count'],
            'total_units' => (string)$row['total_units'],
        ];
    }

    public function mappedSubject(int $versionId, int $subjectId): ?array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT subjects.id, subjects.subject_code, subjects.subject_title,
                    subjects.units, placements.id AS placement_id
             FROM curriculum_version_subjects AS placements
             INNER JOIN subjects ON subjects.id = placements.subject_id
             WHERE placements.curriculum_version_id = ? AND placements.subject_id = ?
             LIMIT 1',
            'ii',
            [$versionId, $subjectId]
        );
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function createRun(int $versionId, int $runBy): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO compliance_validation_runs (curriculum_version_id, run_by)
             VALUES (?, ?)'
        );
        $stmt->bind_param('ii', $versionId, $runBy);
        $stmt->execute();
        $id = (int)$this->db->insert_id;
        $stmt->close();
        return $id;
    }

    public function updateRunCounts(int $id, array $summary): void
    {
        $stmt = $this->db->prepare(
            'UPDATE compliance_validation_runs
             SET rules_evaluated = ?, passed_count = ?, failed_count = ?,
                 not_evaluated_count = ? WHERE id = ?'
        );
        $stmt->bind_param(
            'iiiii',
            $summary['rules_evaluated'],
            $summary['passed'],
            $summary['failed'],
            $summary['not_evaluated'],
            $id
        );
        $stmt->execute();
        $stmt->close();
    }

    public function createResult(int $runId, array $result): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO compliance_validation_results
                (validation_run_id, compliance_rule_id, rule_name_snapshot,
                 rule_type_snapshot, reference_identifier_snapshot,
                 reference_url_snapshot, requirement_snapshot, actual_value_snapshot,
                 result_status, explanation)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'iissssssss',
            $runId,
            $result['compliance_rule_id'],
            $result['rule_name'],
            $result['rule_type'],
            $result['reference_identifier'],
            $result['reference_url'],
            $result['requirement'],
            $result['actual_value'],
            $result['result'],
            $result['explanation']
        );
        $stmt->execute();
        $id = (int)$this->db->insert_id;
        $stmt->close();
        return $id;
    }

    public function findRun(int $id): ?array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            $this->runSelect() . ' WHERE runs.id = ? LIMIT 1',
            'i',
            [$id]
        );
        $run = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$run) {
            return null;
        }
        $resultStmt = RepositoryQuery::execute(
            $this->db,
            'SELECT id, compliance_rule_id, rule_name_snapshot, rule_type_snapshot,
                    reference_identifier_snapshot, reference_url_snapshot, requirement_snapshot,
                    actual_value_snapshot, result_status, explanation, created_at
             FROM compliance_validation_results
             WHERE validation_run_id = ? ORDER BY id ASC',
            'i',
            [$id]
        );
        $run['results'] = $resultStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $resultStmt->close();
        return $run;
    }

    public function paginateRuns(array $query): array
    {
        $conditions = [];
        $types = '';
        $params = [];
        if ($query['search'] !== null) {
            $conditions[] = "(" . RepositoryQuery::searchColumn('programs.program_code') . " LIKE ? ESCAPE '='
                              OR " . RepositoryQuery::searchColumn('programs.program_name') . " LIKE ? ESCAPE '='
                              OR " . RepositoryQuery::searchColumn('curricula.curriculum_code') . " LIKE ? ESCAPE '='
                              OR " . RepositoryQuery::searchColumn('curricula.curriculum_name') . " LIKE ? ESCAPE '='
                              OR " . RepositoryQuery::searchColumn('versions.version_label') . " LIKE ? ESCAPE '=')";
            $pattern = RepositoryQuery::likePattern($query['search']);
            $types .= 'sssss';
            array_push($params, $pattern, $pattern, $pattern, $pattern, $pattern);
        }
        foreach ([
            'academic_program_id' => 'programs.id',
            'curriculum_id' => 'curricula.id',
            'curriculum_version_id' => 'versions.id',
        ] as $field => $column) {
            if ($query[$field] !== null) {
                $conditions[] = $column . ' = ?';
                $types .= 'i';
                $params[] = $query[$field];
            }
        }
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $joins = $this->runJoins();
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
        $searchOrder = RepositoryQuery::searchOrder(['programs.program_code', 'programs.program_name', 'curricula.curriculum_code', 'curricula.curriculum_name', 'versions.version_label'], $query['search'] ?? null, $rankParams);
        $listParams = array_merge($params, $rankParams);
        $listParams[] = $pagination['per_page'];
        $listParams[] = $pagination['offset'];
        $stmt = RepositoryQuery::execute(
            $this->db,
            $this->runSelect() . $where .
            ' ORDER BY ' . $searchOrder . 'runs.created_at DESC, runs.id DESC LIMIT ? OFFSET ?',
            $types . str_repeat('s', count($rankParams)) . 'ii',
            $listParams
        );
        $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        unset($pagination['offset']);
        return ['records' => $records, 'pagination' => $pagination];
    }

    private function runSelect(): string
    {
        return 'SELECT runs.*, versions.version_label, versions.effective_academic_year,
                       curricula.id AS curriculum_id, curricula.curriculum_code,
                       curricula.curriculum_name, programs.id AS academic_program_id,
                       programs.program_code, programs.program_name,
                       runner.username AS run_by_username,
                       runner.first_name AS run_by_first_name,
                       runner.last_name AS run_by_last_name'
            . $this->runJoins();
    }

    private function runJoins(): string
    {
        return ' FROM compliance_validation_runs AS runs
                 INNER JOIN curriculum_versions AS versions
                    ON versions.id = runs.curriculum_version_id
                 INNER JOIN curricula ON curricula.id = versions.curriculum_id
                 INNER JOIN academic_programs AS programs
                    ON programs.id = curricula.academic_program_id
                 LEFT JOIN users AS runner ON runner.id = runs.run_by';
    }
}
