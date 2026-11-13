<?php
// Email OTP regression (CLI only). Uses connection-local TEMPORARY tables built from the migration file, so it needs no migration
// and writes nothing permanent. No mail is sent: OtpMailer uses an in-memory transport. The clock is injected, so nothing waits.
//   php tests/email-otp.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/../shared/EmailOtp.php';
require_once __DIR__ . '/../shared/OtpMailer.php';

$config = csms_database_config();
if (!in_array($config['host'], ['localhost', '127.0.0.1'], true) || getenv('APP_ENV') === 'production') throw new RuntimeException('Local development only.');
$db = csms_database_connect($config);
$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException('FAILED: ' . $label); $checks++; }

// ---- The migration file ----------------------------------------------------------------------------------------------------------
$sql = (string)file_get_contents(__DIR__ . '/../database/migrations/033_login_otp_challenges.sql');
check(substr_count($sql, 'USE sms_db;') === 1 && !preg_match('/^\s*(?:DROP|TRUNCATE|DELETE)\s/mi', $sql), 'migration passes the deploy guard (one USE, no DROP or DELETE statements)');
foreach (['user_id', 'code_hash', 'expires_at', 'attempts', 'used_at', 'created_at'] as $column) check(str_contains($sql, "  $column "), "migration has column $column");
check(!preg_match('/\bcode\s+(CHAR|VARCHAR)/i', $sql) && str_contains($sql, 'CREATE TABLE IF NOT EXISTS login_otp_challenges'), 'the plain code is not a column; the CREATE is guarded');
check(is_file(__DIR__ . '/../database/rollbacks/033_rollback.sql'), 'rollback file exists outside migrations');
preg_match('/CREATE TABLE IF NOT EXISTS login_otp_challenges \(.*?\) ENGINE=InnoDB[^;]*;/s', $sql, $m);
$create = preg_replace('/,\s*CONSTRAINT fk_login_otp_user[^\n]*/', '', str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $m[0]));
$db->query('DROP TEMPORARY TABLE IF EXISTS login_otp_challenges');
$db->query($create);

$t = 1_800_000_000;
$clock = function () use (&$t): int { return $t; };
$otp = new EmailOtpService($db, $clock);
$rows = static fn(int $user) => $db->query("SELECT * FROM login_otp_challenges WHERE user_id=$user ORDER BY id")->fetch_all(MYSQLI_ASSOC);

// ---- Flag off, no-email user -----------------------------------------------------------------------------------------------------
putenv('OTP_ENABLED');
check(!EmailOtpService::enabled(), 'OTP is off when OTP_ENABLED is not set');
foreach (['0', '', 'no', 'off', 'false'] as $value) { putenv('OTP_ENABLED=' . $value); check(!EmailOtpService::enabled(), "OTP is off for OTP_ENABLED='$value'"); }
check(!EmailOtpService::requiredFor(['email' => 'person@example.test']), 'flag off: a user with a valid email signs in with the password only');
foreach (['1', 'true', 'YES', 'on'] as $value) { putenv('OTP_ENABLED=' . $value); check(EmailOtpService::enabled(), "OTP is on for OTP_ENABLED='$value'"); }
putenv('OTP_ENABLED=1');
check(EmailOtpService::requiredFor(['email' => 'person@example.test']), 'flag on and a valid email: a code is required');
foreach ([null, '', '   ', 'not-an-email', 'a@b', '@example.test', "x@example.test\r\nBcc: y@example.test"] as $bad) check(!EmailOtpService::requiredFor(['email' => $bad]), 'flag on but no valid email: password only (' . json_encode($bad) . ')');
check(!EmailOtpService::requiredFor([]), 'flag on and no email field at all: password only');
check(EmailOtpService::maskEmail('person@example.test') === 'p*****@example.test' && !str_contains(EmailOtpService::maskEmail('person@example.test'), 'erson'), 'the email hint hides the name');

