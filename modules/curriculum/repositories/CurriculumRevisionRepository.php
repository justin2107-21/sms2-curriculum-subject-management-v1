<?php
require_once __DIR__ . '/RepositoryQuery.php';

/** Internal clone persistence. All authoritative reads are current locking reads. */
final class CurriculumRevisionRepository
{
    public function __construct(private mysqli $db) {}

    public function rows(string $sql, string $types = '', array $values = []): array
    {
        $stmt = RepositoryQuery::execute($this->db, $sql, $types, $values);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function placements(int $version, bool $lock = true): array
    {
        return $this->rows('SELECT * FROM curriculum_version_subjects WHERE curriculum_version_id=? ORDER BY subject_id,id' . ($lock ? ' FOR UPDATE' : ''), 'i', [$version]);
    }

    public function links(int $version, bool $lock = true): array
    {
        return $this->rows('SELECT * FROM curriculum_version_references WHERE curriculum_version_id=? ORDER BY compliance_reference_id,reference_purpose' . ($lock ? ' FOR UPDATE' : ''), 'i', [$version]);
    }

    public function structures(int $version): array
    {
        return [
            'requirements' => $this->rows('SELECT * FROM curriculum_subject_requirements WHERE curriculum_version_id=? ORDER BY id FOR UPDATE','i',[$version]),
            'groups' => $this->rows('SELECT * FROM curriculum_elective_groups WHERE curriculum_version_id=? ORDER BY id FOR UPDATE','i',[$version]),
            // Include even inconsistent membership rows pointing at a group in this version.
            'memberships' => $this->rows('SELECT m.* FROM curriculum_elective_group_subjects m JOIN curriculum_elective_groups g ON g.id=m.elective_group_id WHERE m.curriculum_version_id=? OR g.curriculum_version_id=? ORDER BY m.id FOR UPDATE','ii',[$version,$version]),
            'schemes' => $this->rows('SELECT s.* FROM grade_weighting_schemes s JOIN curriculum_version_subjects p ON p.id=s.curriculum_version_subject_id WHERE p.curriculum_version_id=? ORDER BY s.id FOR UPDATE','i',[$version]),
            'components' => $this->rows('SELECT c.* FROM grade_weighting_components c JOIN grade_weighting_schemes s ON s.id=c.scheme_id JOIN curriculum_version_subjects p ON p.id=s.curriculum_version_subject_id WHERE p.curriculum_version_id=? ORDER BY c.id FOR UPDATE','i',[$version]),
            'outcomes' => $this->rows('SELECT m.* FROM curriculum_version_subject_outcomes m JOIN curriculum_version_subjects p ON p.id=m.curriculum_version_subject_id WHERE p.curriculum_version_id=? ORDER BY m.id FOR UPDATE','i',[$version]),
            'clos' => $this->rows("SELECT c.* FROM curriculum_version_course_learning_outcomes c JOIN curriculum_version_subjects p ON p.id=c.curriculum_version_subject_id WHERE p.curriculum_version_id=? AND c.record_state='active' ORDER BY c.id FOR UPDATE",'i',[$version]),
            'clo_alignments' => $this->rows("SELECT a.* FROM curriculum_version_clo_outcome_alignments a JOIN curriculum_version_subjects p ON p.id=a.curriculum_version_subject_id WHERE p.curriculum_version_id=? AND a.record_state='active' ORDER BY a.id FOR UPDATE",'i',[$version]),
            'clo_plans' => $this->rows("SELECT a.* FROM curriculum_version_clo_assessment_plans a JOIN curriculum_version_subjects p ON p.id=a.curriculum_version_subject_id WHERE p.curriculum_version_id=? AND a.record_state='active' ORDER BY a.id FOR UPDATE",'i',[$version]),
        ];
    }

    public function createVersion(array $data, int $source, int $actor): int
    {
        return $this->insert('curriculum_versions', [
            'curriculum_id'=>$data['curriculum_id'], 'version_label'=>$data['version_label'],
            'effective_academic_year'=>$data['effective_academic_year'], 'description'=>$data['description'],
            'status'=>'draft', 'version_role'=>'proposed', 'review_status'=>'not_started',
            'baseline_version_id'=>$source, 'created_by'=>$actor,
        ]);
    }

    /** Fixed projections only; metadata timestamps/attribution are never copied. */
    public function copy(string $kind, array $source, array $overrides): int
    {
        $definitions = [
            'placement'=>['curriculum_version_subjects',['subject_id','year_level','semester','sequence_order','is_required']],
            'requirement'=>['curriculum_subject_requirements',['subject_id','required_subject_id','requirement_type']],
            'group'=>['curriculum_elective_groups',['group_name','description','year_level','semester','required_selections','status']],
            'membership'=>['curriculum_elective_group_subjects',['subject_id']],
            'scheme'=>['grade_weighting_schemes',['description']],
            'component'=>['grade_weighting_components',['component_name','weight_percentage','sequence_order']],
            'outcome_mapping'=>['curriculum_version_subject_outcomes',['outcome_id','rationale']],
            'clo'=>['curriculum_version_course_learning_outcomes',['clo_code','clo_statement','sequence_order']],
            'clo_alignment'=>['curriculum_version_clo_outcome_alignments',['outcome_id','rationale','source_mapping_id_snapshot']],
            'clo_plan'=>['curriculum_version_clo_assessment_plans',['assessment_method','planned_task','evidence_description','performance_indicator','rubric_or_standard']],
        ];
        if (!isset($definitions[$kind])) throw new InvalidArgumentException('Unsupported clone structure.');
        [$table,$fields] = $definitions[$kind];
        return $this->insert($table, array_intersect_key($source,array_flip($fields)) + $overrides);
    }

    public function cloneSnapshot(int $oldPlacement, int $newPlacement): void
    {
        $stmt=RepositoryQuery::execute($this->db,
            "INSERT INTO curriculum_version_subject_snapshots
             (curriculum_version_subject_id,subject_code_snapshot,subject_title_snapshot,units_snapshot,
              lecture_units_snapshot,laboratory_units_snapshot,description_snapshot,capture_method,source_reference_id,snapshot_complete)
             SELECT ?,subject_code_snapshot,subject_title_snapshot,units_snapshot,lecture_units_snapshot,
                    laboratory_units_snapshot,description_snapshot,'clone',source_reference_id,snapshot_complete
             FROM curriculum_version_subject_snapshots WHERE curriculum_version_subject_id=?", 'ii',[$newPlacement,$oldPlacement]);
        if ($stmt->affected_rows!==1) throw new DomainConflictException('Reference snapshot disappeared.');
        $stmt->close();
    }

    private function insert(string $table, array $values): int
    {
        $columns=implode(',',array_keys($values));
        $stmt=RepositoryQuery::execute($this->db,'INSERT INTO '.$table.' ('.$columns.') VALUES ('.implode(',',array_fill(0,count($values),'?')).')',str_repeat('s',count($values)),array_values($values));
        $id=(int)$this->db->insert_id;
        $stmt->close();
        return $id;
    }
}
