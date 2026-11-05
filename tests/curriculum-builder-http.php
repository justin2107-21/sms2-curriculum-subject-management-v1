<?php
// Curriculum Builder workspace over real HTTP. sms_db_uitest only, with the scenario from tools/uitest/builder-fixtures.php.
// Creates only disposable rule rows, which it removes again.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('DB_NAME') !== 'sms_db_uitest') { fwrite(STDERR, "Refusing: set DB_NAME=sms_db_uitest\n"); exit(1); }
require_once __DIR__ . '/../shared/database_config.php';
$db = csms_database_connect(csms_database_config());
$creds = [];
foreach (file(__DIR__ . '/../.env.uitest', FILE_IGNORE_NEW_LINES) as $line) if (preg_match('/^(UITEST_\w+)=(.*)$/', $line, $m)) $creds[$m[1]] = $m[2];
$one = static fn(string $sql) => $db->query($sql)->fetch_row()[0];
$id = static fn(string $label): int => (int)$one("SELECT id FROM curriculum_versions WHERE version_label='$label'");
$ref = $id('UITEST-REF'); $rev = $id('UITEST-REV'); $review = $id('UITEST-REVIEW'); $plain = $id('UITEST-B'); $active = $id('UITEST-C');
if (!$ref || !$rev || !$review) { fwrite(STDERR, "Run tools/uitest/builder-fixtures.php first (on a freshly rebuilt test database).\n"); exit(1); }

$port = 8095;
$log = tempnam(sys_get_temp_dir(), 'bld');
$env = getenv(); $env['APP_ENV'] = 'development'; $env['SMS_SESSION_COOKIE_SECURE'] = '0'; $env['CIMF_AI_ENABLED'] = 'false';
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__)], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env);
for ($i = 0; $i < 40; $i++) { $c = @stream_socket_client("tcp://127.0.0.1:$port", $e, $s, .2); if ($c) { fclose($c); break; } usleep(100000); }

