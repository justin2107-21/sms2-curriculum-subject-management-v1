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
    public function context(string $type, int $id): array
    {
        if (!in_array($type, ['program', 'subject'], true)) throw new DomainValidationException('Invalid assignment view.');
        $record = $this->repository->record($type, $id);
        if (!$record) throw new DomainNotFoundException(ucfirst($type) . ' not found.');
        return $this->repository->summary($type, $id, $record);
    }
    public function list(int $id, string $type, array $query): array
    {
        $this->context($type, $id);
        return $this->repository->list($type, $id, $query);
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
    public function remove(int $id): void
    {
        $assignment = $this->details($id);
        $this->db->begin_transaction();
        try {
            // The subject row lock serializes this check against concurrent FK-backed mapping inserts.
            $this->repository->lockSubject((int)$assignment['subject_id']);
            if ($this->repository->placements((int)$assignment['academic_program_id'], (int)$assignment['subject_id']))
                throw new DomainConflictException('This assignment is referenced by curriculum placements. Handle the placements in Subject Mapping before removing it.');
            if (!$this->repository->archive($id)) throw new DomainNotFoundException('Subject assignment not found.');
            audit_log_required('PROGRAM_SUBJECT_ASSIGNMENT_ARCHIVE', 'program_subject_assignment', $id,
                'Program and subject assignment archived.', ['academic_program_id' => (int)$assignment['academic_program_id'], 'subject_id' => (int)$assignment['subject_id']]);
            $this->db->commit();
        } catch (Throwable $error) { $this->db->rollback(); throw $error; }
    }
}
