<?php

require_once __DIR__ . '/CurriculumVersionEditPolicy.php';

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/AcademicProgramRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';

class AcademicProgramService
{
    private AcademicProgramRepository $programs;

    public function __construct(private mysqli $db)
    {
        $this->programs = new AcademicProgramRepository($db);
    }

    public function get(int $id): array
    {
        $program = $this->programs->findById($id);
        if (!$program) {
            throw new DomainNotFoundException('Academic program not found.');
        }
        return $program;
    }

    public function list(array $input): array
    {
        $query = DomainValidator::listQuery($input, ['active', 'inactive']);
        $query['program_type'] = DomainValidator::optionalString($input, 'program_type', 'Degree level', 100);
        $category = DomainValidator::optionalString($input, 'program_category', 'Program category', 20);
        if ($category !== null && !in_array($category, ['college', 'shs'], true)) {
            throw new DomainValidationException('Program category is invalid.');
        }
        $query['program_category'] = $category;
        $result = $this->programs->paginate($query);
        return ['programs' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function create(array $input, int $createdBy): array
    {
        $data = DomainValidator::academicProgram($input);
        $this->db->begin_transaction();
        try {
            if ($this->programs->codeExists($data['program_code'])) {
                throw new DomainConflictException('Program code already exists.');
            }
            $id = $this->programs->create($data, $createdBy);
            audit_log_required(
                'PROGRAM_CREATE',
                'academic_program',
                $id,
                'Academic program record created.',
                ['program_code' => $data['program_code'], 'status' => $data['status']]
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
        $data = DomainValidator::academicProgram($input);
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertMasterEditable('program', $id, $data);
            $existing = $this->programs->findById($id);
            if (!$existing) {
                throw new DomainNotFoundException('Academic program not found.');
            }
            if ($existing['archived_at'] !== null) throw new DomainConflictException('Restore this academic program from Settings > Archives before editing.');
            if ($this->programs->codeExists($data['program_code'], $id)) {
                throw new DomainConflictException('Program code already exists.');
            }
            $this->programs->update($id, $data);
            audit_log_required(
                'PROGRAM_UPDATE',
                'academic_program',
                $id,
                'Academic program record updated.',
                ['program_code' => $data['program_code'], 'status' => $data['status']]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $this->get($id);
    }
}
