<?php

require_once __DIR__ . '/CurriculumVersionEditPolicy.php';

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/PrerequisiteGraph.php';
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
            throw new DomainValidationException('A subject cannot be its own prerequisite.');
        }

        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertEditable($data['curriculum_version_id']);
            if (!$this->requirements->lockVersion($data['curriculum_version_id'])) {
                throw new DomainNotFoundException('Curriculum version not found.');
            }
            if (!$this->placements->assignmentExists(
                $data['curriculum_version_id'],
                $data['subject_id'],
                true
            )) {
                throw new DomainValidationException(
                    'The selected subject is not mapped to this curriculum version.'
                );
            }
            if (!$this->placements->assignmentExists(
                $data['curriculum_version_id'],
                $data['required_subject_id'],
                true
            )) {
                throw new DomainValidationException(
                    'The selected required subject is not mapped to this curriculum version.'
                );
            }
            if ($this->requirements->relationshipExists(
                $data['curriculum_version_id'],
                $data['subject_id'],
                $data['required_subject_id'],
                $data['requirement_type'],
                true
            )) {
                throw new DomainConflictException(
                    'This prerequisite relationship already exists.'
                );
            }
            if ($data['requirement_type'] === 'prerequisite'
                && $this->wouldCreatePrerequisiteCycle(
                    $this->requirements->prerequisiteEdges($data['curriculum_version_id'], true),
                    $data['subject_id'],
                    $data['required_subject_id']
                )) {
                throw new DomainValidationException(
                    'This relationship would create a circular prerequisite chain.'
                );
            }

            if ($data['requirement_type'] === 'prerequisite') {
                $this->validatePlacements($data['curriculum_version_id'], $data['subject_id'], [$data['required_subject_id']], $input, true);
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
                    'event' => $data['requirement_type'] . '.created',
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
                    'This prerequisite relationship already exists.'
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
            (new CurriculumVersionEditPolicy($this->db))->assertChildEditable('requirement', $id);
            $relationship = $this->requirements->findById($id);
            if (!$relationship) {
                throw new DomainNotFoundException('Subject requirement not found.');
            }
            $this->requirements->lockVersion((int)$relationship['curriculum_version_id']);
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
                    'event' => $relationship['requirement_type'] . '.removed',
                ]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function workspace(int $versionId, bool $forUpdate = false): array
    {
        if (!$this->requirements->versionExists($versionId)) throw new DomainNotFoundException('Curriculum version not found.');
        $placements = $this->requirements->allPlacements($versionId, $forUpdate);
        $edges = $this->requirements->allPrerequisites($versionId, $forUpdate);
        return ['placements' => $placements, 'relationships' => $edges,
            'review' => PrerequisiteGraph::review($placements, $edges)];
    }

    public function previewSequence(int $versionId, int $target, array $required): array
    {
        if (!$this->requirements->versionExists($versionId)) throw new DomainNotFoundException('Curriculum version not found.');
        return AcademicSequenceValidator::validate($this->requirements->allPlacements($versionId),
            array_map(fn($id) => ['subject_id' => $target, 'required_subject_id' => DomainValidator::positiveId($id, 'Required subject')], $required));
    }

    private function validatePlacements(int $version, int $target, array $required, array $input, bool $forUpdate = false): void
    {
        $map = [];
        foreach ($this->requirements->allPlacements($version, $forUpdate) as $p) $map[(int)$p['subject_id']] = $p;
        foreach (array_merge([$target], $required) as $id) {
            if (!isset($map[$id])) throw new DomainValidationException('Only subjects mapped to this curriculum version are allowed.');
        }
        $result = AcademicSequenceValidator::validate(array_values($map), array_map(fn($id) => ['subject_id' => $target, 'required_subject_id' => $id], $required));
        $result['curriculum_version_id'] = $version;
        if (!$result['valid']) throw new AcademicSequenceException($result);
    }

    /** Atomically replace a target rule, preserving unchanged IDs and corequisites. */
    public function updateRule(array $input, int $actor): array
    {
        $version = DomainValidator::positiveId($input['curriculum_version_id'] ?? null, 'Curriculum version');
        $target = DomainValidator::positiveId($input['subject_id'] ?? null, 'Target subject');
        $values = $input['required_subject_ids'] ?? [];
        if (!is_array($values) || !is_array($input['expected_ids'] ?? [])) throw new DomainValidationException('Required subjects must be a list.');
        $ids = array_map(fn($id) => DomainValidator::positiveId($id, 'Required subject'), $values);
        if (in_array($target, $ids, true)) throw new DomainValidationException('A subject cannot be its own prerequisite.');
        if (count(array_unique($ids)) !== count($ids)) throw new DomainConflictException('This prerequisite relationship already exists.');
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertEditable($version);
            if (!$this->requirements->lockVersion($version)) throw new DomainNotFoundException('Curriculum version not found.');
            $old = $this->requirements->allPrerequisites($version, true);
            $current = array_values(array_filter($old, fn($e) => (int)$e['subject_id'] === $target));
            $actual = array_map(fn($e) => (int)$e['id'], $current); sort($actual);
            $expected = array_map('intval', $input['expected_ids'] ?? []); sort($expected);
            if ($actual !== $expected) throw new DomainConflictException('This rule changed. Close this dialog and reload before editing.');
            $others = array_values(array_filter($old, fn($e) => (int)$e['subject_id'] !== $target));
            foreach ($ids as $id) {
                if ($this->wouldCreatePrerequisiteCycle($others, $target, $id)) throw new DomainValidationException('This relationship would create a circular prerequisite chain.');
            }
            $this->validatePlacements($version, $target, $ids, $input, true);
            $kept = [];
            foreach ($current as $edge) {
                $id = (int)$edge['required_subject_id'];
                if (!in_array($id, $ids, true) || isset($kept[$id])) {
                    if (!$this->requirements->delete((int)$edge['id'])) {
                        throw new DomainConflictException('This rule changed. Close this dialog and reload before editing.');
                    }
                    audit_log_required('SUBJECT_REQUIREMENT_REMOVE', 'curriculum_subject_requirement', (int)$edge['id'], 'Prerequisite relationship removed.',
                        ['event' => 'prerequisite.removed', 'version_id' => $version, 'target_subject_id' => $target, 'required_subject_id' => $id]);
                } else $kept[$id] = true;
            }
            foreach ($ids as $id) if (!isset($kept[$id])) {
                $edgeId = $this->requirements->create(['curriculum_version_id' => $version, 'subject_id' => $target,
                    'required_subject_id' => $id, 'requirement_type' => 'prerequisite'], $actor);
                audit_log_required('SUBJECT_REQUIREMENT_CREATE', 'curriculum_subject_requirement', $edgeId, 'Prerequisite relationship created.',
                    ['event' => 'prerequisite.created', 'version_id' => $version, 'target_subject_id' => $target, 'required_subject_id' => $id]);
            }
            audit_log_required('SUBJECT_REQUIREMENT_UPDATE', 'curriculum_subject_requirement', null, 'Target prerequisite rule updated.',
                ['event' => 'prerequisite.updated', 'version_id' => $version, 'target_subject_id' => $target, 'required_subject_ids' => $ids]);
            $result = $this->workspace($version, true);
            $this->db->commit();
            return $result;
        } catch (Throwable $error) { $this->db->rollback(); throw $error; }
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
