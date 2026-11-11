<?php

require_once __DIR__ . '/../repositories/RepositoryQuery.php';
require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/CurriculumReadScope.php';

/**
 * The user's shared working context (Program / Curriculum / Version).
 *
 * Only integers are stored; codes, names and status are read from the real tables each time.
 * "Permitted" means the user holds the module's view permission and the version exists in a consistent
 * program > curriculum > version chain. The application has no per-program access rules beyond that.
 */
class WorkingContextService
{
    private const KEY = 'working_context';
    private const MAX_RECENT = 3;
    private const MAX_BYTES = 2048;

    private const MODULE_PERMISSIONS = [
        'subject_mapping' => 'curriculum.view',
        'builder' => 'curriculum.view',
        'prerequisite' => 'prerequisite.view',
        'electives' => 'elective.view',
        'assignment' => 'program_assignment.view',
        'export' => 'curriculum_export.view',
        'grade_weighting' => 'grade_weighting.view',
        'history' => 'subject_history.view',
    ];

    // Actions that change version content. Viewing, exporting and renaming do not count.
    public const CONTENT_ACTIONS = [
        'CURRICULUM_VERSION_CREATE', 'CURRICULUM_REVISION_CREATE',
        'CURRICULUM_SUBJECT_ASSIGN', 'CURRICULUM_SUBJECT_UPDATE', 'CURRICULUM_SUBJECT_REMOVE',
        'SUBJECT_REQUIREMENT_CREATE', 'SUBJECT_REQUIREMENT_UPDATE', 'SUBJECT_REQUIREMENT_REMOVE',
        'ELECTIVE_GROUP_CREATE', 'ELECTIVE_GROUP_UPDATE', 'ELECTIVE_GROUP_REMOVE', 'ELECTIVE_GROUP_ARCHIVE',
        'ELECTIVE_GROUP_STATUS_CHANGE', 'ELECTIVE_GROUP_SELECTION_CHANGE', 'ELECTIVE_GROUP_SUBJECT_ADD', 'ELECTIVE_GROUP_SUBJECT_REMOVE',
        'GRADE_WEIGHTING_CONFIGURATION_SAVE', 'GRADE_WEIGHTING_SCHEME_CREATE', 'GRADE_WEIGHTING_SCHEME_UPDATE', 'GRADE_WEIGHTING_SCHEME_REMOVE',
        'GRADE_WEIGHTING_COMPONENT_CREATE', 'GRADE_WEIGHTING_COMPONENT_UPDATE', 'GRADE_WEIGHTING_COMPONENT_REMOVE',
    ];

    private ?int $scopeUser = null;
    private string $scopeModule = '';

    /** @param callable(int,string):bool $can */
    public function __construct(private mysqli $db, private $can) {}

    /** Rules in order: (a) ids in the address, (b) saved, (c) last edited, (d) newest active. Null context = rule (e). */
    public function resolve(int $userId, array $input): array
    {
        if (!($this->can)($userId, $this->modulePermission($input))) {
            return ['rule' => null, 'context' => null, 'recent' => []];
        }
        $this->scopeUser = $userId;
        $this->scopeModule = (string)$input['module'];
        $stored = $this->stored($userId);
        $context = null;
        $rule = null;
        $linked = $this->optionalId($input['version_id'] ?? null);
        if ($linked !== null) {
            $context = $this->chain($linked, $this->optionalId($input['curriculum_id'] ?? null), $this->optionalId($input['program_id'] ?? null));
            $rule = $context ? 'url' : null;
        }
        // A partial address (program and/or curriculum, no version) opens the newest active version under it, so old
        // Curricula links land on the right curriculum. Read scope still applies to every candidate.
        if (!$context && $linked === null) {
            $linkedCurriculum = $this->optionalId($input['curriculum_id'] ?? null);
            $linkedProgram = $this->optionalId($input['program_id'] ?? null);
            if ($linkedCurriculum !== null || $linkedProgram !== null) {
                foreach ($this->versionsUnder($linkedCurriculum, $linkedProgram) as $candidate) {
                    $context = $this->chain($candidate, $linkedCurriculum, $linkedProgram);
                    if ($context) { $rule = 'url'; break; }
                }
            }
        }
        if (!$context && !empty($stored['version_id'])) {
            $context = $this->chain((int)$stored['version_id'], (int)($stored['curriculum_id'] ?? 0), (int)($stored['program_id'] ?? 0));
            $rule = $context ? 'saved' : null;
        }
        if (!$context && ($edited = $this->lastEditedVersion($userId)) !== null) {
            $context = $this->chain($edited);
            $rule = $context ? 'last_edited' : null;
        }
        if (!$context && ($newest = $this->newestActiveVersion()) !== null) {
            $context = $this->chain($newest);
            $rule = $context ? 'newest_active' : null;
        }
        return ['rule' => $rule, 'context' => $context, 'recent' => $this->recentContexts($stored)];
    }

