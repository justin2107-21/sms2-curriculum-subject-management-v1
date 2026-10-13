<?php

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/AcademicProgramRepository.php';
require_once __DIR__ . '/../repositories/CurriculumRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionRepository.php';
require_once __DIR__ . '/../repositories/SubjectRepository.php';
require_once __DIR__ . '/../repositories/SubjectOfferingHistoryRepository.php';
require_once __DIR__ . '/CurriculumHistoryComparison.php';
require_once __DIR__ . '/CurriculumVersionReferenceService.php';

class SubjectOfferingHistoryService
{
    private AcademicProgramRepository $programs;
    private CurriculumRepository $curricula;
    private CurriculumVersionRepository $versions;
    private SubjectOfferingHistoryRepository $history;
    private SubjectRepository $subjects;

    public function __construct(private mysqli $db)
    {
        $this->programs = new AcademicProgramRepository($db);
        $this->curricula = new CurriculumRepository($db);
        $this->versions = new CurriculumVersionRepository($db);
        $this->history = new SubjectOfferingHistoryRepository($db);
        $this->subjects = new SubjectRepository($db);
    }

    public function listHistory(array $input): array
    {
        $query = DomainValidator::subjectHistoryQuery($input);
        $result = $this->history->paginate($query);
        return [
            'history' => $result['records'],
            'pagination' => $result['pagination'],
            'summary' => $this->integerSummary($result['summary']),
            'filter_values' => $this->history->filterValues($query),
        ];
    }

