<?php
// Academic Programs page per role over real HTTP. sms_db_uitest only; every create is on a disposable row via the API.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('DB_NAME') !== 'sms_db_uitest') { fwrite(STDERR, "Refusing: set DB_NAME=sms_db_uitest\n"); exit(1); }
require_once __DIR__ . '/../shared/database_config.php';
$db = csms_database_connect(csms_database_config());
$creds = [];
foreach (file(__DIR__ . '/../.env.uitest', FILE_IGNORE_NEW_LINES) as $line) if (preg_match('/^(UITEST_\w+)=(.*)$/', $line, $m)) $creds[$m[1]] = $m[2];
$port = 8096;
$log = tempnam(sys_get_temp_dir(), 'prog');
$env = getenv(); $env['APP_ENV'] = 'development'; $env['SMS_SESSION_COOKIE_SECURE'] = '0'; $env['CIMF_AI_ENABLED'] = 'false';
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__)], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env);
for ($i = 0; $i < 40; $i++) { $c = @stream_socket_client("tcp://127.0.0.1:$port", $e, $s, .2); if ($c) { fclose($c); break; } usleep(100000); }

final class ProgramClient {
    private array $cookies = []; public string $csrf = '';
    public function __construct(private int $port) {}
    public function req(string $path, ?array $post = null): array {
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
$failures = 0; $checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void { $checks++; if (!$ok) { $failures++; echo "FAIL $label\n"; } };
$page = 'modules/curriculum/pages/academic_programs.php';
$api = 'modules/curriculum/http/program_actions.php';
try {
    $can = ['superadmin' => [true, true], 'admin' => [true, true], 'staff' => [false, false], 'acadoffice' => [false, false], 'acadfaculty' => [false, false]];
    $who = [];
    foreach ($can as $key => [$create, $update]) {
        $c = new ProgramClient($port); $c->req('auth/signin.php');
        $up = strtoupper($key);
        [$s] = $c->req('shared/auth_actions.php', ['action' => 'login', 'username' => $creds["UITEST_{$up}_USER"], 'password' => $creds["UITEST_{$up}_PASS"], '_csrf_token' => $c->csrf]);
        if ($s !== 200) throw new RuntimeException("login $key");
        $who[$key] = $c;
        [$status, $body] = $c->req($page);
        $check($status === 200, "$key opens the page");
        $check(str_contains($body, 'data-program-tab="college"') && str_contains($body, 'data-program-tab="shs"') && str_contains($body, 'id="programRecordCurricula"') && !str_contains($body, 'programSidePanel'), "$key has the tabs and record modal");
        $check(!str_contains($body, 'program-category-grid'), "$key has no landing cards");
        $check(str_contains($body, 'id="newProgramButton"') === $create, "$key create button");
        $check(str_contains($body, 'id="programModal"') === ($create || $update), "$key program form");
        $check(str_contains($body, 'data-can-update-programs="' . ($update ? '1' : '0') . '"'), "$key update flag");
        foreach (['college', 'shs'] as $category) $check($c->req("$page?category=$category")[0] === 200, "$key category $category");
        $check($c->req("$page?category=bogus")[0] === 400, "$key invalid category is 400");
        $list = $c->req("$api?action=list&page=1&per_page=50&program_category=shs")[2]['programs'] ?? null;
        $check(is_array($list), "$key lists programs");
    }
    // Writes stay on the server's permission and CSRF rules.
    $form = ['action' => 'create', 'program_code' => 'T-' . bin2hex(random_bytes(3)), 'program_name' => 'Program page test', 'program_type' => "Bachelor's Degree", 'status' => 'active'];
    foreach (['staff', 'acadoffice', 'acadfaculty'] as $key) {
        $who[$key]->req($page);
        $check($who[$key]->post($api, $form)[0] === 403, "$key cannot create a program");
    }
    $who['admin']->req($page);
    $check($who['admin']->req($api, $form + ['_csrf_token' => 'wrong'])[0] === 403, 'wrong CSRF token is refused');
    $made = $who['admin']->post($api, $form);
    $check($made[0] === 201, 'Admin creates a program: ' . $made[0] . ' ' . ($made[2]['message'] ?? ''));
    $id = (int)($made[2]['program']['id'] ?? 0);
    $check((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE entity_type='academic_program' AND entity_id=$id")->fetch_row()[0] >= 1, 'create is audited');
    foreach (['staff', 'acadfaculty'] as $key) $check($who[$key]->post($api, ['action' => 'archive', 'id' => $id])[0] === 403, "$key cannot archive");
    // Archive and restore, for an active and an inactive program: stored previous status, refusals with their real reason, audit entries.
    $restoreApi = 'dashboard/archive_actions.php';
    $row = static fn(int $pid): array => $db->query("SELECT status, archived_at, archive_previous_status FROM academic_programs WHERE id=$pid")->fetch_assoc();
    $audited = static fn(string $action, int $pid): int => (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='$action' AND entity_id=$pid")->fetch_row()[0];
    $inactive = $who['admin']->post($api, ['status' => 'inactive', 'program_code' => 'T-' . bin2hex(random_bytes(3))] + $form);
    $inactiveId = (int)($inactive[2]['program']['id'] ?? 0);
    foreach (['active' => $id, 'inactive' => $inactiveId] as $stored => $pid) {
        $before = $row($pid);
        $check($before['status'] === $stored && $before['archived_at'] === null, "$stored program starts $stored and not archived");
        $check($who['admin']->post($api, ['action' => 'archive', 'id' => $pid])[0] === 200, "Admin archives an $stored program");
        $after = $row($pid);
        $check($after['archived_at'] !== null && $after['archive_previous_status'] === $stored && $after['status'] === 'inactive', "$stored program archived with its previous status stored");
        $check($audited('PROGRAM_ARCHIVE', $pid) === 1, "$stored archive is audited");
        $again = $who['admin']->post($api, ['action' => 'archive', 'id' => $pid]);
        $check($again[0] === 409 && str_contains((string)($again[2]['message'] ?? ''), 'already archived'), "archiving an archived $stored program is 409 with its reason: " . $again[0] . ' ' . ($again[2]['message'] ?? ''));
        $check($who['staff']->post($restoreApi, ['action' => 'restore', 'module' => 'program', 'id' => $pid])[0] === 403, "Staff cannot restore the $stored program");
        $check($who['admin']->post($restoreApi, ['action' => 'restore', 'module' => 'program', 'id' => $pid])[0] === 200, "Admin restores the $stored program");
        $restored = $row($pid);
        $check($restored['status'] === $stored && $restored['archived_at'] === null && $restored['archive_previous_status'] === null, "restore returns the program to $stored");
        $check($audited('PROGRAM_RESTORE', $pid) === 1, "$stored restore is audited");
        $twice = $who['admin']->post($restoreApi, ['action' => 'restore', 'module' => 'program', 'id' => $pid]);
        $check($twice[0] === 409, "restoring a program that is not archived is 409");
    }
    $missing = $who['admin']->post($api, ['action' => 'archive', 'id' => 999999999]);
    $check($missing[0] === 404 && ($missing[2]['message'] ?? '') !== '', 'archiving a missing program is 404 with a reason');
    $invalid = $who['admin']->post($api, ['action' => 'archive', 'id' => 0]);
    $check($invalid[0] === 422, 'an invalid id is 422');
    $check(!str_contains(json_encode($again[2] ?? []), 'mysqli') && !str_contains(json_encode($missing[2] ?? []), 'Exception'), 'refusals do not leak internals');
    // Other modules keep the active-only rule: an inactive curriculum cannot be archived.
    $curriculumId = (int)$db->query("SELECT id FROM curricula WHERE status='active' LIMIT 1")->fetch_row()[0];
    $db->query("UPDATE curricula SET status='inactive' WHERE id=$curriculumId");
    $keep = $who['admin']->post('modules/curriculum/http/curriculum_actions.php', ['action' => 'archive', 'id' => $curriculumId]);
    $db->query("UPDATE curricula SET status='active' WHERE id=$curriculumId");
    $check($keep[0] === 409 && str_contains((string)($keep[2]['message'] ?? ''), 'Only active'), 'curriculum archive keeps the active-only rule, as 409: ' . $keep[0]);
    // Leave the inactive program archived-free but inactive for the page checks below.
    // Summary follows the read scope.
    $bsit = (int)$db->query("SELECT c.academic_program_id FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE v.status='draft' AND v.review_status<>'approved' LIMIT 1")->fetch_row()[0];
    $allVersions = (int)$db->query("SELECT COUNT(*) FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE c.academic_program_id=$bsit")->fetch_row()[0];
    $scoped = (int)$db->query("SELECT COUNT(*) FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE c.academic_program_id=$bsit AND (v.review_status='approved' OR v.status='active')")->fetch_row()[0];
    $check(($who['staff']->req("$api?action=summary&id=$bsit")[2]['summary']['versions'] ?? -1) === $allVersions, 'Staff summary counts every version');
    $check(($who['acadfaculty']->req("$api?action=summary&id=$bsit")[2]['summary']['versions'] ?? -1) === $scoped, 'Faculty summary counts only its read scope');
    // Modal data: Faculty sees only versions in its read scope.
    $program = (int)$db->query("SELECT c.academic_program_id FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE v.status='draft' AND v.review_status='not_started' LIMIT 1")->fetch_row()[0];
    $curriculum = (int)$db->query("SELECT c.id FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE c.academic_program_id=$program AND v.status='draft' AND v.review_status='not_started' LIMIT 1")->fetch_row()[0];
    $vapi = "modules/curriculum/http/version_actions.php?action=list&curriculum_id=$curriculum&page=1&per_page=50";
    $hidden = array_map('intval', array_column($db->query("SELECT id FROM curriculum_versions WHERE curriculum_id=$curriculum AND status='draft' AND review_status<>'approved'")->fetch_all(MYSQLI_ASSOC), 'id'));
    $seen = array_map('intval', array_column($who['acadfaculty']->req($vapi)[2]['versions'] ?? [], 'id'));
    $check($hidden !== [] && array_intersect($hidden, $seen) === [], 'Faculty panel lists no unapproved drafts');
    $check(in_array('draft', array_column($who['staff']->req($vapi)[2]['versions'] ?? [], 'status'), true), 'Staff panel lists drafts');
} finally {
    proc_terminate($server);
}
echo $failures ? "FAILED $failures of $checks\n" : "PASS $checks checks\n";
exit($failures ? 1 : 0);
