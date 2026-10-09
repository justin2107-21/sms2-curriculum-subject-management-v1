<?php

require_once __DIR__ . '/RepositoryQuery.php';

final class CurriculumVersionReferenceRepository
{
    public function __construct(private mysqli $db) {}

    public function listForVersion(int $versionId): array
    {
        $stmt = RepositoryQuery::execute($this->db,
            'SELECT cvr.*, cr.reference_type, cr.source_kind AS current_source_kind,
                    cr.reference_code AS current_reference_code, cr.title AS current_title,
                    cr.issuing_body AS current_issuing_body, cr.source_url AS current_source_url,
                    cr.effective_date AS current_effective_date, cr.verification_status,
                    cr.supersedes_reference_id, u.username AS linked_by_username
             FROM curriculum_version_references cvr
             JOIN compliance_references cr ON cr.id=cvr.compliance_reference_id
             LEFT JOIN users u ON u.id=cvr.linked_by
             WHERE cvr.curriculum_version_id=?
             ORDER BY cvr.reference_purpose, cvr.compliance_reference_id', 'i', [$versionId]);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as &$row) {
            $row['evidence_events'] = $this->evidence((int)$row['compliance_reference_id']);
        }
        return $rows;
    }

    public function linksForVersionForUpdate(int $versionId): array
    {
        $stmt = RepositoryQuery::execute($this->db,
            'SELECT * FROM curriculum_version_references
             WHERE curriculum_version_id=? ORDER BY compliance_reference_id, reference_purpose FOR UPDATE',
            'i', [$versionId]);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function evidence(int $referenceId): array
    {
        $stmt = RepositoryQuery::execute($this->db,
            'SELECT verification_subject, result, evidence_locator, evidence_sha256,
                    rationale, created_at
             FROM compliance_reference_evidence_events
             WHERE compliance_reference_id=? ORDER BY id DESC', 'i', [$referenceId]);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function linkForUpdate(int $versionId, int $referenceId, string $purpose): ?array
    {
        $stmt = RepositoryQuery::execute($this->db,
            'SELECT * FROM curriculum_version_references
             WHERE curriculum_version_id=? AND compliance_reference_id=? AND reference_purpose=?
             FOR UPDATE', 'iis', [$versionId, $referenceId, $purpose]);
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function link(int $versionId, array $reference, string $purpose, int $actorId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO curriculum_version_references
             (curriculum_version_id, compliance_reference_id, reference_purpose,
              reference_code_snapshot, reference_title_snapshot, reference_url_snapshot,
              verification_status_snapshot, source_kind_snapshot, issuing_body_snapshot,
              effective_date_snapshot, linked_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iissssssssi', $versionId, $reference['id'], $purpose,
            $reference['reference_code'], $reference['title'], $reference['source_url'],
            $reference['verification_status'], $reference['source_kind'],
            $reference['issuing_body'], $reference['effective_date'], $actorId);
        $stmt->execute();
        $stmt->close();
    }

    public function placements(int $versionId, bool $forUpdate = false): array
    {
        $sql = 'SELECT id, subject_id, year_level, semester, sequence_order, is_required
                FROM curriculum_version_subjects WHERE curriculum_version_id=? ORDER BY subject_id, id';
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $stmt = RepositoryQuery::execute($this->db, $sql, 'i', [$versionId]);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function subjectForUpdate(int $id): ?array
    {
        $stmt = RepositoryQuery::execute($this->db,
            'SELECT id, subject_code, subject_title, units, lecture_units, laboratory_units, description
             FROM subjects WHERE id=? FOR UPDATE', 'i', [$id]);
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function snapshot(int $placementId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM curriculum_version_subject_snapshots
                WHERE curriculum_version_subject_id=?';
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $stmt = RepositoryQuery::execute($this->db, $sql, 'i', [$placementId]);
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function captureSnapshot(int $placementId, array $subject, int $sourceId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO curriculum_version_subject_snapshots
             (curriculum_version_subject_id, subject_code_snapshot, subject_title_snapshot,
              units_snapshot, lecture_units_snapshot, laboratory_units_snapshot,
              description_snapshot, capture_method, source_reference_id, snapshot_complete)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'reference_capture', ?, 1)"
        );
        $stmt->bind_param('issdddsi', $placementId, $subject['subject_code'],
            $subject['subject_title'], $subject['units'], $subject['lecture_units'],
            $subject['laboratory_units'], $subject['description'], $sourceId);
        $stmt->execute();
        $stmt->close();
    }
}
