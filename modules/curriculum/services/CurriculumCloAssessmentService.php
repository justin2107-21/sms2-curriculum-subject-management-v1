<?php

require_once __DIR__ . '/../validators/DomainExceptions.php';
require_once __DIR__ . '/../repositories/CurriculumCloAssessmentRepository.php';
require_once __DIR__ . '/CurriculumVersionEditPolicy.php';
require_once __DIR__ . '/../../../shared/audit.php';

/** User-entered CLO and planned assessment records; never student attainment. */
final class CurriculumCloAssessmentService
{
    private CurriculumCloAssessmentRepository $repo;

    public function __construct(private mysqli $db)
    {
        $this->repo = new CurriculumCloAssessmentRepository($db);
    }

    public function workspace(int $placementId): array
    {
        if ($placementId < 1) throw new DomainValidationException('Invalid subject placement.');
        $this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $placement = $this->repo->placement($placementId);
            if (!$placement) throw new DomainNotFoundException('Subject placement not found.');
            $data = $this->repo->workspace($placementId);
            foreach ($data['plans'] as &$plan) {
                $plan['plan_status'] = self::planStatus($plan);
            }
            unset($plan);
            $data['parent_mappings'] = $this->repo->activeParentMappings($placementId);
            $data['placement'] = $placement;
            $data['editable'] = CurriculumVersionEditPolicy::isEditable($placement);
            $data['disclaimer'] = 'Course outcome alignments and assessment plans are planning records. They do not establish student attainment, regulatory compliance, or institutional approval.';
            $this->db->commit();
            return $data;
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public static function planStatus(array $plan): string
    {
        return trim((string)($plan['assessment_method'] ?? '')) !== ''
            && (trim((string)($plan['planned_task'] ?? '')) !== '' || trim((string)($plan['evidence_description'] ?? '')) !== '')
            && trim((string)($plan['performance_indicator'] ?? '')) !== '' ? 'planned' : 'incomplete';
    }

    public function createClo(int $placementId, string $code, string $statement, int $sequence, int $actor): int
    {
        return $this->mutate($placementId, $actor, function (array $placement) use ($placementId,$code,$statement,$sequence,$actor): int {
            $code = $this->requiredText($code, 40, 'CLO code');
            $statement = $this->requiredText($statement, 12000, 'CLO statement');
            if ($sequence < 1 || $sequence > 65535) throw new DomainValidationException('CLO sequence must be between 1 and 65535.');
            $this->uniqueSequence($placementId, $sequence);
            $this->repo->run('INSERT INTO curriculum_version_course_learning_outcomes (curriculum_version_subject_id,clo_code,clo_statement,sequence_order,created_by) VALUES (?,?,?,?,?)','issii',[$placementId,$code,$statement,$sequence,$actor]);
            $id = (int)$this->db->insert_id;
            audit_log_required('CURRICULUM_CLO_CREATE','curriculum_version_course_learning_outcome',$id,'User-authored course learning outcome recorded.',[
                'placement_id'=>$placementId,'curriculum_version_id'=>(int)$placement['curriculum_version_id'],'actor_id'=>$actor]);
            return $id;
        });
    }

    public function updateClo(int $placementId, int $cloId, string $code, string $statement, int $sequence, int $actor): void
    {
        $this->mutate($placementId, $actor, function (array $placement) use ($placementId,$cloId,$code,$statement,$sequence,$actor): int {
            $code = $this->requiredText($code, 40, 'CLO code');
            $statement = $this->requiredText($statement, 12000, 'CLO statement');
            if ($sequence < 1 || $sequence > 65535) throw new DomainValidationException('CLO sequence must be between 1 and 65535.');
            $row = $this->activeClo($placementId,$cloId,true);
            $this->uniqueSequence($placementId, $sequence, $cloId);
            $this->repo->run('UPDATE curriculum_version_course_learning_outcomes SET clo_code=?,clo_statement=?,sequence_order=?,updated_by=?,review_state=CASE WHEN clo_statement<>? OR clo_code<>? THEN \'awaiting_review\' ELSE review_state END WHERE id=?','ssiissi',[$code,$statement,$sequence,$actor,$statement,$code,$cloId]);
            if ($row['clo_statement'] !== $statement || $row['clo_code'] !== $code) {
                $this->repo->run("UPDATE curriculum_version_clo_outcome_alignments SET review_state='awaiting_review',updated_by=? WHERE clo_id=? AND record_state='active'",'ii',[$actor,$cloId]);
                $this->repo->run("UPDATE curriculum_version_clo_assessment_plans SET review_state='awaiting_review',updated_by=? WHERE clo_id=? AND record_state='active'",'ii',[$actor,$cloId]);
            }
            audit_log_required('CURRICULUM_CLO_UPDATE','curriculum_version_course_learning_outcome',$cloId,'User-authored course learning outcome updated.',[
                'placement_id'=>$placementId,'curriculum_version_id'=>(int)$placement['curriculum_version_id'],'actor_id'=>$actor]);
            return $cloId;
        });
    }

    public function withdrawClo(int $placementId, int $cloId, int $actor): void
    {
        $this->mutate($placementId, $actor, function (array $placement) use ($placementId,$cloId,$actor): int {
            $this->activeClo($placementId,$cloId,true);
            $alignments = $this->repo->rows("SELECT id FROM curriculum_version_clo_outcome_alignments WHERE clo_id=? AND record_state='active' ORDER BY id FOR UPDATE",'i',[$cloId]);
            $plans = $this->repo->rows("SELECT id FROM curriculum_version_clo_assessment_plans WHERE clo_id=? AND record_state='active' ORDER BY id FOR UPDATE",'i',[$cloId]);
            if ($alignments || $plans) throw new DomainConflictException('Withdraw each active CLO alignment and assessment plan before withdrawing this CLO.');
            $this->repo->run("UPDATE curriculum_version_course_learning_outcomes SET record_state='withdrawn',withdrawn_by=?,withdrawn_at=CURRENT_TIMESTAMP,updated_by=? WHERE id=?",'iii',[$actor,$actor,$cloId]);
            audit_log_required('CURRICULUM_CLO_WITHDRAW','curriculum_version_course_learning_outcome',$cloId,'Course learning outcome withdrawn; history retained.',[
                'placement_id'=>$placementId,'curriculum_version_id'=>(int)$placement['curriculum_version_id'],'actor_id'=>$actor]);
            return $cloId;
        });
    }

    public function align(int $placementId, int $cloId, int $parentMappingId, string $rationale, int $actor): int
    {
        return $this->mutate($placementId, $actor, function (array $placement) use ($placementId,$cloId,$parentMappingId,$rationale,$actor): int {
            $rationale = $this->requiredText($rationale, 3000, 'Alignment rationale');
            $parent = $this->repo->rows('SELECT id,outcome_id FROM curriculum_version_subject_outcomes WHERE id=? AND curriculum_version_subject_id=? FOR UPDATE','ii',[$parentMappingId,$placementId])[0] ?? null;
            if (!$parent) throw new DomainNotFoundException('Graduate-outcome mapping does not belong to this placement.');
            $this->activeClo($placementId,$cloId,true);
            if ($this->repo->rows("SELECT id FROM curriculum_version_clo_outcome_alignments WHERE clo_id=? AND parent_mapping_id=? AND record_state='active' FOR UPDATE",'ii',[$cloId,$parentMappingId])) {
                throw new DomainConflictException('This CLO is already aligned to that graduate-outcome mapping.');
            }
            $this->repo->run('INSERT INTO curriculum_version_clo_outcome_alignments (clo_id,curriculum_version_subject_id,parent_mapping_id,source_mapping_id_snapshot,outcome_id,rationale,created_by) VALUES (?,?,?,?,?,?,?)','iiiiisi',[$cloId,$placementId,$parentMappingId,$parentMappingId,(int)$parent['outcome_id'],$rationale,$actor]);
            $id = (int)$this->db->insert_id;
            audit_log_required('CURRICULUM_CLO_ALIGNMENT_CREATE','curriculum_version_clo_outcome_alignment',$id,'CLO alignment to an existing graduate-outcome mapping recorded.',[
                'placement_id'=>$placementId,'curriculum_version_id'=>(int)$placement['curriculum_version_id'],'clo_id'=>$cloId,'parent_mapping_id'=>$parentMappingId,'outcome_id'=>(int)$parent['outcome_id'],'actor_id'=>$actor]);
            return $id;
        });
    }

    public function updateAlignment(int $placementId, int $alignmentId, string $rationale, int $actor): void
    {
        $this->mutate($placementId, $actor, function (array $placement) use ($placementId,$alignmentId,$rationale,$actor): int {
            $rationale = $this->requiredText($rationale, 3000, 'Alignment rationale');
            $row = $this->repo->rows("SELECT id FROM curriculum_version_clo_outcome_alignments WHERE id=? AND curriculum_version_subject_id=? AND record_state='active' FOR UPDATE",'ii',[$alignmentId,$placementId])[0] ?? null;
            if (!$row) throw new DomainNotFoundException('Active CLO alignment not found in this placement.');
            $this->repo->run("UPDATE curriculum_version_clo_outcome_alignments SET rationale=?,updated_by=?,review_state='awaiting_review' WHERE id=?",'sii',[$rationale,$actor,$alignmentId]);
            audit_log_required('CURRICULUM_CLO_ALIGNMENT_UPDATE','curriculum_version_clo_outcome_alignment',$alignmentId,'CLO alignment rationale updated.',[
                'placement_id'=>$placementId,'curriculum_version_id'=>(int)$placement['curriculum_version_id'],'actor_id'=>$actor]);
            return $alignmentId;
        });
    }

    public function withdrawAlignment(int $placementId, int $alignmentId, int $actor): void
    {
        $this->mutate($placementId, $actor, function (array $placement) use ($placementId,$alignmentId,$actor): int {
            $row = $this->repo->rows("SELECT * FROM curriculum_version_clo_outcome_alignments WHERE id=? AND curriculum_version_subject_id=? AND record_state='active' FOR UPDATE",'ii',[$alignmentId,$placementId])[0] ?? null;
            if (!$row) throw new DomainNotFoundException('Active CLO alignment not found in this placement.');
            $this->repo->run("UPDATE curriculum_version_clo_outcome_alignments SET parent_mapping_id=NULL,record_state='withdrawn',withdrawn_by=?,withdrawn_at=CURRENT_TIMESTAMP,updated_by=?,review_state='awaiting_review' WHERE id=?",'iii',[$actor,$actor,$alignmentId]);
            audit_log_required('CURRICULUM_CLO_ALIGNMENT_WITHDRAW','curriculum_version_clo_outcome_alignment',$alignmentId,'CLO alignment withdrawn; source relationship identity retained.',[
                'placement_id'=>$placementId,'curriculum_version_id'=>(int)$placement['curriculum_version_id'],'clo_id'=>(int)$row['clo_id'],
                'source_mapping_id_snapshot'=>(int)$row['source_mapping_id_snapshot'],'outcome_id'=>(int)$row['outcome_id'],'actor_id'=>$actor]);
            return $alignmentId;
        });
    }

    public function createPlan(int $placementId, int $cloId, array $input, int $actor): int
    {
        return $this->mutate($placementId, $actor, function (array $placement) use ($placementId,$cloId,$input,$actor): int {
            $this->activeClo($placementId,$cloId,true);
            $fields = $this->planFields($input);
            if (array_filter($fields, static fn($value)=>$value!==null)===[]) throw new DomainValidationException('Record at least one planned assessment detail.');
            $this->repo->run('INSERT INTO curriculum_version_clo_assessment_plans (clo_id,curriculum_version_subject_id,assessment_method,planned_task,evidence_description,performance_indicator,rubric_or_standard,created_by) VALUES (?,?,?,?,?,?,?,?)','iisssssi',[$cloId,$placementId,...$fields,$actor]);
            $id = (int)$this->db->insert_id;
            audit_log_required('CURRICULUM_CLO_ASSESSMENT_CREATE','curriculum_version_clo_assessment_plan',$id,'Planned CLO assessment created.',[
                'placement_id'=>$placementId,'curriculum_version_id'=>(int)$placement['curriculum_version_id'],'clo_id'=>$cloId,
                'plan_status'=>self::planStatus(array_combine(['assessment_method','planned_task','evidence_description','performance_indicator','rubric_or_standard'],$fields)),'actor_id'=>$actor]);
            return $id;
        });
    }

    public function updatePlan(int $placementId, int $cloId, int $planId, array $input, int $actor): void
    {
        $this->mutate($placementId, $actor, function (array $placement) use ($placementId,$cloId,$planId,$input,$actor): int {
            $this->activeClo($placementId,$cloId,true);
            $row = $this->repo->rows("SELECT id FROM curriculum_version_clo_assessment_plans WHERE id=? AND clo_id=? AND curriculum_version_subject_id=? AND record_state='active' FOR UPDATE",'iii',[$planId,$cloId,$placementId])[0] ?? null;
            if (!$row) throw new DomainNotFoundException('Active assessment plan not found for this CLO.');
            $fields = $this->planFields($input);
            if (array_filter($fields, static fn($value)=>$value!==null)===[]) throw new DomainValidationException('Record at least one planned assessment detail.');
            $this->repo->run("UPDATE curriculum_version_clo_assessment_plans SET assessment_method=?,planned_task=?,evidence_description=?,performance_indicator=?,rubric_or_standard=?,updated_by=?,review_state='awaiting_review' WHERE id=?",'sssssii',[...$fields,$actor,$planId]);
            audit_log_required('CURRICULUM_CLO_ASSESSMENT_UPDATE','curriculum_version_clo_assessment_plan',$planId,'Planned CLO assessment updated.',[
                'placement_id'=>$placementId,'curriculum_version_id'=>(int)$placement['curriculum_version_id'],'clo_id'=>$cloId,
                'plan_status'=>self::planStatus(array_combine(['assessment_method','planned_task','evidence_description','performance_indicator','rubric_or_standard'],$fields)),'actor_id'=>$actor]);
            return $planId;
        });
    }

    public function withdrawPlan(int $placementId, int $cloId, int $planId, int $actor): void
    {
        $this->mutate($placementId, $actor, function (array $placement) use ($placementId,$cloId,$planId,$actor): int {
            $this->activeClo($placementId,$cloId,true);
            $row = $this->repo->rows("SELECT id FROM curriculum_version_clo_assessment_plans WHERE id=? AND clo_id=? AND curriculum_version_subject_id=? AND record_state='active' FOR UPDATE",'iii',[$planId,$cloId,$placementId])[0] ?? null;
            if (!$row) throw new DomainNotFoundException('Active assessment plan not found for this CLO.');
            $this->repo->run("UPDATE curriculum_version_clo_assessment_plans SET record_state='withdrawn',withdrawn_by=?,withdrawn_at=CURRENT_TIMESTAMP,updated_by=? WHERE id=?",'iii',[$actor,$actor,$planId]);
            audit_log_required('CURRICULUM_CLO_ASSESSMENT_WITHDRAW','curriculum_version_clo_assessment_plan',$planId,'Planned CLO assessment withdrawn; history retained.',[
                'placement_id'=>$placementId,'curriculum_version_id'=>(int)$placement['curriculum_version_id'],'clo_id'=>$cloId,'actor_id'=>$actor]);
            return $planId;
        });
    }

    private function mutate(int $placementId, int $actor, callable $action): mixed
    {
        if ($placementId < 1 || $actor < 1) throw new DomainValidationException('An authenticated academic actor and valid placement are required.');
        if (audit_connection() !== $this->db) throw new RuntimeException('CLO audit must use the mutation connection.');
        $this->db->begin_transaction();
        try {
            // Global owner/version locks precede placement, parent mapping, CLO, alignment, plan, then audit writes.
            (new CurriculumVersionEditPolicy($this->db))->assertChildEditable('placement', $placementId);
            $placement = $this->repo->placement($placementId, true);
            if (!$placement) throw new DomainNotFoundException('Subject placement not found.');
            $result = $action($placement);
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            $this->db->rollback();
            if ($error instanceof mysqli_sql_exception && (int)$error->getCode() === 1062) {
                throw new DomainConflictException('CLO code, sequence, or alignment already exists in this placement.');
            }
            throw $error;
        }
    }

    private function activeClo(int $placementId, int $cloId, bool $forUpdate): array
    {
        $row = $this->repo->rows("SELECT * FROM curriculum_version_course_learning_outcomes WHERE id=? AND curriculum_version_subject_id=? AND record_state='active'".($forUpdate?' FOR UPDATE':''),'ii',[$cloId,$placementId])[0] ?? null;
        if (!$row) throw new DomainNotFoundException('Active CLO not found in this placement.');
        return $row;
    }

    private function uniqueSequence(int $placementId, int $sequence, ?int $except = null): void
    {
        $sql = 'SELECT id FROM curriculum_version_course_learning_outcomes WHERE curriculum_version_subject_id=? AND sequence_order=?';
        $types = 'ii'; $values = [$placementId,$sequence];
        if ($except !== null) { $sql .= ' AND id<>?'; $types .= 'i'; $values[] = $except; }
        if ($this->repo->rows($sql.' FOR UPDATE',$types,$values)) throw new DomainConflictException('CLO sequence already exists in this placement.');
    }

    private function requiredText(string $value, int $maxBytes, string $label): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxBytes) throw new DomainValidationException($label.' is required and must not exceed '.$maxBytes.' bytes.');
        return $value;
    }

    private function planFields(array $input): array
    {
        $fields = [];
        foreach (['assessment_method'=>180,'planned_task'=>12000,'evidence_description'=>12000,'performance_indicator'=>6000,'rubric_or_standard'=>12000] as $name=>$max) {
            $value = trim((string)($input[$name] ?? ''));
            if (strlen($value) > $max) throw new DomainValidationException('Assessment plan field '.$name.' exceeds its allowed length.');
            $fields[] = $value === '' ? null : $value;
        }
        return $fields;
    }
}