    /** Saves this user's context, or clears it with clear=1 (Recent is kept). */
    public function save(int $userId, array $input): array
    {
        if (!($this->can)($userId, $this->modulePermission($input))) {
            throw new DomainValidationException('You cannot use this curriculum context in this module.');
        }
        $this->scopeUser = $userId;
        $this->scopeModule = (string)$input['module'];
        $stored = $this->stored($userId);
        $recent = is_array($stored['recent'] ?? null) ? $stored['recent'] : [];
        if (($input['clear'] ?? '') === '1') {
            $value = ['recent' => $recent];
        } else {
            $program = DomainValidator::positiveId($input['program_id'] ?? null, 'Program / Strand');
            $curriculum = DomainValidator::positiveId($input['curriculum_id'] ?? null, 'Curriculum');
            $version = DomainValidator::positiveId($input['version_id'] ?? null, 'Curriculum version');
            if (!$this->chain($version, $curriculum, $program)) {
                throw new DomainValidationException('Select a curriculum version belonging to the selected program and curriculum.');
            }
            $entry = ['program_id' => $program, 'curriculum_id' => $curriculum, 'version_id' => $version];
            $others = array_filter($recent, static fn($item): bool => is_array($item) && (int)($item['version_id'] ?? 0) !== $version);
            $value = $entry + ['recent' => array_slice(array_merge([$entry], array_values($others)), 0, self::MAX_RECENT)];
        }
        $json = json_encode($value, JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_BYTES) {
            throw new DomainValidationException('The working context is too large to save.');
        }
        $key = self::KEY;
        RepositoryQuery::execute($this->db,
            'INSERT INTO user_preferences (user_id, pref_key, pref_value) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)', 'iss', [$userId, $key, $json])->close();
        return ['recent' => $this->recentContexts($value)];
    }

    private function modulePermission(array $input): string
    {
        $module = is_string($input['module'] ?? null) ? $input['module'] : '';
        if (!isset(self::MODULE_PERMISSIONS[$module])) {
            throw new DomainValidationException('Unknown curriculum module.');
        }
        return self::MODULE_PERMISSIONS[$module];
    }

