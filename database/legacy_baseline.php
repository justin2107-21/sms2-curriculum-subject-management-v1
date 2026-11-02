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

function csms_legacy_baseline_fingerprint(mysqli $db): string
{
    $schema = $db->query('SELECT DATABASE()')->fetch_row()[0];
    $read = static function (string $sql) use ($db): array {
        return $db->query($sql)->fetch_all(MYSQLI_ASSOC);
    };
    $stableTables = array_values(array_diff(csms_legacy_baseline_tables(), csms_legacy_baseline_mutated_tables()));
    $stable = implode("','", array_map([$db, 'real_escape_string'], $stableTables));
    $future = implode("','", array_map([$db, 'real_escape_string'], csms_legacy_baseline_future_permissions()));
    $stableTableFilter = "TABLE_NAME IN ('$stable')";
    $rows = [
        'tables' => $read("SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='" . $db->real_escape_string($schema) . "' AND $stableTableFilter ORDER BY TABLE_NAME"),
        'columns' => $read("SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_DEFAULT,IS_NULLABLE,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,NUMERIC_PRECISION,NUMERIC_SCALE,COLUMN_TYPE,COLUMN_KEY,EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='" . $db->real_escape_string($schema) . "' AND $stableTableFilter ORDER BY TABLE_NAME,ORDINAL_POSITION"),
        'indexes' => $read("SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,INDEX_TYPE,COLLATION,SUB_PART FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA='" . $db->real_escape_string($schema) . "' AND $stableTableFilter ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX"),
        'foreign_keys' => $read("SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.CONSTRAINT_SCHEMA='" . $db->real_escape_string($schema) . "' AND k.REFERENCED_TABLE_NAME IS NOT NULL AND k.TABLE_NAME IN ('$stable') ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION"),
        'checks' => $read("SELECT tc.TABLE_NAME,tc.CONSTRAINT_NAME,cc.CHECK_CLAUSE FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc JOIN INFORMATION_SCHEMA.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA='" . $db->real_escape_string($schema) . "' AND tc.CONSTRAINT_TYPE='CHECK' AND tc.TABLE_NAME IN ('$stable') ORDER BY tc.TABLE_NAME,tc.CONSTRAINT_NAME"),
        'roles' => $read('SELECT id,name,description FROM roles ORDER BY id'),
        'permissions' => $read("SELECT id,name,module,description FROM permissions WHERE name NOT IN ('$future') ORDER BY id"),
        'role_permissions' => $read("SELECT rp.role_id,rp.permission_id FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.name NOT IN ('$future') ORDER BY rp.role_id,rp.permission_id"),
    ];
    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
