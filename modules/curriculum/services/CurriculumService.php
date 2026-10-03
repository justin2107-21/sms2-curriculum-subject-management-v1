<?php

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/AcademicProgramRepository.php';
require_once __DIR__ . '/../repositories/CurriculumRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';

class CurriculumService
{
    private AcademicProgramRepository $programs;
    private CurriculumRepository $curricula;

    public function __construct(private mysqli $db)
    {
        $this->programs = new AcademicProgramRepository($db);
        $this->curricula = new CurriculumRepository($db);
    }

    public function get(int $id): array
    {
        $curriculum = $this->curricula->findDetailById($id);
        if (!$curriculum) {
            throw new DomainNotFoundException('Curriculum not found.');
        }
        return $curriculum;
    }

    public function list(array $input): array
    {
        $query = DomainValidator::listQuery($input, ['active', 'inactive'], true);
        $result = $this->curricula->paginate($query);
        return ['curricula' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function create(array $input, int $createdBy): array
    {
        $data = DomainValidator::curriculum($input);
        $this->db->begin_transaction();
        try {
            if (!$this->programs->findById($data['academic_program_id'])) {
                throw new DomainNotFoundException('Academic program not found.');
            }
            if ($this->curricula->codeExists($data['curriculum_code'])) {
                throw new DomainConflictException('Curriculum code already exists.');
            }
            $id = $this->curricula->create($data, $createdBy);
            audit_log_required(
                'CURRICULUM_CREATE',
                'curriculum',
                $id,
                'Curriculum master record created.',
                [
                    'curriculum_code' => $data['curriculum_code'],
                    'academic_program_id' => $data['academic_program_id'],
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

    public function update(int $id, array $input): array
    {
        $data = DomainValidator::curriculum($input);
        $this->db->begin_transaction();
        try {
            $existing = $this->curricula->findById($id);
            if (!$existing) {
                throw new DomainNotFoundException('Curriculum not found.');
            }
            if ($existing['archived_at'] !== null) throw new DomainConflictException('Restore this curriculum from Settings > Archives before editing.');
            if (!$this->programs->findById($data['academic_program_id'])) {
                throw new DomainNotFoundException('Academic program not found.');
            }
            if ($this->curricula->codeExists($data['curriculum_code'], $id)) {
                throw new DomainConflictException('Curriculum code already exists.');
            }
            $this->curricula->update($id, $data);
            audit_log_required(
                'CURRICULUM_UPDATE',
                'curriculum',
                $id,
                'Curriculum master record updated.',
                [
                    'curriculum_code' => $data['curriculum_code'],
                    'academic_program_id' => $data['academic_program_id'],
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
}