    private function optionalId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : (int)$id;
    }

    private function stored(int $userId): array
    {
        $key = self::KEY;
        $stmt = RepositoryQuery::execute($this->db, 'SELECT pref_value FROM user_preferences WHERE user_id = ? AND pref_key = ?', 'is', [$userId, $key]);
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        $value = $row ? json_decode((string)$row[0], true) : null;
        return is_array($value) ? $value : [];
    }

    /** The version with its curriculum and program, or null when missing or when given parent ids do not match. */
    private function chain(int $version, ?int $curriculum = null, ?int $program = null): ?array
    {
        $stmt = RepositoryQuery::execute($this->db,
            'SELECT p.id AS program_id, p.program_code, p.program_name,
                    c.id AS curriculum_id, c.curriculum_code, c.curriculum_name,
                    v.id AS version_id, v.version_label, v.status, v.review_status, v.effective_academic_year
             FROM curriculum_versions v
             JOIN curricula c ON c.id = v.curriculum_id
             JOIN academic_programs p ON p.id = c.academic_program_id
             WHERE v.id = ?', 'i', [$version]);
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || ($curriculum !== null && (int)$row['curriculum_id'] !== $curriculum) || ($program !== null && (int)$row['program_id'] !== $program)) {
            return null;
        }
        $user = (int)$this->scopeUser;
        if (!($this->can)($user, 'curriculum.view_unapproved') && !CurriculumReadScope::inRestrictedScope($row)) {
            return null;
        }
        $exportKey = $row['review_status'] === 'approved' ? 'curriculum_export.official' : 'curriculum_export.draft';
        if ($this->scopeModule === 'export' && !($this->can)($user, $exportKey)) {
            return null;
        }
        return [
            'program' => ['id' => (string)$row['program_id'], 'code' => (string)$row['program_code'], 'name' => (string)$row['program_name']],
            'curriculum' => ['id' => (string)$row['curriculum_id'], 'code' => (string)$row['curriculum_code'], 'name' => (string)$row['curriculum_name']],
            'version' => ['id' => (string)$row['version_id'], 'code' => (string)$row['version_label'], 'name' => '', 'status' => (string)$row['status'], 'year' => (string)($row['effective_academic_year'] ?? '')],
        ];
    }

    private function recentContexts(array $stored): array
    {
        $contexts = [];
        foreach (is_array($stored['recent'] ?? null) ? $stored['recent'] : [] as $item) {
            if (!is_array($item) || empty($item['version_id'])) continue;
            $context = $this->chain((int)$item['version_id'], (int)($item['curriculum_id'] ?? 0), (int)($item['program_id'] ?? 0));
            if ($context) $contexts[] = $context;
        }
        return array_slice($contexts, 0, self::MAX_RECENT);
    }

    /**
     * Newest content-changing audit entry by this user that still leads to an existing version. The version comes from
     * the entry itself, its metadata, or the placement / elective group / weighting scheme or component it refers to.
     */
    private function lastEditedVersion(int $userId): ?int
    {
        $marks = implode(',', array_fill(0, count(self::CONTENT_ACTIONS), '?'));
        $stmt = RepositoryQuery::execute($this->db,
            "SELECT v.id
             FROM audit_logs a
             LEFT JOIN curriculum_version_subjects placement ON placement.id = COALESCE(
                 CAST(JSON_VALUE(a.metadata_json, '$.curriculum_version_subject_id') AS UNSIGNED),
                 CASE WHEN a.entity_type = 'curriculum_version_subject' THEN a.entity_id END)
             LEFT JOIN curriculum_elective_groups elective ON elective.id = COALESCE(
                 CAST(JSON_VALUE(a.metadata_json, '$.elective_group_id') AS UNSIGNED),
                 CASE WHEN a.entity_type = 'curriculum_elective_group' THEN a.entity_id END)
             LEFT JOIN grade_weighting_components component ON a.entity_type = 'grade_weighting_component' AND component.id = a.entity_id
             LEFT JOIN grade_weighting_schemes scheme ON scheme.id = COALESCE(
                 CAST(JSON_VALUE(a.metadata_json, '$.scheme_id') AS UNSIGNED),
                 component.scheme_id,
                 CASE WHEN a.entity_type = 'grade_weighting_scheme' THEN a.entity_id END)
             LEFT JOIN curriculum_version_subjects scheme_placement ON scheme_placement.id = scheme.curriculum_version_subject_id
             JOIN curriculum_versions v ON v.id = COALESCE(
                 CASE WHEN a.entity_type = 'curriculum_version' THEN a.entity_id END,
                 CAST(JSON_VALUE(a.metadata_json, '$.curriculum_version_id') AS UNSIGNED),
                 CAST(JSON_VALUE(a.metadata_json, '$.version_id') AS UNSIGNED),
                 placement.curriculum_version_id,
                 elective.curriculum_version_id,
                 scheme_placement.curriculum_version_id)
             WHERE a.user_id = ? AND a.action IN ($marks)
             ORDER BY a.id DESC LIMIT 1",
            'i' . str_repeat('s', count(self::CONTENT_ACTIONS)), array_merge([$userId], self::CONTENT_ACTIONS));
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return $row ? (int)$row[0] : null;
    }

    /** Candidate versions under a curriculum or program: active first, then newest. */
    private function versionsUnder(?int $curriculum, ?int $program): array
    {
        $where = [];
        $types = '';
        $params = [];
        if ($curriculum !== null) { $where[] = 'v.curriculum_id = ?'; $types .= 'i'; $params[] = $curriculum; }
        if ($program !== null) { $where[] = 'c.academic_program_id = ?'; $types .= 'i'; $params[] = $program; }
        $stmt = RepositoryQuery::execute($this->db,
            'SELECT v.id FROM curriculum_versions v JOIN curricula c ON c.id = v.curriculum_id
             WHERE ' . implode(' AND ', $where) . "
             ORDER BY (v.status = 'active') DESC, v.updated_at DESC, v.id DESC LIMIT 25", $types, $params);
        $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_NUM), 0));
        $stmt->close();
        return $ids;
    }

    private function newestActiveVersion(): ?int
    {
        $stmt = RepositoryQuery::execute($this->db,
            "SELECT v.id
             FROM curriculum_versions v
             JOIN curricula c ON c.id = v.curriculum_id
             JOIN academic_programs p ON p.id = c.academic_program_id
             WHERE v.status = 'active' AND p.status = 'active'
             ORDER BY v.updated_at DESC, p.program_code ASC, v.id DESC LIMIT 1");
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return $row ? (int)$row[0] : null;
    }
}
