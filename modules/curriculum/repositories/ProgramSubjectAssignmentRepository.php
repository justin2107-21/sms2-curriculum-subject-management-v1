<?php
require_once __DIR__ . '/RepositoryQuery.php';
require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../services/CurriculumReadScope.php';

class ProgramSubjectAssignmentRepository
{
    public function __construct(private mysqli $db) {}

    public function search(string $type, string $query, string $contextType = '', int $contextId = 0, bool $availableOnly = false): array
    {
        $q = trim($query);
        $pattern = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], mb_strtolower($q, 'UTF-8')) . '%';
        $availability = '';
        if ($contextId > 0) {
            $availability = $type === 'program'
                ? " AND NOT EXISTS (SELECT 1 FROM program_subject_assignments x WHERE x.academic_program_id=academic_programs.id AND x.subject_id=? AND x.status='active')"
                : " AND NOT EXISTS (SELECT 1 FROM program_subject_assignments x WHERE x.subject_id=subjects.id AND x.academic_program_id=? AND x.status='active')";
        }
        if ($type === 'program') {
            $sql = "SELECT id, program_code, program_name, program_type, status FROM academic_programs
                    WHERE " . ($availableOnly ? "status = 'active' AND " : '') . "(? = '' OR LOWER(program_code) LIKE ? ESCAPE '=' OR LOWER(program_name) LIKE ? ESCAPE '=')
                    $availability
                    ORDER BY CASE WHEN LOWER(program_code)=LOWER(?) THEN 0 WHEN LOWER(program_code) LIKE CONCAT(LOWER(?),'%') THEN 1
                      WHEN LOWER(program_name) LIKE CONCAT(LOWER(?),'%') THEN 2 ELSE 3 END, program_code, id LIMIT 30";
        } else {
            $sql = "SELECT id, subject_code, subject_title, units, status FROM subjects
                    WHERE " . ($availableOnly ? "status = 'active' AND " : '') . "(? = '' OR LOWER(subject_code) LIKE ? ESCAPE '=' OR LOWER(subject_title) LIKE ? ESCAPE '=')
                    $availability
                    ORDER BY CASE WHEN LOWER(subject_code)=LOWER(?) THEN 0 WHEN LOWER(subject_code) LIKE CONCAT(LOWER(?),'%') THEN 1
                      WHEN LOWER(subject_title) LIKE CONCAT(LOWER(?),'%') THEN 2 ELSE 3 END, subject_code, id LIMIT 30";
        }
        $stmt = $this->db->prepare($sql);
        if ($contextId > 0) $stmt->bind_param('sssisss', $q, $pattern, $pattern, $contextId, $q, $q, $q);
        else $stmt->bind_param('ssssss', $q, $pattern, $pattern, $q, $q, $q);
        $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        return $rows;
    }

    public function record(string $type, int $id): ?array
    {
        $table = $type === 'program' ? 'academic_programs' : 'subjects';
        $stmt = $this->db->prepare("SELECT * FROM $table WHERE id = ?");
        $stmt->bind_param('i', $id); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        return $row ?: null;
    }

    public function summary(string $type, int $id, array $record, int $versionId = 0): array
    {
        if ($type === 'program') {
            $record = array_merge($record, $this->coverage($id, $versionId), ['version_id' => $versionId ?: null]);
        } else {
            $stmt = $this->db->prepare("SELECT COUNT(*) assigned_count FROM program_subject_assignments WHERE subject_id=? AND status='active'");
            $stmt->bind_param('i',$id);$stmt->execute();$record['assigned_count']=(int)$stmt->get_result()->fetch_assoc()['assigned_count'];$stmt->close();
            $stmt = $this->db->prepare('SELECT COUNT(DISTINCT c.id) usage_count FROM curriculum_version_subjects cvs JOIN curriculum_versions v ON v.id=cvs.curriculum_version_id JOIN curricula c ON c.id=v.curriculum_id WHERE cvs.subject_id=?');
            $stmt->bind_param('i',$id);$stmt->execute();$record['usage_count']=(int)$stmt->get_result()->fetch_assoc()['usage_count'];$stmt->close();
        }
        return $record;
    }

    public function list(string $view, int $id, array $query): array
    {
        $query = DomainValidator::listQuery($query, ['active', 'archived']);
        $status = $query['status'] ?? '';
        $search = $query['search'] ?? '';
        $perPage = $query['per_page'];
        $page = min(100000, $query['page']);
        $pattern = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], mb_strtolower($search, 'UTF-8')) . '%';
        $filterColumn = $view === 'program' ? 's' : 'p';
        $searchColumns = $view === 'program' ? ['s.subject_code','s.subject_title'] : ['p.program_code','p.program_name'];
        $contextColumn = $view === 'program' ? 'a.academic_program_id' : 'a.subject_id';
        $where = "$contextColumn = ? AND (? = '' OR a.status = ?) AND (? = '' OR LOWER({$searchColumns[0]}) LIKE ? ESCAPE '=' OR LOWER({$searchColumns[1]}) LIKE ? ESCAPE '=')";
        $joins = ' FROM program_subject_assignments a JOIN academic_programs p ON p.id=a.academic_program_id JOIN subjects s ON s.id=a.subject_id';
        $stmt = $this->db->prepare('SELECT COUNT(*) total'.$joins.' WHERE '.$where);
        $stmt->bind_param('isssss', $id, $status, $status, $search, $pattern, $pattern); $stmt->execute(); $total=(int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
        $offset = ($page-1)*$perPage;
        $order = $view === 'program' ? 's.subject_code, a.id' : 'p.program_code, a.id';
        $sql = 'SELECT a.id,a.status AS assignment_status,a.created_at,' .
            'p.id academic_program_id,p.program_code,p.program_name,p.program_type,p.status program_status,' .
            's.id subject_id,s.subject_code,s.subject_title,s.units,s.status subject_status,' .
            '(SELECT COUNT(DISTINCT c2.id) FROM curriculum_version_subjects cvs2 JOIN curriculum_versions v2 ON v2.id=cvs2.curriculum_version_id JOIN curricula c2 ON c2.id=v2.curriculum_id WHERE cvs2.subject_id=s.id AND c2.academic_program_id=p.id) curriculum_usage_count' .
            $joins . ' WHERE '.$where.' ORDER BY '.$order.' LIMIT ? OFFSET ?';
        $stmt = $this->db->prepare($sql); $stmt->bind_param('isssssii', $id, $status, $status, $search, $pattern, $pattern, $perPage, $offset);
        $stmt->execute(); $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        foreach ($rows as &$row) $row['placements'] = $this->placements((int)$row['academic_program_id'], (int)$row['subject_id']); unset($row);
        return ['assignments'=>$rows,'pagination'=>['page'=>$page,'per_page'=>$perPage,'total_records'=>$total,'total_pages'=>max(1,(int)ceil($total/$perPage))]];
    }

    /** Placed in the SELECTED version: constant SQL plus two parameters (program id, version id; 0 matches nothing). Read scope still applies. */
    private function placedSql(): string
    {
        $scope = CurriculumReadScope::sql('v');
        return 'EXISTS (SELECT 1 FROM curriculum_version_subjects cvs JOIN curriculum_versions v ON v.id=cvs.curriculum_version_id JOIN curricula c ON c.id=v.curriculum_id WHERE cvs.subject_id=s.id AND c.academic_program_id=? AND v.id=?' . ($scope !== '' ? " AND $scope" : '') . ')';
    }

    /** True when the version exists under the program (assignment itself never depends on a version). */
    public function versionBelongsToProgram(int $programId, int $versionId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE v.id=? AND c.academic_program_id=? LIMIT 1');
        $stmt->bind_param('ii', $versionId, $programId); $stmt->execute(); $found = (bool)$stmt->get_result()->fetch_row(); $stmt->close();
        return $found;
    }

    /** One row per subject with its assigned (program-level) and placed (selected version) flags. Parameters in SQL order: program id and version id (placed), then program id (assigned join). */
    private function unionSql(): string
    {
        return "SELECT s.id subject_id, s.subject_code, s.subject_title, s.units, s.status subject_status, a.id assignment_id,
                       (a.id IS NOT NULL) assigned, " . $this->placedSql() . " placed
                FROM subjects s LEFT JOIN program_subject_assignments a ON a.subject_id=s.id AND a.academic_program_id=? AND a.status='active'";
    }

    /** True when the curriculum belongs to the program and the version belongs to that curriculum. */
    public function chainValid(int $programId, int $curriculumId, int $versionId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE v.id=? AND c.id=? AND c.academic_program_id=? LIMIT 1');
        $stmt->bind_param('iii', $versionId, $curriculumId, $programId); $stmt->execute(); $found = (bool)$stmt->get_result()->fetch_row(); $stmt->close();
        return $found;
    }

    /** Serializes reconcile runs for one program inside a transaction. */
    public function lockProgram(int $programId): void
    {
        $stmt = $this->db->prepare('SELECT id FROM academic_programs WHERE id=? FOR UPDATE'); $stmt->bind_param('i', $programId); $stmt->execute(); $stmt->get_result()->fetch_row(); $stmt->close();
    }

    /** Subjects placed in the version that have no active assignment to the program (read only; any version status). */
    public function placedNotAssigned(int $programId, int $versionId): array
    {
        $scope = CurriculumReadScope::sql('v');
        $sql = "SELECT s.id subject_id, s.subject_code, s.subject_title, s.units, s.status subject_status, cvs.year_level, cvs.semester
                FROM curriculum_version_subjects cvs JOIN curriculum_versions v ON v.id=cvs.curriculum_version_id JOIN curricula c ON c.id=v.curriculum_id JOIN subjects s ON s.id=cvs.subject_id
                WHERE c.academic_program_id=? AND v.id=?" . ($scope !== '' ? " AND $scope" : '') . "
                  AND NOT EXISTS (SELECT 1 FROM program_subject_assignments a WHERE a.academic_program_id=c.academic_program_id AND a.subject_id=s.id AND a.status='active')
                ORDER BY cvs.year_level, cvs.semester, s.subject_code, s.id";
        $stmt = $this->db->prepare($sql); $stmt->bind_param('ii', $programId, $versionId); $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        foreach ($rows as &$row) $row['subject_id'] = (int)$row['subject_id'];
        return $rows;
    }

    public function coverage(int $programId, int $versionId = 0): array
    {
        $sql = 'SELECT COALESCE(SUM(assigned),0) assigned_count, COALESCE(SUM(placed),0) placed_count,
                       COALESCE(SUM(placed AND NOT assigned),0) placed_not_assigned, COALESCE(SUM(assigned AND NOT placed),0) assigned_not_placed,
                       COALESCE(SUM(placed AND NOT assigned AND subject_status = \'active\'),0) assignable_count
                FROM (' . $this->unionSql() . ') t WHERE assigned=1 OR placed=1';
        $stmt = $this->db->prepare($sql); $stmt->bind_param('iii', $programId, $versionId, $programId); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        return array_map('intval', $row);
    }

    public const COVERAGE_FILTERS = [
        '' => '1=1',
        'assigned' => 'assigned=1',
        'placed' => 'placed=1',
        'placed_not_assigned' => 'placed=1 AND assigned=0',
        'assigned_not_placed' => 'assigned=1 AND placed=0',
    ];

    /** The program's subjects: the union of assigned and placed, filtered, searched and paged on the server. */
    public function listCoverage(int $programId, array $query, string $filter, int $versionId = 0): array
    {
        $query = DomainValidator::listQuery($query);
        if (!array_key_exists($filter, self::COVERAGE_FILTERS)) throw new DomainValidationException('Invalid filter.');
        $search = $query['search'] ?? '';
        $perPage = $query['per_page'];
        $page = min(100000, $query['page']);
        $pattern = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], mb_strtolower($search, 'UTF-8')) . '%';
        $where = "(assigned=1 OR placed=1) AND (? = '' OR LOWER(subject_code) LIKE ? ESCAPE '=' OR LOWER(subject_title) LIKE ? ESCAPE '=') AND (" . self::COVERAGE_FILTERS[$filter] . ')';
        $base = ' FROM (' . $this->unionSql() . ') t WHERE ' . $where;
        $stmt = $this->db->prepare('SELECT COUNT(*) total' . $base);
        $stmt->bind_param('iiisss', $programId, $versionId, $programId, $search, $pattern, $pattern); $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare('SELECT subject_id, subject_code, subject_title, units, subject_status, assignment_id, assigned, placed' . $base . ' ORDER BY subject_code, subject_id LIMIT ? OFFSET ?');
        $stmt->bind_param('iiisssii', $programId, $versionId, $programId, $search, $pattern, $pattern, $perPage, $offset); $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        $byId = $this->placementsForSubjects($programId, $versionId, array_column($rows, 'subject_id'));
        foreach ($rows as &$row) {
            $row['subject_id'] = (int)$row['subject_id']; $row['assignment_id'] = $row['assignment_id'] === null ? null : (int)$row['assignment_id'];
            $row['assigned'] = (bool)$row['assigned']; $row['placed'] = (bool)$row['placed'];
            $row['placements'] = $byId[$row['subject_id']] ?? [];
        }
        unset($row);
        return ['assignments' => $rows, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total_records' => $total, 'total_pages' => max(1, (int)ceil($total / $perPage))]];
    }

    /** Placements of the given subjects in the selected version (year and semester), in read scope, keyed by subject id. */
    public function placementsForSubjects(int $programId, int $versionId, array $subjectIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $subjectIds)));
        if (!$ids) return [];
        $scope = CurriculumReadScope::sql('v');
        $sql = 'SELECT cvs.subject_id, c.id curriculum_id, c.curriculum_code, v.id version_id, v.version_label, cvs.year_level, cvs.semester
                FROM curriculum_version_subjects cvs JOIN curriculum_versions v ON v.id=cvs.curriculum_version_id JOIN curricula c ON c.id=v.curriculum_id
                WHERE c.academic_program_id=? AND v.id=? AND cvs.subject_id IN (' . implode(',', $ids) . ')' . ($scope !== '' ? " AND $scope" : '') . '
                ORDER BY c.curriculum_code, v.version_label, cvs.year_level, cvs.semester, cvs.id';
        $stmt = $this->db->prepare($sql); $stmt->bind_param('ii', $programId, $versionId); $stmt->execute();
        $out = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $out[(int)$row['subject_id']][] = $row;
        $stmt->close();
        return $out;
    }

    /** Where one subject is used: every program it is assigned to, and every curriculum term it is placed in (in read scope). */
    public function usage(int $subjectId): array
    {
        $stmt = $this->db->prepare("SELECT a.id assignment_id, p.id academic_program_id, p.program_code, p.program_name, p.status program_status FROM program_subject_assignments a JOIN academic_programs p ON p.id=a.academic_program_id WHERE a.subject_id=? AND a.status='active' ORDER BY p.program_code");
        $stmt->bind_param('i', $subjectId); $stmt->execute(); $assigned = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        $scope = CurriculumReadScope::sql('v');
        $sql = 'SELECT p.id academic_program_id, p.program_code, c.id curriculum_id, c.curriculum_code, v.id version_id, v.version_label, v.status version_status, cvs.year_level, cvs.semester
                FROM curriculum_version_subjects cvs JOIN curriculum_versions v ON v.id=cvs.curriculum_version_id JOIN curricula c ON c.id=v.curriculum_id JOIN academic_programs p ON p.id=c.academic_program_id
                WHERE cvs.subject_id=?' . ($scope !== '' ? " AND $scope" : '') . ' ORDER BY p.program_code, c.curriculum_code, v.version_label, cvs.year_level, cvs.semester, cvs.id';
        $stmt = $this->db->prepare($sql); $stmt->bind_param('i', $subjectId); $stmt->execute(); $placed = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        return ['assigned' => $assigned, 'placements' => $placed];
    }

    public function reviewFacts(string $view, int $id): array
    {
        $contextColumn = $view === 'program' ? 'a.academic_program_id' : 'a.subject_id';
        $sql = "SELECT COUNT(*) assignment_count,
                       COALESCE(SUM(EXISTS(SELECT 1 FROM curriculum_version_subjects cvs
                           JOIN curriculum_versions v ON v.id=cvs.curriculum_version_id
                           JOIN curricula c ON c.id=v.curriculum_id
                           WHERE cvs.subject_id=a.subject_id AND c.academic_program_id=a.academic_program_id)),0) mapped_count
                FROM program_subject_assignments a WHERE a.status='active' AND $contextColumn=?";
        $stmt=$this->db->prepare($sql);$stmt->bind_param('i',$id);$stmt->execute();$facts=$stmt->get_result()->fetch_assoc();$stmt->close();
        $facts['assignment_count']=(int)$facts['assignment_count'];$facts['mapped_count']=(int)$facts['mapped_count'];
        $facts['unmapped_count']=$facts['assignment_count']-$facts['mapped_count'];
        return $facts;
    }

    public function assignment(int $id): ?array
    {
        $sql='SELECT a.id,a.status AS assignment_status,a.created_at,p.id academic_program_id,p.program_code,p.program_name,p.program_type,p.status program_status,s.id subject_id,s.subject_code,s.subject_title,s.units,s.status subject_status FROM program_subject_assignments a JOIN academic_programs p ON p.id=a.academic_program_id JOIN subjects s ON s.id=a.subject_id WHERE a.id=?';
        $stmt=$this->db->prepare($sql);$stmt->bind_param('i',$id);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();return $row ?: null;
    }
    public function placements(int $programId, int $subjectId): array
    {
        $sql='SELECT c.curriculum_code,curriculum_versions.version_label,curriculum_versions.effective_academic_year,cvs.year_level,cvs.semester FROM curriculum_version_subjects cvs JOIN curriculum_versions ON curriculum_versions.id=cvs.curriculum_version_id JOIN curricula c ON c.id=curriculum_versions.curriculum_id WHERE c.academic_program_id=? AND cvs.subject_id=? ORDER BY c.curriculum_code,curriculum_versions.version_label,cvs.year_level,cvs.semester';
        $stmt=$this->db->prepare($sql);$stmt->bind_param('ii',$programId,$subjectId);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();return $rows;
    }
    public function lockSubject(int $subjectId): void
    {
        $stmt=$this->db->prepare('SELECT id FROM subjects WHERE id=? FOR UPDATE');$stmt->bind_param('i',$subjectId);$stmt->execute();$stmt->get_result()->fetch_row();$stmt->close();
    }
    public function isAssigned(int $programId,int $subjectId): bool
    {
        $stmt=$this->db->prepare("SELECT id FROM program_subject_assignments WHERE academic_program_id=? AND subject_id=? AND status='active' LIMIT 1");$stmt->bind_param('ii',$programId,$subjectId);$stmt->execute();$found=(bool)$stmt->get_result()->fetch_row();$stmt->close();return $found;
    }
    public function activateOrCreate(int $programId,int $subjectId,int $actorId): int
    {
        $stmt=$this->db->prepare('SELECT id FROM program_subject_assignments WHERE academic_program_id=? AND subject_id=? LIMIT 1');$stmt->bind_param('ii',$programId,$subjectId);$stmt->execute();$old=$stmt->get_result()->fetch_assoc();$stmt->close();
        if($old){$id=(int)$old['id'];$update=$this->db->prepare("UPDATE program_subject_assignments SET status='active' WHERE id=?");$update->bind_param('i',$id);$update->execute();$update->close();return $id;}
        $stmt=$this->db->prepare("INSERT INTO program_subject_assignments (academic_program_id,subject_id,status,created_by) VALUES (?,?,'active',?)");$stmt->bind_param('iii',$programId,$subjectId,$actorId);$stmt->execute();$id=(int)$this->db->insert_id;$stmt->close();return $id;
    }
    public function archive(int $id): bool
    {
        $stmt=$this->db->prepare("UPDATE program_subject_assignments SET status='archived' WHERE id=? AND status='active'");$stmt->bind_param('i',$id);$stmt->execute();$changed=$stmt->affected_rows>0;$stmt->close();return $changed;
    }
}
