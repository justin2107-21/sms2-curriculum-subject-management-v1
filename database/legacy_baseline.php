<?php

function csms_legacy_baseline_tables(): array
{
    return [
        'academic_programs', 'audit_logs', 'cimf_ai_logs', 'compliance_references',
        'compliance_rules', 'compliance_validation_results', 'compliance_validation_runs',
        'curricula', 'curriculum_elective_groups', 'curriculum_elective_group_subjects',
        'curriculum_subject_requirements', 'curriculum_versions', 'curriculum_version_subjects',
        'grade_weighting_components', 'grade_weighting_schemes', 'permissions',
        'program_subject_assignments', 'roles', 'role_permissions', 'students', 'subjects',
        'subject_equivalencies', 'users', 'user_roles',
    ];
}

function csms_legacy_baseline_mutated_tables(): array
{
    return ['curriculum_versions', 'compliance_references', 'curriculum_version_references', 'curriculum_version_subject_snapshots'];
}

function csms_legacy_baseline_future_permissions(): array
{
    return ['curriculum.reference.attach', 'compliance.reference.verify', 'curriculum.reference.classify',
        'curriculum.version.clone', 'curriculum.outcome_mapping.manage', 'curriculum.clo.manage',
        'curriculum.assessment_plan.manage'];
}

/**
 * Reviewed changes that approved migrations made to fingerprint-covered state, keyed by migration file.
 * An entry counts only while schema_migrations shows that migration applied with this exact checksum, and only
 * if everything it declares exists exactly as declared; otherwise the fingerprint fails. See database/README.md.
 * Keys: checksum; columns (table => column => COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT); indexes, foreign_keys,
 * checks (table => names); roles_added; roles_renamed (current name => name in the baseline); permissions_added.
 */
function csms_legacy_baseline_declared_changes(): array
{
    return [
        '027_final_user_types.sql' => [
            'checksum' => '40d65d77a005f889c65c7af194f6fc428cd8bd18af573990583af635a8eba690',
            'columns' => ['roles' => [
                'user_type' => ['COLUMN_TYPE' => 'varchar(30)', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => 'NULL'],
                'is_active' => ['COLUMN_TYPE' => 'tinyint(1)', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => '1'],
            ]],
            'roles_added' => ['admin', 'super_admin', 'staff', 'acad_office', 'acad_faculty'],
            'roles_renamed' => ['legacy_admin' => 'admin'],
            'permissions_added' => ['user.manage', 'curriculum.view_unapproved', 'curriculum.review.submit',
                'curriculum.review.decide', 'curriculum_export.draft', 'curriculum_export.official'],
        ],
    ];
}

/** Declared changes whose migration is recorded as applied with the pinned checksum. Strict (none) without a ledger. */
function csms_legacy_baseline_applied_changes(mysqli $db): array
{
    if ($db->query("SHOW TABLES LIKE 'schema_migrations'")->num_rows === 0) return [];
    $ledger = [];
    foreach ($db->query("SELECT migration, checksum FROM schema_migrations WHERE status = 'applied'")->fetch_all(MYSQLI_ASSOC) as $row) {
        $ledger[$row['migration']] = $row['checksum'];
    }
    return array_filter(csms_legacy_baseline_declared_changes(),
        static fn(array $change, string $name): bool => isset($ledger[$name]) && hash_equals($change['checksum'], strtolower($ledger[$name])),
        ARRAY_FILTER_USE_BOTH);
}

/** Current covered state, unmodified. */
function csms_legacy_baseline_state(mysqli $db): array
{
    $schema = $db->query('SELECT DATABASE()')->fetch_row()[0];
    $read = static function (string $sql) use ($db): array {
        return $db->query($sql)->fetch_all(MYSQLI_ASSOC);
    };
    $stableTables = array_values(array_diff(csms_legacy_baseline_tables(), csms_legacy_baseline_mutated_tables()));
    $stable = implode("','", array_map([$db, 'real_escape_string'], $stableTables));
    $future = implode("','", array_map([$db, 'real_escape_string'], csms_legacy_baseline_future_permissions()));
    $stableTableFilter = "TABLE_NAME IN ('$stable')";
    return [
        'tables' => $read("SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='" . $db->real_escape_string($schema) . "' AND $stableTableFilter ORDER BY TABLE_NAME"),
        'columns' => $read("SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_DEFAULT,IS_NULLABLE,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,NUMERIC_PRECISION,NUMERIC_SCALE,COLUMN_TYPE,COLUMN_KEY,EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='" . $db->real_escape_string($schema) . "' AND $stableTableFilter ORDER BY TABLE_NAME,ORDINAL_POSITION"),
        'indexes' => $read("SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,INDEX_TYPE,COLLATION,SUB_PART FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA='" . $db->real_escape_string($schema) . "' AND $stableTableFilter ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX"),
        'foreign_keys' => $read("SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.CONSTRAINT_SCHEMA='" . $db->real_escape_string($schema) . "' AND k.REFERENCED_TABLE_NAME IS NOT NULL AND k.TABLE_NAME IN ('$stable') ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION"),
        'checks' => $read("SELECT tc.TABLE_NAME,tc.CONSTRAINT_NAME,cc.CHECK_CLAUSE FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc JOIN INFORMATION_SCHEMA.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA='" . $db->real_escape_string($schema) . "' AND tc.CONSTRAINT_TYPE='CHECK' AND tc.TABLE_NAME IN ('$stable') ORDER BY tc.TABLE_NAME,tc.CONSTRAINT_NAME"),
        'roles' => $read('SELECT id,name,description FROM roles ORDER BY id'),
        'permissions' => $read("SELECT id,name,module,description FROM permissions WHERE name NOT IN ('$future') ORDER BY id"),
        'role_permissions' => $read("SELECT rp.role_id,rp.permission_id FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.name NOT IN ('$future') ORDER BY rp.role_id,rp.permission_id"),
    ];
}

