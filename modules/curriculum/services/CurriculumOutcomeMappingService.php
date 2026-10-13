<?php

require_once __DIR__ . '/../validators/DomainExceptions.php';
require_once __DIR__ . '/../repositories/RepositoryQuery.php';
require_once __DIR__ . '/CurriculumVersionEditPolicy.php';
require_once __DIR__ . '/../../../shared/audit.php';

/** Manual, version-placement-scoped graduate outcome associations. */
final class CurriculumOutcomeMappingService
{
    private const SOURCE_KEY = 'CHED-CMO-25-2015-BSIT';

    public function __construct(private mysqli $db) {}

    private function rows(string $sql, string $types = '', array $values = []): array
    {
        $stmt = RepositoryQuery::execute($this->db, $sql, $types, $values);
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $result;
    }

    private function run(string $sql, string $types, array $values): int
    {
        $stmt = RepositoryQuery::execute($this->db, $sql, $types, $values);
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected;
    }

    /** One repeatable read snapshot for all version, placement, mapping, and coverage values. */
    public function workspace(int $versionId): array
    {
        if ($versionId < 1) throw new DomainValidationException('Invalid curriculum version.');
        $this->db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $context = $this->rows(
                'SELECT v.id version_id,v.version_label,v.version_role,v.review_status,v.status,v.baseline_version_id,
                        c.id curriculum_id,c.curriculum_name,c.academic_program_id,
                        p.program_code,p.program_name
                 FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id
                 JOIN academic_programs p ON p.id=c.academic_program_id WHERE v.id=?', 'i', [$versionId]
            )[0] ?? null;
            if (!$context) throw new DomainNotFoundException('Curriculum version not found.');
            if (strtoupper((string)$context['program_code']) !== 'BSIT') {
                throw new DomainValidationException('CHED CMO 25 BSIT outcomes are available only for BSIT curriculum versions.');
            }
            $outcomes = $this->rows(
                'SELECT o.*,COALESCE(mc.mapping_count,0) mapping_count,
                        c.assessment_state,c.rationale coverage_rationale
                 FROM curriculum_outcome_catalog o
                 LEFT JOIN curriculum_version_outcome_coverage c ON c.outcome_id=o.id AND c.curriculum_version_id=?
                 LEFT JOIN (SELECT m.outcome_id,COUNT(*) mapping_count
                   FROM curriculum_version_subject_outcomes m JOIN curriculum_version_subjects p ON p.id=m.curriculum_version_subject_id
                   WHERE p.curriculum_version_id=? GROUP BY m.outcome_id) mc ON mc.outcome_id=o.id
                 WHERE o.source_key=? ORDER BY o.source_order', 'iis', [$versionId, $versionId, self::SOURCE_KEY]
            );
            $placements = $this->rows(
                'SELECT p.id placement_id,p.subject_id,p.year_level,p.semester,p.sequence_order,p.is_required,
                        s.subject_code,s.subject_title,s.units,s.lecture_units,s.laboratory_units
                 FROM curriculum_version_subjects p JOIN subjects s ON s.id=p.subject_id
                 WHERE p.curriculum_version_id=? ORDER BY p.year_level,p.semester,p.sequence_order,s.subject_code,p.id', 'i', [$versionId]
            );
            $mappings = $this->rows(
                'SELECT m.id,m.curriculum_version_subject_id placement_id,m.outcome_id,m.rationale,m.mapping_origin,
                        m.inherited_from_mapping_id,m.created_by,m.created_at,o.outcome_code,s.subject_code,s.subject_title,
                        p.year_level,p.semester,p.sequence_order
                 FROM curriculum_version_subject_outcomes m
                 JOIN curriculum_version_subjects p ON p.id=m.curriculum_version_subject_id AND p.curriculum_version_id=?
                 JOIN subjects s ON s.id=p.subject_id
                 JOIN curriculum_outcome_catalog o ON o.id=m.outcome_id AND o.source_key=?
                 ORDER BY o.source_order,p.year_level,p.semester,p.sequence_order,p.id', 'is', [$versionId, self::SOURCE_KEY]
            );
            foreach ($outcomes as &$outcome) {
                $count = (int)$outcome['mapping_count'];
                $outcome['mapping_count'] = $count;
                $outcome['coverage_status'] = $outcome['assessment_state'] === null
                    ? 'not_assessed'
                    : ($outcome['assessment_state'] === 'incomplete' ? 'incomplete' : ($count > 0 ? 'mapped' : 'unmapped'));
            }
            unset($outcome);
            $this->db->commit();
            $context['editable']=CurriculumVersionEditPolicy::isEditable($context);
            return ['context'=>$context,'outcomes'=>$outcomes,'placements'=>$placements,'mappings'=>$mappings,
                'limitations'=>['authenticity'=>'The supplied scan is fingerprinted; independent registry validation has not been performed.',
                    'applicability'=>'No conclusion about current applicability, amendments, or cohort coverage is made.',
                    'institutional_adoption'=>'Institutional adoption/approval not verified',
                    'mapping'=>'Curriculum mapping does not establish student outcome attainment, regulatory compliance, or institutional approval.']];
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }

