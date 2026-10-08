<?php
require_once __DIR__ . '/RepositoryQuery.php';
require_once __DIR__ . '/../validators/DomainValidator.php';

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

    public function summary(string $type, int $id, array $record): array
    {
        if ($type === 'program') {
            $stmt = $this->db->prepare("SELECT COUNT(*) assigned_count FROM program_subject_assignments WHERE academic_program_id=? AND status='active'");
            $stmt->bind_param('i',$id);$stmt->execute();$record['assigned_count']=(int)$stmt->get_result()->fetch_assoc()['assigned_count'];$stmt->close();
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
