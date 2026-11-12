<?php

require_once __DIR__ . '/RepositoryQuery.php';

/** Rule library (migration 032). Every method is safe to call before the migration is applied: available() is false and nothing else runs. */
class ComplianceLibraryRepository
{
    private static array $available = [];

    public function __construct(private mysqli $db)
    {
    }

    public function available(): bool
    {
        $key = spl_object_id($this->db);
        if (!isset(self::$available[$key])) {
            self::$available[$key] = $this->db->query("SHOW TABLES LIKE 'compliance_library_rules'")->num_rows > 0
                && $this->db->query("SHOW TABLES LIKE 'compliance_library_results'")->num_rows > 0;
        }
        return self::$available[$key];
    }

    private const DETAIL_SELECT = 'SELECT lib.*, refs.reference_type, refs.reference_code, refs.title AS reference_title, refs.issuing_body, refs.source_url,
                       refs.verification_status, refs.effective_date, YEAR(refs.effective_date) AS reference_year,
                       EXISTS(SELECT 1 FROM compliance_library_results AS history WHERE history.library_rule_id = lib.id) AS has_history
                FROM compliance_library_rules AS lib
                LEFT JOIN compliance_references AS refs ON refs.id = lib.compliance_reference_id';

    private function rows(string $sql, string $types = '', array $params = []): array
    {
        $stmt = RepositoryQuery::execute($this->db, $sql, $types, $params);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function findById(int $id): ?array
    {
        $row = $this->rows(self::DETAIL_SELECT . ' WHERE lib.id = ? LIMIT 1', 'i', [$id])[0] ?? null;
        return $row ? self::shape($row) : null;
    }

    public function findByCode(string $code): ?array
    {
        return $this->rows('SELECT * FROM compliance_library_rules WHERE rule_code = ? LIMIT 1', 's', [$code])[0] ?? null;
    }

    /** Presents a library row with the keys the page already uses for rules. */
    public static function shape(array $row): array
    {
        $row['origin'] = 'library';
        $row['rule_name'] = $row['title'];
        $row['rule_type'] = $row['computation_key'];
        $row['technical_status'] = (int)$row['active'] === 1 ? 'active' : 'inactive';
        $row['is_demo'] = 0;
        $row['parameters'] = $row['parameters_json'] === null ? null : json_decode((string)$row['parameters_json'], true);
        $row['source_page_label'] = $row['source_page'] ?? null;
        return $row;
    }

    /** Insert or update by rule_code. The active flag is never overwritten. Returns created / updated / unchanged. */
    public function upsert(array $rule, ?int $referenceId): string
    {
        $existing = $this->findByCode($rule['rule_code']);
        $params = $rule['parameters'] === [] || $rule['parameters'] === null ? null : json_encode($rule['parameters'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $values = [
            'compliance_reference_id' => $referenceId, 'source_label' => $rule['source_label'] ?? null, 'section_label' => $rule['section_label'],
            'source_page' => $rule['source_page'] ?? null, 'title' => $rule['title'], 'requirement_text' => $rule['requirement_text'],
            'applies_to_kind' => $rule['applies_to_kind'], 'applies_to_value' => $rule['applies_to_value'] ?? null, 'computation_key' => $rule['computation_key'],
            'parameters_json' => $params, 'evaluation_mode' => $rule['evaluation_mode'], 'source_class' => $rule['source_class'], 'note' => $rule['note'] ?? null,
        ];
        if (!$existing) {
            $columns = array_merge(['rule_code'], array_keys($values));
            $stmt = $this->db->prepare('INSERT INTO compliance_library_rules (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
            $bind = array_merge([$rule['rule_code']], array_values($values));
            $stmt->bind_param('sissssssssssss', ...$bind);
            $stmt->execute();
            $stmt->close();
            return 'created';
        }
        $changed = false;
        foreach ($values as $column => $value) {
            if (($existing[$column] === null ? null : (string)$existing[$column]) !== ($value === null ? null : (string)$value)) $changed = true;
        }
        if ($changed) {
            $sets = implode(',', array_map(static fn($c) => "$c = ?", array_keys($values)));
            $stmt = $this->db->prepare("UPDATE compliance_library_rules SET $sets WHERE id = ?");
            $bind = array_merge(array_values($values), [(int)$existing['id']]);
            $stmt->bind_param('issssssssssssi', ...$bind);
            $stmt->execute();
            $stmt->close();
        }
        return $changed ? 'updated' : 'unchanged';
    }

    public function setActive(int $id, bool $active): void
    {
        $flag = $active ? 1 : 0;
        $stmt = RepositoryQuery::execute($this->db, 'UPDATE compliance_library_rules SET active = ? WHERE id = ?', 'ii', [$flag, $id]);
        $stmt->close();
    }

    /** Active library rules that apply to a program of this code and type (college: Bachelor / degree; senior high: Senior High School). */
    public function applicable(string $programCode, ?string $programType): array
    {
        $isShs = $programType !== null && stripos($programType, 'senior high') !== false ? 1 : 0;
        $isCollege = !$isShs && $programType !== null && preg_match('/bachelor|degree/i', $programType) ? 1 : 0;
        return $this->rows(self::DETAIL_SELECT . " WHERE lib.active = 1
                AND (lib.valid_from IS NULL OR lib.valid_from <= CURDATE()) AND (lib.valid_to IS NULL OR lib.valid_to >= CURDATE())
                AND ((lib.applies_to_kind = 'program_code' AND lib.applies_to_value = ?) OR (lib.applies_to_kind = 'all_college' AND ? = 1) OR (lib.applies_to_kind = 'senior_high' AND ? = 1))
                ORDER BY lib.id", 'sii', [$programCode, $isCollege, $isShs]);
    }

    public function createResult(int $runId, int $ruleId, string $status, ?string $actual, string $explanation, ?string $missing): void
    {
        $stmt = RepositoryQuery::execute($this->db,
            'INSERT INTO compliance_library_results (validation_run_id, library_rule_id, status, actual_text, explanation, missing_data_text) VALUES (?, ?, ?, ?, ?, ?)',
            'iissss', [$runId, $ruleId, $status, $actual, $explanation, $missing]);
        $stmt->close();
    }

    public function resultsForRun(int $runId): array
    {
        return $this->rows('SELECT res.id, res.library_rule_id, res.status, res.actual_text, res.explanation, res.missing_data_text, res.created_at,
                    lib.rule_code, lib.title, lib.requirement_text, lib.section_label, lib.source_page, lib.computation_key, lib.source_class, lib.source_label, lib.evaluation_mode,
                    refs.reference_code, refs.issuing_body, YEAR(refs.effective_date) AS reference_year, refs.reference_type, refs.verification_status AS reference_status, refs.source_kind
                FROM compliance_library_results AS res
                INNER JOIN compliance_library_rules AS lib ON lib.id = res.library_rule_id
                LEFT JOIN compliance_references AS refs ON refs.id = lib.compliance_reference_id
                WHERE res.validation_run_id = ? ORDER BY res.id', 'i', [$runId]);
    }

    /**
     * Key-only union of the two rule tables, filtered and paged in SQL: [['origin' => 'library'|'custom', 'id' => int], ...] and the total.
     * $custom is [whereSql, types, params] for the custom branch (already built by ComplianceRuleRepository).
     */
    public function pageKeys(array $query, array $custom): array
    {
        $libConditions = [];
        $libTypes = '';
        $libParams = [];
        if ($query['search'] !== null) {
            $libConditions[] = "(" . RepositoryQuery::searchColumn('lib.title') . " LIKE ? ESCAPE '=' OR " . RepositoryQuery::searchColumn('lib.rule_code') . " LIKE ? ESCAPE '=' OR " . RepositoryQuery::searchColumn('refs.reference_code') . " LIKE ? ESCAPE '=')";
            $pattern = RepositoryQuery::likePattern($query['search']);
            $libTypes .= 'sss';
            array_push($libParams, $pattern, $pattern, $pattern);
        }
        if ($query['rule_type'] !== null) { $libConditions[] = 'lib.computation_key = ?'; $libTypes .= 's'; $libParams[] = $query['rule_type']; }
        if ($query['technical_status'] !== null) { $libConditions[] = 'lib.active = ?'; $libTypes .= 'i'; $libParams[] = $query['technical_status'] === 'active' ? 1 : 0; }
        if ($query['compliance_reference_id'] !== null) { $libConditions[] = 'lib.compliance_reference_id = ?'; $libTypes .= 'i'; $libParams[] = $query['compliance_reference_id']; }
        if (($query['source_class'] ?? null) !== null) { $libConditions[] = 'lib.source_class = ?'; $libTypes .= 's'; $libParams[] = $query['source_class']; }
        if (($query['applies_to'] ?? null) !== null) {
            $libConditions[] = $query['applies_to'] === 'program' ? "lib.applies_to_kind = 'program_code'" : 'lib.applies_to_kind = ?';
            if ($query['applies_to'] !== 'program') { $libTypes .= 's'; $libParams[] = $query['applies_to']; }
        }
        if ($query['academic_program_id'] !== null || $query['curriculum_id'] !== null) $libConditions[] = '1 = 0';
        $libWhere = $libConditions ? ' WHERE ' . implode(' AND ', $libConditions) : '';
        $wantLibrary = ($query['origin'] ?? null) !== 'custom';
        $wantCustom = ($query['origin'] ?? null) !== 'library' && (($query['source_class'] ?? null) === null || $query['source_class'] === 'public_regulation') && ($query['applies_to'] ?? null) !== 'all_college' && ($query['applies_to'] ?? null) !== 'senior_high';
        $parts = [];
        $types = '';
        $params = [];
        if ($wantLibrary) {
            $parts[] = "SELECT 'library' AS origin, lib.id AS id, lib.title AS sort_name FROM compliance_library_rules AS lib LEFT JOIN compliance_references AS refs ON refs.id = lib.compliance_reference_id" . $libWhere;
            $types .= $libTypes;
            array_push($params, ...$libParams);
        }
        if ($wantCustom) {
            $parts[] = "SELECT 'custom' AS origin, rules.id AS id, rules.rule_name AS sort_name" . $custom['joins'] . $custom['where'];
            $types .= $custom['types'];
            array_push($params, ...$custom['params']);
        }
        if (!$parts) return ['total' => 0, 'keys' => [], 'pagination' => RepositoryQuery::pagination(0, $query['page'], $query['per_page'])];
        $union = implode(' UNION ALL ', $parts);
        $total = (int)$this->rows("SELECT COUNT(*) AS total FROM ($union) AS keys_table", $types, $params)[0]['total'];
        $pagination = RepositoryQuery::pagination($total, $query['page'], $query['per_page']);
        $keys = $this->rows("SELECT origin, id FROM ($union) AS keys_table ORDER BY origin DESC, sort_name ASC, id ASC LIMIT ? OFFSET ?", $types . 'ii', array_merge($params, [$pagination['per_page'], $pagination['offset']]));
        unset($pagination['offset']);
        return ['total' => $total, 'keys' => $keys, 'pagination' => $pagination];
    }
}
