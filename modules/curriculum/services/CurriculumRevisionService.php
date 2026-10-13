<?php
require_once __DIR__ . '/CurriculumVersionReferenceService.php';
require_once __DIR__ . '/PrerequisiteGraph.php';
require_once __DIR__ . '/../repositories/CurriculumRevisionRepository.php';

/** A single atomic copy; never calls public services that commit independently. */
final class CurriculumRevisionService
{
    private CurriculumRevisionRepository $repo;
    public function __construct(private mysqli $db) { $this->repo=new CurriculumRevisionRepository($db); }

    public function preview(int $sourceId, int $curriculumId): array
    {
        $this->db->begin_transaction();
        try {
            $source=$this->lockAndValidate($sourceId,$curriculumId);
            $this->db->commit();
            return ['source_version_id'=>$sourceId,'curriculum_id'=>$curriculumId,'counts'=>$source['counts'],
                'source_label'=>$source['version']['version_label'],
                'notice'=>'Shared Subject Master identities are reused. No review, approval, personnel or validation history is copied. Institutional adoption/approval not verified.'];
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }

    public function create(array $input, int $actor): array
    {
        $sourceId=DomainValidator::positiveId($input['source_version_id']??null,'Reference version');
        // Only these metadata fields are accepted. Lifecycle/baseline fields are server-owned.
        $data=DomainValidator::curriculumVersion(array_intersect_key($input,array_flip([
            'curriculum_id','version_label','effective_academic_year','description'])));
        if (DomainValidator::boolean($input['confirm_reference_unchanged']??null,'Reference preservation confirmation')!==1) {
            throw new DomainValidationException('Confirm that the reference remains unchanged.');
        }
        if (audit_connection() !== $this->db) throw new RuntimeException('Revision audit must use the mutation connection.');
        $this->db->begin_transaction();
        try {
            $source=$this->lockAndValidate($sourceId,$data['curriculum_id']);
            if ($this->repo->rows('SELECT id FROM curriculum_versions WHERE curriculum_id=? AND version_label=? FOR UPDATE','is',[$data['curriculum_id'],$data['version_label']])) {
                throw new DomainConflictException('Version label already exists for this curriculum.');
            }
            $id=$this->repo->createVersion($data,$sourceId,$actor);
            $maps=['placements'=>[],'groups'=>[],'schemes'=>[],'outcomes'=>[],'clos'=>[]];
            foreach ($source['placements'] as $p) {
                $new=$this->repo->copy('placement',$p,['curriculum_version_id'=>$id,'created_by'=>$actor]);
                $maps['placements'][(int)$p['id']]=$new;
                $this->repo->cloneSnapshot((int)$p['id'],$new);
                audit_log_required('CURRICULUM_REVISION_PLACEMENT_COPY','curriculum_version_subject',$new,
                    'Creation-time snapshot copied from the immutable reference placement.',[
                        'source_version_id'=>$sourceId,'source_placement_id'=>(int)$p['id'],
                        'curriculum_version_id'=>$id,'subject_id'=>(int)$p['subject_id'],'capture_method'=>'clone']);
            }
            // These two relationship tables use composite version/subject FKs, not placement IDs.
            foreach ($source['requirements'] as $row) $this->repo->copy('requirement',$row,['curriculum_version_id'=>$id,'created_by'=>$actor]);
            foreach ($source['groups'] as $row) $maps['groups'][(int)$row['id']]=$this->repo->copy('group',$row,['curriculum_version_id'=>$id,'created_by'=>$actor]);
            foreach ($source['memberships'] as $row) $this->repo->copy('membership',$row,[
                'curriculum_version_id'=>$id,'elective_group_id'=>$maps['groups'][(int)$row['elective_group_id']],'created_by'=>$actor]);
            foreach ($source['schemes'] as $row) $maps['schemes'][(int)$row['id']]=$this->repo->copy('scheme',$row,[
                'curriculum_version_subject_id'=>$maps['placements'][(int)$row['curriculum_version_subject_id']],'created_by'=>$actor]);
            foreach ($source['components'] as $row) $this->repo->copy('component',$row,[
                'scheme_id'=>$maps['schemes'][(int)$row['scheme_id']],'created_by'=>$actor]);
            foreach ($source['outcomes'] as $row) {
                $newMapping=$this->repo->copy('outcome_mapping',$row,[
                    'curriculum_version_subject_id'=>$maps['placements'][(int)$row['curriculum_version_subject_id']],
                    'mapping_origin'=>'inherited','inherited_from_mapping_id'=>(int)$row['id'],'created_by'=>$actor]);
                $maps['outcomes'][(int)$row['id']]=$newMapping;
            }
            foreach ($source['clos'] as $row) {
                $maps['clos'][(int)$row['id']]=$this->repo->copy('clo',$row,[
                    'curriculum_version_subject_id'=>$maps['placements'][(int)$row['curriculum_version_subject_id']],
                    'origin'=>'inherited','source_clo_id'=>(int)$row['id'],'review_state'=>'awaiting_review','created_by'=>$actor]);
            }
            foreach ($source['clo_alignments'] as $row) {
                if (!isset($maps['clos'][(int)$row['clo_id']],$maps['outcomes'][(int)$row['parent_mapping_id']])) continue;
                $this->repo->copy('clo_alignment',$row,[
                    'clo_id'=>$maps['clos'][(int)$row['clo_id']],
                    'curriculum_version_subject_id'=>$maps['placements'][(int)$row['curriculum_version_subject_id']],
                    'parent_mapping_id'=>$maps['outcomes'][(int)$row['parent_mapping_id']],
                    'source_alignment_id'=>(int)$row['id'],'origin'=>'inherited','review_state'=>'awaiting_review','created_by'=>$actor]);
            }
            foreach ($source['clo_plans'] as $row) {
                if (!isset($maps['clos'][(int)$row['clo_id']])) continue;
                $this->repo->copy('clo_plan',$row,[
                    'clo_id'=>$maps['clos'][(int)$row['clo_id']],
                    'curriculum_version_subject_id'=>$maps['placements'][(int)$row['curriculum_version_subject_id']],
                    'source_plan_id'=>(int)$row['id'],'origin'=>'inherited','review_state'=>'awaiting_review','created_by'=>$actor]);
            }
            audit_log_required('CURRICULUM_REVISION_CREATE','curriculum_version',$id,
                'Editable curriculum revision created from a protected reference.',[
                    'source_version_id'=>$sourceId,'curriculum_id'=>$data['curriculum_id'],
                    'version_label'=>$data['version_label'],'actor_id'=>$actor,'counts'=>$source['counts'],
                    'group_id_map'=>$maps['groups'],'scheme_id_map'=>$maps['schemes'],
                    'inherited_outcome_mapping_count'=>count($maps['outcomes']),
                    'inherited_clo_count'=>count($maps['clos']),
                    'inherited_clo_alignment_count'=>count($source['clo_alignments']),
                    'inherited_assessment_plan_count'=>count($source['clo_plans']),
                    'institutional_adoption'=>'not_verified']);
            $result=$this->repo->rows('SELECT * FROM curriculum_versions WHERE id=? FOR UPDATE','i',[$id])[0];
            $this->db->commit();
            return ['version'=>$result,'counts'=>$source['counts']];
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($e instanceof mysqli_sql_exception && (int)$e->getCode()===1062) throw new DomainConflictException('Version label or copied structure conflicts with existing data.');
            throw $e;
        }
    }

    private function lockAndValidate(int $id, int $curriculum): array
    {
        // Discovery only: after any wait, all data used for validation is re-read FOR UPDATE.
        $hints=$this->repo->links($id,false);
        $placementHints=$this->repo->placements($id,false);
        $sourceIds=array_values(array_unique(array_map('intval',array_column($hints,'compliance_reference_id')))); sort($sourceIds,SORT_NUMERIC);
        $sources=[]; $sourceRepo=new ComplianceReferenceRepository($this->db);
        foreach ($sourceIds as $sourceId) {
            $sources[$sourceId]=$sourceRepo->findByIdForUpdate($sourceId);
            if (!$sources[$sourceId]) throw new DomainConflictException('A documentary source is missing.');
        }
        $subjectIds=array_values(array_unique(array_map('intval',array_column($placementHints,'subject_id')))); sort($subjectIds,SORT_NUMERIC);
        $policy=new CurriculumVersionEditPolicy($this->db);
        foreach ($subjectIds as $subject) $policy->lockSubject($subject);
        $version=$policy->loadVersion($id);
        // Technical archival is independent of academic protection; archive is not an approval.
        // A reference in any valid technical state remains an immutable source.
        if ((int)$version['curriculum_id']!==$curriculum || $version['version_role']!=='reference'
            || $version['baseline_version_id']!==null
            || !in_array($version['review_status'],['not_started','draft','under_review','returned','approved'],true)
            || !in_array($version['status'],['draft','active','archived'],true)) {
            throw new DomainConflictException('Select a protected reference in the same curriculum with a valid lifecycle.');
        }
        $links=$this->repo->links($id);
        $placements=$this->repo->placements($id);
        if ($links!==$hints || $placements!==$placementHints) throw new DomainConflictException('Reference context changed while waiting. Reload and retry.');
        if (!$placements) throw new DomainConflictException('Reference has no subject placements.');
        $primary=[];
        $referenceService=new CurriculumVersionReferenceService($this->db);
        foreach ($links as $link) {
            $source=$sources[(int)$link['compliance_reference_id']];
            if ($source['verification_status']==='inactive') throw new DomainConflictException('A documentary source is inactive or withdrawn.');
            foreach (['reference_code'=>'reference_code_snapshot','title'=>'reference_title_snapshot',
                'source_kind'=>'source_kind_snapshot','issuing_body'=>'issuing_body_snapshot',
                'source_url'=>'reference_url_snapshot','effective_date'=>'effective_date_snapshot'] as $field=>$snapshot) {
                if ($source[$field]!==$link[$snapshot]) throw new DomainConflictException('Documentary source changed since attachment; reference evidence was preserved.');
            }
            // Reject a recorded stale assessment, even for contextual documents. Missing contextual
            // assessments remain honestly unassessed; the primary source must have identity evidence.
            $seen=[];
            foreach ($sourceRepo->evidenceEvents((int)$source['id'],true) as $event) {
                if (isset($seen[$event['verification_subject']])) continue;
                $seen[$event['verification_subject']]=true;
                foreach (['reference_code','title','source_kind','issuing_body','source_url','effective_date'] as $field) {
                    if ($event[$field.'_snapshot']!==$source[$field]) throw new DomainConflictException('Document assessment is stale for current source metadata.');
                }
            }
            if ($link['reference_purpose']==='school_curriculum') {
                $basis=['ched_sample_curriculum'=>'ched_sample','institutional_curriculum'=>'institutional_source',
                    'other_document'=>'other_document','supporting_document'=>'other_document'][$source['source_kind']]??null;
                if ($basis===null) throw new DomainConflictException('Primary source is not a curriculum source.');
                $referenceService->assertClassificationEvidence((int)$source['id'],$source,$basis);
                $primary[(int)$source['id']]=true;
            }
        }
        if (!$primary) throw new DomainConflictException('Reference has no traceable curriculum source.');
        $refRepo=new CurriculumVersionReferenceRepository($this->db);$bySubject=[];
        foreach ($placements as $p) {
            $snapshot=$refRepo->snapshot((int)$p['id'],true);
            $subject=$this->repo->rows('SELECT * FROM subjects WHERE id=? FOR UPDATE','i',[(int)$p['subject_id']])[0]??null;
            if (!$snapshot || (int)$snapshot['snapshot_complete']!==1 || !isset($primary[(int)$snapshot['source_reference_id']]) || !$subject
                || trim((string)$snapshot['subject_code_snapshot'])==='' || trim((string)$snapshot['subject_title_snapshot'])==='') {
                throw new DomainConflictException('Reference snapshots are missing, incomplete or have conflicting provenance.');
            }
            foreach (['subject_code','subject_title','units','lecture_units','laboratory_units','description'] as $field) {
                if ($snapshot[$field.'_snapshot']!==$subject[$field]) throw new DomainConflictException('Reference subject snapshot conflicts with current Subject Master. A faithful editable revision cannot be created.');
            }
            if (isset($bySubject[(int)$p['subject_id']]) || !in_array((int)$p['is_required'],[0,1],true)) throw new DomainConflictException('Invalid reference placement.');
            $bySubject[(int)$p['subject_id']]=$p+['subject_status'=>$subject['status']];
        }
        $structures=$this->repo->structures($id);
        $prerequisites=[];$pairs=[];
        foreach ($structures['requirements'] as $edge) {
            $a=(int)$edge['subject_id'];$b=(int)$edge['required_subject_id'];$type=$edge['requirement_type'];
            $key="$a:$b:$type";
            if (!isset($bySubject[$a],$bySubject[$b]) || $a===$b || isset($pairs[$key]) || !in_array($type,['prerequisite','corequisite'],true)) throw new DomainConflictException('Invalid reference requirement ownership.');
            $pairs[$key]=true;
            if ($type==='prerequisite') $prerequisites[]=$edge;
        }
        $review=PrerequisiteGraph::review($placements,$prerequisites);
        foreach ($review as $issues) if ($issues) throw new DomainConflictException('Reference prerequisites contain invalid sequencing, missing placements, duplicates or cycles.');
        $groups=array_column($structures['groups'],null,'id');$members=[];
        foreach ($structures['memberships'] as $row) {
            $g=$groups[(int)$row['elective_group_id']]??null;$p=$bySubject[(int)$row['subject_id']]??null;
            if (!$g || !$p || (int)$row['curriculum_version_id']!==$id || !$g['year_level'] || !$g['semester']
                || $p['year_level']!==$g['year_level'] || $p['semester']!==$g['semester'] || $p['subject_status']!=='active'
                || isset($members[(int)$g['id']][(int)$row['subject_id']])) throw new DomainConflictException('Invalid elective membership or placement context.');
            $members[(int)$g['id']][(int)$row['subject_id']]=true;
        }
        foreach ($groups as $g) if (!in_array($g['status'],['active','inactive','archived'],true)
            || trim($g['group_name'])==='' || count($members[(int)$g['id']]??[])<2
            || (int)$g['required_selections']<1 || (int)$g['required_selections']>count($members[(int)$g['id']]??[])) throw new DomainConflictException('Invalid elective selection configuration.');
        $totals=[];
        foreach ($structures['components'] as $component) {
            $weight=$component['weight_percentage'];
            if (trim($component['component_name'])==='') throw new DomainConflictException('Reference weighting component name is missing.');
            if (!preg_match('/^\d{1,3}\.\d{2}$/D',$weight)) throw new DomainConflictException('Invalid reference weighting precision.');
            [$whole,$fraction]=explode('.',$weight);$cents=(int)$whole*100+(int)$fraction;
            if ($cents<1 || $cents>10000) throw new DomainConflictException('Invalid reference component weight.');
            $scheme=(int)$component['scheme_id'];$totals[$scheme]=($totals[$scheme]??0)+$cents;
        }
        // Incomplete schemes are supported by existing incremental component editing (0..100%).
        foreach ($totals as $total) if ($total>10000) throw new DomainConflictException('Reference component weights exceed 100%.');
        $counts=array_map('count',$structures)+['placements'=>count($placements),'snapshots'=>count($placements)];
        return $structures+compact('version','placements','counts');
    }
}
