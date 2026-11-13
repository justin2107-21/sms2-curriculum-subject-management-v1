<?php

require_once __DIR__ . '/CurriculumVersionEditPolicy.php';

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/SubjectRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionSubjectRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';

class SubjectService
{
    private SubjectRepository $subjects;

    public function __construct(private mysqli $db)
    {
        $this->subjects = new SubjectRepository($db);
    }

    public function get(int $id): array
    {
        $subject = $this->subjects->findById($id);
        if (!$subject) {
            throw new DomainNotFoundException('Subject not found.');
        }
        return $subject;
    }

    public function list(array $input): array
    {
        $query = DomainValidator::subjectList($input);
        $query['curriculum_version_id'] = DomainValidator::optionalPositiveId($input['curriculum_version_id'] ?? null, 'Curriculum version');
        $query['academic_program_id'] = DomainValidator::optionalPositiveId($input['academic_program_id'] ?? null, 'Academic program');
        $query['curriculum_id'] = DomainValidator::optionalPositiveId($input['curriculum_id'] ?? null, 'Curriculum');
        $query['year_level'] = DomainValidator::optionalString($input, 'year_level', 'Year level', 50);
        $query['semester'] = DomainValidator::optionalString($input, 'semester', 'Semester', 50);
        if ($query['curriculum_version_id'] !== null && !(new CurriculumVersionRepository($this->db))->findById($query['curriculum_version_id'])) {
            throw new DomainNotFoundException('Curriculum version not found.');
        }
        $query['_include_placement_context'] = ($input['_include_placement_context'] ?? false) === true;
        $query['_include_usage'] = ($input['_include_usage'] ?? true) === true;
        $result = $this->subjects->paginate($query);
        return ['subjects' => $result['records'], 'pagination' => $result['pagination']];
    }

    /** Read-only details: the record, where it is placed (read scope applied) and whether the guard would allow an edit. */
    public function detail(int $id, bool $withUsage): array
    {
        $subject = $this->get($id);
        $subject['placements'] = [];
        $subject['editable'] = true;
        $subject['reason_code'] = null;
        $subject['reason_text'] = null;
        if ($subject['archived_at'] !== null) {
            $subject['editable'] = false;
            $subject['reason_code'] = 'archived';
            $subject['reason_text'] = 'This subject is archived. Restore it from Settings > Archives before editing.';
        } else {
            $locked = $this->subjects->lockedVersionsFor($id);
            if ($locked) {
                $names = array_map(static fn(array $v): string => $v['program_code'] . ' / ' . $v['curriculum_code'] . ' / ' . $v['version_label'], array_slice($locked, 0, 3));
                $subject['editable'] = false;
                $subject['reason_code'] = 'protected_version';
                $subject['reason_text'] = 'Its details are locked because it is placed in a version that cannot be edited: ' . implode('; ', $names) . (count($locked) > 3 ? ' and ' . (count($locked) - 3) . ' more' : '') . '.';
            }
        }
        if ($withUsage) {
            $subject['placements'] = array_map(static function (array $p): array {
                return ['placement_id' => (int)$p['placement_id'], 'academic_program_id' => (int)$p['academic_program_id'], 'curriculum_id' => (int)$p['curriculum_id'], 'program_code' => $p['program_code'], 'curriculum_code' => $p['curriculum_code'], 'curriculum_version_id' => (int)$p['curriculum_version_id'],
                    'version_label' => $p['version_label'], 'version_status' => $p['version_status'], 'review_status' => $p['review_status'], 'year_level' => $p['year_level'], 'semester' => $p['semester'], 'is_required' => (int)$p['is_required'],
                    'version_editable' => CurriculumVersionEditPolicy::isEditable($p)];
            }, $this->subjects->placementsFor($id));
        }
        return $subject;
    }

