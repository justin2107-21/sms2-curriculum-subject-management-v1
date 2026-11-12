<?php

require_once __DIR__ . '/RepositoryQuery.php';
require_once __DIR__ . '/../services/CurriculumVersionEditPolicy.php';

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
        $conditions = ['archived_at IS NULL'];
        $types = '';
        $params = [];

        if ($query['search'] !== null) {
            $conditions[] = "(" . RepositoryQuery::searchColumn('subject_code') . " LIKE ? ESCAPE '=' OR " . RepositoryQuery::searchColumn('subject_title') . " LIKE ? ESCAPE '=')";
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

        // Placements a reader may see: restricted roles never count draft or unapproved versions.
        $scopeSql = CurriculumReadScope::sql('sv');
        $usageFrom = 'FROM curriculum_version_subjects sp JOIN curriculum_versions sv ON sv.id = sp.curriculum_version_id WHERE sp.subject_id = subjects.id' . ($scopeSql !== '' ? ' AND ' . $scopeSql : '');
        if (($query['placed'] ?? null) === 'placed') $conditions[] = "EXISTS (SELECT 1 $usageFrom)";
        if (($query['placed'] ?? null) === 'unplaced') $conditions[] = "NOT EXISTS (SELECT 1 $usageFrom)";

        $placementConditions = ['p.subject_id = subjects.id'];
        if (($query['_exclude_placement_version'] ?? null) !== null) {
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM curriculum_version_subjects placed WHERE placed.subject_id = subjects.id AND placed.curriculum_version_id = ?)';
            $types .= 'i';
            $params[] = $query['_exclude_placement_version'];
        }
        foreach ([
            'academic_program_id' => ['ap.id', 'i'],
            'curriculum_id' => ['c.id', 'i'],
            'curriculum_version_id' => ['p.curriculum_version_id', 'i'],
            'year_level' => ['p.year_level', 's'],
            'semester' => ['p.semester', 's'],
        ] as $field => [$column, $type]) {
            if (($query[$field] ?? null) !== null) {
                $placementConditions[] = $column . ' = ?';
                $types .= $type;
                $params[] = $query[$field];
            }
        }
        if (count($placementConditions) > 1) {
            // EXISTS keeps Subject Master unique even when a subject occurs in many versions.
            $conditions[] = 'EXISTS (
                SELECT 1 FROM curriculum_version_subjects p
                JOIN curriculum_versions v ON v.id = p.curriculum_version_id
                JOIN curricula c ON c.id = v.curriculum_id
                JOIN academic_programs ap ON ap.id = c.academic_program_id
                WHERE ' . implode(' AND ', $placementConditions) . ')';
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
        $rankParams = [];
        $searchOrder = RepositoryQuery::searchOrder(['subject_code', 'subject_title'], $query['search'] ?? null, $rankParams);
        $sortColumns = ['code' => 'subject_code', 'title' => 'subject_title', 'units' => 'units', 'status' => 'status', 'usage' => 'usage_count'];
        if (($query['sort'] ?? null) !== null) {
            // Mapped column names only; the direction was validated to asc or desc.
            $orderBy = $sortColumns[$query['sort']] . ' ' . (($query['direction'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC') . ', subject_code ASC, id ASC';
            $rankParams = [];
        } else {
            $orderBy = $searchOrder . 'subject_code ASC, id ASC';
        }
        $listParams = array_merge($params, $rankParams);
        $listParams[] = $pagination['per_page'];
        $listParams[] = $pagination['offset'];
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT id, subject_code, subject_title, description, units, lecture_units,
                    laboratory_units, status, created_at, updated_at,
                    ' . (($query['_include_usage'] ?? true) ? "(SELECT COUNT(*) $usageFrom)" : 'NULL') . ' AS usage_count
             FROM subjects' . $where . '
             ORDER BY ' . $orderBy . ' LIMIT ? OFFSET ?',
            $types . str_repeat('s', count($rankParams)) . 'ii',
            $listParams
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (($query['_include_placement_context'] ?? false) && $rows) {
            $subjectIds = array_map(static fn(array $row): int => (int)$row['id'], $rows);
            $contextConditions = ['p.subject_id IN (' . implode(',', array_fill(0, count($subjectIds), '?')) . ')'];
            $contextTypes = str_repeat('i', count($subjectIds));
            $contextParams = $subjectIds;
            foreach ([
                'academic_program_id' => ['ap.id', 'i'],
                'curriculum_id' => ['c.id', 'i'],
                'curriculum_version_id' => ['p.curriculum_version_id', 'i'],
                'year_level' => ['p.year_level', 's'],
                'semester' => ['p.semester', 's'],
            ] as $field => [$column, $type]) {
                if (($query[$field] ?? null) !== null) {
                    $contextConditions[] = $column . ' = ?';
                    $contextTypes .= $type;
                    $contextParams[] = $query[$field];
                }
            }
            $contextStmt = RepositoryQuery::execute(
                $this->db,
                'SELECT DISTINCT p.subject_id, ap.program_code, p.year_level, p.semester
                 FROM curriculum_version_subjects p
                 JOIN curriculum_versions v ON v.id = p.curriculum_version_id
                 JOIN curricula c ON c.id = v.curriculum_id
                 JOIN academic_programs ap ON ap.id = c.academic_program_id
                 WHERE ' . implode(' AND ', $contextConditions) . '
                 ORDER BY ap.program_code, p.year_level, p.semester',
                $contextTypes,
                $contextParams
            );
            $contextRows = $contextStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $contextStmt->close();
            $contextBySubject = [];
            foreach ($contextRows as $context) {
                $id = (int)$context['subject_id'];
                $contextBySubject[$id]['programs'][$context['program_code']] = $context['program_code'];
                $contextBySubject[$id]['placements'][$context['year_level'] . "\0" . $context['semester']] = [
                    'year_level' => $context['year_level'],
                    'semester' => $context['semester'],
                ];
                $term = trim(implode(' / ', array_filter([$context['year_level'], $context['semester']], static fn($value): bool => $value !== null && $value !== '')));
                if ($term !== '') $contextBySubject[$id]['terms'][$term] = $term;
            }
            foreach ($rows as &$row) {
                $context = $contextBySubject[(int)$row['id']] ?? [];
                $row['program_context'] = array_values($context['programs'] ?? []);
                $row['placement_context'] = array_values($context['terms'] ?? []);
                $row['placement_contexts'] = array_values($context['placements'] ?? []);
            }
            unset($row);
        }
        unset($pagination['offset']);

        return ['records' => $rows, 'pagination' => $pagination];
    }

    /** Where a subject is placed, with the version's lifecycle state (read scope applied). */
    public function placementsFor(int $subjectId): array
    {
        $scope = CurriculumReadScope::sql('v');
        return $this->rows('SELECT p.id AS placement_id, p.year_level, p.semester, p.is_required, v.id AS curriculum_version_id, v.version_label, v.status AS version_status,
                    v.review_status, v.version_role, v.baseline_version_id, c.id AS curriculum_id, c.curriculum_code, ap.id AS academic_program_id, ap.program_code
                FROM curriculum_version_subjects p JOIN curriculum_versions v ON v.id = p.curriculum_version_id JOIN curricula c ON c.id = v.curriculum_id
                JOIN academic_programs ap ON ap.id = c.academic_program_id WHERE p.subject_id = ?' . ($scope !== '' ? ' AND ' . $scope : '') . '
                ORDER BY ap.program_code, c.curriculum_code, v.version_label, p.id', 'i', [$subjectId]);
    }

    /** Placements of a subject in versions that cannot be edited (all of them, regardless of the reader's scope). */
    public function lockedVersionsFor(int $subjectId): array
    {
        return array_values(array_filter($this->rows('SELECT DISTINCT v.id, v.status, v.review_status, v.version_role, v.baseline_version_id, v.version_label, c.curriculum_code, ap.program_code
                FROM curriculum_version_subjects p JOIN curriculum_versions v ON v.id = p.curriculum_version_id JOIN curricula c ON c.id = v.curriculum_id
                JOIN academic_programs ap ON ap.id = c.academic_program_id WHERE p.subject_id = ?', 'i', [$subjectId]), static fn(array $v): bool => !CurriculumVersionEditPolicy::isEditable($v)));
    }

    /**
     * Program and strand cards for the By program tab: code, name, distinct subjects placed in any version of the program (readable versions only)
     * and number of curricula. Server-side search over code and name, College / SHS filter and paging; college programs come first.
     */
    public function programCards(array $query): array
    {
        $shs = "(LOWER(TRIM(COALESCE(ap.program_type, ''))) LIKE '%senior high%' OR LOWER(TRIM(COALESCE(ap.program_type, ''))) = 'shs' OR LOWER(TRIM(COALESCE(ap.program_type, ''))) LIKE '%strand%')";
        $conditions = ['ap.archived_at IS NULL'];
        $types = '';
        $params = [];
        if ($query['search'] !== null) {
            $conditions[] = '(' . RepositoryQuery::searchColumn('ap.program_code') . " LIKE ? ESCAPE '=' OR " . RepositoryQuery::searchColumn('ap.program_name') . " LIKE ? ESCAPE '=')";
            $pattern = RepositoryQuery::likePattern($query['search']);
            $types .= 'ss';
            array_push($params, $pattern, $pattern);
        }
        $searchWhere = ' WHERE ' . implode(' AND ', $conditions);
        $groups = $this->rows("SELECT COALESCE(SUM(NOT $shs), 0) AS college, COALESCE(SUM($shs), 0) AS shs FROM academic_programs ap" . $searchWhere, $types, $params)[0];
        if ($query['level'] === 'college') $conditions[] = "NOT $shs";
        if ($query['level'] === 'shs') $conditions[] = $shs;
        $where = ' WHERE ' . implode(' AND ', $conditions);
        $total = (int)$this->rows('SELECT COUNT(*) AS total FROM academic_programs ap' . $where, $types, $params)[0]['total'];
        $pagination = RepositoryQuery::pagination($total, $query['page'], $query['per_page']);
        $scope = CurriculumReadScope::sql('v');
        $rows = $this->rows("SELECT ap.id, ap.program_code, ap.program_name, ap.program_type, ($shs) AS is_shs,
                (SELECT COUNT(*) FROM curricula c WHERE c.academic_program_id = ap.id) AS curriculum_count,
                (SELECT COUNT(DISTINCT p.subject_id) FROM curriculum_version_subjects p JOIN curriculum_versions v ON v.id = p.curriculum_version_id JOIN curricula c ON c.id = v.curriculum_id
                  WHERE c.academic_program_id = ap.id" . ($scope !== '' ? ' AND ' . $scope : '') . ") AS subject_count
            FROM academic_programs ap" . $where . ' ORDER BY is_shs ASC, ap.program_code ASC, ap.id ASC LIMIT ? OFFSET ?', $types . 'ii', array_merge($params, [$pagination['per_page'], $pagination['offset']]));
        unset($pagination['offset']);
        return ['records' => $rows, 'pagination' => $pagination, 'groups' => ['college' => (int)$groups['college'], 'shs' => (int)$groups['shs']]];
    }

    /** All placements of one version, filtered, for the By program tab (a version holds tens to a few hundred placements). */
    public function versionPlacements(int $versionId, array $filters): array
    {
        $conditions = ['p.curriculum_version_id = ?'];
        $types = 'i';
        $params = [$versionId];
        if ($filters['search'] !== null) {
            $conditions[] = '(' . RepositoryQuery::searchColumn('s.subject_code') . " LIKE ? ESCAPE '=' OR " . RepositoryQuery::searchColumn('s.subject_title') . " LIKE ? ESCAPE '=')";
            $pattern = RepositoryQuery::likePattern($filters['search']);
            $types .= 'ss';
            array_push($params, $pattern, $pattern);
        }
        if ($filters['status'] !== null) {
            $conditions[] = 's.status = ?';
            $types .= 's';
            $params[] = $filters['status'];
        }
        return $this->rows('SELECT p.id AS placement_id, p.year_level, p.semester, p.sequence_order, p.is_required, s.id, s.subject_code, s.subject_title, s.units, s.status
                FROM curriculum_version_subjects p JOIN subjects s ON s.id = p.subject_id WHERE ' . implode(' AND ', $conditions) . '
                ORDER BY p.year_level IS NULL, p.year_level, p.semester IS NULL, p.semester, p.sequence_order IS NULL, p.sequence_order, s.subject_code, p.id LIMIT 1000', $types, $params);
    }

    public function versionTotals(int $versionId): array
    {
        $row = $this->rows('SELECT COUNT(*) AS placements, COUNT(DISTINCT p.subject_id) AS subjects, COALESCE(SUM(s.units), 0) AS units, COALESCE(SUM(CASE WHEN p.is_required = 1 THEN s.units ELSE 0 END), 0) AS required_units
                FROM curriculum_version_subjects p JOIN subjects s ON s.id = p.subject_id WHERE p.curriculum_version_id = ?', 'i', [$versionId])[0];
        return ['placements' => (int)$row['placements'], 'subjects' => (int)$row['subjects'], 'units' => (string)$row['units'], 'required_units' => (string)$row['required_units']];
    }

    private function rows(string $sql, string $types = '', array $params = []): array
    {
        $stmt = RepositoryQuery::execute($this->db, $sql, $types, $params);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function placementVersions(array $query, array $filters = []): array
    {
        $conditions = [];
        $params = [];
        $types = '';
        foreach (['academic_program_id' => ['c.academic_program_id', 'i'], 'curriculum_id' => ['c.id', 'i']] as $field => [$column, $type]) {
            if (($filters[$field] ?? null) !== null) {
                $conditions[] = $column . ' = ?';
                $types .= $type;
                $params[] = $filters[$field];
            }
        }
        if ($query['search'] !== null) {
            $conditions[] = "(" . RepositoryQuery::searchColumn('c.curriculum_code') . " LIKE ? ESCAPE '=' OR " . RepositoryQuery::searchColumn('c.curriculum_name') . " LIKE ? ESCAPE '=' OR " . RepositoryQuery::searchColumn('v.version_label') . " LIKE ? ESCAPE '=')";
            array_push($params, ...array_fill(0, 3, RepositoryQuery::likePattern($query['search'])));
            $types .= 'sss';
        }
        if (($readScope = CurriculumReadScope::sql('v')) !== '') $conditions[] = $readScope;
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $joins = ' FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id';
        $stmt = RepositoryQuery::execute($this->db, 'SELECT COUNT(*) AS total' . $joins . $where, $types, $params);
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        $pagination = RepositoryQuery::pagination($total, $query['page'], $query['per_page']);
        $rankParams = [];
        $searchOrder = RepositoryQuery::searchOrder(['c.curriculum_code','c.curriculum_name','v.version_label'], $query['search'] ?? null, $rankParams);
        $stmt = RepositoryQuery::execute($this->db,
            'SELECT v.id, v.version_label, c.curriculum_code, c.curriculum_name' . $joins . $where .
            ' ORDER BY ' . $searchOrder . 'c.curriculum_code, v.version_label, v.id LIMIT ? OFFSET ?',
            $types . str_repeat('s', count($rankParams)) . 'ii', array_merge($params, $rankParams, [$pagination['per_page'], $pagination['offset']]));
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        unset($pagination['offset']);
        return ['records' => $rows, 'pagination' => $pagination];
    }

    public function placementPrograms(): array
    {
        $stmt = $this->db->query(
            'SELECT ap.id, ap.program_code, ap.program_name, ap.program_type,
                    COUNT(DISTINCT cvs.subject_id) AS subject_count,
                    COUNT(DISTINCT c.id) AS curriculum_count,
                    COUNT(DISTINCT v.id) AS version_count,
                    CASE
                        WHEN COUNT(DISTINCT v.id) = 1 THEN MAX(v.version_label)
                        WHEN COUNT(DISTINCT c.id) = 1 THEN MAX(c.curriculum_code)
                        ELSE NULL
                    END AS card_context_label
             FROM academic_programs ap
             LEFT JOIN curricula c ON c.academic_program_id = ap.id
             LEFT JOIN curriculum_versions v ON v.curriculum_id = c.id
             LEFT JOIN curriculum_version_subjects cvs ON cvs.curriculum_version_id = v.id
             GROUP BY ap.id, ap.program_code, ap.program_name, ap.program_type
             ORDER BY ap.program_code ASC, ap.id ASC'
        );
        return $stmt->fetch_all(MYSQLI_ASSOC);
    }

    public function placementCurricula(?int $programId): array
    {
        $sql = 'SELECT c.id, c.curriculum_code, c.curriculum_name, ap.program_code
                FROM curricula c JOIN academic_programs ap ON ap.id = c.academic_program_id';
        if ($programId === null) {
            $stmt = $this->db->query($sql . ' ORDER BY c.curriculum_code, c.id');
            return $stmt->fetch_all(MYSQLI_ASSOC);
        }
        $stmt = $this->db->prepare($sql . ' WHERE ap.id = ? ORDER BY c.curriculum_code, c.id');
        $stmt->bind_param('i', $programId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $result;
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
