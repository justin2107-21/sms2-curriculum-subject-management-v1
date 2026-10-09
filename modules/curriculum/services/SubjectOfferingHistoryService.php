<?php

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/AcademicProgramRepository.php';
require_once __DIR__ . '/../repositories/CurriculumRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionRepository.php';
require_once __DIR__ . '/../repositories/SubjectRepository.php';
require_once __DIR__ . '/../repositories/SubjectOfferingHistoryRepository.php';

class SubjectOfferingHistoryService
{
    private AcademicProgramRepository $programs;
    private CurriculumRepository $curricula;
    private CurriculumVersionRepository $versions;
    private SubjectOfferingHistoryRepository $history;
    private SubjectRepository $subjects;

    public function __construct(private mysqli $db)
    {
        $this->programs = new AcademicProgramRepository($db);
        $this->curricula = new CurriculumRepository($db);
        $this->versions = new CurriculumVersionRepository($db);
        $this->history = new SubjectOfferingHistoryRepository($db);
        $this->subjects = new SubjectRepository($db);
    }

    public function listHistory(array $input): array
    {
        $query = DomainValidator::subjectHistoryQuery($input);
        $result = $this->history->paginate($query);
        return [
            'history' => $result['records'],
            'pagination' => $result['pagination'],
            'summary' => $this->integerSummary($result['summary']),
            'filter_values' => $this->history->filterValues($query),
        ];
    }

    public function listSubjects(array $input): array
    {
        $query = DomainValidator::listQuery($input, ['active', 'inactive']);
        $result = $this->subjects->paginate($query);
        return ['subjects' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function historyDetails(int $placementId): array
    {
        $record = $this->history->findPlacement($placementId);
        if (!$record) throw new DomainNotFoundException('Stored subject placement not found.');
        return ['record' => $record];
    }

    public function listPrograms(array $input): array
    {
        $query = DomainValidator::listQuery($input, ['active', 'inactive']);
        $result = $this->programs->paginate($query);
        return ['programs' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function listCurricula(?int $programId, array $input): array
    {
        if ($programId !== null && !$this->programs->findById($programId)) {
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

    public function compareVersions(array $input): array
    {
        $query = DomainValidator::subjectHistoryComparisonQuery($input);
        $curriculum = $this->curricula->findById($query['curriculum_id']);
        if (!$curriculum) {
            throw new DomainNotFoundException('Curriculum not found.');
        }
        $versionA = $this->versions->findById($query['version_a_id']);
        $versionB = $this->versions->findById($query['version_b_id']);
        if (!$versionA || !$versionB) {
            throw new DomainNotFoundException('One or both curriculum versions were not found.');
        }
        if ($query['version_a_id'] === $query['version_b_id']) {
            throw new DomainValidationException('Select two different curriculum versions.');
        }
        if ((int)$versionA['curriculum_id'] !== $query['curriculum_id']
            || (int)$versionB['curriculum_id'] !== $query['curriculum_id']) {
            throw new DomainValidationException(
                'Both curriculum versions must belong to the selected curriculum.'
            );
        }

        $result = $this->history->paginateComparison(
            $query['version_a_id'],
            $query['version_b_id'],
            $query
        );
        return [
            'curriculum' => [
                'id' => (int)$curriculum['id'],
                'curriculum_code' => $curriculum['curriculum_code'],
                'curriculum_name' => $curriculum['curriculum_name'],
            ],
            'version_a' => $this->versionContext($versionA),
            'version_b' => $this->versionContext($versionB),
            'comparison' => $result['records'],
            'pagination' => $result['pagination'],
            'summary' => $this->integerSummary($result['summary']),
        ];
    }

    private function versionContext(array $version): array
    {
        return [
            'id' => (int)$version['id'],
            'version_label' => $version['version_label'],
            'effective_academic_year' => $version['effective_academic_year'],
            'status' => $version['status'],
            'created_at' => $version['created_at'],
            'updated_at' => $version['updated_at'],
        ];
    }

    private function integerSummary(array $summary): array
    {
        foreach (['stored_placements', 'programs', 'curricula', 'curriculum_versions',
                  'added', 'removed', 'moved', 'placement_type_changed', 'changed', 'unchanged',
                  'version_a_subjects', 'version_b_subjects', 'version_a_captured_subjects',
                  'version_b_captured_subjects'] as $key) {
            if (array_key_exists($key, $summary)) {
                $summary[$key] = (int)$summary[$key];
            }
        }
        foreach (['version_a_units', 'version_b_units'] as $key) {
            if (array_key_exists($key, $summary) && $summary[$key] !== null) $summary[$key] = (float)$summary[$key];
        }
        return $summary;
    }
}
