<?php

require_once __DIR__ . '/CurriculumVersionEditPolicy.php';

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/CurriculumElectiveGroupRepository.php';
require_once __DIR__ . '/../repositories/CurriculumElectiveGroupSubjectRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionSubjectRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';

class CurriculumElectiveGroupSubjectService
{
    private CurriculumElectiveGroupRepository $groups;
    private CurriculumElectiveGroupSubjectRepository $memberships;
    private CurriculumVersionSubjectRepository $placements;

    public function __construct(private mysqli $db)
    {
        $this->groups = new CurriculumElectiveGroupRepository($db);
        $this->memberships = new CurriculumElectiveGroupSubjectRepository($db);
        $this->placements = new CurriculumVersionSubjectRepository($db);
    }

    public function list(int $groupId, array $input): array
    {
        $group = $this->groups->findById($groupId);
        if (!$group) {
            throw new DomainNotFoundException('Elective group not found.');
        }
        $query = DomainValidator::listQuery($input);
        $result = $this->memberships->paginateByGroup($groupId, $query);
        return [
            'group' => $group,
            'memberships' => $result['records'],
            'pagination' => $result['pagination'],
        ];
    }

    public function placementOptions(int $groupId, array $input): array
    {
        $group = $this->groups->findById($groupId);
        if (!$group) {
            throw new DomainNotFoundException('Elective group not found.');
        }
        $query = DomainValidator::listQuery($input);
        $result = $this->memberships->paginatePlacementOptions(
            $groupId,
            (int)$group['curriculum_version_id'],
            $query
        );
        return [
            'group' => $group,
            'placements' => $result['records'],
            'pagination' => $result['pagination'],
        ];
    }

    public function add(array $input, int $createdBy): array
    {
        $data = DomainValidator::electiveMembership($input);
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertChildEditable('group', $data['elective_group_id']);
            $group = $this->groups->findByIdForUpdate($data['elective_group_id']);
            if (!$group) {
                throw new DomainNotFoundException('Elective group not found.');
            }
            $versionId = (int)$group['curriculum_version_id'];
            if (!$this->placements->assignmentExists($versionId, $data['subject_id'])) {
                throw new DomainValidationException(
                    'The selected subject is not mapped to this curriculum version.'
                );
            }
            if ($this->memberships->membershipExists(
                $data['elective_group_id'],
                $data['subject_id']
            )) {
                throw new DomainConflictException(
                    'This subject is already included in this elective group.'
                );
            }
            $id = $this->memberships->create(
                $data['elective_group_id'],
                $versionId,
                $data['subject_id'],
                $createdBy
            );
            audit_log_required(
                'ELECTIVE_GROUP_SUBJECT_ADD',
                'curriculum_elective_group_subject',
                $id,
                'Subject added to curriculum elective group.',
                [
                    'elective_group_id' => $data['elective_group_id'],
                    'curriculum_version_id' => $versionId,
                    'subject_id' => $data['subject_id'],
                ]
            );
            $membership = $this->memberships->findById($id);
            if (!$membership) {
                throw new RuntimeException('Created elective membership could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            if ($error instanceof mysqli_sql_exception && (int)$error->getCode() === 1062) {
                throw new DomainConflictException(
                    'This subject is already included in this elective group.'
                );
            }
            throw $error;
        }
        return $membership;
    }

    public function remove(int $id): void
    {
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertChildEditable('membership', $id);
            $membership = $this->memberships->findById($id);
            if (!$membership) {
                throw new DomainNotFoundException('Elective group membership not found.');
            }
            $group = $this->groups->findByIdForUpdate(
                (int)$membership['elective_group_id']
            );
            if (!$group) {
                throw new DomainNotFoundException('Elective group not found.');
            }
            if (!$this->memberships->delete($id)) {
                throw new DomainNotFoundException('Elective group membership not found.');
            }
            audit_log_required(
                'ELECTIVE_GROUP_SUBJECT_REMOVE',
                'curriculum_elective_group_subject',
                $id,
                'Subject removed from curriculum elective group.',
                [
                    'elective_group_id' => (int)$membership['elective_group_id'],
                    'curriculum_version_id' => (int)$membership['curriculum_version_id'],
                    'subject_id' => (int)$membership['subject_id'],
                ]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }
}