/**
 * Removes exactly the declared changes, giving the state the baseline recorded. No database access. Anything declared
 * but absent or different is added to the result under declared_change_errors, so the fingerprint cannot match.
 * Grants held by declared new roles are removed; a new permission granted to a legacy role stays and is detected.
 */
function csms_legacy_baseline_normalize(array $state, array $changes): array
{
    $errors = [];
    foreach ($changes as $migration => $change) {
        foreach ($change['columns'] ?? [] as $table => $columns) {
            foreach ($columns as $column => $shape) {
                $found = false;
                foreach ($state['columns'] as $i => $row) {
                    if ($row['TABLE_NAME'] !== $table || $row['COLUMN_NAME'] !== $column) continue;
                    $found = true;
                    foreach ($shape as $key => $value) {
                        if ((string)$row[$key] !== $value) $errors[] = "$migration: $table.$column $key";
                    }
                    unset($state['columns'][$i]);
                }
                if (!$found) $errors[] = "$migration: $table.$column missing";
            }
            $position = 0;
            foreach ($state['columns'] as $i => $row) {
                if ($row['TABLE_NAME'] === $table) $state['columns'][$i]['ORDINAL_POSITION'] = (string)++$position;
            }
        }
        foreach (['indexes' => 'INDEX_NAME', 'foreign_keys' => 'CONSTRAINT_NAME', 'checks' => 'CONSTRAINT_NAME'] as $group => $nameKey) {
            foreach ($change[$group] ?? [] as $table => $names) {
                foreach ($names as $name) {
                    $before = count($state[$group]);
                    $state[$group] = array_filter($state[$group], static fn(array $r): bool => !($r['TABLE_NAME'] === $table && $r[$nameKey] === $name));
                    if (count($state[$group]) === $before) $errors[] = "$migration: $group $table.$name missing";
                }
            }
        }
        $addedRoleIds = [];
        foreach ($change['roles_added'] ?? [] as $name) {
            $found = false;
            foreach ($state['roles'] as $i => $row) {
                if ($row['name'] === $name) { $found = true; $addedRoleIds[(string)$row['id']] = true; unset($state['roles'][$i]); }
            }
            if (!$found) $errors[] = "$migration: role $name missing";
        }
        $state['role_permissions'] = array_filter($state['role_permissions'], static fn(array $r): bool => !isset($addedRoleIds[(string)$r['role_id']]));
        foreach ($change['roles_renamed'] ?? [] as $current => $original) {
            $found = false;
            foreach ($state['roles'] as $i => $row) {
                if ($row['name'] === $current) { $found = true; $state['roles'][$i]['name'] = $original; }
            }
            if (!$found) $errors[] = "$migration: renamed role $current missing";
        }
        foreach ($change['permissions_added'] ?? [] as $name) {
            $found = false;
            foreach ($state['permissions'] as $i => $row) {
                if ($row['name'] === $name) { $found = true; unset($state['permissions'][$i]); }
            }
            if (!$found) $errors[] = "$migration: permission $name missing";
        }
    }
    $state = array_map('array_values', $state);
    if ($errors !== []) $state['declared_change_errors'] = $errors;
    return $state;
}