// ---- Issue -----------------------------------------------------------------------------------------------------------------------
$r = $otp->issue(7);
check($r['status'] === 'ok' && preg_match('/^[0-9]{6}$/', $r['code']), 'issue gives a 6-digit code');
check($r['expires_at'] === $t + 300 && $r['resend_at'] === $t + 60, 'valid exactly 5 minutes, resend after 60 seconds');
$row = $rows(7)[0];
check(!str_contains($row['code_hash'], $r['code']) && strlen($row['code_hash']) <= 128 && $row['code_hash'] !== $r['code'], 'only a hash is stored, not the code');
check($row['used_at'] === null && (int)$row['attempts'] === 0 && $row['expires_at'] === gmdate('Y-m-d H:i:s', $t + 300), 'new row: unused, 0 attempts, expires at +300 s (UTC)');
check(str_contains((string)file_get_contents(__DIR__ . '/../shared/EmailOtp.php'), 'random_int(0, 999999)'), 'the code comes from random_int');

// ---- Wrong code, attempt limit, reuse --------------------------------------------------------------------------------------------
$good = $r['code']; $wrong = $good === '000000' ? '111111' : '000000';
check($otp->verify(7, 'abc')['status'] === 'invalid_format' && $otp->verify(7, '12345')['status'] === 'invalid_format' && (int)$rows(7)[0]['attempts'] === 0, 'a badly formed code is refused and costs no attempt');
$w = $otp->verify(7, $wrong);
check($w['status'] === 'wrong' && $w['attempts_left'] === 4 && (int)$rows(7)[0]['attempts'] === 1, 'wrong code: 4 tries left');
for ($i = 0; $i < 3; $i++) $w = $otp->verify(7, $wrong);
check($w['status'] === 'wrong' && $w['attempts_left'] === 1, 'after 4 wrong codes, 1 try is left');
check($otp->verify(7, $wrong)['status'] === 'locked', 'the 5th wrong code locks it');
check($otp->verify(7, $good)['status'] === 'locked' && $rows(7)[0]['used_at'] === null, 'a locked code no longer works, even the right one');

$t += 61;
$r2 = $otp->issue(7);
check($r2['status'] === 'ok' && count($rows(7)) === 2, 'a new code can be requested after the cooldown');
check($rows(7)[0]['used_at'] !== null, 'the older code is invalidated by the new one');
$old = $good === $r2['code'] ? $wrong : $good;
check($otp->verify(7, $old)['status'] === 'wrong', 'the older code does not work');
check($otp->verify(7, $r2['code'])['status'] === 'ok', 'the newest code works once');
check($otp->verify(7, $r2['code'])['status'] === 'used', 'single use: the same code is refused the second time');
check($rows(7)[1]['used_at'] !== null, 'used_at is set after success');

// ---- Expiry ----------------------------------------------------------------------------------------------------------------------
$t += 61;
$e = $otp->issue(11); $start = $t;
$t = $start + 299; check($otp->verify(11, $e['code'])['status'] === 'ok', 'a code still works at 4 minutes 59 seconds');
$t += 61; $e2 = $otp->issue(12); $start2 = $t;
$t = $start2 + 300; check($otp->verify(12, $e2['code'])['status'] === 'expired', 'a code expires at exactly 5 minutes');
check($otp->verify(12, $e2['code'])['status'] === 'used', 'an expired code is closed and cannot be retried');
check($otp->verify(8, '123456')['status'] === 'none', 'a user with no code gets "none"');

// ---- Resend cooldown and limit ---------------------------------------------------------------------------------------------------
$t = 1_900_000_000; $u = 9;
$a = $otp->issue($u); check($a['status'] === 'ok', 'resend: first send');
$t += 30; $c = $otp->issue($u);
check($c['status'] === 'cooldown' && $c['retry_after'] === 30 && $c['valid_until'] === 1_900_000_300 && count($rows($u)) === 1, 'resend after 30 s is blocked by the 60-second cooldown (the first code still works)');
$t += 30; $b = $otp->issue($u); check($b['status'] === 'ok', 'resend after 60 s is allowed');
$t += 60; $d = $otp->issue($u); check($d['status'] === 'ok' && count($rows($u)) === 3, 'third send in 15 minutes is allowed');
$t += 60; $f = $otp->issue($u);
check($f['status'] === 'limit' && $f['retry_after'] === 900 - 180 && count($rows($u)) === 3, 'a 4th send in 15 minutes is blocked, with the wait time');
$t = 1_900_000_000 + 901; $g = $otp->issue($u);
check($g['status'] === 'ok', 'after the first send is older than 15 minutes, sending works again');
$o = $otp->issue(10); $t += 61; $otp->discard($o['challenge_id']);
check(count($rows(10)) === 0, 'a code that could not be emailed is discarded (it does not count toward the limit)');