    public function listSubjects(array $input): array
    {
        $query = DomainValidator::listQuery($input, ['active', 'inactive']);
        $result = $this->subjects->paginate($query);
        return ['subjects' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function historyDetails(int $placementId): array
    {
        $record = $this->history->findPlacement($placementId);
        if (!$record) throw new DomainNotFoundException('Stored subject placement not found.');
        return ['record' => $record];
    }

    public function listPrograms(array $input): array
    {
        $query = DomainValidator::listQuery($input, ['active', 'inactive']);
        $result = $this->programs->paginate($query);
        return ['programs' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function listCurricula(?int $programId, array $input): array
    {
        if ($programId !== null && !$this->programs->findById($programId)) {
            throw new DomainNotFoundException('Academic program not found.');
        }
        $query = DomainValidator::listQuery($input, ['active', 'inactive'], true);
        $query['academic_program_id'] = $programId;
        $result = $this->curricula->paginate($query);
        return ['curricula' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function listVersions(int $curriculumId, array $input): array
    {
        if (!$this->curricula->findById($curriculumId)) {
            throw new DomainNotFoundException('Curriculum not found.');
        }
        $query = DomainValidator::listQuery($input, ['draft', 'active', 'archived']);
        $result = $this->versions->paginateByCurriculum($curriculumId, $query);
        return ['versions' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function compareVersions(array $input): array
    {
        $query = DomainValidator::subjectHistoryComparisonQuery($input);
        $curriculum = $this->curricula->findById($query['curriculum_id']);
        if (!$curriculum) {
            throw new DomainNotFoundException('Curriculum not found.');
        }
        $versionA = $this->versions->findById($query['version_a_id']);
        $versionB = $this->versions->findById($query['version_b_id']);
        if (!$versionA || !$versionB) {
            throw new DomainNotFoundException('One or both curriculum versions were not found.');
        }
        if ($query['version_a_id'] === $query['version_b_id']) {
            throw new DomainValidationException('Select two different curriculum versions.');
        }
        if ((int)$versionA['curriculum_id'] !== $query['curriculum_id']
            || (int)$versionB['curriculum_id'] !== $query['curriculum_id']) {
            throw new DomainValidationException(
                'Both curriculum versions must belong to the selected curriculum.'
            );
        }

        $result = $this->history->paginateComparison(
            $query['version_a_id'],
            $query['version_b_id'],
            $query
        );
        return [
            'curriculum' => [
                'id' => (int)$curriculum['id'],
                'curriculum_code' => $curriculum['curriculum_code'],
                'curriculum_name' => $curriculum['curriculum_name'],
            ],
            'version_a' => $this->versionContext($versionA),
            'version_b' => $this->versionContext($versionB),
            'comparison' => $result['records'],
            'pagination' => $result['pagination'],
            'summary' => $this->integerSummary($result['summary']),
        ];
    }

    /** Task 5 comparison. The older generic history endpoint remains a separate compatibility read. */
    public function compareRevision(array $input): array
    {
        $curriculumId=DomainValidator::positiveId($input['curriculum_id']??null,'Curriculum');
        $revisionId=DomainValidator::positiveId($input['version_b_id']??null,'Revision version');
        $referenceId=DomainValidator::optionalPositiveId($input['version_a_id']??null,'Reference version');
        $query=DomainValidator::listQuery($input,[]);
        if ((int)$this->db->query('SELECT @@in_transaction')->fetch_row()[0]!==0) {
            throw new RuntimeException('History comparison requires its own read-only transaction.');
        }
        // Next transaction only. No session/global isolation change and no academic write locks.
        $this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $curriculum=$this->curricula->findById($curriculumId);
            $revision=$this->versions->findById($revisionId);
            if (!$curriculum || !$revision) throw new DomainNotFoundException('Curriculum or revision not found.');
            if ($revision['version_role']!=='proposed' || $revision['baseline_version_id']===null
                || (int)$revision['curriculum_id']!==$curriculumId) {
                throw new DomainValidationException('Select a proposed revision with a recorded baseline in this curriculum.');
            }
            $referenceId=$referenceId??(int)$revision['baseline_version_id'];
            $reference=$this->versions->findById($referenceId);
            if (!$reference) throw new DomainNotFoundException('Reference version not found.');
            if ($reference['version_role']!=='reference' || $reference['baseline_version_id']!==null
                || (int)$reference['curriculum_id']!==$curriculumId || $referenceId===$revisionId
                || (int)$revision['baseline_version_id']!==$referenceId) {
                throw new DomainValidationException('Reference must be the revision\'s exact protected baseline in the same curriculum.');
            }
            foreach ([$reference,$revision] as $version) {
                if (!in_array($version['status'],['draft','active','archived'],true)
                    || !in_array($version['review_status'],['not_started','draft','under_review','returned','approved'],true)) {
                    throw new DomainValidationException('Invalid version lifecycle.');
                }
            }
            // History has always allowed archived versions/owners by explicit ID; keep that read visibility.
            $workspace=(new CurriculumVersionReferenceService($this->db))->workspace($referenceId);
            $sources=[];
            foreach ($workspace['references'] as $link) if ($link['reference_purpose']==='school_curriculum'
                && in_array($link['source_kind_snapshot'],['ched_sample_curriculum','institutional_curriculum','other_document','supporting_document'],true)) {
                $sources[]=(int)$link['compliance_reference_id'];
            }
            $referenceState=$this->history->comparisonState($referenceId);
            $revisionState=$this->history->comparisonState($revisionId);
            $result=CurriculumHistoryComparison::calculate($referenceState,$revisionState,$sources,$referenceId);
            $result['clo_assessment']=$this->compareCloAssessment($referenceState,$revisionState);
            if (!$result['clo_assessment']['complete']) {
                $result['partial']=true;
                $result['warnings'][]='CLO alignment and planned assessment comparison has records without reliable clone lineage.';
            }
            if (!$referenceState['placements']) {$result['partial']=true;$result['warnings'][]='Historical subject details unavailable: reference has no recorded placements.';}
            if (!$sources) {$result['partial']=true;$result['warnings'][]='Reference documentary provenance is unavailable.';}
            $pagination=RepositoryQuery::pagination(count($result['comparison']),$query['page'],$query['per_page']);
            $result['comparison']=array_slice($result['comparison'],$pagination['offset'],$pagination['per_page']);
            unset($pagination['offset']);
            $result+=['pagination'=>$pagination,'curriculum'=>$curriculum,'program'=>$this->programs->findById((int)$curriculum['academic_program_id']),'version_a'=>$reference,'version_b'=>$revision,
                'references'=>$workspace['references'],'institutional_adoption_label'=>$workspace['institutional_adoption_label'],
                'read_view'=>'Single read-only REPEATABLE READ consistent snapshot; no lifecycle or audit writes.'];
            $this->db->commit();
            return $result;
        } catch (Throwable $error) { $this->db->rollback(); throw $error; }
    }

    /** Compare only recorded CLO lineage and normalized outcome/business fields. */
    private function compareCloAssessment(array $before,array $after): array
    {
        $dimensions=[];$complete=true;
        foreach ([
            'clos'=>['source_clo_id',['clo_code','clo_statement','sequence_order','record_state']],
            'clo_alignments'=>['source_alignment_id',['outcome_id','outcome_code','rationale','record_state']],
            'clo_plans'=>['source_plan_id',['assessment_method','planned_task','evidence_description','performance_indicator','rubric_or_standard','record_state']],
        ] as $name=>[$lineage,$fields]) {
            $left=[];$right=[];
            foreach ($before[$name]??[] as $row) $left['id:'.(int)$row['id']]=$row;
            foreach ($after[$name]??[] as $row) {
                if (($row[$lineage]??null)!==null) $key='id:'.(int)$row[$lineage];
                else { $key='new:'.(int)$row['id']; if (($row['origin']??'manual')==='inherited') $complete=false; }
                $right[$key]=$row;
            }
            $changes=[];
            foreach (array_unique(array_merge(array_keys($left),array_keys($right))) as $key) {
                $a=$left[$key]??null;$b=$right[$key]??null;
                $record=['change'=>$a===null?'added':($b===null?'removed':'unchanged'),'before'=>$a,'after'=>$b,'differences'=>[]];
                if ($a && $b) foreach ($fields as $field) if ((string)($a[$field]??'')!==(string)($b[$field]??'')) $record['differences'][$field]=['before'=>$a[$field]??null,'after'=>$b[$field]??null];
                if ($record['differences']) $record['change']='changed';
                if ($record['change']!=='unchanged') $changes[]=$record;
            }
            $dimensions[$name]=$changes;
        }
        return ['complete'=>$complete,'changes'=>$dimensions,'coverage'=>'CLO, alignment and planned assessment records only; no attainment is represented.'];
    }

    private function versionContext(array $version): array
    {
        return [
            'id' => (int)$version['id'],
            'version_label' => $version['version_label'],
            'effective_academic_year' => $version['effective_academic_year'],
            'status' => $version['status'],
            'created_at' => $version['created_at'],
            'updated_at' => $version['updated_at'],
        ];
    }

    private function integerSummary(array $summary): array
    {
        foreach (['stored_placements', 'programs', 'curricula', 'curriculum_versions',
                  'added', 'removed', 'moved', 'placement_type_changed', 'changed', 'unchanged',
                  'version_a_subjects', 'version_b_subjects', 'version_a_captured_subjects',
                  'version_b_captured_subjects'] as $key) {
            if (array_key_exists($key, $summary)) {
                $summary[$key] = (int)$summary[$key];
            }
        }
        foreach (['version_a_units', 'version_b_units'] as $key) {
            if (array_key_exists($key, $summary) && $summary[$key] !== null) $summary[$key] = (float)$summary[$key];
        }
        return $summary;
    }
}
