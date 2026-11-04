<?php

/**
 * Which versions a user may read and export, enforced on the server.
 *
 * Holders of curriculum.view_unapproved read every version. Everyone else (Acad Faculty) gets the scope below.
 * THE ONE SETTING: change FACULTY_READ_SCOPE to 'approved' once approvals exist (or set env CSMS_FACULTY_READ_SCOPE).
 */
final class CurriculumReadScope
{
    public const FACULTY_READ_SCOPE = 'active_or_approved';

    public static function setting(): string
    {
        $value = getenv('CSMS_FACULTY_READ_SCOPE');
        $value = $value === false || $value === '' ? self::FACULTY_READ_SCOPE : $value;
        // Any unknown value falls back to the stricter rule.
        return $value === 'active_or_approved' ? $value : 'approved';
    }

    public static function restricted(?int $userId): bool
    {
        return $userId !== null && !user_can($userId, 'curriculum.view_unapproved');
    }

    /** Constant SQL (no user input) limiting a versions alias for this user, or '' when unrestricted or outside a request. */
    public static function sql(string $alias, ?int $userId = null): string
    {
        $userId ??= function_exists('current_user_id') ? current_user_id() : null;
        if (!self::restricted($userId)) return '';
        $approved = "$alias.review_status = 'approved'";
        return self::setting() === 'approved' ? "($approved)" : "($approved OR $alias.status = 'active')";
    }

    public static function allows(array $version, ?int $userId): bool
    {
        return !self::restricted($userId) || self::inRestrictedScope($version);
    }

    public static function inRestrictedScope(array $version): bool
    {
        if (($version['review_status'] ?? '') === 'approved') return true;
        return self::setting() === 'active_or_approved' && ($version['status'] ?? '') === 'active';
    }

    /** Refuses the request when any version named in the input, directly or through a child record, is out of scope. */
    public static function enforceInput(mysqli $db, array $input): void
    {
        $userId = current_user_id();
        if (!self::restricted($userId)) return;
        $lookups = [
            'curriculum_version_id' => 'SELECT status, review_status FROM curriculum_versions WHERE id = ?',
            'version_id' => 'SELECT status, review_status FROM curriculum_versions WHERE id = ?',
            'version_a_id' => 'SELECT status, review_status FROM curriculum_versions WHERE id = ?',
            'version_b_id' => 'SELECT status, review_status FROM curriculum_versions WHERE id = ?',
            'source_version_id' => 'SELECT status, review_status FROM curriculum_versions WHERE id = ?',
            'placement_id' => 'SELECT v.status, v.review_status FROM curriculum_version_subjects x JOIN curriculum_versions v ON v.id = x.curriculum_version_id WHERE x.id = ?',
            'curriculum_version_subject_id' => 'SELECT v.status, v.review_status FROM curriculum_version_subjects x JOIN curriculum_versions v ON v.id = x.curriculum_version_id WHERE x.id = ?',
            'elective_group_id' => 'SELECT v.status, v.review_status FROM curriculum_elective_groups x JOIN curriculum_versions v ON v.id = x.curriculum_version_id WHERE x.id = ?',
        ];
        foreach ($lookups as $key => $sql) {
            $id = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) continue;
            self::enforceRow($db, $sql, $id, $userId);
        }
    }

    public static function enforceVersion(mysqli $db, int $versionId): void
    {
        self::enforceRow($db, 'SELECT status, review_status FROM curriculum_versions WHERE id = ?', $versionId, current_user_id());
    }

    private static function enforceRow(mysqli $db, string $sql, int $id, ?int $userId): void
    {
        if (!self::restricted($userId)) return;
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && !self::allows($row, $userId)) {
            json_response(false, 'This curriculum version is not available to your role.', [], 403);
        }
    }

    /** Approved versions need the official key; anything else needs the draft key. */
    public static function canExport(array $version, ?int $userId): bool
    {
        if ($userId === null) return false;
        return user_can($userId, ($version['review_status'] ?? '') === 'approved' ? 'curriculum_export.official' : 'curriculum_export.draft');
    }

    public static function exportSql(string $alias, ?int $userId = null): string
    {
        $userId ??= function_exists('current_user_id') ? current_user_id() : null;
        if ($userId === null) return '';
        $official = $userId !== null && user_can($userId, 'curriculum_export.official');
        $draft = $userId !== null && user_can($userId, 'curriculum_export.draft');
        if ($official && $draft) return '';
        if ($official) return "$alias.review_status = 'approved'";
        if ($draft) return "$alias.review_status <> 'approved'";
        return '0 = 1';
    }

    /** True when the user may export this version; false when out of scope. Null version is left to the normal not-found path. */
    public static function exportAllowed(mysqli $db, mixed $versionId): bool
    {
        $id = filter_var($versionId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) return true;
        $stmt = $db->prepare('SELECT status, review_status FROM curriculum_versions WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return !$row || self::canExport($row, current_user_id());
    }
}
