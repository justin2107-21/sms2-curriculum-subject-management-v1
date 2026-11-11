<?php

require_once __DIR__ . '/../validators/DomainExceptions.php';
require_once __DIR__ . '/../repositories/RepositoryQuery.php';
require_once __DIR__ . '/../../../shared/audit.php';

/**
 * Domain write gate. Call only inside the caller's transaction, on its mysqli.
 * No lifecycle state is accepted from input and no transaction is begun/committed here.
 */
final class CurriculumVersionEditPolicy
{
    public function __construct(private mysqli $db) {}

    private function rows(string $sql, string $types = '', array $values = []): array
    {
        $stmt = RepositoryQuery::execute($this->db, $sql, $types, $values);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function one(string $sql, int $id): array
    {
        $rows = $this->rows($sql, 'i', [$id]);
        if (!$rows) throw new DomainNotFoundException('Academic record not found.');
        return $rows[0];
    }

    /** Owners first, then all versions in ID order: also serializes baseline checks. */
    private function curriculumVersions(int $curriculumId): array
    {
        $initial = $this->one('SELECT academic_program_id FROM curricula WHERE id=?', $curriculumId);
        $this->one('SELECT id FROM academic_programs WHERE id=? FOR UPDATE', (int)$initial['academic_program_id']);
        $current = $this->one('SELECT academic_program_id FROM curricula WHERE id=? FOR UPDATE', $curriculumId);
        if ($initial['academic_program_id'] !== $current['academic_program_id']) {
            throw new DomainConflictException('Academic context changed. Reload before editing.');
        }
        return $this->rows('SELECT * FROM curriculum_versions WHERE curriculum_id=? ORDER BY id FOR UPDATE', 'i', [$curriculumId]);
    }

    public function loadVersion(int $id): array
    {
        $initial = $this->one('SELECT curriculum_id FROM curriculum_versions WHERE id=?', $id);
        $versions = $this->curriculumVersions((int)$initial['curriculum_id']);
        foreach ($versions as $version) if ((int)$version['id'] === $id) return $version;
        throw new DomainConflictException('Academic context changed. Reload before editing.');
    }

    public static function isEditable(array $version): bool
    {
        // An archived version is history: no content change is allowed, whatever its role or review state.
        if (($version['status'] ?? null) === 'archived') return false;
        $role = $version['version_role'] ?? null;
        $review = $version['review_status'] ?? null;
        $baseline = $version['baseline_version_id'] ?? null;
        if ($role === 'unclassified') return $review === 'not_started' && $baseline === null;
        return $role === 'proposed' && $baseline !== null
            && in_array($review, ['not_started', 'draft', 'returned'], true);
    }

    private function assertState(array $version): void
    {
        if (($version['status'] ?? null) === 'archived') {
            throw new DomainConflictException('This curriculum version is archived and cannot be changed.');
        }
        if (!self::isEditable($version)) {
            throw new DomainConflictException('This curriculum version is protected or has an invalid lifecycle state.');
        }
    }

    public function assertEditable(int $id): array
    {
        $version = $this->loadVersion($id);
        $this->assertState($version);
        if ($version['version_role'] === 'proposed') {
            $this->validateBaseline($id, (int)$version['baseline_version_id']);
        }
        return $version;
    }

    /**
     * Validate only; callers of a future assignment operation must retain these
     * owner/chain locks until their write commits. No baseline write endpoint exists.
     */
    public function validateBaseline(int $versionId, int $baselineId): void
    {
        if ($versionId < 1 || $baselineId < 1) throw new DomainValidationException('Invalid baseline identifier.');
        $version = $this->loadVersion($versionId);
        $chain = $this->curriculumVersions((int)$version['curriculum_id']);
        $byId = array_column($chain, null, 'id');
        $seen = [$versionId => true];
        $next = $baselineId;
        while ($next !== null) {
            if (isset($seen[$next])) throw new DomainConflictException('A baseline cannot reference itself or form a cycle.');
            $seen[$next] = true;
            if (!isset($byId[$next])) throw new DomainConflictException('Baseline must exist in the same curriculum.');
            $node = $byId[$next];
            $role = $node['version_role'];
            $review = $node['review_status'];
            $parent = $node['baseline_version_id'];
            if (!in_array($role, ['unclassified', 'reference', 'proposed'], true)
                || !in_array($review, ['not_started', 'draft', 'under_review', 'returned', 'approved'], true)
                || ($role === 'proposed' ? $parent === null : $parent !== null)
                || ($role === 'unclassified' && $review !== 'not_started')) {
                throw new DomainConflictException('Baseline chain has an invalid lifecycle relationship.');
            }
            $next = $parent === null ? null : (int)$parent;
        }
    }

    /** Resolve parent through stored relationships, then lock/recheck the child. */
    public function assertChildEditable(string $kind, int $id): array
    {
        $queries = [
            'placement' => 'SELECT curriculum_version_id FROM curriculum_version_subjects WHERE id=?',
            'requirement' => 'SELECT curriculum_version_id FROM curriculum_subject_requirements WHERE id=?',
            'group' => 'SELECT curriculum_version_id FROM curriculum_elective_groups WHERE id=?',
            'membership' => 'SELECT m.curriculum_version_id FROM curriculum_elective_group_subjects m JOIN curriculum_elective_groups g ON g.id=m.elective_group_id AND g.curriculum_version_id=m.curriculum_version_id WHERE m.id=?',
            'scheme' => 'SELECT p.curriculum_version_id FROM grade_weighting_schemes s JOIN curriculum_version_subjects p ON p.id=s.curriculum_version_subject_id WHERE s.id=?',
            'component' => 'SELECT p.curriculum_version_id FROM grade_weighting_components c JOIN grade_weighting_schemes s ON s.id=c.scheme_id JOIN curriculum_version_subjects p ON p.id=s.curriculum_version_subject_id WHERE c.id=?',
        ];
        if (!isset($queries[$kind])) throw new InvalidArgumentException('Unsupported academic child.');
        $initial = $this->one($queries[$kind], $id);
        $version = $this->assertEditable((int)$initial['curriculum_version_id']);
        $current = $this->one($queries[$kind] . ' FOR UPDATE', $id);
        if ($current['curriculum_version_id'] !== $initial['curriculum_version_id']) {
            throw new DomainConflictException('Academic context changed. Reload before editing.');
        }
        return $version;
    }

    /** Subject inserts take this lock before owner/version locks, as shared updates do. */
    public function lockSubject(int $id): void
    {
        $this->one('SELECT id FROM subjects WHERE id=? FOR UPDATE', $id);
    }

    /**
     * Guard material master changes. The allowlisted master field projection is
     * supplied by the existing validator. Identical data need not block a no-op.
     */
    public function assertMasterEditable(string $kind, int $id, ?array $data = null): void
    {
        $tables = ['subject'=>'subjects', 'curriculum'=>'curricula', 'program'=>'academic_programs'];
        if (!isset($tables[$kind])) throw new InvalidArgumentException('Unsupported academic master.');
        // Parent program is always locked before curriculum.
        if ($kind === 'curriculum') {
            $this->curriculumVersions($id);
        }
        $old = $this->one('SELECT * FROM ' . $tables[$kind] . ' WHERE id=? FOR UPDATE', $id);
        if ($data !== null) {
            $changed = false;
            foreach ($data as $field=>$value) {
                // DECIMAL values arrive as padded strings from mysqli and floats from validation.
                if (in_array($field, ['units', 'lecture_units', 'laboratory_units'], true)
                    && isset($old[$field]) && $value !== null && (float)$old[$field] === (float)$value) continue;
                if (!array_key_exists($field, $old) || ($old[$field] === null ? null : (string)$old[$field]) !== ($value === null ? null : (string)$value)) $changed = true;
            }
            if (!$changed) return;
        }
        if ($kind === 'subject') {
            // Current locking read, not an earlier consistent snapshot.
            $ids = $this->rows('SELECT DISTINCT curriculum_version_id AS id FROM curriculum_version_subjects WHERE subject_id=? ORDER BY curriculum_version_id FOR UPDATE', 'i', [$id]);
        } elseif ($kind === 'curriculum') {
            $ids = $this->rows('SELECT id FROM curriculum_versions WHERE curriculum_id=? ORDER BY id FOR UPDATE', 'i', [$id]);
        } else {
            $ids = $this->rows('SELECT v.id FROM curricula c JOIN curriculum_versions v ON v.curriculum_id=c.id WHERE c.academic_program_id=? ORDER BY c.id,v.id FOR UPDATE', 'i', [$id]);
        }
        foreach ($ids as $row) $this->assertEditable((int)$row['id']);
    }

    public function assertArchiveEditable(string $kind, int $id): void
    {
        if ($kind === 'elective_group') $this->assertChildEditable('group', $id);
        else $this->assertMasterEditable($kind, $id);
    }

    public function assertRuleScopeEditable(array $rule): void
    {
        // Rules apply to their entire program/curriculum, not only mapped subject.
        if ($rule['curriculum_id'] !== null) $this->assertMasterEditable('curriculum', (int)$rule['curriculum_id']);
        elseif ($rule['academic_program_id'] !== null) $this->assertMasterEditable('program', (int)$rule['academic_program_id']);
        else throw new DomainConflictException('Compliance rule has an invalid scope.');
    }

    public function assertReferenceEditable(int $id): void
    {
        $this->one('SELECT id FROM compliance_references WHERE id=? FOR UPDATE', $id);
        $links = $this->rows('SELECT curriculum_version_id FROM curriculum_version_references WHERE compliance_reference_id=? ORDER BY curriculum_version_id FOR UPDATE', 'i', [$id]);
        foreach ($links as $link) $this->assertEditable((int)$link['curriculum_version_id']);
        $rules = $this->rows('SELECT * FROM compliance_rules WHERE compliance_reference_id=? ORDER BY id FOR UPDATE', 'i', [$id]);
        foreach ($rules as $rule) $this->assertRuleScopeEditable($rule);
    }

    public function preparePlacementRemoval(int $placementId): void
    {
        $version = $this->assertChildEditable('placement', $placementId);
        $snapshots = $this->rows('SELECT curriculum_version_subject_id FROM curriculum_version_subject_snapshots WHERE curriculum_version_subject_id=? FOR UPDATE', 'i', [$placementId]);
        if (!$snapshots) return;
        if ($version['version_role'] !== 'proposed') {
            throw new DomainConflictException('Legacy historical snapshots must be preserved.');
        }
        // Exact stored placement only; FK checks remain enabled. Audit failure rolls back cleanup.
        $stmt = RepositoryQuery::execute($this->db, 'DELETE FROM curriculum_version_subject_snapshots WHERE curriculum_version_subject_id=?', 'i', [$placementId]);
        $stmt->close();
        audit_log_required('CURRICULUM_SUBJECT_SNAPSHOT_REMOVE', 'curriculum_version_subject', $placementId,
            'Editable proposed placement snapshot removed with its placement.',
            ['curriculum_version_id'=>(int)$version['id'], 'version_role'=>$version['version_role']]);
    }
}
