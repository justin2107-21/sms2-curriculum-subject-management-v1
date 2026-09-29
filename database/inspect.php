<?php
// Read-only CLI integrity report; emits counts and constraint names, never row data.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/db.php';

$schema = $conn->query('SELECT DATABASE()')->fetch_row()[0];
$quotedSchema = $conn->real_escape_string($schema);
$counts = [];
$issues = [];
foreach ($conn->query('SHOW TABLES')->fetch_all(MYSQLI_NUM) as [$table]) {
    $counts[$table] = (int)$conn->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0];
    foreach ($conn->query("CHECK TABLE `$table`")->fetch_all(MYSQLI_ASSOC) as $check) {
        if ($check['Msg_type'] !== 'status' || $check['Msg_text'] !== 'OK') {
            $issues[] = 'Table check: ' . $table;
        }
    }
}
$keys = [];
$sql = "SELECT * FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='$quotedSchema' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION";
foreach ($conn->query($sql)->fetch_all(MYSQLI_ASSOC) as $column) {
    $keys[$column['TABLE_NAME'] . '.' . $column['CONSTRAINT_NAME']][] = $column;
}
foreach ($keys as $name => $columns) {
    $join = []; $notNull = [];
    foreach ($columns as $c) {
        $join[] = 'child.`' . $c['COLUMN_NAME'] . '` = parent.`' . $c['REFERENCED_COLUMN_NAME'] . '`';
        $notNull[] = 'child.`' . $c['COLUMN_NAME'] . '` IS NOT NULL';
    }
    $first = $columns[0];
    $query = 'SELECT COUNT(*) FROM `' . $first['TABLE_NAME'] . '` child WHERE ' . implode(' AND ', $notNull)
        . ' AND NOT EXISTS (SELECT 1 FROM `' . $first['REFERENCED_TABLE_NAME'] . '` parent WHERE ' . implode(' AND ', $join) . ')';
    $orphans = (int)$conn->query($query)->fetch_row()[0];
    if ($orphans) { $issues[] = "$name: $orphans orphan(s)"; }
}
$permissions = $conn->query('SELECT r.name,COUNT(rp.permission_id) AS permission_count FROM roles r LEFT JOIN role_permissions rp ON rp.role_id=r.id GROUP BY r.id,r.name ORDER BY r.name')->fetch_all(MYSQLI_ASSOC);
echo json_encode(['database' => $schema, 'counts' => $counts, 'foreign_keys_checked' => count($keys), 'roles' => $permissions, 'issues' => $issues], JSON_PRETTY_PRINT) . PHP_EOL;
exit($issues ? 1 : 0);
