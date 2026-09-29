<?php

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

    public function list(int $curriculumId, array $input): array
    {
        if (!$this->curricula->findById($curriculumId)) {
            throw new DomainNotFoundException('Curriculum not found.');
        }
        $query = DomainValidator::listQuery($input, ['draft', 'active', 'archived']);
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
}
