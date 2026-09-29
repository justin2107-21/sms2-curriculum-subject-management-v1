<?php

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/SubjectRepository.php';
require_once __DIR__ . '/../repositories/SubjectEquivalencyRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';

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
        [$lowerId, $higherId] = $this->canonicalPair(
            $pair['subject_a_id'],
            $pair['subject_b_id']
        );
        return [
            'subject_a' => $subjectA,
            'subject_b' => $subjectB,
            'differences' => $this->objectiveDifferences($subjectA, $subjectB),
            'usage' => [
                'subject_a' => $this->equivalencies->usageContext($pair['subject_a_id']),
                'subject_b' => $this->equivalencies->usageContext($pair['subject_b_id']),
            ],
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
        $isFinal = $review['decision_status'] !== 'pending';
        $this->db->begin_transaction();
        try {
            if (!$this->subjects->findById($subjectId)
                || !$this->subjects->findById($equivalentSubjectId)) {
                throw new DomainNotFoundException('One or both Subject Master records were not found.');
            }
            if ($this->equivalencies->findByPair($subjectId, $equivalentSubjectId)) {
                throw new DomainConflictException(
                    'An equivalency record already exists for these subjects.'
                );
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
        $isFinal = $review['decision_status'] !== 'pending';
        $this->db->begin_transaction();
        try {
            $existing = $this->equivalencies->findByIdForUpdate($id);
            if (!$existing) {
                throw new DomainNotFoundException('Subject equivalency record not found.');
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
                    'decision_status' => $review['decision_status'],
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
