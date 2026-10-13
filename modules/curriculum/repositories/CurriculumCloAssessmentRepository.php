<?php

require_once __DIR__ . '/RepositoryQuery.php';

/** Prepared, version-placement-scoped persistence for Task 8 records. */
final class CurriculumCloAssessmentRepository
{
    public function __construct(private mysqli $db) {}

    public function rows(string $sql, string $types = '', array $values = []): array
    {
        $stmt = RepositoryQuery::execute($this->db, $sql, $types, $values);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function run(string $sql, string $types, array $values): int
    {
        $stmt = RepositoryQuery::execute($this->db, $sql, $types, $values);
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected;
    }

    public function placement(int $id, bool $forUpdate = false): ?array
    {
        return $this->rows(
            'SELECT p.id placement_id,p.curriculum_version_id,p.subject_id,p.year_level,p.semester,p.sequence_order,
                    s.subject_code,s.subject_title,v.version_label,v.version_role,v.review_status,v.status,
                    c.id curriculum_id,c.curriculum_name,c.academic_program_id,a.program_code,a.program_name
             FROM curriculum_version_subjects p JOIN subjects s ON s.id=p.subject_id
             JOIN curriculum_versions v ON v.id=p.curriculum_version_id JOIN curricula c ON c.id=v.curriculum_id
             JOIN academic_programs a ON a.id=c.academic_program_id WHERE p.id=?' . ($forUpdate ? ' FOR UPDATE' : ''),
            'i', [$id]
        )[0] ?? null;
    }

    public function workspace(int $placementId): array
    {
        $clos = $this->rows(
            'SELECT * FROM curriculum_version_course_learning_outcomes
             WHERE curriculum_version_subject_id=? ORDER BY sequence_order,id', 'i', [$placementId]
        );
        $alignments = $this->rows(
            'SELECT a.*,o.outcome_code,o.outcome_text,o.document_code,o.document_title,o.source_section,
                    o.printed_page,o.pdf_page_index,o.source_sha256
             FROM curriculum_version_clo_outcome_alignments a
             JOIN curriculum_outcome_catalog o ON o.id=a.outcome_id
             WHERE a.curriculum_version_subject_id=? ORDER BY a.id', 'i', [$placementId]
        );
        $plans = $this->rows(
            'SELECT * FROM curriculum_version_clo_assessment_plans
             WHERE curriculum_version_subject_id=? ORDER BY clo_id,id', 'i', [$placementId]
        );
        return ['clos'=>$clos,'alignments'=>$alignments,'plans'=>$plans];
    }

    public function activeParentMappings(int $placementId): array
    {
        return $this->rows(
            'SELECT m.id mapping_id,m.outcome_id,o.outcome_code,o.outcome_text,o.document_code,o.document_title,
                    o.source_section,o.printed_page,o.pdf_page_index,o.source_sha256,m.rationale,m.mapping_origin
             FROM curriculum_version_subject_outcomes m JOIN curriculum_outcome_catalog o ON o.id=m.outcome_id
             WHERE m.curriculum_version_subject_id=? ORDER BY o.source_order,m.id', 'i', [$placementId]
        );
    }
}
