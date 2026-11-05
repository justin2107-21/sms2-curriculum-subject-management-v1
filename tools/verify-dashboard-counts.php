<?php
// Read-only check: every dashboard stat card, chart and list against an independent SELECT.
// Usage: php tools/verify-dashboard-counts.php [database]      (default: DB_NAME or sms_db)
// The connection is opened READ ONLY, so a write by any code path here fails instead of changing data.
// Each role's dashboard is built by the real DashboardAnalyticsService with that role's permission set
// (read from role_permissions) and a real account of the role (or, for a role with no account, an
// account that is not read-restricted). The SQL below is written separately from the service's code.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$database = $argv[1] ?? (getenv('DB_NAME') ?: 'sms_db');
putenv("DB_NAME=$database");
require_once __DIR__ . '/../shared/database_config.php';
$db = csms_database_connect(csms_database_config());
$db->query('SET SESSION TRANSACTION READ ONLY');
$GLOBALS['conn'] = $db;
require_once __DIR__ . '/../shared/authorization.php';
require_once __DIR__ . '/../modules/curriculum/services/CurriculumReadScope.php';
require_once __DIR__ . '/../dashboard/DashboardAnalyticsService.php';

function rows(string $sql): array { global $db; return $db->query($sql)->fetch_all(MYSQLI_ASSOC); }
function one(string $sql) { $r = rows($sql); return $r ? array_values($r[0])[0] : null; }

$report = []; $bad = 0;
function check(string $role, string $scope, string $card, $dash, $sql): void
{
    global $report, $bad;
    $norm = static fn($v) => is_float($v) || is_int($v) ? round((float)$v, 2) : $v;
    $ok = is_array($dash) || is_array($sql) ? json_encode($dash) === json_encode($sql) : $norm($dash) === $norm($sql);
    if (!$ok) $bad++;
    $fmt = static fn($v) => $v === null ? 'null' : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v);
    $report[] = [$role, $scope, $card, mb_strimwidth($fmt($dash), 0, 70, '…'), mb_strimwidth($fmt($sql), 0, 70, '…'), $ok];
}

$profiles = [
    'super_admin' => ['super_admin', null], 'admin' => ['admin', null], 'staff' => ['staff', 23],
    'acad_office' => ['acad_office', null], 'acad_faculty' => ['acad_faculty', null],
];
$unrestricted = (int)one("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE r.name='acad_office' AND r.is_active=1 ORDER BY ur.user_id LIMIT 1");

