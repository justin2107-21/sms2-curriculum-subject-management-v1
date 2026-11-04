<?php

require_once __DIR__ . '/../validators/DomainExceptions.php';
require_once __DIR__ . '/../../../shared/audit.php';

/**
 * Submit / approve / return for proposed curriculum revisions. Writes the review_status column and the
 * review-events table from migration 021. Technical status, baseline and references are not touched.
 * A version under review or approved is already protected by CurriculumVersionEditPolicy.
 */
final class CurriculumReviewService
{
    private const MAX_REASON = 2000;

    public function __construct(private mysqli $db) {}

    public function history(int $versionId): array
    {
        $version = $this->version($versionId, false);
        $stmt = $this->db->prepare(
            "SELECT e.action, e.previous_status, e.new_status, e.comments, e.created_at,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS actor_name, e.actor_user_id
             FROM curriculum_version_review_events e JOIN users u ON u.id = e.actor_user_id
             WHERE e.curriculum_version_id = ? ORDER BY e.id DESC");
        $stmt->bind_param('i', $versionId);
        $stmt->execute();
        $events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return ['version_id' => $versionId, 'review_status' => $version['review_status'], 'version_role' => $version['version_role'],
            'can_submit' => self::submittable($version), 'can_decide' => $version['review_status'] === 'under_review', 'events' => $events];
    }

    public function submit(int $versionId, string $comment, int $actor): string
    {
        return $this->transition($versionId, $actor, 'submit', 'under_review', $comment, static function (array $version): void {
            if (!self::submittable($version)) {
                throw new DomainConflictException('Only a proposed revision that is not already under review or approved can be submitted.');
            }
        });
    }

    public function decide(int $versionId, string $decision, string $reason, int $actor): string
    {
        if (!in_array($decision, ['approve', 'return'], true)) {
            throw new DomainValidationException('Choose approve or return.');
        }
        if (trim($reason) === '') {
            throw new DomainValidationException('Enter the reason for this decision.');
        }
        $target = $decision === 'approve' ? 'approved' : 'returned';
        return $this->transition($versionId, $actor, $decision, $target, $reason, function (array $version) use ($versionId, $actor): void {
            if ($version['review_status'] !== 'under_review') {
                throw new DomainConflictException('Only a version under review can be approved or returned.');
            }
            if ($this->lastSubmitter($versionId) === $actor) {
                throw new DomainConflictException('You submitted this version, so another reviewer must decide it.');
            }
        });
    }

    private static function submittable(array $version): bool
    {
        return $version['version_role'] === 'proposed' && in_array($version['review_status'], ['not_started', 'draft', 'returned'], true);
    }

    private function transition(int $versionId, int $actor, string $action, string $target, string $comment, callable $guard): string
    {
        $comment = trim($comment);
        if (mb_strlen($comment) > self::MAX_REASON) {
            throw new DomainValidationException('The comment is too long.');
        }
        $this->db->begin_transaction();
        try {
            $version = $this->version($versionId, true);
            $guard($version);
            $previous = $version['review_status'];
            $stmt = $this->db->prepare('UPDATE curriculum_versions SET review_status = ? WHERE id = ?');
            $stmt->bind_param('si', $target, $versionId);
            $stmt->execute();
            $stmt->close();
            $stmt = $this->db->prepare('INSERT INTO curriculum_version_review_events (curriculum_version_id, actor_user_id, action, previous_status, new_status, comments) VALUES (?, ?, ?, ?, ?, ?)');
            $note = $comment === '' ? null : $comment;
            $stmt->bind_param('iissss', $versionId, $actor, $action, $previous, $target, $note);
            $stmt->execute();
            $stmt->close();
            audit_log_required('CURRICULUM_REVIEW_' . strtoupper($action), 'curriculum_version', $versionId,
                'Curriculum version review status changed.', ['previous_status' => $previous, 'new_status' => $target]);
            $this->db->commit();
            return $target;
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private function version(int $versionId, bool $lock): array
    {
        $stmt = $this->db->prepare('SELECT id, version_role, review_status, status FROM curriculum_versions WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->bind_param('i', $versionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) throw new DomainNotFoundException('Curriculum version not found.');
        return $row;
    }

    private function lastSubmitter(int $versionId): ?int
    {
        $stmt = $this->db->prepare("SELECT actor_user_id FROM curriculum_version_review_events WHERE curriculum_version_id = ? AND action = 'submit' ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('i', $versionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return $row ? (int)$row[0] : null;
    }
}
