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

    /** Selected Program / Curriculum / Version (all three must belong together), with the lifecycle fields the edit policy needs. */
    public function versionContext(int $programId, int $curriculumId, int $versionId): ?array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT versions.id AS version_id, versions.version_label, versions.effective_academic_year, versions.status, versions.review_status,
                    versions.version_role, versions.baseline_version_id, curricula.id AS curriculum_id, curricula.curriculum_code, curricula.curriculum_name,
                    programs.id AS program_id, programs.program_code, programs.program_name
             FROM curriculum_versions AS versions
             INNER JOIN curricula ON curricula.id = versions.curriculum_id
             INNER JOIN academic_programs AS programs ON programs.id = curricula.academic_program_id
             WHERE programs.id = ? AND curricula.id = ? AND versions.id = ? LIMIT 1',
            'iii',
            [$programId, $curriculumId, $versionId]
        );
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function versionById(int $versionId): ?array
    {
        $stmt = RepositoryQuery::execute($this->db, 'SELECT id, status, review_status, version_role, baseline_version_id FROM curriculum_versions WHERE id = ? LIMIT 1', 'i', [$versionId]);
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    /** Distinct year levels and terms of a version (for the filters). */
    public function versionTerms(int $versionId): array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT DISTINCT year_level, semester FROM curriculum_version_subjects WHERE curriculum_version_id = ? LIMIT 200',
            'i',
            [$versionId]
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /** True when at least one version the user may read can be edited (same rule as CurriculumVersionEditPolicy::isEditable). */
    public function editableVersionExists(string $scopeSql): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM curriculum_versions AS versions
             WHERE versions.status <> 'archived'
               AND ((versions.version_role = 'unclassified' AND versions.review_status = 'not_started' AND versions.baseline_version_id IS NULL)
                 OR (versions.version_role = 'proposed' AND versions.baseline_version_id IS NOT NULL AND versions.review_status IN ('not_started', 'draft', 'returned')))"
            . ($scopeSql !== '' ? ' AND ' . $scopeSql : '') . ' LIMIT 1'
        );
        $stmt->execute();
        $found = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $found;
    }

    /** Sortable columns of the version overview (whitelist). */
    private const OVERVIEW_SORTS = [
        'code' => 'subjects.subject_code',
        'title' => 'subjects.subject_title',
        'term' => null,
        'components' => 'component_count',
        'total' => 'total_weight',
        'status' => 'status_rank',
    ];

    /**
     * Placements of one version with their weighting state, filtered, sorted and paged in SQL. Step 1 picks the ids of the page; step 2 loads
     * those rows. The component count and total are correlated subqueries, evaluated only for the rows that need them.
     */
    public function overview(int $versionId, array $query): array
    {
        $count = '(SELECT COUNT(*) FROM grade_weighting_components AS c INNER JOIN grade_weighting_schemes AS g ON g.id = c.scheme_id WHERE g.curriculum_version_subject_id = placements.id)';
        $total = '(SELECT SUM(c.weight_percentage) FROM grade_weighting_components AS c INNER JOIN grade_weighting_schemes AS g ON g.id = c.scheme_id WHERE g.curriculum_version_subject_id = placements.id)';
        $conditions = ['placements.curriculum_version_id = ?'];
        $types = 'i';
        $params = [$versionId];
        if ($query['search'] !== null) {
            $pattern = RepositoryQuery::likePattern($query['search']);
            $conditions[] = '(' . RepositoryQuery::searchColumn('subjects.subject_code') . " LIKE ? ESCAPE '=' OR " . RepositoryQuery::searchColumn('subjects.subject_title') . " LIKE ? ESCAPE '=')";
            $types .= 'ss';
            array_push($params, $pattern, $pattern);
        }
        foreach (['year_level' => 'placements.year_level', 'semester' => 'placements.semester'] as $key => $column) {
            if ($query[$key] !== null) {
                $conditions[] = "$column = ?";
                $types .= 's';
                $params[] = $query[$key];
            }
        }
        if ($query['status'] === 'configured') $conditions[] = "$total = 100";
        elseif ($query['status'] === 'incomplete') $conditions[] = "($total IS NOT NULL AND $total <> 100)";
        elseif ($query['status'] === 'not_configured') $conditions[] = "$total IS NULL";
        $where = ' WHERE ' . implode(' AND ', $conditions);
        $from = ' FROM curriculum_version_subjects AS placements INNER JOIN subjects ON subjects.id = placements.subject_id';

        $countStmt = RepositoryQuery::execute($this->db, 'SELECT COUNT(*) AS total_rows' . $from . $where, $types, $params);
        $totalRows = (int)$countStmt->get_result()->fetch_assoc()['total_rows'];
        $countStmt->close();
        $pagination = RepositoryQuery::pagination($totalRows, $query['page'], $query['per_page']);

        $direction = $query['direction'] === 'asc' ? 'ASC' : 'DESC';
        $sort = $query['sort'];
        if ($sort === 'term') {
            $order = "placements.year_level $direction, placements.semester $direction, placements.sequence_order IS NULL, placements.sequence_order $direction, subjects.subject_code $direction";
        } elseif ($sort === 'components') {
            $order = "COALESCE($count, 0) $direction, subjects.subject_code ASC";
        } elseif ($sort === 'total') {
            $order = "COALESCE($total, 0) $direction, subjects.subject_code ASC";
        } elseif ($sort === 'status') {
            // configured (100) > incomplete > not configured
            $order = "(CASE WHEN $total IS NULL THEN 0 WHEN $total = 100 THEN 2 ELSE 1 END) $direction, subjects.subject_code ASC";
        } else {
            $order = self::OVERVIEW_SORTS[$sort] . " $direction";
        }
        $idStmt = RepositoryQuery::execute(
            $this->db,
            'SELECT placements.id' . $from . $where . " ORDER BY $order, placements.id ASC LIMIT ? OFFSET ?",
            $types . 'ii',
            array_merge($params, [$pagination['per_page'], $pagination['offset']])
        );
        $ids = array_map('intval', array_column($idStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
        $idStmt->close();
        $records = [];
        if ($ids) {
            $stmt = RepositoryQuery::execute(
                $this->db,
                "SELECT placements.id, placements.subject_id, placements.year_level, placements.semester, subjects.subject_code, subjects.subject_title, subjects.units,
                        COALESCE($count, 0) AS component_count, COALESCE($total, 0) AS total_weight
                 FROM curriculum_version_subjects AS placements INNER JOIN subjects ON subjects.id = placements.subject_id
                 WHERE placements.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')',
                str_repeat('i', count($ids)),
                $ids
            );
            $byId = [];
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $byId[(int)$row['id']] = $row;
            $stmt->close();
            foreach ($ids as $id) if (isset($byId[$id])) $records[] = $byId[$id];
        }
        unset($pagination['offset']);
        $pagination['sort'] = $sort;
        $pagination['direction'] = strtolower($direction);
        return ['records' => $records, 'pagination' => $pagination];
    }

    /** Whole-version coverage: placements, configured (100%), incomplete, not configured. */
    public function coverage(int $versionId): array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS placements, COALESCE(SUM(w = 100), 0) AS configured, COALESCE(SUM(w IS NOT NULL AND w <> 100), 0) AS incomplete
             FROM (SELECT (SELECT SUM(c.weight_percentage) FROM grade_weighting_components AS c INNER JOIN grade_weighting_schemes AS g ON g.id = c.scheme_id
                           WHERE g.curriculum_version_subject_id = placements.id) AS w
                   FROM curriculum_version_subjects AS placements WHERE placements.curriculum_version_id = ?) AS x',
            'i',
            [$versionId]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return ['placements' => (int)$row['placements'], 'configured' => (int)$row['configured'], 'incomplete' => (int)$row['incomplete'],
            'not_configured' => (int)$row['placements'] - (int)$row['configured'] - (int)$row['incomplete']];
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
