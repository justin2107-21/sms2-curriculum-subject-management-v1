<?php
// Read-only drift report: live grants of the five current roles against the approved matrix in
// docs/ui-reference/Roles_and_Permissions.md, section 4. It warns only: it never writes and never blocks a deploy.
// Usage: $env:DB_NAME='sms_db'; php tools\rbac-drift-report.php
// Exit 0 with or without drift; exit 1 only when the report could not be produced.

const CSMS_DRIFT_ROLES = ['super_admin', 'admin', 'staff', 'acad_office', 'acad_faculty'];

/** @return array<string,string[]> role => permissions granted by the matrix */
function csms_drift_parse_matrix(string $markdown): array
{
    $matrix = array_fill_keys(CSMS_DRIFT_ROLES, []);
    $rows = 0;
    foreach (preg_split('/\R/', $markdown) as $line) {
        if (!str_starts_with($line, '| `')) continue;
        $cells = array_map('trim', explode('|', trim($line, " |")));
        if (count($cells) !== 6) continue;
        preg_match_all('/`([^`]+)`/', $cells[0], $names);
        $keys = []; $prefix = '';
        foreach ($names[1] as $name) {
            if ($name[0] === '.') { $keys[] = $prefix . $name; continue; }
            $keys[] = $name;
            $prefix = substr($name, 0, (int)strrpos($name, '.'));
        }
        foreach (CSMS_DRIFT_ROLES as $i => $role) {
            $cell = $cells[$i + 1];
            if (str_starts_with($cell, '✓') || str_starts_with($cell, '◐')) array_push($matrix[$role], ...$keys);
            elseif (!str_starts_with($cell, '—')) throw new RuntimeException("Unreadable matrix cell: $cell");
        }
        $rows++;
    }
    if ($rows === 0) throw new RuntimeException('No permission matrix found.');
    return $matrix;
}

/** @return array<string,array{missing:string[],extra:string[]}> differences only */
function csms_drift_compare(array $matrix, array $live): array
{
    $out = [];
    foreach (CSMS_DRIFT_ROLES as $role) {
        $missing = array_values(array_diff($matrix[$role] ?? [], $live[$role] ?? []));
        $extra = array_values(array_diff($live[$role] ?? [], $matrix[$role] ?? []));
        sort($missing); sort($extra);
        if ($missing || $extra) $out[$role] = ['missing' => $missing, 'extra' => $extra];
    }
    return $out;
}

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;

try {
    require_once __DIR__ . '/../shared/database_config.php';
    $config = csms_database_config();
    $db = csms_database_connect($config);
    $db->query('SET SESSION TRANSACTION READ ONLY');
    $markdown = file_get_contents(__DIR__ . '/../docs/ui-reference/Roles_and_Permissions.md');
    if ($markdown === false) throw new RuntimeException('Cannot read the matrix document.');
    $matrix = csms_drift_parse_matrix($markdown);
    $live = array_fill_keys(CSMS_DRIFT_ROLES, []);
    $known = [];
    foreach ($db->query('SELECT name FROM permissions')->fetch_all(MYSQLI_NUM) as $row) $known[$row[0]] = true;
    foreach ($db->query("SELECT r.name AS role_name, p.name AS permission FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE r.name IN ('" . implode("','", CSMS_DRIFT_ROLES) . "')")->fetch_all(MYSQLI_ASSOC) as $row) {
        $live[$row['role_name']][] = $row['permission'];
    }
    $drift = csms_drift_compare($matrix, $live);
    echo "RBAC drift report for {$config['database']} (read-only, warning only)\n";
    foreach (CSMS_DRIFT_ROLES as $role) echo sprintf("  %-13s matrix %2d, live %2d\n", $role, count($matrix[$role]), count($live[$role]));
    foreach (array_diff(array_merge(...array_values($matrix)), array_keys($known)) as $name) echo "WARNING matrix names a permission the database does not have: $name\n";
    if ($drift === []) { echo "No differences.\n"; exit(0); }
    foreach ($drift as $role => $d) {
        foreach ($d['missing'] as $name) echo "WARNING $role: in the matrix, not granted: $name\n";
        foreach ($d['extra'] as $name) echo "WARNING $role: granted, not in the matrix: $name\n";
    }
    echo count($drift) . " role(s) differ. This does not block anything; review them on the Permissions page.\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Report failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
