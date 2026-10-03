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
        return '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], self::normalizeSearch($search)) . '%';
    }

    public static function normalizeSearch(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
    }

    /** Only repository-owned column identifiers may be passed here. */
    public static function searchColumn(string $column): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/D', $column)) throw new InvalidArgumentException('Invalid search column.');
        return "LOWER(TRIM(REGEXP_REPLACE(COALESCE($column, ''), '[[:space:]]+', ' ')))";
    }

    /** Ranking is bound and applied BEFORE pagination; empty searches keep existing order. */
    public static function searchOrder(array $fields, ?string $query, array &$params): string
    {
        $query = self::normalizeSearch($query ?? '');
        if ($query === '') return '';
        $prefix = substr(self::likePattern($query), 1);
        $token = '(^|[^[:alnum:]_])' . preg_quote($query, '/');
        $ranks = [];
        foreach ($fields as $field) {
            $column = self::searchColumn($field);
            $ranks[] = "CASE WHEN $column = ? THEN 0 WHEN $column LIKE ? ESCAPE '=' THEN 1 WHEN $column REGEXP ? THEN 2 ELSE 3 END";
            array_push($params, $query, $prefix, $token);
        }
        $rank = count($ranks) === 1 ? $ranks[0] : 'LEAST(' . implode(',', $ranks) . ')';
        // At equal relevance prefer the entity's code/name field to secondary title matches.
        array_push($params, $query, $prefix, $token);
        return $rank . ', ' . $ranks[0] . ', ' . implode(', ', array_map([self::class, 'searchColumn'], $fields)) . ', ';
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