// ---- Mail template and transport -------------------------------------------------------------------------------------------------
$html = OtpMailer::htmlBody('Ana <b>Cruz</b>', '482913', 5); $text = OtpMailer::textBody('Ana Cruz', '482913', 5);
check(str_contains($html, '482913') && str_contains($html, '#2163c5') && str_contains($html, 'valid for <strong>5 minutes</strong>') && str_contains($html, 'ignore this email'), 'HTML mail: code, brand color, 5 minutes, ignore note');
check(str_contains($html, '<table') && !preg_match('/<img|<link|<script|src=|url\(/i', $html) && !str_contains($html, '<b>Cruz</b>'), 'HTML mail: table layout, no remote images or scripts, name escaped');
check(str_contains($html, 'Georgia') && str_contains($html, 'Arial') && !str_contains($html, 'DM Sans'), 'HTML mail uses web-safe fonts');
check(str_contains($text, '482913') && str_contains($text, '5 minutes') && str_contains($text, 'ignore this email') && !preg_match('/<[a-z]/i', $text), 'plain-text alternative');
$sent = null; OtpMailer::useTransport(function ($to, $subject, $h, $tx) use (&$sent) { $sent = [$to, $subject, $h, $tx]; return true; });
check(OtpMailer::send('person@example.test', 'Ana', '123456') === true && $sent[0] === 'person@example.test' && $sent[1] === 'Your CSMS sign-in code', 'transport receives the message');
OtpMailer::useTransport(fn() => false); check(OtpMailer::send('person@example.test', 'Ana', '123456') === false, 'a failing transport returns false');
OtpMailer::useTransport(null);
foreach (['SMTP_HOST', 'SMTP_FROM'] as $name) putenv($name);
check(OtpMailer::configured() === false && OtpMailer::send('person@example.test', 'Ana', '123456') === false, 'without SMTP settings nothing is sent and false is returned');

// ---- The sign-in action (static): no session before the code, nothing secret in the audit log ---------------------------------------
$auth = (string)file_get_contents(__DIR__ . '/../shared/auth_actions.php');
check(substr_count($auth, "\$_SESSION['user_id'] =") === 1, 'only complete_login() creates the logged-in session');
check(strpos($auth, 'EmailOtpService::requiredFor($user)') !== false && strpos($auth, 'EmailOtpService::requiredFor($user)') < strpos($auth, 'complete_login($conn, $user);'), 'the code step is decided before sign-in is completed');
foreach (['AUTH_OTP_SENT', 'AUTH_OTP_VERIFIED', 'AUTH_OTP_FAILED', 'AUTH_OTP_EXPIRED', 'AUTH_OTP_RESEND_BLOCKED', 'AUTH_OTP_SEND_FAILED'] as $event) check(str_contains($auth, "'$event'"), "audit event $event is written");
foreach (explode("\n", $auth) as $line) if (str_contains($line, 'audit_log(')) check(!preg_match('/\[\'code\'\]|\$code\b|\$entered|\$_POST/', $line), 'audit lines never carry the code');
check(str_contains($auth, "'We could not send the verification email, so you were not signed in.") && strpos($auth, 'OtpMailer::send(') < strpos($auth, "'AUTH_OTP_SENT'"), 'a failed send is reported and ends before any session exists');
check(str_contains((string)file_get_contents(__DIR__ . '/../shared/auth.php'), 'const CSMS_IDLE_TIMEOUT_SECONDS = 300;'), 'the 5-minute idle timeout is unchanged');

$db->query('DROP TEMPORARY TABLE IF EXISTS login_otp_challenges');
echo "PASS: $checks Email OTP checks (expiry, reuse, wrong code, attempt limit, resend limit, flag off, no-email user, mail template, sign-in wiring)\n";
