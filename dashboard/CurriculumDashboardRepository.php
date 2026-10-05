<?php

class CurriculumDashboardRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function summaryCounts(): array
    {
        $result = $this->db->query(
            'SELECT
                (SELECT COUNT(*) FROM academic_programs) AS academic_programs,
                (SELECT COUNT(*) FROM subjects) AS subjects,
                (SELECT COUNT(*) FROM curricula) AS curricula,
                (SELECT COUNT(*) FROM curriculum_versions) AS curriculum_versions,
                (SELECT COUNT(*) FROM curriculum_version_subjects) AS subject_mappings,
                (SELECT COUNT(*) FROM curriculum_subject_requirements) AS subject_requirements,
                (SELECT COUNT(*) FROM curriculum_elective_groups) AS elective_groups,
                (SELECT COUNT(*) FROM subject_equivalencies) AS subject_equivalencies'
        );

        return $result->fetch_assoc() ?: [];
    }

    public function visiblePrograms(): array
    {
        $result = $this->db->query(
            'SELECT p.id, p.program_code, p.program_name, p.status,
                    COUNT(DISTINCT c.id) AS curricula
             FROM academic_programs AS p
             LEFT JOIN curricula AS c ON c.academic_program_id = p.id
             GROUP BY p.id
             ORDER BY p.program_code, p.id'
        );
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function subjectMasterCount(): int
    {
        $result = $this->db->query('SELECT COUNT(*) AS total FROM subjects');
        return $result ? (int)$result->fetch_assoc()['total'] : 0;
    }

    public function versionStatusCounts(): array
    {
        $result = $this->db->query(
            "SELECT status, COUNT(*) AS total
             FROM curriculum_versions
             WHERE status IN ('draft', 'active', 'archived')
             GROUP BY status"
        );
        $counts = ['draft' => 0, 'active' => 0, 'archived' => 0];

        while ($row = $result->fetch_assoc()) {
            $status = (string)$row['status'];
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int)$row['total'];
            }
        }

        return $counts;
    }

    public function recentCurriculumActivity(int $limit = 8): array
    {
        $safeLimit = max(1, min(10, $limit));
        $stmt = $this->db->prepare(
            "SELECT audit_logs.action, audit_logs.entity_type, audit_logs.entity_id,
                    audit_logs.description, audit_logs.created_at,
                    users.username, users.first_name, users.last_name
             FROM audit_logs
             LEFT JOIN users ON users.id = audit_logs.user_id
             WHERE audit_logs.action LIKE 'PROGRAM_%'
                OR audit_logs.action LIKE 'SUBJECT_%'
                OR audit_logs.action LIKE 'CURRICULUM_%'
                OR audit_logs.action LIKE 'ELECTIVE_%'
                OR audit_logs.action LIKE 'GRADE_WEIGHTING_%'
                OR audit_logs.action LIKE 'COMPLIANCE_%'
             ORDER BY audit_logs.created_at DESC, audit_logs.id DESC
             LIMIT ?"
        );
        $stmt->bind_param('i', $safeLimit);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];

        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}
