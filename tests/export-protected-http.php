<?php
// Curriculum Export: optional password protection (AES-256 ZIP). CLI only. Run with DB_NAME=sms_db_uitest as the structure source.
// It creates and drops its own disposable schema and loopback PHP server; neither sms_db nor sms_db_uitest rows are written.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/FoundationHttpClient.php';
$config = csms_database_config();
if (!in_array($config['host'], ['localhost', '127.0.0.1'], true) || getenv('APP_ENV') === 'production') throw new RuntimeException('Local development only.');
if ($config['database'] === 'sms_db') throw new RuntimeException('Never run against sms_db; use DB_NAME=sms_db_uitest.');
$db = csms_database_connect($config); $source = $config['database'];
if (!preg_match('/^[A-Za-z0-9_]+$/D', $source)) throw new RuntimeException('Unsafe source schema');
$name = 'csms_export_test_' . bin2hex(random_bytes(8)); $server = null; $clients = []; $checks = 0; $created = false;
$envBefore = getenv(); $log = tempnam(sys_get_temp_dir(), 'ex-http-'); $sessionDir = $log . '-sessions'; mkdir($sessionDir);
function ok(bool $cond, string $label): void { global $checks; if (!$cond) throw new RuntimeException('FAILED: ' . $label); $checks++; }
function tempZipFiles(): array { return glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cx*') ?: []; }
/** Reads the first entry of a ZIP held in memory; returns [opened, content-or-false]. Uses a private temp copy that is removed. */
function readZip(string $bytes, ?string $password): array {
    $tmp = tempnam(sys_get_temp_dir(), 'zt'); file_put_contents($tmp, $bytes);
    $zip = new ZipArchive(); $opened = $zip->open($tmp) === true; $content = false; $entry = null;
    if ($opened) { if ($password !== null) $zip->setPassword($password); $entry = $zip->getNameIndex(0); $content = $zip->getFromIndex(0); $zip->close(); }
    unlink($tmp);
    return [$opened, $content, $entry];
}
$password = 'Corr3ct-Horse-Battery-' . bin2hex(random_bytes(4));   // test-only secret, never printed
try {
    $db->query("CREATE DATABASE `$name`"); $created = true;
    foreach (array_column($db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM), 0) as $table) {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) throw new RuntimeException('Unsafe table name');
        $db->query("CREATE TABLE `$name`.`$table` LIKE `$source`.`$table`");
    }
    $db->select_db($name);
    foreach (['roles', 'permissions', 'role_permissions'] as $table) $db->query("INSERT INTO `$table` SELECT * FROM `$source`.`$table`");
    $loginPassword = bin2hex(random_bytes(24)); $hash = password_hash($loginPassword, PASSWORD_DEFAULT);
    foreach (['eadmin', 'eexporter', 'eviewer'] as $i => $username) {
        $id = $i + 1; $email = $username . '@example.invalid';
        $stmt = $db->prepare("INSERT INTO users(id,username,email,first_name,last_name,password_hash,role) VALUES(?,?,?,'Test','Only',?,'student')");
        $stmt->bind_param('isss', $id, $username, $email, $hash); $stmt->execute();
    }
    $db->query("INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE name='super_admin'");
    $db->query("INSERT INTO roles(name,display_name,description) VALUES('e_exporter','Exporter','Disposable test fixture')"); $exporterRole = (int)$db->insert_id;
    $db->query("INSERT INTO roles(name,display_name,description) VALUES('e_viewer','Viewer','Disposable test fixture')"); $viewerRole = (int)$db->insert_id;
    $db->query("INSERT INTO user_roles(user_id,role_id) VALUES(2,$exporterRole),(3,$viewerRole)");
    $db->query("INSERT INTO role_permissions(role_id,permission_id) SELECT $exporterRole,id FROM permissions WHERE name IN ('curriculum_export.view','curriculum_export.draft','curriculum.view_unapproved')");
    $db->query("INSERT INTO role_permissions(role_id,permission_id) SELECT $viewerRole,id FROM permissions WHERE name IN ('curriculum.view')");   // no export rights

    $db->query("INSERT INTO academic_programs(id,program_code,program_name) VALUES(1,'EP','Export Program')");
    $db->query("INSERT INTO curricula(id,curriculum_code,curriculum_name,academic_program_id) VALUES(1,'EC','Export Curriculum',1)");
    $db->query("INSERT INTO curriculum_versions(id,curriculum_id,version_label,effective_academic_year,status,review_status) VALUES(1,1,'V1','2026-2027','draft','not_started')");
    foreach ([1 => ['X1', 'Export One'], 2 => ['X2', 'Export Two, with comma'], 3 => ['X3', 'Export "Three"']] as $id => [$code, $title]) {
        $db->query("INSERT INTO subjects(id,subject_code,subject_title,units,lecture_units,laboratory_units) VALUES($id,'$code','$title',3,3,0)");
        $db->query("INSERT INTO curriculum_version_subjects(id,curriculum_version_id,subject_id,year_level,semester) VALUES($id,1,$id,'1st Year','1st Semester')");
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr); $address = stream_socket_get_name($socket, false); fclose($socket);
    $env = $envBefore; $env['DB_NAME'] = $name; $env['APP_ENV'] = 'development'; $env['SMS_SESSION_COOKIE_SECURE'] = '0';
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessionDir, '-d', 'error_log=' . $log, '-S', $address, '-t', dirname(__DIR__)],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env, ['bypass_shell' => true]);
    if (!is_resource($server)) throw new RuntimeException('Test HTTP server failed'); fclose($pipes[0]);
    for ($i = 0; $i < 60; $i++) { $ready = @stream_socket_client('tcp://' . $address, $errno, $errstr, .1); if ($ready) { fclose($ready); break; } usleep(100000); }
    $admin = new FoundationHttpClient('http://' . $address); $exporter = new FoundationHttpClient('http://' . $address);
    $viewer = new FoundationHttpClient('http://' . $address); $guest = new FoundationHttpClient('http://' . $address);
    $clients = [$admin, $exporter, $viewer, $guest];
    foreach ([[$admin, 'eadmin'], [$exporter, 'eexporter'], [$viewer, 'eviewer']] as [$client, $username]) {
        $client->request('auth/signin.php');
        $r = $client->request('shared/auth_actions.php', ['action' => 'login', 'username' => $username, 'password' => $loginPassword], 'POST');
        ok($r['status'] === 200, 'Fixture login ' . $username);
        $client->request('modules/curriculum/pages/curriculum_export.php');   // loads the CSRF token
    }
    $q = ['academic_program_id' => 1, 'curriculum_id' => 1, 'curriculum_version_id' => 1];
    $protect = fn(string $pw, ?string $confirm = null) => $q + ['protect' => '1', 'export_password' => $pw, 'export_password_confirm' => $confirm ?? $pw];

    // ---- Page ----
    $page = $admin->request('modules/curriculum/pages/curriculum_export.php');
    ok($page['status'] === 200 && substr_count($page['body'], 'class="mapping-context-bar') === 1, 'Export page keeps the shared context bar');
    ok(str_contains($page['body'], 'Protect with password') && str_contains($page['body'], 'id="exportPassword"') && str_contains($page['body'], 'id="exportPasswordConfirm"') && str_contains($page['body'], 'csms-info__tip'), 'Page: protect option, password and confirm fields, csms-info');
    ok(str_contains($page['body'], '7-Zip') && stripos($page['body'], 'Windows File Explorer') !== false, 'Page: says which tools open the file (7-Zip; Explorer caveat)');
    ok(preg_match('/<input[^>]*type="password"[^>]*value=/i', $page['body']) !== 1 && !str_contains($page['body'], $password), 'Page: password fields are empty');

    // ---- Plain export unchanged ----
    $plain = $admin->api('export', 'download_csv', $q, 'POST');
    ok($plain['status'] === 200 && str_starts_with(implode("\n", $admin->headers), 'HTTP') && preg_grep('/^Content-Type: text\/csv/i', $admin->headers), 'Unprotected export: still text/csv');
    ok(str_starts_with($plain['body'], "\xEF\xBB\xBF") && str_contains($plain['body'], 'X1') && str_contains($plain['body'], 'X3'), 'Unprotected export: CSV with the selected data');
    $plainAudit = $db->query("SELECT metadata_json FROM audit_logs WHERE action='CURRICULUM_EXPORT_GENERATE' ORDER BY id DESC LIMIT 1")->fetch_row()[0];
    ok(str_contains($plainAudit, '"format":"csv"') && str_contains($plainAudit, '"protected":false'), 'Unprotected export audit unchanged in meaning (format csv, protected false)');

    // ---- Validation: nothing is generated and nothing is audited ----
    $auditBefore = (int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0]; $tmpBefore = count(tempZipFiles());
    $cases = [
        'missing' => [$q + ['protect' => '1'], 'Enter a password'],
        'short' => [$protect('short-pw1'), 'at least 10'],
        'spaces' => [$protect('          '), 'only spaces'],
        'mismatch' => [$protect($password, $password . 'x'), 'do not match'],
        'no confirm' => [$q + ['protect' => '1', 'export_password' => $password], 'Confirm the password'],
        'too long' => [$protect(str_repeat('a', 129)), 'at most 128'],
    ];
    foreach ($cases as $label => [$data, $expected]) {
        $r = $admin->api('export', 'download_csv', $data, 'POST');
        ok($r['status'] === 422 && str_contains($r['json']['message'] ?? '', $expected), "Rejected ($label): " . ($r['json']['message'] ?? $r['status']));
        ok(!str_contains($r['body'], $password) && !str_contains($r['body'], 'aaaaaaaaaaaa'), "Rejected ($label): the response does not echo the password");
    }
    ok((int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0] === $auditBefore, 'Rejected requests write no audit row');

    // ---- Protected export ----
    $r = $admin->api('export', 'download_csv', $protect($password), 'POST');
    ok($r['status'] === 200, 'Protected export succeeds');
    $headers = implode("\n", $admin->headers);
    ok(stripos($headers, 'Content-Type: application/zip') !== false && preg_match('/filename="EP_EC_V1_protected\.zip"/', $headers) === 1, 'Protected export: application/zip with a _protected.zip name');
    ok(!str_contains($headers, $password) && !str_contains($headers, rawurlencode($password)), 'Password not in response headers');
    $zip = $r['body'];
    ok(str_starts_with($zip, "PK\x03\x04"), 'Body is a ZIP archive');
    ok(unpack('v', substr($zip, 8, 2))[1] === 99 && str_contains($zip, "\x01\x99\x07\x00"), 'Entry uses WinZip AES (compression method 99, extra field 0x9901)');
    ok(unpack('v', substr($zip, 6, 2))[1] & 1, 'Entry is flagged encrypted');
    ok(!str_contains($zip, 'Export One') && !str_contains($zip, 'X1') && !str_contains($zip, 'Program Code'), 'No plaintext CSV content anywhere in the archive bytes');
    [$opened, $content, $entry] = readZip($zip, null);
    ok($opened && $content === false, 'Without the password the content cannot be read');
    [, $content] = readZip($zip, 'wrong-password-123');
    ok($content === false, 'Wrong password fails');
    [, $content] = readZip($zip, substr($password, 0, -1));
    ok($content === false, 'Almost-right password fails');
    [, $content, $entry] = readZip($zip, $password);
    ok($content !== false && $entry === 'EP_EC_V1.csv', 'Right password opens the archive; entry is the CSV file');
    // The generation timestamp is not part of the rows; compare everything the plain export contains.
    $strip = static fn(string $csv): string => preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}[^,"\r\n]*/', 'T', $csv);
    ok($strip($content) === $strip($plain['body']), 'Decrypted content equals the plain CSV');
    ok(count(tempZipFiles()) === $tmpBefore, 'No temporary archive is left behind');

    // ---- Audit: who, program, version, format; no password ----
    $row = $db->query("SELECT user_id, action, entity_type, entity_id, description, metadata_json FROM audit_logs WHERE action='CURRICULUM_EXPORT_GENERATE' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $meta = json_decode($row['metadata_json'], true);
    ok((int)$row['user_id'] === 1 && $row['entity_type'] === 'curriculum_version' && (int)$row['entity_id'] === 1 && $meta['format'] === 'csv_zip_aes256' && $meta['protected'] === true
        && (int)$meta['academic_program_id'] === 1 && $meta['version_label'] === 'V1', 'Audit row records actor, program, version, format and that protection was used');
    ok(!preg_grep('/pass|secret|hash|key/i', array_keys($meta)), 'Audit metadata has no password-like keys');

    // ---- Permissions ----
    $auditBefore = (int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0];
    $r = $viewer->api('export', 'download_csv', $protect($password), 'POST');
    ok($r['status'] === 403 && !str_starts_with($r['body'], 'PK'), 'Role without export rights: protected export refused (403)');
    $r = $guest->api('export', 'download_csv', $protect($password), 'POST');
    ok($r['status'] === 401 || $r['status'] === 403, 'Guest refused');
    $r = $admin->api('export', 'download_csv', $protect($password), 'POST', '');
    ok($r['status'] === 403 && str_contains($r['body'], 'security token'), 'Protected export needs a valid CSRF token');
    $r = $admin->request('modules/curriculum/http/export_actions.php', ['action' => 'download_csv'] + $protect('decoy-url-password-1'));   // decoy: a URL is logged by any web server, which is the point of refusing it
    ok($r['status'] === 405, 'Password is never accepted in a URL (GET refused)');
    ok((int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0] === $auditBefore, 'Refused requests write no audit row');
    $r = $exporter->api('export', 'download_csv', $protect($password), 'POST');
    ok($r['status'] === 200 && str_starts_with($r['body'], "PK\x03\x04"), 'Role with export.draft rights can export a draft version protected');
    [, $content] = readZip($r['body'], $password);
    ok($content !== false && str_contains($content, 'X2'), 'That archive opens with the password');

    // ---- The password appears nowhere ----
    $found = [];
    foreach (array_column($db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM), 0) as $table) {
        $res = $db->query("SELECT * FROM `$table`"); if (!$res) continue;
        while ($rec = $res->fetch_assoc()) if (str_contains(json_encode($rec), $password)) $found[] = $table;
    }
    ok($found === [], 'Password appears in no database table' . ($found ? ': ' . implode(',', $found) : ''));
    $logText = file_get_contents($log);
    ok(!str_contains($logText, $password) && !str_contains($logText, rawurlencode($password)), 'Password appears nowhere in the server log');
    ok(count(tempZipFiles()) === $tmpBefore, 'Still no temporary archive left behind');
    $svc = file_get_contents(__DIR__ . '/../modules/curriculum/services/CurriculumExportService.php') . file_get_contents(__DIR__ . '/../modules/curriculum/http/export_actions.php');
    ok(!preg_match('/(error_log|log_application_error|audit_log\w*)\([^;]*\$password/', $svc), 'Source: no logging or audit call receives the password');
} finally {
    foreach ($clients as $c) $c->close();
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($created && preg_match('/^csms_export_test_[a-f0-9]{16}$/D', $name)) $db->query("DROP DATABASE `$name`");
    $db->close();
    $logText = file_get_contents($log); unlink($log); foreach (glob($sessionDir . '/sess_*') as $file) unlink($file); rmdir($sessionDir);
}
ok(!preg_match('/PHP (Warning|Fatal|Notice|Parse error)/', $logText), 'No PHP errors in HTTP server log');
echo "PASS: $checks isolated HTTP checks for protected curriculum export; disposable server and schema removed.\n";
