<?php

require_once __DIR__ . '/CurriculumVersionEditPolicy.php';
require_once __DIR__ . '/WorkingContextService.php';

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/CurriculumRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';

class CurriculumVersionService
{
    private CurriculumRepository $curricula;
    private CurriculumVersionRepository $versions;

    public function __construct(private mysqli $db)
    {
        $this->curricula = new CurriculumRepository($db);
        $this->versions = new CurriculumVersionRepository($db);
    }

    public function get(int $id): array
    {
        $version = $this->versions->findById($id);
        if (!$version) {
            throw new DomainNotFoundException('Curriculum version not found.');
        }
        return $version;
    }

    /** Read-only header figures for the Builder: placed units and the unit target, if a rule sets one. */
    public function builderSummary(int $versionId): array
    {
        $stmt = $this->db->prepare('SELECT v.id, v.curriculum_id, c.academic_program_id FROM curriculum_versions v JOIN curricula c ON c.id = v.curriculum_id WHERE v.id = ?');
        $stmt->bind_param('i', $versionId);
        $stmt->execute();
        $version = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$version) throw new DomainNotFoundException('Curriculum version not found.');
        $stmt = $this->db->prepare('SELECT COUNT(*) AS placements, COALESCE(SUM(s.units), 0) AS units FROM curriculum_version_subjects m JOIN subjects s ON s.id = m.subject_id WHERE m.curriculum_version_id = ?');
        $stmt->bind_param('i', $versionId);
        $stmt->execute();
        $placed = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        // The curriculum's own MIN_TOTAL_UNITS rule beats its program's; no active rule means no target.
        $curriculumId = (int)$version['curriculum_id'];
        $programId = (int)$version['academic_program_id'];
        $stmt = $this->db->prepare("SELECT rule_name, numeric_value FROM compliance_rules WHERE rule_type = 'MIN_TOTAL_UNITS' AND technical_status = 'active'
            AND (curriculum_id = ? OR (academic_program_id = ? AND curriculum_id IS NULL)) ORDER BY (curriculum_id = ?) DESC, id LIMIT 1");
        $stmt->bind_param('iii', $curriculumId, $programId, $curriculumId);
        $stmt->execute();
        $rule = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return ['version_id' => $versionId, 'last_edit' => $this->lastContentEdit($versionId), 'placements' => (int)$placed['placements'], 'placed_units' => round((float)$placed['units'], 2),
            'target' => $rule ? ['value' => (float)$rule['numeric_value'], 'rule_name' => (string)$rule['rule_name']] : null];
    }

    /** Latest audit entry that changed this version's content (the same actions the working context counts), or null. */
    private function lastContentEdit(int $versionId): ?array
    {
        $actions = WorkingContextService::CONTENT_ACTIONS;
        $marks = implode(',', array_fill(0, count($actions), '?'));
        $stmt = $this->db->prepare(
            "SELECT a.created_at, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS full_name, u.username
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
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
             WHERE a.action IN ($marks) AND COALESCE(
                 CASE WHEN a.entity_type = 'curriculum_version' THEN a.entity_id END,
                 CAST(JSON_VALUE(a.metadata_json, '$.curriculum_version_id') AS UNSIGNED),
                 CAST(JSON_VALUE(a.metadata_json, '$.version_id') AS UNSIGNED),
                 placement.curriculum_version_id,
                 elective.curriculum_version_id,
                 scheme_placement.curriculum_version_id) = ?
             ORDER BY a.id DESC LIMIT 1");
        $stmt->bind_param(str_repeat('s', count($actions)) . 'i', ...[...$actions, $versionId]);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;
        $name = trim((string)$row['full_name']) !== '' ? trim((string)$row['full_name']) : (string)($row['username'] ?? '');
        return ['by' => $name !== '' ? $name : null, 'at' => (string)$row['created_at']];
    }

    public function list(int $curriculumId, array $input): array
    {
        $curriculum=$this->curricula->findById($curriculumId);
        if (!$curriculum) {
            throw new DomainNotFoundException('Curriculum not found.');
        }
        $program=DomainValidator::optionalPositiveId($input['academic_program_id']??null,'Academic program');
        if ($program!==null && $program!==(int)$curriculum['academic_program_id']) {
            throw new DomainValidationException('The selected curriculum does not belong to this program.');
        }
        $query = DomainValidator::listQuery($input, ['draft', 'active', 'archived']);
        $query['scope_sql'] = CurriculumReadScope::sql('curriculum_versions');
        $result = $this->versions->paginateByCurriculum($curriculumId, $query);
        return ['versions' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function create(array $input, int $createdBy): array
    {
        $data = DomainValidator::curriculumVersion($input);
        $this->db->begin_transaction();
        try {
            if (!$this->curricula->findById($data['curriculum_id'])) {
                throw new DomainNotFoundException('Curriculum not found.');
            }
            if ($this->versions->labelExists($data['curriculum_id'], $data['version_label'])) {
                throw new DomainConflictException('Version label already exists for this curriculum.');
            }
            $id = $this->versions->create($data, $createdBy);
            audit_log_required(
                'CURRICULUM_VERSION_CREATE',
                'curriculum_version',
                $id,
                'Curriculum version record created.',
                [
                    'curriculum_id' => $data['curriculum_id'],
                    'version_label' => $data['version_label'],
                    'status' => $data['status'],
                ]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $this->get($id);
    }

    public function rename(int $id, array $input, int $actorId): array
    {
        $label = DomainValidator::requiredString($input, 'version_label', 'Version label', 100);
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertEditable($id);
            $existing = $this->versions->findById($id);
            if (!$existing) {
                throw new DomainNotFoundException('Curriculum version not found.');
            }
            if ($this->versions->labelExists((int)$existing['curriculum_id'], $label, $id)) {
                throw new DomainConflictException('Version label already exists for this curriculum.');
            }
            $this->versions->rename($id, $label);
            audit_log_required(
                'CURRICULUM_VERSION_RENAME',
                'curriculum_version',
                $id,
                'Curriculum version label updated.',
                [
                    'curriculum_id' => (int)$existing['curriculum_id'],
                    'old_version_label' => $existing['version_label'],
                    'version_label' => $label,
                    'actor_id' => $actorId,
                ]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $this->get($id);
    }
}
