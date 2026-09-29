<?php

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/SubjectRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionSubjectRepository.php';
require_once __DIR__ . '/../repositories/CurriculumSubjectRequirementRepository.php';
require_once __DIR__ . '/../repositories/CurriculumElectiveGroupSubjectRepository.php';
require_once __DIR__ . '/../repositories/GradeWeightingRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';

class CurriculumVersionSubjectService
{
    private SubjectRepository $subjects;
    private CurriculumVersionRepository $versions;
    private CurriculumVersionSubjectRepository $assignments;
    private CurriculumSubjectRequirementRepository $requirements;
    private CurriculumElectiveGroupSubjectRepository $electiveMemberships;
    private GradeWeightingRepository $gradeWeighting;

    public function __construct(private mysqli $db)
    {
        $this->subjects = new SubjectRepository($db);
        $this->versions = new CurriculumVersionRepository($db);
        $this->assignments = new CurriculumVersionSubjectRepository($db);
        $this->requirements = new CurriculumSubjectRequirementRepository($db);
        $this->electiveMemberships = new CurriculumElectiveGroupSubjectRepository($db);
        $this->gradeWeighting = new GradeWeightingRepository($db);
    }

    public function list(int $versionId, array $input = []): array
    {
        if (!$this->versions->findById($versionId)) {
            throw new DomainNotFoundException('Curriculum version not found.');
        }
        $query = DomainValidator::listQuery($input);
        $result = $this->assignments->paginateByVersion($versionId, $query);
        return ['assignments' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function listMappings(array $input = []): array
    {
        $query = DomainValidator::subjectMappingQuery($input);
        $result = $this->assignments->paginateMappings($query);
        return [
            'mappings' => $result['records'],
            'pagination' => $result['pagination'],
            'filter_values' => $this->assignments->mappingFilterValues($query),
        ];
    }

    public function assign(array $input, int $createdBy): array
    {
        $data = DomainValidator::curriculumVersionSubject($input);
        $this->db->begin_transaction();
        try {
            if (!$this->versions->findById($data['curriculum_version_id'])) {
                throw new DomainNotFoundException('Curriculum version not found.');
            }
            if (!$this->subjects->findById($data['subject_id'])) {
                throw new DomainNotFoundException('Subject not found.');
            }
            if ($this->assignments->assignmentExists(
                $data['curriculum_version_id'],
                $data['subject_id']
            )) {
                throw new DomainConflictException('This subject is already mapped to this curriculum version.');
            }
            $id = $this->assignments->create($data, $createdBy);
            audit_log_required(
                'CURRICULUM_SUBJECT_ASSIGN',
                'curriculum_version_subject',
                $id,
                'Subject assigned to curriculum version.',
                [
                    'curriculum_version_id' => $data['curriculum_version_id'],
                    'subject_id' => $data['subject_id'],
                    'is_required' => $data['is_required'],
                ]
            );
            $assignment = $this->assignments->findById($id);
            if (!$assignment) {
                throw new RuntimeException('Created assignment could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $assignment;
    }

    public function updatePlacement(int $id, array $input): array
    {
        $data = DomainValidator::curriculumVersionSubjectPlacement($input);
        $this->db->begin_transaction();
        try {
            $assignment = $this->assignments->findById($id);
            if (!$assignment) {
                throw new DomainNotFoundException('Subject mapping not found.');
            }
            $this->assignments->updatePlacement($id, $data);
            audit_log_required(
                'CURRICULUM_SUBJECT_UPDATE',
                'curriculum_version_subject',
                $id,
                'Curriculum subject placement details updated.',
                [
                    'curriculum_version_id' => (int)$assignment['curriculum_version_id'],
                    'subject_id' => (int)$assignment['subject_id'],
                    'year_level' => $data['year_level'],
                    'semester' => $data['semester'],
                    'sequence_order' => $data['sequence_order'],
                    'is_required' => $data['is_required'],
                ]
            );
            $updated = $this->assignments->findDetailedById($id);
            if (!$updated) {
                throw new RuntimeException('Updated subject mapping could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $updated;
    }

    public function remove(int $id): void
    {
        $this->db->begin_transaction();
        try {
            $assignment = $this->assignments->findById($id);
            if (!$assignment) {
                throw new DomainNotFoundException('Curriculum subject assignment not found.');
            }
            if ($this->requirements->placementHasRelationships(
                (int)$assignment['curriculum_version_id'],
                (int)$assignment['subject_id']
            )) {
                throw new DomainConflictException(
                    'Remove this subject\'s prerequisite or corequisite relationships before removing its curriculum placement.'
                );
            }
            if ($this->electiveMemberships->placementHasMemberships(
                (int)$assignment['curriculum_version_id'],
                (int)$assignment['subject_id']
            )) {
                throw new DomainConflictException(
                    'Remove this subject from all elective groups before removing its curriculum placement.'
                );
            }
            if ($this->gradeWeighting->placementHasScheme($id)) {
                throw new DomainConflictException(
                    'Remove the grade weighting configuration before removing this curriculum placement.'
                );
            }
            $this->assignments->delete($id);
            audit_log_required(
                'CURRICULUM_SUBJECT_REMOVE',
                'curriculum_version_subject',
                $id,
                'Subject removed from curriculum version.',
                [
                    'curriculum_version_id' => (int)$assignment['curriculum_version_id'],
                    'subject_id' => (int)$assignment['subject_id'],
                ]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }
}
