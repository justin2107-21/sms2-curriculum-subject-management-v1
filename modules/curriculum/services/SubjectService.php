<?php

require_once __DIR__ . '/CurriculumVersionEditPolicy.php';

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/SubjectRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionSubjectRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';

class SubjectService
{
    private SubjectRepository $subjects;

    public function __construct(private mysqli $db)
    {
        $this->subjects = new SubjectRepository($db);
    }

    public function get(int $id): array
    {
        $subject = $this->subjects->findById($id);
        if (!$subject) {
            throw new DomainNotFoundException('Subject not found.');
        }
        return $subject;
    }

    public function list(array $input): array
    {
        $query = DomainValidator::listQuery($input, ['active', 'inactive']);
        $query['curriculum_version_id'] = DomainValidator::optionalPositiveId($input['curriculum_version_id'] ?? null, 'Curriculum version');
        $query['academic_program_id'] = DomainValidator::optionalPositiveId($input['academic_program_id'] ?? null, 'Academic program');
        $query['curriculum_id'] = DomainValidator::optionalPositiveId($input['curriculum_id'] ?? null, 'Curriculum');
        $query['year_level'] = DomainValidator::optionalString($input, 'year_level', 'Year level', 50);
        $query['semester'] = DomainValidator::optionalString($input, 'semester', 'Semester', 50);
        if ($query['curriculum_version_id'] !== null && !(new CurriculumVersionRepository($this->db))->findById($query['curriculum_version_id'])) {
            throw new DomainNotFoundException('Curriculum version not found.');
        }
        $query['_include_placement_context'] = ($input['_include_placement_context'] ?? false) === true;
        $result = $this->subjects->paginate($query);
        return ['subjects' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function contexts(array $input): array
    {
        $query = DomainValidator::listQuery($input);
        $filters = DomainValidator::subjectMappingQuery($input);
        if ($filters['curriculum_version_id'] !== null && !(new CurriculumVersionRepository($this->db))->findById($filters['curriculum_version_id'])) {
            throw new DomainNotFoundException('Curriculum version not found.');
        }
        $result = $this->subjects->placementVersions($query, $filters);
        return ['programs' => $this->subjects->placementPrograms(),
            'curricula' => $this->subjects->placementCurricula($filters['academic_program_id']),
            'versions' => $result['records'], 'pagination' => $result['pagination'],
            'filter_values' => (new CurriculumVersionSubjectRepository($this->db))->mappingFilterValues($filters)];
    }

    public function create(array $input, int $createdBy): array
    {
        $data = DomainValidator::subject($input);
        $this->db->begin_transaction();
        try {
            if ($this->subjects->codeExists($data['subject_code'])) {
                throw new DomainConflictException('Subject code already exists.');
            }
            $id = $this->subjects->create($data, $createdBy);
            audit_log_required(
                'SUBJECT_CREATE',
                'subject',
                $id,
                'Subject master record created.',
                ['subject_code' => $data['subject_code'], 'status' => $data['status']]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $this->get($id);
    }

    public function update(int $id, array $input): array
    {
        $data = DomainValidator::subject($input);
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertMasterEditable('subject', $id, $data);
            $existing = $this->subjects->findById($id);
            if (!$existing) {
                throw new DomainNotFoundException('Subject not found.');
            }
            if ($existing['archived_at'] !== null) throw new DomainConflictException('Restore this subject from Settings > Archives before editing.');
            if ($this->subjects->codeExists($data['subject_code'], $id)) {
                throw new DomainConflictException('Subject code already exists.');
            }
            $this->subjects->update($id, $data);
            audit_log_required(
                'SUBJECT_UPDATE',
                'subject',
                $id,
                'Subject master record updated.',
                ['subject_code' => $data['subject_code'], 'status' => $data['status']]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $this->get($id);
    }
}
