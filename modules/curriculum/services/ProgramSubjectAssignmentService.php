<?php
require_once __DIR__ . '/../validators/DomainExceptions.php';
require_once __DIR__ . '/../repositories/ProgramSubjectAssignmentRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';

class ProgramSubjectAssignmentService
{
    private ProgramSubjectAssignmentRepository $repository;
    public function __construct(private mysqli $db) { $this->repository = new ProgramSubjectAssignmentRepository($db); }

    public function search(string $type, string $query, string $contextType = '', int $contextId = 0, bool $availableOnly = false): array
    {
        if (!in_array($type, ['program', 'subject'], true)) throw new DomainValidationException('Invalid record type.');
        if ($contextType !== '' && (!in_array($contextType, ['program','subject'], true) || $contextId < 1)) throw new DomainValidationException('Invalid assignment context.');
        return $this->repository->search($type, $query, $contextType, $contextId, $availableOnly);
    }
    /** The selected curriculum version (0 = none). It only scopes the Placed columns; it must belong to the program. */
    private function selectedVersion(int $programId, mixed $value): int
    {
        if ($value === null || $value === '') return 0;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new DomainValidationException('Curriculum version is invalid.');
        if (!$this->repository->versionBelongsToProgram($programId, (int)$id)) throw new DomainValidationException('Select a curriculum version belonging to the selected program.');
        return (int)$id;
    }
    public function context(string $type, int $id, mixed $versionId = null): array
    {
        if (!in_array($type, ['program', 'subject'], true)) throw new DomainValidationException('Invalid assignment view.');
        $record = $this->repository->record($type, $id);
        if (!$record) throw new DomainNotFoundException(ucfirst($type) . ' not found.');
        return $this->repository->summary($type, $id, $record, $type === 'program' ? $this->selectedVersion($id, $versionId) : 0);
    }
    public function list(int $id, string $type, array $query): array
    {
        $this->context($type, $id);
        if ($type === 'program') return $this->repository->listCoverage($id, $query, is_string($query['filter'] ?? null) ? $query['filter'] : '', $this->selectedVersion($id, $query['version_id'] ?? null));
        return $this->repository->list($type, $id, $query);
    }
    /** Where a subject is used: its assignments and its curriculum placements. */
    public function usage(int $subjectId): array
    {
        $subject = $this->repository->record('subject', $subjectId);
        if (!$subject) throw new DomainNotFoundException('Subject not found.');
        return ['subject' => ['id' => (int)$subject['id'], 'subject_code' => $subject['subject_code'], 'subject_title' => $subject['subject_title'], 'units' => $subject['units'], 'status' => $subject['status']]]
            + $this->repository->usage($subjectId);
    }
    public function details(int $assignmentId): array
    {
        $record = $this->repository->assignment($assignmentId);
        if (!$record) throw new DomainNotFoundException('Subject assignment not found.');
        $record['placements'] = $this->repository->placements((int)$record['academic_program_id'], (int)$record['subject_id']);
        return $record;
    }
    public function reviewFacts(string $view, int $id): array
    {
        $this->context($view, $id);
        return $this->repository->reviewFacts($view, $id);
    }
    public function assign(string $view, int $contextId, array $ids, int $actorId): array
    {
        if (!in_array($view, ['program', 'subject'], true)) throw new DomainValidationException('Invalid assignment view.');
        if (!$ids || count($ids) > 100) throw new DomainValidationException('Select between 1 and 100 records.');
        $ids = array_values(array_unique(array_map(static function ($id): int {
            if (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id < 1) throw new DomainValidationException('Invalid selected record.');
            return (int)$id;
        }, $ids)));
        $context = $this->repository->record($view, $contextId);
        if (!$context) throw new DomainNotFoundException(ucfirst($view) . ' not found.');
        if (($context['status'] ?? '') !== 'active') throw new DomainValidationException('Inactive or archived ' . $view . ' records cannot receive new assignments.');
        $programId = $view === 'program' ? $contextId : null;
        $subjectId = $view === 'subject' ? $contextId : null;
        $resolved = [];
        foreach ($ids as $id) {
            $record = $this->repository->record($view === 'program' ? 'subject' : 'program', $id);
            if (!$record) throw new DomainNotFoundException('A selected ' . ($view === 'program' ? 'subject' : 'program') . ' no longer exists.');
            if ($record['status'] !== 'active') throw new DomainValidationException('Inactive or archived records cannot be assigned.');
            $resolved[] = $record;
        }
        $this->db->begin_transaction();
        try {
            foreach ($resolved as $record) {
                $p = $view === 'program' ? $programId : (int)$record['id'];
                $s = $view === 'program' ? (int)$record['id'] : $subjectId;
                if ($this->repository->isAssigned($p, $s)) throw new DomainConflictException('This Program / Strand and Subject are already assigned.');
                $assignmentId = $this->repository->activateOrCreate($p, $s, $actorId);
                audit_log_required('PROGRAM_SUBJECT_ASSIGN', 'program_subject_assignment', $assignmentId,
                    'Subject associated with academic program.', ['academic_program_id' => $p, 'subject_id' => $s]);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            if ($error instanceof mysqli_sql_exception && (int)$error->getCode() === 1062) throw new DomainConflictException('This Program / Strand and Subject are already assigned.');
            throw $error;
        }
        return ['count' => count($resolved), 'context' => $context];
    }
    /** The curricula and versions that block an unassign, in words: "Placed in C / v1, 4th Year 2nd Semester; ..." (first five). */
    public static function placementBlockMessage(string $subjectCode, array $placements): string
    {
        $lines = array_map(static fn(array $p): string => trim($p['curriculum_code'] . ' / ' . $p['version_label'] . ', ' . trim(($p['year_level'] ?? '') . ' ' . ($p['semester'] ?? '')), ' ,'), array_slice($placements, 0, 5));
        $more = count($placements) - count($lines);
        return $subjectCode . ' cannot be unassigned. Placed in ' . implode('; ', $lines) . ($more > 0 ? " and $more more" : '') . '. Remove it from those curriculum plans first.';
    }
    /** Program, curriculum and version must belong together; returns the program. */
    private function reconcileProgram(int $programId, int $curriculumId, int $versionId): array
    {
        $program = $this->repository->record('program', $programId);
        if (!$program) throw new DomainNotFoundException('Program not found.');
        if (!$this->repository->chainValid($programId, $curriculumId, $versionId)) throw new DomainValidationException('Select a curriculum version belonging to the selected program and curriculum.');
        return $program;
    }
    private static function splitActive(array $rows): array
    {
        $active = []; $inactive = [];
        foreach ($rows as $row) { if ($row['subject_status'] === 'active') $active[] = $row; else $inactive[] = $row; }
        return [$active, $inactive];
    }
    /** Read-only preview: what "Assign all placed" would assign, and which inactive subjects it would skip. */
    public function reconcilePreview(int $programId, int $curriculumId, int $versionId): array
    {
        $program = $this->reconcileProgram($programId, $curriculumId, $versionId);
        [$active, $inactive] = self::splitActive($this->repository->placedNotAssigned($programId, $versionId));
        return ['program_status' => $program['status'], 'assignable' => $active, 'skipped_inactive' => $inactive];
    }
    /**
     * Assigns every active subject that is placed in the version and not yet assigned, at program level, in one transaction.
     * Never writes to curriculum_version_subjects or any curriculum version. Idempotent: a second run finds nothing.
     */
    public function reconcile(int $programId, int $curriculumId, int $versionId, int $actorId): array
    {
        $program = $this->reconcileProgram($programId, $curriculumId, $versionId);
        if ($program['status'] !== 'active') throw new DomainValidationException('Inactive or archived program records cannot receive new assignments.');
        $this->db->begin_transaction();
        try {
            $this->repository->lockProgram($programId);
            // Read again inside the lock so a concurrent run or manual assign is never repeated.
            [$active, $inactive] = self::splitActive($this->repository->placedNotAssigned($programId, $versionId));
            foreach ($active as $row) {
                $assignmentId = $this->repository->activateOrCreate($programId, $row['subject_id'], $actorId);
                audit_log_required('PROGRAM_SUBJECT_ASSIGN', 'program_subject_assignment', $assignmentId,
                    'Subject associated with academic program (assign all placed).',
                    ['academic_program_id' => $programId, 'subject_id' => $row['subject_id'], 'source' => 'reconcile', 'curriculum_version_id' => $versionId]);
            }
            if ($active) {
                audit_log_required('PROGRAM_SUBJECT_RECONCILE', 'academic_program', $programId, 'Assigned all active placed subjects of a curriculum version to the program.',
                    ['academic_program_id' => $programId, 'curriculum_id' => $curriculumId, 'curriculum_version_id' => $versionId, 'assigned' => count($active), 'skipped_inactive' => count($inactive)]);
            }
            $this->db->commit();
        } catch (Throwable $error) { $this->db->rollback(); throw $error; }
        return ['assigned' => count($active), 'skipped_inactive' => count($inactive)];
    }
    public function remove(int $id): void
    {
        $assignment = $this->details($id);
        $this->db->begin_transaction();
        try {
            // The subject row lock serializes this check against concurrent FK-backed mapping inserts.
            $this->repository->lockSubject((int)$assignment['subject_id']);
            if ($blocking = $this->repository->placements((int)$assignment['academic_program_id'], (int)$assignment['subject_id']))
                throw new DomainConflictException(self::placementBlockMessage((string)$assignment['subject_code'], $blocking));
            if (!$this->repository->archive($id)) throw new DomainNotFoundException('Subject assignment not found.');
            audit_log_required('PROGRAM_SUBJECT_ASSIGNMENT_ARCHIVE', 'program_subject_assignment', $id,
                'Program and subject assignment archived.', ['academic_program_id' => (int)$assignment['academic_program_id'], 'subject_id' => (int)$assignment['subject_id']]);
            $this->db->commit();
        } catch (Throwable $error) { $this->db->rollback(); throw $error; }
    }
}
