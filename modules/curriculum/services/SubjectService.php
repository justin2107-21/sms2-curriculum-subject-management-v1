<?php

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/SubjectRepository.php';
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
        $query = DomainValidator::listQuery($input, ['active', 'inactive']);
        $result = $this->subjects->paginate($query);
        return ['subjects' => $result['records'], 'pagination' => $result['pagination']];
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
            if (!$this->subjects->findById($id)) {
                throw new DomainNotFoundException('Subject not found.');
            }
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
