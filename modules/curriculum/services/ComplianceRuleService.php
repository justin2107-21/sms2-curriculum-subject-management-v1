<?php

require_once __DIR__ . '/CurriculumVersionEditPolicy.php';

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/ComplianceReferenceRepository.php';
require_once __DIR__ . '/../repositories/ComplianceRuleRepository.php';
require_once __DIR__ . '/../repositories/ComplianceLibraryRepository.php';
require_once __DIR__ . '/../repositories/ComplianceLibraryRepository.php';
require_once __DIR__ . '/../repositories/AcademicProgramRepository.php';
require_once __DIR__ . '/../repositories/CurriculumRepository.php';
require_once __DIR__ . '/../repositories/SubjectRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';

class ComplianceRuleService
{
    private ComplianceReferenceRepository $references;
    private ComplianceRuleRepository $rules;
    private ComplianceLibraryRepository $library;
    private AcademicProgramRepository $programs;
    private CurriculumRepository $curricula;
    private SubjectRepository $subjects;

    public function __construct(private mysqli $db)
    {
        $this->references = new ComplianceReferenceRepository($db);
        $this->rules = new ComplianceRuleRepository($db);
        $this->library = new ComplianceLibraryRepository($db);
        $this->programs = new AcademicProgramRepository($db);
        $this->curricula = new CurriculumRepository($db);
        $this->subjects = new SubjectRepository($db);
    }

    /** Library rules and custom rules together (library first). Without migration 032 only the custom rules exist. */
    public function list(array $input): array
    {
        $query = DomainValidator::complianceRuleQuery($input);
        if (!$this->library->available()) {
            if (($query['origin'] ?? null) === 'library' || ($query['source_class'] ?? null) === 'client_reply' || in_array($query['applies_to'] ?? null, ['all_college', 'senior_high'], true)) {
                return ['rules' => [], 'pagination' => RepositoryQuery::pagination(0, $query['page'], $query['per_page'])];
            }
            $result = $this->rules->paginate($query);
            foreach ($result['records'] as &$record) $record['origin'] = 'custom';
            return ['rules' => $result['records'], 'pagination' => $result['pagination']];
        }
        $page = $this->library->pageKeys($query, $this->rules->keyFilter($query));
        $records = [];
        foreach ($page['keys'] as $key) {
            $record = $key['origin'] === 'library' ? $this->library->findById((int)$key['id']) : $this->rules->findById((int)$key['id']);
            if ($record) {
                $record['origin'] = $key['origin'];
                $records[] = $record;
            }
        }
        return ['rules' => $records, 'pagination' => $page['pagination']];
    }

    public function getLibrary(int $id): array
    {
        $rule = $this->library->available() ? $this->library->findById($id) : null;
        if (!$rule) throw new DomainNotFoundException('Library rule not found.');
        return $rule;
    }

    /** Library values are never edited in the application. A manager can only switch a library rule off (or on again). */
    public function setLibraryActive(int $id, bool $active): array
    {
        $this->db->begin_transaction();
        try {
            $rule = $this->getLibrary($id);
            $this->library->setActive($id, $active);
            audit_log_required('COMPLIANCE_LIBRARY_RULE_ACTIVE', 'compliance_library_rule', $id, 'Library rule ' . ($active ? 'activated.' : 'deactivated.'),
                ['rule_code' => $rule['rule_code'], 'old_active' => (int)$rule['active'], 'new_active' => $active ? 1 : 0]);
            $updated = $this->getLibrary($id);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $updated;
    }

    public function get(int $id): array
    {
        $rule = $this->rules->findById($id);
        if (!$rule) {
            throw new DomainNotFoundException('Compliance rule not found.');
        }
        return $rule;
    }

    public function create(array $input, int $actorId): array
    {
        $data = DomainValidator::complianceRule($input);
        $this->db->begin_transaction();
        try {
            $policy = new CurriculumVersionEditPolicy($this->db);
            $policy->assertRuleScopeEditable($data);
            $this->assertRelatedRecords($data);
            if ($this->rules->nameExists($data)) {
                throw new DomainConflictException('A rule with this name already exists for this reference and scope.');
            }
            $id = $this->rules->create($data, $actorId);
            audit_log_required(
                'COMPLIANCE_RULE_CREATE',
                'compliance_rule',
                $id,
                'Deterministic compliance rule created.',
                $this->auditMetadata($data)
            );
            $rule = $this->rules->findById($id);
            if (!$rule) {
                throw new RuntimeException('Created compliance rule could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $rule;
    }

    public function update(int $id, array $input): array
    {
        $data = DomainValidator::complianceRule($input);
        $this->db->begin_transaction();
        try {
            $policy = new CurriculumVersionEditPolicy($this->db);
            $oldScope = $this->rules->findById($id);
            if (!$oldScope) throw new DomainNotFoundException('Compliance rule not found.');
            $policy->assertRuleScopeEditable($oldScope);
            $policy->assertRuleScopeEditable($data);
            $existing = $this->rules->findByIdForUpdate($id);
            if (!$existing) {
                throw new DomainNotFoundException('Compliance rule not found.');
            }
            $policy->assertRuleScopeEditable($existing);
            $this->assertRelatedRecords($data);
            if ($this->rules->hasHistory($id) && $this->semanticFieldsChanged($existing, $data)) {
                throw new DomainConflictException(
                    'This rule has validation history. Create a new rule to change its reference, scope, type, subject, or threshold.'
                );
            }
            if ($this->rules->nameExists($data, $id)) {
                throw new DomainConflictException('A rule with this name already exists for this reference and scope.');
            }
            $this->rules->update($id, $data);
            audit_log_required(
                'COMPLIANCE_RULE_UPDATE',
                'compliance_rule',
                $id,
                'Deterministic compliance rule updated.',
                $this->auditMetadata($data)
            );
            $rule = $this->rules->findById($id);
            if (!$rule) {
                throw new RuntimeException('Updated compliance rule could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $rule;
    }

    private function assertRelatedRecords(array $data): void
    {
        if (!$this->references->findById($data['compliance_reference_id'])) {
            throw new DomainNotFoundException('Compliance reference not found.');
        }
        if ($data['academic_program_id'] !== null
            && !$this->programs->findById($data['academic_program_id'])) {
            throw new DomainNotFoundException('Academic program not found.');
        }
        if ($data['curriculum_id'] !== null
            && !$this->curricula->findById($data['curriculum_id'])) {
            throw new DomainNotFoundException('Curriculum not found.');
        }
        if ($data['subject_id'] !== null && !$this->subjects->findById($data['subject_id'])) {
            throw new DomainNotFoundException('Configured Subject Master record not found.');
        }
    }

    private function semanticFieldsChanged(array $existing, array $data): bool
    {
        foreach ([
            'compliance_reference_id', 'rule_type', 'numeric_value', 'subject_id',
            'academic_program_id', 'curriculum_id',
        ] as $field) {
            $before = $existing[$field] === null ? null : (string)$existing[$field];
            $after = $data[$field] === null ? null : (string)$data[$field];
            if ($before !== $after) {
                return true;
            }
        }
        return false;
    }

    private function auditMetadata(array $data): array
    {
        return [
            'compliance_reference_id' => $data['compliance_reference_id'],
            'rule_type' => $data['rule_type'],
            'academic_program_id' => $data['academic_program_id'],
            'curriculum_id' => $data['curriculum_id'],
            'technical_status' => $data['technical_status'],
        ];
    }
}
