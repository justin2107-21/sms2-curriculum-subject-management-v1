<?php

require_once __DIR__ . '/CurriculumVersionEditPolicy.php';

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/CurriculumElectiveGroupRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';

class CurriculumElectiveGroupService
{
    private CurriculumElectiveGroupRepository $groups;

    public function __construct(private mysqli $db)
    {
        $this->groups = new CurriculumElectiveGroupRepository($db);
    }

    public function list(int $versionId, array $input): array
    {
        if (!$this->groups->versionExists($versionId)) {
            throw new DomainNotFoundException('Curriculum version not found.');
        }
        $query = DomainValidator::listQuery($input);
        $result = $this->groups->paginateByVersion($versionId, $query);
        return ['groups' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function create(array $input, int $createdBy): array
    {
        $data = DomainValidator::electiveGroup($input);
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertEditable($data['curriculum_version_id']);
            if (!$this->groups->lockVersion($data['curriculum_version_id'])) {
                throw new DomainNotFoundException('Curriculum version not found.');
            }
            if ($this->groups->nameExists(
                $data['curriculum_version_id'],
                $data['group_name']
            )) {
                throw new DomainConflictException(
                    'An elective group with this name already exists in this curriculum version.'
                );
            }
            $id = $this->groups->create($data, $createdBy);
            audit_log_required(
                'ELECTIVE_GROUP_CREATE',
                'curriculum_elective_group',
                $id,
                'Curriculum elective group created.',
                [
                    'curriculum_version_id' => $data['curriculum_version_id'],
                    'group_name' => $data['group_name'],
                ]
            );
            $group = $this->groups->findById($id);
            if (!$group) {
                throw new RuntimeException('Created elective group could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            if ($error instanceof mysqli_sql_exception && (int)$error->getCode() === 1062) {
                throw new DomainConflictException(
                    'An elective group with this name already exists in this curriculum version.'
                );
            }
            throw $error;
        }
        return $group;
    }

    public function update(int $id, array $input): array
    {
        $data = DomainValidator::electiveGroupUpdate($input);
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertChildEditable('group', $id);
            $group = $this->groups->findByIdForUpdate($id);
            if (!$group) {
                throw new DomainNotFoundException('Elective group not found.');
            }
            $versionId = (int)$group['curriculum_version_id'];
            if ($this->groups->nameExists($versionId, $data['group_name'], $id)) {
                throw new DomainConflictException(
                    'An elective group with this name already exists in this curriculum version.'
                );
            }
            $this->groups->update($id, $data);
            audit_log_required(
                'ELECTIVE_GROUP_UPDATE',
                'curriculum_elective_group',
                $id,
                'Curriculum elective group updated.',
                [
                    'curriculum_version_id' => $versionId,
                    'group_name' => $data['group_name'],
                ]
            );
            $updated = $this->groups->findById($id);
            if (!$updated) {
                throw new RuntimeException('Updated elective group could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            if ($error instanceof mysqli_sql_exception && (int)$error->getCode() === 1062) {
                throw new DomainConflictException(
                    'An elective group with this name already exists in this curriculum version.'
                );
            }
            throw $error;
        }
        return $updated;
    }

    public function remove(int $id): void
    {
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertChildEditable('group', $id);
            $group = $this->groups->findByIdForUpdate($id);
            if (!$group) {
                throw new DomainNotFoundException('Elective group not found.');
            }
            if ($this->groups->membershipCount($id) > 0) {
                throw new DomainConflictException(
                    'Remove all subjects from this elective group before removing the group.'
                );
            }
            if (!$this->groups->delete($id)) {
                throw new DomainNotFoundException('Elective group not found.');
            }
            audit_log_required(
                'ELECTIVE_GROUP_REMOVE',
                'curriculum_elective_group',
                $id,
                'Empty curriculum elective group removed.',
                [
                    'curriculum_version_id' => (int)$group['curriculum_version_id'],
                    'group_name' => $group['group_name'],
                ]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }
}
