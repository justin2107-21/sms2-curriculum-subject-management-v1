<?php

require_once __DIR__ . '/CurriculumVersionEditPolicy.php';
require_once __DIR__ . '/../repositories/ComplianceReferenceRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionReferenceRepository.php';
require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/RepositoryQuery.php';
require_once __DIR__ . '/../../../shared/audit.php';

final class CurriculumVersionReferenceService
{
    private ComplianceReferenceRepository $sources;
    private CurriculumVersionReferenceRepository $references;

    public function __construct(private mysqli $db)
    {
        $this->sources = new ComplianceReferenceRepository($db);
        $this->references = new CurriculumVersionReferenceRepository($db);
    }

    public function workspace(int $versionId): array
    {
        $stmt = RepositoryQuery::execute($this->db,
            'SELECT v.id, v.curriculum_id, v.version_label, v.status, v.version_role,
                    v.review_status, v.baseline_version_id, v.effective_academic_year,
                    c.curriculum_code, c.curriculum_name, c.academic_program_id, p.program_code, p.program_name,
                    bv.version_label AS baseline_version_label,
                    bv.version_role AS baseline_version_role
             FROM curriculum_versions v
             JOIN curricula c ON c.id=v.curriculum_id
             JOIN academic_programs p ON p.id=c.academic_program_id
             LEFT JOIN curriculum_versions bv ON bv.id=v.baseline_version_id
             WHERE v.id=? LIMIT 1', 'i', [$versionId]);
        $version = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$version) throw new DomainNotFoundException('Curriculum version not found.');
        $version['references'] = $this->references->listForVersion($versionId);
        // Inherited provenance stays attached to the immutable baseline, never copied as
        // a new verification or attachment event on the editable proposal.
        $version['references_inherited_from_version_id'] = null;
        if ($version['version_role'] === 'proposed' && $version['baseline_version_role'] === 'reference'
            && $version['baseline_version_id'] !== null) {
            $version['references'] = $this->references->listForVersion((int)$version['baseline_version_id']);
            $version['references_inherited_from_version_id'] = (int)$version['baseline_version_id'];
        }
        foreach ($version['references'] as &$reference) {
            $reference['current_assessments'] = [];
            foreach ($reference['evidence_events'] as $event) {
                $subject = $event['verification_subject'];
                if (isset($reference['current_assessments'][$subject])) continue;
                $isCurrent = (int)$event['matches_current_source'] === 1;
                $reference['current_assessments'][$subject] = [
                    'status' => $isCurrent ? $event['result'] : 'stale_for_current_metadata',
                    'historical_result' => $event['result'],
                    'matches_current_source' => $isCurrent,
                    'recorded_at' => $event['created_at'],
                ];
            }
            $reference['attachment_matches_current_source'] =
                ($reference['reference_code_snapshot'] ?? null) === ($reference['current_reference_code'] ?? null)
                && ($reference['reference_title_snapshot'] ?? null) === ($reference['current_title'] ?? null)
                && ($reference['source_kind_snapshot'] ?? null) === ($reference['current_source_kind'] ?? null)
                && ($reference['issuing_body_snapshot'] ?? null) === ($reference['current_issuing_body'] ?? null)
                && ($reference['reference_url_snapshot'] ?? null) === ($reference['current_source_url'] ?? null)
                && ($reference['effective_date_snapshot'] ?? null) === ($reference['current_effective_date'] ?? null);
        }
        unset($reference);
        $version['snapshot_summary'] = $this->snapshotSummary($versionId);
        $version['institutional_adoption_label'] = 'Institutional adoption/approval not verified';
        $version['editable'] = CurriculumVersionEditPolicy::isEditable($version);
        return $version;
    }

