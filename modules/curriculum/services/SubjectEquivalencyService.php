<?php

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/SubjectRepository.php';
require_once __DIR__ . '/../repositories/SubjectEquivalencyRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';
require_once __DIR__ . '/CurriculumReadScope.php';
require_once __DIR__ . '/../../../dashboard/DashboardShaper.php';

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
        // The Program filter respects the read scope: a restricted reader matches placements in approved versions only.
        $result = $this->equivalencies->paginate($query, CurriculumReadScope::sql('versions'));
        return ['equivalencies' => $result['records'], 'pagination' => $result['pagination']];
    }

    /**
     * Program picker data (grouped exactly like the Dashboard: DashboardShaper::isShs decides programs vs Senior High strands)
     * and, when the URL names a reviewer, that reviewer's label.
     */
    public function filterOptions(array $input = []): array
    {
        $programs = array_map(static function (array $program): array {
            $isShs = DashboardShaper::isShs($program['program_type'] ?? null);
            return [
                'id' => (int)$program['id'], 'code' => (string)$program['program_code'], 'name' => (string)$program['program_name'],
                'group' => $isShs ? 'Senior High School strands' : 'College programs', 'level' => $isShs ? 'shs' : 'college',
                'inactive' => strtolower((string)($program['status'] ?? 'active')) !== 'active',
            ];
        }, $this->equivalencies->programOptions());
        usort($programs, static fn(array $a, array $b): int => [$a['inactive'], $a['code']] <=> [$b['inactive'], $b['code']]);
        $reviewer = null;
        $reviewerId = trim((string)($input['reviewed_by'] ?? ''));
        if ($reviewerId !== '') {
            $found = $this->equivalencies->reviewers(null, DomainValidator::positiveId($reviewerId, 'Reviewed by'));
            $reviewer = $found[0] ?? null;
        }
        return ['programs' => $programs, 'reviewer' => $reviewer];
    }

    /** Reviewed-by search: two characters minimum, at most 20 users. */
    public function reviewerSearch(array $input): array
    {
        $search = DomainValidator::optionalString($input, 'search', 'Search', 100);
        if ($search === null || mb_strlen($search, 'UTF-8') < DomainValidator::EQUIVALENCY_SEARCH_MIN) {
            return ['reviewers' => [], 'needs_more_characters' => true];
        }
        return ['reviewers' => $this->equivalencies->reviewers($search), 'needs_more_characters' => false];
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
        // Picker search: never preloads the Subject Master. Fewer than two characters returns nothing; at most 20 rows.
        $query = DomainValidator::listQuery(['search' => $input['search'] ?? null, 'page' => 1, 'per_page' => 20], ['active', 'inactive']);
        if ($query['search'] === null || mb_strlen($query['search'], 'UTF-8') < DomainValidator::EQUIVALENCY_SEARCH_MIN) {
            return ['subjects' => [], 'pagination' => ['page' => 1, 'per_page' => 20, 'total_records' => 0, 'total_pages' => 1], 'needs_more_characters' => true];
        }
        $result = $this->subjects->paginate($query);
        return ['subjects' => $result['records'], 'pagination' => $result['pagination'], 'needs_more_characters' => false];
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
            // Soft warning data only (advisory, never blocks): one subject is a prerequisite / corequisite of the other.
            'requirement_links' => array_map(static fn(array $row): array => [
                'subject_id' => (int)$row['subject_id'], 'required_subject_id' => (int)$row['required_subject_id'],
                'requirement_type' => $row['requirement_type'], 'versions' => $row['versions'],
            ], $this->equivalencies->requirementBetween($pair['subject_a_id'], $pair['subject_b_id'], $scope)),
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
