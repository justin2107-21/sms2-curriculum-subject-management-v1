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

    /** Source-scoped outcomes with mappings from this exact version only. */
    public function outcomeMappings(int $versionId): array
    {
        $stmt=RepositoryQuery::execute($this->db,
            'SELECT o.id outcome_id,o.outcome_code,o.outcome_text,o.source_order,o.document_code,o.document_title,
                    o.issuing_authority,o.source_section,o.printed_page,o.pdf_page_index,o.source_sha256,
                    c.assessment_state,c.rationale coverage_rationale,m.mapping_id,m.mapping_rationale,m.mapping_origin,
                    m.inherited_from_mapping_id,m.placement_id,m.subject_code,m.subject_title,m.year_level,m.semester
             FROM curriculum_outcome_catalog o
             LEFT JOIN curriculum_version_outcome_coverage c ON c.curriculum_version_id=? AND c.outcome_id=o.id
             LEFT JOIN (SELECT x.id mapping_id,x.outcome_id,x.rationale mapping_rationale,x.mapping_origin,
                        x.inherited_from_mapping_id,p.id placement_id,s.subject_code,s.subject_title,p.year_level,p.semester
                        FROM curriculum_version_subject_outcomes x
                        JOIN curriculum_version_subjects p ON p.id=x.curriculum_version_subject_id AND p.curriculum_version_id=?
                        JOIN subjects s ON s.id=p.subject_id) m ON m.outcome_id=o.id
             WHERE o.source_key=? ORDER BY o.source_order,m.subject_code,m.placement_id',
            'iis',[$versionId,$versionId,'CHED-CMO-25-2015-BSIT']);
        $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();$result=[];
        foreach($rows as $row){$id=(int)$row['outcome_id'];if(!isset($result[$id]))$result[$id]=[
            'id'=>$id,'outcome_code'=>$row['outcome_code'],'outcome_text'=>$row['outcome_text'],'source_order'=>(int)$row['source_order'],
            'document_code'=>$row['document_code'],'document_title'=>$row['document_title'],'issuing_authority'=>$row['issuing_authority'],
            'source_section'=>$row['source_section'],'printed_page'=>(int)$row['printed_page'],'pdf_page_index'=>(int)$row['pdf_page_index'],
            'source_sha256'=>$row['source_sha256'],'assessment_state'=>$row['assessment_state'],'coverage_rationale'=>$row['coverage_rationale'],'mappings'=>[]];
            if($row['mapping_id']!==null)$result[$id]['mappings'][]=[
                'id'=>(int)$row['mapping_id'],'placement_id'=>(int)$row['placement_id'],'subject_code'=>$row['subject_code'],
                'subject_title'=>$row['subject_title'],'year_level'=>$row['year_level'],'semester'=>$row['semester'],
                'rationale'=>$row['mapping_rationale'],'mapping_origin'=>$row['mapping_origin'],
                'inherited_from_mapping_id'=>$row['inherited_from_mapping_id']===null?null:(int)$row['inherited_from_mapping_id']];
        }
        foreach($result as &$outcome){$count=count($outcome['mappings']);$outcome['mapping_count']=$count;
            $outcome['coverage_status']=$outcome['assessment_state']===null?'not_assessed':($outcome['assessment_state']==='incomplete'?'incomplete':($count>0?'mapped':'unmapped'));}
        unset($outcome);return array_values($result);
    }

    /** Placement-scoped CLO, alignment and planning records; no attainment fields exist. */
    public function cloAssessmentRecords(int $versionId): array
    {
        $run = fn(string $sql) => RepositoryQuery::execute($this->db,$sql,'i',[$versionId]);
        $stmt=$run('SELECT c.id clo_id,c.curriculum_version_subject_id placement_id,p.subject_id,s.subject_code,s.subject_title,c.clo_code,c.clo_statement,c.sequence_order,c.origin,c.source_clo_id,c.record_state,c.review_state FROM curriculum_version_course_learning_outcomes c JOIN curriculum_version_subjects p ON p.id=c.curriculum_version_subject_id JOIN subjects s ON s.id=p.subject_id WHERE p.curriculum_version_id=? ORDER BY p.year_level,p.semester,p.sequence_order,c.sequence_order,c.id');
        $clos=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();$byId=[];
        foreach($clos as $row){$row['alignments']=[];$row['assessment_plans']=[];$byId[(int)$row['clo_id']]=$row;}
        $stmt=$run('SELECT a.clo_id,o.outcome_code,o.outcome_text,a.rationale,a.origin,a.record_state,a.review_state FROM curriculum_version_clo_outcome_alignments a JOIN curriculum_version_subjects p ON p.id=a.curriculum_version_subject_id JOIN curriculum_outcome_catalog o ON o.id=a.outcome_id WHERE p.curriculum_version_id=? ORDER BY a.id');
        foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) if(isset($byId[(int)$row['clo_id']])) $byId[(int)$row['clo_id']]['alignments'][]=$row;
        $stmt->close();
        $stmt=$run('SELECT clo_id,assessment_method,planned_task,evidence_description,performance_indicator,rubric_or_standard,origin,source_plan_id,record_state,review_state FROM curriculum_version_clo_assessment_plans WHERE curriculum_version_subject_id IN (SELECT id FROM curriculum_version_subjects WHERE curriculum_version_id=?) ORDER BY id');
        foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) if(isset($byId[(int)$row['clo_id']])) $byId[(int)$row['clo_id']]['assessment_plans'][]=$row;
        $stmt->close();return array_values($byId);
    }

    public function unitSummary(int $versionId): array
    {
        $stmt = RepositoryQuery::execute(
            $this->db,
            'SELECT COUNT(*) AS total_subjects,
                    CAST(COALESCE(SUM(subjects.units), 0) AS DECIMAL(12,2)) AS total_units,
                    CAST(SUM(subjects.lecture_units) AS DECIMAL(12,2)) AS total_lecture_units,
                    CAST(SUM(subjects.laboratory_units) AS DECIMAL(12,2)) AS total_laboratory_units
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
                    groups.required_selections, groups.description,
                    memberships.id AS membership_id,
                    subjects.id AS subject_id, subjects.subject_code,
                    subjects.subject_title
             FROM curriculum_elective_groups AS groups
             LEFT JOIN curriculum_elective_group_subjects AS memberships
                ON memberships.elective_group_id = groups.id
             LEFT JOIN subjects ON subjects.id = memberships.subject_id
             WHERE groups.curriculum_version_id = ?
               AND groups.status = \'active\'
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
