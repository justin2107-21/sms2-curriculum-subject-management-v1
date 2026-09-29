<?php

/**
 * Small query helpers shared by the plain-PHP curriculum repositories.
 *
 * SQL fragments remain repository-owned and predetermined. This helper only
 * binds already validated values and builds consistent pagination metadata.
 */
class RepositoryQuery
{
    public static function execute(mysqli $db, string $sql, string $types = '', array $params = []): mysqli_stmt
    {
        $stmt = $db->prepare($sql);
        if ($types !== '') {
            $bind = [$types];
            foreach ($params as $index => $value) {
                $params[$index] = $value;
                $bind[] = &$params[$index];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
        return $stmt;
    }

    public static function likePattern(string $search): string
    {
        // '=' is the explicitly declared LIKE escape character in list queries.
        return '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search) . '%';
    }

    public static function pagination(int $totalRecords, int $requestedPage, int $perPage): array
    {
        $totalPages = max(1, (int)ceil($totalRecords / $perPage));
        $page = min($requestedPage, $totalPages);

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages,
            'offset' => ($page - 1) * $perPage,
        ];
    }
}