function csms_legacy_baseline_hash(array $state): string
{
    return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function csms_legacy_baseline_fingerprint(mysqli $db): string
{
    return csms_legacy_baseline_hash(csms_legacy_baseline_normalize(csms_legacy_baseline_state($db), csms_legacy_baseline_applied_changes($db)));
}

/** @return string[] problems found in one migration against its declared entry (null when it has none). */
function csms_legacy_baseline_undeclared(string $sql, ?array $entry): array
{
    $problems = [];
    $code = preg_replace(['~/\*.*?\*/~s', '/^\s*(--|#).*$/m'], '', $sql);
    $code = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", $code);
    $stable = array_values(array_diff(csms_legacy_baseline_tables(), csms_legacy_baseline_mutated_tables()));
    if (preg_match('/\b(PREPARE|EXECUTE\s+IMMEDIATE|DELIMITER|CREATE\s+(?:DEFINER\S*\s+)?PROCEDURE)\b/i', $code, $m)) {
        $problems[] = "dynamic SQL cannot be checked: {$m[1]}";
    }
    foreach (array_filter(array_map('trim', explode(';', $code))) as $statement) {
        $name = '`?([A-Za-z0-9_]+)`?';
        if (preg_match("/^ALTER\s+(?:IGNORE\s+)?TABLE\s+$name\s+(.*)$/is", $statement, $m)) {
            $table = strtolower($m[1]);
            if (!in_array($table, $stable, true)) continue;
            $body = $m[2];
            foreach (['MODIFY', 'CHANGE', 'DROP', 'RENAME', 'ENGINE', 'CONVERT', 'CHARACTER\s+SET', 'COLLATE\s*=', 'ALTER\s+COLUMN'] as $op) {
                if (preg_match("/(?:^|,)\s*$op\b/i", $body)) $problems[] = "$table: this ALTER changes existing baseline schema and cannot be declared";
            }
            $declare = static function (string $pattern, string $key) use (&$body, $entry, $table, &$problems): void {
                preg_match_all($pattern, $body, $hits);
                foreach ($hits[1] as $item) {
                    $declared = $key === 'columns' ? array_keys($entry['columns'][$table] ?? []) : ($entry[$key][$table] ?? []);
                    if (!in_array($item, $declared, true)) $problems[] = "$table: $key entry missing for $item";
                }
                $body = preg_replace($pattern, '', $body);
            };
            $declare('/\bADD\s+(?:UNIQUE\s+|FULLTEXT\s+)?(?:INDEX|KEY)\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', 'indexes');
            preg_match_all('/\bADD\s+CONSTRAINT\s+`?(\w+)`?\s+FOREIGN\s+KEY/i', $body, $fk);
            foreach ($fk[1] as $item) if (!in_array($item, $entry['foreign_keys'][$table] ?? [], true)) $problems[] = "$table: foreign_keys entry missing for $item";
            $body = preg_replace('/\bADD\s+CONSTRAINT\s+`?\w+`?\s+FOREIGN\s+KEY/i', '', $body);
            $declare('/\bADD\s+CONSTRAINT\s+`?(\w+)`?\s+CHECK/i', 'checks');
            $declare('/\bADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', 'columns');
            if (preg_match('/\bADD\b/i', $body)) $problems[] = "$table: unrecognised ADD clause; write ADD COLUMN, ADD INDEX or ADD CONSTRAINT with a name";
            continue;
        }
        if (preg_match("/^(?:CREATE\s+(?:UNIQUE\s+)?INDEX\s+\S+\s+ON|DROP\s+INDEX\s+\S+\s+ON|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|RENAME\s+TABLE|TRUNCATE(?:\s+TABLE)?|CREATE\s+TRIGGER\s+.*?\sON)\s+$name/is", $statement, $m)
            && in_array(strtolower($m[1]), $stable, true)) {
            $problems[] = strtolower($m[1]) . ': write this as a named ALTER TABLE clause, or review it by hand';
        }
        if (preg_match("/^(INSERT(?:\s+IGNORE)?(?:\s+INTO)?|REPLACE(?:\s+INTO)?|UPDATE|DELETE(?:\s+\w+)?\s+FROM)\s+$name/is", $statement, $m)) {
            $table = strtolower($m[2]);
            $isInsert = stripos($m[1], 'INSERT') === 0;
            if ($table === 'roles' && !($isInsert ? !empty($entry['roles_added']) : !empty($entry['roles_renamed']))) $problems[] = 'roles: rows changed without roles_added or roles_renamed';
            if ($table === 'permissions' && !($isInsert && !empty($entry['permissions_added']))) $problems[] = 'permissions: rows changed without permissions_added';
            if ($table === 'role_permissions' && !($isInsert && (!empty($entry['roles_added']) || !empty($entry['new_role_grants_only'])))) $problems[] = 'role_permissions: grants changed without roles_added or new_role_grants_only';
        }
    }
    return array_values(array_unique($problems));
}

/**
 * Declaration rule for one migration (CRLF normalised, USE line removed). It starts at 027, the first migration
 * after the fingerprint's exclusions were frozen; earlier files are history, and a new install still runs them.
 * @return string[] problems; empty means the migration may run.
 */
function csms_legacy_baseline_migration_problems(string $name, string $sql): array
{
    if (strcmp($name, '027') < 0) return [];
    $entry = csms_legacy_baseline_declared_changes()[$name] ?? null;
    $problems = csms_legacy_baseline_undeclared($sql, $entry);
    if ($entry !== null && !hash_equals($entry['checksum'], hash('sha256', $sql))) $problems[] = 'file does not match the pinned checksum';
    return $problems;
}

function csms_legacy_baseline_shape_is_expected(mysqli $db): bool
{
    $tables = array_column($db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM), 0);
    sort($tables, SORT_STRING);
    $expected = csms_legacy_baseline_tables();
    sort($expected, SORT_STRING);
    return $tables === $expected && !in_array('schema_migrations', $tables, true)
        && !in_array('schema_baseline_adoptions', $tables, true);
}
