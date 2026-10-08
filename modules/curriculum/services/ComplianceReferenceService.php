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
        return $reference;
    }

    public function create(array $input, int $actorId): array
    {
        $data = DomainValidator::complianceReference($input);
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
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertReferenceEditable($id);
            if (!$this->references->findByIdForUpdate($id)) {
                throw new DomainNotFoundException('Compliance reference not found.');
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
}
