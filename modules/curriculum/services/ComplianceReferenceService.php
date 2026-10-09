<?php

require_once __DIR__ . '/CurriculumVersionEditPolicy.php';

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/ComplianceReferenceRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';

class ComplianceReferenceService
{
    private ComplianceReferenceRepository $references;

    public function __construct(private mysqli $db)
    {
        $this->references = new ComplianceReferenceRepository($db);
    }

    public function list(array $input): array
    {
        $result = $this->references->paginate(DomainValidator::complianceReferenceQuery($input));
        return ['references' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function get(int $id): array
    {
        $reference = $this->references->findById($id);
        if (!$reference) {
            throw new DomainNotFoundException('Compliance reference not found.');
        }
        $reference['evidence_events'] = $this->references->evidenceEvents($id);
        return $reference;
    }

    public function create(array $input, int $actorId): array
    {
        $data = DomainValidator::complianceReference($input);
        if ($data['verification_status'] === 'verified') {
            throw new DomainValidationException('Create the source as Draft, then record a confirmed document-identity assessment with evidence before marking it internally verified.');
        }
        if ($data['supersedes_reference_id'] !== null) {
            if ($this->references->findById($data['supersedes_reference_id']) === null) {
                throw new DomainNotFoundException('Superseded compliance reference not found.');
            }
        }
        $this->db->begin_transaction();
        try {
            if ($this->references->codeExists($data['reference_type'], $data['reference_code'])) {
                throw new DomainConflictException('A reference with this type and code already exists.');
            }
            $id = $this->references->create($data, $actorId);
            audit_log_required(
                'COMPLIANCE_REFERENCE_CREATE',
                'compliance_reference',
                $id,
                'Compliance source reference created.',
                [
                    'reference_type' => $data['reference_type'],
                    'source_kind' => $data['source_kind'],
                    'supersedes_reference_id' => $data['supersedes_reference_id'],
                    'reference_code' => $data['reference_code'],
                    'verification_status' => $data['verification_status'],
                ]
            );
            $reference = $this->references->findById($id);
            if (!$reference) {
                throw new RuntimeException('Created compliance reference could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            if ($error instanceof mysqli_sql_exception && (int)$error->getCode() === 1062) {
                throw new DomainConflictException('A reference with this type and code already exists.');
            }
            throw $error;
        }
        return $reference;
    }

    public function update(int $id, array $input): array
    {
        $data = DomainValidator::complianceReference($input);
        if ($data['supersedes_reference_id'] === $id) {
            throw new DomainValidationException('A compliance reference cannot supersede itself.');
        }
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertReferenceEditable($id);
            $existing = $this->references->findByIdForUpdate($id);
            if (!$existing) {
                throw new DomainNotFoundException('Compliance reference not found.');
            }
            if ((int)($existing['supersedes_reference_id'] ?? 0) !== (int)($data['supersedes_reference_id'] ?? 0)) {
                throw new DomainConflictException('Supersession history is immutable; create a new source record to correct or replace it.');
            }
            $identityEvidence = $this->latestEvidenceEvent($id, 'document_identity');
            if ($data['verification_status'] === 'verified'
                && (!$identityEvidence || $identityEvidence['result'] !== 'confirmed'
                    || !$this->evidenceMatchesReference($identityEvidence, $data))) {
                throw new DomainConflictException('Document identity must have a confirmed evidence assessment for the current source details before this source can be marked internally verified.');
            }
            if ($this->references->codeExists($data['reference_type'], $data['reference_code'], $id)) {
                throw new DomainConflictException('A reference with this type and code already exists.');
            }
            $this->references->update($id, $data);
            audit_log_required(
                'COMPLIANCE_REFERENCE_UPDATE',
                'compliance_reference',
                $id,
                'Compliance source reference updated.',
                [
                    'reference_type' => $data['reference_type'],
                    'source_kind' => $data['source_kind'],
                    'supersedes_reference_id' => $data['supersedes_reference_id'],
                    'reference_code' => $data['reference_code'],
                    'verification_status' => $data['verification_status'],
                ]
            );
            $reference = $this->references->findById($id);
            if (!$reference) {
                throw new RuntimeException('Updated compliance reference could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $reference;
    }

    public function recordEvidence(int $id, array $input, int $actorId): array
    {
        $evidence = DomainValidator::complianceReferenceEvidence($input);
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertReferenceEditable($id);
            $reference = $this->references->findByIdForUpdate($id);
            if (!$reference) throw new DomainNotFoundException('Compliance reference not found.');
            $eventId = $this->references->recordEvidence($id, $reference, $evidence, $actorId);
            if ($evidence['verification_subject'] === 'document_identity') {
                $this->references->setVerificationStatus($id,
                    $evidence['result'] === 'confirmed' ? 'verified' : 'draft');
                $reference['verification_status'] = $evidence['result'] === 'confirmed' ? 'verified' : 'draft';
            }
            audit_log_required('COMPLIANCE_REFERENCE_EVIDENCE', 'compliance_reference', $id,
                'Evidence-based internal source assessment recorded.', [
                    'evidence_event_id' => $eventId,
                    'verification_subject' => $evidence['verification_subject'],
                    'result' => $evidence['result'],
                    'source_kind' => $reference['source_kind'],
                    'verification_status' => $reference['verification_status'],
                    'institutional_adoption' => 'not_assessed',
                ]);
            $reference['evidence_events'] = $this->references->evidenceEvents($id);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $reference;
    }

    private function latestEvidenceEvent(int $id, string $subject): ?array
    {
        foreach ($this->references->evidenceEvents($id) as $event) {
            if ($event['verification_subject'] === $subject) return $event;
        }
        return null;
    }

    private function evidenceMatchesReference(array $event, array $reference): bool
    {
        foreach ([
            'reference_code_snapshot'=>'reference_code', 'title_snapshot'=>'title',
            'source_kind_snapshot'=>'source_kind', 'issuing_body_snapshot'=>'issuing_body',
            'source_url_snapshot'=>'source_url', 'effective_date_snapshot'=>'effective_date',
        ] as $snapshotField=>$sourceField) {
            if (($event[$snapshotField] ?? null) !== ($reference[$sourceField] ?? null)) return false;
        }
        return true;
    }
}
