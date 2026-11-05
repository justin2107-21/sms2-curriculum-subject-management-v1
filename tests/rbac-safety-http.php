<?php
// Authorization through real HTTP with real sign-in. Uses sms_db_uitest only; creates disposable rows there.
// Usage (DB_NAME=sms_db_uitest): php tests/rbac-safety-http.php
if (getenv('DB_NAME') !== 'sms_db_uitest') { fwrite(STDERR, "Refusing: set DB_NAME=sms_db_uitest\n"); exit(1); }
require_once __DIR__ . '/../shared/database_config.php';
$db = csms_database_connect(csms_database_config());
$creds = [];
foreach (file(__DIR__ . '/../.env.uitest', FILE_IGNORE_NEW_LINES) as $line) if (preg_match('/^(UITEST_\w+)=(.*)$/', $line, $m)) $creds[$m[1]] = $m[2];

$port = 8097;
$log = tempnam(sys_get_temp_dir(), 'rbac');
$env = getenv(); $env['APP_ENV'] = 'development'; $env['SMS_SESSION_COOKIE_SECURE'] = '0'; $env['CIMF_AI_ENABLED'] = 'false';
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__)], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env, ['bypass_shell' => true]);
for ($i = 0; $i < 40; $i++) { $c = @stream_socket_client("tcp://127.0.0.1:$port", $e, $s, .2); if ($c) { fclose($c); break; } usleep(100000); }

final class Client {
    private array $cookies = []; public string $csrf = '';
    public function __construct(private int $port) {}
    public function req(string $path, array $post = null): array {
        $headers = []; if ($this->cookies) $headers[] = 'Cookie: ' . http_build_query($this->cookies, '', '; ');
        $opts = ['ignore_errors' => true, 'follow_location' => 0, 'method' => $post === null ? 'GET' : 'POST'];
        if ($post !== null) { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; $opts['content'] = http_build_query($post); }
        $opts['header'] = implode("\r\n", $headers);
        $body = (string)file_get_contents("http://127.0.0.1:{$this->port}/$path", false, stream_context_create(['http' => $opts]));
        $status = 0;
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) $status = (int)$m[1];
            if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $h, $m)) $this->cookies[$m[1]] = urldecode($m[2]);
        }
        if (preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m)) $this->csrf = $m[1];
        return [$status, $body, json_decode($body, true)];
    }
    public function post(string $path, array $data): array { return $this->req($path, $data + ['_csrf_token' => $this->csrf]); }
}
$login = static function (string $key) use ($creds, $port): Client {
    $c = new Client($port); $c->req('auth/signin.php');
    $up = strtoupper($key);
    [$s] = $c->req('shared/auth_actions.php', ['action' => 'login', 'username' => $creds["UITEST_{$up}_USER"], 'password' => $creds["UITEST_{$up}_PASS"], '_csrf_token' => $c->csrf]);
    if ($s !== 200) throw new RuntimeException("login $key: $s");
    $c->req('dashboard/account.php');
    return $c;
};
$failures = 0; $checks = 0;
$expect = static function (int $want, array $res, string $label) use (&$failures, &$checks): void {
    $checks++;
    if ($res[0] !== $want) { $failures++; echo "FAIL $label: wanted $want got {$res[0]} " . substr((string)($res[2]['message'] ?? ''), 0, 90) . "\n"; }
};
$truth = static function (bool $ok, string $label) use (&$failures, &$checks): void { $checks++; if (!$ok) { $failures++; echo "FAIL $label\n"; } };

