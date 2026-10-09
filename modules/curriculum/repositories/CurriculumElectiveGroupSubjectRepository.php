<?php

require_once __DIR__ . '/RepositoryQuery.php';

class CurriculumElectiveGroupSubjectRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT memberships.id, memberships.elective_group_id,
                    memberships.curriculum_version_id, memberships.subject_id,
                    memberships.created_at,
                    groups.group_name,
                    subjects.subject_code, subjects.subject_title, subjects.units,
                    placements.year_level, placements.semester, placements.sequence_order,
                    curriculum_versions.version_label,
                    curricula.curriculum_code, curricula.curriculum_name
             FROM curriculum_elective_group_subjects AS memberships
             INNER JOIN curriculum_elective_groups AS groups
                ON groups.id = memberships.elective_group_id
             INNER JOIN curriculum_version_subjects AS placements
                ON placements.curriculum_version_id = memberships.curriculum_version_id
               AND placements.subject_id = memberships.subject_id
             INNER JOIN subjects ON subjects.id = memberships.subject_id
             INNER JOIN curriculum_versions
                ON curriculum_versions.id = memberships.curriculum_version_id
             INNER JOIN curricula ON curricula.id = curriculum_versions.curriculum_id
             WHERE memberships.id = ? LIMIT 1',
            'i',
            [$id]
        );
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function membershipExists(int $groupId, int $subjectId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM curriculum_elective_group_subjects
             WHERE elective_group_id = ? AND subject_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $groupId, $subjectId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $exists;
    }

    public function placementHasMemberships(int $versionId, int $subjectId, bool $forUpdate = false): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM curriculum_elective_group_subjects
             WHERE curriculum_version_id = ? AND subject_id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->bind_param('ii', $versionId, $subjectId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $exists;
    }

    public function paginateByGroup(int $groupId, array $query): array
    {
        $conditions = ['memberships.elective_group_id = ?'];
        $types = 'i';
        $params = [$groupId];
        if ($query['search'] !== null) {
            $conditions[] = "(" . RepositoryQuery::searchColumn('subjects.subject_code') . " LIKE ? ESCAPE '='
                              OR " . RepositoryQuery::searchColumn('subjects.subject_title') . " LIKE ? ESCAPE '=')";
            $pattern = RepositoryQuery::likePattern($query['search']);
            $types .= 'ss';
            $params[] = $pattern;
            $params[] = $pattern;
        }
        $where = ' WHERE ' . implode(' AND ', $conditions);
        $joins = ' FROM curriculum_elective_group_subjects AS memberships
                   INNER JOIN curriculum_elective_groups AS groups
                      ON groups.id = memberships.elective_group_id
                   INNER JOIN curriculum_version_subjects AS placements
                      ON placements.curriculum_version_id = memberships.curriculum_version_id
                     AND placements.subject_id = memberships.subject_id
                   INNER JOIN subjects ON subjects.id = memberships.subject_id';

        $countStmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS total' . $joins . $where,
            $types,
            $params
        );
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();
        $pagination = RepositoryQuery::pagination($total, $query['page'], $query['per_page']);

        $rankParams = [];
        $searchOrder = RepositoryQuery::searchOrder(['subjects.subject_code', 'subjects.subject_title'], $query['search'] ?? null, $rankParams);
        $listParams = array_merge($params, $rankParams);
        $listParams[] = $pagination['per_page'];
        $listParams[] = $pagination['offset'];
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT memberships.id, memberships.elective_group_id,
                    memberships.curriculum_version_id, memberships.subject_id,
                    memberships.created_at, groups.group_name,
                    subjects.subject_code, subjects.subject_title, subjects.units,
                    subjects.lecture_units, subjects.laboratory_units,
                    placements.year_level, placements.semester,
                    placements.sequence_order, placements.is_required'
            . $joins . $where .
            ' ORDER BY ' . $searchOrder . 'placements.sequence_order IS NULL,
                       placements.sequence_order ASC,
                       subjects.subject_code ASC,
                       memberships.id ASC
              LIMIT ? OFFSET ?',
            $types . str_repeat('s', count($rankParams)) . 'ii',
            $listParams
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        unset($pagination['offset']);
        return ['records' => $rows, 'pagination' => $pagination];
    }

    public function paginatePlacementOptions(
        int $groupId,
        int $versionId,
        array $query
    ): array {
        $conditions = [
            'placements.curriculum_version_id = ?',
            'existing_membership.id IS NULL',
        ];
        $types = 'ii';
        $params = [$groupId, $versionId];
        $conditions[] = "subjects.status = 'active'";
        $conditions[] = 'placements.year_level = (SELECT year_level FROM curriculum_elective_groups WHERE id=?)';
        $conditions[] = 'placements.semester = (SELECT semester FROM curriculum_elective_groups WHERE id=?)';
        $types .= 'ii';
        array_push($params, $groupId, $groupId);
        if ($query['search'] !== null) {
            $conditions[] = "(" . RepositoryQuery::searchColumn('subjects.subject_code') . " LIKE ? ESCAPE '='
                              OR " . RepositoryQuery::searchColumn('subjects.subject_title') . " LIKE ? ESCAPE '=')";
            $pattern = RepositoryQuery::likePattern($query['search']);
            $types .= 'ss';
            $params[] = $pattern;
            $params[] = $pattern;
        }
        $where = ' WHERE ' . implode(' AND ', $conditions);
        $joins = ' FROM curriculum_version_subjects AS placements
                   INNER JOIN subjects ON subjects.id = placements.subject_id
                   LEFT JOIN curriculum_elective_group_subjects AS existing_membership
                      ON existing_membership.elective_group_id = ?
                     AND existing_membership.curriculum_version_id = placements.curriculum_version_id
                     AND existing_membership.subject_id = placements.subject_id';

        $countStmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS total' . $joins . $where,
            $types,
            $params
        );
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();
        $pagination = RepositoryQuery::pagination($total, $query['page'], $query['per_page']);

        $rankParams = [];
        $searchOrder = RepositoryQuery::searchOrder(['subjects.subject_code', 'subjects.subject_title'], $query['search'] ?? null, $rankParams);
        $listParams = array_merge($params, $rankParams);
        $listParams[] = $pagination['per_page'];
        $listParams[] = $pagination['offset'];
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT placements.id AS placement_id,
                    placements.curriculum_version_id, placements.subject_id,
                    placements.year_level, placements.semester,
                    placements.sequence_order, placements.is_required,
                    subjects.subject_code, subjects.subject_title, subjects.units'
            . $joins . $where .
            ' ORDER BY ' . $searchOrder . 'placements.sequence_order IS NULL,
                       placements.sequence_order ASC,
                       subjects.subject_code ASC,
                       placements.id ASC
              LIMIT ? OFFSET ?',
            $types . str_repeat('s', count($rankParams)) . 'ii',
            $listParams
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        unset($pagination['offset']);
        return ['records' => $rows, 'pagination' => $pagination];
    }

    public function create(int $groupId, int $versionId, int $subjectId, int $createdBy): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO curriculum_elective_group_subjects
                (elective_group_id, curriculum_version_id, subject_id, created_by)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('iiii', $groupId, $versionId, $subjectId, $createdBy);
        $stmt->execute();
        $id = (int)$this->db->insert_id;
        $stmt->close();
        return $id;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM curriculum_elective_group_subjects WHERE id = ?'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $deleted = $stmt->affected_rows === 1;
        $stmt->close();
        return $deleted;
    }
}
