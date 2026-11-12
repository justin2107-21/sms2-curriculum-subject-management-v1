<?php

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/SubjectRepository.php';
require_once __DIR__ . '/../repositories/SubjectEquivalencyRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';
require_once __DIR__ . '/CurriculumReadScope.php';

class SubjectEquivalencyService
{
    private SubjectRepository $subjects;
    private SubjectEquivalencyRepository $equivalencies;

    public function __construct(private mysqli $db)
    {
        $this->subjects = new SubjectRepository($db);
        $this->equivalencies = new SubjectEquivalencyRepository($db);
    }

    public function list(array $input): array
    {
        $query = DomainValidator::subjectEquivalencyQuery($input);
        $result = $this->equivalencies->paginate($query);
        return ['equivalencies' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function get(int $id): array
    {
        $record = $this->equivalencies->findById($id);
        if (!$record) {
            throw new DomainNotFoundException('Subject equivalency record not found.');
        }
        return $record;
    }

    public function searchSubjects(array $input): array
    {
        $query = DomainValidator::listQuery($input, ['active', 'inactive']);
        $result = $this->subjects->paginate($query);
        return ['subjects' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function compare(array $input): array
    {
        $pair = DomainValidator::subjectEquivalencyPair($input);
        $subjectA = $this->subjects->findById($pair['subject_a_id']);
        $subjectB = $this->subjects->findById($pair['subject_b_id']);
        if (!$subjectA || !$subjectB) {
            throw new DomainNotFoundException('One or both Subject Master records were not found.');
        }
        if (($subjectA['archived_at'] ?? null) !== null || ($subjectB['archived_at'] ?? null) !== null) {
            throw new DomainValidationException('Archived subjects cannot be compared in the current workspace.');
        }
        [$lowerId, $higherId] = $this->canonicalPair(
            $pair['subject_a_id'],
            $pair['subject_b_id']
        );
        // Same read scope as Assignment / Electives: a reader without curriculum.view_unapproved sees usage and
        // prerequisites from approved (or, by setting, active) versions only. CIMF receives this same scoped payload.
        $scope = CurriculumReadScope::sql('versions');
        return [
            'subject_a' => $subjectA,
            'subject_b' => $subjectB,
            'differences' => $this->objectiveDifferences($subjectA, $subjectB),
            'usage' => [
                'subject_a' => $this->equivalencies->usageContext($pair['subject_a_id'], $scope),
                'subject_b' => $this->equivalencies->usageContext($pair['subject_b_id'], $scope),
            ],
            'prerequisites' => [
                'subject_a' => $this->equivalencies->prerequisites($pair['subject_a_id'], $scope),
                'subject_b' => $this->equivalencies->prerequisites($pair['subject_b_id'], $scope),
            ],
            'scope_restricted' => $scope !== '',
            'existing_equivalency' => $this->equivalencies->findByPair($lowerId, $higherId),
        ];
    }

    public function create(array $input, int $actorId): array
    {
        $pair = DomainValidator::subjectEquivalencyPair($input);
        $review = DomainValidator::subjectEquivalencyReview($input);
        [$subjectId, $equivalentSubjectId] = $this->canonicalPair(
            $pair['subject_a_id'],
            $pair['subject_b_id']
        );
        $data = $review + [
            'subject_id' => $subjectId,
            'equivalent_subject_id' => $equivalentSubjectId,
        ];
        $isFinal = true;
        $this->db->begin_transaction();
        try {
            $subjectA = $this->subjects->findById($subjectId);
            $subjectB = $this->subjects->findById($equivalentSubjectId);
            if (!$subjectA || !$subjectB) {
                throw new DomainNotFoundException('One or both Subject Master records were not found.');
            }
            if (($subjectA['archived_at'] ?? null) !== null || ($subjectB['archived_at'] ?? null) !== null) {
                throw new DomainValidationException('Archived subjects cannot receive a current equivalency decision.');
            }
            $existingPair = $this->equivalencies->findByPair($subjectId, $equivalentSubjectId);
            if ($existingPair) {
                throw new DomainConflictException($existingPair['archived_at'] !== null
                    ? 'An archived equivalency record already exists for these subjects. Restore it to change the decision.'
                    : 'An equivalency record already exists for these subjects. Edit that record instead.');
            }
            $id = $this->equivalencies->create(
                $data,
                $actorId,
                $isFinal ? $actorId : null,
                $isFinal
            );
            audit_log_required(
                'SUBJECT_EQUIVALENCY_CREATE',
                'subject_equivalency',
                $id,
                'Human-reviewed subject equivalency record created.',
                [
                    'subject_id' => $subjectId,
                    'equivalent_subject_id' => $equivalentSubjectId,
                    'decision_status' => $review['decision_status'],
                ]
            );
            $record = $this->equivalencies->findById($id);
            if (!$record) {
                throw new RuntimeException('Created equivalency record could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            if ($error instanceof mysqli_sql_exception && (int)$error->getCode() === 1062) {
                throw new DomainConflictException(
                    'An equivalency record already exists for these subjects.'
                );
            }
            throw $error;
        }
        return $record;
    }

    public function update(int $id, array $input, int $actorId): array
    {
        $review = DomainValidator::subjectEquivalencyReview($input);
        $isFinal = true;
        $this->db->begin_transaction();
        try {
            $existing = $this->equivalencies->findByIdForUpdate($id);
            if (!$existing) {
                throw new DomainNotFoundException('Subject equivalency record not found.');
            }
            if (($existing['archived_at'] ?? null) !== null) {
                throw new DomainConflictException('Archived equivalency records cannot be edited.');
            }
            $this->equivalencies->update(
                $id,
                $review,
                $isFinal ? $actorId : null,
                $isFinal
            );
            audit_log_required(
                'SUBJECT_EQUIVALENCY_UPDATE',
                'subject_equivalency',
                $id,
                'Human-reviewed subject equivalency decision updated.',
                [
                    'subject_id' => (int)$existing['subject_id'],
                    'equivalent_subject_id' => (int)$existing['equivalent_subject_id'],
                    'old_decision_status' => (string)$existing['decision_status'],
                    'decision_status' => $review['decision_status'],
                    // Only whether the reason changed; the reason text itself is not copied into the audit log.
                    'remarks_changed' => trim((string)($existing['remarks'] ?? '')) !== $review['remarks'],
                ]
            );
            $record = $this->equivalencies->findById($id);
            if (!$record) {
                throw new RuntimeException('Updated equivalency record could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $record;
    }

    public function archive(int $id, int $actorId): void
    {
        $this->db->begin_transaction();
        try {
            $existing = $this->equivalencies->findByIdForUpdate($id);
            if (!$existing) throw new DomainNotFoundException('Subject equivalency record not found.');
            $this->equivalencies->archive($id, $actorId);
            audit_log_required(
                'SUBJECT_EQUIVALENCY_ARCHIVE', 'subject_equivalency', $id,
                'Human-reviewed subject equivalency record archived.',
                ['subject_id' => (int)$existing['subject_id'],
                 'equivalent_subject_id' => (int)$existing['equivalent_subject_id']]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function restore(int $id, int $actorId): array
    {
        $this->db->begin_transaction();
        try {
            $existing = $this->equivalencies->findByIdForUpdate($id);
            if (!$existing) throw new DomainNotFoundException('Subject equivalency record not found.');
            $this->equivalencies->restore($id);
            audit_log_required(
                'SUBJECT_EQUIVALENCY_RESTORE', 'subject_equivalency', $id,
                'Archived subject equivalency record restored.',
                ['subject_id' => (int)$existing['subject_id'],
                 'equivalent_subject_id' => (int)$existing['equivalent_subject_id'],
                 'decision_status' => (string)$existing['decision_status']]
            );
            $record = $this->equivalencies->findById($id);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $record;
    }

    private function canonicalPair(int $subjectAId, int $subjectBId): array
    {
        return $subjectAId < $subjectBId
            ? [$subjectAId, $subjectBId]
            : [$subjectBId, $subjectAId];
    }

    private function objectiveDifferences(array $subjectA, array $subjectB): array
    {
        $fields = [
            'subject_code' => ['Subject Code', 'text'],
            'subject_title' => ['Subject Title', 'text'],
            'description' => ['Description', 'text'],
            'units' => ['Total Units', 'number'],
            'lecture_units' => ['Lecture Units', 'number'],
            'laboratory_units' => ['Laboratory Units', 'number'],
            'status' => ['Technical Status', 'text'],
        ];
        $differences = [];
        foreach ($fields as $field => [$label, $type]) {
            $valueA = $subjectA[$field] ?? null;
            $valueB = $subjectB[$field] ?? null;
            $missing = $valueA === null || $valueA === '' || $valueB === null || $valueB === '';
            if ($missing) {
                $result = 'not_available';
            } elseif ($type === 'number') {
                $result = (float)$valueA === (float)$valueB ? 'same' : 'different';
            } else {
                $result = trim((string)$valueA) === trim((string)$valueB) ? 'same' : 'different';
            }
            $differences[] = [
                'field' => $field,
                'label' => $label,
                'subject_a' => $valueA,
                'subject_b' => $valueB,
                'result' => $result,
            ];
        }
        return $differences;
    }
}
