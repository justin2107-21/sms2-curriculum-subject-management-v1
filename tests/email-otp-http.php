<?php
// Email OTP through real HTTP and a real SMTP conversation. CLI only. Run on a CLONE that has migration 033 (never sms_db):
//   DB_NAME=<clone> php tests/email-otp-http.php
// It starts three PHP servers (OTP off, OTP on with a local SMTP sink, OTP on with no mail server), creates a few disposable
// accounts with random passwords, and removes them at the end. The sink keeps mail in a temp file; codes are never printed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
$config = csms_database_config();
if ($config['database'] === 'sms_db' || !in_array($config['host'], ['localhost', '127.0.0.1'], true)) { fwrite(STDERR, "Refusing: run on a local clone, not sms_db.\n"); exit(1); }
$db = csms_database_connect($config);
if (!$db->query("SHOW TABLES LIKE 'login_otp_challenges'")->num_rows) { fwrite(STDERR, "Apply migration 033 to this clone first (database/deploy.php).\n"); exit(1); }
$checks = 0; $failed = null;
function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException('FAILED: ' . $label); $checks++; }
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'otp_http_' . bin2hex(random_bytes(4));
mkdir($tmp);
$procs = []; $userIds = [];

function startPhp(array $args, array $env, string $log): array
{
    $p = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env + getenv());
    return [$p, $pipes];
}
function waitPort(int $port): void { for ($i = 0; $i < 60; $i++) { $c = @stream_socket_client("tcp://127.0.0.1:$port", $e, $s, .2); if ($c) { fclose($c); return; } usleep(100000); } throw new RuntimeException("port $port did not open"); }

// Minimal SMTP sink: accepts any mail, appends "=====\r\n<message>" to a file.
file_put_contents($tmp . '/sink.php', <<<'SINK'
<?php
$port = (int)$argv[1]; $out = $argv[2];
$srv = stream_socket_server("tcp://127.0.0.1:$port", $errno, $err);
while ($c = @stream_socket_accept($srv, 600)) {
    fwrite($c, "220 sink ready\r\n"); $data = false; $msg = '';
    while (($line = fgets($c)) !== false) {
        if ($data) { if ($line === ".\r\n") { $data = false; file_put_contents($out, "=====\r\n" . $msg, FILE_APPEND); $msg = ''; fwrite($c, "250 queued\r\n"); } else $msg .= $line; continue; }
        $cmd = strtoupper(substr($line, 0, 4));
        if ($cmd === 'EHLO' || $cmd === 'HELO') fwrite($c, "250 sink\r\n");
        elseif ($cmd === 'DATA') { $data = true; fwrite($c, "354 go\r\n"); }
        elseif ($cmd === 'QUIT') { fwrite($c, "221 bye\r\n"); break; }
        else fwrite($c, "250 ok\r\n");
    }
    fclose($c);
}
SINK);

$base = ['APP_ENV' => 'development', 'SMS_SESSION_COOKIE_SECURE' => '0', 'CIMF_AI_ENABLED' => 'false', 'DB_NAME' => $config['database']];
$sinkPort = 8561; $offPort = 8562; $onPort = 8563; $brokenPort = 8564; $securePort = 8565; $mailFile = $tmp . '/mail.txt';
$procs[] = startPhp([$tmp . '/sink.php', (string)$sinkPort, $mailFile], [], $tmp . '/sink.log')[0];
waitPort($sinkPort);
$smtp = ['SMTP_HOST' => '127.0.0.1', 'SMTP_PORT' => (string)$sinkPort, 'SMTP_FROM' => 'csms@example.test', 'SMTP_FROM_NAME' => 'CSMS Test'];
$procs[] = startPhp(['-S', "127.0.0.1:$offPort", '-t', dirname(__DIR__)], $base + ['OTP_ENABLED' => '0'] + $smtp, $tmp . '/off.log')[0];
$procs[] = startPhp(['-S', "127.0.0.1:$onPort", '-t', dirname(__DIR__)], $base + ['OTP_ENABLED' => '1'] + $smtp, $tmp . '/on.log')[0];
$procs[] = startPhp(['-S', "127.0.0.1:$brokenPort", '-t', dirname(__DIR__)], $base + ['OTP_ENABLED' => '1', 'SMTP_HOST' => '127.0.0.1', 'SMTP_PORT' => '9', 'SMTP_FROM' => 'csms@example.test'], $tmp . '/broken.log')[0];
// Behind an HTTPS proxy the container sees plain HTTP; the image forces Secure cookies with SMS_SESSION_COOKIE_SECURE=1.
$procs[] = startPhp(['-S', "127.0.0.1:$securePort", '-t', dirname(__DIR__)], ['SMS_SESSION_COOKIE_SECURE' => '1'] + $base + ['OTP_ENABLED' => '1'] + $smtp, $tmp . '/secure.log')[0];
foreach ([$offPort, $onPort, $brokenPort, $securePort] as $p) waitPort($p);

