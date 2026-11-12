<?php
// Each role's dashboard payload holds only that role's cards. Read-only. DB_NAME must be sms_db_uitest.
if (getenv('DB_NAME') !== 'sms_db_uitest') { fwrite(STDERR, "Refusing: set DB_NAME=sms_db_uitest\n"); exit(1); }
require_once __DIR__ . '/../shared/bootstrap.php';
require_once __DIR__ . '/../dashboard/DashboardAnalyticsService.php';
$db = authorization_connection();
$failures = 0; $checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void { $checks++; if (!$ok) { $failures++; echo "FAIL $label\n"; } };

$expected = [
    'uitest.superadmin' => ['super_admin', ['active_accounts', 'password_change', 'audit_7d', 'awaiting'], ['accounts_by_role', 'audit_weekly', 'pipeline'], ['rbac_changes', 'password_accounts']],
    'uitest.admin' => ['admin', ['programs', 'curricula', 'approved', 'awaiting'], ['pipeline', 'completeness', 'programs_by_level'], ['recently_approved', 'export_log', 'no_curriculum']],
    'uitest.staff' => ['staff', ['my_drafts', 'returned', 'waiting', 'failing'], ['term_load', 'prerequisite_health', 'compliance_working', 'units_by_category'], ['returned_list', 'my_drafts', 'needs_attention']],
    'uitest.acadoffice' => ['acad_office', ['awaiting_mine', 'avg_days', 'approved_month', 'returned_month'], ['review_outcomes', 'decision_split', 'pipeline', 'compliance_under_review'], ['awaiting_review', 'my_decisions']],
    'uitest.acadfaculty' => ['acad_faculty', ['approved', 'outcomes', 'my_subjects'], ['outcome_coverage', 'term_load', 'units_by_category'], ['approved_curricula', 'recently_approved']],
];
$forbidden = [
    'uitest.staff' => ['accounts_by_role', 'audit_weekly', 'rbac_changes', 'password_accounts', 'active_accounts', 'export_log'],
    'uitest.acadfaculty' => ['my_drafts', 'accounts_by_role', 'rbac_changes', 'needs_attention', 'pipeline', 'compliance_under_review', 'export_log'],
    'uitest.admin' => ['rbac_changes', 'password_accounts', 'accounts_by_role', 'audit_weekly', 'my_drafts', 'awaiting_review'],
    'uitest.acadoffice' => ['accounts_by_role', 'rbac_changes', 'password_accounts', 'my_drafts', 'export_log'],
];
$service = new DashboardAnalyticsService($db, ['dashboard.show_pending_charts' => true]);
foreach ($expected as $username => [$profile, $stats, $charts, $lists]) {
    $id = (int)$db->query("SELECT id FROM users WHERE username='$username'")->fetch_row()[0];
    $can = [];
    foreach (DashboardAnalyticsService::PERMISSIONS as $p) $can[$p] = user_can($id, $p);
    $payload = $service->build($id, $can, null, null);
    $check($payload['profile'] === $profile, "$username profile");
    $check(array_column($payload['stats'], 'key') === $stats, "$username stats " . implode(',', array_column($payload['stats'], 'key')));
    $check(array_keys($payload['charts']) === $charts, "$username charts " . implode(',', array_keys($payload['charts'])));
    $check(array_keys($payload['lists']) === $lists, "$username lists " . implode(',', array_keys($payload['lists'])));
    $check(count($payload['stats']) <= 4, "$username at most 4 stats");
    $json = json_encode($payload);
    foreach ($forbidden[$username] ?? [] as $key) $check(!str_contains($json, '"' . $key . '"'), "$username payload has no $key");
    foreach (['required_optional', 'grade_weighting"', 'outcome_ipd', 'version_status', 'units_per_program'] as $dropped) $check(!str_contains($json, $dropped), "$username no dropped chart $dropped");
}
// Faculty read scope: drafts never reach the payload.
$faculty = (int)$db->query("SELECT id FROM users WHERE username='uitest.acadfaculty'")->fetch_row()[0];
$can = []; foreach (DashboardAnalyticsService::PERMISSIONS as $p) $can[$p] = user_can($faculty, $p);
$program = (int)$db->query("SELECT c.academic_program_id FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE v.status='draft' AND v.review_status='not_started' ORDER BY v.id LIMIT 1")->fetch_row()[0];
$draft = (int)$db->query("SELECT v.id FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE v.status='draft' AND v.review_status='not_started' AND c.academic_program_id=$program ORDER BY v.id LIMIT 1")->fetch_row()[0];
$payload = $service->build($faculty, $can, $program, null);
$check(!in_array($draft, array_column($payload['filter']['versions'], 'id'), true), 'Faculty version list has no draft');
$refused = false;
try { $service->build($faculty, $can, $program, $draft); } catch (DomainValidationException) { $refused = true; }
$check($refused, 'Faculty cannot select a draft by id');
// Completeness: no rule means no target; the counted version is named.
$admin = (int)$db->query("SELECT id FROM users WHERE username='uitest.admin'")->fetch_row()[0];
$can = []; foreach (DashboardAnalyticsService::PERMISSIONS as $p) $can[$p] = user_can($admin, $p);
$rows = $service->build($admin, $can, null, null)['charts']['completeness']['data']['rows'] ?? [];
$rules = (int)$db->query("SELECT COUNT(*) FROM compliance_rules WHERE rule_type='MIN_TOTAL_UNITS' AND technical_status='active'")->fetch_row()[0];
$check($rows !== [], 'Completeness has rows');
if ($rules === 0) foreach ($rows as $r) $check($r['target'] === null, 'No rule, no target: ' . $r['label']);
$labels = array_column($rows, 'label');
$check(count($labels) === count(array_unique($labels)), 'One row per curriculum');
// Filter placement rule: for Super Admin the program filter reaches only the pipeline card.
$sa = (int)$db->query("SELECT id FROM users WHERE username='uitest.superadmin'")->fetch_row()[0];
$can = []; foreach (DashboardAnalyticsService::PERMISSIONS as $p) $can[$p] = user_can($sa, $p);
$program = (int)$db->query("SELECT c.academic_program_id FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id GROUP BY c.academic_program_id ORDER BY COUNT(*) DESC LIMIT 1")->fetch_row()[0];
$all = $service->build($sa, $can, null, null); $one = $service->build($sa, $can, $program, null);
$check(count($all['charts']['pipeline']['data']['rows']) > 1 && count($one['charts']['pipeline']['data']['rows']) === 1, 'Super Admin filter narrows the pipeline to one program');
$check($all['stats'] === $one['stats'], 'Super Admin stats ignore the program filter');
$check($all['charts']['accounts_by_role'] === $one['charts']['accounts_by_role'] && $all['charts']['audit_weekly'] === $one['charts']['audit_weekly'] && $all['lists'] === $one['lists'], 'Super Admin account and audit cards ignore the program filter');
echo $failures ? "FAILED $failures of $checks\n" : "PASS $checks checks\n";
exit($failures ? 1 : 0);
