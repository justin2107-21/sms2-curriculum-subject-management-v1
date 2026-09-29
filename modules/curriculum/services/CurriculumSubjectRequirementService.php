<?php

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/CurriculumVersionSubjectRepository.php';
require_once __DIR__ . '/../repositories/CurriculumSubjectRequirementRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';

class CurriculumSubjectRequirementService
{
    private CurriculumVersionSubjectRepository $placements;
    private CurriculumSubjectRequirementRepository $requirements;

    public function __construct(private mysqli $db)
    {
        $this->placements = new CurriculumVersionSubjectRepository($db);
        $this->requirements = new CurriculumSubjectRequirementRepository($db);
    }

    public function list(array $input): array
    {
        $query = DomainValidator::subjectRequirementQuery($input);
        $result = $this->requirements->paginate($query);
        return [
            'relationships' => $result['records'],
            'pagination' => $result['pagination'],
        ];
    }

    public function placementOptions(int $versionId, array $input): array
    {
        $query = DomainValidator::listQuery($input);
        if (!$this->requirements->versionExists($versionId)) {
            throw new DomainNotFoundException('Curriculum version not found.');
        }
        $result = $this->requirements->paginatePlacementOptions($versionId, $query);
        return [
            'placements' => $result['records'],
            'pagination' => $result['pagination'],
        ];
    }

    public function create(array $input, int $createdBy): array
    {
        $data = DomainValidator::subjectRequirement($input);
        if ($data['subject_id'] === $data['required_subject_id']) {
            throw new DomainValidationException('A subject cannot require itself.');
        }

        $this->db->begin_transaction();
        try {
            if (!$this->requirements->lockVersion($data['curriculum_version_id'])) {
                throw new DomainNotFoundException('Curriculum version not found.');
            }
            if (!$this->placements->assignmentExists(
                $data['curriculum_version_id'],
                $data['subject_id']
            )) {
                throw new DomainValidationException(
                    'The selected subject is not mapped to this curriculum version.'
                );
            }
            if (!$this->placements->assignmentExists(
                $data['curriculum_version_id'],
                $data['required_subject_id']
            )) {
                throw new DomainValidationException(
                    'The selected required subject is not mapped to this curriculum version.'
                );
            }
            if ($this->requirements->relationshipExists(
                $data['curriculum_version_id'],
                $data['subject_id'],
                $data['required_subject_id'],
                $data['requirement_type']
            )) {
                throw new DomainConflictException(
                    'This subject requirement already exists for this curriculum version.'
                );
            }
            if ($data['requirement_type'] === 'prerequisite'
                && $this->wouldCreatePrerequisiteCycle(
                    $this->requirements->prerequisiteEdges($data['curriculum_version_id']),
                    $data['subject_id'],
                    $data['required_subject_id']
                )) {
                throw new DomainValidationException(
                    'This prerequisite would create a circular dependency.'
                );
            }

            $id = $this->requirements->create($data, $createdBy);
            audit_log_required(
                'SUBJECT_REQUIREMENT_CREATE',
                'curriculum_subject_requirement',
                $id,
                'Curriculum subject requirement created.',
                [
                    'curriculum_version_id' => $data['curriculum_version_id'],
                    'subject_id' => $data['subject_id'],
                    'required_subject_id' => $data['required_subject_id'],
                    'relationship_type' => $data['requirement_type'],
                ]
            );
            $relationship = $this->requirements->findById($id);
            if (!$relationship) {
                throw new RuntimeException('Created subject requirement could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            if ($error instanceof mysqli_sql_exception && (int)$error->getCode() === 1062) {
                throw new DomainConflictException(
                    'This subject requirement already exists for this curriculum version.'
                );
            }
            throw $error;
        }

        return $relationship;
    }

    public function remove(int $id): void
    {
        $this->db->begin_transaction();
        try {
            $relationship = $this->requirements->findById($id);
            if (!$relationship) {
                throw new DomainNotFoundException('Subject requirement not found.');
            }
            if (!$this->requirements->delete($id)) {
                throw new DomainNotFoundException('Subject requirement not found.');
            }
            audit_log_required(
                'SUBJECT_REQUIREMENT_REMOVE',
                'curriculum_subject_requirement',
                $id,
                'Curriculum subject requirement removed.',
                [
                    'curriculum_version_id' => (int)$relationship['curriculum_version_id'],
                    'subject_id' => (int)$relationship['subject_id'],
                    'required_subject_id' => (int)$relationship['required_subject_id'],
                    'relationship_type' => $relationship['requirement_type'],
                ]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private function wouldCreatePrerequisiteCycle(
        array $edges,
        int $subjectId,
        int $requiredSubjectId
    ): bool {
        $graph = [];
        foreach ($edges as $edge) {
            $from = (int)$edge['subject_id'];
            $to = (int)$edge['required_subject_id'];
            $graph[$from][] = $to;
        }

        // A new edge subject -> required is cyclic exactly when required can
        // already reach subject through prerequisite edges in this version.
        $pending = [$requiredSubjectId];
        $visited = [];
        while ($pending !== []) {
            $node = array_pop($pending);
            if ($node === $subjectId) {
                return true;
            }
            if (isset($visited[$node])) {
                continue;
            }
            $visited[$node] = true;
            foreach ($graph[$node] ?? [] as $next) {
                if (!isset($visited[$next])) {
                    $pending[] = $next;
                }
            }
        }
        return false;
    }
}
