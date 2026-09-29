<?php

require_once __DIR__ . '/RepositoryQuery.php';

class CurriculumExportRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function contextByVersion(int $versionId): ?array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT programs.id AS academic_program_id, programs.program_code,
                    programs.program_name, programs.program_type,
                    programs.status AS program_status,
                    curricula.id AS curriculum_id, curricula.curriculum_code,
                    curricula.curriculum_name, curricula.status AS curriculum_status,
                    versions.id AS curriculum_version_id, versions.version_label,
                    versions.effective_academic_year, versions.description AS version_description,
                    versions.status AS version_status, versions.created_at AS version_created_at,
                    versions.updated_at AS version_updated_at
             FROM curriculum_versions AS versions
             INNER JOIN curricula ON curricula.id = versions.curriculum_id
             INNER JOIN academic_programs AS programs
                ON programs.id = curricula.academic_program_id
             WHERE versions.id = ? LIMIT 1',
            'i',
            [$versionId]
        );
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function subjects(int $versionId): array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT placements.id AS placement_id, placements.subject_id,
                    placements.year_level, placements.semester,
                    placements.sequence_order, placements.is_required,
                    subjects.subject_code, subjects.subject_title,
                    subjects.description, subjects.units,
                    subjects.lecture_units, subjects.laboratory_units,
                    subjects.status AS subject_status
             FROM curriculum_version_subjects AS placements
             INNER JOIN subjects ON subjects.id = placements.subject_id
             WHERE placements.curriculum_version_id = ?
             ORDER BY COALESCE(placements.year_level, \'\') ASC,
                      COALESCE(placements.semester, \'\') ASC,
                      placements.sequence_order IS NULL,
                      placements.sequence_order ASC,
                      subjects.subject_code ASC,
                      placements.id ASC',
            'i',
            [$versionId]
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function unitSummary(int $versionId): array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS total_subjects,
                    CAST(COALESCE(SUM(subjects.units), 0) AS DECIMAL(12,2)) AS total_units,
                    CAST(COALESCE(SUM(subjects.lecture_units), 0) AS DECIMAL(12,2)) AS total_lecture_units,
                    CAST(COALESCE(SUM(subjects.laboratory_units), 0) AS DECIMAL(12,2)) AS total_laboratory_units
             FROM curriculum_version_subjects AS placements
             INNER JOIN subjects ON subjects.id = placements.subject_id
             WHERE placements.curriculum_version_id = ?',
            'i',
            [$versionId]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row;
    }

    public function requirements(int $versionId): array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT requirements.id, requirements.requirement_type,
                    target_subjects.id AS subject_id,
                    target_subjects.subject_code, target_subjects.subject_title,
                    required_subjects.id AS required_subject_id,
                    required_subjects.subject_code AS required_subject_code,
                    required_subjects.subject_title AS required_subject_title
             FROM curriculum_subject_requirements AS requirements
             INNER JOIN subjects AS target_subjects
                ON target_subjects.id = requirements.subject_id
             INNER JOIN subjects AS required_subjects
                ON required_subjects.id = requirements.required_subject_id
             WHERE requirements.curriculum_version_id = ?
             ORDER BY target_subjects.subject_code ASC,
                      requirements.requirement_type ASC,
                      required_subjects.subject_code ASC,
                      requirements.id ASC',
            'i',
            [$versionId]
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function electiveRows(int $versionId): array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT groups.id AS elective_group_id, groups.group_name,
                    groups.description, memberships.id AS membership_id,
                    subjects.id AS subject_id, subjects.subject_code,
                    subjects.subject_title
             FROM curriculum_elective_groups AS groups
             LEFT JOIN curriculum_elective_group_subjects AS memberships
                ON memberships.elective_group_id = groups.id
             LEFT JOIN subjects ON subjects.id = memberships.subject_id
             WHERE groups.curriculum_version_id = ?
             ORDER BY groups.group_name ASC, groups.id ASC,
                      subjects.subject_code ASC, memberships.id ASC',
            'i',
            [$versionId]
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function gradeWeightingRows(int $versionId): array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT schemes.id AS scheme_id, schemes.description AS scheme_description,
                    placements.id AS placement_id, subjects.id AS subject_id,
                    subjects.subject_code, subjects.subject_title,
                    components.id AS component_id, components.component_name,
                    components.weight_percentage, components.sequence_order,
                    totals.total_weight
             FROM grade_weighting_schemes AS schemes
             INNER JOIN curriculum_version_subjects AS placements
                ON placements.id = schemes.curriculum_version_subject_id
             INNER JOIN subjects ON subjects.id = placements.subject_id
             LEFT JOIN grade_weighting_components AS components
                ON components.scheme_id = schemes.id
             LEFT JOIN (
                SELECT scheme_id,
                       CAST(COALESCE(SUM(weight_percentage), 0) AS DECIMAL(7,2)) AS total_weight
                FROM grade_weighting_components
                GROUP BY scheme_id
             ) AS totals ON totals.scheme_id = schemes.id
             WHERE placements.curriculum_version_id = ?
             ORDER BY subjects.subject_code ASC, schemes.id ASC,
                      components.sequence_order IS NULL,
                      components.sequence_order ASC,
                      components.component_name ASC,
                      components.id ASC',
            'i',
            [$versionId]
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function latestComplianceRun(int $versionId): ?array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT runs.id, runs.curriculum_version_id, runs.rules_evaluated,
                    runs.passed_count, runs.failed_count,
                    runs.not_evaluated_count, runs.created_at,
                    runner.username AS run_by_username,
                    runner.first_name AS run_by_first_name,
                    runner.last_name AS run_by_last_name
             FROM compliance_validation_runs AS runs
             LEFT JOIN users AS runner ON runner.id = runs.run_by
             WHERE runs.curriculum_version_id = ?
             ORDER BY runs.created_at DESC, runs.id DESC LIMIT 1',
            'i',
            [$versionId]
        );
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }
}
