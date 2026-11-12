<?php
// Curriculum Export: optional password protection (AES-256 ZIP) for CSV and PDF, and the real PDF itself. CLI only. Run with DB_NAME=sms_db_uitest as the structure source.
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
    foreach ([1 => ['X1', 'Export One'], 2 => ['X2', 'Export Two, with comma'], 3 => ['X3', 'Export "Three"'],
              4 => ['X4', "Introducci\u{00F3}n \u{2013} An\u{00E1}lisis"], 5 => ['X5', "Seminar \u{2713} \u{65E5}\u{672C} \u{03A9}"]] as $id => [$code, $title]) {
        $year = $id >= 4 ? '2nd Year' : '1st Year'; $sem = $id >= 4 ? '2nd Semester' : '1st Semester';
        $db->query("INSERT INTO subjects(id,subject_code,subject_title,units,lecture_units,laboratory_units) VALUES($id,'$code','$title',3,3,0)");
        $db->query("INSERT INTO curriculum_version_subjects(id,curriculum_version_id,subject_id,year_level,semester) VALUES($id,1,$id,'$year','$sem')");
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

    // ======== E2: real PDF, same data as the CSV ========
    $pdfText = static function (string $pdf): string {   // inflate every content stream so the text can be inspected
        $out = '';
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) foreach ($m[1] as $stream) { $x = @gzuncompress($stream); $out .= $x === false ? $stream : $x; }
        return preg_replace('/\\\\([()\\\\])/', '$1', $out);   // PDF string escapes: \( \) \\
    };
    $csvCodes = static function (string $csv): array {
        $rows = array_map('str_getcsv', preg_split('/\r\n/', trim(ltrim($csv, "\xEF\xBB\xBF"))));
        $head = array_shift($rows); $i = array_search('Subject Code', $head, true); $codes = [];
        foreach ($rows as $r) if (isset($r[$i]) && preg_match('/^X\d$/', $r[$i])) $codes[] = $r[$i];
        sort($codes); return $codes;
    };
    $pdfCodes = static function (string $text): array { preg_match_all('/\((X\d)\)/', $text, $m); $c = array_unique($m[1]); sort($c); return array_values($c); };
    $auditCountBefore = (int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0];

    $r = $admin->api('export', 'download_pdf', $q, 'POST');
    $h = implode("\n", $admin->headers);
    ok($r['status'] === 200 && stripos($h, 'Content-Type: application/pdf') !== false && preg_match('/filename="EP_EC_V1\.pdf"/', $h) === 1, 'Unprotected PDF download: application/pdf, EP_EC_V1.pdf');
    $pdf = $r['body'];
    ok(str_starts_with($pdf, '%PDF-') && str_contains(substr($pdf, -16), '%%EOF'), 'PDF starts with %PDF- and ends with %%EOF');
    ok(preg_match('/\/Type \/Pages.*?\/Count (\d+)/s', $pdf, $pc) === 1 && (int)$pc[1] > 0, 'PDF has at least one page (' . ($pc[1] ?? '?') . ')');
    $text = $pdfText($pdf);
    $dbUnits = rtrim(rtrim((string)$db->query('SELECT SUM(s.units) FROM curriculum_version_subjects p JOIN subjects s ON s.id=p.subject_id WHERE p.curriculum_version_id=1')->fetch_row()[0], '0'), '.');
    $dbCount = (int)$db->query('SELECT COUNT(*) FROM curriculum_version_subjects WHERE curriculum_version_id=1')->fetch_row()[0];
    ok(str_contains($text, 'Total units: ' . $dbUnits) && str_contains($text, 'Total subjects: ' . $dbCount), "PDF totals equal the database ($dbCount subjects, $dbUnits units)");
    $plainAll = $admin->api('export', 'download_csv', $q, 'POST')['body'];
    ok($pdfCodes($text) === $csvCodes($plainAll) && count($csvCodes($plainAll)) === $dbCount, 'PDF lists exactly the subject codes of the CSV: ' . implode(',', $pdfCodes($text)));
    ok(str_contains($text, 'Curriculum Structure') && str_contains($text, 'EC - Export Curriculum') && str_contains($text, 'Entire Curriculum') && str_contains($text, 'Draft / review: not started') && str_contains($text, '1st Year') && str_contains($text, '2nd Semester'), 'PDF carries title, curriculum, scope, version status and year/semester groups');
    ok(str_contains($text, 'Generated from stored curriculum data (draft/approved status as shown)') && str_contains($text, 'Page 1 of 1') && !preg_match('/officially approved|CHED approved|accredited by/i', $text), 'PDF footer wording and page numbers; no approval claim');
    ok(str_contains($text, "Introducci\xF3n \x96 An\xE1lisis"), 'Accents and the en dash are mapped to Windows-1252');
    ok(str_contains($text, 'Seminar ? ? ?') || str_contains($text, 'Seminar ?'), 'Unmappable characters (check mark, CJK, omega) become "?" and do not break the PDF');
    ok(strlen($text) > 500 && !str_contains($pdf, 'Corr3ct'), 'PDF is non-trivial and holds no password');

    // scope filters change CSV and PDF equally
    foreach ([['1st Year', '', ['X1', 'X2', 'X3']], ['2nd Year', '2nd Semester', ['X4', 'X5']], ['', '1st Semester', ['X1', 'X2', 'X3']]] as [$yr, $sem, $expected]) {
        $sq = $q + ['year_level' => $yr, 'semester' => $sem];
        $c = $admin->api('export', 'download_csv', $sq, 'POST'); $p = $admin->api('export', 'download_pdf', $sq, 'POST');
        ok($c['status'] === 200 && $p['status'] === 200 && $csvCodes($c['body']) === $expected && $pdfCodes($pdfText($p['body'])) === $expected, "Scope [$yr|$sem]: CSV and PDF both contain exactly " . implode(',', $expected));
    }
    $pt = $pdfText($admin->api('export', 'download_pdf', $q + ['year_level' => '2nd Year', 'semester' => '2nd Semester'], 'POST')['body']);
    ok(str_contains($pt, '2nd Year') && str_contains($pt, 'Total subjects: 2') && str_contains($pt, 'Total units: 6'), 'Filtered PDF shows its own scope and totals');

    // protected PDF: inside the AES-256 ZIP (E1 code path), needs the password
    $tmpBefore = count(tempZipFiles());
    $r = $admin->api('export', 'download_pdf', $protect($password), 'POST');
    $h = implode("\n", $admin->headers);
    ok($r['status'] === 200 && stripos($h, 'Content-Type: application/zip') !== false && preg_match('/filename="EP_EC_V1_pdf_protected\.zip"/', $h) === 1, 'Protected PDF: application/zip named _pdf_protected.zip');
    $zip = $r['body'];
    ok(str_starts_with($zip, "PK\x03\x04") && unpack('v', substr($zip, 8, 2))[1] === 99 && (unpack('v', substr($zip, 6, 2))[1] & 1), 'Protected PDF archive is WinZip AES and flagged encrypted');
    ok(!str_contains($zip, '%PDF') && !str_contains($zip, 'Curriculum Structure'), 'No PDF bytes visible in the archive');
    [$opened, $content, $entry] = readZip($zip, null); ok($opened && $content === false, 'PDF ZIP: unreadable without the password');
    [, $content] = readZip($zip, 'wrong-password-123'); ok($content === false, 'PDF ZIP: wrong password fails');
    [, $content, $entry] = readZip($zip, $password);
    ok($content !== false && $entry === 'EP_EC_V1.pdf' && str_starts_with($content, '%PDF-'), 'PDF ZIP: right password gives EP_EC_V1.pdf');
    ok($pdfCodes($pdfText($content)) === $csvCodes($plainAll) && str_contains($pdfText($content), 'Total units: ' . $dbUnits), 'Decrypted PDF has the same subjects and totals');
    ok(count(tempZipFiles()) === $tmpBefore, 'No temporary archive left behind after PDF export');

    // validation, permissions, CSRF, GET
    $auditBefore = (int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0];
    foreach (['missing' => [$q + ['protect' => '1'], 'Enter a password'], 'short' => [$protect('short-pw1'), 'at least 10'], 'mismatch' => [$protect($password, $password . 'x'), 'do not match']] as $label => [$data, $expected]) {
        $r = $admin->api('export', 'download_pdf', $data, 'POST');
        ok($r['status'] === 422 && str_contains($r['json']['message'] ?? '', $expected) && !str_contains($r['body'], $password), "PDF rejected ($label)");
    }
    $r = $viewer->api('export', 'download_pdf', $q, 'POST'); ok($r['status'] === 403 && !str_starts_with($r['body'], '%PDF'), 'Role without export rights: PDF refused (403)');
    $r = $viewer->api('export', 'download_pdf', $protect($password), 'POST'); ok($r['status'] === 403 && !str_starts_with($r['body'], 'PK'), 'Role without export rights: protected PDF refused (403)');
    $r = $guest->api('export', 'download_pdf', $q, 'POST'); ok(in_array($r['status'], [401, 403], true), 'Guest refused for PDF');
    $r = $admin->api('export', 'download_pdf', $q, 'POST', ''); ok($r['status'] === 403 && str_contains($r['body'], 'security token'), 'PDF needs a valid CSRF token');
    $r = $admin->request('modules/curriculum/http/export_actions.php', ['action' => 'download_pdf'] + $protect('decoy-url-password-1')); ok($r['status'] === 405, 'PDF: a password in a URL (GET) is refused');
    $r = $admin->api('export', 'download_pdf', ['academic_program_id' => 1, 'curriculum_id' => 1, 'curriculum_version_id' => 1, 'year_level' => '9th Year'], 'POST'); ok($r['status'] === 422, 'PDF: an unavailable scope is refused');
    ok((int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0] === $auditBefore, 'Refused PDF requests write no audit row');
    $r = $exporter->api('export', 'download_pdf', $q, 'POST'); ok($r['status'] === 200 && str_starts_with($r['body'], '%PDF-'), 'Role with draft export rights can download a draft PDF');

    // audit rows
    $rows = $db->query("SELECT user_id, metadata_json FROM audit_logs WHERE action='CURRICULUM_EXPORT_GENERATE' AND id > $auditCountBefore ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $formats = array_map(fn($x) => json_decode($x['metadata_json'], true)['format'] . ':' . (json_decode($x['metadata_json'], true)['protected'] ? 'p' : 'u'), $rows);
    ok(in_array('pdf:u', $formats, true) && in_array('pdf_zip_aes256:p', $formats, true) && in_array('csv:u', $formats, true), 'Audit: pdf (unprotected), pdf_zip_aes256 (protected) and csv rows exist: ' . implode(',', array_unique($formats)));
    $pm = null; foreach ($rows as $x) { $mm = json_decode($x['metadata_json'], true); if ($mm['format'] === 'pdf_zip_aes256') $pm = [$x['user_id'], $mm]; }
    ok($pm && (int)$pm[0] === 1 && $pm[1]['protected'] === true && $pm[1]['scope'] === 'Entire Curriculum' && (int)$pm[1]['academic_program_id'] === 1 && (int)$pm[1]['curriculum_id'] === 1 && (int)$pm[1]['curriculum_version_id'] === 1, 'Audit row: actor, program, curriculum, version, scope, format and protected flag');
    $fl = null; foreach ($rows as $x) { $mm = json_decode($x['metadata_json'], true); if ($mm['format'] === 'pdf' && $mm['scope'] !== 'Entire Curriculum') $fl = $mm; }
    ok($fl !== null && str_contains($fl['scope'], '2nd Year'), 'Audit row records a filtered scope');
    ok(!preg_grep('/pass|secret|hash|key/i', array_keys($pm[1] ?? [])), 'PDF audit metadata has no password-like keys');

    // page redesign
    $page = $admin->request('modules/curriculum/pages/curriculum_export.php')['body'];
    ok(substr_count($page, 'class="mapping-context-bar') === 1 && str_contains($page, 'id="exportProgramSearch"'), 'Page: shared context bar kept');
    ok(!str_contains($page, 'role="tab"') && !str_contains($page, 'export-scope-card') && !str_contains($page, 'generateCurriculumPreview') && !str_contains($page, 'exportScopeSummary') && !str_contains($page, 'Entire Curriculum'), 'Page: no tabs, no Export Scope card, no Generate button, no "Entire Curriculum" chip');
    ok(str_contains($page, 'Download CSV') && str_contains($page, 'Download PDF') && str_contains($page, 'id="printCurriculumExport"') && substr_count($page, 'Protect with password') === 1, 'Page: CSV, PDF and Print actions and ONE shared "Protect with password"');
    ok(substr_count($page, 'class="csms-select"') === 2 && str_contains($page, 'csms-filter-row') && str_contains($page, 'csms-empty') && str_contains($page, 'csms-info__tip'), 'Page: year and semester are shared csms-selects in a csms-filter-row, with csms-empty and csms-info');
    ok(substr_count($page, 'Preview generated from current stored curriculum data') === 0, 'Page: no duplicated "Preview generated" line');
    ok(!str_contains($page, 'data-print-page') && !str_contains($page, 'export-summary-card') && str_contains($page, 'export-preview-head') && str_contains($page, 'class="export-top"') && str_contains($page, 'data-pw-toggle="exportPassword"') && str_contains($page, '10-128 characters') && preg_match('/id="exportProtectFields"[^>]*hidden/', $page) === 1, 'Page: no separate print page, summary card, password toggles, 10-128 hint, fields hidden by default');
    $css = file_get_contents(__DIR__ . '/../modules/curriculum/assets/curriculum-export.css');
    ok(str_contains($css, '.export-protect-fields[hidden] { display: none !important; }'), 'CSS: the hidden attribute on the password block cannot be overridden by its grid display');
    $jsText = file_get_contents(__DIR__ . '/../modules/curriculum/assets/curriculum-export.js');
    ok(str_contains($jsText, "'download_pdf'") && !str_contains($jsText, 'activateTab') && !preg_match('/searchParams\.set\([^)]*password/i', $jsText), 'Script: PDF action present, tabs gone, password never placed in a URL');
    ok(is_file(__DIR__ . '/../shared/lib/fpdf/NOTICE.md') && is_file(__DIR__ . '/../shared/lib/fpdf/.htaccess') && str_contains(file_get_contents(__DIR__ . '/../shared/lib/fpdf/NOTICE.md'), 'permissive'), 'FPDF is vendored with a NOTICE (licence) and a deny rule');
    $svcSrc = file_get_contents(__DIR__ . '/../modules/curriculum/services/CurriculumPdfRenderer.php');
    ok(!str_contains($svcSrc, 'SetProtection') && !str_contains($svcSrc, '/Encrypt'), 'PDF renderer does no PDF-level encryption');

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