final class Client {
    private array $cookies = []; public string $csrf = ''; public array $setCookie = [];
    public function __construct(public int $port) {}
    public function req(string $path, ?array $post = null): array {
        $headers = []; if ($this->cookies) $headers[] = 'Cookie: ' . http_build_query($this->cookies, '', '; ');
        $opts = ['ignore_errors' => true, 'follow_location' => 0, 'method' => $post === null ? 'GET' : 'POST', 'timeout' => 40];
        if ($post !== null) { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; $opts['content'] = http_build_query($post); }
        $opts['header'] = implode("\r\n", $headers);
        $body = (string)@file_get_contents("http://127.0.0.1:{$this->port}/$path", false, stream_context_create(['http' => $opts]));
        $status = 0; $location = ''; $this->setCookie = [];
        foreach ($http_response_header ?? [] as $h) {
            if (stripos($h, 'Set-Cookie:') === 0) $this->setCookie[] = $h;
            if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) $status = (int)$m[1];
            if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $h, $m)) $this->cookies[$m[1]] = urldecode($m[2]);
            if (preg_match('/^Location: (.*)$/i', $h, $m)) $location = trim($m[1]);
        }
        if (preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m)) $this->csrf = html_entity_decode($m[1]);
        return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true), 'location' => $location];
    }
    public function auth(string $action, array $data = []): array { return $this->req('shared/auth_actions.php', ['action' => $action, '_csrf_token' => $this->csrf] + $data); }
    public function fresh(): void { $this->req('auth/signin.php'); }
    /** Signed in means the sign-in page sends us on to the dashboard. */
    public function signedIn(): bool { $r = $this->req('auth/signin.php'); return $r['status'] === 302 && str_contains($r['location'], 'dashboard'); }
}
$mails = static function () use ($mailFile): array { return is_file($mailFile) ? array_values(array_filter(explode("=====\r\n", (string)file_get_contents($mailFile)))) : []; };
$lastCode = static function () use ($mails): ?string { $all = $mails(); $m = end($all) ?: ''; $m = quoted_printable_decode(preg_replace('/\r\n(?=[ \t])/', ' ', $m)); return preg_match('/sign-in code is: ([0-9]{6})/', $m, $x) ? $x[1] : null; };

