<?php

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/AcademicProgramRepository.php';
require_once __DIR__ . '/../repositories/CurriculumRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionRepository.php';
require_once __DIR__ . '/../repositories/AcademicProgramAssignmentRepository.php';
require_once __DIR__ . '/CurriculumVersionSubjectService.php';

class AcademicProgramAssignmentService
{
    private AcademicProgramRepository $programs;
    private CurriculumRepository $curricula;
    private CurriculumVersionRepository $versions;
    private AcademicProgramAssignmentRepository $assignments;
    private CurriculumVersionSubjectService $placementService;

    public function __construct(private mysqli $db)
    {
        $this->programs = new AcademicProgramRepository($db);
        $this->curricula = new CurriculumRepository($db);
        $this->versions = new CurriculumVersionRepository($db);
        $this->assignments = new AcademicProgramAssignmentRepository($db);
        $this->placementService = new CurriculumVersionSubjectService($db);
    }

    public function listAssignments(array $input): array
    {
        $view = is_scalar($input['view'] ?? null) ? trim((string)$input['view']) : '';
        if (!in_array($view, ['program', 'subject'], true)) {
            throw new DomainValidationException('View must be program or subject.');
        }

        $query = DomainValidator::academicProgramAssignmentQuery($input);
        $result = $this->assignments->paginate($query, $view);
        return [
            'assignments' => $result['records'],
            'pagination' => $result['pagination'],
            'filter_values' => $this->assignments->filterValues($query),
        ];
    }

    public function listPrograms(array $input): array
    {
        $query = DomainValidator::listQuery($input, ['active', 'inactive']);
        $result = $this->programs->paginate($query);
        return ['programs' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function listCurricula(int $programId, array $input): array
    {
        if (!$this->programs->findById($programId)) {
            throw new DomainNotFoundException('Academic program not found.');
        }
        $query = DomainValidator::listQuery($input, ['active', 'inactive'], true);
        $query['academic_program_id'] = $programId;
        $result = $this->curricula->paginate($query);
        return ['curricula' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function listVersions(int $curriculumId, array $input): array
    {
        if (!$this->curricula->findById($curriculumId)) {
            throw new DomainNotFoundException('Curriculum not found.');
        }
        $query = DomainValidator::listQuery($input, ['draft', 'active', 'archived']);
        $result = $this->versions->paginateByCurriculum($curriculumId, $query);
        return ['versions' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function availableSubjects(int $versionId, array $input): array
    {
        if (!$this->versions->findById($versionId)) {
            throw new DomainNotFoundException('Curriculum version not found.');
        }
        $query = DomainValidator::listQuery($input);
        $result = $this->assignments->paginateAvailableSubjects($versionId, $query);
        return ['subjects' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function assign(array $input, int $createdBy): array
    {
        return $this->placementService->assign($input, $createdBy);
    }

    public function update(int $id, array $input): array
    {
        return $this->placementService->updatePlacement($id, $input);
    }

    public function remove(int $id): void
    {
        $this->placementService->remove($id);
    }
}