final class BuilderClient {
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
$page = 'modules/curriculum/pages/curriculum_builder.php';
$vapi = 'modules/curriculum/http/version_actions.php';
$ruleIds = [];
try {
    $who = [];
    foreach (['superadmin', 'admin', 'staff', 'acadoffice', 'acadfaculty'] as $key) {
        $c = new BuilderClient($port); $c->req('auth/signin.php');
        $up = strtoupper($key);
        [$s] = $c->req('shared/auth_actions.php', ['action' => 'login', 'username' => $creds["UITEST_{$up}_USER"], 'password' => $creds["UITEST_{$up}_PASS"], '_csrf_token' => $c->csrf]);
        if ($s !== 200) throw new RuntimeException("login $key");
        $who[$key] = $c;
    }
    $flags = [
        'superadmin' => ['submit' => 1, 'decide' => 1, 'classify' => 1, 'attach' => 1, 'clone' => 1, 'edit-plan' => 1],
        'admin' => ['submit' => 0, 'decide' => 0, 'classify' => 0, 'attach' => 0, 'clone' => 0, 'edit-plan' => 1],
        'staff' => ['submit' => 1, 'decide' => 0, 'classify' => 0, 'attach' => 1, 'clone' => 1, 'edit-plan' => 1],
        'acadoffice' => ['submit' => 0, 'decide' => 1, 'classify' => 1, 'attach' => 0, 'clone' => 0, 'edit-plan' => 0],
        'acadfaculty' => ['submit' => 0, 'decide' => 0, 'classify' => 0, 'attach' => 0, 'clone' => 0, 'edit-plan' => 0],
    ];
    foreach ($who as $key => $c) {
        [$status, $body] = $c->req($page);
        $check($status === 200 && str_contains($body, 'id="builderRoot"') && !str_contains($body, 'id="versionWorkspace"'), "$key opens the Builder page without a redirect");
        $check($c->req("$page?program_id=1&curriculum_id=1&version_id=$rev")[0] === 200, "$key opens the Builder with context in the address");
        foreach ($flags[$key] as $name => $expected) $check(str_contains($body, 'data-can-' . $name . '="' . $expected . '"'), "$key flag $name = $expected");
        $check(str_contains($body, 'id="builderClassifyModal"') === (bool)$flags[$key]['classify'] && str_contains($body, 'id="builderCloneModal"') === (bool)$flags[$key]['clone'], "$key only gets the dialogs it may use");
        $check($c->req('modules/curriculum/pages/curricula.php')[0] === 200, "$key: the classic Curricula page still opens");
    }
    $guest = new BuilderClient($port);
    $check($guest->req($page)[0] !== 200, 'a guest does not get the Builder');
    $check($guest->req("$vapi?action=builder_summary&curriculum_version_id=$rev")[0] === 401, 'a guest gets 401 from builder_summary');

    // builder_summary: figures, target, read scope.
    $sum = static fn(string $who, int $v) => $GLOBALS['who'][$who]->req("$GLOBALS[vapi]?action=builder_summary&curriculum_version_id=$v");
    $units = (float)$one("SELECT COALESCE(SUM(s.units),0) FROM curriculum_version_subjects m JOIN subjects s ON s.id=m.subject_id WHERE m.curriculum_version_id=$rev");
    $count = (int)$one("SELECT COUNT(*) FROM curriculum_version_subjects WHERE curriculum_version_id=$rev");
    $r = $sum('staff', $rev);
    $check($r[0] === 200 && (float)$r[2]['summary']['placed_units'] === $units && $r[2]['summary']['placements'] === $count, 'summary counts placements and units');
    $check(isset($r[2]['summary']['target']) && abs((float)$r[2]['summary']['target']['value'] - 20) < 0.001 && ($r[2]['summary']['target']['rule_name'] ?? '') === 'UITEST minimum units', 'the program rule is the target');
    $program = (int)$one("SELECT c.academic_program_id FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE v.id=$rev");
    $curriculum = (int)$one("SELECT curriculum_id FROM curriculum_versions WHERE id=$rev");
    $source = (int)$one('SELECT id FROM compliance_references LIMIT 1');
    $db->query("INSERT INTO compliance_rules(compliance_reference_id,rule_name,rule_type,numeric_value,academic_program_id,curriculum_id,technical_status) VALUES($source,'curriculum rule','MIN_TOTAL_UNITS',30,NULL,$curriculum,'active')");
    $ruleIds[] = (int)$db->insert_id;
    $check(abs((float)($sum('staff', $rev)[2]['summary']['target']['value'] ?? 0) - 30) < 0.001, "the curriculum's own rule beats the program's");
    $db->query("INSERT INTO compliance_rules(compliance_reference_id,rule_name,rule_type,numeric_value,academic_program_id,curriculum_id,technical_status) VALUES($source,'inactive rule','MIN_TOTAL_UNITS',99,NULL,$curriculum,'inactive')");
    $ruleIds[] = (int)$db->insert_id;
    $check(abs((float)($sum('staff', $rev)[2]['summary']['target']['value'] ?? 0) - 30) < 0.001, 'an inactive rule is ignored');
    $db->query('DELETE FROM compliance_rules WHERE id IN (' . implode(',', $ruleIds) . ')');
    $ruleIds = [];
    $other = (int)$one("SELECT v.id FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE c.academic_program_id<>$program LIMIT 1");
    $noRule = $sum('staff', $other);
    $check($noRule[0] === 200 && array_key_exists('target', $noRule[2]['summary']) && $noRule[2]['summary']['target'] === null, 'no rule means no target');
    $check($sum('acadfaculty', $rev)[0] === 403, 'Faculty cannot read a draft version summary');
    $check($sum('acadfaculty', $active)[0] === 200, 'Faculty reads an active version summary');
    $check($sum('staff', 999999999)[0] === 404, 'a missing version is 404');
    $check($sum('staff', 0)[0] === 422, 'an invalid id is 422');
    $check(!str_contains(json_encode($sum('staff', 999999999)[2]), 'mysqli'), 'refusals do not leak internals');

    // What the header needs from the existing endpoints.
    $hist = $who['staff']->req("modules/curriculum/http/review_actions.php?action=history&curriculum_version_id=$rev");
    $check($hist[0] === 200 && $hist[2]['can_submit'] === true && $hist[2]['can_decide'] === false, 'a not-started revision can be submitted, not decided');
    $hist = $who['acadoffice']->req("modules/curriculum/http/review_actions.php?action=history&curriculum_version_id=$review");
    $check($hist[0] === 200 && $hist[2]['can_decide'] === true && ($hist[2]['events'][0]['action'] ?? '') === 'submit', 'an under-review version can be decided, with its submit event');
    $ws = $who['staff']->req("modules/curriculum/http/version_actions.php?action=get&id=$plain");
    $check($ws[0] === 200, 'Staff reads the unclassified version');
    $refWs = $who['staff']->req("modules/curriculum/http/version_reference_actions.php?action=workspace&curriculum_version_id=$ref");
    $check($refWs[0] === 200 && isset($refWs[2]['workspace']['academic_program_id']) && $refWs[2]['workspace']['version_role'] === 'reference' && $refWs[2]['workspace']['editable'] === false, 'the workspace carries the program id and says a reference is protected');
    $graph = $who['staff']->req("modules/curriculum/http/requirement_actions.php?action=workspace&curriculum_version_id=$rev");
    $check($graph[0] === 200 && count($graph[2]['review']['sequencing']) === 1, 'the prerequisite review reports the one scenario conflict');
    $check($who['acadfaculty']->req("modules/curriculum/http/requirement_actions.php?action=workspace&curriculum_version_id=$rev")[0] === 403, 'Faculty cannot read prerequisite data of a draft');

    // Existing rules still hold from this page's point of view.
    $check($who['staff']->post('modules/curriculum/http/review_actions.php', ['action' => 'submit', 'curriculum_version_id' => $ref])[0] === 409, 'a reference cannot be submitted');
    $check($who['acadoffice']->post('modules/curriculum/http/review_actions.php', ['action' => 'decide', 'decision' => 'approve', 'reason' => '', 'curriculum_version_id' => $review])[0] === 422, 'approving needs a reason');
    $check($who['acadfaculty']->post('modules/curriculum/http/review_actions.php', ['action' => 'submit', 'curriculum_version_id' => $rev])[0] === 403, 'Faculty cannot submit');

    // Working context for the new module.
    $ctx = 'modules/curriculum/http/working_context_actions.php';
    $saved = $who['staff']->post($ctx, ['action' => 'save', 'module' => 'builder', 'program_id' => $program, 'curriculum_id' => $curriculum, 'version_id' => $rev]);
    $read = $who['staff']->req("$ctx?action=read&module=builder");
    $check($saved[0] === 200 && $read[0] === 200 && (int)($read[2]['context']['version']['id'] ?? 0) === $rev, 'the Builder saves and restores the working context');
    $check($who['staff']->post($ctx, ['action' => 'save', 'module' => 'nonsense', 'program_id' => $program, 'curriculum_id' => $curriculum, 'version_id' => $rev])[0] === 422, 'an unknown module is refused');
} finally {
    if ($ruleIds) $db->query('DELETE FROM compliance_rules WHERE id IN (' . implode(',', $ruleIds) . ')');
    proc_terminate($server);
}
echo $failures ? "FAILED $failures of $checks\n" : "PASS $checks checks\n";
exit($failures ? 1 : 0);