$makeUser = function (string $label, string $email, string $password) use ($db, &$userIds): array {
    $username = 'otp_' . $label . '_' . bin2hex(random_bytes(3));
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (username, email, first_name, last_name, password_hash, is_active, must_change_password) VALUES (?, ?, 'Otp', 'Test', ?, 1, 0)");
    $stmt->bind_param('sss', $username, $email, $hash); $stmt->execute(); $id = (int)$db->insert_id; $stmt->close();
    $userIds[] = $id;
    return ['id' => $id, 'username' => $username, 'password' => $password];
};
$pw = static fn() => bin2hex(random_bytes(9)) . 'Aa1!';
try {
    $withMail = $makeUser('mail', 'otp_' . bin2hex(random_bytes(3)) . '@example.test', $pw());
    $noMail = $makeUser('nomail', 'no-email-' . bin2hex(random_bytes(3)), $pw());
    $login = static fn(Client $c, array $u) => $c->auth('login', ['username' => $u['username'], 'password' => $u['password']]);

    // ---- OTP off: exactly as before --------------------------------------------------------------------------------------------
    $c = new Client($offPort); $c->fresh();
    $r = $login($c, $withMail);
    check($r['status'] === 200 && ($r['json']['success'] ?? false) === true && !isset($r['json']['otp_required']) && $c->signedIn(), 'OTP_ENABLED=0: password alone signs in, no code');
    check(count($mails()) === 0, 'OTP off: no email is sent');
    $c = new Client($offPort); $c->fresh();
    check(($login($c, ['username' => $withMail['username'], 'password' => 'wrong-password'])['status'] ?? 0) === 401, 'OTP off: a wrong password is still refused');

    // ---- OTP on, user without a valid email: password only ---------------------------------------------------------------------
    $c = new Client($onPort); $c->fresh();
    $r = $login($c, $noMail);
    check(($r['json']['success'] ?? false) === true && !isset($r['json']['otp_required']) && $c->signedIn() && count($mails()) === 0, 'OTP on, no valid email: password only, no email sent');

    // ---- OTP on, user with email -----------------------------------------------------------------------------------------------
    $c = new Client($onPort); $c->fresh();
    check(($login($c, ['username' => $withMail['username'], 'password' => 'wrong-password'])['status'] ?? 0) === 401 && count($mails()) === 0, 'OTP on: a wrong password sends no code');
    $r = $login($c, $withMail);
    check($r['status'] === 200 && ($r['json']['otp_required'] ?? false) === true && ($r['json']['expires_in'] ?? 0) > 290 && ($r['json']['resend_in'] ?? 0) > 50 && !str_contains((string)($r['json']['email_hint'] ?? ''), 'otp_'), 'password right: the code step is requested, with the 5-minute and 60-second timers and a masked hint');
    check(!$c->signedIn() && $c->req('dashboard/dashboard.php')['status'] === 302, 'before the code there is no signed-in session (dashboard redirects to sign-in)');
    check(count($mails()) === 1, 'one email was sent');
    $code = $lastCode(); check($code !== null && preg_match('/^[0-9]{6}$/', $code) === 1, 'the email carries a 6-digit code');
    check(str_contains(implode('', $mails()), 'text/plain') && str_contains(implode('', $mails()), 'text/html'), 'the email has an HTML part and a plain-text part');
    $page = $c->req('auth/signin.php');
    check($page['status'] === 200 && str_contains($page['body'], 'id="stepOtp"') && !preg_match('/id="stepOtp"[^>]*hidden/', $page['body']), 'a refresh keeps the code screen');
    $wrong = $code === '000000' ? '111111' : '000000';
    $r = $c->auth('otp_verify', ['code' => $wrong]);
    check($r['status'] === 401 && ($r['json']['attempts_left'] ?? 0) === 4 && !$c->signedIn(), 'wrong code: refused, 4 tries left, still not signed in');
    check($c->auth('otp_verify', ['code' => 'abc'])['status'] === 422, 'a badly formed code is refused');
    $r = $c->auth('otp_resend');
    check($r['status'] === 429 && count($mails()) === 1, 'resend inside 60 seconds is blocked and no second email goes out');
    $r = $c->auth('otp_verify', ['code' => $code]);
    check($r['status'] === 200 && ($r['json']['success'] ?? false) === true && $c->signedIn(), 'the right code completes sign-in');
    $c2 = new Client($onPort); $c2->fresh(); $login($c2, $withMail);
    $r = $c2->auth('otp_verify', ['code' => $code]);
    check($r['status'] !== 200 && !$c2->signedIn(), 'a used code cannot sign anyone in again');

    // ---- Back to sign in ---------------------------------------------------------------------------------------------------------
    $u2 = $makeUser('back', 'otp_' . bin2hex(random_bytes(3)) . '@example.test', $pw());
    $c = new Client($onPort); $c->fresh(); $login($c, $u2); $code2 = $lastCode();
    check($c->auth('otp_cancel')['status'] === 200, 'Back to sign in ends the code step');
    $r = $c->auth('otp_verify', ['code' => (string)$code2]);
    check($r['status'] === 401 && !$c->signedIn(), 'after Back to sign in, even the right code does nothing');

    // ---- Attempt limit -----------------------------------------------------------------------------------------------------------
    $u3 = $makeUser('limit', 'otp_' . bin2hex(random_bytes(3)) . '@example.test', $pw());
    $c = new Client($onPort); $c->fresh(); $login($c, $u3); $code3 = $lastCode(); $bad = $code3 === '123456' ? '654321' : '123456';
    for ($i = 0; $i < 4; $i++) $r = $c->auth('otp_verify', ['code' => $bad]);
    check($r['status'] === 401 && ($r['json']['attempts_left'] ?? 0) === 1, 'four wrong codes leave one try');
    $r = $c->auth('otp_verify', ['code' => $bad]);
    check($r['status'] === 429 && !$c->signedIn(), 'the fifth wrong code locks it');
    check($c->auth('otp_verify', ['code' => (string)$code3])['status'] === 429 && !$c->signedIn(), 'a locked code is refused even when right');

    // ---- Expiry ------------------------------------------------------------------------------------------------------------------
    $u4 = $makeUser('expire', 'otp_' . bin2hex(random_bytes(3)) . '@example.test', $pw());
    $c = new Client($onPort); $c->fresh(); $login($c, $u4); $code4 = $lastCode();
    $db->query('UPDATE login_otp_challenges SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE user_id = ' . $u4['id']);
    $r = $c->auth('otp_verify', ['code' => (string)$code4]);
    check($r['status'] === 410 && !$c->signedIn(), 'an expired code is refused');

    // ---- Send failure: no sign-in, audit entry, no code row ------------------------------------------------------------------------
    $u5 = $makeUser('broken', 'otp_' . bin2hex(random_bytes(3)) . '@example.test', $pw());
    $c = new Client($brokenPort); $c->fresh();
    $r = $login($c, $u5);
    check($r['status'] === 503 && ($r['json']['success'] ?? true) === false && str_contains((string)$r['json']['message'], 'could not send') && !$c->signedIn(), 'mail server down: a clear error and the user is NOT signed in');
    check((int)$db->query('SELECT COUNT(*) FROM login_otp_challenges WHERE user_id = ' . $u5['id'])->fetch_row()[0] === 0, 'the unsent code is not kept');

    // ---- Behind an HTTPS proxy: Secure session cookie, relative redirects, CSRF and form posts still work -------------------------
    $u6 = $makeUser('proxy', 'otp_' . bin2hex(random_bytes(3)) . '@example.test', $pw());
    $c = new Client($securePort); $c->fresh();
    $r = $c->auth('login', ['username' => $u6['username'], 'password' => $u6['password']]);
    $cookieLines = implode(' | ', $c->setCookie);
    check(($r['json']['otp_required'] ?? false) === true && stripos($cookieLines, 'PHPSESSID') !== false && stripos($cookieLines, '; secure') !== false && stripos($cookieLines, 'httponly') !== false && stripos($cookieLines, 'samesite=lax') !== false, 'with SMS_SESSION_COOKIE_SECURE=1 the session cookie is Secure, HttpOnly and SameSite=Lax');
    $proxyCode = $lastCode();
    $r = $c->auth('otp_verify', ['code' => (string)$proxyCode]);
    check(($r['json']['success'] ?? false) === true && !preg_match('~^https?:|localhost~i', (string)($r['json']['redirect'] ?? 'x')) && $c->signedIn(), 'the whole code flow works behind the proxy setup, and the redirect is relative (no host or scheme)');
    $mailText = quoted_printable_decode(implode('', $mails()));
    check(!preg_match('~https?://|localhost~i', $mailText), 'no emailed message holds a URL or a localhost address');

    // ---- Emergency switch: OTP_ENABLED=0 after a restart drops a waiting code screen, and the OTP table is not touched ------------------
    $u7 = $makeUser('switch', 'otp_' . bin2hex(random_bytes(3)) . '@example.test', $pw());
    $c = new Client($onPort); $c->fresh(); $login($c, $u7); $switchCode = $lastCode();
    $c->port = $offPort;
    $r = $c->auth('otp_verify', ['code' => (string)$switchCode]);
    check($r['status'] === 401 && ($r['json']['restart'] ?? false) === true && !$c->signedIn(), 'OTP switched off: a pending code screen is refused and sign-in starts again');
    check((int)$db->query('SELECT attempts FROM login_otp_challenges WHERE user_id = ' . $u7['id'])->fetch_row()[0] === 0, 'OTP switched off: the OTP table was not read or written');
    $r = $login($c, $u7);
    check(($r['json']['success'] ?? false) === true && !isset($r['json']['otp_required']) && $c->signedIn(), 'OTP switched off: the same account signs in with the password only');

    // ---- Audit log ----------------------------------------------------------------------------------------------------------------
    $ids = implode(',', $userIds);
    $count = static fn(string $action) => (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action = '$action' AND entity_id IN ($ids)")->fetch_row()[0];
    foreach (['AUTH_OTP_SENT', 'AUTH_OTP_VERIFIED', 'AUTH_OTP_FAILED', 'AUTH_OTP_EXPIRED', 'AUTH_OTP_RESEND_BLOCKED', 'AUTH_OTP_SEND_FAILED'] as $action) check($count($action) >= 1, "audit entry written: $action");
    $text = ''; $res = $db->query("SELECT description, COALESCE(metadata_json, '') AS m FROM audit_logs WHERE action LIKE 'AUTH_OTP%' AND entity_id IN ($ids)");
    while ($row = $res->fetch_assoc()) $text .= $row['description'] . ' ' . $row['m'] . ' ';
    $hashes = $db->query("SELECT code_hash FROM login_otp_challenges WHERE user_id IN ($ids)")->fetch_all(MYSQLI_NUM);
    foreach ($hashes as [$hash]) check($hash !== '' && !str_contains($text, substr($hash, 0, 16)) && !str_contains($text, substr($hash, -16)), 'no code hash appears in the audit log');
    check(!preg_match('/password|passwd|pass_word/i', preg_replace('/AUTH_LOGIN[A-Z_]*|invalid_credentials|missing_credentials/', '', $text)), 'no password text appears in the OTP audit entries');
    foreach ([$code, $code2, $code3, $code4, $proxyCode, $switchCode] as $secret) check(!str_contains($text, (string)$secret), 'no sign-in code appears in the audit log');
    check(!preg_match('/otp_[0-9a-f]{6}@/', $text), 'no email address appears in the audit log');
} catch (Throwable $e) { $failed = $e; }
finally {
    foreach ($procs as $p) if (is_resource($p)) proc_terminate($p);
    if ($userIds) {
        $ids = implode(',', $userIds);
        try { $db->query("DELETE FROM users WHERE id IN ($ids)"); } catch (Throwable $cleanup) { fwrite(STDERR, "Cleanup of the test accounts failed.
"); }
    }
    foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp);
}
if ($failed) { fwrite(STDERR, $failed->getMessage() . "\n"); exit(1); }
echo "PASS: $checks Email OTP HTTP checks (flag off, no-email user, code step, wrong code, attempt limit, expiry, resend block, send failure, audit)\n";
