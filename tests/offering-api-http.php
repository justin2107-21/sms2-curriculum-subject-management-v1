<?php
// Offering API (migration 029): auth, scopes, limits, idempotency, per-item results, flags, own-rows read, no-write guarantee,
// key handling, CLI, rollback. CLI only. Run with DB_NAME=sms_db_uitest (it must already have migration 029 as the structure source).
// Creates and drops its own disposable schema and loopback server. Never run against sms_db.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
$config = csms_database_config();
if (!in_array($config['host'], ['localhost', '127.0.0.1'], true) || getenv('APP_ENV') === 'production') throw new RuntimeException('Local development only.');
if ($config['database'] === 'sms_db') throw new RuntimeException('Never run against sms_db; use DB_NAME=sms_db_uitest.');
$db = csms_database_connect($config); $source = $config['database'];
if (!preg_match('/^[A-Za-z0-9_]+$/D', $source)) throw new RuntimeException('Unsafe source schema');
$name = 'csms_offapi_test_' . bin2hex(random_bytes(8)); $server = null; $created = false; $checks = 0;
$pepper = bin2hex(random_bytes(32));
$envBefore = getenv(); $log = tempnam(sys_get_temp_dir(), 'oa-http-'); $sessionDir = $log . '-sessions'; mkdir($sessionDir);
function ok(bool $cond, string $label): void { global $checks; if (!$cond) throw new RuntimeException('FAILED: ' . $label); $checks++; }
function fingerprint(mysqli $db): string {
    $out = [];
    foreach (['subjects', 'academic_programs', 'curricula', 'curriculum_versions', 'curriculum_version_subjects', 'curriculum_version_subject_snapshots', 'curriculum_subject_requirements'] as $t) $out[] = $t . ':' . $db->query("CHECKSUM TABLE `$t`")->fetch_row()[1];
    return implode('|', $out);
}
/** Runs the client-management CLI with the disposable database. Returns [exit, stdout, stderr]. */
function cli(array $args, array $env): array {
    $p = proc_open([PHP_BINARY, __DIR__ . '/../scripts/api-client-create.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env, ['bypass_shell' => true]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), $out, $err];
}
function keyFrom(string $out): string { ok(preg_match('/^(csms_[a-z0-9]{8}_[A-Za-z0-9_-]{43})$/m', $out, $m) === 1, 'CLI prints one well-formed key'); return $m[1]; }
function call(string $base, string $method, string $path, $body = null, ?string $key = null, array $headers = []): array {
    $h = $headers; if ($key !== null) $h[] = 'Authorization: Bearer ' . $key;
    $ch = curl_init($base . $path);
    $respHeaders = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) { $respHeaders[] = trim($line); return strlen($line); }]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return ['status' => $status, 'body' => (string)$raw, 'json' => json_decode((string)$raw, true), 'headers' => $respHeaders];
}
function expect(array $r, int $status, string $label, ?string $code = null): array {
    ok($r['status'] === $status, "$label: expected $status got {$r['status']} " . substr($r['body'], 0, 200));
    ok(!preg_match('/Fatal error|Stack trace|mysqli_sql_exception|SQLSTATE/', $r['body']), "$label: no internal detail in the response");
    if ($code !== null) ok(($r['json']['error']['code'] ?? null) === $code, "$label: error code $code, got " . ($r['json']['error']['code'] ?? 'none'));
    return $r['json'] ?? [];
}
$item = fn(string $id, array $o = []) => $o + ['external_id' => $id, 'subject_code' => 'S1', 'program_code' => 'TP', 'academic_year' => '2026-2027', 'term' => '1st Semester', 'status' => 'offered', 'section_count' => 3, 'enrolled_count' => 90];
try {
    $db->query("CREATE DATABASE `$name`"); $created = true;
    foreach (array_column($db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM), 0) as $table) {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) throw new RuntimeException('Unsafe table name');
        $db->query("CREATE TABLE `$name`.`$table` LIKE `$source`.`$table`");
    }
    $db->select_db($name);
    foreach (['roles', 'permissions', 'role_permissions'] as $table) $db->query("INSERT INTO `$table` SELECT * FROM `$source`.`$table`");
    ok((int)$db->query("SELECT COUNT(*) FROM permissions WHERE name='api_client.manage'")->fetch_row()[0] === 1, 'Source schema has migration 029 (apply it to sms_db_uitest first)');
    $hash = password_hash('x', PASSWORD_DEFAULT);
    foreach ([[1, 'opsuper', 'super_admin'], [2, 'opadmin', 'admin']] as [$id, $u, $role]) {
        $stmt = $db->prepare("INSERT INTO users(id,username,email,first_name,last_name,password_hash,role) VALUES(?,?,?,'Test','Only',?,'student')");
        $email = "$u@example.invalid"; $stmt->bind_param('isss', $id, $u, $email, $hash); $stmt->execute(); $stmt->close();
        $db->query("INSERT INTO user_roles(user_id,role_id) SELECT $id,id FROM roles WHERE name='$role'");
    }
    ok(array_column($db->query("SELECT r.name FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE p.name='api_client.manage'")->fetch_all(MYSQLI_ASSOC), 'name') === ['super_admin'], 'api_client.manage is granted to Super Admin only');
    // Data: TP active (S1 placed, S2 not placed, S3 inactive), IP inactive program.
    $db->query("INSERT INTO academic_programs(id,program_code,program_name,status) VALUES(1,'TP','Test Program','active'),(2,'IP','Inactive Program','inactive')");
    $db->query("INSERT INTO curricula(id,curriculum_code,curriculum_name,academic_program_id) VALUES(1,'TC','Test Curriculum',1)");
    $db->query("INSERT INTO curriculum_versions(id,curriculum_id,version_label,status) VALUES(1,1,'V1','active')");
    $db->query("INSERT INTO subjects(id,subject_code,subject_title,units,status) VALUES(1,'S1','Subject 1',3,'active'),(2,'S2','Subject 2',3,'active'),(3,'S3','Subject 3',3,'inactive')");
    $db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES(1,1,'1st Year','1st Semester')");

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr); $address = stream_socket_get_name($socket, false); fclose($socket);
    $env = $envBefore; $env['DB_NAME'] = $name; $env['APP_ENV'] = 'development'; $env['API_KEY_PEPPER'] = $pepper; $env['API_RATE_LIMIT_PER_MIN'] = '40';
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessionDir, '-d', 'error_log=' . $log, '-S', $address, '-t', dirname(__DIR__)],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env, ['bypass_shell' => true]);
    if (!is_resource($server)) throw new RuntimeException('Test HTTP server failed'); fclose($pipes[0]);
    for ($i = 0; $i < 60; $i++) { $ready = @stream_socket_client('tcp://' . $address, $errno, $errstr, .1); if ($ready) { fclose($ready); break; } usleep(100000); }
    $base = 'http://' . $address; $path = '/api/v1/offerings.php';

    // ---- CLI: client management, audit, refusals ----
    [$code, $out, $err] = cli(['create', '--name=Class Scheduling', '--source=class_scheduling', '--scope=read_write', '--operator=opsuper'], $env);
    ok($code === 0 && $err === '', 'CLI create succeeds: ' . $err); $keyA = keyFrom($out);
    [, $out] = cli(['create', '--name=Enrollment', '--source=enrollment', '--scope=read_write', '--operator=opsuper'], $env); $keyB = keyFrom($out);
    [, $out] = cli(['create', '--name=Reader', '--source=reader_sys', '--scope=read', '--operator=opsuper'], $env); $keyR = keyFrom($out);
    [, $out] = cli(['create', '--name=Writer', '--source=writer_sys', '--scope=write', '--operator=opsuper'], $env); $keyW = keyFrom($out);
    [, $out] = cli(['create', '--name=Burst', '--source=burst_sys', '--scope=read_write', '--operator=opsuper'], $env); $keyBurst = keyFrom($out);
    [, $out] = cli(['create', '--name=Temp', '--source=temp_sys', '--scope=write', '--operator=opsuper'], $env); $keyTemp = keyFrom($out);
    [$c1] = cli(['create', '--name=Nope', '--source=nope', '--scope=write', '--operator=opadmin'], $env); ok($c1 !== 0, 'CLI refuses an operator without api_client.manage (Admin)');
    [$c2] = cli(['create', '--name=Nope', '--source=nope', '--scope=write', '--operator=ghost'], $env); ok($c2 !== 0, 'CLI refuses an unknown operator');
    $noPepper = $env; unset($noPepper['API_KEY_PEPPER']); [$c3, , $e3] = cli(['create', '--name=Nope', '--source=nope', '--scope=write', '--operator=opsuper'], $noPepper);
    ok($c3 !== 0 && str_contains($e3, 'API_KEY_PEPPER'), 'CLI refuses to run without the pepper');
    [$c4, , $e4] = cli(['create', '--name=Bad', '--source=Bad-Source', '--scope=write', '--operator=opsuper'], $env); ok($c4 !== 0 && $e4 !== '', 'CLI validates the source system');
    ok((int)$db->query("SELECT COUNT(*) FROM api_clients WHERE name IN ('Nope','Bad')")->fetch_row()[0] === 0, 'Refused CLI calls create nothing');
    ok((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='API_CLIENT_CREATE' AND user_id=1")->fetch_row()[0] === 6, 'Admin action audited: 6 create rows with the operator as actor');
    $web = call($base, 'GET', '/scripts/api-client-create.php'); ok($web['status'] === 404 || $web['status'] === 403, 'CLI script is not reachable over the web (' . $web['status'] . ')');

    $before = fingerprint($db);
    // ---- Authentication ----
    expect(call($base, 'GET', $path), 401, 'No key', 'unauthorized');
    expect(call($base, 'GET', $path, null, 'not-a-key'), 401, 'Malformed key', 'unauthorized');
    expect(call($base, 'GET', $path, null, 'csms_zzzzzzzz_' . str_repeat('A', 43)), 401, 'Unknown prefix', 'unauthorized');
    $wrong = substr($keyA, 0, 14) . str_repeat('B', 43); expect(call($base, 'GET', $path, null, $wrong), 401, 'Right prefix, wrong secret', 'unauthorized');
    $r = call($base, 'GET', $path, null, 'x', ['Authorization: Basic abc']); expect($r, 401, 'Basic auth refused', 'unauthorized');
    ok(isset($r['json']['request_id']) && strlen($r['json']['request_id']) === 32, 'Errors carry a request id');
    expect(call($base, 'DELETE', $path, null, $keyA), 405, 'DELETE not allowed', 'method_not_allowed');
    $r = call($base, 'GET', $path, null, $keyA, ['Cookie: PHPSESSID=abc']); expect($r, 200, 'Cookies are ignored; key alone authenticates');
    ok(!preg_grep('/^set-cookie:/i', $r['headers']), 'API never sets a cookie');
    // ---- Scopes ----
    expect(call($base, 'POST', $path, ['offerings' => [$item('R1')]], $keyR), 403, 'Read-only key cannot POST', 'forbidden');
    expect(call($base, 'GET', $path, null, $keyW), 403, 'Write-only key cannot GET', 'forbidden');
    // ---- Whole-request validation ----
    expect(call($base, 'POST', $path, '{not json', $keyA), 400, 'Bad JSON', 'bad_request');
    expect(call($base, 'POST', $path, ['offerings' => []], $keyA), 422, 'Empty list', 'invalid_request');
    expect(call($base, 'POST', $path, ['offerings' => 'x'], $keyA), 422, 'Not a list', 'invalid_request');
    expect(call($base, 'POST', $path, ['offerings' => [$item('X')], 'extra' => 1], $keyA), 422, 'Extra top-level key', 'invalid_request');
    expect(call($base, 'POST', $path, ['offerings' => array_map(fn($i) => $item("N$i"), range(1, 101))], $keyA), 413, '101 items', 'too_many_items');
    expect(call($base, 'POST', $path, json_encode(['offerings' => [$item('BIG', ['external_id' => str_repeat('x', 300000)])]]), $keyA), 413, 'Oversize body', 'payload_too_large');
    ok((int)$db->query('SELECT COUNT(*) FROM reported_offerings')->fetch_row()[0] === 0, 'Whole-request failures stored nothing');

    // ---- Happy path, idempotent replay ----
    $batch = ['offerings' => [$item('A1'), $item('A2', ['term' => '2nd Semester', 'section_count' => 2, 'enrolled_count' => null]), $item('A3', ['status' => 'cancelled', 'section_count' => 0, 'reported_at' => '2026-11-10T08:00:00Z'])]];
    $r = expect(call($base, 'POST', $path, $batch, $keyA), 200, 'First batch');
    ok($r['summary'] === ['received' => 3, 'accepted' => 3, 'flagged' => 0, 'rejected' => 0, 'created' => 3, 'updated' => 0, 'unchanged' => 0], 'Summary: 3 created: ' . json_encode($r['summary']));
    ok(array_column($r['results'], 'status') === ['accepted', 'accepted', 'accepted'] && array_column($r['results'], 'action') === ['created', 'created', 'created'], 'Each item reports accepted/created');
    $snapshot = $db->query('SELECT id,payload_hash,received_at,updated_at FROM reported_offerings ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    sleep(1);
    $r = expect(call($base, 'POST', $path, $batch, $keyA), 200, 'Replay');
    ok($r['summary']['unchanged'] === 3 && $r['summary']['created'] === 0 && $r['summary']['updated'] === 0, 'Replay: identical payload changes nothing');
    ok($db->query('SELECT id,payload_hash,received_at,updated_at FROM reported_offerings ORDER BY id')->fetch_all(MYSQLI_ASSOC) === $snapshot, 'Replay leaves rows byte-identical (no updated_at bump)');
    $row = $db->query("SELECT * FROM reported_offerings WHERE external_id='A1'")->fetch_assoc();
    ok($row['subject_id'] == 1 && $row['program_id'] == 1 && $row['subject_code'] === 'S1' && $row['source_system'] === 'class_scheduling' && $row['flags'] === null, 'Resolved ids stored with the codes, source bound to the key');
    $changed = $batch; $changed['offerings'][0]['section_count'] = 5;
    $r = expect(call($base, 'POST', $path, $changed, $keyA), 200, 'Changed payload');
    ok($r['summary']['updated'] === 1 && $r['summary']['unchanged'] === 2, 'Changed item is updated, others unchanged');
    ok((int)$db->query('SELECT COUNT(*) FROM reported_offerings')->fetch_row()[0] === 3, 'Upsert never duplicates (source_system, external_id)');
    $stale = $item('A3', ['status' => 'offered', 'section_count' => 1, 'reported_at' => '2026-11-01T00:00:00Z']);
    $r = expect(call($base, 'POST', $path, ['offerings' => [$stale]], $keyA), 200, 'Stale item');
    ok($r['results'][0]['status'] === 'rejected' && $r['results'][0]['error']['code'] === 'stale', 'Older reported_at is rejected as stale');
    ok($db->query("SELECT status FROM reported_offerings WHERE external_id='A3'")->fetch_row()[0] === 'cancelled', 'Stale data did not overwrite');

    // ---- Per-item partial success, flags, rejections ----
    $mixed = ['offerings' => [
        $item('M1'),                                                        // 0 accepted
        $item('M2', ['subject_code' => 'NOPE999']),                         // 1 unknown subject
        $item('M3', ['academic_year' => '2026-2028']),                      // 2 non-consecutive years
        $item('M4', ['term' => 'Trimester 9']),                             // 3 unknown term
        $item('M5') + ['student_name' => 'Juan Dela Cruz'],                 // 4 personal field
        $item('M6') + ['foo' => 'bar'],                                     // 5 unknown field
        $item('M7') + ['instructor_email' => 'teacher@example.invalid'],    // 6 personal field
        $item('M1'),                                                        // 7 duplicate in request
        $item('M8', ['section_count' => '3']),                              // 8 string number
        $item('M9', ['subject_code' => 'S3']),                              // 9 inactive subject: flagged
        $item('M10', ['subject_code' => 'S2']),                             // 10 not in program curriculum: flagged
        $item('M11', ['program_code' => 'IP']),                             // 11 inactive program + not in curriculum: flagged
        $item('M12', ['program_code' => 'NOPROG']),                         // 12 unknown program
        $item('M13', ['status' => 'closed']),                               // 13 bad status
        'not an object',                                                    // 14 not an item
    ]];
    $r = expect(call($base, 'POST', $path, $mixed, $keyA), 200, 'Mixed batch');
    $by = []; foreach ($r['results'] as $x) $by[$x['index']] = $x;
    ok($by[0]['status'] === 'accepted', 'Good item accepted next to bad ones');
    foreach ([1 => 'unknown_subject', 2 => 'invalid_academic_year', 3 => 'invalid_term', 4 => 'personal_field', 5 => 'unknown_field', 6 => 'personal_field', 7 => 'duplicate_in_request', 8 => 'invalid_number', 12 => 'unknown_program', 13 => 'invalid_status', 14 => 'invalid_item'] as $i => $c)
        ok($by[$i]['status'] === 'rejected' && $by[$i]['error']['code'] === $c, "Item $i rejected as $c, got " . json_encode($by[$i]));
    ok($by[9]['status'] === 'flagged' && in_array('subject_inactive', $by[9]['flags'], true) && $by[9]['action'] === 'created', 'Inactive subject is ACCEPTED with flag subject_inactive (S3 is also unplaced, so both flags)');
    ok($by[10]['status'] === 'flagged' && $by[10]['flags'] === ['not_in_program_curriculum'], 'Known subject not placed in the program is accepted with not_in_program_curriculum');
    ok($by[11]['status'] === 'flagged' && in_array('program_inactive', $by[11]['flags'], true), 'Inactive program is flagged');
    ok($r['summary'] === ['received' => 15, 'accepted' => 1, 'flagged' => 3, 'rejected' => 11, 'created' => 4, 'updated' => 0, 'unchanged' => 0], 'Summary matches per-item results: ' . json_encode($r['summary']));
    ok($db->query("SELECT flags FROM reported_offerings WHERE external_id='M9'")->fetch_row()[0] === 'subject_inactive,not_in_program_curriculum', 'Flags stored with the offering');
    ok((int)$db->query("SELECT COUNT(*) FROM reported_offerings WHERE external_id IN ('M2','M3','M4','M5','M6','M7','M8','M12','M13')")->fetch_row()[0] === 0, 'Rejected items are not stored as offerings');
    $rej = $db->query("SELECT item_index,reason_code,field_name,reported_code,payload_hash,external_id FROM api_rejected_items ORDER BY item_index")->fetch_all(MYSQLI_ASSOC);
    ok(count($rej) === 12, 'Every rejection is logged (11 here plus the earlier stale one): ' . count($rej));
    $unknown = array_values(array_filter($rej, fn($x) => $x['reason_code'] === 'unknown_subject'))[0];
    ok($unknown['reported_code'] === 'NOPE999' && $unknown['field_name'] === 'subject_code', 'Unknown subject code is written to api_rejected_items');
    $personal = array_values(array_filter($rej, fn($x) => $x['reason_code'] === 'personal_field'));
    ok(count($personal) === 2 && $personal[0]['field_name'] === 'student_name' && preg_match('/^[a-f0-9]{64}$/', $personal[0]['payload_hash']), 'Personal field rejected: field name and keyed hash only');
    $dump = json_encode([$db->query('SELECT * FROM api_rejected_items')->fetch_all(MYSQLI_ASSOC), $db->query('SELECT * FROM reported_offerings')->fetch_all(MYSQLI_ASSOC)]);
    ok(!str_contains($dump, 'Juan Dela Cruz') && !str_contains($dump, 'teacher@example.invalid'), 'Rejected personal values are stored nowhere');
    ok(!str_contains((string)file_get_contents($log), 'Juan Dela Cruz'), 'Rejected personal values are not in the server log');
    ok((int)$db->query("SELECT COUNT(*) FROM subjects")->fetch_row()[0] === 3, 'Unknown subject codes never create subjects');

    // ---- Own rows only, paging, filters ----
    $r = expect(call($base, 'POST', $path, ['offerings' => [$item('A1', ['subject_code' => 'S2', 'academic_year' => '2025-2026'])]], $keyB), 200, 'Enrollment reports the same external_id');
    ok($r['summary']['created'] === 1, 'Same external_id from another source is a different row');
    $a = expect(call($base, 'GET', $path . '?per_page=2&page=1', null, $keyA), 200, 'A lists own rows');
    ok($a['total'] === 7 && count($a['data']) === 2 && $a['per_page'] === 2 && $a['page'] === 1, 'Paging: ' . json_encode([$a['total'], count($a['data'])]));
    ok($a['data'][0]['academic_year'] === '2026-2027' && isset($a['data'][0]['received_at'], $a['data'][0]['flags']), 'Rows carry flags and received_at');
    $b = expect(call($base, 'GET', $path, null, $keyB), 200, 'B lists own rows');
    ok($b['total'] === 1 && $b['data'][0]['subject_code'] === 'S2', 'B sees only its own row, never A\'s');
    $f = expect(call($base, 'GET', $path . '?status=cancelled', null, $keyA), 200, 'Filter'); ok($f['total'] === 1 && $f['data'][0]['external_id'] === 'A3', 'Filter by status');
    $f = expect(call($base, 'GET', $path . '?term=2nd%20Semester&subject_code=S1', null, $keyA), 200, 'Filter'); ok($f['total'] === 1, 'Filter by term and subject');
    expect(call($base, 'GET', $path . '?per_page=500', null, $keyA), 422, 'per_page too large', 'invalid_query');
    expect(call($base, 'GET', $path . '?term=Winter', null, $keyA), 422, 'Bad term filter', 'invalid_query');
    expect(call($base, 'GET', $path . '?page=0', null, $keyA), 422, 'Bad page', 'invalid_query');
    ok(!str_contains(json_encode($b), 'A2'), 'No leakage of another client rows in B\'s response');

    // ---- Rotate and revoke (audited) ----
    [$c, $out] = cli(['rotate', '--name=Temp', '--operator=opsuper'], $env); $newTemp = keyFrom($out);
    expect(call($base, 'POST', $path, ['offerings' => [$item('T1')]], $keyTemp), 401, 'Rotated: old key stops working', 'unauthorized');
    expect(call($base, 'POST', $path, ['offerings' => [$item('T1')]], $newTemp), 200, 'Rotated: new key works');
    [$c] = cli(['revoke', '--name=Temp', '--operator=opsuper'], $env); ok($c === 0, 'CLI revoke');
    expect(call($base, 'POST', $path, ['offerings' => [$item('T1')]], $newTemp), 401, 'Revoked key refused', 'unauthorized');
    [$c] = cli(['rotate', '--name=Temp', '--operator=opsuper'], $env); ok($c !== 0, 'A revoked client cannot be rotated');
    foreach (['API_CLIENT_ROTATE' => 1, 'API_CLIENT_REVOKE' => 1] as $action => $n) ok((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='$action' AND user_id=1")->fetch_row()[0] === $n, "Audit row $action written");

    // ---- Rate limit ----
    $statuses = []; for ($i = 0; $i < 43; $i++) { $x = call($base, 'GET', $path, null, $keyBurst); $statuses[] = $x['status']; if ($x['status'] === 429) $last429 = $x; }
    ok(count(array_filter($statuses, fn($s) => $s === 200)) === 40 && in_array(429, $statuses, true), 'Rate limit: 40 allowed, then 429 (' . implode(',', array_slice($statuses, 38, 5)) . ')');
    $retry = array_values(array_filter($last429['headers'], fn($h) => stripos($h, 'retry-after:') === 0));
    ok($retry !== [] && (int)trim(substr($retry[0], 12)) >= 1, 'Rate limit sends Retry-After');
    expect($last429, 429, 'Rate limited', 'rate_limited');
    expect(call($base, 'GET', $path, null, $keyA), 200, 'Another client is not affected by the burst client');

    // ---- No writes to curriculum data; key handling ----
    ok(fingerprint($db) === $before, 'No curriculum, subject, program, version or placement table changed by any API call');
    $secretOnly = fn(string $key) => substr($key, 14);
    $everything = json_encode([$db->query('SELECT * FROM api_clients')->fetch_all(MYSQLI_ASSOC), $db->query('SELECT * FROM api_rejected_items')->fetch_all(MYSQLI_ASSOC),
        $db->query('SELECT * FROM reported_offerings')->fetch_all(MYSQLI_ASSOC), $db->query('SELECT * FROM audit_logs')->fetch_all(MYSQLI_ASSOC)]);
    $logText = file_get_contents($log);
    foreach ([$keyA, $keyB, $keyR, $keyW, $keyBurst, $keyTemp, $newTemp] as $k) {
        ok(!str_contains($everything, $secretOnly($k)) && !str_contains($logText, $secretOnly($k)), 'Plain key secret appears in no table and no log');
    }
    ok(!str_contains($everything, 'csms_') || !preg_match('/csms_[a-z0-9]{8}_[A-Za-z0-9_-]{43}/', $everything), 'No full key anywhere in the database');
    ok(!str_contains($everything, $pepper), 'The pepper is not stored');
    $stored = $db->query("SELECT key_prefix,key_hash FROM api_clients WHERE name='Class Scheduling'")->fetch_assoc();
    ok($stored['key_prefix'] === substr($keyA, 5, 8) && $stored['key_hash'] === hash_hmac('sha256', $secretOnly($keyA), $pepper) && $stored['key_hash'] !== hash('sha256', $secretOnly($keyA)), 'Only the HMAC-SHA256 hash (with the pepper) is stored');
    $meta = $db->query("SELECT metadata_json FROM audit_logs WHERE action='API_CLIENT_CREATE' LIMIT 1")->fetch_row()[0];
    ok(str_contains($meta, 'key_prefix') && !str_contains($meta, 'key_hash'), 'Audit metadata holds the prefix, never the key or hash');

    // The demo source name uses a hyphen ("demo-" marks demo data in the UI); the key still binds the source.
    [$cd, $outD] = cli(['create', '--name=Demo Class Scheduling', '--source=demo-class-scheduling', '--scope=write', '--operator=opsuper'], $env);
    ok($cd === 0 && (string)$db->query("SELECT source_system FROM api_clients WHERE name='Demo Class Scheduling'")->fetch_row()[0] === 'demo-class-scheduling', 'CLI accepts a hyphenated demo source system');
    [$cu] = cli(['create', '--name=Bad2', '--source=-bad', '--scope=write', '--operator=opsuper'], $env); [$cv] = cli(['create', '--name=Bad3', '--source=Demo-X', '--scope=write', '--operator=opsuper'], $env);
    ok($cu !== 0 && $cv !== 0, 'CLI still refuses a leading hyphen and uppercase in the source system');

    // ---- Rollback and re-apply on the disposable schema ----
    $rollback = file_get_contents(__DIR__ . '/../database/rollbacks/029_rollback.sql');
    $db->multi_query($rollback); do { if ($res = $db->store_result()) $res->free(); } while ($db->more_results() && $db->next_result());
    ok($db->query("SHOW TABLES LIKE 'api_clients'")->num_rows === 0 && $db->query("SHOW TABLES LIKE 'reported_offerings'")->num_rows === 0 && $db->query("SHOW TABLES LIKE 'api_rejected_items'")->num_rows === 0, 'Rollback drops the three tables');
    ok((int)$db->query("SELECT COUNT(*) FROM permissions WHERE name='api_client.manage'")->fetch_row()[0] === 0 && (int)$db->query("SELECT COUNT(*) FROM subjects")->fetch_row()[0] === 3, 'Rollback removes the permission and leaves curriculum data alone');
    $migration = preg_replace('/^USE sms_db;\s*$/m', '', str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../database/migrations/029_offering_api.sql')));
    $db->multi_query($migration); do { if ($res = $db->store_result()) $res->free(); } while ($db->more_results() && $db->next_result());
    ok($db->query("SHOW TABLES LIKE 'reported_offerings'")->num_rows === 1 && (int)$db->query("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id JOIN roles r ON r.id=rp.role_id WHERE p.name='api_client.manage' AND r.name='super_admin'")->fetch_row()[0] === 1, 'Migration re-applies cleanly after rollback');
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($created && preg_match('/^csms_offapi_test_[a-f0-9]{16}$/D', $name)) $db->query("DROP DATABASE `$name`");
    $db->close();
    $logText = file_get_contents($log); unlink($log); foreach (glob($sessionDir . '/sess_*') as $file) unlink($file); rmdir($sessionDir);
}
ok(!preg_match('/PHP (Warning|Fatal|Notice|Parse error|Deprecated)/', $logText), 'No PHP errors in the server log');
echo "PASS: $checks Offering API checks; disposable server and schema removed.\n";