try {
    $sa = $login('superadmin'); $admin = $login('admin'); $staff = $login('staff'); $office = $login('acadoffice'); $faculty = $login('acadfaculty');
    $roleId = static fn(string $n): int => (int)$db->query("SELECT id FROM roles WHERE name='$n'")->fetch_row()[0];
    $userId = static fn(string $n): int => (int)$db->query("SELECT id FROM users WHERE username='$n'")->fetch_row()[0];
    $accounts = 'admin/account_actions.php';
    $form = static fn(?int $id, string $name, int $role, int $active = 1): array => ['action' => 'save', 'id' => $id ?? '', 'username' => $name, 'email' => "$name@uitest.invalid", 'first_name' => 'T', 'last_name' => 'U', 'role_id' => $role, 'is_active' => $active];
    $suffix = bin2hex(random_bytes(3));

    // Safety rule 1: role ceiling.
    $saId = $userId('uitest.superadmin'); $staffId = $userId('uitest.staff');
    $expect(403, $admin->req("$accounts?action=get&id=$saId"), 'Admin get Super Admin');
    $expect(403, $admin->req("$accounts?action=get&id=" . $userId('csms_admin')), 'Admin get csms_admin');
    $expect(200, $admin->req("$accounts?action=get&id=$staffId"), 'Admin get Staff');
    $expect(403, $admin->post($accounts, $form(null, "t_sa_$suffix", $roleId('super_admin'))), 'Admin creates Super Admin');
    $expect(403, $admin->post($accounts, $form(null, "t_ad_$suffix", $roleId('admin'))), 'Admin creates Admin');
    $expect(403, $admin->post($accounts, $form($saId, 'uitest.superadmin', $roleId('staff'))), 'Admin edits Super Admin');
    $expect(403, $admin->post($accounts, $form($staffId, 'uitest.staff', $roleId('super_admin'))), 'Admin promotes Staff to Super Admin');
    $expect(422, $admin->post($accounts, $form($userId('uitest.admin'), 'uitest.admin', $roleId('super_admin'))), 'Admin self-escalates');
    $created = $admin->post($accounts, $form(null, "t_st_$suffix", $roleId('acad_faculty')));
    $expect(200, $created, 'Admin creates Acad Faculty');
    $newId = (int)($created[2]['user']['id'] ?? 0);
    $expect(200, $admin->post($accounts, $form($newId, "t_st_$suffix", $roleId('staff'), 0)), 'Admin edits and deactivates that account');
    $truth((int)$db->query("SELECT COUNT(*) FROM roles WHERE id=" . $roleId('system_administrator') . " AND is_active=0")->fetch_row()[0] === 1, 'old role inactive');
    $expect(422, $sa->post($accounts, $form($newId, "t_st_$suffix", $roleId('student'))), 'Retired role cannot be assigned');
    $expect(403, $staff->req("$accounts?action=get&id=$staffId"), 'Staff no account access');
    $expect(403, $faculty->post($accounts, $form(null, "t_x_$suffix", $roleId('staff'))), 'Faculty create refused');

    // No CSRF, no change.
    $expect(403, $admin->req($accounts, $form(null, "t_n_$suffix", $roleId('staff'))), 'Missing CSRF refused');

    // Safety rule 2 and 4: self-edit and permissions.
    $expect(422, $sa->post($accounts, $form($saId, 'uitest.superadmin', $roleId('staff'))), 'Super Admin edits own account');
    $perm = ['action' => 'permissions', 'role_id' => $roleId('staff'), 'permissions' => ['dashboard.view', 'account.view', 'account.update']];
    $expect(403, $admin->post($accounts, $perm), 'Admin edits permissions');
    $expect(422, $sa->post($accounts, ['role_id' => $roleId('super_admin')] + $perm), 'Super Admin role cannot be edited');
    $expect(422, $sa->post($accounts, ['permissions' => ['dashboard.view', 'account.view', 'account.update', 'rbac.manage']] + $perm), 'rbac.manage not grantable to Staff');

    // Safety rule 3: last Super Admin. The service runs directly so the actor can be excluded from the active count.
    require_once __DIR__ . '/../shared/bootstrap.php';
    require_once __DIR__ . '/../admin/AccountAdministration.php';
    $service = new AccountAdministration($db);
    $target = (int)$db->query("SELECT u.id FROM users u WHERE u.username='csms_admin'")->fetch_row()[0];
    $saved = $db->query("SELECT u.id, u.is_active FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.name='super_admin'")->fetch_all(MYSQLI_ASSOC);
    $db->query("UPDATE users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id SET u.is_active=0 WHERE r.name='super_admin' AND u.id<>$target");
    $row = $db->query("SELECT username,email FROM users WHERE id=$target")->fetch_assoc();
    foreach ([[$roleId('staff'), 1, 'demote'], [$roleId('super_admin'), 0, 'deactivate']] as [$newRole, $active, $what]) {
        $refused = false;
        try { $service->save(['id' => $target, 'username' => $row['username'], 'email' => $row['email'], 'first_name' => 'CSMS', 'last_name' => 'Admin', 'role_id' => $newRole, 'is_active' => (string)$active], $saId); }
        catch (InvalidArgumentException $e) { $refused = str_contains($e->getMessage(), 'last active Super Admin'); }
        $truth($refused, "Last Super Admin: $what refused");
    }
    foreach ($saved as $item) $db->query('UPDATE users SET is_active=' . (int)$item['is_active'] . ' WHERE id=' . (int)$item['id']);
    // With another active Super Admin the same change passes the rule, on a disposable second Super Admin.
    $second = $sa->post($accounts, $form(null, "t_sa2_$suffix", $roleId('super_admin')));
    $expect(200, $second, 'Super Admin creates Super Admin');
    $expect(200, $sa->post($accounts, $form((int)$second[2]['user']['id'], "t_sa2_$suffix", $roleId('staff'))), 'Super Admin demotes another while others remain');

    // Pages: 403s by role.
    $pages = ['admin/users.php' => ['sa' => 200, 'ad' => 200, 'st' => 403, 'of' => 403, 'fa' => 403],
        'admin/permissions.php' => ['sa' => 200, 'ad' => 403, 'st' => 403, 'of' => 403, 'fa' => 403],
        'admin/settings.php' => ['sa' => 200, 'ad' => 403, 'st' => 403, 'of' => 403, 'fa' => 403],
        'admin/audit_logs.php' => ['sa' => 200, 'ad' => 200, 'st' => 403, 'of' => 403, 'fa' => 403],
        'modules/curriculum/pages/equivalency_records.php' => ['sa' => 200, 'ad' => 200, 'st' => 200, 'of' => 200, 'fa' => 403],
        'modules/curriculum/pages/curriculum_export.php' => ['sa' => 200, 'ad' => 200, 'st' => 200, 'of' => 200, 'fa' => 403],
        'modules/curriculum/pages/grade_weighting_setup.php' => ['sa' => 200, 'ad' => 200, 'st' => 200, 'of' => 200, 'fa' => 403],
        'modules/curriculum/pages/subject_mapping.php' => ['sa' => 200, 'ad' => 200, 'st' => 200, 'of' => 200, 'fa' => 200]];
    $who = ['sa' => $sa, 'ad' => $admin, 'st' => $staff, 'of' => $office, 'fa' => $faculty];
    foreach ($pages as $page => $map) foreach ($map as $k => $want) $expect($want, $who[$k]->req($page), "$k $page");

    // Fixture: a reference and a proposed revision, plus an isolated draft. Uses this copy's first curriculum.
    $curriculum = (int)$db->query('SELECT id FROM curricula ORDER BY id LIMIT 1')->fetch_row()[0];
    $mk = static function (string $label, string $role, ?int $base, string $status) use ($db, $curriculum): int {
        $b = $base ?? 'NULL';
        $db->query("INSERT INTO curriculum_versions(curriculum_id,version_label,status,version_role,review_status,baseline_version_id) VALUES($curriculum,'$label','$status','$role','not_started',$b)");
        return (int)$db->insert_id;
    };
    $reference = $mk("ref$suffix", 'reference', null, 'active');
    $proposed = $mk("rev$suffix", 'proposed', $reference, 'draft');
    $api = 'modules/curriculum/http/review_actions.php'; $vapi = 'modules/curriculum/http/version_actions.php';
    $amapi = 'modules/curriculum/http/assignment_actions.php'; $xapi = 'modules/curriculum/http/export_actions.php';

    // Faculty read scope: draft refused, active allowed, list filtered, including through child endpoints.
    $expect(403, $faculty->req("$vapi?action=get&id=$proposed"), 'Faculty reads draft version');
    $expect(200, $faculty->req("$vapi?action=get&id=$reference"), 'Faculty reads active version');
    $expect(403, $faculty->req("$amapi?action=list&curriculum_version_id=$proposed"), 'Faculty reads draft placements');
    $expect(403, $faculty->req("modules/curriculum/http/requirement_actions.php?action=workspace&curriculum_version_id=$proposed"), 'Faculty reads draft prerequisites');
    $list = $faculty->req("$vapi?action=list&curriculum_id=$curriculum&per_page=50");
    $ids = array_map('intval', array_column($list[2]['versions'] ?? [], 'id'));
    $truth(in_array($reference, $ids, true) && !in_array($proposed, $ids, true), 'Faculty version list filtered');
    $expect(200, $staff->req("$vapi?action=get&id=$proposed"), 'Staff reads draft');

    // The export audit entry stores the review state at generation time (the dashboard's Official exports list reads it).
    $firstSubject = (int)$db->query('SELECT id FROM subjects ORDER BY id LIMIT 1')->fetch_row()[0];
    $db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester,is_required) VALUES($proposed,$firstSubject,'1st Year','1st Semester',1)");
    $csv = static fn(Client $c, int $v): array => $c->post($xapi, ['action' => 'download_csv', 'academic_program_id' => (int)$db->query("SELECT academic_program_id FROM curricula WHERE id=$curriculum")->fetch_row()[0], 'curriculum_id' => $curriculum, 'curriculum_version_id' => $v]);
    $expect(200, $csv($staff, $proposed), 'Staff downloads a draft CSV');

    // Export split.
    $preview = static fn(Client $c, int $v): array => $c->req("$xapi?action=preview&curriculum_version_id=$v");
    $expect(403, $preview($admin, $proposed), 'Admin exports unapproved');
    $expect(403, $preview($office, $proposed), 'Acad Office exports unapproved');
    $expect(403, $preview($faculty, $reference), 'Faculty export');
    $truth($preview($staff, $proposed)[0] !== 403, 'Staff exports draft');

    // Review workflow.
    $expect(403, $faculty->post($api, ['action' => 'submit', 'curriculum_version_id' => $proposed]), 'Faculty submits');
    $expect(403, $admin->post($api, ['action' => 'submit', 'curriculum_version_id' => $proposed]), 'Admin submits');
    $expect(403, $office->post($api, ['action' => 'submit', 'curriculum_version_id' => $proposed]), 'Office submits');
    $expect(409, $staff->post($api, ['action' => 'submit', 'curriculum_version_id' => $reference]), 'Submit a reference');
    $expect(409, $office->post($api, ['action' => 'decide', 'decision' => 'approve', 'reason' => 'x', 'curriculum_version_id' => $proposed]), 'Decide before submit');
    $expect(200, $staff->post($api, ['action' => 'submit', 'curriculum_version_id' => $proposed, 'comment' => 'ready']), 'Staff submits');
    $expect(403, $staff->post($api, ['action' => 'decide', 'decision' => 'approve', 'reason' => 'x', 'curriculum_version_id' => $proposed]), 'Staff decides');
    $expect(403, $admin->post($api, ['action' => 'decide', 'decision' => 'approve', 'reason' => 'x', 'curriculum_version_id' => $proposed]), 'Admin decides');
    $expect(422, $office->post($api, ['action' => 'decide', 'decision' => 'return', 'reason' => '  ', 'curriculum_version_id' => $proposed]), 'Return needs a reason');
    $expect(422, $office->post($api, ['action' => 'decide', 'decision' => 'approve', 'reason' => '', 'curriculum_version_id' => $proposed]), 'Approve needs a reason');
    $expect(200, $office->post($api, ['action' => 'decide', 'decision' => 'return', 'reason' => 'fix units', 'curriculum_version_id' => $proposed]), 'Office returns');
    $truth($db->query("SELECT review_status FROM curriculum_versions WHERE id=$proposed")->fetch_row()[0] === 'returned', 'status returned');
    $expect(200, $sa->post($api, ['action' => 'submit', 'curriculum_version_id' => $proposed]), 'Super Admin submits');
    $expect(409, $sa->post($api, ['action' => 'decide', 'decision' => 'approve', 'reason' => 'x', 'curriculum_version_id' => $proposed]), 'Submitter cannot decide own submission');
    $expect(200, $office->post($api, ['action' => 'decide', 'decision' => 'approve', 'reason' => 'ok', 'curriculum_version_id' => $proposed]), 'Office approves');
    $expect(409, $staff->post($api, ['action' => 'submit', 'curriculum_version_id' => $proposed]), 'Approved cannot be resubmitted');
    $truth((int)$db->query("SELECT COUNT(*) FROM curriculum_version_review_events WHERE curriculum_version_id=$proposed")->fetch_row()[0] === 4, 'four review events');
    $truth((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE entity_type='curriculum_version' AND entity_id=$proposed AND action LIKE 'CURRICULUM_REVIEW_%'")->fetch_row()[0] === 4, 'four audit entries');

    // Approved: Faculty reads it; official exporters can, draft exporters cannot; the version is locked.
    $expect(200, $faculty->req("$vapi?action=get&id=$proposed"), 'Faculty reads approved draft-state version');
    $truth($preview($admin, $proposed)[0] !== 403, 'Admin official export');
    $truth($preview($office, $proposed)[0] !== 403, 'Office official export');
    $expect(403, $preview($staff, $proposed), 'Staff cannot export approved');
    $expect(403, $csv($staff, $proposed), 'Staff cannot download an approved CSV');
    $expect(200, $csv($admin, $proposed), 'Admin downloads an approved CSV');
    $stored = [];
    $res = $db->query("SELECT metadata_json FROM audit_logs WHERE action='CURRICULUM_EXPORT_GENERATE' AND entity_id=$proposed ORDER BY id");
    while ($row = $res->fetch_row()) $stored[] = json_decode($row[0], true);
    $truth(count($stored) === 2 && $stored[0]['review_status'] === 'not_started' && $stored[1]['review_status'] === 'approved', 'Export audit stores the review state at export time');
    $truth(($stored[1]['version_label'] ?? '') === "rev$suffix" && (int)$stored[1]['curriculum_version_id'] === $proposed, 'Export audit stores the version label and id');
    require_once __DIR__ . '/../dashboard/DashboardAnalyticsRepository.php';
    $official = array_column((new DashboardAnalyticsRepository($db))->officialExports(null, 500), 'stored_label');
    $truth(in_array("rev$suffix", $official, true) && count(array_keys($official, "rev$suffix", true)) === 1, 'Official exports lists the approved export once and not the draft export');
    $subject = (int)$db->query('SELECT id FROM subjects ORDER BY id LIMIT 1')->fetch_row()[0];
    $locked = $staff->post($amapi, ['action' => 'assign', 'curriculum_version_id' => $proposed, 'subject_id' => $subject, 'year_level' => '1st Year', 'semester' => '1st Semester']);
    $truth($locked[0] >= 400, 'Approved version refuses placement edits, got ' . $locked[0]);

    // Students: nothing rendered even for Super Admin.
    $truth(!str_contains($sa->req('reports_tab/reports.php')[1], 'Total Students'), 'no student totals on reports');
    $expect(403, $staff->req('shared/student_actions.php?action=get&id=1'), 'student.* refused for Staff');
    $expect(403, $admin->req('shared/student_actions.php?action=get&id=1'), 'student.* refused for Admin');

    // Sidebar visibility per role: exactly these links in the System section and the gated curriculum items.
    $links = ['users.php', 'permissions.php', 'audit_logs.php', 'settings.php', 'subject_history', 'curriculum_export.php', 'grade_weighting_setup.php', 'electives_manager.php', 'prerequisite_configuration.php', 'program_centric_assignment.php'];
    $visible = ['sa' => $links,
        'ad' => ['users.php', 'audit_logs.php', 'subject_history', 'curriculum_export.php', 'grade_weighting_setup.php', 'electives_manager.php', 'prerequisite_configuration.php', 'program_centric_assignment.php'],
        'st' => ['subject_history', 'curriculum_export.php', 'grade_weighting_setup.php', 'electives_manager.php', 'prerequisite_configuration.php', 'program_centric_assignment.php'],
        'of' => ['subject_history', 'curriculum_export.php', 'grade_weighting_setup.php', 'electives_manager.php', 'prerequisite_configuration.php', 'program_centric_assignment.php'],
        'fa' => ['electives_manager.php', 'prerequisite_configuration.php']];
    foreach ($visible as $k => $want) {
        $html = $who[$k]->req('dashboard/account.php')[1];
        if (preg_match('#<aside class="sidebar".*?</aside>#s', $html, $m)) $html = $m[0]; else $truth(false, "$k sidebar found");
        $got = array_values(array_filter($links, static fn(string $l): bool => str_contains($html, $l)));
        sort($got); sort($want);
        $truth($got === $want, "$k sidebar: got " . implode(',', $got));
    }
} finally {
    proc_terminate($server);
    @unlink($log);
}
echo $failures ? "FAILED $failures of $checks\n" : "PASS $checks checks\n";
exit($failures ? 1 : 0);