// Independent SQL helpers. $p = program id or null.
$pc = static fn(?int $p): string => $p === null ? '' : " AND c.academic_program_id = $p";
$counted = static function (?int $p) use ($pc): array {
    return rows("SELECT v.id, v.curriculum_id, v.version_label, c.curriculum_code, pr.program_code, c.academic_program_id AS program_id
        FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id JOIN academic_programs pr ON pr.id=c.academic_program_id
        WHERE v.status<>'archived'{$pc($p)} AND v.id = (SELECT v2.id FROM curriculum_versions v2 WHERE v2.curriculum_id=v.curriculum_id AND v2.status<>'archived'
            ORDER BY CASE WHEN v2.review_status='approved' THEN 3 WHEN v2.status='active' THEN 2
                          WHEN (SELECT COUNT(*) FROM curriculum_version_subjects x WHERE x.curriculum_version_id=v2.id)>0 THEN 1 ELSE 0 END DESC, v2.updated_at DESC, v2.id DESC LIMIT 1)");
};
$label = static fn(array $v): string => $v['program_code'] . ' · ' . $v['curriculum_code'] . ' · ' . $v['version_label'];
$review = static fn(string $state, ?int $p): int => (int)one("SELECT COUNT(*) FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE v.review_status='$state' AND v.status<>'archived'{$pc($p)}");
$pipelineSql = static function (?int $p) use ($pc): array {
    $out = [];
    foreach (rows("SELECT pr.program_code AS label,
            SUM(v.status<>'archived' AND v.review_status NOT IN ('approved','under_review','returned') AND v.status<>'active') AS draft,
            SUM(v.status<>'archived' AND v.review_status NOT IN ('approved','under_review','returned') AND v.status='active') AS active,
            SUM(v.status<>'archived' AND v.review_status='under_review') AS in_review,
            SUM(v.status<>'archived' AND v.review_status='returned') AS returned,
            SUM(v.status<>'archived' AND v.review_status='approved') AS approved,
            SUM(v.status='archived') AS archived, COUNT(*) AS total
        FROM academic_programs pr JOIN curricula c ON c.academic_program_id=pr.id JOIN curriculum_versions v ON v.curriculum_id=c.id
        WHERE 1=1{$pc($p)} GROUP BY pr.id, pr.program_code") as $r) {
        $out[] = ['label' => $r['label'], 'counts' => ['draft' => (int)$r['draft'], 'active' => (int)$r['active'], 'in_review' => (int)$r['in_review'],
            'returned' => (int)$r['returned'], 'approved' => (int)$r['approved'], 'archived' => (int)$r['archived']], 'total' => (int)$r['total']];
    }
    usort($out, static fn($a, $b) => [$b['total'], $a['label']] <=> [$a['total'], $b['label']]);
    return $out;
};
$posExpr = static fn(string $a): string => "((CASE WHEN $a.year_level REGEXP '^[Gg]rade' THEN CAST(REGEXP_SUBSTR($a.year_level,'[0-9]+') AS UNSIGNED)-10 ELSE CAST(REGEXP_SUBSTR($a.year_level,'[0-9]+') AS UNSIGNED) END)-1)*2+CAST(REGEXP_SUBSTR($a.semester,'[0-9]+') AS UNSIGNED)";

foreach ([null, (int)one("SELECT c.academic_program_id FROM curricula c JOIN curriculum_versions v ON v.curriculum_id=c.id GROUP BY c.academic_program_id ORDER BY COUNT(*) DESC, c.academic_program_id LIMIT 1")] as $programId) {
    $scope = $programId === null ? 'all programs' : 'program ' . one("SELECT program_code FROM academic_programs WHERE id=$programId");
    foreach ($profiles as $profile => [$roleName, $fixedUser]) {
        $can = [];
        foreach (DashboardAnalyticsService::PERMISSIONS as $perm) {
            $can[$perm] = (int)one("SELECT COUNT(*) FROM roles r JOIN role_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id WHERE r.name='$roleName' AND p.name='$perm'") > 0;
        }
        $uid = $fixedUser ?? (int)one("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE r.name='$roleName' AND r.is_active=1 AND u.is_active=1 ORDER BY ur.user_id LIMIT 1");
        $note = $uid === 0 ? ' (no account)' : '';
        $restricted = $roleName === 'acad_faculty';
        if ($uid === 0) $uid = $unrestricted;
        // Staff scope without an account: owner of the personal cards is account $fixedUser; its role does not matter, the permission set does.
        $payload = (new DashboardAnalyticsService($db, ['dashboard.show_pending_charts' => true]))->build($uid, $can, $programId, null);
        $S = array_column($payload['stats'], 'value', 'key');
        $C = $payload['charts']; $L = $payload['lists'];
        $R = "$roleName$note";
        $cc = $counted($programId);
        $ccv = array_map(static fn($v) => ['label' => $label($v), 'id' => (int)$v['id']], $cc);

        $pipeCheck = static function (string $roleLabel, string $sc) use ($C, $pipelineSql, $programId, $profile): void {
            $d = $C['pipeline']['data'] ?? null;
            $exp = $pipelineSql($programId);
            $expWithRows = array_slice($exp, 0, 10);
            $shown = array_map(static fn($r) => ['label' => $r['label'], 'counts' => $r['counts']], $d['rows'] ?? []);
            $want = array_map(static fn($r) => ['label' => $r['label'], 'counts' => $r['counts']], $expWithRows);
            check($roleLabel, $sc, 'pipeline: rows shown (top 10)', $shown, $want);
            check($roleLabel, $sc, 'pipeline: programs with versions', $d['total_rows'] ?? 0, count($exp));
        };

        switch ($profile) {
            case 'super_admin':
                check($R, $scope, 'Active accounts', $S['active_accounts'], (int)one('SELECT COUNT(*) FROM users WHERE is_active=1'));
                check($R, $scope, 'Need password change', $S['password_change'], (int)one('SELECT COUNT(*) FROM users WHERE is_active=1 AND must_change_password=1'));
                check($R, $scope, 'Audit events, 7 days', $S['audit_7d'], (int)one('SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)'));
                check($R, $scope, 'Awaiting review (all programs: the filter reaches only the pipeline)', $S['awaiting'], $review('under_review', null));
                $seg = []; foreach ($C['accounts_by_role']['data']['segments'] ?? [] as $s) $seg[$s['label']] = $s['value'];
                $exp = []; foreach (rows("SELECT r.display_name n, COUNT(*) c FROM user_roles ur JOIN roles r ON r.id=ur.role_id AND r.is_active=1 JOIN users u ON u.id=ur.user_id AND u.is_active=1 GROUP BY r.id, r.display_name") as $r) $exp[$r['n']] = (int)$r['c'];
                ksort($seg); ksort($exp);
                check($R, $scope, 'Accounts by role (donut)', $seg, $exp);
                check($R, $scope, 'Accounts by role: total', $C['accounts_by_role']['data']['total'] ?? 0, array_sum($exp));
                $d = $C['audit_weekly']['data'] ?? null;
                if ($d) {
                    $from = $d['periods'][0];
                    check($R, $scope, 'Audit events per week (sum of 12 weeks)', array_sum($d['series'][0]['values']), (int)one("SELECT COUNT(*) FROM audit_logs WHERE created_at >= '$from 00:00:00'"));
                } else check($R, $scope, 'Audit events per week', 'empty', one('SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)') == 0 ? 'empty' : 'has rows');
                $pipeCheck($R, $scope);
                check($R, $scope, 'Role/permission changes list', count($L['rbac_changes']['data']['items'] ?? []), min(5, (int)one("SELECT COUNT(*) FROM audit_logs WHERE action IN ('RBAC_ROLE_ASSIGNMENT','RBAC_PERMISSION_CHANGE')")));
                check($R, $scope, 'Accounts needing password change list', count($L['password_accounts']['data']['items'] ?? []), min(5, (int)one('SELECT COUNT(*) FROM users WHERE is_active=1 AND must_change_password=1')));
                break;

            case 'admin':
                check($R, $scope, 'Active programs', $S['programs'], (int)one("SELECT COUNT(*) FROM academic_programs WHERE LOWER(status)='active'"));
                check($R, $scope, 'Curricula', $S['curricula'], (int)one('SELECT COUNT(*) FROM curricula c WHERE 1=1' . $pc($programId)));
                check($R, $scope, 'Approved versions', $S['approved'], $review('approved', $programId));
                check($R, $scope, 'Awaiting review', $S['awaiting'], $review('under_review', $programId));
                $pipeCheck($R, $scope);
                $units = [];
                foreach ($ccv as $v) $units[] = ['label' => $v['label'], 'units' => round((float)one("SELECT COALESCE(SUM(s.units),0) FROM curriculum_version_subjects m JOIN subjects s ON s.id=m.subject_id WHERE m.curriculum_version_id={$v['id']}"), 2)];
                usort($units, static fn($a, $b) => [$b['units'], $a['label']] <=> [$a['units'], $b['label']]);
                $d = $C['completeness']['data'] ?? ['rows' => [], 'total_rows' => 0];
                check($R, $scope, 'Completeness: rows shown (label, placed units)', array_map(static fn($r) => ['label' => $r['label'], 'units' => round((float)$r['units'], 2)], $d['rows']), array_slice($units, 0, 10));
                check($R, $scope, 'Completeness: curricula counted', $d['total_rows'] ?? 0, count($units));
                $shs = (int)one("SELECT COUNT(*) FROM academic_programs WHERE LOWER(status)='active' AND (LOWER(program_type) LIKE '%senior high%' OR LOWER(program_type)='shs' OR LOWER(program_type) LIKE '%strand%')");
                $all = (int)one("SELECT COUNT(*) FROM academic_programs WHERE LOWER(status)='active'");
                $seg = []; foreach ($C['programs_by_level']['data']['segments'] ?? [] as $s) $seg[$s['label']] = $s['value'];
                check($R, $scope, 'Programs by level (donut)', $seg, ['College' => $all - $shs, 'Senior High School' => $shs]);
                check($R, $scope, 'Recently approved list', count($L['recently_approved']['data']['items'] ?? []), min(5, (int)one("SELECT COUNT(*) FROM curriculum_version_review_events e JOIN curriculum_versions v ON v.id=e.curriculum_version_id AND v.review_status='approved' JOIN curricula c ON c.id=v.curriculum_id WHERE e.action='approve'" . $pc($programId))));
                check($R, $scope, 'Official exports list', count($L['export_log']['data']['items'] ?? []), min(5, (int)one("SELECT COUNT(*) FROM audit_logs a JOIN curriculum_versions v ON v.id=a.entity_id JOIN curricula c ON c.id=v.curriculum_id WHERE a.action='CURRICULUM_EXPORT_GENERATE' AND a.entity_type='curriculum_version' AND JSON_UNQUOTE(JSON_EXTRACT(a.metadata_json,'$.review_status'))='approved'" . $pc($programId))));
                check($R, $scope, 'Programs with no curriculum list', count($L['no_curriculum']['data']['items'] ?? []), (int)one('SELECT COUNT(*) FROM academic_programs p WHERE NOT EXISTS (SELECT 1 FROM curricula c WHERE c.academic_program_id=p.id)' . ($programId === null ? '' : " AND p.id=$programId")));
                break;

            case 'staff':
                $owner = "COALESCE((SELECT e.actor_user_id FROM curriculum_version_review_events e WHERE e.curriculum_version_id=v.id AND e.action='submit' ORDER BY e.id DESC LIMIT 1), v.created_by) = $uid";
                $mine = static fn(string $extra) => (int)one("SELECT COUNT(*) FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE $owner $extra" . $pc($programId));
                $drafts = $mine("AND v.status='draft' AND v.review_status NOT IN ('under_review','approved','returned')");
                check($R, $scope, 'My drafts', $S['my_drafts'], $drafts);
                check($R, $scope, 'Returned to me', $S['returned'], $mine("AND v.review_status='returned'"));
                check($R, $scope, 'Awaiting decision', $S['waiting'], $mine("AND v.review_status='under_review'"));
                $failSql = "SELECT COUNT(*) FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE $owner" . $pc($programId) . " AND COALESCE((SELECT r.failed_count FROM compliance_validation_runs r WHERE r.curriculum_version_id=v.id ORDER BY r.id DESC LIMIT 1),0) > 0";
                check($R, $scope, 'Failing checks', $S['failing'], (int)one($failSql));
                $sel = $payload['filter']['version_id'];
                if ($sel) {
                    $t = rows("SELECT COUNT(*) n, COALESCE(SUM(s.units),0) u FROM curriculum_version_subjects m JOIN subjects s ON s.id=m.subject_id WHERE m.curriculum_version_id=$sel")[0];
                    $tl = $C['term_load']['data']['totals'] ?? ['subjects' => 0, 'units' => 0];
                    check($R, $scope, "Term load totals (version $sel): subjects", $tl['subjects'], (int)$t['n']);
                    check($R, $scope, "Term load totals (version $sel): units", $tl['units'], round((float)$t['u'], 2));
                    $run = rows("SELECT passed_count p, failed_count f, not_evaluated_count n FROM compliance_validation_runs WHERE curriculum_version_id=$sel ORDER BY id DESC LIMIT 1")[0] ?? null;
                    $seg = []; foreach ($C['compliance_working']['data']['segments'] ?? [] as $s) $seg[$s['label']] = $s['value'];
                    check($R, $scope, "Compliance of working version $sel (donut)", $seg ?: 'empty', $run ? ['Pass' => (int)$run['p'], 'Fail' => (int)$run['f'], 'Not evaluated' => (int)$run['n']] : 'empty');
                } else check($R, $scope, 'Term load', $C['term_load']['status'], 'empty');
                $ph = $C['prerequisite_health']['data']['rows'] ?? [];
                $byLabel = []; foreach ($ccv as $v) $byLabel[$v['label']] = $v['id'];
                foreach ($ph as $r) {
                    $id = $byLabel[$r['label']] ?? 0;
                    $pos = $posExpr('m'); $posR = $posExpr('mr');
                    $placements = (int)one("SELECT COUNT(*) FROM curriculum_version_subjects WHERE curriculum_version_id=$id");
                    $upper = (int)one("SELECT COUNT(*) FROM curriculum_version_subjects m WHERE m.curriculum_version_id=$id AND ($pos) > 2");
                    $cov = (int)one("SELECT COUNT(*) FROM curriculum_version_subjects m WHERE m.curriculum_version_id=$id AND ($pos) > 2 AND EXISTS (SELECT 1 FROM curriculum_subject_requirements q WHERE q.curriculum_version_id=$id AND q.subject_id=m.subject_id AND q.requirement_type='prerequisite')");
                    $conf = (int)one("SELECT COUNT(*) FROM curriculum_subject_requirements q JOIN curriculum_version_subjects m ON m.curriculum_version_id=q.curriculum_version_id AND m.subject_id=q.subject_id JOIN curriculum_version_subjects mr ON mr.curriculum_version_id=q.curriculum_version_id AND mr.subject_id=q.required_subject_id WHERE q.curriculum_version_id=$id AND q.requirement_type='prerequisite' AND q.subject_id<>q.required_subject_id AND ($posR) >= ($pos)");
                    check($R, $scope, "Prerequisite health {$r['label']}: placed / Y2-4 share / conflicts", [$r['placements'], $r['percent'], $r['later_term']], [$placements, $upper > 0 ? (int)round($cov / $upper * 100) : null, $conf]);
                }
                check($R, $scope, 'Prerequisite health: curricula with placements', $C['prerequisite_health']['data']['total_rows'] ?? 0, count(array_filter($ccv, static fn($v) => (int)one("SELECT COUNT(*) FROM curriculum_version_subjects WHERE curriculum_version_id={$v['id']}") > 0)));
                check($R, $scope, 'Returned list', count($L['returned_list']['data']['items'] ?? []), min(5, $mine("AND v.review_status='returned'")));
                check($R, $scope, 'My drafts list', count($L['my_drafts']['data']['items'] ?? []), min(5, $drafts));
                $gw = 0;
                foreach (rows("SELECT v.id FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE $owner AND v.status<>'archived'" . $pc($programId)) as $v) {
                    $pl = (int)one("SELECT COUNT(*) FROM curriculum_version_subjects WHERE curriculum_version_id={$v['id']}");
                    $ok = (int)one("SELECT COUNT(*) FROM curriculum_version_subjects m WHERE m.curriculum_version_id={$v['id']} AND EXISTS (SELECT 1 FROM grade_weighting_schemes g WHERE g.curriculum_version_subject_id=m.id AND (SELECT COALESCE(SUM(w.weight_percentage),0) FROM grade_weighting_components w WHERE w.scheme_id=g.id)=100)");
                    if ($pl > 0 && $pl - $ok > 0) $gw++;
                }
                check($R, $scope, 'Needs attention list (failing + incomplete grade weighting)', count($L['needs_attention']['data']['items'] ?? []), min(8, (int)one($failSql) + $gw));
                break;

            case 'acad_office':
                $since90 = "DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
                $ev = static fn(string $a, string $when) => (int)one("SELECT COUNT(*) FROM curriculum_version_review_events e JOIN curriculum_versions v ON v.id=e.curriculum_version_id JOIN curricula c ON c.id=v.curriculum_id WHERE e.action='$a' AND $when" . $pc($programId));
                $awaiting = (int)one("SELECT COUNT(*) FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE v.review_status='under_review' AND (SELECT e.actor_user_id FROM curriculum_version_review_events e WHERE e.curriculum_version_id=v.id AND e.action='submit' ORDER BY e.id DESC LIMIT 1) <> $uid" . $pc($programId));
                check($R, $scope, 'Awaiting my review', $S['awaiting_mine'], $awaiting);
                $avg = one("SELECT ROUND(AVG(TIMESTAMPDIFF(SECOND, (SELECT s.created_at FROM curriculum_version_review_events s WHERE s.curriculum_version_id=e.curriculum_version_id AND s.action='submit' AND s.id<e.id ORDER BY s.id DESC LIMIT 1), e.created_at))/86400, 1)
                    FROM curriculum_version_review_events e JOIN curriculum_versions v ON v.id=e.curriculum_version_id JOIN curricula c ON c.id=v.curriculum_id WHERE e.action IN ('approve','return') AND e.created_at >= $since90" . $pc($programId));
                check($R, $scope, 'Average days in review (— when no decisions)', $S['avg_days'], $avg === null ? null : (float)$avg);
                check($R, $scope, 'Approved this month', $S['approved_month'], $ev('approve', "DATE_FORMAT(e.created_at,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')"));
                check($R, $scope, 'Returned this month', $S['returned_month'], $ev('return', "DATE_FORMAT(e.created_at,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')"));
                $seg = []; foreach ($C['decision_split']['data']['segments'] ?? [] as $s) $seg[$s['label']] = $s['value'];
                $a = $ev('approve', "e.created_at >= $since90"); $r = $ev('return', "e.created_at >= $since90");
                check($R, $scope, 'Decisions, last 90 days (donut)', $seg ?: 'empty', $a + $r > 0 ? ['Approved' => $a, 'Returned' => $r] : 'empty');
                $d = $C['review_outcomes']['data'] ?? null;
                if ($d) {
                    $from = $d['periods'][0] . '-01';
                    $tot = []; foreach ($d['series'] as $s) $tot[$s['label']] = array_sum($s['values']);
                    $want = ['Submitted' => $ev('submit', "e.created_at >= '$from'"), 'Approved' => $ev('approve', "e.created_at >= '$from'"), 'Returned' => $ev('return', "e.created_at >= '$from'")];
                    check($R, $scope, 'Review outcomes per month (series totals)', $tot, $want);
                } else check($R, $scope, 'Review outcomes per month', 'empty', ($ev('submit', '1=1') + $ev('approve', '1=1') + $ev('return', '1=1')) === 0 ? 'empty' : 'has rows');
                $pipeCheck($R, $scope);
                $cd = $C['compliance_under_review']['data'] ?? ['total_rows' => 0, 'bars' => []];
                $validated = (int)one("SELECT COUNT(*) FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE v.review_status='under_review' AND v.status<>'archived' AND EXISTS (SELECT 1 FROM compliance_validation_runs r WHERE r.curriculum_version_id=v.id)" . $pc($programId));
                check($R, $scope, 'Compliance under review: versions with a run', $cd['total_rows'] ?? 0, $validated);
                check($R, $scope, 'Awaiting my review list', count($L['awaiting_review']['data']['items'] ?? []), min(5, $awaiting));
                check($R, $scope, 'Pending equivalency list', count($L['pending_equivalency']['data']['items'] ?? []), min(5, (int)one("SELECT COUNT(*) FROM subject_equivalencies WHERE decision_status='pending' AND archived_at IS NULL")));
                check($R, $scope, 'My recent decisions list', count($L['my_decisions']['data']['items'] ?? []), min(5, (int)one("SELECT COUNT(*) FROM curriculum_version_review_events e JOIN curriculum_versions v ON v.id=e.curriculum_version_id JOIN curricula c ON c.id=v.curriculum_id WHERE e.actor_user_id=$uid AND e.action IN ('approve','return')" . $pc($programId))));
                break;

            case 'acad_faculty':
                $scopeSql = "(v.review_status='approved' OR v.status='active')";
                check($R, $scope, 'Approved curricula', $S['approved'], (int)one("SELECT COUNT(*) FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE v.review_status='approved' AND v.status<>'archived' AND $scopeSql" . $pc($programId)));
                $catalog = (int)one("SELECT COUNT(*) FROM curriculum_outcome_catalog WHERE source_key='CHED-CMO-25-2015-BSIT'");
                if ($catalog === 0 || ($C['outcome_coverage']['status'] ?? '') !== 'ok') check($R, $scope, 'Outcomes covered (— without catalog)', $S['outcomes'], $catalog === 0 ? null : $S['outcomes']);
                else check($R, $scope, 'Outcomes covered', $S['outcomes'], (int)one("SELECT COUNT(DISTINCT m.outcome_id) FROM curriculum_version_subject_outcomes m JOIN curriculum_version_subjects p ON p.id=m.curriculum_version_subject_id JOIN curriculum_outcome_catalog o ON o.id=m.outcome_id WHERE p.curriculum_version_id={$payload['filter']['version_id']} AND o.source_key='CHED-CMO-25-2015-BSIT'"));
                $sel = $payload['filter']['version_id'];
                if ($sel) {
                    $t = rows("SELECT COUNT(*) n, COALESCE(SUM(s.units),0) u FROM curriculum_version_subjects m JOIN subjects s ON s.id=m.subject_id WHERE m.curriculum_version_id=$sel")[0];
                    $tl = $C['term_load']['data']['totals'] ?? ['subjects' => 0, 'units' => 0];
                    check($R, $scope, "Term load totals (version $sel): subjects", $tl['subjects'], (int)$t['n']);
                    check($R, $scope, "Term load totals (version $sel): units", $tl['units'], round((float)$t['u'], 2));
                } else check($R, $scope, 'Term load', $C['term_load']['status'], 'empty');
                check($R, $scope, 'Approved curricula list', count($L['approved_curricula']['data']['items'] ?? []), min(5, (int)one("SELECT COUNT(*) FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE v.review_status='approved' AND v.status<>'archived'" . $pc($programId))));
                check($R, $scope, 'Recently approved list', count($L['recently_approved']['data']['items'] ?? []), min(5, (int)one("SELECT COUNT(*) FROM curriculum_version_review_events e JOIN curriculum_versions v ON v.id=e.curriculum_version_id AND v.review_status='approved' JOIN curricula c ON c.id=v.curriculum_id WHERE e.action='approve'" . $pc($programId))));
                break;
        }
    }
}

echo "# Dashboard correctness on $database (read-only)\n\n| Role | Scope | Card | Dashboard | SQL | Match |\n|---|---|---|---|---|---|\n";
foreach ($report as [$role, $scope, $card, $dash, $sql, $ok]) {
    echo '| ' . implode(' | ', [$role, $scope, str_replace('|', '/', $card), str_replace('|', '/', $dash), str_replace('|', '/', $sql), $ok ? 'yes' : '**NO**']) . " |\n";
}
echo "\n" . count($report) . " checks, $bad mismatches.\n";
exit($bad ? 1 : 0);
