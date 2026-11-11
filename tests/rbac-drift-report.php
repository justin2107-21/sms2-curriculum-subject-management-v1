<?php
// DB-free checks for tools/rbac-drift-report.php: matrix parsing and comparison.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../tools/rbac-drift-report.php';
$failures = 0; $checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void { $checks++; if (!$ok) { $failures++; echo "FAIL $label\n"; } };

$m = csms_drift_parse_matrix((string)file_get_contents(__DIR__ . '/../docs/ui-reference/Roles_and_Permissions.md'));
$check(in_array('student.status.update', $m['super_admin'], true) && !in_array('student.view', $m['admin'], true), 'shorthand keys expand');
$check(in_array('user.manage', $m['admin'], true), 'a scoped cell counts as granted');
$check(in_array('curriculum.clo.manage', $m['staff'], true) && !in_array('curriculum.clo.manage', $m['acad_faculty'], true), 'annotated cells parse');
$check(count($m['super_admin']) === 52, 'Super Admin has all 52 keys, got ' . count($m['super_admin']));
$check(csms_drift_compare($m, $m) === [], 'identical sets have no drift');
$live = $m; $live['staff'][] = 'rbac.manage'; array_shift($live['admin']);
$d = csms_drift_compare($m, $live);
$check($d['staff']['extra'] === ['rbac.manage'] && $d['staff']['missing'] === [], 'extra grant is listed');
$check(count($d['admin']['missing']) === 1 && !isset($d['super_admin']), 'missing grant is listed, others stay clean');
$threw = false;
try { csms_drift_parse_matrix('nothing here'); } catch (RuntimeException) { $threw = true; }
$check($threw, 'a missing matrix is an error, not a clean report');
echo $failures ? "FAILED $failures of $checks\n" : "PASS $checks checks\n";
exit($failures ? 1 : 0);
