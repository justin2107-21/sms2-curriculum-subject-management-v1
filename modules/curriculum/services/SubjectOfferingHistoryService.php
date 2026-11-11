<?php

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/AcademicProgramRepository.php';
require_once __DIR__ . '/../repositories/CurriculumRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionRepository.php';
require_once __DIR__ . '/../repositories/SubjectRepository.php';
require_once __DIR__ . '/../repositories/SubjectOfferingHistoryRepository.php';
require_once __DIR__ . '/CurriculumReadScope.php';
require_once __DIR__ . '/ElectivesWorkspaceService.php';
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

    /** Subjects placed in the selected version (server-side paging and search). */
    public function versionSubjects(array $input): array
    {
        $versionId = DomainValidator::positiveId($input['version_id'] ?? null, 'Curriculum version');
        $query = DomainValidator::listQuery($input, []);
        $version = $this->versions->findById($versionId);
        if (!$version) throw new DomainNotFoundException('Curriculum version not found.');
        $this->assertReadable($version);
        $curriculum = $this->curricula->findById((int)$version['curriculum_id']);
        $result = $this->history->paginateVersionSubjects($versionId, (int)$curriculum['academic_program_id'], $query);
        foreach ($result['records'] as &$row) {
            foreach (['placement_id', 'subject_id', 'snapshot_complete', 'version_count', 'is_required', 'reported_count'] as $key) $row[$key] = (int)$row[$key];
        }
        unset($row);
        return ['subjects' => $result['records'], 'pagination' => $result['pagination'],
                'version' => ['id' => $versionId, 'status' => $version['status']]];
    }

    /** One subject across every readable version of the selected placement's program. */
    public function subjectTimeline(int $placementId): array
    {
        $record = $this->history->findPlacement($placementId);
        if (!$record) throw new DomainNotFoundException('Stored subject placement not found.');
        $timeline = $this->history->subjectTimeline((int)$record['subject_id'], (int)$record['academic_program_id']);
        $eventsByVersion = [];
        foreach ($this->history->reviewEvents(array_column($timeline, 'version_id')) as $event) {
            $eventsByVersion[(int)$event['version_id']][] = ['action' => $event['action'], 'previous_status' => $event['previous_status'],
                'new_status' => $event['new_status'], 'created_at' => $event['created_at'], 'actor_name' => $event['actor_name'] !== '' ? $event['actor_name'] : null];
        }
        foreach ($timeline as &$row) {
            foreach (['placement_id', 'version_id', 'snapshot_complete', 'is_required'] as $key) $row[$key] = (int)$row[$key];
            $row['is_selected'] = $row['placement_id'] === $placementId;
            $row['lifecycle'] = $eventsByVersion[$row['version_id']] ?? [];
        }
        unset($row);
        // Reported by other subsystems through the API: separate from placements, not verified here, read-scoped.
        [$reportedTotal, $reportedRows] = $this->history->reportedOfferings((int)$record['subject_id'], (int)$record['academic_program_id']);
        $reported = array_map(static fn(array $r): array => [
            'academic_year' => $r['academic_year'], 'term' => $r['term'], 'status' => $r['status'],
            'section_count' => (int)$r['section_count'], 'enrolled_count' => $r['enrolled_count'] === null ? null : (int)$r['enrolled_count'],
            'flags' => $r['flags'] !== null && $r['flags'] !== '' ? explode(',', $r['flags']) : [],
            'source_system' => $r['source_system'], 'is_demo' => str_starts_with((string)$r['source_system'], 'demo-'),
            'received_at' => $r['received_at'],
        ], $reportedRows);
        return [
            'subject' => ['id' => (int)$record['subject_id'], 'current_code' => $record['current_subject_code'],
                          'current_title' => $record['current_subject_title'], 'current_units' => $record['current_units']],
            'program' => ['id' => (int)$record['academic_program_id'], 'code' => $record['program_code'], 'name' => $record['program_name']],
            'selected_placement_id' => $placementId,
            'timeline' => $timeline,
            'reported' => ['available' => $this->history->reportedAvailable(), 'total' => $reportedTotal, 'rows' => $reported],
        ];
    }

    /**
     * Deterministic findings for one subject, for CIMF "Summarize History". Counts come from stored placements only.
     * Scope: the program of the selected version, or every readable program when no version is selected.
     */
    public function subjectFindings(int $subjectId, ?int $versionId): array
    {
        $subject = $this->subjects->findById($subjectId);
        if (!$subject) throw new DomainNotFoundException('Subject not found.');
        $programId = null;
        $scope = 'every readable program';
        if ($versionId !== null) {
            $version = $this->versions->findById($versionId);
            if (!$version) throw new DomainNotFoundException('Curriculum version not found.');
            $this->assertReadable($version);
            $curriculum = $this->curricula->findById((int)$version['curriculum_id']);
            $programId = (int)$curriculum['academic_program_id'];
            $scope = 'the program of the selected version';
        }
        // One appearance per version (first placement in the version); later rows of the same version are not counted twice.
        $byVersion = [];
        foreach ($this->history->subjectAppearances($subjectId, $programId) as $row) $byVersion[(int)$row['version_id']] ??= $row;
        $appearances = array_values($byVersion);
        $term = static fn(array $r): string => trim(($r['year_level'] ?? '') . ' · ' . ($r['semester'] ?? ''), ' ·') ?: 'term not recorded';
        $moves = []; $typeChanges = [];
        for ($i = 1; $i < count($appearances); $i++) {
            $before = $appearances[$i - 1]; $after = $appearances[$i];
            if (($before['year_level'] ?? '') !== ($after['year_level'] ?? '') || ($before['semester'] ?? '') !== ($after['semester'] ?? '')) {
                $moves[] = ['id' => (int)$after['version_id'], 'code' => $before['version_label'] . ' → ' . $after['version_label'], 'title' => $term($before) . ' → ' . $term($after)];
            }
            if ((int)$before['is_required'] !== (int)$after['is_required']) {
                $typeChanges[] = ['id' => (int)$after['version_id'], 'code' => $before['version_label'] . ' → ' . $after['version_label'], 'title' => ''];
            }
        }
        $version = static fn(array $r): array => ['id' => (int)$r['version_id'], 'code' => $r['curriculum_code'] . ' · ' . $r['version_label'], 'title' => $term($r)];
        $noSnapshot = array_map($version, array_filter($appearances, static fn(array $r): bool => (int)$r['snapshot_complete'] !== 1));
        $noEffectivity = array_map($version, array_filter($appearances, static fn(array $r): bool => trim((string)($r['effective_academic_year'] ?? '')) === ''));
        $findings = [
            ['key' => 'appears', 'label' => 'Appears in curriculum versions', 'count' => count($appearances), 'subjects' => array_map($version, $appearances),
             'hint' => 'Each version is a stored curriculum plan. Being placed in a version is not a class offering.'],
            ['key' => 'moved', 'label' => 'Year or term changed between consecutive versions', 'count' => count($moves), 'subjects' => $moves,
             'hint' => 'Review these first: the placement moved to another year or term from one version to the next.'],
            ['key' => 'type_changed', 'label' => 'Required or elective status changed between versions', 'count' => count($typeChanges), 'subjects' => $typeChanges,
             'hint' => 'Confirm the change was intended in the curriculum plan.'],
            ['key' => 'no_snapshot', 'label' => 'Versions without a stored snapshot (current values shown)', 'count' => count($noSnapshot), 'subjects' => $noSnapshot,
             'hint' => 'Their code, title and units are current Subject Master values, not captured history.'],
            ['key' => 'no_effectivity', 'label' => 'Versions with no recorded effectivity', 'count' => count($noEffectivity), 'subjects' => $noEffectivity,
             'hint' => 'The effective academic year is free text and was not entered for these versions.'],
        ];
        if ($this->history->reportedAvailable()) {
            // Programs, not offerings: advisory counts of where reported data and curriculum placement disagree. Same read scope as the dialog.
            $placed = array_values(array_unique(array_map(static fn(array $r): int => (int)$r['academic_program_id'], $appearances)));
            $reportedIds = array_map(static fn(array $r): int => (int)$r['program_id'], $this->history->reportedPrograms($subjectId, $programId));
            $unplaced = array_values(array_diff($reportedIds, $placed));
            $silent = array_values(array_diff($placed, $reportedIds));
            $codes = $this->history->programCodes(array_merge($unplaced, $silent));
            $program = static fn(int $id): array => ['id' => $id, 'code' => (string)($codes[$id] ?? ''), 'title' => ''];
            $findings[] = ['key' => 'reported_not_placed', 'label' => 'Programs with reported offerings but no curriculum placement', 'count' => count($unplaced), 'subjects' => array_map($program, $unplaced),
                'hint' => 'Another system reported this subject for a program whose stored curricula do not contain it. Review the placement or the report; neither is assumed correct.'];
            $findings[] = ['key' => 'placed_not_reported', 'label' => 'Programs with a curriculum placement but no reported offerings', 'count' => count($silent), 'subjects' => array_map($program, $silent),
                'hint' => 'This depends on whether a source system sends data. No report may only mean no system has reported yet; it does not show the subject was not offered.'];
        }
        return [
            'subject' => ['id' => (int)$subject['id'], 'subject_code' => $subject['subject_code'], 'subject_title' => $subject['subject_title'], 'units' => $subject['units'] ?? null],
            'scope' => $scope,
            'version_count' => count($appearances),
            'findings' => $findings,
            'notice' => 'Counts are calculated by CSMS from stored curriculum placements and from offerings reported by other systems. Reported offerings are not verified by CSMS. None of this is enrollment or instructors.',
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

    /** Shared Program / Curriculum / Version bar lookup (same read-scoped query as Electives Manager). */
    public function contextOptions(array $input): array
    {
        return (new ElectivesWorkspaceService($this->db))->contextOptions($input);
    }

    public function selectedContext(array $input): array
    {
        $record = $this->history->versionContext(
            DomainValidator::positiveId($input['program_id'] ?? null, 'Program / Strand'),
            DomainValidator::positiveId($input['curriculum_id'] ?? null, 'Curriculum'),
            DomainValidator::positiveId($input['version_id'] ?? null, 'Curriculum version')
        );
        if (!$record) throw new DomainNotFoundException('Curriculum version not found.');
        return ['record' => $record];
    }

    private function assertReadable(array $version): void
    {
        if (function_exists('current_user_id') && !CurriculumReadScope::allows($version, current_user_id())) {
            throw new DomainNotFoundException('Curriculum version not found.');
        }
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
        $query['scope_sql'] = CurriculumReadScope::sql('curriculum_versions');
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
        $this->assertReadable($versionA);
        $this->assertReadable($versionB);
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
            $this->assertReadable($revision);
            $this->assertReadable($reference);
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