    /** Cards of the By program tab (server-side search, College / SHS filter, paging). */
    public function programCards(array $input): array
    {
        $perPage = filter_var($input['per_page'] ?? 60, FILTER_VALIDATE_INT);
        if ($perPage === false || !in_array((int)$perPage, [20, 40, 60, 100], true)) {
            throw new DomainValidationException('Per page must be 20, 40, 60 or 100.');
        }
        $query = DomainValidator::listQuery(array_merge($input, ['per_page' => 20]));
        $query['per_page'] = (int)$perPage;
        $level = DomainValidator::optionalString($input, 'level', 'Level', 10) ?? 'all';
        if (!in_array($level, ['all', 'college', 'shs'], true)) {
            throw new DomainValidationException('Invalid level filter.');
        }
        $query['level'] = $level;
        $result = $this->subjects->programCards($query);
        $programs = array_map(static fn(array $p): array => [
            'id' => (int)$p['id'], 'program_code' => $p['program_code'], 'program_name' => $p['program_name'], 'level' => (int)$p['is_shs'] === 1 ? 'shs' : 'college',
            'subject_count' => (int)$p['subject_count'], 'curriculum_count' => (int)$p['curriculum_count'], 'version_count' => (int)$p['version_count'],
            'version_statuses' => $p['version_statuses'],
        ], $result['records']);
        return ['programs' => $programs, 'pagination' => $result['pagination'], 'groups' => $result['groups'], 'unplaced_count' => $this->subjects->unplacedCount()];
    }

    /** Placements of one version with the filters of the By program tab, plus whole-version totals (units of all placements and of required placements). */
    public function versionPlacements(array $input): array
    {
        $versionId = DomainValidator::positiveId($input['curriculum_version_id'] ?? null, 'Curriculum version');
        if (!(new CurriculumVersionRepository($this->db))->findById($versionId)) {
            throw new DomainNotFoundException('Curriculum version not found.');
        }
        $filters = [
            'search' => DomainValidator::optionalString($input, 'search', 'Search', 100),
            'status' => DomainValidator::optionalString($input, 'status', 'Status', 30),
        ];
        if ($filters['status'] !== null && !in_array($filters['status'], ['active', 'inactive'], true)) {
            throw new DomainValidationException('Invalid technical record status filter.');
        }
        return ['placements' => $this->subjects->versionPlacements($versionId, $filters), 'totals' => $this->subjects->versionTotals($versionId)];
    }

    public function contexts(array $input): array
    {
        $query = DomainValidator::listQuery($input);
        $filters = DomainValidator::subjectMappingQuery($input);
        if ($filters['curriculum_version_id'] !== null && !(new CurriculumVersionRepository($this->db))->findById($filters['curriculum_version_id'])) {
            throw new DomainNotFoundException('Curriculum version not found.');
        }
        $result = $this->subjects->placementVersions($query, $filters);
        return ['programs' => $this->subjects->placementPrograms(),
            'curricula' => $this->subjects->placementCurricula($filters['academic_program_id']),
            'versions' => $result['records'], 'pagination' => $result['pagination'],
            'filter_values' => (new CurriculumVersionSubjectRepository($this->db))->mappingFilterValues($filters)];
    }

    public function create(array $input, int $createdBy): array
    {
        $data = DomainValidator::subject($input);
        $this->db->begin_transaction();
        try {
            if ($this->subjects->codeExists($data['subject_code'])) {
                throw new DomainConflictException('Subject code already exists.');
            }
            $id = $this->subjects->create($data, $createdBy);
            audit_log_required(
                'SUBJECT_CREATE',
                'subject',
                $id,
                'Subject master record created.',
                ['subject_code' => $data['subject_code'], 'status' => $data['status']]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $this->get($id);
    }

    public function update(int $id, array $input): array
    {
        $data = DomainValidator::subject($input);
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertMasterEditable('subject', $id, $data);
            $existing = $this->subjects->findById($id);
            if (!$existing) {
                throw new DomainNotFoundException('Subject not found.');
            }
            if ($existing['archived_at'] !== null) throw new DomainConflictException('Restore this subject from Settings > Archives before editing.');
            if ($this->subjects->codeExists($data['subject_code'], $id)) {
                throw new DomainConflictException('Subject code already exists.');
            }
            $this->subjects->update($id, $data);
            audit_log_required(
                'SUBJECT_UPDATE',
                'subject',
                $id,
                'Subject master record updated.',
                ['subject_code' => $data['subject_code'], 'status' => $data['status']]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $this->get($id);
    }
}