    public function add(int $versionId, int $placementId, int $outcomeId, string $rationale, int $actor): int
    {
        return $this->mutate($versionId, $placementId, $outcomeId, $actor, function(array $context) use ($versionId,$placementId,$outcomeId,$rationale,$actor): int {
            $text = trim($rationale);
            if ($text === '' || strlen($text) > 3000) throw new DomainValidationException('Mapping rationale is required and must be at most 3000 bytes.');
            $this->assertOutcome($outcomeId);
            $this->run('INSERT INTO curriculum_version_subject_outcomes (curriculum_version_subject_id,outcome_id,rationale,mapping_origin,created_by) VALUES (?,?,?,\'manual\',?)','iisi',[$placementId,$outcomeId,$text,$actor]);
            $id = (int)$this->db->insert_id;
            $this->invalidateCoverage($versionId,$outcomeId,$actor);
            audit_log_required('CURRICULUM_OUTCOME_MAPPING_CREATE','curriculum_version_subject_outcome',$id,
                'Manual subject-to-outcome mapping recorded.',['curriculum_version_id'=>(int)$context['id'],'placement_id'=>$placementId,'outcome_id'=>$outcomeId,'actor_id'=>$actor]);
            return $id;
        });
    }

    public function remove(int $versionId, int $mappingId, int $actor): void
    {
        $this->assertAuditConnection();
        $this->assertActor($actor);
        $this->db->begin_transaction();
        try {
            $context = (new CurriculumVersionEditPolicy($this->db))->assertEditable($versionId);
            $row = $this->rows('SELECT m.* FROM curriculum_version_subject_outcomes m JOIN curriculum_version_subjects p ON p.id=m.curriculum_version_subject_id WHERE m.id=? AND p.curriculum_version_id=? FOR UPDATE','ii',[$mappingId,$versionId])[0] ?? null;
            if (!$row) throw new DomainNotFoundException('Outcome mapping not found in this curriculum version.');
            $this->assertBsitVersion($versionId);
            $this->run('DELETE FROM curriculum_version_subject_outcomes WHERE id=?','i',[$mappingId]);
            $this->invalidateCoverage($versionId,(int)$row['outcome_id'],$actor);
            audit_log_required('CURRICULUM_OUTCOME_MAPPING_REMOVE','curriculum_version_subject_outcome',$mappingId,
                'Manual subject-to-outcome mapping removed.',['curriculum_version_id'=>$versionId,'placement_id'=>(int)$row['curriculum_version_subject_id'],'outcome_id'=>(int)$row['outcome_id'],'actor_id'=>$actor]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }

    public function assess(int $versionId, int $outcomeId, string $state, ?string $rationale, int $actor): void
    {
        $this->assertAuditConnection();
        $this->assertActor($actor);
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertEditable($versionId);
            $this->assertBsitVersion($versionId); $this->assertOutcome($outcomeId);
            if (!in_array($state,['incomplete','complete'],true)) throw new DomainValidationException('Invalid coverage assessment state.');
            $note = trim((string)$rationale);
            if ($note !== '' && strlen($note)>3000) throw new DomainValidationException('Coverage note must be at most 3000 bytes.');
            $existing = $this->rows('SELECT * FROM curriculum_version_outcome_coverage WHERE curriculum_version_id=? AND outcome_id=? FOR UPDATE','ii',[$versionId,$outcomeId])[0]??null;
            if ($existing) {
                $this->run('UPDATE curriculum_version_outcome_coverage SET assessment_state=?,rationale=?,updated_by=? WHERE id=?','ssii',[$state,$note===''?null:$note,$actor,(int)$existing['id']]);
                $id=(int)$existing['id']; $action='CURRICULUM_OUTCOME_COVERAGE_UPDATE';
            } else {
                $this->run('INSERT INTO curriculum_version_outcome_coverage (curriculum_version_id,outcome_id,assessment_state,rationale,recorded_by) VALUES (?,?,?,?,?)','iissi',[$versionId,$outcomeId,$state,$note===''?null:$note,$actor]);
                $id=(int)$this->db->insert_id; $action='CURRICULUM_OUTCOME_COVERAGE_CREATE';
            }
            audit_log_required($action,'curriculum_version_outcome_coverage',$id,'Outcome coverage assessment recorded.',[
                'curriculum_version_id'=>$versionId,'outcome_id'=>$outcomeId,'assessment_state'=>$state,'actor_id'=>$actor]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }

    private function mutate(int $versionId,int $placementId,int $outcomeId,int $actor,callable $action): int
    {
        $this->assertAuditConnection();
        $this->assertActor($actor);
        $this->db->begin_transaction();
        try {
            $context=(new CurriculumVersionEditPolicy($this->db))->assertEditable($versionId);
            $this->assertBsitVersion($versionId);
            $placement=$this->rows('SELECT id FROM curriculum_version_subjects WHERE id=? AND curriculum_version_id=? FOR UPDATE','ii',[$placementId,$versionId]);
            if (!$placement) throw new DomainNotFoundException('Subject placement does not belong to this curriculum version.');
            $id=$action($context);
            $this->db->commit(); return $id;
        } catch (Throwable $e) { $this->db->rollback();
            if ($e instanceof mysqli_sql_exception && (int)$e->getCode()===1062) throw new DomainConflictException('This subject is already mapped to the selected outcome.');
            throw $e;
        }
    }

    private function assertAuditConnection(): void
    {
        if (audit_connection() !== $this->db) throw new RuntimeException('Outcome mapping audit must use the mutation connection.');
    }

    private function assertActor(int $actor): void
    {
        if ($actor < 1) throw new DomainValidationException('An authenticated mapping actor is required.');
    }

    private function assertBsitVersion(int $versionId): void
    {
        $row=$this->rows('SELECT p.program_code FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id JOIN academic_programs p ON p.id=c.academic_program_id WHERE v.id=? FOR UPDATE','i',[$versionId])[0]??null;
        if (!$row) throw new DomainNotFoundException('Curriculum version not found.');
        if (strtoupper((string)$row['program_code'])!=='BSIT') throw new DomainValidationException('CHED CMO 25 BSIT outcomes cannot be applied to another program.');
    }

    private function assertOutcome(int $outcomeId): void
    {
        if (!$this->rows('SELECT id FROM curriculum_outcome_catalog WHERE id=? AND source_key=? FOR UPDATE','is',[$outcomeId,self::SOURCE_KEY])) throw new DomainNotFoundException('BSIT outcome catalog record not found.');
    }

    private function invalidateCoverage(int $versionId,int $outcomeId,int $actor): void
    {
        $row=$this->rows('SELECT id,assessment_state FROM curriculum_version_outcome_coverage WHERE curriculum_version_id=? AND outcome_id=? FOR UPDATE','ii',[$versionId,$outcomeId])[0]??null;
        if ($row && $row['assessment_state']==='complete') {
            $this->run("UPDATE curriculum_version_outcome_coverage SET assessment_state='incomplete',rationale=CONCAT(COALESCE(rationale,''),CASE WHEN COALESCE(rationale,'')='' THEN '' ELSE '\\n' END,'Mapping changed; coverage review must be repeated.'),updated_by=? WHERE id=?",'ii',[$actor,(int)$row['id']]);
            audit_log_required('CURRICULUM_OUTCOME_COVERAGE_INVALIDATE','curriculum_version_outcome_coverage',(int)$row['id'],
                'Coverage review marked incomplete after a mapping change.',['curriculum_version_id'=>$versionId,'outcome_id'=>$outcomeId,'actor_id'=>$actor]);
        }
    }
}