    public function attach(array $input, int $actorId): array
    {
        $data = DomainValidator::curriculumVersionReference($input);
        $this->db->begin_transaction();
        try {
            // Source ownership first, then Task 2.1 parent/version ownership locks.
            $source = $this->sources->findByIdForUpdate($data['compliance_reference_id']);
            if (!$source) throw new DomainNotFoundException('Compliance reference not found.');
            $version = (new CurriculumVersionEditPolicy($this->db))->assertEditable($data['curriculum_version_id']);
            $this->requireUnclassified($version);
            if ($source['verification_status'] === 'inactive') {
                throw new DomainConflictException('Inactive documentary references cannot be attached.');
            }
            if ($this->references->linkForUpdate($data['curriculum_version_id'],
                    $data['compliance_reference_id'], $data['reference_purpose'])) {
                throw new DomainConflictException('This reference is already attached for that purpose.');
            }
            $this->references->link($data['curriculum_version_id'], $source,
                $data['reference_purpose'], $actorId);
            audit_log_required('CURRICULUM_VERSION_REFERENCE_ATTACH', 'curriculum_version',
                $data['curriculum_version_id'], 'Documentary reference attached to an unclassified version.', [
                    'compliance_reference_id' => $data['compliance_reference_id'],
                    'reference_purpose' => $data['reference_purpose'],
                    'source_kind' => $source['source_kind'],
                    'verification_status' => $source['verification_status'],
                ]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            if ($error instanceof mysqli_sql_exception && (int)$error->getCode() === 1062) {
                throw new DomainConflictException('This reference is already attached for that purpose.');
            }
            throw $error;
        }
        return $this->workspace($data['curriculum_version_id']);
    }

    public function classifyReference(int $versionId, int $sourceId, string $basis, bool $confirmed, int $actorId): array
    {
        if (!$confirmed) throw new DomainValidationException('Confirm the protected-reference consequences before continuing.');
        if (!in_array($basis, ['ched_sample', 'institutional_source', 'other_document'], true)) {
            throw new DomainValidationException('Select an explicit reference classification basis.');
        }
        $this->db->begin_transaction();
        try {
            $source = $this->sources->findByIdForUpdate($sourceId);
            if (!$source) throw new DomainNotFoundException('Compliance reference not found.');
            if ($source['verification_status'] === 'inactive') {
                throw new DomainConflictException('Inactive documentary references cannot establish a baseline.');
            }
            $attached = $this->references->linksForVersionForUpdate($versionId);
            $hasPrimary = false;
            foreach ($attached as $link) {
                if ((int)$link['compliance_reference_id'] === $sourceId
                    && $link['reference_purpose'] === 'school_curriculum') {
                    $hasPrimary = true;
                    if (($link['reference_code_snapshot'] ?? null) !== ($source['reference_code'] ?? null)
                        || ($link['reference_title_snapshot'] ?? null) !== ($source['title'] ?? null)
                        || ($link['source_kind_snapshot'] ?? null) !== ($source['source_kind'] ?? null)
                        || ($link['issuing_body_snapshot'] ?? null) !== ($source['issuing_body'] ?? null)
                        || ($link['reference_url_snapshot'] ?? null) !== ($source['source_url'] ?? null)
                        || ($link['effective_date_snapshot'] ?? null) !== ($source['effective_date'] ?? null)) {
                        throw new DomainConflictException('Source metadata changed after attachment. Attach a current source record before classification; the original attachment evidence remains unchanged.');
                    }
                }
            }
            if (!$hasPrimary) throw new DomainConflictException('Attach this document as a curriculum source before classification.');
            $this->assertClassificationEvidence($sourceId, $source, $basis);

            // Discovery reads are hints. Subject locks precede the owner locks per Task 2.1;
            // after the owner lock, placements are re-read as current locking reads.
            $discovered = $this->references->placements($versionId);
            if (!$discovered) throw new DomainConflictException('A version with no subject placements cannot be classified as a reference.');
            $subjectIds = array_values(array_unique(array_map(static fn($row) => (int)$row['subject_id'], $discovered)));
            sort($subjectIds, SORT_NUMERIC);
            $policy = new CurriculumVersionEditPolicy($this->db);
            foreach ($subjectIds as $subjectId) $policy->lockSubject($subjectId);
            $version = $policy->assertEditable($versionId);
            $this->requireUnclassified($version);
            $placements = $this->references->placements($versionId, true);
            if ($this->placementSignature($discovered) !== $this->placementSignature($placements)) {
                throw new DomainConflictException('Subject placements changed during classification. Reload and retry.');
            }

            foreach ($placements as $placement) {
                $subject = $this->references->subjectForUpdate((int)$placement['subject_id']);
                if (!$subject) throw new DomainConflictException('A placed subject no longer exists.');
                foreach (['subject_code', 'subject_title', 'units'] as $required) {
                    if ($subject[$required] === null || $subject[$required] === '') {
                        throw new DomainConflictException('A placed subject is missing required snapshot data.');
                    }
                }
                $snapshot = $this->references->snapshot((int)$placement['id'], true);
                if ($snapshot !== null) {
                    if ((int)$snapshot['snapshot_complete'] !== 1
                        || (int)$snapshot['source_reference_id'] !== $sourceId
                        || !$this->snapshotMatches($snapshot, $subject)) {
                        throw new DomainConflictException('An existing snapshot is incomplete or conflicts with current evidence; it was preserved unchanged.');
                    }
                    continue;
                }
                $this->references->captureSnapshot((int)$placement['id'], $subject, $sourceId);
            }

            $stmt = RepositoryQuery::execute($this->db,
                "UPDATE curriculum_versions SET version_role='reference'
                 WHERE id=? AND version_role='unclassified'
                   AND review_status='not_started' AND baseline_version_id IS NULL",
                'i', [$versionId]);
            $changed = $stmt->affected_rows;
            $stmt->close();
            if ($changed !== 1) throw new DomainConflictException('Version lifecycle changed during classification. Reload and retry.');
            audit_log_required('CURRICULUM_VERSION_CLASSIFY_REFERENCE', 'curriculum_version', $versionId,
                'Unclassified curriculum version classified as a protected reference.', [
                    'source_reference_id' => $sourceId,
                    'source_kind' => $source['source_kind'],
                    'classification_basis' => $basis,
                    'snapshot_count' => count($placements),
                    'institutional_adoption' => 'not_verified',
                ]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $this->workspace($versionId);
    }

    private function requireUnclassified(array $version): void
    {
        if (($version['version_role'] ?? null) !== 'unclassified'
            || ($version['review_status'] ?? null) !== 'not_started'
            || ($version['baseline_version_id'] ?? null) !== null) {
            throw new DomainConflictException('Only an unclassified version that has not entered review can be changed.');
        }
    }

    public function assertClassificationEvidence(int $sourceId, array $source, string $basis): void
    {
        $allowed = [
            'ched_sample' => ['ched_sample_curriculum'],
            'institutional_source' => ['institutional_curriculum'],
            'other_document' => ['other_document', 'supporting_document'],
        ];
        if (!in_array($source['source_kind'], $allowed[$basis], true)) {
            throw new DomainConflictException('The selected classification basis does not match the documentary source kind.');
        }
        $latest = [];
        foreach ($this->sources->evidenceEvents($sourceId, true) as $event) {
            if (!isset($latest[$event['verification_subject']])) $latest[$event['verification_subject']] = $event;
        }
        // Document identity is explicitly assessed but may honestly remain not assessed/not confirmed.
        if (!isset($latest['document_identity']) || !$this->evidenceMatchesSource($latest['document_identity'], $source)) {
            throw new DomainConflictException('Record an explicit document-identity assessment before classification.');
        }
        $sourceLocator = (string)($source['source_url'] ?? '');
        $evidenceLocator = trim((string)($latest['document_identity']['evidence_locator'] ?? ''));
        if (!$this->isValidSourceLocator($sourceLocator) && $evidenceLocator === '') {
            throw new DomainConflictException('A traceable document location is required: add a valid source locator or a matching identity-evidence locator. A fingerprint alone is not a location.');
        }
        if ($basis === 'institutional_source' && (!isset($latest['declared_origin'])
            || !$this->evidenceMatchesSource($latest['declared_origin'], $source))) {
            throw new DomainConflictException('Record a separate declared-origin assessment. This does not establish BCP adoption or approval.');
        }
    }

    private function evidenceMatchesSource(array $event, array $source): bool
    {
        return ($event['reference_code_snapshot'] ?? null) === ($source['reference_code'] ?? null)
            && ($event['title_snapshot'] ?? null) === ($source['title'] ?? null)
            && ($event['source_kind_snapshot'] ?? null) === ($source['source_kind'] ?? null)
            && ($event['issuing_body_snapshot'] ?? null) === ($source['issuing_body'] ?? null)
            && ($event['source_url_snapshot'] ?? null) === ($source['source_url'] ?? null)
            && ($event['effective_date_snapshot'] ?? null) === ($source['effective_date'] ?? null);
    }

    private function isValidSourceLocator(string $locator): bool
    {
        $locator = trim($locator);
        if ($locator === '' || !filter_var($locator, FILTER_VALIDATE_URL)) return false;
        $parts = parse_url($locator);
        return is_array($parts)
            && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true);
    }

    private function placementSignature(array $rows): string
    {
        return hash('sha256', json_encode(array_map(static fn($row) => [
            (int)$row['id'], (int)$row['subject_id'], $row['year_level'], $row['semester'],
            $row['sequence_order'] === null ? null : (int)$row['sequence_order'], (int)$row['is_required'],
        ], $rows), JSON_THROW_ON_ERROR));
    }

    private function snapshotMatches(array $snapshot, array $subject): bool
    {
        return $snapshot['subject_code_snapshot'] === $subject['subject_code']
            && $snapshot['subject_title_snapshot'] === $subject['subject_title']
            && (float)$snapshot['units_snapshot'] === (float)$subject['units']
            && ($snapshot['lecture_units_snapshot'] === null ? null : (float)$snapshot['lecture_units_snapshot'])
                === ($subject['lecture_units'] === null ? null : (float)$subject['lecture_units'])
            && ($snapshot['laboratory_units_snapshot'] === null ? null : (float)$snapshot['laboratory_units_snapshot'])
                === ($subject['laboratory_units'] === null ? null : (float)$subject['laboratory_units'])
            && $snapshot['description_snapshot'] === $subject['description'];
    }

    private function snapshotSummary(int $versionId): array
    {
        $stmt = RepositoryQuery::execute($this->db,
            'SELECT COUNT(*) AS placements,
                    SUM(CASE WHEN s.snapshot_complete=1 THEN 1 ELSE 0 END) AS complete_snapshots,
                    MIN(s.captured_at) AS first_capture, MAX(s.captured_at) AS last_capture
             FROM curriculum_version_subjects p
             LEFT JOIN curriculum_version_subject_snapshots s
               ON s.curriculum_version_subject_id=p.id WHERE p.curriculum_version_id=?', 'i', [$versionId]);
        $summary = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        foreach (['placements', 'complete_snapshots'] as $field) $summary[$field] = (int)($summary[$field] ?? 0);
        return $summary;
    }
}
