<?php

require_once __DIR__ . '/CurriculumVersionEditPolicy.php';

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/AcademicSequenceValidator.php';
require_once __DIR__ . '/../repositories/SubjectRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionRepository.php';
require_once __DIR__ . '/../repositories/CurriculumRepository.php';
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
        $query['subject_id'] = DomainValidator::optionalPositiveId($input['subject_id'] ?? null, 'Subject');
        $result = $this->assignments->paginateMappings($query);
        return [
            'mappings' => $result['records'],
            'pagination' => $result['pagination'],
            'filter_values' => $this->assignments->mappingFilterValues($query),
        ];
    }

    public function placementSubjectOptions(array $input): array
    {
        $version = DomainValidator::positiveId($input['curriculum_version_id'] ?? null, 'Curriculum version');
        $this->validatePlacementContext($version, $input);
        $query = DomainValidator::listQuery($input, ['active', 'inactive']);
        $query['_exclude_placement_version'] = $version;
        $result = $this->subjects->paginate($query);
        return ['subjects' => $result['records'], 'pagination' => $result['pagination']];
    }

    private function validatePlacementContext(int $versionId, array $input): void
    {
        $programId = DomainValidator::positiveId($input['academic_program_id'] ?? null, 'Academic program');
        $curriculumId = DomainValidator::positiveId($input['curriculum_id'] ?? null, 'Curriculum');
        $version = $this->versions->findById($versionId);
        $curriculum = (new CurriculumRepository($this->db))->findById($curriculumId);
        if (!$version || !$curriculum || (int)$version['curriculum_id'] !== $curriculumId || (int)$curriculum['academic_program_id'] !== $programId) {
            throw new DomainValidationException('Select a matching Program, Curriculum, and Curriculum Version.');
        }
    }

    public function assign(array $input, int $createdBy, bool $requireContext = false): array
    {
        $data = DomainValidator::curriculumVersionSubject($input);
        $this->db->begin_transaction();
        try {
            $policy = new CurriculumVersionEditPolicy($this->db);
            $policy->lockSubject($data['subject_id']);
            $policy->assertEditable($data['curriculum_version_id']);
            if (!$this->requirements->lockVersion($data['curriculum_version_id'])) {
                throw new DomainNotFoundException('Curriculum version not found.');
            }
            if ($requireContext) $this->validatePlacementContext($data['curriculum_version_id'], $input);
            if (!$this->subjects->findById($data['subject_id'])) {
                throw new DomainNotFoundException('Subject not found.');
            }
            if ($this->assignments->assignmentExists(
                $data['curriculum_version_id'],
                $data['subject_id'],
                true
            )) {
                throw new DomainConflictException('This subject is already placed in this curriculum version. Use Edit Placement to change its placement.');
            }
            $validation = $this->placementSequence($data, true);
            if (!$validation['valid']) throw new AcademicSequenceException($validation);
            $id = $this->assignments->create($data, $createdBy);
            audit_log_required(
                'CURRICULUM_SUBJECT_ASSIGN',
                'curriculum_version_subject',
                $id,
                'Subject assigned to curriculum version.',
                [
                    'event' => 'placement.created',
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

    public function updatePlacement(int $id, array $input, bool $placementOnly = false): array
    {
        $data = DomainValidator::curriculumVersionSubjectPlacement($input);
        $this->db->begin_transaction();
        try {
            $version = (int)(new CurriculumVersionEditPolicy($this->db))->assertChildEditable('placement', $id)['id'];
            if (!$this->requirements->lockVersion($version)) throw new DomainNotFoundException('Subject mapping not found.');
            $assignment = $this->assignments->findById($id, true);
            if (!$assignment) {
                throw new DomainNotFoundException('Subject mapping not found.');
            }
            if ((int)$assignment['curriculum_version_id'] !== $version) throw new DomainConflictException('Academic context changed. Reload before editing.');
            $validation = $this->placementSequence(array_replace($assignment, $data), true);
            if (!$validation['valid']) throw new AcademicSequenceException($validation);
            if ($placementOnly) {
                $data['sequence_order'] = $assignment['sequence_order'];
            }
            $this->assignments->updatePlacement($id, $data);
            audit_log_required(
                'CURRICULUM_SUBJECT_UPDATE',
                'curriculum_version_subject',
                $id,
                'Curriculum subject placement details updated.',
                [
                    'event' => 'placement.updated',
                    'curriculum_version_id' => (int)$assignment['curriculum_version_id'],
                    'subject_id' => (int)$assignment['subject_id'],
                    'year_level' => $data['year_level'],
                    'previous_year_level' => $assignment['year_level'],
                    'previous_semester' => $assignment['semester'],
                    'affected_relationships' => $validation['relationships'],
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

    public function previewPlacement(int $id, array $input): array
    {
        $assignment = $this->assignments->findById($id);
        if (!$assignment) throw new DomainNotFoundException('Subject mapping not found.');
        $data = DomainValidator::curriculumVersionSubjectPlacement($input);
        return $this->placementSequence(array_replace($assignment, $data));
    }

    private function placementSequence(array $proposed, bool $forUpdate = false): array
    {
        $version = (int)$proposed['curriculum_version_id'];
        $placements = array_values(array_filter($this->requirements->allPlacements($version, $forUpdate), fn($p) => (int)$p['subject_id'] !== (int)$proposed['subject_id']));
        $subject = $this->subjects->findById((int)$proposed['subject_id']);
        $placements[] = array_replace($subject ?? [], $proposed);
        return AcademicSequenceValidator::validate($placements, $this->requirements->allPrerequisites($version, $forUpdate), (int)$proposed['subject_id']) + ['curriculum_version_id' => $version];
    }

    public function remove(int $id): void
    {
        $this->db->begin_transaction();
        try {
            $version = (int)(new CurriculumVersionEditPolicy($this->db))->assertChildEditable('placement', $id)['id'];
            if (!$this->requirements->lockVersion($version)) throw new DomainNotFoundException('Subject mapping not found.');
            $assignment = $this->assignments->findById($id, true);
            if (!$assignment) {
                throw new DomainNotFoundException('Curriculum subject assignment not found.');
            }
            if ((int)$assignment['curriculum_version_id'] !== $version) throw new DomainConflictException('Academic context changed. Reload before editing.');
            if ($this->requirements->placementHasRelationships(
                (int)$assignment['curriculum_version_id'],
                (int)$assignment['subject_id'],
                true
            )) {
                throw new DomainConflictException(
                    'Remove this subject\'s prerequisite or corequisite relationships before removing its curriculum placement.'
                );
            }
            if ($this->electiveMemberships->placementHasMemberships(
                (int)$assignment['curriculum_version_id'],
                (int)$assignment['subject_id'],
                true
            )) {
                throw new DomainConflictException(
                    'Remove this subject from all elective groups before removing its curriculum placement.'
                );
            }
            if ($this->gradeWeighting->placementHasScheme($id, true)) {
                throw new DomainConflictException(
                    'Remove the grade weighting configuration before removing this curriculum placement.'
                );
            }
            if ($this->assignments->placementHasOutcomeMappings($id, true)) {
                throw new DomainConflictException(
                    'Remove recorded graduate outcome mappings before removing this curriculum placement.'
                );
            }
            if ($this->assignments->placementHasCloEvidence($id, true)) {
                throw new DomainConflictException(
                    'This placement has current or withdrawn CLO and assessment history. Preserve that evidence before removing the placement.'
                );
            }
            (new CurriculumVersionEditPolicy($this->db))->preparePlacementRemoval($id);
            $this->assignments->delete($id);
            audit_log_required(
                'CURRICULUM_SUBJECT_REMOVE',
                'curriculum_version_subject',
                $id,
                'Subject removed from curriculum version.',
                [
                    'event' => 'placement.removed',
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
