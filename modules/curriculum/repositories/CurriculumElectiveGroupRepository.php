<?php

require_once __DIR__ . '/RepositoryQuery.php';

class CurriculumElectiveGroupRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT groups.id, groups.curriculum_version_id, groups.group_name,
                    groups.description, groups.created_at, groups.updated_at,
                    curriculum_versions.version_label,
                    curriculum_versions.effective_academic_year,
                    curricula.id AS curriculum_id, curricula.curriculum_code,
                    curricula.curriculum_name,
                    academic_programs.id AS academic_program_id,
                    academic_programs.program_code, academic_programs.program_name,
                    (SELECT COUNT(*)
                     FROM curriculum_elective_group_subjects AS memberships
                     WHERE memberships.elective_group_id = groups.id) AS subject_count
             FROM curriculum_elective_groups AS groups
             INNER JOIN curriculum_versions
                ON curriculum_versions.id = groups.curriculum_version_id
             INNER JOIN curricula ON curricula.id = curriculum_versions.curriculum_id
             INNER JOIN academic_programs
                ON academic_programs.id = curricula.academic_program_id
             WHERE groups.id = ? LIMIT 1',
            'i',
            [$id]
        );
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function findByIdForUpdate(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, curriculum_version_id, group_name, description
             FROM curriculum_elective_groups WHERE id = ? FOR UPDATE'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function versionExists(int $versionId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM curriculum_versions WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $versionId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $exists;
    }

    public function lockVersion(int $versionId): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM curriculum_versions WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $versionId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $exists;
    }

    public function nameExists(int $versionId, string $groupName, ?int $excludeId = null): bool
    {
        if ($excludeId === null) {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM curriculum_elective_groups
                 WHERE curriculum_version_id = ? AND group_name = ? LIMIT 1'
            );
            $stmt->bind_param('is', $versionId, $groupName);
        } else {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM curriculum_elective_groups
                 WHERE curriculum_version_id = ? AND group_name = ? AND id <> ? LIMIT 1'
            );
            $stmt->bind_param('isi', $versionId, $groupName, $excludeId);
        }
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $exists;
    }

    public function paginateByVersion(int $versionId, array $query): array
    {
        $conditions = ['groups.curriculum_version_id = ?'];
        $types = 'i';
        $params = [$versionId];
        if ($query['search'] !== null) {
            $conditions[] = "groups.group_name LIKE ? ESCAPE '='";
            $types .= 's';
            $params[] = RepositoryQuery::likePattern($query['search']);
        }
        $where = ' WHERE ' . implode(' AND ', $conditions);

        $countStmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS total FROM curriculum_elective_groups AS groups' . $where,
            $types,
            $params
        );
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();
        $pagination = RepositoryQuery::pagination($total, $query['page'], $query['per_page']);

        $listParams = $params;
        $listParams[] = $pagination['per_page'];
        $listParams[] = $pagination['offset'];
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT groups.id, groups.curriculum_version_id, groups.group_name,
                    groups.description, groups.created_at, groups.updated_at,
                    curriculum_versions.version_label,
                    curriculum_versions.effective_academic_year,
                    curricula.id AS curriculum_id, curricula.curriculum_code,
                    curricula.curriculum_name,
                    academic_programs.id AS academic_program_id,
                    academic_programs.program_code, academic_programs.program_name,
                    (SELECT COUNT(*)
                     FROM curriculum_elective_group_subjects AS memberships
                     WHERE memberships.elective_group_id = groups.id) AS subject_count
             FROM curriculum_elective_groups AS groups
             INNER JOIN curriculum_versions
                ON curriculum_versions.id = groups.curriculum_version_id
             INNER JOIN curricula ON curricula.id = curriculum_versions.curriculum_id
             INNER JOIN academic_programs
                ON academic_programs.id = curricula.academic_program_id'
            . $where .
            ' ORDER BY groups.group_name ASC, groups.id ASC
              LIMIT ? OFFSET ?',
            $types . 'ii',
            $listParams
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        unset($pagination['offset']);
        return ['records' => $rows, 'pagination' => $pagination];
    }

    public function create(array $data, int $createdBy): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO curriculum_elective_groups
                (curriculum_version_id, group_name, description, created_by)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'issi',
            $data['curriculum_version_id'],
            $data['group_name'],
            $data['description'],
            $createdBy
        );
        $stmt->execute();
        $id = (int)$this->db->insert_id;
        $stmt->close();
        return $id;
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE curriculum_elective_groups
             SET group_name = ?, description = ?
             WHERE id = ?'
        );
        $stmt->bind_param('ssi', $data['group_name'], $data['description'], $id);
        $stmt->execute();
        $stmt->close();
    }

    public function membershipCount(int $id): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS total
             FROM curriculum_elective_group_subjects
             WHERE elective_group_id = ?'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        return $total;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM curriculum_elective_groups WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $deleted = $stmt->affected_rows === 1;
        $stmt->close();
        return $deleted;
    }
}
