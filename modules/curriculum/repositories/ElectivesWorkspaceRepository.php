<?php
require_once __DIR__ . '/RepositoryQuery.php';

/** Reads preserve member references with LEFT JOINs so legacy defects remain visible. */
class ElectivesWorkspaceRepository
{
    public function __construct(private mysqli $db) {}

    private function rows(string $sql, string $types = '', array $params = []): array
    {
        $stmt = RepositoryQuery::execute($this->db, $sql, $types, $params);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function execute(string $sql, string $types, array $params): void
    {
        $stmt = RepositoryQuery::execute($this->db, $sql, $types, $params);
        $stmt->close();
    }

    public function context(int $program, int $curriculum, int $version, bool $lock = false): ?array
    {
        return $this->rows('SELECT v.*, p.program_type, p.id AS program_id FROM curriculum_versions v
            JOIN curricula c ON c.id=v.curriculum_id JOIN academic_programs p ON p.id=c.academic_program_id
            WHERE p.id=? AND c.id=? AND v.id=?' . ($lock ? ' FOR UPDATE' : ''), 'iii', [$program,$curriculum,$version])[0] ?? null;
    }

    public function contextOptions(string $level, string $search, ?int $program, ?int $curriculum): array
    {
        if ($level === 'program') {
            $fields = ['program_code','program_name']; $from = 'academic_programs'; $id = 'id'; $scope = ''; $types = ''; $params = [];
        } elseif ($level === 'curriculum') {
            $fields = ['curriculum_code','curriculum_name']; $from = 'curricula'; $id = 'id'; $scope = 'academic_program_id=? AND '; $types = 'i'; $params = [$program];
        } else {
            $fields = ['v.version_label','v.effective_academic_year']; $from = 'curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id'; $id = 'v.id'; $scope = 'c.academic_program_id=? AND c.id=? AND '; $types = 'ii'; $params = [$program,$curriculum];
        }
        $where = implode(' OR ', array_map(fn($field) => RepositoryQuery::searchColumn($field) . " LIKE ? ESCAPE '='", $fields));
        array_push($params, RepositoryQuery::likePattern($search), RepositoryQuery::likePattern($search)); $types .= 'ss';
        $rankParams = [];
        $order = RepositoryQuery::searchOrder($fields, $search, $rankParams);
        return $this->rows("SELECT $id AS id, {$fields[0]} AS code, {$fields[1]} AS title FROM $from WHERE $scope ($where) ORDER BY $order {$fields[0]}, $id LIMIT 3", $types . str_repeat('s',count($rankParams)), array_merge($params,$rankParams));
    }

    public function lockGroup(int $id, int $version): ?array
    {
        return $this->rows('SELECT * FROM curriculum_elective_groups WHERE id=? AND curriculum_version_id=? FOR UPDATE','ii',[$id,$version])[0] ?? null;
    }

    public function memberIds(int $id): array
    {
        return array_map('intval',array_column($this->rows('SELECT subject_id FROM curriculum_elective_group_subjects WHERE elective_group_id=?','i',[$id]),'subject_id'));
    }

    public function saveGroup(?int $id, array $data, int $actor): int
    {
        $values = [$data['group_name'],$data['year_level'],$data['semester'],$data['required_selections'],$data['status']];
        if ($id !== null) {
            $this->execute('UPDATE curriculum_elective_groups SET group_name=?,year_level=?,semester=?,required_selections=?,status=? WHERE id=?','sssisi',[...$values,$id]);
            return $id;
        }
        $this->execute('INSERT INTO curriculum_elective_groups(curriculum_version_id,group_name,year_level,semester,required_selections,status,created_by) VALUES(?,?,?,?,?,?,?)','isssisi',[$data['curriculum_version_id'],...$values,$actor]);
        return (int)$this->db->insert_id;
    }

    public function removeMember(int $group, int $subject): void
    {
        $this->execute('DELETE FROM curriculum_elective_group_subjects WHERE elective_group_id=? AND subject_id=?','ii',[$group,$subject]);
    }

    public function addMember(int $group, int $version, int $subject, int $actor): void
    {
        $this->execute('INSERT INTO curriculum_elective_group_subjects(elective_group_id,curriculum_version_id,subject_id,created_by) VALUES(?,?,?,?)','iiii',[$group,$version,$subject,$actor]);
    }

    public function archive(int $id): void
    {
        $this->execute("UPDATE curriculum_elective_groups SET status='archived' WHERE id=?",'i',[$id]);
    }

    public function groups(int $version): array
    {
        $groups = $this->rows('SELECT g.*, (v.id IS NOT NULL AND c.id IS NOT NULL AND a.id IS NOT NULL) AS context_valid FROM curriculum_elective_groups g
            LEFT JOIN curriculum_versions v ON v.id=g.curriculum_version_id LEFT JOIN curricula c ON c.id=v.curriculum_id LEFT JOIN academic_programs a ON a.id=c.academic_program_id
            WHERE g.curriculum_version_id=? ORDER BY g.group_name,g.id', 'i', [$version]);
        $members = $this->rows('SELECT m.*, s.subject_code,s.subject_title,s.units,s.status AS subject_status,
            p.id AS placement_id,p.year_level,p.semester FROM curriculum_elective_group_subjects m
            JOIN curriculum_elective_groups g ON g.id=m.elective_group_id
            LEFT JOIN subjects s ON s.id=m.subject_id
            LEFT JOIN curriculum_version_subjects p ON p.curriculum_version_id=g.curriculum_version_id AND p.subject_id=m.subject_id
            WHERE g.curriculum_version_id=? ORDER BY s.subject_code,m.id', 'i', [$version]);
        $byGroup = [];
        foreach ($members as $member) $byGroup[(int)$member['elective_group_id']][] = $member;
        foreach ($groups as &$group) $group['members'] = $byGroup[(int)$group['id']] ?? [];
        return $groups;
    }

    public function placements(int $version, bool $lock = false): array
    {
        return $this->rows('SELECT p.*,s.subject_code,s.subject_title,s.units,s.status AS subject_status
            FROM curriculum_version_subjects p JOIN subjects s ON s.id=p.subject_id
            WHERE p.curriculum_version_id=? ORDER BY s.subject_code' . ($lock ? ' FOR UPDATE' : ''), 'i', [$version]);
    }

    public function prerequisites(int $version): array
    {
        return $this->rows("SELECT r.subject_id,s.id AS required_subject_id,s.subject_code,s.subject_title
            FROM curriculum_subject_requirements r JOIN subjects s ON s.id=r.required_subject_id
            WHERE r.curriculum_version_id=? AND r.requirement_type='prerequisite' ORDER BY s.subject_code", 'i', [$version]);
    }
}
