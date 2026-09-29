<?php

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/AcademicProgramRepository.php';
require_once __DIR__ . '/../repositories/CurriculumRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionSubjectRepository.php';
require_once __DIR__ . '/../repositories/GradeWeightingRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';

class GradeWeightingService
{
    private AcademicProgramRepository $programs;
    private CurriculumRepository $curricula;
    private CurriculumVersionRepository $versions;
    private CurriculumVersionSubjectRepository $placements;
    private GradeWeightingRepository $weighting;

    public function __construct(private mysqli $db)
    {
        $this->programs = new AcademicProgramRepository($db);
        $this->curricula = new CurriculumRepository($db);
        $this->versions = new CurriculumVersionRepository($db);
        $this->placements = new CurriculumVersionSubjectRepository($db);
        $this->weighting = new GradeWeightingRepository($db);
    }

    public function programOptions(array $input): array
    {
        $result = $this->programs->paginate(
            DomainValidator::listQuery($input, ['active', 'inactive'])
        );
        return ['programs' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function curriculumOptions(array $input): array
    {
        $result = $this->curricula->paginate(
            DomainValidator::listQuery($input, ['active', 'inactive'], true)
        );
        return ['curricula' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function versionOptions(array $input): array
    {
        $curriculumId = DomainValidator::positiveId(
            $input['curriculum_id'] ?? null,
            'Curriculum'
        );
        if (!$this->curricula->findById($curriculumId)) {
            throw new DomainNotFoundException('Curriculum not found.');
        }
        $result = $this->versions->paginateByCurriculum(
            $curriculumId,
            DomainValidator::listQuery($input, ['draft', 'active', 'archived'])
        );
        return ['versions' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function placementOptions(array $input): array
    {
        $versionId = DomainValidator::positiveId(
            $input['curriculum_version_id'] ?? null,
            'Curriculum version'
        );
        if (!$this->versions->findById($versionId)) {
            throw new DomainNotFoundException('Curriculum version not found.');
        }
        $result = $this->placements->paginateByVersion(
            $versionId,
            DomainValidator::listQuery($input)
        );
        return ['placements' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function getSchemeForPlacement(int $placementId): array
    {
        $placement = $this->placements->findDetailedById($placementId);
        if (!$placement) {
            throw new DomainNotFoundException('Curriculum subject placement not found.');
        }
        $scheme = $this->weighting->findSchemeByPlacement($placementId);
        return [
            'placement' => $placement,
            'scheme' => $scheme ? $this->decorateScheme($scheme) : null,
        ];
    }

    public function listComponents(int $schemeId, array $input): array
    {
        $scheme = $this->weighting->findDetailedSchemeById($schemeId);
        if (!$scheme) {
            throw new DomainNotFoundException('Grade weighting scheme not found.');
        }
        $result = $this->weighting->paginateComponents(
            $schemeId,
            DomainValidator::listQuery($input)
        );
        return [
            'scheme' => $this->decorateScheme($scheme),
            'components' => $result['records'],
            'pagination' => $result['pagination'],
        ];
    }

    public function createScheme(array $input, int $actorId): array
    {
        $data = DomainValidator::gradeWeightingScheme($input);
        $this->db->begin_transaction();
        try {
            if (!$this->placements->findDetailedById($data['curriculum_version_subject_id'])) {
                throw new DomainNotFoundException('Curriculum subject placement not found.');
            }
            if ($this->weighting->placementHasScheme($data['curriculum_version_subject_id'])) {
                throw new DomainConflictException(
                    'A grade weighting scheme already exists for this curriculum subject placement.'
                );
            }
            $id = $this->weighting->createScheme($data, $actorId);
            audit_log_required(
                'GRADE_WEIGHTING_SCHEME_CREATE',
                'grade_weighting_scheme',
                $id,
                'Placement-scoped grade weighting scheme created.',
                ['curriculum_version_subject_id' => $data['curriculum_version_subject_id']]
            );
            $scheme = $this->weighting->findDetailedSchemeById($id);
            if (!$scheme) {
                throw new RuntimeException('Created grade weighting scheme could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            if ($error instanceof mysqli_sql_exception && (int)$error->getCode() === 1062) {
                throw new DomainConflictException(
                    'A grade weighting scheme already exists for this curriculum subject placement.'
                );
            }
            throw $error;
        }
        return $this->decorateScheme($scheme);
    }

    public function updateScheme(int $id, array $input): array
    {
        $data = DomainValidator::gradeWeightingSchemeUpdate($input);
        $this->db->begin_transaction();
        try {
            $existing = $this->weighting->findSchemeById($id, true);
            if (!$existing) {
                throw new DomainNotFoundException('Grade weighting scheme not found.');
            }
            $this->weighting->updateScheme($id, $data['description']);
            audit_log_required(
                'GRADE_WEIGHTING_SCHEME_UPDATE',
                'grade_weighting_scheme',
                $id,
                'Grade weighting scheme description updated.',
                ['curriculum_version_subject_id' => (int)$existing['curriculum_version_subject_id']]
            );
            $scheme = $this->weighting->findDetailedSchemeById($id);
            if (!$scheme) {
                throw new RuntimeException('Updated grade weighting scheme could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $this->decorateScheme($scheme);
    }

    public function removeScheme(int $id): void
    {
        $this->db->begin_transaction();
        try {
            $scheme = $this->weighting->findSchemeById($id, true);
            if (!$scheme) {
                throw new DomainNotFoundException('Grade weighting scheme not found.');
            }
            if ($this->weighting->componentCount($id) > 0) {
                throw new DomainConflictException(
                    'Remove all weighting components before removing this scheme.'
                );
            }
            $this->weighting->deleteScheme($id);
            audit_log_required(
                'GRADE_WEIGHTING_SCHEME_REMOVE',
                'grade_weighting_scheme',
                $id,
                'Empty grade weighting scheme removed.',
                ['curriculum_version_subject_id' => (int)$scheme['curriculum_version_subject_id']]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function createComponent(array $input, int $actorId): array
    {
        $data = DomainValidator::gradeWeightingComponent($input);
        $this->db->begin_transaction();
        try {
            if (!$this->weighting->findSchemeById($data['scheme_id'], true)) {
                throw new DomainNotFoundException('Grade weighting scheme not found.');
            }
            if ($this->weighting->componentNameExists(
                $data['component_name'],
                $data['scheme_id']
            )) {
                throw new DomainConflictException(
                    'A component with this name already exists in this weighting scheme.'
                );
            }
            $this->assertProjectedTotal(
                $this->weighting->sumComponentWeights($data['scheme_id']),
                $data['weight_percentage']
            );
            $id = $this->weighting->createComponent($data, $actorId);
            audit_log_required(
                'GRADE_WEIGHTING_COMPONENT_CREATE',
                'grade_weighting_component',
                $id,
                'Grade weighting component created.',
                [
                    'scheme_id' => $data['scheme_id'],
                    'component_name' => $data['component_name'],
                    'weight_percentage' => $data['weight_percentage'],
                ]
            );
            $component = $this->weighting->findComponentById($id);
            $scheme = $this->weighting->findDetailedSchemeById($data['scheme_id']);
            if (!$component || !$scheme) {
                throw new RuntimeException('Created grade weighting component could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            if ($error instanceof mysqli_sql_exception && (int)$error->getCode() === 1062) {
                throw new DomainConflictException(
                    'A component with this name already exists in this weighting scheme.'
                );
            }
            throw $error;
        }
        return ['component' => $component, 'scheme' => $this->decorateScheme($scheme)];
    }

    public function updateComponent(int $id, array $input): array
    {
        $data = DomainValidator::gradeWeightingComponentFields($input);
        $this->db->begin_transaction();
        try {
            $component = $this->weighting->findComponentById($id, true);
            if (!$component) {
                throw new DomainNotFoundException('Grade weighting component not found.');
            }
            $schemeId = (int)$component['scheme_id'];
            if (!$this->weighting->findSchemeById($schemeId, true)) {
                throw new DomainNotFoundException('Grade weighting scheme not found.');
            }
            if ($this->weighting->componentNameExists(
                $data['component_name'],
                $schemeId,
                $id
            )) {
                throw new DomainConflictException(
                    'A component with this name already exists in this weighting scheme.'
                );
            }
            $this->assertProjectedTotal(
                $this->weighting->sumComponentWeights($schemeId, $id),
                $data['weight_percentage']
            );
            $this->weighting->updateComponent($id, $data);
            audit_log_required(
                'GRADE_WEIGHTING_COMPONENT_UPDATE',
                'grade_weighting_component',
                $id,
                'Grade weighting component updated.',
                [
                    'scheme_id' => $schemeId,
                    'component_name' => $data['component_name'],
                    'weight_percentage' => $data['weight_percentage'],
                ]
            );
            $updated = $this->weighting->findComponentById($id);
            $scheme = $this->weighting->findDetailedSchemeById($schemeId);
            if (!$updated || !$scheme) {
                throw new RuntimeException('Updated grade weighting component could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            if ($error instanceof mysqli_sql_exception && (int)$error->getCode() === 1062) {
                throw new DomainConflictException(
                    'A component with this name already exists in this weighting scheme.'
                );
            }
            throw $error;
        }
        return ['component' => $updated, 'scheme' => $this->decorateScheme($scheme)];
    }

    public function removeComponent(int $id): array
    {
        $this->db->begin_transaction();
        try {
            $component = $this->weighting->findComponentById($id, true);
            if (!$component) {
                throw new DomainNotFoundException('Grade weighting component not found.');
            }
            $schemeId = (int)$component['scheme_id'];
            if (!$this->weighting->findSchemeById($schemeId, true)) {
                throw new DomainNotFoundException('Grade weighting scheme not found.');
            }
            $this->weighting->deleteComponent($id);
            audit_log_required(
                'GRADE_WEIGHTING_COMPONENT_REMOVE',
                'grade_weighting_component',
                $id,
                'Grade weighting component removed.',
                [
                    'scheme_id' => $schemeId,
                    'component_name' => (string)$component['component_name'],
                    'weight_percentage' => (string)$component['weight_percentage'],
                ]
            );
            $scheme = $this->weighting->findDetailedSchemeById($schemeId);
            if (!$scheme) {
                throw new RuntimeException('Grade weighting scheme could not be reloaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $this->decorateScheme($scheme);
    }

    private function assertProjectedTotal(string $existingTotal, string $newWeight): void
    {
        if ($this->decimalToHundredths($existingTotal)
            + $this->decimalToHundredths($newWeight) > 10000) {
            throw new DomainValidationException(
                'Total component weight cannot exceed 100%.'
            );
        }
    }

    private function decorateScheme(array $scheme): array
    {
        $total = $this->decimalToHundredths((string)$scheme['total_weight']);
        $scheme['component_count'] = (int)$scheme['component_count'];
        $scheme['total_weight'] = $this->formatHundredths($total);
        $scheme['remaining_weight'] = $this->formatHundredths(max(0, 10000 - $total));
        $scheme['completion_status'] = $total === 10000 ? 'complete' : 'incomplete';
        return $scheme;
    }

    private function decimalToHundredths(string $value): int
    {
        $parts = explode('.', $value, 2);
        return ((int)$parts[0] * 100) + (int)str_pad($parts[1] ?? '', 2, '0');
    }

    private function formatHundredths(int $value): string
    {
        return intdiv($value, 100) . '.' . str_pad(
            (string)($value % 100),
            2,
            '0',
            STR_PAD_LEFT
        );
    }
}
